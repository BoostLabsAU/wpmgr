<?php
/**
 * TaskRunnerFetchFallbackTest — named test from the wp.org review fix spec.
 *
 * Verifies that the fetchUrl() fallback in TaskRunner rejects non-file://
 * schemes when wp_remote_get is unavailable (the wp.org reviewer's requirement:
 * no remote-capable file_get_contents in shipped code).
 *
 * fetchUrl() is protected; we invoke it via ReflectionMethod so no subclassing
 * of the final class is required.
 *
 * Named test: TaskRunnerFetchFallbackTest::test_fallback_rejects_non_file_urls
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionMethod;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\TaskRunner
 */
final class TaskRunnerFetchFallbackTest extends TestCase
{
    private TaskRunner $runner;
    private ReflectionMethod $fetchUrl;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // Construct TaskRunner with minimal params (no progress_endpoint, so
        // no ProgressClient is built and no Keystore/Signer is touched).
        $this->runner = new TaskRunner([]);

        // setAccessible() is a no-op since PHP 8.1 and deprecated since 8.5;
        // ReflectionMethod::invoke() works on protected methods directly.
        $this->fetchUrl = new ReflectionMethod(TaskRunner::class, 'fetchUrl');
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Convenience wrapper: call the protected fetchUrl via reflection.
     */
    private function callFetchUrl(string $url): ?string
    {
        $result = $this->fetchUrl->invoke($this->runner, $url);
        return $result; // @phpstan-ignore-line
    }

    // -------------------------------------------------------------------------
    // Named test: test_fallback_rejects_non_file_urls
    // -------------------------------------------------------------------------

    /**
     * When wp_remote_get fails (or the URL is non-http), the fallback must
     * reject any non-file:// scheme and return null.
     *
     * This test is the exact requirement from the wp.org reviewer: no remote-
     * capable file_get_contents remains in the codebase.
     */
    public function test_fallback_rejects_non_file_urls(): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');

        // For http/https URLs: wp_remote_get returns WP_Error (unavailable).
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('no_http', 'unavailable'));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(0);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        // Schemes that must return null — either from wp_remote_get WP_Error
        // (http/https) or from the file:// guard in the fallback branch.
        $schemes = [
            'https://example.com/archive.zip'        => 'https (wp_remote_get fails)',
            'http://malicious.example.com/payload'   => 'http (wp_remote_get fails)',
            'ftp://example.com/file.tar'             => 'ftp — not http and not file://',
            'data:text/plain,hello'                  => 'data URI',
            'ssh://user@example.com/backup.tar'      => 'ssh',
            'php://input'                            => 'php wrapper',
        ];

        foreach ($schemes as $url => $label) {
            $result = $this->callFetchUrl($url);
            $this->assertNull(
                $result,
                "fetchUrl() must return null for scheme '{$label}' (url: {$url})"
            );
        }
    }

    /**
     * A file:// URL pointing to a real temp file must be readable through
     * the fallback (non-http scheme bypasses wp_remote_get entirely and lands
     * directly in the file:// branch).
     */
    public function test_fallback_reads_local_file_url(): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');

        // Write a temp file and build a file:// URL for it.
        $tmpFile = sys_get_temp_dir() . '/wpmgr_fetchtest_' . uniqid('', true) . '.txt';
        file_put_contents($tmpFile, 'hello-e2e-fixture');

        try {
            $result = $this->callFetchUrl('file://' . $tmpFile);
            $this->assertSame(
                'hello-e2e-fixture',
                $result,
                'fetchUrl() must read a file:// URI pointing to a local file'
            );
        } finally {
            @unlink($tmpFile); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }

    /**
     * A non-existent file:// URL must return null, not throw or fatal.
     */
    public function test_fallback_returns_null_for_missing_file(): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');

        $result = $this->callFetchUrl('file:///nonexistent/path/to/file.txt');
        $this->assertNull($result, 'fetchUrl() must return null for a missing file:// URL');
    }
}
