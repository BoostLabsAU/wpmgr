<?php
/**
 * PerfReporterTest — validates the reporter envelope shape and option-persistence
 * helpers without actually POSTing to any network endpoint. All WP functions are
 * stubbed via Brain Monkey.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\PerfReporter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\PerfReporter
 */
final class PerfReporterTest extends TestCase
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
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    public function test_persist_config_version_stores_option(): void
    {
        PerfReporter::persistConfigVersion(42);
        $this->assertSame(42, $this->optionStore[PerfReporter::OPTION_PERF_CONFIG_VERSION]);
    }

    public function test_persist_preload_total_stores_option(): void
    {
        PerfReporter::persistPreloadTotal(100);
        $this->assertSame(100, $this->optionStore[PerfReporter::OPTION_PRELOAD_TOTAL]);
    }

    public function test_persist_last_preload_at_stores_option(): void
    {
        $ts = time();
        PerfReporter::persistLastPreloadAt($ts);
        $this->assertSame($ts, $this->optionStore[PerfReporter::OPTION_LAST_PRELOAD_AT]);
    }

    public function test_persist_last_purge_stores_timestamp_and_kind(): void
    {
        $ts = time();
        PerfReporter::persistLastPurge($ts, 'all');
        $this->assertSame($ts, $this->optionStore[PerfReporter::OPTION_LAST_PURGED_AT]);
        $this->assertSame('all', $this->optionStore[PerfReporter::OPTION_LAST_PURGE_KIND]);
    }

    public function test_persist_last_purge_defaults_kind_to_all(): void
    {
        PerfReporter::persistLastPurge(time());
        $this->assertSame('all', $this->optionStore[PerfReporter::OPTION_LAST_PURGE_KIND]);
    }

    public function test_option_keys_are_non_empty_strings(): void
    {
        $this->assertNotEmpty(PerfReporter::OPTION_PERF_CONFIG_VERSION);
        $this->assertNotEmpty(PerfReporter::OPTION_PRELOAD_TOTAL);
        $this->assertNotEmpty(PerfReporter::OPTION_LAST_PRELOAD_AT);
    }
}
