<?php
/**
 * PerfConfig — the typed optimization-layer configuration value object.
 *
 * Phase 3 owns the page-CACHE config (CacheConfig, wp-option wpmgr_cache_config).
 * Phase 4 adds the OPTIMIZATION config — the CSS/JS/font/image/bloat/CDN/DB flags
 * the control plane pushes via perf.config.update. They are stored as a single
 * wp-option (autoload off) so the request-path optimizer reads them cheaply once
 * and every transform self-no-ops when its flag is off.
 *
 * Each flag maps 1:1 to a field on the CP-side site_perf_config contract; the
 * config-key semantics follow the reference notes (CSS/JS/Image/Font/
 * Bloat/CDN/DB groups). Unknown keys are dropped on construction so a malformed
 * push cannot inject state.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Immutable optimization configuration consumed by the request-path Optimizer.
 */
final class PerfConfig
{
    /** Single wp-option storing the serialised optimization config. */
    public const OPTION = 'wpmgr_perf_config';

    // -- CSS / JS ----------------------------------------------------------
    /** Minify local CSS + JS assets (hash filenames into the wpmgr asset cache). */
    public bool $cssJsMinify;

    /** Download external CSS/JS to the local cache and rewrite URLs. */
    public bool $selfHostThirdParty;

    /** Remove unused CSS via the control-plane RUCSS endpoint (graceful skip). */
    public bool $cssRucss;

    /**
     * Extra selectors RUCSS must always keep (safelist).
     *
     * @var list<string>
     */
    public array $rucssIncludeSelectors;

    /** Delay first-party JS until interaction/idle. */
    public bool $jsDelay;

    /** Delay method: 'defer' | 'interaction' | 'idle'. */
    public string $jsDelayMethod;

    /**
     * Script-tag substrings excluded from the delay rewrite.
     *
     * @var list<string>
     */
    public array $jsDelayExcludes;

    // -- Fonts -------------------------------------------------------------
    /** Inject font-display:swap into @font-face + Google Fonts links. */
    public bool $fontsDisplaySwap;

    /** Self-host Google Fonts stylesheets locally. */
    public bool $fontsOptimizeGoogle;

    /** Emit <link rel=preload> for critical fonts heuristically. */
    public bool $fontsPreload;

    // -- Images / iframe ---------------------------------------------------
    /** Add loading=lazy + width/height + srcset + fetchpriority to <img>. */
    public bool $lazyLoad;

    /** Add width/height (getimagesize) so images do not cause layout shift. */
    public bool $properlySizeImages;

    /**
     * <img>/iframe substrings excluded from lazy loading (kept eager).
     *
     * @var list<string>
     */
    public array $lazyLoadExclusions;

    /** Replace YouTube iframes with a click-to-load facade. */
    public bool $youtubePlaceholder;

    /** Download + self-host gravatar avatars. */
    public bool $selfHostGravatars;

    // -- CDN ---------------------------------------------------------------
    /** Master CDN-rewrite flag. */
    public bool $cdn;

    /** CDN host (scheme-less or full URL); empty disables rewrite. */
    public string $cdnUrl;

    /** Which asset types to rewrite: 'all' | 'css_js_font' | 'image'. */
    public string $cdnFileTypes;

    // -- Speculation / prefetch -------------------------------------------
    /** Inject <script type=speculationrules> prefetch. */
    public bool $cacheLinkPrefetch;

    // -- DB cleanup --------------------------------------------------------
    /** @var bool Delete post revisions. */
    public bool $dbPostRevisions;

    /** @var bool Delete auto-draft posts. */
    public bool $dbPostAutoDrafts;

    /** @var bool Permanently delete trashed posts. */
    public bool $dbPostTrashed;

    /** @var bool Delete spam comments. */
    public bool $dbCommentsSpam;

    /** @var bool Permanently delete trashed comments. */
    public bool $dbCommentsTrashed;

    /** @var bool Delete expired transients. */
    public bool $dbTransientsExpired;

