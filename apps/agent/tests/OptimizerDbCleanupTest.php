<?php
/**
 * DbCleanup tests: each task deletes ONLY the rows its toggle enables, returns a
 * count, and uses prepared statements; disabled toggles run nothing.
 *
 * A fake $wpdb records prepared queries and returns canned id sets so we can
 * assert exactly which DELETEs were issued.
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
        $this->assertSame(3, $report['revisions']);
        // No comment/transient/optimize work happened.
        $this->assertArrayNotHasKey('spam_comments', $report);
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
        $this->assertSame(1, $report['trashed_posts']);
        $this->assertTrue($this->wpdb->preparedWith('post_status = %s', 'trash'));
    }

    public function test_spam_comments_deletes_only_spam(): void
    {
        $this->wpdb->idResults = [7, 8];
        $report = $this->cleanup(['db_comments_spam' => true])->run();
        $this->assertSame(2, $report['spam_comments']);
        $this->assertTrue($this->wpdb->preparedWith('comment_approved = %s', 'spam'));
        $this->assertTrue($this->wpdb->wroteLike('DELETE FROM wp_comments WHERE comment_ID IN'));
    }

    public function test_optimize_tables_runs_optimize(): void
    {
        // Neutralise the cooldown (no last-run transient) so OPTIMIZE always runs.
        // Brain Monkey is used so the stubs are deterministic regardless of
        // whether an earlier test has already shimmed these WP functions.
        Monkey\setUp();
        try {
            Functions\when('get_transient')->justReturn(false);
            Functions\when('set_transient')->justReturn(true);

            // information_schema reports wp_posts as a non-InnoDB table with overhead.
            $this->wpdb->optimizableTables = ['wp_posts', 'wp_options'];
            $report = $this->cleanup(['db_optimize_tables' => true])->run();
            $this->assertArrayHasKey('optimized_tables', $report);
            $this->assertSame(2, $report['optimized_tables']);
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

            $this->assertSame(0, $report['optimized_tables']);
            $this->assertFalse($this->wpdb->wroteLike('OPTIMIZE TABLE'));
        } finally {
            Monkey\tearDown();
        }
    }

    public function test_task_allowlist_restricts_run(): void
    {
        $this->wpdb->idResults = [1];
        // Both toggles on, but only 'revisions' allow-listed.
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

            // Skipped: no optimized_tables in the report, no OPTIMIZE ran, cooldown
            // not re-armed.
            $this->assertArrayNotHasKey('optimized_tables', $report);
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

            $this->assertArrayHasKey('optimized_tables', $report);
            $this->assertGreaterThan(0, $report['optimized_tables']);
            $this->assertTrue($this->wpdb->wroteLike('OPTIMIZE TABLE `wp_posts`'));

            // Cooldown armed with the expected key + 12h TTL.
            $this->assertCount(1, $set);
            $this->assertSame(DbCleanup::OPTIMIZE_COOLDOWN_OPTION, $set[0][0]);
            $this->assertSame(DbCleanup::OPTIMIZE_COOLDOWN_SECONDS, $set[0][2]);
        } finally {
            Monkey\tearDown();
        }
    }
}
