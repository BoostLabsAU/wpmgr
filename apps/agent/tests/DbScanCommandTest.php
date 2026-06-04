<?php
/**
 * DbScanCommand unit tests.
 *
 * Verifies the synchronous wire contract:
 *   - Missing job_id → ok=false, detail set.
 *   - Unknown categories silently ignored.
 *   - Full scan result is in the ACK body (no async side-effects).
 *   - Engine exception → ok=false.
 *   - Empty categories → scans all 14.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Commands\DbScanCommand;
use WPMgr\Agent\Optimizer\DbCleanup;
use WPMgr\Agent\Optimizer\PerfConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\DbScanCommand
 */
final class DbScanCommandTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a DbScanCommand backed by a live fake wpdb. */
    private function command(?object $wpdb = null): DbScanCommand
    {
        $cleanup = new DbCleanup(new PerfConfig([]), $wpdb ?? new ScanFakeWpdb());
        return new DbScanCommand($cleanup);
    }

    // -------------------------------------------------------------------------
    // name()
    // -------------------------------------------------------------------------

    public function test_name_is_db_scan(): void
    {
        $this->assertSame('db_scan', (new DbScanCommand())->name());
    }

    // -------------------------------------------------------------------------
    // Refusal cases
    // -------------------------------------------------------------------------

    public function test_missing_job_id_returns_ok_false(): void
    {
        $result = $this->command()->execute([], []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('job_id', $result['detail']);
    }

    public function test_empty_job_id_returns_ok_false(): void
    {
        $result = $this->command()->execute([], ['job_id' => '']);
        $this->assertFalse($result['ok']);
    }

    // -------------------------------------------------------------------------
    // Successful scan
    // -------------------------------------------------------------------------

    public function test_scan_returns_ok_true_with_job_id_echoed(): void
    {
        $result = $this->command()->execute([], ['job_id' => 'test-uuid-1234']);
        $this->assertTrue($result['ok']);
        $this->assertSame('test-uuid-1234', $result['job_id']);
    }

    public function test_scan_result_has_required_keys(): void
    {
        $result = $this->command()->execute([], ['job_id' => 'abc']);
        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('db_size_bytes', $result);
        $this->assertArrayHasKey('table_count', $result);
        $this->assertArrayHasKey('scanned_at', $result);
        $this->assertIsArray($result['categories']);
        $this->assertIsInt($result['db_size_bytes']);
        $this->assertIsInt($result['table_count']);
        $this->assertIsInt($result['scanned_at']);
    }

    public function test_empty_categories_scans_all_14(): void
    {
        $result = $this->command()->execute([], [
            'job_id'     => 'uuid-all',
            'categories' => [],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(14, $result['categories']);
    }

    public function test_categories_subset_filters_correctly(): void
    {
        $result = $this->command()->execute([], [
            'job_id'     => 'uuid-sub',
            'categories' => ['revisions', 'trashed_posts'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['categories']);
        $this->assertArrayHasKey('revisions', $result['categories']);
        $this->assertArrayHasKey('trashed_posts', $result['categories']);
    }

    public function test_unknown_categories_are_ignored(): void
    {
        $result = $this->command()->execute([], [
            'job_id'     => 'uuid-unk',
            'categories' => ['revisions', 'not_a_real_category'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['categories']);
        $this->assertArrayHasKey('revisions', $result['categories']);
        $this->assertArrayNotHasKey('not_a_real_category', $result['categories']);
    }

    public function test_category_entries_have_count_and_bytes(): void
    {
        $result = $this->command()->execute([], [
            'job_id'     => 'uuid-entries',
            'categories' => ['revisions', 'spam_comments'],
        ]);
        foreach ($result['categories'] as $id => $entry) {
            $this->assertArrayHasKey('count', $entry, "Missing count for {$id}");
            $this->assertArrayHasKey('bytes', $entry, "Missing bytes for {$id}");
            $this->assertIsInt($entry['count']);
            $this->assertIsInt($entry['bytes']);
        }
    }

    // -------------------------------------------------------------------------
    // Engine exception → ok=false
    //
    // DbCleanup is final and cannot be extended in anonymous classes.
    // We verify the exception path indirectly: a null-wpdb DbCleanup::scan()
    // returns an empty structure (no exception), but a command-level guard on an
    // invalid engine state (wpdb=null → categories=[]) is verified separately.
    // The actual exception branch is covered by passing a ScanThrowingWpdb that
    // causes get_row() → ok=false via the try/catch inside DbScanCommand::execute.
    // -------------------------------------------------------------------------

    public function test_engine_exception_returns_ok_false(): void
    {
        // ScanThrowingWpdb throws from get_row(), which is called by scanDbSummary()
        // OUTSIDE the per-category try/catch inside scan(). That exception propagates
        // out of scan() into DbScanCommand::execute's try/catch → ok=false.
        $wpdb    = new ScanThrowingWpdb('DB server gone');
        $cleanup = new DbCleanup(new PerfConfig([]), $wpdb);
        $cmd     = new DbScanCommand($cleanup);
        $result  = $cmd->execute([], ['job_id' => 'uuid-exc', 'categories' => ['revisions']]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DB server gone', $result['detail']);
    }

    // -------------------------------------------------------------------------
    // optimize_tables includes tables array when present
    // -------------------------------------------------------------------------

    public function test_optimize_tables_tables_array_in_result(): void
    {
        $wpdb = new ScanFakeWpdb();
        $wpdb->optimizableTables = [
            ['TABLE_NAME' => 'wp_posts', 'ENGINE' => 'MyISAM', 'DATA_LENGTH' => 1048576, 'DATA_FREE' => 204800],
        ];
        $result = $this->command($wpdb)->execute([], [
            'job_id'     => 'uuid-opt',
            'categories' => ['optimize_tables'],
        ]);
        $this->assertTrue($result['ok']);
        $entry = $result['categories']['optimize_tables'];
        $this->assertArrayHasKey('tables', $entry);
        $this->assertCount(1, $entry['tables']);
        $this->assertSame('wp_posts', $entry['tables'][0]['name']);
        // No writes issued.
        $this->assertSame([], $wpdb->writes);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// wpdb double that throws from get_var() to exercise the exception path in
// DbScanCommand::execute's try/catch block.
// ─────────────────────────────────────────────────────────────────────────────

final class ScanThrowingWpdb
{
    public string $prefix = 'wp_';

    private string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function get_var(string $sql): string
    {
        return '0';
    }

    public function get_row(string $sql, $mode = null): ?array
    {
        // scanDbSummary() calls get_row() outside the per-category try/catch;
        // throwing here propagates to DbScanCommand::execute's catch → ok=false.
        throw new \RuntimeException($this->message);
    }

    public function get_results(string $sql, $mode = null): array
    {
        return [];
    }

    public function get_col(string $sql): array
    {
        return [];
    }

    public function query(string $sql): int
    {
        return 0;
    }
}
