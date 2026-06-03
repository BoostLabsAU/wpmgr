<?php
/**
 * CacheManager — the page-cache orchestrator.
 *
 * Owns the page-cache config (one wp-option), wires the request-path hooks
 * (output-buffer writer, auto-purge, role cookie, refresh cron), and exposes the
 * high-level operations the CP command handlers call:
 *
 *   enable()        — write WP_CACHE, install the drop-in, add the .htaccess block.
 *   disable()       — reverse all three cleanly.
 *   purge(all|url)  — delegate to Purge.
 *   preload(urls)   — delegate to Preload.
 *   applyConfig()   — persist a new config, re-render the drop-in + re-eval the
 *                     .htaccess mobile flag, re-arm the refresh cron.
 *   stats()         — page count + bytes for the heartbeat.
 *
 * The config is stored as a single wp-option (autoload off). On the request path
 * the manager reads it cheaply once and, if caching is enabled, opens the output
 * buffer as early as template_redirect.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Orchestrates the page-cache lifecycle and request hooks.
 */
final class CacheManager
{
    /** Single wp-option storing the serialised CacheConfig. */
    public const OPTION_CONFIG = 'wpmgr_cache_config';

    /** WP option flagging the last enable/disable error for the admin/CP. */
    public const OPTION_LAST_ERROR = 'wpmgr_cache_last_error';

    /**
     * Cheap stats snapshot for the heartbeat. Refreshed on every state change
     * (enable/disable/purge/applyConfig) so the 60s heartbeat reads this option
     * directly instead of walking the cache dir on every tick.
     */
    public const OPTION_STATS = 'wpmgr_cache_stats';

    private WpCacheConstant $wpCache;

    private DropinInstaller $dropin;

    private HtaccessManager $htaccess;

    private NginxHelper $nginx;

    /** Cached config for this request. */
    private ?CacheConfig $config = null;

    /**
     * @param WpCacheConstant|null $wpCache  WP_CACHE editor.
     * @param DropinInstaller|null $dropin   Drop-in installer.
     * @param HtaccessManager|null $htaccess .htaccess manager.
     * @param NginxHelper|null     $nginx    Nginx snippet helper.
     */
    public function __construct(
        ?WpCacheConstant $wpCache = null,
        ?DropinInstaller $dropin = null,
        ?HtaccessManager $htaccess = null,
        ?NginxHelper $nginx = null
    ) {
        $this->wpCache  = $wpCache ?? new WpCacheConstant();
        $this->dropin   = $dropin ?? new DropinInstaller();
        $this->htaccess = $htaccess ?? new HtaccessManager();
        $this->nginx    = $nginx ?? new NginxHelper();
    }

    /**
     * Bind request-path hooks. Only opens the output buffer + auto-purge when
     * caching is actually enabled, so an inert site has zero request overhead
     * beyond a single option read.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        $config = $this->config();

        // The role cookie + refresh cron schedule are armed regardless so a
        // later enable already has the role data and the cron is consistent.
        (new LoggedInRoles())->registerHooks();

        $refresh = new CacheRefreshCron(
            $this->purgeEngine(),
            $this->preloadEngine(),
            $config->refreshInterval
        );
        $refresh->registerHooks();

        // The preload cron handler is always bound (it self-no-ops when empty).
        if (function_exists('add_action')) {
            add_action(Preload::HOOK, [$this, 'runPreload']);
        }

        if (!$config->enabled) {
            return;
        }

        // Auto-purge on content change.
        if ($config->autoPurge) {
            (new AutoPurge($this->purgeEngine(), $this->preloadEngine()))->registerHooks();
        }

        // Output-buffer writer: open as early as template_redirect for front-end
        // GET requests. Skip preload requests (the warmer wants fresh HTML).
        if (function_exists('add_action') && !$this->isPreloadRequest()) {
            add_action('template_redirect', [$this, 'startBuffer'], 0);
        }
    }

    /**
     * Open the page-cache output buffer (bound to template_redirect).
     *
     * @return void
     */
    public function startBuffer(): void
    {
        $writer = new CacheWriter($this->config(), $this->cacheRoot());
        ob_start([$writer, 'handle']);
    }

    /**
     * Preload cron callback. Passes a PerfReporter so progress/completion are
     * reported back to the CP after each batch (fire-and-forget).
     *
     * @return void
     */
    public function runPreload(): void
    {
        $reporter = $this->makePerfReporter();
        $this->preloadEngine()->run(50, $reporter);
    }

    // -------------------------------------------------------------------------
    // High-level operations (called by command handlers)
    // -------------------------------------------------------------------------

