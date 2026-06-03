<?php
/**
 * Minimal $wpdb double for DbCleanup tests: records prepared statements + writes
 * and returns canned id sets so the cleanup tasks can be asserted precisely.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

/**
 * Emulates the wpdb surface DbCleanup touches (prepare/get_col/get_results/query).
 */
final class FakeCleanupWpdb
{
    public string $prefix = 'wp_';

    /** @var list<int> Canned ids returned by get_col(). */
    public array $idResults = [];

    /** @var list<string> Canned table names returned by get_col() for the
     *  information_schema (non-InnoDB / DATA_FREE) optimize-eligibility query. */
    public array $optimizableTables = [];

    /** @var list<string> Prepared statement strings (post-substitution). */
    public array $prepared = [];

    /** @var list<string> Raw (pre-substitution) query templates. */
    public array $rawQueries = [];

    /** @var list<string> Write statements executed via query(). */
    public array $writes = [];

    /**
     * @param string $query SQL with placeholders.
     * @param mixed  ...$args Bound args.
     * @return string
     */
    public function prepare(string $query, ...$args): string
    {
        $this->rawQueries[] = $query;

        // Flatten a possible single array arg (DbCleanup spreads array chunks).
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
        $i = 0;
        $sql = preg_replace_callback('/%[sd]/', static function ($m) use (&$i, $flat) {
            $v = $flat[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $v : "'" . $v . "'";
        }, $query);
        $this->prepared[] = (string) $sql;
        return (string) $sql;
    }

    /**
     * @param string $sql Prepared SELECT.
     * @return list<int>
     */
    public function get_col(string $sql): array
    {
        // The optimize-eligibility query (information_schema engine + DATA_FREE)
        // returns table NAMES; every other get_col returns the canned id list.
        if (stripos($sql, 'information_schema') !== false || stripos($sql, 'DATA_FREE') !== false) {
            return $this->optimizableTables;
        }
        return $this->idResults;
    }

    /**
     * @param string $sql  Prepared SELECT.
     * @param mixed  $mode Output mode (ignored).
     * @return list<array<string,mixed>>
     */
    public function get_results(string $sql, $mode = null): array
    {
        return [];
    }

    /**
     * @param string $sql Statement.
     * @return int Affected rows.
     */
    public function query(string $sql): int
    {
        $this->writes[] = $sql;
        if (stripos($sql, 'DELETE FROM') === 0 && preg_match('/IN \(([^)]*)\)/', $sql, $m)) {
            return count(array_filter(explode(',', $m[1]), static fn ($x) => trim($x) !== ''));
        }
        return 1;
    }

    /**
     * Did any prepared statement bind $value (post-substitution) AND carry the
     * column fragment (matched against the RAW template, which still has %s/%d)?
     *
     * @param string $rawFragment SQL fragment with the placeholder (e.g. "post_type = %s").
     * @param string $value       Expected bound value.
     * @return bool
     */
    public function preparedWith(string $rawFragment, string $value): bool
    {
        $sawFragment = false;
        foreach ($this->rawQueries as $raw) {
            if (strpos($raw, $rawFragment) !== false) {
                $sawFragment = true;
                break;
            }
        }
        if (!$sawFragment) {
            return false;
        }
        foreach ($this->prepared as $p) {
            if (strpos($p, "'" . $value . "'") !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Did any executed write contain $needle?
     *
     * @param string $needle SQL fragment.
     * @return bool
     */
    public function wroteLike(string $needle): bool
    {
        foreach ($this->writes as $w) {
            if (strpos($w, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
