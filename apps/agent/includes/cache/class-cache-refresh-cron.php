<?php
/**
 * CacheRefreshCron — scheduled full-cache refresh (purge-all + re-preload).
 *
 * When an operator enables a refresh interval, this keeps the cache from going
 * stale on sites that change indirectly (dynamic widgets, time-based content)
 * without a content-edit hook firing. On each tick it purges the whole site
 * cache and re-queues the known important URLs (home + sitemap-derived set kept
 * minimal here; the bulk warm is driven by traffic + auto-purge).
 *
 * Interval comes from the page-cache config (seconds). 0 disables the schedule.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Scheduled purge-and-preload on a fixed interval.
 */
final class CacheRefreshCron
{
    /** WP cron hook for the scheduled refresh. */
    public const HOOK = 'wpmgr_cache_refresh';

    /** Custom cron-schedule name registered for the configured interval. */
    public const SCHEDULE = 'wpmgr_cache_refresh_interval';

    private Purge $purge;

    private Preload $preload;

    /** Refresh interval in seconds (0 = disabled). */
    private int $intervalSeconds;

    /**
     * @param Purge   $purge           Disk purger.
     * @param Preload $preload         Warmer.
     * @param int     $intervalSeconds Interval in seconds (0 disables).
     */
    public function __construct(Purge $purge, Preload $preload, int $intervalSeconds = 0)
    {
        $this->purge           = $purge;
        $this->preload         = $preload;
        $this->intervalSeconds = max(0, $intervalSeconds);
    }

    /**
     * Register the custom cron schedule filter + bind the handler. Idempotent.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        if (!function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * Inject the configured interval as a named cron schedule.
     *
     * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
     * @return array<string,array{interval:int,display:string}>
     */
    public function addSchedule(array $schedules): array
    {
        if ($this->intervalSeconds > 0) {
            $schedules[self::SCHEDULE] = [
                'interval' => $this->intervalSeconds,
                'display'  => 'WPMgr Cache refresh interval',
            ];
        }
        return $schedules;
    }

    /**
     * Arm (or re-arm) the recurring refresh event to match the configured
     * interval. Clears it when the interval is 0.
     *
     * @return void
     */
    public function schedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        $existing = wp_next_scheduled(self::HOOK);

        if ($this->intervalSeconds <= 0) {
            if ($existing !== false && function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }

        if ($existing === false) {
            wp_schedule_event(time() + $this->intervalSeconds, self::SCHEDULE, self::HOOK);
        }
    }

    /**
     * Clear the recurring refresh event.
     *
     * @return void
     */
    public function clear(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * The cron callback: purge the whole site cache, then re-warm the home page
     * (and the configured posts page) so the next visitor hits a warm cache.
     *
     * @return void
     */
    public function run(): void
    {
        $host = '';
        if (function_exists('home_url')) {
            $host = (string) (wp_parse_url((string) home_url('/'), PHP_URL_HOST) ?? '');
        }

        if ($host !== '') {
            $this->purge->purgeSite($host);
        }

        $urls = [];
        if (function_exists('home_url')) {
            $urls[] = (string) home_url('/');
        }
        if (function_exists('get_option') && function_exists('get_permalink')) {
            $postsPage = (int) get_option('page_for_posts');
            if ($postsPage > 0) {
                $u = get_permalink($postsPage);
                if (is_string($u) && $u !== '') {
                    $urls[] = $u;
                }
            }
        }

        $urls = array_values(array_filter($urls, static fn (string $u): bool => $u !== ''));
        if ($urls !== []) {
            $this->preload->queue($urls);
        }
    }
}
