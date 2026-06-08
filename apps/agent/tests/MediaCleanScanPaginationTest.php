<?php
/**
 * MediaCleanScanPaginationTest — verifies that the scan applies offset/limit
 * to the UNUSED results list, not to the attachment walk (#190 root-cause fix).
 *
 * Root cause: the old implementation walked attachment rows [offset:offset+limit]
 * and filtered for unused within that slice. On a site with many USED attachments
 * at the start of the library, page 0 returned near-zero candidates even when
 * hundreds of unused ones existed elsewhere — total=463 but page 0 showed nothing.
 *
 * Fix: walk the FULL library, collect all unused candidates (up to SCAN_MAX), then
 * apply offset/limit to that unused list. offset=0 must return actual unused
 * candidates regardless of where they appear in the attachment table.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaCleanCommand;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\MediaCleanCommand
 */
final class MediaCleanScanPaginationTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null; // @phpstan-ignore-line
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Shared wpdb factory
    // =========================================================================

    /**
     * Build a wpdb stub that:
     *   - serves get_results for "post_type = 'attachment'" selects by slicing
     *     $attachmentRows using LIMIT/OFFSET parsed from the prepare()-expanded SQL.
     *   - marks IDs in $referencedIds as referenced via the _thumbnail_id col
     *     (served once to MediaReferenceIndex::indexFeaturedImages).
     *   - returns [] / '' for all other queries.
     *
     * Note: file_exists is NOT stubbed here because it is a PHP internal and
     * cannot be redefined with Brain\Monkey. The attachment rows use fake filesystem
     * paths that will not exist on disk; file_exists returns false naturally,
     * giving file_size=0, which is acceptable for these tests.
     *
     * @param list<array<string,string>> $attachmentRows
     * @param list<int>                  $referencedIds   IDs to mark as referenced.
     */
    private function makeWpdb(array $attachmentRows, array $referencedIds = []): object
    {
        return new class ($attachmentRows, $referencedIds) {
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $options  = 'wp_options';
            public string $termmeta = 'wp_termmeta';
            public string $usermeta = 'wp_usermeta';

            /** @var list<array<string,string>> */
            private array $rows;
            /** @var list<int> */
            private array $referencedIds;
            private bool  $thumbnailColServed = false;

            /**
             * @param list<array<string,string>> $rows
             * @param list<int>                  $referencedIds
             */
            public function __construct(array $rows, array $referencedIds)
            {
                $this->rows          = $rows;
                $this->referencedIds = $referencedIds;
            }

            /** @return list<array<string,string>> */
            public function get_results(string $sql, string $output): array
            {
                // _thumbnail_id featured-image scan (get_results with LEFT JOIN for attribution).
                // Served once; subsequent calls return [] to terminate any loop.
                if (
                    strpos($sql, '_thumbnail_id') !== false
                    && strpos($sql, 'REGEXP') !== false
                    && !$this->thumbnailColServed
                ) {
                    $this->thumbnailColServed = true;
                    $rows = [];
                    foreach ($this->referencedIds as $refId) {
                        $rows[] = ['meta_value' => (string)$refId, 'post_id' => '0', 'post_title' => ''];
                    }
                    return $rows;
                }

                // _product_image_gallery (get_results with LEFT JOIN).
                if (strpos($sql, '_product_image_gallery') !== false) {
                    return [];
                }

                // Attachment walk query.
                if (strpos($sql, "post_type = 'attachment'") !== false) {
                    preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $m);
                    $limit  = isset($m[1]) ? (int)$m[1] : count($this->rows);
                    $offset = isset($m[2]) ? (int)$m[2] : 0;
                    return array_slice($this->rows, $offset, $limit);
                }
                return [];
            }

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                return [];
            }

            public function get_var(string $sql): string
            {
                return '';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $pos = strpos($sql, '%d');
                    if ($pos !== false) {
                        $sql = substr_replace($sql, (string)(int)$arg, $pos, 2);
                        continue;
                    }
                    $pos = strpos($sql, '%s');
                    if ($pos !== false) {
                        $sql = substr_replace($sql, (string)$arg, $pos, 2);
                    }
                }
                return $sql;
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }
        };
    }

    /**
     * Stub the WP functions required by MediaCleanCommand::handleScan +
     * MediaReferenceIndex::build().
     */
    private function stubWpFunctions(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/var/www/html/wp-content/uploads',
        ]);

        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) {
                if ($key === '_wp_attached_file') {
                    // Relative path that will not exist on disk: file_size will be 0.
                    return "tests/img{$id}.jpg";
                }
                return [];
            }
        );

        Functions\when('wp_get_attachment_image_src')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\when('is_serialized')->justReturn(false);
        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string)$s));
        // admin_url is called by buildPostEditUrl() for attribution; return a safe stub.
        Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
    }

    // =========================================================================
    // Test: unused candidates NOT in first attachment slice
    // =========================================================================

    /**
     * Scenario: 300 attachments total.
     *   - IDs 1-250 are USED (referenced via _thumbnail_id).
     *   - IDs 251-300 are UNUSED.
     *
     * With the OLD buggy implementation, scanning with offset=0&limit=100 would
     * fetch attachment rows [0,100) (all USED) and return 0 candidates even though
     * 50 unused attachments exist later in the library.
     *
     * With the FIX, the scan walks the full library, finds all 50 unused ones,
     * and offset=0&limit=100 returns them all.
     */
    public function testOffset0ReturnsActualUnusedCandidatesWhenUnusedAreNotInFirstSlice(): void
    {
        global $wpdb;

        // Build 300 attachment rows: IDs 1..250 used, 251..300 unused.
        $allRows = [];
        for ($i = 1; $i <= 300; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Attachment {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        $referencedIds = range(1, 250);

        $wpdb = $this->makeWpdb($allRows, $referencedIds); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue(
            $result['ok'],
            'Scan must succeed. Detail: ' . ($result['detail'] ?? '(none)')
        );

        // total = 50 (unused count), NOT 300 (all attachments).
        $this->assertSame(
            50,
            $result['total'],
            'total must reflect unused count (50), not all-attachments count (300).'
        );

        // offset=0 must not be empty.
        $this->assertNotEmpty(
            $result['candidates'],
            'offset=0 must return actual unused candidates even when they are past the first attachment batch.'
        );

        // All returned candidates must come from the unused range (IDs 251-300).
        foreach ($result['candidates'] as $c) {
            $this->assertGreaterThan(
                250,
                $c['id'],
                "Candidate ID {$c['id']} is in the USED range — scan must not flag referenced attachments."
            );
        }

        // truncated must be false (50 unused < SCAN_MAX=500).
        $this->assertFalse(
            $result['truncated'],
            'truncated must be false when unused count is below SCAN_MAX.'
        );
    }

    // =========================================================================
    // Test: offset/limit slices the unused list
    // =========================================================================

    /**
     * Scenario: 5 unused attachments. offset=2, limit=2 must return candidates
     * at positions [2] and [3] of the unused list (IDs 3 and 4), not IDs 1 and 2.
     */
    public function testOffsetLimitSlicesTheUnusedList(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 5; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Orphan {$i}",
                'guid'       => "https://example.com/wp-content/uploads/orphan{$i}.jpg",
            ];
        }

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 2, 'limit' => 2]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');
        $this->assertSame(5, $result['total'], 'total must be 5 (all are unused).');
        $this->assertCount(2, $result['candidates'], 'offset=2, limit=2 must return exactly 2 candidates.');

        $ids = array_column($result['candidates'], 'id');
        $this->assertContains(3, $ids, 'Unused candidate at position 2 must be ID 3.');
        $this->assertContains(4, $ids, 'Unused candidate at position 3 must be ID 4.');
        $this->assertNotContains(1, $ids, 'ID 1 (position 0) must not appear at offset=2.');
        $this->assertNotContains(5, $ids, 'ID 5 (position 4) must not appear with limit=2.');
    }

    // =========================================================================
    // Test: offset beyond total returns empty candidates
    // =========================================================================

    /**
     * When offset exceeds the unused count, candidates must be empty and total
     * still reflects the full unused count.
     */
    public function testOffsetBeyondTotalReturnsEmptyCandidates(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 50; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Orphan {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 100, 'limit' => 10]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');
        $this->assertSame(50, $result['total'], 'total must be the full unused count (50).');
        $this->assertEmpty($result['candidates'], 'offset beyond total must return an empty candidates array.');
    }

    // =========================================================================
    // Test: multi-batch accumulation — unused spread across multiple 200-row
    //       walk batches are ALL collected (#190 batch-collapse regression).
    // =========================================================================

    /**
     * Scenario: 600 attachments across THREE 200-row walk batches.
     *   Batch 1 (IDs   1–200): IDs   1–170 used,   171–200 unused (30 unused).
     *   Batch 2 (IDs 201–400): IDs 201–400 all used              ( 0 unused).
     *   Batch 3 (IDs 401–600): IDs 401–560 used,   561–600 unused (40 unused).
     *
     * Total known unused: 70 (30 from batch 1, 40 from batch 3).
     *
     * With the pre-fix "overwrite" bug, $allUnused would be reassigned per batch,
     * so only the final-batch partial set (≤40) would survive — total would be 40,
     * and the batch-1 unused (IDs 171–200) would be invisible.
     *
     * With the correct fix, $allUnused accumulates across all batches; total=70
     * and offset=0&limit=500 returns EXACTLY the union of batch-1 + batch-3 unused.
     *
     * This test catches BOTH the batch-collapse (overwrite) bug and any off-by-one
     * that would drop the first or last unused row of any batch.
     */
    public function testMultiBatchAccumulationCollectsUnusedFromAllBatches(): void
    {
        global $wpdb;

        // Build 600 attachment rows: IDs 1..600.
        $allRows = [];
        for ($i = 1; $i <= 600; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Attachment {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        // Referenced: IDs 1–170 (batch 1 used portion) + IDs 201–400 (all of batch 2)
        //           + IDs 401–560 (batch 3 used portion).
        // Unused:    IDs 171–200 (30 items, in batch 1)
        //          + IDs 561–600 (40 items, in batch 3).
        $referencedIds = array_merge(
            range(1, 170),
            range(201, 400),
            range(401, 560)
        );

        $wpdb = $this->makeWpdb($allRows, $referencedIds); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 500]);

        $this->assertTrue(
            $result['ok'],
            'Scan must succeed. Detail: ' . ($result['detail'] ?? '(none)')
        );

        // total must be the FULL unused count across all batches (70), NOT the
        // last-batch count (40) that the overwrite bug would have produced.
        $this->assertSame(
            70,
            $result['total'],
            'total must be 70 (30 unused in batch 1 + 40 unused in batch 3); ' .
            'a batch-collapse bug would produce 40 (only last batch survives).'
        );

        $this->assertFalse(
            $result['truncated'],
            'truncated must be false (70 unused < SCAN_MAX=500).'
        );

        // All 70 unused must be present in candidates.
        $this->assertCount(
            70,
            $result['candidates'],
            'candidates must contain all 70 unused attachments (offset=0, limit=500 >= 70).'
        );

        $candidateIds = array_column($result['candidates'], 'id');
        sort($candidateIds);

        // Every ID in the batch-1 unused range must be present.
        $expectedBatch1Unused = range(171, 200); // 30 IDs
        foreach ($expectedBatch1Unused as $expectedId) {
            $this->assertContains(
                $expectedId,
                $candidateIds,
                "Unused ID {$expectedId} (batch 1, first-batch unused) must appear in candidates. " .
                "A batch-collapse bug would drop all batch-1 unused."
            );
        }

        // Every ID in the batch-3 unused range must be present.
        $expectedBatch3Unused = range(561, 600); // 40 IDs
        foreach ($expectedBatch3Unused as $expectedId) {
            $this->assertContains(
                $expectedId,
                $candidateIds,
                "Unused ID {$expectedId} (batch 3, last-batch unused) must appear in candidates."
            );
        }

        // No referenced ID must appear.
        foreach ($referencedIds as $refId) {
            $this->assertNotContains(
                $refId,
                $candidateIds,
                "Referenced ID {$refId} must NOT appear in unused candidates."
            );
        }

        // Verify the exact set: IDs 171–200 union 561–600 and nothing else.
        $expectedSet = array_merge(range(171, 200), range(561, 600));
        sort($expectedSet);
        $this->assertSame(
            $expectedSet,
            $candidateIds,
            'candidates must be EXACTLY the known unused set: IDs 171–200 and 561–600.'
        );
    }

    // =========================================================================
    // Test: multi-batch pagination — offset/limit applied to the FULL unused
    //       list across all batches, not to a per-batch window.
    // =========================================================================

    /**
     * Uses the same 600-attachment library (30 unused in batch 1, 40 in batch 3).
     * Requests offset=25 (past the first 25 of batch-1 unused), limit=20.
     *
     * Correct result: unused list is [171,…,200, 561,…,600] (sorted by ID ASC
     * because the attachment walk is ORDER BY ID ASC). Positions [25..44]:
     *   - positions 25–29 → IDs 196–200 (last 5 of batch-1 unused)
     *   - positions 30–44 → IDs 561–575 (first 15 of batch-3 unused)
     *
     * This catches an off-by-one where the slice boundary between batches
     * causes the fencepost row to be dropped or duplicated.
     */
    public function testMultiBatchPaginationSlicesAcrossBatchBoundary(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 1; $i <= 600; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Attachment {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        $referencedIds = array_merge(
            range(1, 170),
            range(201, 400),
            range(401, 560)
        );

        $wpdb = $this->makeWpdb($allRows, $referencedIds); // @phpstan-ignore-line
        $this->stubWpFunctions();

        $cmd    = new MediaCleanCommand();
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 25, 'limit' => 20]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');
        $this->assertSame(70, $result['total'], 'total must be 70 regardless of offset/limit.');
        $this->assertCount(20, $result['candidates'], 'Must return exactly 20 candidates for limit=20.');

        // The full unused list in order: 171–200 (positions 0–29), 561–600 (positions 30–69).
        // offset=25, limit=20 → positions 25–44 → IDs [196,197,198,199,200,561,562,...,575].
        $expectedIds = array_merge(range(196, 200), range(561, 575));

        $actualIds = array_column($result['candidates'], 'id');
        $this->assertSame(
            $expectedIds,
            $actualIds,
            'Paginated slice at offset=25, limit=20 must span the batch-1/batch-3 boundary exactly. ' .
            'An off-by-one at the boundary would drop or duplicate IDs 200 or 561.'
        );
    }
}