    /** @var bool Run OPTIMIZE TABLE on the core tables. */
    public bool $dbOptimizeTables;

    // -- Bloat removal -----------------------------------------------------
    /** @var bool Dequeue wp-block-library CSS on the front end. */
    public bool $bloatDisableBlockCss;

    /** @var bool Dequeue dashicons for anonymous front-end visitors. */
    public bool $bloatDisableDashicons;

    /** @var bool Strip the WP emoji detection script/styles. */
    public bool $bloatDisableEmojis;

    /** @var bool Drop jquery-migrate from the jQuery dependency chain. */
    public bool $bloatDisableJqueryMigrate;

    /** @var bool Disable XML-RPC. */
    public bool $bloatDisableXmlRpc;

    /** @var bool Disable RSS/Atom feeds + their <head> links. */
    public bool $bloatDisableRssFeed;

    /** @var bool Remove oEmbed discovery + host JS. */
    public bool $bloatDisableOembeds;

    /** @var bool Throttle the Heartbeat API interval. */
    public bool $bloatHeartbeatControl;

    /** @var bool Cap stored post revisions. */
    public bool $bloatPostRevisionsControl;

    // -- Preload tuning (Task #171) ----------------------------------------
    /** @var int Parallel loopback drain workers (PreloadQueue concurrency, 1..4). */
    public int $preloadConcurrency;

    /** @var int Inter-request warm delay in MILLISECONDS (0..10000; agent x1000 => µs). */
    public int $preloadDelayMs;

    /** @var int Max URLs a single drain pass handles (1..500; informational for loopback). */
    public int $preloadBatchSize;

    /** @var float 1-min load-average-per-core ceiling above which a pass defers (0..64; 0 = disabled). */
    public float $preloadMaxLoad;

    /**
     * @param array<string,mixed> $data Raw config map (from storage or CP).
     */
    public function __construct(array $data = [])
    {
        $this->cssJsMinify           = (bool) ($data['css_js_minify'] ?? false);
        $this->selfHostThirdParty    = (bool) ($data['css_js_self_host_third_party'] ?? false);
        $this->cssRucss              = (bool) ($data['css_rucss'] ?? false);
        $this->rucssIncludeSelectors = self::stringList($data['css_rucss_include_selectors'] ?? []);
        $this->jsDelay               = (bool) ($data['js_delay'] ?? false);
        $this->jsDelayMethod         = self::delayMethod($data['js_delay_method'] ?? 'interaction');
        $this->jsDelayExcludes       = self::stringList($data['js_delay_excludes'] ?? []);

        $this->fontsDisplaySwap      = (bool) ($data['fonts_display_swap'] ?? false);
        $this->fontsOptimizeGoogle   = (bool) ($data['fonts_optimize_google'] ?? false);
        $this->fontsPreload          = (bool) ($data['fonts_preload'] ?? false);

        $this->lazyLoad              = (bool) ($data['lazy_load'] ?? false);
        $this->properlySizeImages    = (bool) ($data['properly_size_images'] ?? false);
        $this->lazyLoadExclusions    = self::stringList($data['lazy_load_exclusions'] ?? []);
        $this->youtubePlaceholder    = (bool) ($data['youtube_placeholder'] ?? false);
        $this->selfHostGravatars     = (bool) ($data['self_host_gravatars'] ?? false);

        $this->cdn                   = (bool) ($data['cdn'] ?? false);
        $this->cdnUrl                = is_string($data['cdn_url'] ?? null) ? trim((string) $data['cdn_url']) : '';
        $this->cdnFileTypes          = self::cdnFileTypes($data['cdn_file_types'] ?? 'all');

        $this->cacheLinkPrefetch     = (bool) ($data['cache_link_prefetch'] ?? false);

        $this->dbPostRevisions       = (bool) ($data['db_post_revisions'] ?? false);
        $this->dbPostAutoDrafts      = (bool) ($data['db_post_auto_drafts'] ?? false);
        $this->dbPostTrashed         = (bool) ($data['db_post_trashed'] ?? false);
        $this->dbCommentsSpam        = (bool) ($data['db_comments_spam'] ?? false);
        $this->dbCommentsTrashed     = (bool) ($data['db_comments_trashed'] ?? false);
        $this->dbTransientsExpired   = (bool) ($data['db_transients_expired'] ?? false);
        $this->dbOptimizeTables      = (bool) ($data['db_optimize_tables'] ?? false);

        $this->bloatDisableBlockCss      = (bool) ($data['bloat_disable_block_css'] ?? false);
        $this->bloatDisableDashicons     = (bool) ($data['bloat_disable_dashicons'] ?? false);
        $this->bloatDisableEmojis        = (bool) ($data['bloat_disable_emojis'] ?? false);
        $this->bloatDisableJqueryMigrate = (bool) ($data['bloat_disable_jquery_migrate'] ?? false);
        $this->bloatDisableXmlRpc        = (bool) ($data['bloat_disable_xml_rpc'] ?? false);
        $this->bloatDisableRssFeed       = (bool) ($data['bloat_disable_rss_feed'] ?? false);
        $this->bloatDisableOembeds       = (bool) ($data['bloat_disable_oembeds'] ?? false);
        $this->bloatHeartbeatControl     = (bool) ($data['bloat_heartbeat_control'] ?? false);
        $this->bloatPostRevisionsControl = (bool) ($data['bloat_post_revisions_control'] ?? false);

        // Preload tuning (Task #171). Clamp (never reject) to the frozen bounds.
        $this->preloadConcurrency = self::clampInt($data['preload_concurrency'] ?? 1, 1, 4);
        $this->preloadDelayMs     = self::clampInt($data['preload_delay_ms'] ?? 500, 0, 10000);
        $this->preloadBatchSize   = self::clampInt($data['preload_batch_size'] ?? 50, 1, 500);
        $this->preloadMaxLoad     = self::clampFloat($data['preload_max_load'] ?? 0.0, 0.0, 64.0);
    }

