<?php
/**
 * Tests for PingCommand: contract payload shape, spawn_cron side-effect,
 * wp_cron_disabled flag, heartbeat_overdue_sec computation, and null-when-absent.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\PingCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\PingCommand
 */
final class PingCommandTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Payload shape
    // -------------------------------------------------------------------------

    public function test_returns_contract_shaped_payload(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('agent_version', $result);
        $this->assertArrayHasKey('php_time', $result);
        $this->assertArrayHasKey('wp_cron_disabled', $result);
        $this->assertArrayHasKey('heartbeat_overdue_sec', $result);

        $this->assertTrue($result['ok']);
        $this->assertIsString($result['agent_version']);
        $this->assertIsInt($result['php_time']);
        $this->assertIsBool($result['wp_cron_disabled']);
        // heartbeat_overdue_sec is int|null.
        $this->assertTrue(
            is_int($result['heartbeat_overdue_sec']) || is_null($result['heartbeat_overdue_sec']),
            'heartbeat_overdue_sec must be int or null'
        );
    }

    public function test_agent_version_reflects_constant(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(null);

        if (!defined('WPMGR_AGENT_VERSION')) {
            define('WPMGR_AGENT_VERSION', '0.44.0-test');
        }

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertSame((string) constant('WPMGR_AGENT_VERSION'), $result['agent_version']);
    }

    // -------------------------------------------------------------------------
    // spawn_cron side-effect
    // -------------------------------------------------------------------------

    public function test_calls_spawn_cron(): void
    {
        Functions\when('get_option')->justReturn(false);

        // expect() asserts spawn_cron is called exactly once.
        Functions\expect('spawn_cron')->once()->withNoArgs()->andReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        // Also verify the payload is valid — satisfies PHPUnit's assertion count.
        $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------------
    // wp_cron_disabled flag
    // -------------------------------------------------------------------------

    public function test_wp_cron_disabled_false_when_constant_absent(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(null);

        // DISABLE_WP_CRON is not defined in the test environment unless
        // explicitly set. We cannot un-define a PHP constant, so only run
        // this assertion when the constant is absent.
        if (defined('DISABLE_WP_CRON')) {
            $this->markTestSkipped('DISABLE_WP_CRON already defined in this process.');
        }

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertFalse($result['wp_cron_disabled']);
    }

    // -------------------------------------------------------------------------
    // heartbeat_overdue_sec
    // -------------------------------------------------------------------------

    public function test_heartbeat_overdue_sec_null_when_option_absent(): void
    {
        // get_option returns false (default) when the option has never been set.
        Functions\when('get_option')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertNull($result['heartbeat_overdue_sec']);
    }

    public function test_heartbeat_overdue_sec_zero_when_recent(): void
    {
        $recentTimestamp = time() - 30; // 30 s ago — well within the 60 s interval.
        Functions\when('get_option')->justReturn($recentTimestamp);
        Functions\when('spawn_cron')->justReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertSame(0, $result['heartbeat_overdue_sec']);
    }

    public function test_heartbeat_overdue_sec_positive_when_overdue(): void
    {
        // 130 s ago: 130 - 60 = 70 s overdue.
        $overdueTimestamp = time() - 130;
        Functions\when('get_option')->justReturn($overdueTimestamp);
        Functions\when('spawn_cron')->justReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertIsInt($result['heartbeat_overdue_sec']);
        $this->assertGreaterThanOrEqual(60, $result['heartbeat_overdue_sec']);
    }

    public function test_heartbeat_overdue_sec_never_negative(): void
    {
        // Option timestamp is NOW — elapsed is 0, overdue is max(0, -60) = 0.
        Functions\when('get_option')->justReturn(time());
        Functions\when('spawn_cron')->justReturn(null);

        $cmd    = new PingCommand();
        $result = $cmd->execute([], []);

        $this->assertSame(0, $result['heartbeat_overdue_sec']);
    }
}
