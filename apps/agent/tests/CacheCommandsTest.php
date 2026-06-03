<?php
/**
 * Cache command-handler tests: stable names, body validation, and well-formed
 * ack envelopes. The CacheManager's wp-option reads/writes are stubbed via Brain
 * Monkey; server-side artefact writes (wp-config / drop-in / .htaccess) are not
 * exercised here (covered by the dedicated unit tests), so we assert the
 * handlers' own contract: validation + envelope shape.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\CacheManager;
use WPMgr\Agent\Commands\CacheDisableCommand;
use WPMgr\Agent\Commands\CacheEnableCommand;
use WPMgr\Agent\Commands\CachePreloadCommand;
use WPMgr\Agent\Commands\CachePurgeCommand;
use WPMgr\Agent\Commands\DbCleanCommand;
use WPMgr\Agent\Commands\PerfConfigUpdateCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\CacheEnableCommand
 * @covers \WPMgr\Agent\Commands\CacheDisableCommand
 * @covers \WPMgr\Agent\Commands\CachePurgeCommand
 * @covers \WPMgr\Agent\Commands\CachePreloadCommand
 * @covers \WPMgr\Agent\Commands\PerfConfigUpdateCommand
 * @covers \WPMgr\Agent\Commands\DbCleanCommand
 */
