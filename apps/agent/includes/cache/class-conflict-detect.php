<?php
/**
 * ConflictDetect — detects OTHER active page-cache / asset-optimization plugins
 * that would collide with the WPMgr page cache (double-caching, duplicate
 * minification, fighting drop-ins, stale serves).
 *
 * Two such plugins running at once is the single most common cause of "the cache
 * won't clear" / "my CSS is broken" support tickets, so we surface the conflict
 * to the control plane (via the heartbeat) instead of failing silently. This
 * class ONLY reports; it never deactivates anything and writes no admin output.
 *
 * Detection is by each plugin's canonical loaded identifier (a defined constant,
 * a class, or a function it always declares) — the public, uncopyrightable facts
 * each plugin's own code exposes once active. Cheap: only `defined()` /
 * `class_exists()` / `function_exists()` checks, safe to call on the 60s
 * heartbeat. Original implementation.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Detects conflicting cache / optimization plugins and reports them.
 */
final class ConflictDetect
{
    /**
     * Optional detection override (slug => bool) used by tests to simulate a
     * conflicting plugin being active WITHOUT defining a real, process-wide
     * constant/class that would leak into other test cases. Null in production —
     * the live constant/class/function probes run unchanged.
     *
     * @var array<string,bool>|null
     */
    private static ?array $detectOverride = null;

    /**
     * Test hook: force the active-state for a set of conflict slugs. Pass null to
     * clear and return to live detection. Production code never calls this.
     *
     * @param array<string,bool>|null $map Slug => active flag, or null to reset.
     * @return void
     */
    public static function overrideDetectionForTests(?array $map): void
    {
        self::$detectOverride = $map;
    }

    /**
     * Conflicting plugins keyed by a stable slug, each with a human label and the
     * canonical loaded identifier(s) that prove it is ACTIVE in this request.
     *
     * @return array<string,array{label:string,constants:list<string>,classes:list<string>,functions:list<string>}>
     */
    private static function table(): array
    {
        return [
            'wp-rocket'        => ['label' => 'WP Rocket',          'constants' => ['WP_ROCKET_VERSION'],          'classes' => [], 'functions' => []],
            'w3-total-cache'   => ['label' => 'W3 Total Cache',     'constants' => ['W3TC', 'W3TC_VERSION'],       'classes' => [], 'functions' => []],
            'litespeed-cache'  => ['label' => 'LiteSpeed Cache',    'constants' => ['LSCWP_V', 'LSCWP_VERSION'],   'classes' => [], 'functions' => []],
            'wp-super-cache'   => ['label' => 'WP Super Cache',     'constants' => ['WPCACHEHOME'],                'classes' => [], 'functions' => ['wpsupercache_activate', 'wp_cache_phase2']],
            'autoptimize'      => ['label' => 'Autoptimize',        'constants' => ['AUTOPTIMIZE_PLUGIN_VERSION'], 'classes' => ['autoptimizeMain'], 'functions' => []],
            'wp-fastest-cache' => ['label' => 'WP Fastest Cache',   'constants' => ['WPFC_WP_CONTENT_BASENAME'],   'classes' => ['WpFastestCache'], 'functions' => []],
            'comet-cache'      => ['label' => 'Comet Cache',        'constants' => [],                             'classes' => ['comet_cache'], 'functions' => []],
            'cache-enabler'    => ['label' => 'Cache Enabler',      'constants' => ['CACHE_ENABLER_VERSION'],      'classes' => ['Cache_Enabler'], 'functions' => []],
            'hummingbird'      => ['label' => 'Hummingbird',        'constants' => ['WPHB_VERSION'],               'classes' => ['\\Hummingbird\\WP_Hummingbird'], 'functions' => []],
            'sg-optimizer'     => ['label' => 'SG Optimizer',       'constants' => ['SiteGround_Optimizer\\VERSION'], 'classes' => ['SiteGround_Optimizer\\Loader'], 'functions' => []],
            'breeze'           => ['label' => 'Breeze',             'constants' => ['BREEZE_VERSION'],             'classes' => ['Breeze_Admin'], 'functions' => []],
            'swift-performance' => ['label' => 'Swift Performance', 'constants' => ['SWIFT_PERFORMANCE_VER', 'SWIFT_PERFORMANCE_LITE_VER'], 'classes' => ['Swift_Performance'], 'functions' => []],
            'nitropack'        => ['label' => 'NitroPack',          'constants' => ['NITROPACK_VERSION'],          'classes' => ['NitroPack\\SDK\\NitroPack'], 'functions' => []],
            'perfmatters'      => ['label' => 'Perfmatters',        'constants' => ['PERFMATTERS_VERSION'],        'classes' => [], 'functions' => []],
            'asset-cleanup'    => ['label' => 'Asset CleanUp',      'constants' => ['WPACU_PLUGIN_VERSION'],       'classes' => ['WpAssetCleanUp\\Main'], 'functions' => []],
        ];
    }

    /**
     * The list of detected conflicting plugins. Each item carries the stable slug
     * and a human label; the control plane keys/dedupes on the slug and shows the
     * label. Empty when no conflicting plugin is active.
     *
     * @return list<array{slug:string,label:string}>
     */
    public function conflicts(): array
    {
        $out = [];
        foreach (self::table() as $slug => $def) {
            if ($this->isActive($slug, $def)) {
                $out[] = ['slug' => $slug, 'label' => $def['label']];
            }
        }
        return $out;
    }

    /**
     * The detected conflict slugs only (compact form for the heartbeat gauge).
     *
     * @return list<string>
     */
    public function conflictSlugs(): array
    {
        $out = [];
        foreach ($this->conflicts() as $conflict) {
            $out[] = $conflict['slug'];
        }
        return $out;
    }

    /**
     * Whether ANY conflicting plugin is active.
     *
     * @return bool
     */
    public function hasConflict(): bool
    {
        foreach (self::table() as $slug => $def) {
            if ($this->isActive($slug, $def)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a single definition matches an active plugin. Honours the test
     * override when set for the slug; otherwise resolves any of its constants,
     * classes, or functions.
     *
     * @param string                                                                                  $slug Conflict slug.
     * @param array{label:string,constants:list<string>,classes:list<string>,functions:list<string>} $def  Definition.
     * @return bool
     */
    private function isActive(string $slug, array $def): bool
    {
        if (self::$detectOverride !== null) {
            return (bool) (self::$detectOverride[$slug] ?? false);
        }
        foreach ($def['constants'] as $const) {
            if (defined($const)) {
                return true;
            }
        }
        foreach ($def['classes'] as $class) {
            if (class_exists($class)) {
                return true;
            }
        }
        foreach ($def['functions'] as $fn) {
            if (function_exists($fn)) {
                return true;
            }
        }
        return false;
    }
}
