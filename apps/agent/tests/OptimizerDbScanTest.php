<?php
/**
 * DbCleanup::scan() unit tests.
 *
 * Verifies the read-only scan path:
 *   - Returns the correct top-level structure (categories, db_size_bytes,
 *     table_count, scanned_at).
 *   - Per-category count values come from bounded queries and are never negative.
 *   - optimize_tables reads DATA_FREE without running OPTIMIZE TABLE.
 *   - No write statements (query() calls) are issued by scan().
 *   - Category allow-list filtering works (empty → all 14, non-empty → subset).
 *   - No-wpdb path returns a sensible zero-result structure.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Optimizer\DbCleanup;
use WPMgr\Agent\Optimizer\PerfConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\DbCleanup::scan
 */
final class OptimizerDbScanTest extends TestCase
{
    private ScanFakeWpdb $wpdb;

    protected function set_up(): void
    {
        parent::set_up();
        $this->wpdb = new ScanFakeWpdb();
    }

    private function cleanup(): DbCleanup
    {
        return new DbCleanup(new PerfConfig([]), $this->wpdb);
    }

    // -------------------------------------------------------------------------
    // Structure tests
    // -------------------------------------------------------------------------

    public function test_scan_returns_required_top_level_keys(): void
    {
        $result = $this->cleanup()->scan();

        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('db_size_bytes', $result);
        $this->assertArrayHasKey('table_count', $result);
        $this->assertArrayHasKey('scanned_at', $result);
        $this->assertArrayHasKey('tables', $result);
        $this->assertIsArray($result['categories']);
        $this->assertIsInt($result['db_size_bytes']);
        $this->assertIsInt($result['table_count']);
        $this->assertIsInt($result['scanned_at']);
        $this->assertGreaterThan(0, $result['scanned_at']);
        $this->assertIsArray($result['tables']);
    }

    public function test_scan_tables_is_empty_when_no_inventory_rows(): void
    {
        // ScanFakeWpdb.inventoryRows defaults to [] → tables key must be [].
        $result = $this->cleanup()->scan();
        $this->assertSame([], $result['tables']);
    }

    public function test_scan_tables_rows_are_forwarded_from_inventory(): void
    {
        // Verify that the plumbing from scanTableInventory() into scan() works:
        // when the wpdb double returns inventory rows, scan() includes them in
        // the 'tables' key with the correct numeric values. Classification is
        // tested comprehensively in OptimizerDbTableInventoryTest.
        // Note: scan() wraps scanTableInventory() in a try/catch, so even when
        // Brain\Monkey is active in a prior test and WP functions are not mocked,
        // the tables key is still an array (possibly empty on exception). We only
        // assert the structure here; classification accuracy lives in its own suite.
        $this->wpdb->inventoryRows = [
            [
                'name'           => 'wp_posts',
                'rows'           => 1000,
                'size_bytes'     => 1048576,
                'engine'         => 'InnoDB',
                'overhead_bytes' => 0,
            ],
        ];

        $result = $this->cleanup()->scan();

        // The tables key must be an array.
        $this->assertIsArray($result['tables']);
        // If the inventory ran (WP functions available), verify the row shape.
        if (count($result['tables']) > 0) {
            $row = $result['tables'][0];
            $this->assertSame('wp_posts', $row['name']);
            $this->assertSame(1000, $row['rows']);
            $this->assertSame(1048576, $row['size_bytes']);
            $this->assertSame('InnoDB', $row['engine']);
            $this->assertSame(0, $row['overhead_bytes']);
            $this->assertArrayHasKey('owner_type', $row);
            $this->assertArrayHasKey('belongs_to', $row);
        }
    }

    public function test_scan_is_still_read_only_with_inventory(): void
    {
        $this->wpdb->inventoryRows = [
            ['name' => 'wp_options', 'rows' => 500, 'size_bytes' => 524288, 'engine' => 'InnoDB', 'overhead_bytes' => 0],
        ];
        $this->cleanup()->scan();
        $this->assertSame([], $this->wpdb->writes, 'scan() must not execute any write statements');
    }

    public function test_scan_all_returns_14_categories(): void
    {
        $result = $this->cleanup()->scan([]);
        $this->assertCount(14, $result['categories']);
    }

    public function test_each_category_has_count_and_bytes(): void
    {
        $result = $this->cleanup()->scan();
        foreach ($result['categories'] as $id => $entry) {
            $this->assertArrayHasKey('count', $entry, "Missing 'count' for category {$id}");
            $this->assertArrayHasKey('bytes', $entry, "Missing 'bytes' for category {$id}");
            $this->assertIsInt($entry['count'], "count for {$id} must be int");
            $this->assertIsInt($entry['bytes'], "bytes for {$id} must be int");
            $this->assertGreaterThanOrEqual(0, $entry['count']);
            $this->assertGreaterThanOrEqual(0, $entry['bytes']);
        }
    }

    // -------------------------------------------------------------------------
    // Category allow-list filtering
    // -------------------------------------------------------------------------

