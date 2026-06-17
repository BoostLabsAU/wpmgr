<?php
/**
 * CloudPanel - purge CloudPanel's local Varnish and PageSpeed host caches.
 *
 * CloudPanel exposes its Varnish target through the site user's
 * ~/.varnish-cache/settings.json rather than an always-loaded PHP class. This
 * integration reads that operator-owned settings file and sends best-effort
 * local PURGE requests without depending on the optional CloudPanel plugin.
 *
 * @package WPMgr\Agent\Integrations
 */

declare(strict_types=1);

namespace WPMgr\Agent\Integrations;

/**
 * CloudPanel Varnish and PageSpeed purger.
 */
final class CloudPanel extends Integration
{
    /** Hard cap on per-call URL purges (loopback flood guard). */
    private const MAX_URLS = 200;

    /**
     * Parsed CloudPanel settings cached for this request.
     *
     * @var array{endpoint:string,cacheTagPrefix:string}|null
     */
    private ?array $settings = null;

    /** Whether settings have already been loaded for this request. */
    private bool $settingsLoaded = false;

    /**
     * Detect CloudPanel Varnish from the site user's settings.json.
     *
     * @return bool
     */
    protected function detect(): bool
    {
        return $this->settings() !== null;
    }

    /**
     * Purge the whole CloudPanel Varnish cache, then wipe PageSpeed contents.
     *
     * @return void
     */
    protected function purgeAll(): void
    {
        $settings = $this->settings();
        if ($settings === null) {
            return;
        }

        $host = $this->siteHost();
        if ($host !== '') {
            $this->purge($settings['endpoint'] . '/', ['Host' => $host]);
        }

        $this->purge($settings['endpoint'] . '/', ['X-Cache-Tags' => $this->cleanHeaderValue($settings['cacheTagPrefix'])]);

        if ($host !== '') {
            $this->wipePageSpeedHostCache($host);
        }
    }

    /**
     * PURGE each URL through CloudPanel Varnish.
     *
     * @param list<string> $urls Validated absolute URLs.
     * @return void
     */
    protected function purgeUrls(array $urls): void
    {
        $settings = $this->settings();
        if ($settings === null) {
            return;
        }

        $count = 0;
        foreach ($urls as $url) {
            if ($count++ >= self::MAX_URLS) {
                break;
            }

            $host = $this->urlPart($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }

            $path = $this->urlPart($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                $path = '/';
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }

            $query = $this->urlPart($url, PHP_URL_QUERY);
            $this->purge(
                $settings['endpoint'] . $path . (is_string($query) && $query !== '' ? '?' . $query : ''),
                ['Host' => $this->cleanHeaderValue($host)]
            );
        }
    }

    /**
     * Load and validate CloudPanel settings once per request.
     *
     * @return array{endpoint:string,cacheTagPrefix:string}|null
     */
    private function settings(): ?array
    {
        if ($this->settingsLoaded) {
            return $this->settings;
        }

        $this->settingsLoaded = true;
        $raw                  = $this->readSettingsFile($this->settingsPaths());
        if (!is_array($raw) || ($raw['enabled'] ?? null) !== true) {
            return null;
        }

        $server = $raw['server'] ?? null;
        $prefix = $raw['cacheTagPrefix'] ?? null;
        if (!is_string($server) || trim($server) === '' || !is_string($prefix) || trim($prefix) === '') {
            return null;
        }

        $endpoint = $this->normalizeEndpoint($server);
        if ($endpoint === '') {
            return null;
        }

        $this->settings = [
            'endpoint'       => $endpoint,
            'cacheTagPrefix' => trim($prefix),
        ];

        return $this->settings;
    }

    /**
     * Candidate CloudPanel settings paths in lookup order.
     *
     * @return list<string>
     */
    private function settingsPaths(): array
    {
        $override = defined('WPMGR_CLOUDPANEL_SETTINGS_PATH')
            ? trim((string) constant('WPMGR_CLOUDPANEL_SETTINGS_PATH'))
            : '';
        if ($override !== '') {
            return [$override];
        }

        $paths = [];
        foreach ($this->homeDirectories() as $home) {
            $paths[] = $this->joinPath($home, '.varnish-cache/settings.json');
        }
        return $paths;
    }

