<?php
/**
 * OptimizerDbClassifyTest — tests for the Phase 2.2 classification rewrite.
 *
 * Covers:
 *   1. WPMgr own tables (PASS 0) — always plugin / WPMgr Agent, never orphan.
 *   2. WP core (PASS 1) — exact match.
 *   3. Source-scan map (PASS 2) — wc_ tables attributed to WooCommerce;
 *      digits_ tables attributed to DIGITS; wpmgr_ tables via PASS 0.
 *   4. Slug-prefix fallback (PASS 3) — slug matches bare name prefix.
 *   5. Orphan (PASS 4) — no match.
 *   6. bustPluginTableMapCache() — deletes the transient.
 *
 * DbTableActionCommand tests:
 *   7. validate: missing job_id, unknown action, empty tables → ok=false.
 *   8. drop rejects non-orphan table (skipped).
 *   9. drop rejects table not in information_schema (not_found / rejected).
 *  10. optimize on a valid table → done.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Optimizer\DbCleanup;
use WPMgr\Agent\Optimizer\PerfConfig;
use WPMgr\Agent\Commands\DbTableActionCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\DbCleanup::classifyTable
 * @covers \WPMgr\Agent\Optimizer\DbCleanup::bustPluginTableMapCache
 * @covers \WPMgr\Agent\Commands\DbTableActionCommand
 */