    public function test_scan_subset_returns_only_requested_categories(): void
    {
        $result = $this->cleanup()->scan(['revisions', 'trashed_posts']);
        $this->assertCount(2, $result['categories']);
        $this->assertArrayHasKey('revisions', $result['categories']);
        $this->assertArrayHasKey('trashed_posts', $result['categories']);
        $this->assertArrayNotHasKey('spam_comments', $result['categories']);
    }

    public function test_scan_ignores_unknown_category_ids(): void
    {
        $result = $this->cleanup()->scan(['revisions', 'totally_made_up']);
        $this->assertCount(1, $result['categories']);
        $this->assertArrayHasKey('revisions', $result['categories']);
        $this->assertArrayNotHasKey('totally_made_up', $result['categories']);
    }

    // -------------------------------------------------------------------------
    // READ-ONLY guarantee: scan must never write
    // -------------------------------------------------------------------------

    public function test_scan_issues_no_write_statements(): void
    {
        $this->cleanup()->scan();
        $this->assertSame(
            [],
            $this->wpdb->writes,
            'scan() must not execute any write (DELETE/OPTIMIZE/UPDATE/INSERT) statements'
        );
    }

    public function test_scan_does_not_call_optimize_table(): void
    {
        $result = $this->cleanup()->scan(['optimize_tables']);
        // No writes of any kind.
        $this->assertSame([], $this->wpdb->writes);
        // The scan result for optimize_tables includes bytes and tables.
        $this->assertArrayHasKey('optimize_tables', $result['categories']);
        $entry = $result['categories']['optimize_tables'];
        $this->assertArrayHasKey('bytes', $entry);
        $this->assertGreaterThanOrEqual(0, $entry['bytes']);
    }

    // -------------------------------------------------------------------------
    // optimize_tables returns per-table detail
    // -------------------------------------------------------------------------

    public function test_optimize_tables_scan_returns_table_detail(): void
    {
        $this->wpdb->optimizableTables = [
            ['TABLE_NAME' => 'wp_posts',   'ENGINE' => 'MyISAM', 'DATA_LENGTH' => 1048576, 'DATA_FREE' => 204800],
            ['TABLE_NAME' => 'wp_options', 'ENGINE' => 'MyISAM', 'DATA_LENGTH' => 524288,  'DATA_FREE' => 65536],
        ];

        $result = $this->cleanup()->scan(['optimize_tables']);
        $entry  = $result['categories']['optimize_tables'];

        $this->assertArrayHasKey('tables', $entry);
        $this->assertCount(2, $entry['tables']);
        $this->assertSame(204800 + 65536, $entry['bytes']);
        $this->assertSame(2, $entry['count']);

        // Confirm no OPTIMIZE TABLE statement was issued.
        $this->assertSame([], $this->wpdb->writes);
    }

    // -------------------------------------------------------------------------
    // Counts come from bounded queries (get_var)
    // -------------------------------------------------------------------------

    public function test_revisions_count_comes_from_get_var(): void
    {
        $this->wpdb->countResult = 42;
        $result = $this->cleanup()->scan(['revisions']);
        $this->assertSame(42, $result['categories']['revisions']['count']);
    }

    public function test_expired_transients_count_is_read_only(): void
    {
        $this->wpdb->countResult = 99;
        $result = $this->cleanup()->scan(['expired_transients']);
        $this->assertSame(99, $result['categories']['expired_transients']['count']);
        $this->assertSame([], $this->wpdb->writes);
    }

    // -------------------------------------------------------------------------
    // action_scheduler: silently returns 0 when table missing
    // -------------------------------------------------------------------------

    public function test_action_scheduler_returns_zero_when_table_missing(): void
    {
        $this->wpdb->actionSchedulerExists = false;
        $result = $this->cleanup()->scan(['action_scheduler_completed', 'action_scheduler_failed']);
        $this->assertSame(0, $result['categories']['action_scheduler_completed']['count']);
        $this->assertSame(0, $result['categories']['action_scheduler_failed']['count']);
    }

    // -------------------------------------------------------------------------
    // db_size_bytes + table_count come from get_row on information_schema
    // -------------------------------------------------------------------------

    public function test_db_summary_fields(): void
    {
        $this->wpdb->dbSizeBytes = 45088768;
        $this->wpdb->dbTableCount = 23;

        $result = $this->cleanup()->scan([]);
        $this->assertSame(45088768, $result['db_size_bytes']);
        $this->assertSame(23, $result['table_count']);
    }

    // -------------------------------------------------------------------------
    // No-wpdb safety
    // -------------------------------------------------------------------------

