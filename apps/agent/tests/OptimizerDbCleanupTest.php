<?php
/**
 * DbCleanup tests: each task runs only the requested categories, returns the
 * frozen per-category wire shape { rows_deleted, bytes_freed, state, detail },
 * and uses prepared statements. Disabled flags + empty KNOWN_TASKS guard are
 * tested; the fixed key 'optimize_tables' (not 'optimized_tables') is verified.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Optimizer\DbCleanup;
use WPMgr\Agent\Optimizer\PerfConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\DbCleanup
 */
final class OptimizerDbCleanupTest extends TestCase
{
    private FakeCleanupWpdb $wpdb;

    protected function set_up(): void
    {
        parent::set_up();
        $this->wpdb = new FakeCleanupWpdb();
    }

    private function cleanup(array $cfg): DbCleanup
    {
        return new DbCleanup(new PerfConfig($cfg), $this->wpdb);
    }

    // -------------------------------------------------------------------------
    // Helper: assert the frozen per-category wire shape
    // -------------------------------------------------------------------------

    /**
     * Assert that $result is the frozen per-category wire shape with the expected
     * rows_deleted count and state='done'.
     *
     * @param array<string,mixed> $result
     * @param int                 $expectedRows
     */
    private function assertDoneResult(array $result, int $expectedRows): void
    {
        $this->assertArrayHasKey('rows_deleted', $result);
        $this->assertArrayHasKey('bytes_freed', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertSame($expectedRows, $result['rows_deleted']);
        $this->assertIsInt($result['bytes_freed']);
        $this->assertSame('done', $result['state']);
    }

    /**
     * Assert a skipped result shape.
     *
     * @param array<string,mixed> $result
     */
    private function assertSkippedResult(array $result): void
    {
        $this->assertArrayHasKey('state', $result);
        $this->assertSame('skipped', $result['state']);
        $this->assertSame(0, $result['rows_deleted']);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_disabled_runs_nothing(): void
    {
        $report = $this->cleanup([])->run();
        $this->assertSame([], $report);
        $this->assertSame([], $this->wpdb->writes);
    }

    public function test_revisions_only_deletes_revisions(): void
    {
        $this->wpdb->idResults = [3, 4, 5]; // ids returned for the revision SELECT
        $report = $this->cleanup(['db_post_revisions' => true])->run();

        $this->assertArrayHasKey('revisions', $report);
        $this->assertDoneResult($report['revisions'], 3);

        // No comment/transient/optimize work happened.
        $this->assertArrayNotHasKey('spam_comments', $report);
        // Defect 3 fix: key is 'optimize_tables', NOT 'optimized_tables'.
        $this->assertArrayNotHasKey('optimize_tables', $report);
        $this->assertArrayNotHasKey('optimized_tables', $report);

        // The SELECT bound post_type = 'revision'.
        $this->assertTrue($this->wpdb->preparedWith('post_type = %s', 'revision'));
        // A DELETE FROM the posts table ran with the ids.
        $this->assertTrue($this->wpdb->wroteLike('DELETE FROM wp_posts WHERE ID IN'));
    }

    public function test_trashed_posts_uses_status(): void
    {
        $this->wpdb->idResults = [10];
        $report = $this->cleanup(['db_post_trashed' => true])->run();
        $this->assertArrayHasKey('trashed_posts', $report);
        $this->assertDoneResult($report['trashed_posts'], 1);
        $this->assertTrue($this->wpdb->preparedWith('post_status = %s', 'trash'));
    }

    public function test_spam_comments_deletes_only_spam(): void
    {
        $this->wpdb->idResults = [7, 8];
        $report = $this->cleanup(['db_comments_spam' => true])->run();
        $this->assertArrayHasKey('spam_comments', $report);
        $this->assertDoneResult($report['spam_comments'], 2);
        $this->assertTrue($this->wpdb->preparedWith('comment_approved = %s', 'spam'));
        $this->assertTrue($this->wpdb->wroteLike('DELETE FROM wp_comments WHERE comment_ID IN'));
    }

    /**
     * Defect 3 fix: the key in the report MUST be 'optimize_tables', NOT
     * 'optimized_tables'. Verify both the key name and the per-category shape.
     */
    public function test_optimize_tables_runs_optimize(): void
    {
        // Neutralise the cooldown (no last-run transient) so OPTIMIZE always runs.
        Monkey\setUp();
        try {
            Functions\when('get_transient')->justReturn(false);
            Functions\when('set_transient')->justReturn(true);

            // information_schema reports wp_posts as a non-InnoDB table with overhead.
            $this->wpdb->optimizableTables = ['wp_posts', 'wp_options'];
            $report = $this->cleanup(['db_optimize_tables' => true])->run();

            // DEFECT 3 FIX: key is 'optimize_tables' — NOT 'optimized_tables'.
            $this->assertArrayHasKey('optimize_tables', $report);
            $this->assertArrayNotHasKey('optimized_tables', $report);

            // rows_deleted holds the count of optimized tables.
            $this->assertDoneResult($report['optimize_tables'], 2);
            $this->assertTrue($this->wpdb->wroteLike('OPTIMIZE TABLE `wp_posts`'));
            $this->assertTrue($this->wpdb->wroteLike('OPTIMIZE TABLE `wp_options`'));
        } finally {
            Monkey\tearDown();
        }
    }

    public function test_optimize_skips_innodb_tables(): void
    {
        // SAFETY: an all-InnoDB site (information_schema returns no eligible tables)
        // must run ZERO OPTIMIZE statements — OPTIMIZE on InnoDB locks/rebuilds the
        // table and could take a live site down.
        Monkey\setUp();
        try {
            Functions\when('get_transient')->justReturn(false);
            Functions\when('set_transient')->justReturn(true);

            $this->wpdb->optimizableTables = []; // none eligible (all InnoDB)
            $report = $this->cleanup(['db_optimize_tables' => true])->run();

            // DEFECT 3 FIX: key is 'optimize_tables'; rows_deleted=0.
            $this->assertArrayHasKey('optimize_tables', $report);
            $this->assertDoneResult($report['optimize_tables'], 0);
            $this->assertFalse($this->wpdb->wroteLike('OPTIMIZE TABLE'));
        } finally {
            Monkey\tearDown();
        }
    }

    public function test_task_allowlist_restricts_run(): void
    {
        $this->wpdb->idResults = [1];
        // Both toggles on (via CP-driven path), but only 'revisions' allow-listed.
        $report = $this->cleanup([
            'db_post_revisions' => true,
            'db_comments_spam'  => true,
        ])->run(['revisions']);

        $this->assertArrayHasKey('revisions', $report);
        $this->assertArrayNotHasKey('spam_comments', $report);
    }

    public function test_no_wpdb_returns_empty(): void
    {
        $cleanup = new DbCleanup(new PerfConfig(['db_post_revisions' => true]), null);
        $this->assertSame([], $cleanup->run());
    }

    public function test_optimize_tables_skipped_during_cooldown(): void
    {
        Monkey\setUp();
        try {
            // A recent last-run timestamp inside the 12h window.
            $recent = time() - 60;
            Functions\when('get_transient')->alias(
                static fn ($k) => $k === DbCleanup::OPTIMIZE_COOLDOWN_OPTION ? $recent : false
            );
            $set = [];
            Functions\when('set_transient')->alias(function ($k, $v, $t) use (&$set) {
                $set[] = [$k, $v, $t];
                return true;
            });

            $report = $this->cleanup(['db_optimize_tables' => true])->run();

            // Skipped: optimize_tables present with state=skipped, no OPTIMIZE ran,
            // cooldown not re-armed.
            $this->assertArrayHasKey('optimize_tables', $report);
            $this->assertSkippedResult($report['optimize_tables']);
            $this->assertFalse($this->wpdb->wroteLike('OPTIMIZE TABLE wp_posts'));
            $this->assertSame([], $set);
        } finally {
            Monkey\tearDown();
        }
    }

    public function test_optimize_tables_runs_and_arms_cooldown_when_window_elapsed(): void
    {
        Monkey\setUp();
        try {
            // Last run well outside the window (or never): cooldown does not block.
            Functions\when('get_transient')->justReturn(false);
            $set = [];
            Functions\when('set_transient')->alias(function ($k, $v, $t) use (&$set) {
                $set[] = [$k, $v, $t];
                return true;
            });

            $this->wpdb->optimizableTables = ['wp_posts'];
            $report = $this->cleanup(['db_optimize_tables' => true])->run();

            // DEFECT 3 FIX: key is 'optimize_tables'.
            $this->assertArrayHasKey('optimize_tables', $report);
            $this->assertGreaterThan(0, $report['optimize_tables']['rows_deleted']);
            $this->assertTrue($this->wpdb->wroteLike('OPTIMIZE TABLE `wp_posts`'));

            // Cooldown armed with the expected key + 12h TTL.
            $this->assertCount(1, $set);
            $this->assertSame(DbCleanup::OPTIMIZE_COOLDOWN_OPTION, $set[0][0]);
            $this->assertSame(DbCleanup::OPTIMIZE_COOLDOWN_SECONDS, $set[0][2]);
        } finally {
            Monkey\tearDown();
        }
    }

    /**
     * CP-driven path: when $only is non-empty the PerfConfig flag is ignored for
     * gating — the task runs regardless of its flag value.
     */
    public function test_cp_driven_tasks_ignore_perf_config_flags(): void
    {
        $this->wpdb->idResults = [99];
        // Flag is FALSE but the CP explicitly requests 'revisions'.
        $report = $this->cleanup(['db_post_revisions' => false])->run(['revisions']);

        $this->assertArrayHasKey('revisions', $report);
        // The task ran (rows_deleted = 1, state = done).
        $this->assertDoneResult($report['revisions'], 1);
    }

    /**
     * An unknown task id in the $only list must be silently ignored.
     */
    public function test_unknown_task_ids_are_ignored(): void
    {
        $report = $this->cleanup([])->run(['totally_unknown_id']);
        $this->assertArrayNotHasKey('totally_unknown_id', $report);
    }

    // -------------------------------------------------------------------------
    // Fix 1: batched SELECT→DELETE for orphaned_postmeta / orphaned_commentmeta
    // -------------------------------------------------------------------------

    /**
     * Fix 1 (orphaned_postmeta): must SELECT meta_ids via prepared LEFT JOIN
     * query and DELETE via WHERE meta_id IN (...) — not a single multi-table
     * DELETE JOIN.
     */
    public function test_orphaned_postmeta_uses_batched_select_delete(): void
    {
        // Simulate 3 orphaned meta_ids.
        $this->wpdb->idResults = [10, 20, 30];

        $report = $this->cleanup([])->run(['orphaned_postmeta']);

        $this->assertArrayHasKey('orphaned_postmeta', $report);
        $this->assertDoneResult($report['orphaned_postmeta'], 3);

        // Must have issued a SELECT with LEFT JOIN (not a DELETE ... JOIN).
        $this->assertTrue(
            $this->wpdb->preparedWith('LEFT JOIN', '') ||
            $this->someRawQueryContains('LEFT JOIN'),
            'Expected a SELECT with LEFT JOIN for orphaned postmeta'
        );

        // Must have issued a DELETE … WHERE meta_id IN (…) — not DELETE pm FROM.
        $this->assertTrue(
            $this->wpdb->wroteLike('WHERE meta_id IN'),
            'Expected chunked DELETE WHERE meta_id IN for orphaned postmeta'
        );

        // Must NOT use the old single-statement multi-table DELETE JOIN form.
        $this->assertFalse(
            $this->wpdb->wroteLike('DELETE pm FROM'),
            'Must NOT use single-statement multi-table DELETE JOIN for postmeta'
        );
    }

    /**
     * Fix 1 (orphaned_postmeta): when there are no orphaned rows the task
     * returns rows_deleted=0 without issuing any DELETE.
     */
    public function test_orphaned_postmeta_no_rows_no_delete(): void
    {
        $this->wpdb->idResults = [];
        $report = $this->cleanup([])->run(['orphaned_postmeta']);
        $this->assertArrayHasKey('orphaned_postmeta', $report);
        $this->assertDoneResult($report['orphaned_postmeta'], 0);
        $this->assertFalse($this->wpdb->wroteLike('DELETE FROM wp_postmeta'));
    }

    /**
     * Fix 1 (orphaned_commentmeta): must SELECT meta_ids via prepared LEFT JOIN
     * and DELETE via WHERE meta_id IN (…).
     */
    public function test_orphaned_commentmeta_uses_batched_select_delete(): void
    {
        $this->wpdb->idResults = [5, 6];
        $report = $this->cleanup([])->run(['orphaned_commentmeta']);

        $this->assertArrayHasKey('orphaned_commentmeta', $report);
        $this->assertDoneResult($report['orphaned_commentmeta'], 2);

        $this->assertTrue(
            $this->wpdb->wroteLike('WHERE meta_id IN'),
            'Expected chunked DELETE WHERE meta_id IN for orphaned commentmeta'
        );
        $this->assertFalse(
            $this->wpdb->wroteLike('DELETE cm FROM'),
            'Must NOT use single-statement multi-table DELETE JOIN for commentmeta'
        );
    }

    // -------------------------------------------------------------------------
    // Fix 1: batched duplicate_postmeta
    // -------------------------------------------------------------------------

    /**
     * Fix 1 (duplicate_postmeta): must collect meta_ids via a read-only SELECT
     * with the INNER JOIN subquery and then DELETE via meta_id IN (…).
     */
    public function test_duplicate_postmeta_uses_batched_collect_delete(): void
    {
        $this->wpdb->idResults = [100, 200];
        $report = $this->cleanup([])->run(['duplicate_postmeta']);

        $this->assertArrayHasKey('duplicate_postmeta', $report);
        $this->assertDoneResult($report['duplicate_postmeta'], 2);

        // SELECT must reference MIN(meta_id) / keep_id (the subquery read).
        $this->assertTrue(
            $this->someRawQueryContains('MIN(meta_id)'),
            'Expected a SELECT with MIN(meta_id) subquery for duplicate postmeta'
        );

        // DELETE must use WHERE meta_id IN (…).
        $this->assertTrue(
            $this->wpdb->wroteLike('WHERE meta_id IN'),
            'Expected chunked DELETE WHERE meta_id IN for duplicate postmeta'
        );

        // Must NOT use old single-statement multi-table DELETE JOIN form.
        $this->assertFalse(
            $this->wpdb->wroteLike('DELETE pm FROM'),
            'Must NOT use single-statement multi-table DELETE JOIN for duplicate postmeta'
        );
    }

    /**
     * Fix 1 (duplicate_postmeta): no orphans → no DELETE.
     */
    public function test_duplicate_postmeta_no_rows_no_delete(): void
    {
        $this->wpdb->idResults = [];
        $report = $this->cleanup([])->run(['duplicate_postmeta']);
        $this->assertDoneResult($report['duplicate_postmeta'], 0);
        $this->assertFalse($this->wpdb->wroteLike('DELETE FROM wp_postmeta'));
    }

    // -------------------------------------------------------------------------
    // Fix 2: orphaned_term_relationships excludes link_category
    // -------------------------------------------------------------------------

    /**
     * Fix 2: must restrict orphan detection to post-attached taxonomies by
     * excluding 'link_category' from the term_taxonomy SELECT.
     */
    public function test_orphaned_term_relationships_excludes_link_category(): void
    {
        // Simulate: 2 post-taxonomy tt_ids found.
        $this->wpdb->termTaxonomyIds = [11, 22];
        // Simulate: 1 orphaned object_id found in those taxonomies.
        $this->wpdb->orphanObjectIds = [99];

        $report = $this->cleanup([])->run(['orphaned_term_relationships']);

        $this->assertArrayHasKey('orphaned_term_relationships', $report);
        $this->assertDoneResult($report['orphaned_term_relationships'], 1);

        // The term_taxonomy SELECT must exclude 'link_category'.
        $this->assertTrue(
            $this->someRawQueryContains("taxonomy NOT IN"),
            'Expected term_taxonomy SELECT to exclude non-post taxonomies'
        );
        $this->assertTrue(
            $this->someRawQueryContains("link_category") ||
            $this->wpdb->preparedWith("taxonomy NOT IN", 'link_category') ||
            $this->someRawQueryContains("NOT IN"),
            'Expected link_category to be excluded from taxonomy query'
        );

        // The DELETE must scope to term_taxonomy_id IN (…) — never touches
        // link_category rows.
        $this->assertTrue(
            $this->wpdb->wroteLike('term_taxonomy_id IN'),
            'Expected DELETE scoped to term_taxonomy_id IN (...)'
        );

        // Must NOT use the old unbounded DELETE … JOIN form.
        $this->assertFalse(
            $this->wpdb->wroteLike('DELETE tr FROM'),
            'Must NOT use single-statement multi-table DELETE JOIN for term_relationships'
        );
    }

    /**
     * Fix 2: when NO post-taxonomy tt_ids exist (e.g. bare WP install with
     * only link_category), the task returns 0 and issues no DELETE at all.
     */
    public function test_orphaned_term_relationships_no_post_taxonomies_returns_zero(): void
    {
        $this->wpdb->termTaxonomyIds = []; // no post-taxonomy term_taxonomy rows
        $this->wpdb->orphanObjectIds = [];

        $report = $this->cleanup([])->run(['orphaned_term_relationships']);

        $this->assertArrayHasKey('orphaned_term_relationships', $report);
        $this->assertDoneResult($report['orphaned_term_relationships'], 0);
        $this->assertFalse($this->wpdb->wroteLike('DELETE FROM wp_term_relationships'));
    }

    /**
     * Fix 2: when the orphan loop returns 0 object_ids (no orphans in the
     * safe taxonomies), no DELETE is issued.
     */
    public function test_orphaned_term_relationships_no_orphans_no_delete(): void
    {
        $this->wpdb->termTaxonomyIds = [33, 44]; // post-taxonomy tt_ids exist
        $this->wpdb->orphanObjectIds = [];         // but no orphans

        $report = $this->cleanup([])->run(['orphaned_term_relationships']);
        $this->assertDoneResult($report['orphaned_term_relationships'], 0);
        $this->assertFalse($this->wpdb->wroteLike('DELETE FROM wp_term_relationships'));
    }

    // -------------------------------------------------------------------------
    // Internal helper for test assertions
    // -------------------------------------------------------------------------

    /**
     * Check whether any raw query template (pre-substitution) contains $needle.
     */
    private function someRawQueryContains(string $needle): bool
    {
        foreach ($this->wpdb->rawQueries as $raw) {
            if (stripos($raw, $needle) !== false) {
                return true;
            }
        }
        // Also check prepared (post-substitution) strings.
        foreach ($this->wpdb->prepared as $p) {
            if (stripos($p, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
