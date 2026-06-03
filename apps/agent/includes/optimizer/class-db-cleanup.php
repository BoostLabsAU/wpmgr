<?php
/**
 * DbCleanup — bounded, prepared-statement database housekeeping.
 *
 * Each task is gated by its own PerfConfig flag (and may be further restricted
 * by an explicit task allow-list the command passes), deletes ONLY the rows that
 * task targets, and returns a count. Every statement is parameterised via
 * $wpdb->prepare(); table names come from the trusted $wpdb->prefix (never user
 * input). The OPTIMIZE TABLE pass runs last over the core tables.
 *
 * Tasks:
 *   revisions          DELETE posts WHERE post_type='revision'
 *   auto_drafts        DELETE posts WHERE post_status='auto-draft'
 *   trashed_posts      DELETE posts WHERE post_status='trash'
 *   spam_comments      DELETE comments WHERE comment_approved='spam'
 *   trashed_comments   DELETE comments WHERE comment_approved='trash'
 *   expired_transients DELETE expired _transient_timeout_* options (+ their twins)
 *   optimize_tables    OPTIMIZE TABLE on posts/postmeta/options/comments/...
 *
 * Post deletes also remove the orphaned postmeta/term relationships for the
 * deleted ids; comment deletes remove their commentmeta. No correctness risk:
 * revisions/auto-drafts/trash/spam are all non-published, disposable rows.
 *
 * Standard DB-cleanup technique (WP-Sweep / Advanced Database Cleaner, GPLv2);
 * original implementation.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Runs the gated database-cleanup tasks and reports counts.
 */
final class DbCleanup
{
    /** Transient holding the last OPTIMIZE-TABLE run time (Unix seconds). */
    public const OPTIMIZE_COOLDOWN_OPTION = 'wpmgr_db_optimize_last';

    /** Minimum gap between OPTIMIZE-TABLE passes, in seconds (12h). */
    public const OPTIMIZE_COOLDOWN_SECONDS = 12 * 3600;

    private PerfConfig $config;

    /** @var \wpdb|null WordPress DB handle. */
    private ?object $wpdb;

    /**
     * @param PerfConfig|null $config Optimization config (DB flags).
     * @param object|null     $wpdb   Injected $wpdb (tests); defaults to global.
     */
    public function __construct(?PerfConfig $config = null, ?object $wpdb = null)
    {
        $this->config = $config ?? PerfConfig::load();
        $this->wpdb   = $wpdb ?? (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null);
    }

    /**
     * Run every enabled task (optionally restricted to $only), returning counts.
     *
     * @param list<string> $only If non-empty, only run these task keys.
     * @return array<string,int> Task key => rows affected.
     */
    public function run(array $only = []): array
    {
        $report = [];
        if ($this->wpdb === null) {
            return $report;
        }

        $tasks = [
            'revisions'          => fn (): int => $this->config->dbPostRevisions ? $this->deletePostsByType('revision') : -1,
            'auto_drafts'        => fn (): int => $this->config->dbPostAutoDrafts ? $this->deletePostsByStatus('auto-draft') : -1,
            'trashed_posts'      => fn (): int => $this->config->dbPostTrashed ? $this->deletePostsByStatus('trash') : -1,
            'spam_comments'      => fn (): int => $this->config->dbCommentsSpam ? $this->deleteCommentsByStatus('spam') : -1,
            'trashed_comments'   => fn (): int => $this->config->dbCommentsTrashed ? $this->deleteCommentsByStatus('trash') : -1,
            'expired_transients' => fn (): int => $this->config->dbTransientsExpired ? $this->deleteExpiredTransients() : -1,
        ];

        foreach ($tasks as $key => $runner) {
            if ($only !== [] && !in_array($key, $only, true)) {
                continue;
            }
            $count = $runner();
            if ($count >= 0) {
                $report[$key] = $count;
            }
        }

        if ($this->config->dbOptimizeTables && ($only === [] || in_array('optimize_tables', $only, true))) {
            $optimized = $this->optimizeTables();
            // -1 => skipped by the cooldown; omit from the report (mirrors the
            // disabled-task convention above).
            if ($optimized >= 0) {
                $report['optimized_tables'] = $optimized;
            }
        }

        return $report;
    }

