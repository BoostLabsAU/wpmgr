<?php
/**
 * PerfConfigUpdateCommand — applies a new page-cache configuration.
 *
 * Re-renders the inlined drop-in config and re-evaluates the .htaccess mobile
 * flag, and re-arms the scheduled refresh interval — without toggling the
 * enabled state unless the payload explicitly sets it.
 *
 * Wire contract (CP → agent):
 *   POST /wp-json/wpmgr/v1/command/perf_config_update
 *   Authorization: Bearer <Ed25519 JWT, cmd="perf_config_update", aud=<siteId>>
 *   Body: {
 *     "enabled":          <bool>?,    // optional; preserves current if omitted
 *     "cache_logged_in":  <bool>,
 *     "cache_mobile":     <bool>,
 *     "auto_purge":       <bool>,
 *     "refresh_interval": <int seconds>,
 *     "include_queries":  string[],
 *     "include_cookies":  string[],
 *     "bypass_urls":      string[],
 *     "bypass_cookies":   string[]
 *   }
 *
 * Response: { "ok": <bool>, "detail": "<text>", "stats": {...} }
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\Cache\CacheManager;
use WPMgr\Agent\Optimizer\PerfConfig;

/**
 * Re-renders the page-cache config (drop-in + .htaccess flag + refresh cron).
 */
final class PerfConfigUpdateCommand implements CommandInterface
{
    /** Recognised config keys (anything else is ignored). */
    private const KNOWN_KEYS = [
        'enabled', 'cache_logged_in', 'cache_mobile', 'auto_purge',
        'refresh_interval', 'include_queries', 'include_cookies',
        'bypass_urls', 'bypass_cookies',
    ];

    /** Hard ceiling on the refresh interval (30 days) to reject absurd values. */
    private const MAX_REFRESH = 2592000;

    private CacheManager $cache;

    /**
     * @param CacheManager $cache Page-cache orchestrator.
     */
    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'perf_config_update';
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string,mixed> $claims Validated JWT claims (unused).
     * @param array<string,mixed> $params Config map (see header).
     * @return array{ok:bool,detail:string,stats?:array<string,mixed>}
     */
    public function execute(array $claims, array $params): array
    {
        // Phase 4 — persist the OPTIMIZATION-layer config (CSS/JS/font/image/
        // bloat/CDN/DB flags) into its own wp-option when present. These keys are
        // disjoint from the cache keys below; PerfConfig drops anything unknown.
        // Done first + independently so an optimization-only push (no cache keys)
        // still lands and the request-path optimizer picks it up on next render.
        $optimizationApplied = $this->maybePersistOptimizationConfig($params);

        // Whitelist the keys so a malformed push cannot inject unknown state.
        $clean = [];
        foreach (self::KNOWN_KEYS as $key) {
            if (array_key_exists($key, $params)) {
                $clean[$key] = $params[$key];
            }
        }

        if ($clean === []) {
            if ($optimizationApplied) {
                return ['ok' => true, 'detail' => 'optimization config applied'];
            }
            return ['ok' => false, 'detail' => 'no recognised config fields'];
        }

        // Bound the refresh interval.
        if (isset($clean['refresh_interval'])) {
            $interval = (int) $clean['refresh_interval'];
            if ($interval < 0) {
                $interval = 0;
            }
            if ($interval > self::MAX_REFRESH) {
                $interval = self::MAX_REFRESH;
            }
            $clean['refresh_interval'] = $interval;
        }

        try {
            $result = $this->cache->applyConfig($clean);
            $result['stats'] = $this->cache->stats();
            return $result;
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'perf config update failed'];
        }
    }

    /**
     * Persist the optimization-layer config when the push carries any of its
     * keys. PerfConfig normalises the payload (clamps enums, drops unknowns), so
     * a malformed push cannot inject state. Merges over the stored config so a
     * partial push only changes the supplied fields.
     *
     * @param array<string,mixed> $params Raw request params.
     * @return bool Whether an optimization config was written.
     */
    private function maybePersistOptimizationConfig(array $params): bool
    {
        $current = PerfConfig::load()->toArray();
        // Only the keys PerfConfig recognises (intersection with the payload).
        $intersection = array_intersect_key($params, $current);
        if ($intersection === []) {
            return false;
        }
        $merged = new PerfConfig(array_merge($current, $intersection));
        if (function_exists('update_option')) {
            update_option(PerfConfig::OPTION, $merged->toArray(), false);
        }
        return true;
    }
}
