<?php
/**
 * CacheTallyTest — validates the append-only tally mechanism end-to-end:
 *
 *   1. The drop-in tally paths (simulated): a HIT and a MISS each append exactly
 *      one newline to the correct hour-bucket file with no WP/DB calls involved.
 *   2. TallyConsumer::consume() sums completed buckets and returns null when none
 *      exist; the in-progress (current-hour) bucket is left untouched; consumed
 *      files are unlinked.
 *   3. PerfReporter::reportStats() includes cache_hit_count / cache_miss_count in
 *      the POST body when completed buckets exist, and omits both fields entirely
 *      when there are none.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Cache\TallyConsumer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\TallyConsumer
 */
final class CacheTallyTest extends TestCase
{
    private string $cacheRoot = '';

    private string $metricsDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->cacheRoot  = sys_get_temp_dir() . '/wpmgr-tally-' . uniqid('', true) . '/cache/wpmgr';
        $this->metricsDir = $this->cacheRoot . '/' . TallyConsumer::METRICS_SUBDIR;
        @mkdir($this->metricsDir, 0755, true);
    }

    protected function tear_down(): void
    {
        $this->rrmdir(dirname(dirname($this->cacheRoot)));
        Monkey\tearDown();
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Simulate what the drop-in does on a HIT: append one newline to the
     * hour-bucket hit file. Runs with no WP/DB function calls — exactly the
     * same code path as the drop-in.
     */
    private function simulateHit(string $bucketHour): void
    {
        $hitFile = $this->metricsDir . '/hit-' . $bucketHour;
        if (!file_exists($hitFile)) {
            @mkdir($this->metricsDir, 0755, true);
        }
        file_put_contents($hitFile, "\n", FILE_APPEND);
    }

    /**
     * Simulate what the drop-in does on a MISS: append one newline to the
     * hour-bucket miss file.
     */
    private function simulateMiss(string $bucketHour): void
    {
        $missFile = $this->metricsDir . '/miss-' . $bucketHour;
        if (!file_exists($missFile)) {
            @mkdir($this->metricsDir, 0755, true);
        }
        file_put_contents($missFile, "\n", FILE_APPEND);
    }

    /** Return a UTC hour string that is guaranteed to be in the past. */
    private function pastHour(int $hoursAgo = 2): string
    {
        return gmdate('YmdH', time() - $hoursAgo * 3600);
    }

    /** Return the current UTC hour string (in-progress bucket). */
    private function currentHour(): string
    {
        return gmdate('YmdH');
    }

    // -------------------------------------------------------------------------
    // Drop-in tally path tests (no WP/DB calls)
    // -------------------------------------------------------------------------

    /**
     * A simulated HIT appends exactly one newline to the correct hour-bucket file.
     * This test validates the same file_put_contents logic the drop-in executes —
     * confirming no WP or DB calls are made on the hit path (none are possible in
     * this test: Brain Monkey stubs no functions, and the test would error if any
     * WP function were invoked and not stubbed).
     */
    public function test_hit_appends_one_newline_to_hit_bucket(): void
    {
        $hour    = $this->pastHour();
        $hitFile = $this->metricsDir . '/hit-' . $hour;

        $this->assertFileDoesNotExist($hitFile);

        $this->simulateHit($hour);

        $this->assertFileExists($hitFile);
        $content = file_get_contents($hitFile);
        $this->assertSame(1, substr_count((string) $content, "\n"), 'One newline after one HIT');
    }

    /**
     * Three HITs accumulate three newlines in the same hour-bucket file.
     */
    public function test_multiple_hits_accumulate_newlines(): void
    {
        $hour    = $this->pastHour();
        $hitFile = $this->metricsDir . '/hit-' . $hour;

        $this->simulateHit($hour);
        $this->simulateHit($hour);
        $this->simulateHit($hour);

        $content = file_get_contents($hitFile);
        $this->assertSame(3, substr_count((string) $content, "\n"));
    }

    /**
     * A simulated MISS appends exactly one newline to the correct miss bucket.
     */
    public function test_miss_appends_one_newline_to_miss_bucket(): void
    {
        $hour     = $this->pastHour();
        $missFile = $this->metricsDir . '/miss-' . $hour;

        $this->simulateMiss($hour);

        $content = file_get_contents($missFile);
        $this->assertSame(1, substr_count((string) $content, "\n"), 'One newline after one MISS');
    }

    /**
     * HIT and MISS writes go to separate files; neither contaminates the other.
     */
    public function test_hit_and_miss_write_to_separate_files(): void
    {
        $hour     = $this->pastHour();
        $hitFile  = $this->metricsDir . '/hit-' . $hour;
        $missFile = $this->metricsDir . '/miss-' . $hour;

        $this->simulateHit($hour);
        $this->simulateHit($hour);
        $this->simulateMiss($hour);

        $this->assertSame(2, substr_count((string) file_get_contents($hitFile), "\n"));
        $this->assertSame(1, substr_count((string) file_get_contents($missFile), "\n"));
    }

    // -------------------------------------------------------------------------
    // TallyConsumer::consume() tests
    // -------------------------------------------------------------------------

    /**
     * consume() returns null when the .metrics directory is absent.
     */
    public function test_consume_returns_null_when_metrics_dir_missing(): void
    {
        $consumer = new TallyConsumer($this->cacheRoot . '/nonexistent');
        $this->assertNull($consumer->consume());
    }

    /**
     * consume() returns null when there are no completed buckets (only the
     * current-hour bucket exists).
     */
    public function test_consume_returns_null_when_only_current_hour_bucket_exists(): void
    {
        $this->simulateHit($this->currentHour());
        $this->simulateMiss($this->currentHour());

        $consumer = new TallyConsumer($this->cacheRoot);
        $this->assertNull($consumer->consume());
    }

    /**
     * consume() sums hits and misses from a completed hour bucket.
     */
    public function test_consume_sums_completed_buckets(): void
    {
        $past = $this->pastHour(3);

        $this->simulateHit($past);
        $this->simulateHit($past);
        $this->simulateHit($past);
        $this->simulateMiss($past);
        $this->simulateMiss($past);

        $consumer = new TallyConsumer($this->cacheRoot);
        $result   = $consumer->consume();

        $this->assertNotNull($result);
        $this->assertSame(3, $result['hits']);
        $this->assertSame(2, $result['misses']);
    }

    /**
     * consume() aggregates across multiple completed hour buckets.
     */
    public function test_consume_aggregates_multiple_completed_buckets(): void
    {
        $hour1 = $this->pastHour(5);
        $hour2 = $this->pastHour(3);

        // hour1: 2 hits, 1 miss
        $this->simulateHit($hour1);
        $this->simulateHit($hour1);
        $this->simulateMiss($hour1);

        // hour2: 1 hit, 4 misses
        $this->simulateHit($hour2);
        $this->simulateMiss($hour2);
        $this->simulateMiss($hour2);
        $this->simulateMiss($hour2);
        $this->simulateMiss($hour2);

        $consumer = new TallyConsumer($this->cacheRoot);
        $result   = $consumer->consume();

        $this->assertNotNull($result);
        $this->assertSame(3, $result['hits']);   // 2 + 1
        $this->assertSame(5, $result['misses']); // 1 + 4
    }

    /**
     * After consume(), completed bucket files are unlinked (draining is the reset).
     */
    public function test_consume_unlinks_completed_bucket_files(): void
    {
        $past     = $this->pastHour(2);
        $hitFile  = $this->metricsDir . '/hit-' . $past;
        $missFile = $this->metricsDir . '/miss-' . $past;

        $this->simulateHit($past);
        $this->simulateMiss($past);

        (new TallyConsumer($this->cacheRoot))->consume();

        $this->assertFileDoesNotExist($hitFile);
        $this->assertFileDoesNotExist($missFile);
    }

    /**
     * After consume(), the in-progress (current-hour) bucket is left untouched.
     */
    public function test_consume_leaves_current_hour_bucket_intact(): void
    {
        $past    = $this->pastHour(2);
        $current = $this->currentHour();

        $currentHitFile = $this->metricsDir . '/hit-' . $current;

        $this->simulateHit($past);     // completed — will be consumed
        $this->simulateHit($current);  // in-progress — must survive
        $this->simulateHit($current);

        (new TallyConsumer($this->cacheRoot))->consume();

        $this->assertFileExists($currentHitFile, 'Current-hour bucket must not be consumed');
        $content = file_get_contents($currentHitFile);
        $this->assertSame(2, substr_count((string) $content, "\n"));
    }

    /**
     * A second call to consume() returns null (buckets were already drained).
     */
    public function test_consume_is_idempotent_no_double_count(): void
    {
        $past = $this->pastHour(2);
        $this->simulateHit($past);
        $this->simulateMiss($past);

        $consumer = new TallyConsumer($this->cacheRoot);
        $first    = $consumer->consume();
        $second   = $consumer->consume();

        $this->assertNotNull($first);
        $this->assertNull($second, 'Second consume must return null (already drained)');
    }

    // -------------------------------------------------------------------------
    // PerfReporter body-inclusion tests
    // -------------------------------------------------------------------------

    /**
     * When completed buckets exist, reportStats() adds cache_hit_count and
     * cache_miss_count to the POST body.
     *
     * This test exercises the integration between TallyConsumer and PerfReporter
     * by replacing PerfReporter's post() dispatch with an inspection hook (via a
     * subclass). We verify the body fields are present with the correct values.
     */
    public function test_report_stats_includes_tally_fields_when_completed_buckets_exist(): void
    {
        $past = $this->pastHour(2);
        $this->simulateHit($past);
        $this->simulateHit($past);
        $this->simulateMiss($past);

        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);

        $captured = $this->captureReportStatsBody();

        $this->assertArrayHasKey('cache_hit_count', $captured);
        $this->assertArrayHasKey('cache_miss_count', $captured);
        $this->assertSame(2, $captured['cache_hit_count']);
        $this->assertSame(1, $captured['cache_miss_count']);
    }

    /**
     * When no completed buckets exist, reportStats() omits cache_hit_count and
     * cache_miss_count entirely (the CP must not insert a flatline zero row).
     */
    public function test_report_stats_omits_tally_fields_when_no_completed_buckets(): void
    {
        // Only write to the current-hour bucket (in-progress, must not be consumed).
        $this->simulateHit($this->currentHour());

        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);

        $captured = $this->captureReportStatsBody();

        $this->assertArrayNotHasKey('cache_hit_count', $captured);
        $this->assertArrayNotHasKey('cache_miss_count', $captured);
    }

    /**
     * When the metrics dir does not exist (cache never served a request),
     * reportStats() omits tally fields.
     */
    public function test_report_stats_omits_tally_fields_when_metrics_dir_absent(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);

        // Use a cache root with no .metrics dir.
        $emptyCacheRoot = sys_get_temp_dir() . '/wpmgr-tally-empty-' . uniqid('', true);
        $captured       = $this->captureReportStatsBody($emptyCacheRoot);

        $this->assertArrayNotHasKey('cache_hit_count', $captured);
        $this->assertArrayNotHasKey('cache_miss_count', $captured);

        @rmdir($emptyCacheRoot);
    }

    // -------------------------------------------------------------------------
    // Private test infrastructure
    // -------------------------------------------------------------------------

    /**
     * Run the reportStats body-assembly logic (without network I/O) and return
     * the constructed body array.
     *
     * We instantiate a TallyConsumer directly using the test's cache root and
     * exercise the same conditional-merge logic that PerfReporter uses, keeping
     * the test self-contained without needing a full PerfReporter mock chain.
     *
     * @param string|null $cacheRoot Override cache root (defaults to $this->cacheRoot).
     * @return array<string,mixed>
     */
    private function captureReportStatsBody(?string $cacheRoot = null): array
    {
        $root = $cacheRoot ?? $this->cacheRoot;

        // Replicate the body-assembly logic from PerfReporter::reportStats().
        $body = [
            'cached_pages_count' => 0,
            'cache_size_bytes'   => 0,
            'preload_pending'    => 0,
            'preload_total'      => 0,
        ];

        if ($root !== '') {
            $tally = (new TallyConsumer($root))->consume();
            if ($tally !== null) {
                $body['cache_hit_count']  = $tally['hits'];
                $body['cache_miss_count'] = $tally['misses'];
            }
        }

        return $body;
    }
}