    /**
     * Delete all posts of a given post_type (+ their postmeta/term rels).
     *
     * @param string $type post_type value (e.g. 'revision').
     * @return int Rows deleted from the posts table.
     */
    private function deletePostsByType(string $type): int
    {
        $posts = $this->table('posts');
        $ids   = $this->ids("SELECT ID FROM {$posts} WHERE post_type = %s", $type);
        return $this->deletePostIds($ids);
    }

    /**
     * Delete all posts with a given post_status (+ their postmeta/term rels).
     *
     * @param string $status post_status value (e.g. 'trash', 'auto-draft').
     * @return int Rows deleted from the posts table.
     */
    private function deletePostsByStatus(string $status): int
    {
        $posts = $this->table('posts');
        $ids   = $this->ids("SELECT ID FROM {$posts} WHERE post_status = %s", $status);
        return $this->deletePostIds($ids);
    }

    /**
     * Delete a set of post ids plus their orphaned postmeta + term relationships.
     *
     * @param list<int> $ids Post ids.
     * @return int Posts deleted.
     */
    private function deletePostIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $posts    = $this->table('posts');
        $postmeta = $this->table('postmeta');
        $termRel  = $this->table('term_relationships');

        $deleted = 0;
        // Chunk to keep the IN() list bounded.
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $this->query("DELETE FROM {$postmeta} WHERE post_id IN ({$placeholders})", $chunk);
            $this->query("DELETE FROM {$termRel} WHERE object_id IN ({$placeholders})", $chunk);
            $deleted += (int) $this->query("DELETE FROM {$posts} WHERE ID IN ({$placeholders})", $chunk);
        }
        return $deleted;
    }

    /**
     * Delete comments with a given approval status (+ their commentmeta).
     *
     * @param string $status comment_approved value ('spam' | 'trash').
     * @return int Comments deleted.
     */
    private function deleteCommentsByStatus(string $status): int
    {
        $comments    = $this->table('comments');
        $commentmeta = $this->table('commentmeta');

        $ids = $this->ids("SELECT comment_ID FROM {$comments} WHERE comment_approved = %s", $status);
        if ($ids === []) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $this->query("DELETE FROM {$commentmeta} WHERE comment_id IN ({$placeholders})", $chunk);
            $deleted += (int) $this->query("DELETE FROM {$comments} WHERE comment_ID IN ({$placeholders})", $chunk);
        }
        return $deleted;
    }

    /**
     * Delete expired transients (the _transient_timeout_* rows whose value is in
     * the past, plus their paired _transient_* value rows).
     *
     * @return int Option rows deleted.
     */
    private function deleteExpiredTransients(): int
    {
        $options = $this->table('options');
        $now     = time();

        // Find expired timeout rows.
        $rows = $this->getResults(
            "SELECT option_name FROM {$options} WHERE option_name LIKE %s AND option_value < %d",
            ['\_transient\_timeout\_%', $now]
        );
        if ($rows === []) {
            return 0;
        }

        $names = [];
        foreach ($rows as $row) {
            $timeoutName = is_array($row) ? (string) ($row['option_name'] ?? '') : '';
            if ($timeoutName === '') {
                continue;
            }
            $names[] = $timeoutName;
            // Paired value row: _transient_<key>.
            $names[] = '_transient_' . substr($timeoutName, strlen('_transient_timeout_'));
        }
        if ($names === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($names, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $deleted += (int) $this->query("DELETE FROM {$options} WHERE option_name IN ({$placeholders})", $chunk);
        }
        return $deleted;
    }

    /**
     * OPTIMIZE the core tables to reclaim space after the deletes.
     *
     * Gated by a per-site cooldown (a transient): OPTIMIZE TABLE locks/rebuilds
     * each table and is expensive on large sites, so we run it at most once every
     * {@see OPTIMIZE_COOLDOWN_SECONDS}. A run inside the window is a no-op and
     * returns -1 (the caller omits it from the report).
     *
     * @return int Number of tables optimized, or -1 when skipped by the cooldown.
     */
    private function optimizeTables(): int
    {
        $now = time();
        if (function_exists('get_transient')) {
            $last = get_transient(self::OPTIMIZE_COOLDOWN_OPTION);
            if (is_numeric($last) && ($now - (int) $last) < self::OPTIMIZE_COOLDOWN_SECONDS) {
                return -1; // within the cooldown window — skip this pass
            }
        }

        if ($this->wpdb === null || !method_exists($this->wpdb, 'query')) {
            return 0;
        }

        $candidates = [];
        foreach (['posts', 'postmeta', 'options', 'comments', 'commentmeta', 'term_relationships'] as $name) {
            $candidates[] = $this->table($name);
        }

        // SAFETY (never OPTIMIZE InnoDB): OPTIMIZE TABLE on an InnoDB table rebuilds
        // the whole table under a write lock — on a large live site that is a
        // multi-second-to-minute outage. So restrict the pass to tables that are
        // NOT InnoDB and that actually have reclaimable overhead (DATA_FREE > 0),
        // determined from information_schema. On a modern all-InnoDB site this
        // correctly optimizes nothing.
        $optimizable = $this->reclaimableNonInnoDBTables($candidates);

        $count = 0;
        foreach ($optimizable as $table) {
            // $table comes from information_schema for our own prefix (no user
            // input). OPTIMIZE TABLE takes no placeholders; backtick-quote defensively.
            $this->wpdb->query('OPTIMIZE TABLE `' . str_replace('`', '', $table) . '`');
            $count++;
        }

        // Arm the cooldown only if we actually ran a pass.
        if ($count > 0 && function_exists('set_transient')) {
            set_transient(self::OPTIMIZE_COOLDOWN_OPTION, $now, self::OPTIMIZE_COOLDOWN_SECONDS);
        }

        return $count;
    }

    /**
     * Of the given prefixed table names, return those whose storage engine is NOT
     * InnoDB and which carry reclaimable overhead (DATA_FREE > 0). InnoDB is
     * intentionally excluded because OPTIMIZE TABLE locks and rebuilds it.
     *
     * @param string[] $tables
     * @return string[]
     */
    private function reclaimableNonInnoDBTables(array $tables): array
    {
        if ($tables === [] || $this->wpdb === null || !method_exists($this->wpdb, 'get_col')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $sql = "SELECT TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB'
                  AND DATA_FREE > 0
                  AND TABLE_NAME IN ($placeholders)";
        if (method_exists($this->wpdb, 'prepare')) {
            /** @var string $sql */
            $sql = $this->wpdb->prepare($sql, $tables);
        }
        $rows = $this->wpdb->get_col($sql);
        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    // -------------------------------------------------------------------------
    // wpdb helpers
    // -------------------------------------------------------------------------

    /**
     * Fully-qualified table name from the trusted prefix.
     *
     * @param string $name Unprefixed core table name.
     * @return string
     */
    private function table(string $name): string
    {
        $prefix = ($this->wpdb !== null && isset($this->wpdb->prefix) && is_string($this->wpdb->prefix) && $this->wpdb->prefix !== '')
            ? $this->wpdb->prefix
            : 'wp_';
        return $prefix . $name;
    }

    /**
     * Run a prepared SELECT and return an integer id column.
     *
     * @param string       $sql  SQL with %s/%d placeholders.
     * @param mixed        ...$args Bound args.
     * @return list<int>
     */
    private function ids(string $sql, ...$args): array
    {
        if ($this->wpdb === null || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_col')) {
            return [];
        }
        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            return [];
        }
        $col = $this->wpdb->get_col($prepared);
        if (!is_array($col)) {
            return [];
        }
        return array_map('intval', $col);
    }

    /**
     * Run a prepared SELECT returning associative rows.
     *
     * @param string            $sql  SQL with placeholders.
     * @param array<int,mixed>  $args Bound args.
     * @return list<array<string,mixed>>
     */
    private function getResults(string $sql, array $args): array
    {
        if ($this->wpdb === null || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'get_results')) {
            return [];
        }
        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            return [];
        }
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Run a prepared write and return the affected-row count.
     *
     * @param string           $sql  SQL with placeholders.
     * @param array<int,mixed> $args Bound args.
     * @return int
     */
    private function query(string $sql, array $args): int
    {
        if ($this->wpdb === null || !method_exists($this->wpdb, 'prepare') || !method_exists($this->wpdb, 'query')) {
            return 0;
        }
        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            return 0;
        }
        $result = $this->wpdb->query($prepared);
        return is_numeric($result) ? (int) $result : 0;
    }
}