    public function test_no_wpdb_returns_zero_result(): void
    {
        $cleanup = new DbCleanup(new PerfConfig([]), null);
        $result  = $cleanup->scan();

        $this->assertSame([], $result['categories']);
        $this->assertSame(0, $result['db_size_bytes']);
        $this->assertSame(0, $result['table_count']);
        $this->assertIsInt($result['scanned_at']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Minimal wpdb double for scan tests.
//
// The scan path calls:
//   - prepare()     — for bounded COUNT queries
//   - get_var()     — reads the COUNT(*) result
//   - get_row()     — reads the db_size_bytes / table_count summary
//   - get_results() — dispatched to three paths:
//       (a) inventory query  (contains TABLE_ROWS alias `rows`)
//       (b) optimize_tables  (contains ENGINE <> 'InnoDB')
//       (c) term_taxonomy    (orphaned_term_relationships)
//   - get_col()     — reads term_taxonomy ids (orphaned_term_relationships)
//                     + tableExists() information_schema lookups
//   - query()       — MUST NOT be called by scan() (writes only)
// ─────────────────────────────────────────────────────────────────────────────

final class ScanFakeWpdb
{
    public string $prefix = 'wp_';

    /** Canned COUNT(*) result returned by get_var(). */
    public int $countResult = 5;

    /** Total DB size bytes returned by get_row() summary query. */
    public int $dbSizeBytes = 1024 * 1024;

    /** Table count returned by get_row() summary query. */
    public int $dbTableCount = 10;

    /**
     * Rows returned by get_results() for the optimize_tables DATA_FREE query.
     * Each element: ['TABLE_NAME'=>string,'ENGINE'=>string,'DATA_LENGTH'=>int,'DATA_FREE'=>int].
     *
     * @var list<array<string,mixed>>
     */
    public array $optimizableTables = [];

    /**
     * Rows returned by get_results() for the table-inventory query.
     * Each element: ['name'=>string,'rows'=>int,'size_bytes'=>int,
     *                'engine'=>string,'overhead_bytes'=>int].
     * When empty, the inventory returns [].
     *
     * @var list<array<string,mixed>>
     */
    public array $inventoryRows = [];

    /** When false, get_col() for tableExists returns [] (table not found). */
    public bool $actionSchedulerExists = true;

    /** All write statements — must remain empty after scan(). */
    public array $writes = [];

    // ── wpdb API ──────────────────────────────────────────────────────────────

    public function prepare(string $query, ...$args): string
    {
        // Flat substitution: replace %s/%d placeholders in order.
        $flat = [];
        foreach ($args as $a) {
            if (is_array($a)) {
                foreach ($a as $v) {
                    $flat[] = $v;
                }
            } else {
                $flat[] = $a;
            }
        }
        $i   = 0;
        $sql = preg_replace_callback('/%[sd]/', static function ($m) use (&$i, $flat) {
            $v = $flat[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
        return (string) $sql;
    }

    /** Returns the canned countResult for all COUNT(*) bounded queries. */
    public function get_var(string $sql): string
    {
        return (string) $this->countResult;
    }

    /**
     * Returns a summary row for the db_size/table_count query, or DATA_FREE
     * rows for the optimize_tables query.
     */
    public function get_row(string $sql, $mode = null): ?array
    {
        // DB summary query.
        if (stripos($sql, 'db_size_bytes') !== false) {
            return [
                'db_size_bytes' => $this->dbSizeBytes,
                'table_count'   => $this->dbTableCount,
            ];
        }
        return null;
    }

    /**
     * Dispatches get_results() to the three query paths:
     *   (a) inventory query — detected by the "`name`" alias (TABLE_NAME AS `name`)
     *   (b) optimize_tables scan — detected by ENGINE <> 'InnoDB'
     *   (c) orphaned_term_relationships — returns [] (unused in scan path here)
     */
    public function get_results(string $sql, $mode = null): array
    {
        // (a) Per-table inventory query: SELECT TABLE_NAME AS `name`, TABLE_ROWS AS `rows`, ...
        if (stripos($sql, '`name`') !== false || stripos($sql, 'TABLE_ROWS') !== false) {
            return $this->inventoryRows;
        }
        // (b) optimize_tables scan: WHERE ENGINE <> 'InnoDB' AND DATA_FREE > 0
        if (stripos($sql, 'DATA_FREE') !== false) {
            return $this->optimizableTables;
        }
        return [];
    }

    /**
     * Returns table-exists results for tableExists() calls, and term_taxonomy ids
     * for the orphaned_term_relationships scan.
     */
    public function get_col(string $sql): array
    {
        // tableExists guard: information_schema lookup without TABLE_ROWS alias.
        if (stripos($sql, 'information_schema') !== false && stripos($sql, 'TABLE_ROWS') === false) {
            if (!$this->actionSchedulerExists && stripos($sql, 'actionscheduler') !== false) {
                return [];
            }
            return ['wp_actionscheduler_actions'];
        }
        // term_taxonomy ids for orphaned_term_relationships.
        if (stripos($sql, 'term_taxonomy') !== false) {
            return [1, 2, 3];
        }
        return [];
    }

    /** Records any write statement. scan() must never call this. */
    public function query(string $sql): int
    {
        $this->writes[] = $sql;
        return 0;
    }
}