    /**
     * Enable page caching: WP_CACHE define → drop-in → .htaccess block. Persists
     * `enabled=true` in the config so the request path activates on next load.
     *
     * @return array{ok:bool,detail:string,steps:array<string,bool>}
     */
    public function enable(): array
    {
        $config = $this->config();
        $config->enabled = true;
        $this->saveConfig($config);

        $steps = [
            'wp_cache' => $this->wpCache->enable(),
            'dropin'   => $this->dropin->install($config->toDropinArray()),
            'htaccess' => $this->htaccessApply($config),
        ];

        // Nginx has no .htaccess fast-path; surface the manual snippet instead of
        // treating the missing block as a failure.
        $nginxNotice = false;
        if ($this->nginx->isNginx()) {
            $steps['htaccess'] = true;
            $nginxNotice = true;
            if (function_exists('update_option')) {
                update_option(NginxHelper::OPTION_NGINX_NOTICE, 1, false);
            }
        }

        $ok = $steps['wp_cache'] && $steps['dropin'] && $steps['htaccess'];
        $detail = $ok ? 'cache enabled' : 'cache enable incomplete';
        if ($nginxNotice) {
            $detail .= ' (nginx: add the manual location snippet)';
        }
        $this->recordError($ok ? '' : $detail);

        return ['ok' => $ok, 'detail' => $detail, 'steps' => $steps];
    }

    /**
     * Disable page caching: remove the .htaccess block, the drop-in, and the
     * WP_CACHE define, then purge everything and flip `enabled=false`.
     *
     * @return array{ok:bool,detail:string,steps:array<string,bool>}
     */
    public function disable(): array
    {
        $config = $this->config();
        $config->enabled = false;
        $this->saveConfig($config);

        $steps = [
            'htaccess' => $this->htaccess->uninstall(),
            'dropin'   => $this->dropin->uninstall(),
            'wp_cache' => $this->wpCache->disable(),
            'purge'    => $this->purgeEngine()->purgeEverything(),
        ];

        if (function_exists('delete_option')) {
            delete_option(NginxHelper::OPTION_NGINX_NOTICE);
        }
        $refresh = new CacheRefreshCron($this->purgeEngine(), $this->preloadEngine(), 0);
        $refresh->clear();

        $ok = $steps['htaccess'] && $steps['dropin'] && $steps['wp_cache'];
        $detail = $ok ? 'cache disabled' : 'cache disable incomplete';
        $this->recordError($ok ? '' : $detail);

        return ['ok' => $ok, 'detail' => $detail, 'steps' => $steps];
    }

    /**
     * Purge the cache. $url empty / "all" purges everything; otherwise purge that
     * single URL's variants.
     *
     * @param string $url Target URL, or '' / 'all' for everything.
     * @return array{ok:bool,detail:string,removed:int}
     */
    public function purge(string $url = ''): array
    {
        $purge = $this->purgeEngine();

        if ($url === '' || strtolower($url) === 'all') {
            $ok = $purge->purgeEverything();
            return ['ok' => $ok, 'detail' => $ok ? 'purged all' : 'purge failed', 'removed' => -1];
        }

        $removed = $purge->purgeUrl($url);
        return ['ok' => true, 'detail' => 'purged url', 'removed' => $removed];
    }

    /**
     * Queue URLs for preload warming.
     *
     * @param list<string> $urls URLs to warm (empty ⇒ warm the home page).
     * @return array{ok:bool,detail:string,queued:int}
     */
    public function preload(array $urls = []): array
    {
        if ($urls === [] && function_exists('home_url')) {
            $urls = [(string) home_url('/')];
        }
        $queued = $this->preloadEngine()->queue($urls);
        return ['ok' => true, 'detail' => 'preload queued', 'queued' => $queued];
    }

    /**
     * Apply a new config: persist it, re-render the drop-in, re-evaluate the
     * .htaccess mobile flag, and re-arm the refresh cron. Used by perf.config.update.
     *
     * @param array<string,mixed> $data Raw config map from the CP.
     * @return array{ok:bool,detail:string}
     */
    public function applyConfig(array $data): array
    {
        // Preserve the current enabled state unless the payload explicitly sets it.
        $current = $this->config();
        if (!array_key_exists('enabled', $data)) {
            $data['enabled'] = $current->enabled;
        }

        $config = new CacheConfig($data);
        $this->saveConfig($config);

        // Only touch server artefacts when caching is enabled.
        if ($config->enabled) {
            $this->dropin->install($config->toDropinArray());
            $this->htaccessApply($config);
        }

        // Re-arm the refresh schedule to the (possibly new) interval.
        $refresh = new CacheRefreshCron($this->purgeEngine(), $this->preloadEngine(), $config->refreshInterval);
        $refresh->schedule();

        return ['ok' => true, 'detail' => 'config applied'];
    }

