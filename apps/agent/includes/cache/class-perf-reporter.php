<?php
/**
 * PerfReporter — signed, fire-and-forget reporter that pushes cache stats and
 * install-state to the control-plane endpoints:
 *
 *   POST {cp_base}/agent/v1/cache/stats-report
 *   POST {cp_base}/agent/v1/perf/config-ack
 *
 * Both requests are authenticated with the same Ed25519 signed-header scheme
 * that ProgressClient, BackupTransport, and shipPayload use (Signer::signHeaders).
 * A failure MUST NEVER break any caller — every method is entirely fire-and-forget.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

use WPMgr\Agent\Settings;
use WPMgr\Agent\Signer;

/**
 * Pushes cache telemetry and install-state to the control plane.
 */
final class PerfReporter
{
    /** wp-option storing the last applied perf config_version. */
    public const OPTION_PERF_CONFIG_VERSION = 'wpmgr_perf_config_version';

    /** wp-option storing the timestamp of the last completed preload. */
    public const OPTION_LAST_PRELOAD_AT = 'wpmgr_cache_last_preload_at';

    /** wp-option storing the total URL count of the current/last preload batch. */
    public const OPTION_PRELOAD_TOTAL = 'wpmgr_cache_preload_total';

    /** wp-option storing the timestamp of the last full cache purge. */
    public const OPTION_LAST_PURGED_AT = 'wpmgr_cache_last_purged_at';

    /** wp-option storing the kind of the last purge (e.g. "all", "auto"). */
    public const OPTION_LAST_PURGE_KIND = 'wpmgr_cache_last_purge_kind';

    /** CP path for cache stats. */
    private const PATH_STATS = '/agent/v1/cache/stats-report';

    /** CP path for install-state ack. */
    private const PATH_CONFIG_ACK = '/agent/v1/perf/config-ack';

    private Settings $settings;

    private Signer $signer;

    private CacheManager $cache;

