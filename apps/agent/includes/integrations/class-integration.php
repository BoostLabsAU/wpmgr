<?php
/**
 * Integration — abstract base for a host/edge-cache purge bridge.
 *
 * Centralises the boilerplate every integration shares:
 *   - hook registration on the WPMgr purge actions (guarded so it is a no-op
 *     without WordPress), and
 *   - the detect()-gated dispatch: when WPMgr purges, we first check the host is
 *     actually present (its class/function/global) and silently return when it
 *     is not, so an un-managed site never makes a single outbound call.
 *
 * A concrete integration implements two things:
 *   - detect(): bool — is this host/layer present on THIS site?
 *   - purgeAll(): void — clear the host's entire cache.
 * and MAY override purgeUrls(array $urls): void to purge by URL (the default
 * falls back to purgeAll()).
 *
 * @package WPMgr\Agent\Integrations
 */

declare(strict_types=1);

namespace WPMgr\Agent\Integrations;

/**
 * Base class wiring the purge actions to a host-specific cache flush.
 */
abstract class Integration
{
    /**
     * Register on the WPMgr purge actions. Hooks fire BEFORE WPMgr deletes its
     * own files so the upstream cache is cleared first (it would otherwise
     * immediately re-cache the page WPMgr is about to regenerate).
     */
    public function __construct()
    {
        if (!function_exists('add_action')) {
            return;
        }

        \add_action('wpmgr_purge_urls:before', [$this, 'onPurgeUrls'], 10, 1);
        \add_action('wpmgr_purge_pages:before', [$this, 'onPurgeEverything'], 10, 0);
        \add_action('wpmgr_purge_everything:before', [$this, 'onPurgeEverything'], 10, 0);
    }

    /**
     * Action handler: purge the given URLs from the host cache (host-gated).
     *
     * @param mixed $urls List of absolute URLs (from the purge action).
     * @return void
     */
    final public function onPurgeUrls($urls = []): void
    {
        if (!$this->detect()) {
            return;
        }
        $clean = $this->normalizeUrls($urls);
        if ($clean === []) {
            $this->purgeAll();
            return;
        }
        $this->purgeUrls($clean);
    }

    /**
     * Action handler: purge the host's entire cache (host-gated).
     *
     * @return void
     */
    final public function onPurgeEverything(): void
    {
        if (!$this->detect()) {
            return;
        }
        $this->purgeAll();
    }

    /**
     * Is this host/cache layer present on the current site?
     *
     * @return bool
     */
    abstract protected function detect(): bool;

    /**
     * Purge the host's entire cache.
     *
     * @return void
     */
    abstract protected function purgeAll(): void;

    /**
     * Purge specific URLs from the host cache. Default: fall back to purgeAll().
     *
     * @param list<string> $urls Validated absolute URLs.
     * @return void
     */
    protected function purgeUrls(array $urls): void
    {
        $this->purgeAll();
    }

    /**
     * Coerce a purge action payload into a clean list of absolute http(s) URLs.
     *
     * @param mixed $urls Raw payload.
     * @return list<string>
     */
    final protected function normalizeUrls($urls): array
    {
        if (!is_array($urls)) {
            $urls = [$urls];
        }
        $out = [];
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $scheme = strtolower((string) (wp_parse_url($url, PHP_URL_SCHEME) ?? ''));
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }
            $out[$url] = true;
        }
        return array_keys($out);
    }
}