    /**
     * Page count + total bytes for the heartbeat gauge. Walks the cache dir and
     * persists the result as a cheap snapshot (see {@see snapshot()}).
     *
     * @return array{enabled:bool,pages:int,bytes:int}
     */
    public function stats(): array
    {
        $s = (new CacheStats($this->cacheRoot()))->collect();
        $stats = [
            'enabled' => $this->config()->enabled,
            'pages'   => $s['pages'],
            'bytes'   => $s['bytes'],
        ];

        if (function_exists('update_option')) {
            update_option(self::OPTION_STATS, $stats, false);
        }

        return $stats;
    }

    /**
     * The cheap stats snapshot for the heartbeat. Reads the persisted option
     * (refreshed on every cache state change) WITHOUT walking the cache dir, so
     * it is safe to call every 60s.
     *
     * @return array{enabled:bool,pages:int,bytes:int}
     */
    public static function snapshot(): array
    {
        $default = ['enabled' => false, 'pages' => 0, 'bytes' => 0];
        if (!function_exists('get_option')) {
            return $default;
        }
        $stored = get_option(self::OPTION_STATS, $default);
        if (!is_array($stored)) {
            return $default;
        }
        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'pages'   => (int) ($stored['pages'] ?? 0),
            'bytes'   => (int) ($stored['bytes'] ?? 0),
        ];
    }

    /**
     * The current (request-cached) config.
     *
     * @return CacheConfig
     */
    public function config(): CacheConfig
    {
        if ($this->config === null) {
            $stored = function_exists('get_option') ? get_option(self::OPTION_CONFIG, []) : [];
            $this->config = new CacheConfig(is_array($stored) ? $stored : []);
        }
        return $this->config;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Persist a config and refresh the request-cached copy.
     *
     * @param CacheConfig $config Config to store.
     * @return void
     */
    private function saveConfig(CacheConfig $config): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_CONFIG, $config->toArray(), false);
        }
        $this->config = $config;
    }

    /**
     * Install/refresh the .htaccess block for the current host + mobile flag.
     *
     * @param CacheConfig $config Active config.
     * @return bool
     */
    private function htaccessApply(CacheConfig $config): bool
    {
        if ($this->htaccess->isNginx()) {
            return false;
        }
        return $this->htaccess->install($this->hostname(), $config->cacheMobile);
    }

    /**
     * The disk cache root (…/wp-content/cache/wpmgr).
     *
     * @return string
     */
    public function cacheRoot(): string
    {
        $content = defined('WP_CONTENT_DIR') ? rtrim((string) constant('WP_CONTENT_DIR'), '/\\') : '';
        if ($content === '') {
            return '';
        }
        return $content . '/cache/wpmgr';
    }

    /**
     * Build a Purge engine for the current cache root.
     *
     * @return Purge
     */
    private function purgeEngine(): Purge
    {
        return new Purge($this->cacheRoot());
    }

    /**
     * Build a Preload engine honouring the mobile-warm config.
     *
     * @return Preload
     */
    private function preloadEngine(): Preload
    {
        return new Preload($this->config()->cacheMobile);
    }

    /**
     * The current request host (or the configured site host as a fallback).
     *
     * @return string
     */
    private function hostname(): string
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return $_SERVER['HTTP_HOST'];
        }
        if (function_exists('home_url')) {
            $host = (string) (parse_url((string) home_url('/'), PHP_URL_HOST) ?? '');
            if ($host !== '') {
                return $host;
            }
        }
        return '';
    }

    /**
     * Whether the current request is a preload warm (drop-in/writer must skip it).
     *
     * @return bool
     */
    private function isPreloadRequest(): bool
    {
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', Preload::PRELOAD_HEADER));
        return isset($_SERVER[$header]) && (string) $_SERVER[$header] === '1';
    }

    /**
     * Build a PerfReporter for the current site. Returns null when the reporter
     * cannot be constructed (e.g. before enrollment). The reporter is cheap to
     * build (no DB reads); actual I/O only happens when report*() is called.
     *
     * @return PerfReporter|null
     */
    public function makePerfReporter(): ?PerfReporter
    {
        try {
            // PerfReporter needs Settings + Signer. These mirror what Plugin
            // holds, but CacheManager is constructed without them. We build
            // lightweight instances here; they share the same wp-options storage.
            $settings = new \WPMgr\Agent\Settings();
            if (!$settings->isEnrolled()) {
                return null;
            }
            $keystore = new \WPMgr\Agent\Keystore();
            $signer   = new \WPMgr\Agent\Signer($keystore);
            return new PerfReporter($settings, $signer, $this);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Record (or clear) the last error for surfacing to the admin/CP. Never logs
     * secrets — only short operational strings.
     *
     * @param string $error Error text or '' to clear.
     * @return void
     */
    private function recordError(string $error): void
    {
        if (!function_exists('update_option') || !function_exists('delete_option')) {
            return;
        }
        if ($error === '') {
            delete_option(self::OPTION_LAST_ERROR);
        } else {
            update_option(self::OPTION_LAST_ERROR, $error, false);
        }
    }
}