    /**
     * @param Settings     $settings Enrollment / CP-URL state.
     * @param Signer       $signer   Agent Ed25519 signer.
     * @param CacheManager $cache    Page-cache orchestrator.
     */
    public function __construct(Settings $settings, Signer $signer, CacheManager $cache)
    {
        $this->settings = $settings;
        $this->signer   = $signer;
        $this->cache    = $cache;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Gather cache stats + preload progress and POST to /agent/v1/cache/stats-report.
     * Fire-and-forget: never throws, never returns a meaningful value.
     *
     * @param int|null $preloadPending Override for the pending count (e.g. mid-run).
     * @param int|null $preloadTotal   Override for the total count (e.g. mid-run).
     * @param int|null $lastPreloadAt  Override for last-preload timestamp.
     * @return void
     */
    public function reportStats(
        ?int $preloadPending = null,
        ?int $preloadTotal = null,
        ?int $lastPreloadAt = null
    ): void {
        if (!$this->settings->isEnrolled()) {
            return;
        }
        try {
            $stats = $this->cache->stats();

            // Preload pending: either the override (mid-run call) or the current
            // queue-table count (pending + processing).
            if ($preloadPending === null) {
                $preloadPending = PreloadQueue::fromConfig()->pendingCount();
            }

            // Preload total: override or stored option (persisted when queue was built).
            if ($preloadTotal === null) {
                $preloadTotal = (int) (function_exists('get_option')
                    ? get_option(self::OPTION_PRELOAD_TOTAL, 0)
                    : 0);
            }

            // Last preload at: override or stored option.
            if ($lastPreloadAt === null) {
                $stored = function_exists('get_option')
                    ? get_option(self::OPTION_LAST_PRELOAD_AT, null)
                    : null;
                $lastPreloadAt = $stored !== null ? (int) $stored : null;
            }

            $body = [
                'cached_pages_count' => $stats['pages'],
                'cache_size_bytes'   => $stats['bytes'],
                'preload_pending'    => $preloadPending,
                'preload_total'      => $preloadTotal,
            ];
            if ($lastPreloadAt !== null) {
                $body['last_preload_at'] = $lastPreloadAt;
            }

            // Last purge: reported so the dashboard "Last purge" gauge reflects
            // agent-side full-cache purges the CP cannot see (auto-purge on content
            // changes, host-integration flushes). The CP also stamps operator
            // dashboard purges directly and reconciles with GREATEST, so an older
            // value reported here can never regress a newer CP stamp.
            $lastPurgedAt = function_exists('get_option')
                ? get_option(self::OPTION_LAST_PURGED_AT, null)
                : null;
            if ($lastPurgedAt !== null) {
                $body['last_purged_at'] = (int) $lastPurgedAt;
                $kind = function_exists('get_option')
                    ? get_option(self::OPTION_LAST_PURGE_KIND, '')
                    : '';
                if (is_string($kind) && $kind !== '') {
                    $body['last_purge_kind'] = $kind;
                }
            }

            // Hit/miss tally: consume completed hour buckets from the append-only
            // tally files written by the drop-in on the zero-DB hit/miss paths.
            // Fields are omitted entirely when no completed buckets exist so the
            // CP inserts no history row and the chart does not flatline with zeros.
            $cacheRoot = $this->cache->cacheRoot();
            if ($cacheRoot !== '') {
                $tally = (new TallyConsumer($cacheRoot))->consume();
                if ($tally !== null) {
                    $body['cache_hit_count']  = $tally['hits'];
                    $body['cache_miss_count'] = $tally['misses'];
                }
            }

            $this->post(self::PATH_STATS, $body);
        } catch (\Throwable $e) {
            // Fire-and-forget: swallow.
        }
    }

    /**
     * Gather install-state and POST to /agent/v1/perf/config-ack. Fire-and-forget.
     *
     * @return void
     */
    public function reportInstallState(): void
    {
        if (!$this->settings->isEnrolled()) {
            return;
        }
        try {
            $dropin   = new DropinInstaller();
            $htaccess = new HtaccessManager();

            $dropinPath = $dropin->dropinPath();
            $dropinInstalled = $dropinPath !== '' && @is_file($dropinPath) && $this->isOurDropin($dropinPath);

            // htaccess is managed only on Apache; nginx/OpenLiteSpeed report false.
            $htaccessManaged = !$htaccess->isNginx() && $this->htaccessHasOurBlock();

            // WP_CACHE constant presence in the runtime (truthy in PHP means it
            // was set in wp-config.php — not necessarily that the file is writable
            // now, but that caching is actually active).
            $wpCacheSet = defined('WP_CACHE') && (bool) constant('WP_CACHE');

            $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])
                ? (string) $_SERVER['SERVER_SOFTWARE']
                : '';

            $configVersion = (int) (function_exists('get_option')
                ? get_option(self::OPTION_PERF_CONFIG_VERSION, 0)
                : 0);

            $body = [
                'config_version'        => $configVersion,
                'server_software'       => $serverSoftware,
                'dropin_installed'      => $dropinInstalled,
                'wp_cache_constant_set' => $wpCacheSet,
                'htaccess_managed'      => $htaccessManaged,
            ];

            $this->post(self::PATH_CONFIG_ACK, $body);
        } catch (\Throwable $e) {
            // Fire-and-forget: swallow.
        }
    }

    /**
     * Persist the applied perf config_version so future reportInstallState calls
     * include it. Call this when a perf_config_update or cache_enable applies a
     * versioned config payload.
     *
     * @param int $version The config_version from the CP payload.
     * @return void
     */
    public static function persistConfigVersion(int $version): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_PERF_CONFIG_VERSION, $version, false);
        }
    }

    /**
     * Persist the preload batch total so the cron worker knows the denominator.
     * Call this right after queueing a new preload batch.
     *
     * @param int $total Total URL count queued.
     * @return void
     */
    public static function persistPreloadTotal(int $total): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_PRELOAD_TOTAL, $total, false);
        }
    }

    /**
     * Record the timestamp of a completed preload.
     *
     * @param int $at Unix timestamp.
     * @return void
     */
    public static function persistLastPreloadAt(int $at): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_LAST_PRELOAD_AT, $at, false);
        }
    }

    /**
     * Record the timestamp + kind of a full cache purge so the next stats push
     * reports it to the CP. Called from the purge engine on every full-cache clear
     * (operator "purge everything", auto-purge on content changes, host-integration
     * flushes). Per-URL purges are intentionally NOT recorded here — they include
     * the RUCSS reheat/compute pre-purges, which would spam the gauge.
     *
     * @param int    $at   Unix timestamp.
     * @param string $kind Purge kind, e.g. "all" or "auto".
     * @return void
     */
    public static function persistLastPurge(int $at, string $kind = 'all'): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_LAST_PURGED_AT, $at, false);
            update_option(self::OPTION_LAST_PURGE_KIND, $kind, false);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Sign and POST a JSON body to $path (relative to the CP base URL).
     * Fire-and-forget: failure is silently swallowed.
     *
     * @param string              $path    Request path, e.g. /agent/v1/cache/stats-report.
     * @param array<string,mixed> $payload JSON-encodable map.
     * @return void
     */
    private function post(string $path, array $payload): void
    {
        $base = $this->settings->controlPlaneUrl();
        if ($base === '') {
            return;
        }
        if (!function_exists('wp_json_encode') || !function_exists('wp_remote_post')) {
            return;
        }

        $body = (string) wp_json_encode($payload);

        try {
            $auth = $this->signer->signHeaders('POST', $path, $body);
        } catch (\Throwable $e) {
            return;
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $auth
        );

        wp_remote_post($base . $path, [
            'timeout'   => 5,
            'headers'   => $headers,
            'body'      => $body,
            'blocking'  => false, // fire-and-forget on the agent side
            'sslverify' => true,
        ]);
        // We deliberately do not check the response — any failure is acceptable.
    }

    /**
     * Whether the drop-in at $path is ours (contains our signature line).
     *
     * @param string $path Absolute path to the drop-in.
     * @return bool
     */
    private function isOurDropin(string $path): bool
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }
        return strpos($content, DropinInstaller::SIGNATURE) !== false;
    }

    /**
     * Whether the site-root .htaccess currently contains our managed block.
     *
     * @return bool
     */
    private function htaccessHasOurBlock(): bool
    {
        $root = '';
        if (function_exists('get_home_path')) {
            $candidate = get_home_path();
            if (is_string($candidate) && $candidate !== '') {
                $root = $candidate;
            }
        }
        if ($root === '' && defined('ABSPATH')) {
            $root = (string) constant('ABSPATH');
        }
        if ($root === '') {
            return false;
        }
        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        if (!@is_file($path)) {
            return false;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }
        return strpos($content, HtaccessManager::BEGIN) !== false;
    }
}