    /**
     * Read the first readable settings file.
     *
     * @param list<string> $paths Candidate paths.
     * @return array<string,mixed>|null
     */
    private function readSettingsFile(array $paths): ?array
    {
        foreach ($paths as $path) {
            if (!is_readable($path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- CloudPanel settings live outside WP_Filesystem's scope.
                continue;
            }

            $json = @file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the operator-owned local CloudPanel settings file.
            if (!is_string($json)) {
                continue;
            }

            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Normalize a CloudPanel Varnish server string into a request endpoint.
     *
     * @param string $server Raw settings server value.
     * @return string Endpoint without trailing slash, or empty string when invalid.
     */
    private function normalizeEndpoint(string $server): string
    {
        $server = trim($server);
        if ($server === '') {
            return '';
        }
        if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $server)) {
            $server = 'http://' . $server;
        }

        $parts = \wp_parse_url($server);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host   = isset($parts['host']) ? trim((string) $parts['host']) : '';
        $path   = isset($parts['path']) ? trim((string) $parts['path']) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        if ($path !== '' && $path !== '/') {
            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port <= 0 || $port > 65535)) {
            return '';
        }

        return rtrim($scheme . '://' . $host . ($port !== null ? ':' . $port : ''), '/');
    }

    /**
     * Send a best-effort CloudPanel Varnish PURGE request.
     *
     * @param string               $url     PURGE target.
     * @param array<string,string> $headers Request headers.
     * @return void
     */
    private function purge(string $url, array $headers): void
    {
        if (!function_exists('wp_remote_request')) {
            return;
        }

        \wp_remote_request($url, [
            'method'            => 'PURGE',
            'timeout'           => 2,
            'blocking'          => false,
            'sslverify'         => false,
            // Intentional: target is CloudPanel's local loopback endpoint from the site user's settings.
            'reject_unsafe_urls' => false,
            'headers'           => $headers,
        ]);
    }

