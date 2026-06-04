<?php
/**
 * Minimal in-memory $wpdb double for the PreloadQueue table I/O exercised by the
 * cache command tests. Emulates only the surface PreloadQueue::addTask() and the
 * COUNT helpers touch:
 *
 *   - prepare(): JSON-encodes {sql, args} (same convention as FakeWpdb).
 *   - query():   handles the addTask upsert (dedup by task_hash) and returns
 *                rows_affected; also accepts the DELETE clearQueue.
 *   - get_var(): handles the pending/processing/failed COUNT(*) queries.
 *
 * It is intentionally NOT a general SQL engine — it pattern-matches the exact
 * queries PreloadQueue emits so unit tests can assert queued counts without a DB.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

/**
 * In-memory preload-queue table double.
 */
final class FakePreloadQueueWpdb
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    public int $rows_affected = 0;

    /** @var array<string,array{status:string}> task_hash => row */
    private array $rows = [];

    private int $nextId = 0;

    /**
     * @param string $query  Query with %s/%d placeholders.
     * @param mixed  ...$args Bound arguments.
     * @return string JSON token decoded by the methods below.
     */
    public function prepare(string $query, ...$args): string
    {
        return json_encode(['sql' => $query, 'args' => $args]) ?: '';
    }

    /**
     * @param string $prepared Output of prepare().
     * @return int Rows affected.
     */
    public function query(string $prepared): int
    {
        $decoded = json_decode($prepared, true);
        if (!is_array($decoded)) {
            $this->rows_affected = 0;
            return 0;
        }
        $sql  = (string) ($decoded['sql'] ?? '');
        $args = is_array($decoded['args'] ?? null) ? $decoded['args'] : [];

        if (strpos($sql, 'INSERT INTO') !== false) {
            // addTask upsert: args = [group, callback, url, device, hash, priority].
            $hash = (string) ($args[4] ?? '');
            if ($hash !== '' && !isset($this->rows[$hash])) {
                $this->nextId++;
                $this->insert_id    = $this->nextId;
                $this->rows[$hash]  = ['status' => 'pending'];
                $this->rows_affected = 1;
            } else {
                // Conflict: keep existing id (no new row).
                $this->rows_affected = 2; // mysql reports 2 on an ON DUP UPDATE.
            }
            return $this->rows_affected;
        }

        if (strpos($sql, 'DELETE FROM') !== false) {
            $deleted = count($this->rows);
            $this->rows = [];
            $this->rows_affected = $deleted;
            return $deleted;
        }

        // UPDATE (stale-requeue / claim) — no claimable rows are exercised here.
        $this->rows_affected = 0;
        return 0;
    }

    /**
     * @param string $prepared Output of prepare().
     * @return string COUNT(*) result.
     */
    public function get_var(string $prepared): string
    {
        $decoded = json_decode($prepared, true);
        $sql     = is_array($decoded) ? (string) ($decoded['sql'] ?? '') : '';

        if (strpos($sql, "status IN ('pending','processing')") !== false) {
            $count = 0;
            foreach ($this->rows as $row) {
                if (in_array($row['status'], ['pending', 'processing'], true)) {
                    $count++;
                }
            }
            return (string) $count;
        }

        // SHOW TABLES LIKE — report the table exists.
        if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
            return $this->prefix . 'wpmgr_preload_queue';
        }

        // Other COUNTs (processing/failed/claimable/active) — none seeded here.
        return '0';
    }

    /**
     * @param string $prepared Output of prepare().
     * @return array<int,array<string,mixed>>|null
     */
    public function get_results(string $prepared, $output = null): ?array
    {
        return [];
    }

    /**
     * @param string $prepared Output of prepare().
     * @return array<string,mixed>|null
     */
    public function get_row(string $prepared, $output = null): ?array
    {
        return null;
    }
}