final class OptimizerDbClassifyTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // Default stubs: no plugins, no themes, no active plugins.
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_plugins')->justReturn([]);
        Functions\when('wp_get_themes')->justReturn([]);
        Functions\when('get_stylesheet')->justReturn('');
        Functions\when('get_template')->justReturn('');
        // Stub transient functions: return false (cold cache) by default.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        // is_multisite() is called (after function_exists guard) inside
        // classifyTable()'s multisite-safety block; single-site default.
        Functions\when('is_multisite')->justReturn(false);
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function cleanup(): DbCleanup
    {
        return new DbCleanup(new PerfConfig([]), null);
    }

    /**
     * Call classifyTable() with the minimum required args (empty plugin/theme maps).
     *
     * @param string               $fullName   Full table name (with wp_ prefix).
     * @param array<string,string> $pluginMeta slug => display
     * @param array<string,string> $themeMeta  slug => display
     * @return array{string,string}
     */
    private function classify(
        string $fullName,
        array $pluginMeta = [],
        array $themeMeta = []
    ): array {
        $activeSlugs = array_keys($pluginMeta);
        return $this->cleanup()->classifyTable(
            $fullName,
            'wp_',
            $activeSlugs,
            $pluginMeta,
            [],
            $themeMeta
        );
    }

    // =========================================================================
    // PASS 0: WPMgr own tables
    // =========================================================================

    /**
     * @dataProvider wpmgrOwnTablesProvider
     */
    public function test_wpmgr_own_tables_are_always_plugin(string $bareTable): void
    {
        [$ownerType, $belongsTo] = $this->classify('wp_' . $bareTable);

        $this->assertSame('plugin', $ownerType, "Own table '$bareTable' must be owner_type=plugin");
        $this->assertSame('WPMgr Agent', $belongsTo, "Own table '$bareTable' must belong to WPMgr Agent");
    }

    /**
     * Own tables must remain plugin even when NO plugin metadata is provided —
     * i.e. PASS 0 runs before any source-scan or slug-prefix check.
     *
     * @dataProvider wpmgrOwnTablesProvider
     */
    public function test_wpmgr_own_tables_never_orphan_with_empty_plugin_list(string $bareTable): void
    {
        [$ownerType] = $this->classify('wp_' . $bareTable, [], []);

        $this->assertNotSame('orphan', $ownerType, "Own table '$bareTable' must never be orphan");
    }

    /**
     * @return list<array{string}>
     */
    public static function wpmgrOwnTablesProvider(): array
    {
        return [
            ['wpmgr_agent_jti'],
            ['wpmgr_autologin_jti'],
            ['wpmgr_backup_runs'],
            ['wpmgr_backup_tasks'],
            ['wpmgr_restore_runs'],
            ['wpmgr_restore_tasks'],
            ['wpmgr_php_errors'],
            ['wpmgr_diagnostics_runs'],
            ['wpmgr_activity_log'],
            ['wpmgr_login_events'],
            ['wpmgr_preload_queue'],
        ];
    }

    // =========================================================================
    // PASS 1: WP core exact match
    // =========================================================================

    public function test_core_table_classified_as_core(): void
    {
        [$ownerType, $belongsTo] = $this->classify('wp_posts');

        $this->assertSame('core', $ownerType);
        $this->assertSame('WordPress core', $belongsTo);
    }

    public function test_wp_options_is_core(): void
    {
        [$ownerType] = $this->classify('wp_options');

        $this->assertSame('core', $ownerType);
    }

    // =========================================================================
    // PASS 2: Source-scan map (transient-cached)
    // =========================================================================

    /**
     * When the transient is pre-populated with a mapping for wc_orders → woocommerce,
     * the classifier should return plugin/WooCommerce.
     */
    public function test_wc_orders_attributed_to_woocommerce_via_source_map(): void
    {
        // Pre-warm the transient with the expected scan result.
        Functions\when('get_transient')->justReturn([
            'wc_orders' => ['woocommerce' => 'WooCommerce'],
        ]);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_wc_orders',
            ['woocommerce' => 'WooCommerce']
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('WooCommerce', $belongsTo);
    }

    /**
     * wp_wc_admin_notes should be attributed to WooCommerce when the source-scan
     * map has it (wc_admin_notes → woocommerce).
     */
    public function test_wc_admin_notes_attributed_to_woocommerce(): void
    {
        Functions\when('get_transient')->justReturn([
            'wc_admin_notes' => ['woocommerce' => 'WooCommerce'],
        ]);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_wc_admin_notes',
            ['woocommerce' => 'WooCommerce']
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('WooCommerce', $belongsTo);
    }

    /**
     * wp_digits_ tables should be attributed to the DIGITS plugin when the map says so.
     */
    public function test_digits_tables_attributed_to_digits_plugin(): void
    {
        Functions\when('get_transient')->justReturn([
            'digits_otps' => ['digits' => 'DIGITS: Mobile Number Signup and Login'],
        ]);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_digits_otps',
            ['digits' => 'DIGITS: Mobile Number Signup and Login']
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('DIGITS: Mobile Number Signup and Login', $belongsTo);
    }

    /**
     * wp_snippets (code-snippets plugin) should be attributed when the source map
     * maps it — the slug is 'code-snippets' but the bare name is 'snippets'.
     */
    public function test_snippets_table_attributed_to_code_snippets(): void
    {
        Functions\when('get_transient')->justReturn([
            'snippets' => ['code-snippets' => 'Code Snippets'],
        ]);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_snippets',
            ['code-snippets' => 'Code Snippets']
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('Code Snippets', $belongsTo);
    }

    /**
     * When multiple slugs match the same table, an active plugin slug wins.
     */
    public function test_active_slug_preferred_over_inactive_when_multiple_matches(): void
    {
        Functions\when('get_transient')->justReturn([
            'shared_table' => [
                'inactive-plugin' => 'Inactive Plugin',
                'active-plugin'   => 'Active Plugin',
            ],
        ]);

        // active-plugin is in the active list (get_option / getActivePluginSlugs)
        Functions\when('get_option')->justReturn(['active-plugin/active-plugin.php']);

        $cleanup = $this->cleanup();
        [$ownerType, $belongsTo] = $cleanup->classifyTable(
            'wp_shared_table',
            'wp_',
            ['active-plugin'],                                            // active slugs
            ['inactive-plugin' => 'Inactive Plugin', 'active-plugin' => 'Active Plugin'],
            [],
            []
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('Active Plugin', $belongsTo);
    }

    // =========================================================================
    // PASS 3: Slug-prefix fallback
    // =========================================================================

    /**
     * When no source map entry exists but the bare name starts with the
     * slug-normalised prefix (hyphen→underscore), the fallback applies.
     * Example: slug "akismet" → table "akismet_processed".
     */
    public function test_slug_prefix_fallback_matches_when_no_source_map(): void
    {
        // Cold transient (no source map).
        Functions\when('get_transient')->justReturn(false);
        // Skip actual filesystem scan by returning empty (no plugin dir).
        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', '/tmp/nonexistent_plugins_dir');
        }

        [$ownerType, $belongsTo] = $this->classify(
            'wp_akismet_processed',
            ['akismet' => 'Akismet Anti-Spam']
        );

        // Slug "akismet" normalised = "akismet"; bare "akismet_processed" starts with it.
        $this->assertSame('plugin', $ownerType);
        $this->assertSame('Akismet Anti-Spam', $belongsTo);
    }

    /**
     * Slug with hyphen normalised to underscore matches: "wpmgr-agent" → "wpmgr_agent".
     * Even without the PASS 0 hardcoded list this would fall through to PASS 3.
     * (The PASS 0 hardcoded list catches "wpmgr_activity_log" etc., but this tests
     * the normalisation logic independently.)
     */
    public function test_slug_prefix_hyphen_normalised_to_underscore(): void
    {
        Functions\when('get_transient')->justReturn(false);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_wpmgr_custom_extra',
            ['wpmgr-agent' => 'WPMgr Agent']
        );

        // "wpmgr-agent" normalised = "wpmgr_agent"; "wpmgr_custom_extra" does NOT
        // start with "wpmgr_agent" — so it falls to orphan here (PASS 3 only matches
        // exact prefix). This tests the normalisation path without a false positive.
        // The table "wpmgr_agent_extra" WOULD match "wpmgr_agent".
        $this->assertSame('orphan', $ownerType);
    }

    public function test_slug_prefix_hyphen_normalised_matches_longer_prefix(): void
    {
        Functions\when('get_transient')->justReturn(false);

        // "wpmgr-agent" normalised = "wpmgr_agent" → matches "wpmgr_agent_something".
        [$ownerType, $belongsTo] = $this->classify(
            'wp_wpmgr_agent_something',
            ['wpmgr-agent' => 'WPMgr Agent']
        );

        // This is caught by PASS 0 first (wpmgr_agent_something is NOT in the
        // WPMGR_OWN_BARE_NAMES list), then hits PASS 3 slug-prefix fallback.
        $this->assertSame('plugin', $ownerType);
        $this->assertSame('WPMgr Agent', $belongsTo);
    }

    // =========================================================================
    // PASS 4: Orphan
    // =========================================================================

    public function test_unmatched_table_is_orphan(): void
    {
        Functions\when('get_transient')->justReturn(false);

        [$ownerType, $belongsTo] = $this->classify('wp_totally_unknown_table');

        $this->assertSame('orphan', $ownerType);
        $this->assertSame('Orphan', $belongsTo);
    }

    /**
     * actionscheduler_ tables are orphan when woocommerce is NOT installed
     * (no source map entry, no slug prefix match).
     */
    public function test_actionscheduler_is_orphan_when_woocommerce_not_installed(): void
    {
        Functions\when('get_transient')->justReturn(false);

        [$ownerType] = $this->classify('wp_actionscheduler_actions');

        $this->assertSame('orphan', $ownerType);
    }

    /**
     * actionscheduler_ tables are attributed to WooCommerce when the source-scan
     * map found the string in woocommerce PHP files.
     */
    public function test_actionscheduler_attributed_to_woocommerce_when_installed(): void
    {
        Functions\when('get_transient')->justReturn([
            'actionscheduler_actions' => ['woocommerce' => 'WooCommerce'],
        ]);

        [$ownerType, $belongsTo] = $this->classify(
            'wp_actionscheduler_actions',
            ['woocommerce' => 'WooCommerce']
        );

        $this->assertSame('plugin', $ownerType);
        $this->assertSame('WooCommerce', $belongsTo);
    }

    // =========================================================================
    // bustPluginTableMapCache()
    // =========================================================================

    public function test_bust_plugin_table_map_cache_calls_delete_transient(): void
    {
        $deleted = false;
        Functions\when('delete_transient')->alias(static function (string $key) use (&$deleted): bool {
            if ($key === DbCleanup::PLUGIN_TABLE_MAP_TRANSIENT) {
                $deleted = true;
            }
            return true;
        });

        DbCleanup::bustPluginTableMapCache();

        $this->assertTrue($deleted, 'bustPluginTableMapCache() must delete the plugin map transient');
    }

    // =========================================================================
    // DbTableActionCommand — validation
    // =========================================================================

    private function makeActionWpdb(
        bool $tableExists = true,
        string $tableName = 'wp_orphan_table',
        bool $isOrphan = true
    ): ActionFakeWpdb {
        return new ActionFakeWpdb($tableExists ? $tableName : null, $tableName);
    }

    public function test_action_missing_job_id_returns_error(): void
    {
        $cmd    = new DbTableActionCommand(null, $this->makeActionWpdb());
        $result = $cmd->execute([], ['action' => 'optimize', 'tables' => ['wp_posts']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', (string) ($result['detail'] ?? ''));
    }

    public function test_action_unknown_action_returns_error(): void
    {
        $cmd    = new DbTableActionCommand(null, $this->makeActionWpdb());
        $result = $cmd->execute([], ['job_id' => 'test-uuid', 'action' => 'explode', 'tables' => ['wp_posts']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('action', (string) ($result['detail'] ?? ''));
    }

    public function test_action_empty_tables_returns_error(): void
    {
        $cmd    = new DbTableActionCommand(null, $this->makeActionWpdb());
        $result = $cmd->execute([], ['job_id' => 'test-uuid', 'action' => 'optimize', 'tables' => []]);

        $this->assertFalse($result['ok']);
    }

    public function test_action_optimize_valid_table_returns_done(): void
    {
        $wpdb = new ActionFakeWpdb('wp_orphan_table', 'wp_orphan_table', queryResult: true);

        // Provide plugin maps so classify doesn't try to scan filesystem.
        Functions\when('get_transient')->justReturn(false);

        $cmd    = new DbTableActionCommand(null, $wpdb);
        $result = $cmd->execute([], [
            'job_id' => 'test-uuid-1',
            'action' => 'optimize',
            'tables' => ['wp_orphan_table'],
        ]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('optimize', $result['action'] ?? '');
        $this->assertIsArray($result['results'] ?? null);
        $this->assertCount(1, $result['results']);
        $this->assertSame('done', $result['results'][0]['status'] ?? '');
    }

    public function test_action_drop_rejects_core_table(): void
    {
        // Table exists in information_schema.
        $wpdb = new ActionFakeWpdb('wp_posts', 'wp_posts', queryResult: true);

        // The transient returns no source-map entry for 'posts', so PASS 1 core match fires.
        // Phase 2.4: DROP is now refused for core+unknown (not orphan-only).
        Functions\when('get_transient')->justReturn([]);
        Functions\when('get_plugins')->justReturn([]);
        Functions\when('get_option')->justReturn([]);

        $cmd    = new DbTableActionCommand(null, $wpdb);
        $result = $cmd->execute([], [
            'job_id' => 'test-uuid-2',
            'action' => 'drop',
            'tables' => ['wp_posts'],
        ]);

        $this->assertTrue($result['ok'] ?? false, 'Command itself should succeed (ok=true)');
        $this->assertSame('skipped', $result['results'][0]['status'] ?? '');
        $this->assertStringContainsString('core', $result['results'][0]['detail'] ?? '');
    }

    public function test_action_drop_not_found_returns_not_found(): void
    {
        // Table NOT in information_schema.
        $wpdb = new ActionFakeWpdb(null, 'wp_ghost_table', queryResult: false);

        $cmd    = new DbTableActionCommand(null, $wpdb);
        $result = $cmd->execute([], [
            'job_id' => 'test-uuid-3',
            'action' => 'drop',
            'tables' => ['wp_ghost_table'],
        ]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('not_found', $result['results'][0]['status'] ?? '');
    }

    public function test_action_drop_orphan_table_executes(): void
    {
        // Table exists; the source map has no entry → falls through to orphan.
        $wpdb = new ActionFakeWpdb('wp_orphan_leftovers', 'wp_orphan_leftovers', queryResult: true);

        Functions\when('get_transient')->justReturn([]);
        Functions\when('get_plugins')->justReturn([]);
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_get_themes')->justReturn([]);

        $cmd    = new DbTableActionCommand(null, $wpdb);
        $result = $cmd->execute([], [
            'job_id' => 'test-uuid-4',
            'action' => 'drop',
            'tables' => ['wp_orphan_leftovers'],
        ]);

        $this->assertTrue($result['ok'] ?? false);
        // Should be done (not skipped, not not_found).
        $this->assertSame('done', $result['results'][0]['status'] ?? '');
        // DROP TABLE SQL must have been executed.
        $executedSqls = $wpdb->executedSqls;
        $hasDropSql = false;
        foreach ($executedSqls as $sql) {
            if (stripos($sql, 'DROP TABLE') !== false) {
                $hasDropSql = true;
                break;
            }
        }
        $this->assertTrue($hasDropSql, 'DROP TABLE SQL must be executed for orphan table');
    }

    public function test_action_results_echo_job_id_and_action(): void
    {
        $wpdb = new ActionFakeWpdb('wp_orphan_table', 'wp_orphan_table', queryResult: true);

        Functions\when('get_transient')->justReturn([]);

        $cmd    = new DbTableActionCommand(null, $wpdb);
        $result = $cmd->execute([], [
            'job_id' => 'my-job-id',
            'action' => 'repair',
            'tables' => ['wp_orphan_table'],
        ]);

        $this->assertSame('my-job-id', $result['job_id'] ?? '');
        $this->assertSame('repair', $result['action'] ?? '');
    }
}

// =============================================================================
// Fake wpdb for DbTableActionCommand tests.
//
// Simulates information_schema.TABLES lookup (get_var) and query execution.
// =============================================================================

final class ActionFakeWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<string> */
    public array $executedSqls = [];

    /** The table name returned by information_schema lookup (null = not found). */
    private ?string $existingTable;

    /** The raw input table name to match against. */
    private string $inputTableName;

    /** What query() returns (false = error, true/int = success). */
    private bool $queryResult;

    public function __construct(?string $existingTable, string $inputTableName, bool $queryResult = true)
    {
        $this->existingTable  = $existingTable;
        $this->inputTableName = $inputTableName;
        $this->queryResult    = $queryResult;
    }

    /** Naive prepare: returns the query as-is with args appended for matching. */
    public function prepare(string $query, ...$args): string
    {
        // Encode as a simple token the get_var/query methods can inspect.
        return json_encode(['sql' => $query, 'args' => $args]) ?: $query;
    }

    /** Simulate information_schema.TABLES lookup. */
    public function get_var(string $prepared): ?string
    {
        $decoded = json_decode($prepared, true);
        if (is_array($decoded)) {
            $arg = (string) ($decoded['args'][0] ?? '');
            if ($this->existingTable !== null && $arg === $this->inputTableName) {
                return $this->existingTable;
            }
            return null;
        }
        return null;
    }

    /** Record all SQL executions; return queryResult. */
    public function query(string $sql): bool|int
    {
        $this->executedSqls[] = $sql;
        return $this->queryResult ? 1 : false;
    }
}