    /**
     * Resolve the host WordPress is currently serving.
     *
     * @return string
     */
    private function siteHost(): string
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return $this->cleanHeaderValue($_SERVER['HTTP_HOST']);
        }

        if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '') {
            return $this->cleanHeaderValue($_SERVER['SERVER_NAME']);
        }

        if (function_exists('home_url')) {
            $host = $this->urlPart((string) \home_url('/'), PHP_URL_HOST);
            return is_string($host) ? $this->cleanHeaderValue($host) : '';
        }

        return '';
    }

    /**
     * Delete PageSpeed cache contents for a host when the resolved path is safe.
     *
     * @param string $host Site host.
     * @return void
     */
    private function wipePageSpeedHostCache(string $host): void
    {
        $hostDirName = $this->hostDirectoryName($host);
        $base        = $this->pageSpeedBasePath();
        if ($hostDirName === '' || $base === '') {
            return;
        }

        $pageSpeedRoot = $this->joinPath($base, 'pagespeed_cache/v3');
        $hostDir       = $this->joinPath($pageSpeedRoot, $hostDirName);
        if (!is_dir($hostDir) || is_link($hostDir)) {
            return;
        }
        if (!is_writable($hostDir)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct PageSpeed cache writability probe; WP_Filesystem is not initialized here.
            return;
        }

        $baseReal      = realpath($base);
        $pageSpeedReal = realpath($pageSpeedRoot);
        $hostReal      = realpath($hostDir);
        if (!is_string($baseReal) || !is_string($pageSpeedReal) || !is_string($hostReal)) {
            return;
        }
        if (!$this->pathInside($pageSpeedReal, $baseReal) || !$this->pathInside($hostReal, $pageSpeedReal)) {
            return;
        }

        $this->deleteDirectoryContents($hostReal, $hostReal);
    }

    /**
     * Recursively delete files and emptied subdirectories under a safe root.
     *
     * @param string $dir  Directory to empty.
     * @param string $root Resolved host cache root.
     * @return void
     */
    private function deleteDirectoryContents(string $dir, string $root): void
    {
        try {
            $items = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($items as $item) {
            $path = $item->getPathname();
            $safe = $item->isLink() ? $path : realpath($path);
            if (!is_string($safe) || !$this->pathInside($safe, $root)) {
                continue;
            }

            if ($item->isLink() || $item->isFile()) {
                $this->deleteFile($path);
                continue;
            }

            if ($item->isDir()) {
                $this->deleteDirectoryContents($path, $root);
                if ($this->isEmptyDirectory($path)) {
                    @rmdir($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an emptied PageSpeed cache subdirectory only.
                }
            }
        }
    }

    /**
     * Delete a single cache file, preferring WordPress' deletion helper.
     *
     * @param string $path File path.
     * @return void
     */
    private function deleteFile(string $path): void
    {
        if (function_exists('wp_delete_file')) {
            \wp_delete_file($path);
            return;
        }

        @unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file is unavailable outside full WP boot; unlink only removes the resolved cache file path.
    }

    /**
     * Whether a directory has no children.
     *
     * @param string $dir Directory path.
     * @return bool
     */
    private function isEmptyDirectory(string $dir): bool
    {
        try {
            $items = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException) {
            return false;
        }

        return !$items->valid();
    }

    /**
     * Resolve PageSpeed base root.
     *
     * @return string
     */
    private function pageSpeedBasePath(): string
    {
        $override = defined('WPMGR_CLOUDPANEL_PAGESPEED_PATH')
            ? trim((string) constant('WPMGR_CLOUDPANEL_PAGESPEED_PATH'))
            : '';
        if ($override !== '') {
            return $override;
        }

        $homes = $this->homeDirectories();
        return $homes === [] ? '' : $this->joinPath($homes[0], 'tmp');
    }

    /**
     * Site-user home directories in lookup order, without duplicates.
     *
     * @return list<string>
     */
    private function homeDirectories(): array
    {
        $homes = [];
        foreach ([getenv('HOME'), $_SERVER['HOME'] ?? null] as $home) {
            if (!is_string($home)) {
                continue;
            }

            $home = rtrim(trim($home), "\\/");
            if ($home === '' || isset($homes[$home])) {
                continue;
            }
            $homes[$home] = $home;
        }

        return array_values($homes);
    }

    /**
     * Convert a site host into the PageSpeed host directory name.
     *
     * @param string $host Host header or parsed host.
     * @return string
     */
    private function hostDirectoryName(string $host): string
    {
        $host = $this->cleanHeaderValue($host);
        if ($host === '') {
            return '';
        }

        if ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? '' : substr($host, 1, $end - 1);
        } elseif (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        $host = strtolower($host);
        $host = preg_replace('/[^a-z0-9.-]/', '', $host) ?? '';
        return trim($host, '.');
    }

    /**
     * Clean a host/header value without requiring WordPress helpers.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function cleanHeaderValue(string $value): string
    {
        if (function_exists('wp_unslash')) {
            $value = (string) \wp_unslash($value);
        }
        if (function_exists('sanitize_text_field')) {
            return trim((string) \sanitize_text_field($value));
        }

        return trim((string) preg_replace('/[\r\n\t]+/', '', $value));
    }

    /**
     * Parse a URL part through WordPress when available.
     *
     * @param string $url       URL to parse.
     * @param int    $component PHP_URL_* component.
     * @return string|int|null|false
     */
    private function urlPart(string $url, int $component): string|int|null|false
    {
        return \wp_parse_url($url, $component);
    }

    /**
     * Join two path fragments using a portable separator.
     *
     * @param string $base Base path.
     * @param string $tail Relative tail.
     * @return string
     */
    private function joinPath(string $base, string $tail): string
    {
        return rtrim($base, "\\/") . '/' . ltrim($tail, "\\/");
    }

    /**
     * Check that a path is strictly inside a resolved root.
     *
     * @param string $path Candidate path.
     * @param string $root Root path.
     * @return bool
     */
    private function pathInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path !== $root && str_starts_with($path, $root . '/');
    }
}
