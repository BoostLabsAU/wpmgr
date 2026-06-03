<?php
/**
 * RucssComputeCommandTest — validates the rucss_compute command's SSRF guard,
 * RUCSS-disabled gate, and envelope shape. HTTP self-fetches are stubbed so no
 * actual network calls happen.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\RucssComputeCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\RucssComputeCommand
 */
final class RucssComputeCommandTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $optionStore = [];

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->optionStore = [];
        Functions\when('get_option')->alias(fn ($k, $d = false) => $this->optionStore[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->optionStore[$k] = $v;
            return true;
        });
        Functions\when('delete_option')->alias(function ($k) {
            unset($this->optionStore[$k]);
            return true;
        });
        Functions\when('home_url')->justReturn('https://example.com/');
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function cmd(): RucssComputeCommand
    {
        return new RucssComputeCommand();
    }

    public function test_command_name_is_rucss_compute(): void
    {
        $this->assertSame('rucss_compute', $this->cmd()->name());
    }

    public function test_returns_disabled_when_rucss_not_enabled(): void
    {
        // PerfConfig defaults css_rucss=false, so with an empty option store
        // the command should report RUCSS is disabled.
        $res = $this->cmd()->execute([], []);
        $this->assertTrue($res['ok']);
        $this->assertStringContainsStringIgnoringCase('disabled', $res['detail']);
        $this->assertSame(0, $res['queued']);
    }

    public function test_rejects_cross_host_urls(): void
    {
        // Enable RUCSS so we get past the gate.
        $this->optionStore['wpmgr_perf_config'] = ['css_rucss' => true];

        // Provide only cross-host URLs — all should be rejected.
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $res = $this->cmd()->execute([], [
            'urls' => [
                'https://evil.example.org/steal',
                'http://169.254.169.254/latest/meta-data/',
                'ftp://example.com/file',
            ],
        ]);

        // All cross-host / non-http(s) URLs filtered; no valid same-host URL.
        $this->assertFalse($res['ok']);
        $this->assertSame(0, $res['queued']);
    }

    public function test_accepts_same_host_urls(): void
    {
        $this->optionStore['wpmgr_perf_config'] = ['css_rucss' => true];

        // Stub wp_remote_get to return a success response.
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $res = $this->cmd()->execute([], [
            'urls' => [
                'https://example.com/',
                'https://example.com/page/',
            ],
        ]);

        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['queued']);
    }

    public function test_rejects_urls_non_array(): void
    {
        $this->optionStore['wpmgr_perf_config'] = ['css_rucss' => true];

        $res = $this->cmd()->execute([], ['urls' => 'not-an-array']);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('array', $res['detail']);
        $this->assertSame(0, $res['queued']);
    }

    public function test_response_envelope_has_required_keys(): void
    {
        $res = $this->cmd()->execute([], []);
        $this->assertArrayHasKey('ok', $res);
        $this->assertArrayHasKey('detail', $res);
        $this->assertArrayHasKey('queued', $res);
    }
}
