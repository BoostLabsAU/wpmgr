<?php
/**
 * Tests for DiagnosticsCommand::fetchPublicIp() (M28 public-IP probe).
 *
 * Brain Monkey stubs all WordPress functions so no WP runtime is needed.
 * Coverage:
 *   - Cache hit (get_transient returns a valid IP) → returns IP, no HTTP call.
 *   - Failure marker hit (wpmgr_public_ip_fail set) → returns '', no HTTP call.
 *   - WP_Error from wp_remote_get → caches failure marker, returns ''.
 *   - Non-200 HTTP status → caches failure marker, returns ''.
 *   - Empty body → caches failure marker, returns ''.
 *   - Body longer than 45 chars → caches failure marker, returns ''.
 *   - Body that is not a valid IP → caches failure marker, returns ''.
 *   - Valid IPv4 response → stores in transient, returns IP.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\DiagnosticsCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\DiagnosticsCommand
 */
final class DiagnosticsCommandPublicIpTest extends TestCase
{
    /** @var array<string,mixed> Simulated WordPress transient store. */
    private array $transients = [];

    /** @var array<string,mixed> Simulated WordPress option store (for safeCollect-adjacent calls). */
    private array $options = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->transients = [];
        $this->options    = [];

        // Stub transient functions against the in-memory store.
        Functions\when('get_transient')->alias(function (string $key) {
            return $this->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function (string $key, $value, int $ttl = 0): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });

        // Stub is_wp_error so tests can pass a WP_Error double.
        Functions\when('is_wp_error')->alias(function ($thing): bool {
            return $thing instanceof \WP_Error;
        });

        // Stub wp_remote_retrieve_response_code / wp_remote_retrieve_body as
        // passthroughs that read from a plain array response double.
        Functions\when('wp_remote_retrieve_response_code')->alias(
            function ($response): int {
                return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
            }
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            function ($response): string {
                return is_array($response) ? (string) ($response['body'] ?? '') : '';
            }
        );

        // WP time constants used in the method (not defined in unit-test env).
        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fake successful HTTP response double understood by our stubs.
     *
     * @param int    $status HTTP status code.
     * @param string $body   Response body text.
     * @return array<string,mixed>
     */
    private function fakeHttpResponse(int $status, string $body): array
    {
        return [
            'response' => ['code' => $status, 'message' => ''],
            'body'     => $body,
            'headers'  => [],
        ];
    }

    /**
     * Invoke collectHosting() via the public execute() path and extract
     * hosting['public_ip'].  We do this via execute() because collectHosting()
     * is private; execute() calls safeCollect('hosting', ...) which in turn
     * calls collectHosting(). We stub every other collect* dependency to
     * return minimal valid arrays so only the hosting key matters.
     *
     * Returns the value of hosting['public_ip'].
     */
    private function runAndGetPublicIp(): string
    {
        // Stub all WordPress functions that the other collect* methods touch
        // so execute() can complete without real WP.
        Functions\stubs([
            'home_url'                  => 'https://example.com',
            'site_url'                  => 'https://example.com',
            'get_bloginfo'              => '',
            'get_option'                => false,
            'wp_using_ext_object_cache' => false,
            'function_exists'           => true,
            '_get_cron_array'           => [],
            'get_plugins'               => [],
            'wp_get_themes'             => [],
            'wp_get_active_and_valid_themes' => [],
            'count_users'               => ['total_users' => 0, 'avail_roles' => []],
        ]);

        // Disable the wp_native collector (WP_Debug_Data won't be available).
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        $cmd    = new DiagnosticsCommand();
        $result = $cmd->execute([], []);

        $hosting = $result['hosting'] ?? [];
        return is_array($hosting) ? (string) ($hosting['public_ip'] ?? '__missing__') : '__missing__';
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** Cache hit: transient already holds a valid IP → returns IP without any HTTP call. */
    public function test_cache_hit_returns_cached_ip(): void
    {
        $this->transients['wpmgr_public_ip'] = '1.2.3.4';

        // wp_remote_get must NOT be called.
        Functions\expect('wp_remote_get')->never();

        $ip = $this->runAndGetPublicIp();
        $this->assertSame('1.2.3.4', $ip);
    }

    /** Failure marker set: transient wpmgr_public_ip_fail present → returns '' without HTTP call. */
    public function test_failure_marker_suppresses_http_call(): void
    {
        $this->transients['wpmgr_public_ip_fail'] = '1';

        Functions\expect('wp_remote_get')->never();

        $ip = $this->runAndGetPublicIp();
        $this->assertSame('', $ip);
    }

    /** wp_remote_get returns WP_Error → caches failure marker, returns ''. */
    public function test_wp_error_caches_failure_marker(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'cURL error'));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('', $ip);
        $this->assertNotFalse($this->transients['wpmgr_public_ip_fail'] ?? false, 'failure marker must be set');
        $this->assertArrayNotHasKey('wpmgr_public_ip', $this->transients, 'good-IP transient must not be set');
    }

    /** Non-200 response → caches failure marker, returns ''. */
    public function test_non_200_status_caches_failure_marker(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(503, ''));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('', $ip);
        $this->assertNotFalse($this->transients['wpmgr_public_ip_fail'] ?? false);
        $this->assertArrayNotHasKey('wpmgr_public_ip', $this->transients);
    }

    /** Empty body → caches failure marker, returns ''. */
    public function test_empty_body_caches_failure_marker(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(200, ''));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('', $ip);
        $this->assertNotFalse($this->transients['wpmgr_public_ip_fail'] ?? false);
    }

    /** Body longer than 45 chars → caches failure marker, returns ''. */
    public function test_oversized_body_caches_failure_marker(): void
    {
        $garbage = str_repeat('A', 46);
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(200, $garbage));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('', $ip);
        $this->assertNotFalse($this->transients['wpmgr_public_ip_fail'] ?? false);
    }

    /** Non-IP body (e.g. HTML error page snippet) → caches failure marker, returns ''. */
    public function test_non_ip_body_caches_failure_marker(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(200, 'not-an-ip'));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('', $ip);
        $this->assertNotFalse($this->transients['wpmgr_public_ip_fail'] ?? false);
    }

    /** Valid IPv4 response → stores validated IP in transient, returns the IP. */
    public function test_valid_ipv4_stored_in_transient(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(200, '203.0.113.42'));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('203.0.113.42', $ip);
        $this->assertSame('203.0.113.42', $this->transients['wpmgr_public_ip'] ?? '');
        $this->assertArrayNotHasKey('wpmgr_public_ip_fail', $this->transients, 'failure marker must not be set on success');
    }

    /** Body with leading/trailing whitespace is trimmed before validation. */
    public function test_whitespace_trimmed_before_validation(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->fakeHttpResponse(200, "  203.0.113.42\n"));

        $ip = $this->runAndGetPublicIp();

        $this->assertSame('203.0.113.42', $ip);
    }
}