final class CacheCommandsTest extends TestCase
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
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    private function manager(): CacheManager
    {
        // Default CacheManager; WP_CONTENT_DIR is undefined in tests so cacheRoot
        // resolves to '' and the file operations are inert no-ops — exactly what
        // we want for envelope-shape assertions.
        return new CacheManager();
    }

    public function test_command_names_match_cp_contract(): void
    {
        $mgr = $this->manager();
        $this->assertSame('cache_enable', (new CacheEnableCommand($mgr))->name());
        $this->assertSame('cache_disable', (new CacheDisableCommand($mgr))->name());
        $this->assertSame('cache_purge', (new CachePurgeCommand($mgr))->name());
        $this->assertSame('cache_preload', (new CachePreloadCommand($mgr))->name());
        $this->assertSame('perf_config_update', (new PerfConfigUpdateCommand($mgr))->name());
        $this->assertSame('db_clean', (new DbCleanCommand())->name());
    }

    public function test_purge_url_scope_requires_url(): void
    {
        $cmd = new CachePurgeCommand($this->manager());
        $res = $cmd->execute([], ['scope' => 'url']);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('url', $res['detail']);
    }

    public function test_purge_all_returns_envelope(): void
    {
        $cmd = new CachePurgeCommand($this->manager());
        $res = $cmd->execute([], ['scope' => 'all']);
        $this->assertArrayHasKey('ok', $res);
        $this->assertArrayHasKey('detail', $res);
        $this->assertArrayHasKey('stats', $res);
    }

    public function test_preload_rejects_non_array_urls(): void
    {
        $cmd = new CachePreloadCommand($this->manager());
        $res = $cmd->execute([], ['urls' => 'not-an-array']);
        $this->assertFalse($res['ok']);
    }

    public function test_preload_queues_urls(): void
    {
        $cmd = new CachePreloadCommand($this->manager());
        $res = $cmd->execute([], ['urls' => ['https://example.com/a', 'https://example.com/b']]);
        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('queued', $res);
        $this->assertSame(2, $res['queued']);
    }

    public function test_perf_config_update_rejects_empty_payload(): void
    {
        $cmd = new PerfConfigUpdateCommand($this->manager());
        $res = $cmd->execute([], ['unrelated' => 1]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('recognised', $res['detail']);
    }

    public function test_perf_config_update_persists_known_fields(): void
    {
        $cmd = new PerfConfigUpdateCommand($this->manager());
        $res = $cmd->execute([], [
            'cache_mobile'     => true,
            'auto_purge'       => false,
            'refresh_interval' => 7200,
            'include_cookies'  => ['geo'],
        ]);
        $this->assertTrue($res['ok']);

        $stored = $this->optionStore[CacheManager::OPTION_CONFIG] ?? [];
        $this->assertTrue($stored['cache_mobile']);
        $this->assertFalse($stored['auto_purge']);
        $this->assertSame(7200, $stored['refresh_interval']);
        $this->assertSame(['geo'], $stored['include_cookies']);
    }

    public function test_perf_config_update_clamps_absurd_refresh_interval(): void
    {
        $cmd = new PerfConfigUpdateCommand($this->manager());
        $cmd->execute([], ['refresh_interval' => 999999999]);
        $stored = $this->optionStore[CacheManager::OPTION_CONFIG] ?? [];
        $this->assertLessThanOrEqual(2592000, $stored['refresh_interval']);
    }

    public function test_perf_config_preserves_enabled_state_when_omitted(): void
    {
        // Seed an enabled config.
        $this->optionStore[CacheManager::OPTION_CONFIG] = ['enabled' => true];
        $cmd = new PerfConfigUpdateCommand($this->manager());
        $cmd->execute([], ['cache_mobile' => true]);
        $stored = $this->optionStore[CacheManager::OPTION_CONFIG] ?? [];
        $this->assertTrue($stored['enabled'], 'enabled must be preserved when not in the payload');
    }

    public function test_db_clean_returns_counts_envelope(): void
    {
        // No $wpdb in this test environment, so the engine reports an empty
        // cleaned map but the command still returns a well-formed success ack.
        $res = (new DbCleanCommand())->execute([], ['tasks' => ['revisions']]);
        $this->assertTrue($res['ok']);
        $this->assertSame('db cleaned', $res['detail']);
        $this->assertIsArray($res['cleaned']);
    }

    public function test_enable_returns_envelope_with_stats(): void
    {
        // Pretty permalinks present, so the pre-flight guard passes.
        $this->optionStore['permalink_structure'] = '/%postname%/';
        $cmd = new CacheEnableCommand($this->manager());
        $res = $cmd->execute([], []);
        $this->assertArrayHasKey('ok', $res);
        $this->assertArrayHasKey('detail', $res);
        $this->assertArrayHasKey('stats', $res);
        // The config option is persisted with enabled=true even if artefact
        // writes are inert in the test environment.
        $stored = $this->optionStore[CacheManager::OPTION_CONFIG] ?? [];
        $this->assertTrue($stored['enabled']);
    }

    public function test_enable_returns_top_level_install_state_fields(): void
    {
        $this->optionStore['permalink_structure'] = '/%postname%/';
        $cmd = new CacheEnableCommand($this->manager());
        $res = $cmd->execute([], []);
        // These top-level fields are required by the CP dashboard "Verify" card.
        $this->assertArrayHasKey('server_software', $res);
        $this->assertArrayHasKey('dropin_installed', $res);
        $this->assertArrayHasKey('wp_cache_constant_set', $res);
        $this->assertArrayHasKey('htaccess_managed', $res);
        // Values are booleans (the exact value depends on the test environment).
        $this->assertIsBool($res['dropin_installed']);
        $this->assertIsBool($res['wp_cache_constant_set']);
        $this->assertIsBool($res['htaccess_managed']);
        $this->assertIsString($res['server_software']);
    }

    public function test_perf_config_update_returns_install_state_fields(): void
    {
        $cmd = new PerfConfigUpdateCommand($this->manager());
        $res = $cmd->execute([], [
            'cache_mobile' => true,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('server_software', $res);
        $this->assertArrayHasKey('dropin_installed', $res);
        $this->assertArrayHasKey('wp_cache_constant_set', $res);
        $this->assertArrayHasKey('htaccess_managed', $res);
    }

    public function test_enable_refused_on_plain_permalinks(): void
    {
        // Plain permalinks (?p=123) give no URL path to key a disk cache file on,
        // so cache-enable must refuse rather than silently cache nothing.
        $this->optionStore['permalink_structure'] = '';
        $cmd = new CacheEnableCommand($this->manager());
        $res = $cmd->execute([], []);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('permalink', $res['detail']);
        // It must NOT have flipped the config to enabled.
        $stored = $this->optionStore[CacheManager::OPTION_CONFIG] ?? [];
        $this->assertArrayNotHasKey('stats', $res);
        $this->assertTrue(empty($stored['enabled']));
    }
}