    /**
     * Load the persisted config (request-cheap; single get_option read).
     *
     * @return PerfConfig
     */
    public static function load(): self
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        return new self(is_array($stored) ? $stored : []);
    }

    /**
     * Whether ANY request-path transform is enabled. Lets the cache writer skip
     * the whole optimizer pipeline (and its DOM scans) on an inert site.
     *
     * @return bool
     */
    public function anyHtmlTransformEnabled(): bool
    {
        return $this->cssJsMinify
            || $this->selfHostThirdParty
            || $this->cssRucss
            || $this->jsDelay
            || $this->fontsDisplaySwap
            || $this->fontsOptimizeGoogle
            || $this->fontsPreload
            || $this->lazyLoad
            || $this->properlySizeImages
            || $this->youtubePlaceholder
            || $this->selfHostGravatars
            || $this->cdn
            || $this->cacheLinkPrefetch;
    }

    /**
     * Whether any bloat-removal toggle is on (so the init-time hook registrar
     * can no-op entirely on an inert site).
     *
     * @return bool
     */
    public function anyBloatEnabled(): bool
    {
        return $this->bloatDisableBlockCss
            || $this->bloatDisableDashicons
            || $this->bloatDisableEmojis
            || $this->bloatDisableJqueryMigrate
            || $this->bloatDisableXmlRpc
            || $this->bloatDisableRssFeed
            || $this->bloatDisableOembeds
            || $this->bloatHeartbeatControl
            || $this->bloatPostRevisionsControl;
    }

    /**
     * Full serialisable form (storage + CP round-trips).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'css_js_minify'                => $this->cssJsMinify,
            'css_js_self_host_third_party' => $this->selfHostThirdParty,
            'css_rucss'                    => $this->cssRucss,
            'css_rucss_include_selectors'  => $this->rucssIncludeSelectors,
            'js_delay'                     => $this->jsDelay,
            'js_delay_method'              => $this->jsDelayMethod,
            'js_delay_excludes'            => $this->jsDelayExcludes,
            'fonts_display_swap'           => $this->fontsDisplaySwap,
            'fonts_optimize_google'        => $this->fontsOptimizeGoogle,
            'fonts_preload'                => $this->fontsPreload,
            'lazy_load'                    => $this->lazyLoad,
            'properly_size_images'         => $this->properlySizeImages,
            'lazy_load_exclusions'         => $this->lazyLoadExclusions,
            'youtube_placeholder'          => $this->youtubePlaceholder,
            'self_host_gravatars'          => $this->selfHostGravatars,
            'cdn'                          => $this->cdn,
            'cdn_url'                      => $this->cdnUrl,
            'cdn_file_types'               => $this->cdnFileTypes,
            'cache_link_prefetch'          => $this->cacheLinkPrefetch,
            'db_post_revisions'            => $this->dbPostRevisions,
            'db_post_auto_drafts'          => $this->dbPostAutoDrafts,
            'db_post_trashed'              => $this->dbPostTrashed,
            'db_comments_spam'             => $this->dbCommentsSpam,
            'db_comments_trashed'          => $this->dbCommentsTrashed,
            'db_transients_expired'        => $this->dbTransientsExpired,
            'db_optimize_tables'           => $this->dbOptimizeTables,
            'bloat_disable_block_css'      => $this->bloatDisableBlockCss,
            'bloat_disable_dashicons'      => $this->bloatDisableDashicons,
            'bloat_disable_emojis'         => $this->bloatDisableEmojis,
            'bloat_disable_jquery_migrate' => $this->bloatDisableJqueryMigrate,
            'bloat_disable_xml_rpc'        => $this->bloatDisableXmlRpc,
            'bloat_disable_rss_feed'       => $this->bloatDisableRssFeed,
            'bloat_disable_oembeds'        => $this->bloatDisableOembeds,
            'bloat_heartbeat_control'      => $this->bloatHeartbeatControl,
            'bloat_post_revisions_control' => $this->bloatPostRevisionsControl,
            'preload_concurrency'          => $this->preloadConcurrency,
            'preload_delay_ms'             => $this->preloadDelayMs,
            'preload_batch_size'           => $this->preloadBatchSize,
            'preload_max_load'             => $this->preloadMaxLoad,
        ];
    }

    /**
     * Clamp the delay method to the known set.
     *
     * @param mixed $value Candidate method.
     * @return string
     */
    private static function delayMethod($value): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($v, ['defer', 'interaction', 'idle'], true) ? $v : 'interaction';
    }

    /**
     * Clamp the CDN file-type group to the known set.
     *
     * @param mixed $value Candidate group.
     * @return string
     */
    private static function cdnFileTypes($value): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($v, ['all', 'css_js_font', 'image'], true) ? $v : 'all';
    }

    /**
     * Clamp a mixed value to an integer within [min, max] (inclusive). A
     * non-numeric value coerces to the lower bound after int-cast (0 for most),
     * then is clamped — never rejected.
     *
     * @param mixed $value Candidate value.
     * @param int   $min   Lower bound.
     * @param int   $max   Upper bound.
     * @return int
     */
    private static function clampInt($value, int $min, int $max): int
    {
        $v = is_numeric($value) ? (int) $value : $min;
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }
        return $v;
    }

    /**
     * Clamp a mixed value to a float within [min, max] (inclusive). Non-numeric
     * coerces to the lower bound — never rejected.
     *
     * @param mixed $value Candidate value.
     * @param float $min   Lower bound.
     * @param float $max   Upper bound.
     * @return float
     */
    private static function clampFloat($value, float $min, float $max): float
    {
        $v = is_numeric($value) ? (float) $value : $min;
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }
        return $v;
    }

    /**
     * Coerce a mixed value into a clean list of non-empty strings.
     *
     * @param mixed $value Candidate list.
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
