<?php
/**
 * CacheEnableCommand — turns page caching on for this site.
 *
 * Wire contract (CP → agent):
 *   POST /wp-json/wpmgr/v1/command/cache_enable
 *   Authorization: Bearer <Ed25519 JWT, cmd="cache_enable", aud=<siteId>>
 *   Body: {} (optional config keys may be included; applied before enabling)
 *
 * Response: { "ok": <bool>, "detail": "<text>", "steps": {...}, "stats": {...} }
 *
 * Auth: the Router's permission_callback already enforced the signed JWT +
 * anti-replay contract (Connector::verifyCommand) before execute() runs.
 *
 * @package WPMgr\Agent\Commands
 */

declare(strict_types=1);

namespace WPMgr\Agent\Commands;

use WPMgr\Agent\Cache\CacheManager;

/**
 * Enables the page cache (WP_CACHE + drop-in + .htaccess).
 */
final class CacheEnableCommand implements CommandInterface
{
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
        return 'cache_enable';
    }

    /**
     * Optionally apply a pushed config, then enable caching.
     *
     * @param array<string,mixed> $claims Validated JWT claims (unused here).
     * @param array<string,mixed> $params Decoded JSON body.
     * @return array{ok:bool,detail:string,steps?:array<string,bool>,stats?:array<string,mixed>}
     */
    public function execute(array $claims, array $params): array
    {
        try {
            // Pre-flight: disk-path caching keys the cache file on the request URL
            // path, which plain permalinks (?p=123) do not provide. Enabling on a
            // plain-permalink site would silently cache nothing and look broken, so
            // refuse with a clear, actionable reason instead.
            if (function_exists('get_option') && (string) \get_option('permalink_structure', '') === '') {
                return [
                    'ok'     => false,
                    'detail' => 'Caching requires pretty permalinks. Set Settings > Permalinks to anything other than Plain, then enable caching again.',
                ];
            }

            // Apply any inline config first so enable() installs with it.
            if ($params !== [] && $this->looksLikeConfig($params)) {
                $this->cache->applyConfig($params);
            }

            $result = $this->cache->enable();
            $result['stats'] = $this->cache->stats();
            return $result;
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'cache enable failed'];
        }
    }

    /**
     * Whether the params map carries any recognised config key (so we only
     * call applyConfig when there is something to apply).
     *
     * @param array<string,mixed> $params Request params.
     * @return bool
     */
    private function looksLikeConfig(array $params): bool
    {
        $keys = [
            'enabled', 'cache_logged_in', 'cache_mobile', 'auto_purge',
            'refresh_interval', 'include_queries', 'include_cookies',
            'bypass_urls', 'bypass_cookies',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                return true;
            }
        }
        return false;
    }
}
