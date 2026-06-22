<?php
/**
 * Advanced-cache drop-in fast-path tests.
 *
 * Proves the pre-WP drop-in honors configured bypass URLs before serving an
 * already-warmed cache file. The drop-in is rendered with a real config and
 * included against a temporary wp-content tree so it exercises the actual file
 * without needing a full WordPress bootstrap.
 *
 * Each test that includes the drop-in runs in a separate process because the
 * drop-in defines constants and reads superglobals that cannot be safely reset
 * in a single PHPUnit process.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\CacheConfig;
use WPMgr\Agent\Cache\DropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\DropinInstaller
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AdvancedCacheDropinTest extends TestCase
{
    /** @var string */
    private string $tempDir;

    protected function set_up(): void
    {
        parent::set_up();
        $this->tempDir = sys_get_temp_dir() . '/wpmgr-dropin-test-' . uniqid('', true);
        @mkdir($this->tempDir, 0o777, true);
        @mkdir($this->tempDir . '/cache/wpmgr', 0o777, true);
    }

    protected function tear_down(): void
    {
        $this->rmdirRecursive($this->tempDir);
        parent::tear_down();
    }

    /**
     * Recursively remove a directory created by the test.
     *
     * @param string $dir Directory to remove.
     */
    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getRealPath());
            } else {
                @unlink($entry->getRealPath());
            }
        }
        @rmdir($dir);
    }

    /**
     * Render the drop-in into a temp file with the supplied config and include it
     * in a controlled environment. Returns the value produced by the drop-in
     * (false on bypass/miss, exits on hit).
     *
     * @param array<string,mixed> $config Drop-in config array.
     * @param array<string,mixed> $server $_SERVER overrides.
     * @return mixed
     */
    private function runDropin(array $config, array $server = [])
    {
        $template = dirname(__DIR__) . '/assets/wpmgr-advanced-cache.php';
        $installer = new DropinInstaller($this->tempDir, $template);
        $rendered  = $installer->render($config);
        $this->assertNotEmpty($rendered);

        $renderedPath = $this->tempDir . '/advanced-cache.php';
        file_put_contents($renderedPath, $rendered);

        // Plain-PHP guards the drop-in expects.
        if (!defined('ABSPATH')) {
            define('ABSPATH', $this->tempDir . '/');
        }
        if (!defined('WP_CACHE')) {
            define('WP_CACHE', true);
        }
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tempDir);
        }

        $_SERVER = array_merge([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/',
            'HTTP_HOST'      => 'example.com',
            'HTTPS'          => 'on',
        ], $server);
        $_GET    = [];
        $_COOKIE  = [];
        $_POST    = [];

        return include $renderedPath;
    }

    /**
     * Warm a cache file for the given host/path and return its absolute path.
     *
     * @param string $host  Canonical host.
     * @param string $path  URL path (no trailing slash for root).
     * @param string $name  Cache file base name.
     * @return string Absolute path to the warmed file.
     */
    private function warmCacheFile(string $host, string $path, string $name = 'index'): string
    {
        $dir = $this->tempDir . '/cache/wpmgr/' . $host . ($path === '' ? '' : $path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $file = $dir . '/' . $name . '.html.gz';
        file_put_contents($file, gzencode('<html><body>cached</body></html>'));
        return $file;
    }

    /**
     * A request whose URI contains a configured bypass URL must return false
     * BEFORE serving an existing cache file.
     */
    public function test_bypass_url_prevents_serving_existing_cache_file(): void
    {
        $this->warmCacheFile('example.com', '');

        $config = (new CacheConfig(['bypass_urls' => ['/cart']]))->toDropinArray();
        $result = $this->runDropin($config, ['REQUEST_URI' => '/cart/']);

        $this->assertFalse($result, 'bypass URL must return false before serving a warmed cache file');
    }

    /**
     * Case-insensitive substring containment: the bypass rule must match even
     * when the casing differs.
     */
    public function test_bypass_url_match_is_case_insensitive(): void
    {
        $this->warmCacheFile('example.com', '/checkout');

        $config = (new CacheConfig(['bypass_urls' => ['/CHECKOUT']]))->toDropinArray();
        $result = $this->runDropin($config, ['REQUEST_URI' => '/checkout/']);

        $this->assertFalse($result);
    }

}
