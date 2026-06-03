<?php
/**
 * Preload — the cache warmer.
 *
 * Queues URLs and warms them with a real HTTP request (wp_remote_get) carrying
 * the `x-wpmgr-preload: 1` header so the serving drop-in bypasses the disk cache
 * and lets WordPress render fresh HTML that the CacheWriter then stores. Each URL
 * is warmed for BOTH a desktop and a mobile user-agent (when mobile caching is
 * enabled) so both buckets are populated.
 *
 * Throttling: a small inter-request delay (0.5s) is applied, and a 429 / 5xx
 * response throws so the cron worker backs off rather than hammering a struggling
 * origin.
 *
 * Standard preloader technique (Cache Enabler / WP Super Cache, GPLv2).
 * Original implementation.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Cache warmer: enqueues and fetches URLs.
 */
final class Preload
{
    /** WP cron hook the warmer runs under. */
    public const HOOK = 'wpmgr_cache_preload';

    /** Header that marks a request as a preload (drop-in bypasses cache for it). */
    public const PRELOAD_HEADER = 'x-wpmgr-preload';

    /** Desktop UA used when warming. */
    public const DESKTOP_UA = 'Mozilla/5.0 (compatible; WPMgr-Preload/1.0; +https://wpmgr.app)';

    /** Mobile UA used when warming the mobile bucket. */
    public const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) WPMgr-Preload/1.0';

    /** Inter-request delay in microseconds (0.5s) to avoid origin overload. */
    private const DELAY_US = 500000;

    /** Whether to also warm a mobile UA pass. */
    private bool $warmMobile;

    /**
     * @param bool $warmMobile Warm a mobile UA pass in addition to desktop.
     */
    public function __construct(bool $warmMobile = false)
    {
        $this->warmMobile = $warmMobile;
    }

    /**
     * Queue a set of URLs for background warming. De-duplicates against any
     * already-pending batch and schedules a single near-immediate cron event.
     *
     * SSRF guard (confused-deputy): a `cache_preload` command is signed by the
     * control plane but carries an OPERATOR-supplied `urls[]`. We must never let
     * it coerce the agent into fetching an arbitrary host (cloud metadata,
     * loopback, internal services). Every URL is filtered to THIS site's own host
     * before it is queued; off-host URLs are dropped (and logged) and never reach
     * the warming HTTP request.
     *
     * @param list<string> $urls URLs to warm.
     * @return int Count of URLs now pending.
     */
    public function queue(array $urls): int
    {
        $urls = $this->filterOnHost(array_values(array_unique(array_filter(
            array_map('strval', $urls),
            static fn (string $u): bool => $u !== ''
        ))));
        if ($urls === []) {
            return 0;
        }

        $pending = $this->pending();
        $merged  = array_values(array_unique(array_merge($pending, $urls)));
        $this->setPending($merged);

        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
            if (wp_next_scheduled(self::HOOK) === false) {
                wp_schedule_single_event(time() + 1, self::HOOK);
            }
        }

        return count($merged);
    }

    /**
     * Drain the pending queue, warming each URL. Bounded by $max per invocation;
     * re-schedules itself when work remains.
     *
     * @param int $max Maximum URLs to warm this pass.
     * @return int URLs warmed this pass.
     */
    public function run(int $max = 50): int
    {
        $pending = $this->pending();
        if ($pending === []) {
            return 0;
        }

        $batch     = array_slice($pending, 0, max(1, $max));
        $remaining = array_slice($pending, count($batch));
        $this->setPending($remaining);

        $warmed = 0;
        foreach ($batch as $url) {
            try {
                $this->warm($url);
                $warmed++;
            } catch (\Throwable $e) {
                // Re-queue the URL that triggered backoff and stop this pass.
                $remaining = array_values(array_unique(array_merge([$url], $remaining)));
                $this->setPending($remaining);
                break;
            }
            usleep(self::DELAY_US);
        }

        // Re-arm if work remains.
        if ($this->pending() !== []
            && function_exists('wp_schedule_single_event')
            && function_exists('wp_next_scheduled')
            && wp_next_scheduled(self::HOOK) === false
        ) {
            wp_schedule_single_event(time() + 2, self::HOOK);
        }

        return $warmed;
    }

    /**
     * Warm a single URL (desktop, then mobile if enabled). Throws on a 429/5xx so
     * the caller can back off.
     *
     * @param string $url URL to warm.
     * @return void
     * @throws \RuntimeException On a throttle/backoff response.
     */
    public function warm(string $url): void
    {
        $this->fetch($url, self::DESKTOP_UA);
        if ($this->warmMobile) {
            $this->fetch($url, self::MOBILE_UA);
        }
    }

    /**
     * The pending-URL queue.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $value = get_option(self::optionKey(), []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            array_map('strval', $value),
            static fn (string $u): bool => $u !== ''
        ));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Persist the pending-URL queue.
     *
     * @param list<string> $urls Queue.
     * @return void
     */
    private function setPending(array $urls): void
    {
        if (function_exists('update_option')) {
            update_option(self::optionKey(), array_values($urls), false);
        }
    }

    /**
     * Perform the warming HTTP request via wp_remote_get.
     *
     * @param string $url       URL.
     * @param string $userAgent UA to present.
     * @return void
     * @throws \RuntimeException On 429/5xx.
     */
    private function fetch(string $url, string $userAgent): void
    {
        if (!function_exists('wp_remote_get')) {
            return;
        }

        // Defence-in-depth SSRF guard: even if an off-host URL somehow reached
        // the persisted queue (stale data, future caller), never fetch it.
        if (!$this->isOnHost($url)) {
            $this->logOffHost($url);
            return;
        }

        $response = wp_remote_get($url, [
            'timeout'           => 10,
            'redirection'       => 2,
            'blocking'          => true,
            'sslverify'         => true,
            // Engage WP's SSRF guard (blocks loopback/private-IP/non-standard
            // port) even for on-host URLs — defence-in-depth.
            'reject_unsafe_urls' => true,
            'user-agent'        => $userAgent,
            'headers'           => [self::PRELOAD_HEADER => '1'],
        ]);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            // Transport failure is non-fatal for warming (skip this URL/UA).
            return;
        }

        $code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 200;

        if ($code === 429 || $code >= 500) {
            throw new \RuntimeException('preload backoff: HTTP ' . $code);
        }
    }

    /**
     * Drop every URL whose host is not this site's own host. The surviving list
     * is safe to warm; off-host URLs are logged and discarded.
     *
     * @param list<string> $urls Candidate URLs.
     * @return list<string> On-host URLs only.
     */
    private function filterOnHost(array $urls): array
    {
        $kept = [];
        foreach ($urls as $url) {
            if ($this->isOnHost($url)) {
                $kept[] = $url;
            } else {
                $this->logOffHost($url);
            }
        }
        return $kept;
    }

    /**
     * Whether $url's host equals this site's own host (case-insensitive).
     *
     * A URL with no host, a non-http(s) scheme, or a host that differs from the
     * site host is rejected. This is the key SSRF control on the command-driven
     * preload path: it blocks loopback (127.0.0.1), link-local cloud-metadata
     * (169.254.169.254) and any other off-host target an operator could supply.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    private function isOnHost(string $url): bool
    {
        $siteHost = $this->siteHost();
        if ($siteHost === '') {
            // Cannot resolve our own host — fail closed (warm nothing).
            return false;
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return hash_equals($siteHost, strtolower($host));
    }

    /**
     * This site's own host (lower-case), derived from home_url() — never from
     * request input.
     *
     * @return string Lower-case host, or '' when unresolvable.
     */
    private function siteHost(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }
        $host = parse_url((string) home_url('/'), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Log a dropped off-host preload URL (no secrets; short operational line).
     *
     * @param string $url The rejected URL.
     * @return void
     */
    private function logOffHost(string $url): void
    {
        if (function_exists('error_log')) {
            $host = parse_url($url, PHP_URL_HOST);
            error_log('wpmgr-agent: preload rejected off-host url (host=' . (is_string($host) ? $host : '?') . ')');
        }
    }

    /**
     * The wp-option key holding the pending queue.
     *
     * @return string
     */
    private static function optionKey(): string
    {
        return 'wpmgr_cache_preload_queue';
    }
}
