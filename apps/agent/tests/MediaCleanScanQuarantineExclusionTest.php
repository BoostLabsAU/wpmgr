<?php
/**
 * MediaCleanScanQuarantineExclusionTest — verifies that the scan action
 * excludes attachments whose IDs are already recorded in a quarantine manifest
 * from the candidate list, the total_attachments count, and the unused count.
 *
 * Three scenarios are covered:
 *
 *   1. Quarantined + unreferenced: an attachment that is both unreferenced and
 *      present in a quarantine manifest must NOT appear in candidates; it must
 *      NOT be counted in total_attachments or unused_count; quarantined_count
 *      must reflect it.
 *
 *   2. Non-quarantined unreferenced (regression guard): an attachment that is
 *      unreferenced and NOT in any manifest must still appear in candidates.
 *
 *   3. Empty / absent quarantine dir: quarantined_count must be 0 and the scan
 *      must behave exactly as without any quarantine.
 *
 * The MediaQuarantine instance is injected via the constructor so filesystem I/O
 * is fully in-process and isolated. The wpdb double mirrors the one used in
 * MediaCleanScanPaginationTest.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\MediaCleanCommand;
use WPMgr\Agent\Media\MediaQuarantine;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\MediaCleanCommand
 * @covers \WPMgr\Agent\Media\MediaQuarantine
 */
final class MediaCleanScanQuarantineExclusionTest extends TestCase
{
    /** Temp directory that acts as wp-content for tests that write manifests. */
    private string $wpContent = '';

    /** Temp uploads dir (simulates wp-content/uploads). */
    private string $uploadsDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // Create an isolated temp tree per test.
        $tmp = sys_get_temp_dir() . '/wpmgr-qex-' . bin2hex(random_bytes(6));
        $this->wpContent  = $tmp . '/wp-content';
        $this->uploadsDir = $this->wpContent . '/uploads';
        mkdir($this->uploadsDir . '/2024/01', 0755, true);
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null; // @phpstan-ignore-line

        $this->rrmdir(dirname($this->wpContent));

        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Build a MediaQuarantine instance backed by the test's isolated temp tree.
     */
    private function makeQuarantine(): MediaQuarantine
    {
        return new MediaQuarantine($this->wpContent);
    }

    /**
     * Stub all WP functions required by MediaCleanCommand::handleScan and
     * MediaReferenceIndex::build(). Mirrors the helper in
     * MediaCleanScanPaginationTest.
     *
     * @param string $uploadsBase Absolute path to the fake uploads directory.
     */
    private function stubWpFunctions(string $uploadsBase): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => $uploadsBase,
        ]);

        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) {
                if ($key === '_wp_attached_file') {
                    return "tests/img{$id}.jpg";
                }
                return [];
            }
        );

        Functions\when('wp_get_attachment_image_src')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\when('is_serialized')->justReturn(false);
        Functions\when('esc_sql')->alias(static fn ($s) => addslashes((string)$s));
        Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        Functions\when('wp_json_encode')->alias(static fn ($data, int $flags = 0): string|false => json_encode($data, $flags));
    }

    /**
     * Build a wpdb double that serves attachment rows and optionally marks IDs
     * as referenced via _thumbnail_id. Mirrors makeWpdb() in
     * MediaCleanScanPaginationTest.
     *
     * @param list<array<string,string>> $attachmentRows
     * @param list<int>                  $referencedIds
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

                if (strpos($sql, '_product_image_gallery') !== false) {
                    return [];
                }

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

    // =========================================================================
    // Scenario 1: quarantined + unreferenced attachment is excluded
    // =========================================================================

    /**
     * Library: 3 attachments (IDs 1, 2, 3). None are referenced.
     * ID 2 is quarantined (in a manifest on disk).
     *
     * Expected:
     *   - candidates contains IDs 1 and 3 only.
     *   - total = 2 (unused count).
     *   - total_attachments = 2 (quarantined ID is not walked).
     *   - unused_count = 2.
     *   - quarantined_count = 1.
     */
    public function testQuarantinedUnreferencedAttachmentIsExcluded(): void
    {
        global $wpdb;

        $allRows = [
            ['ID' => '1', 'post_title' => 'Image 1', 'guid' => 'https://example.com/wp-content/uploads/img1.jpg'],
            ['ID' => '2', 'post_title' => 'Image 2', 'guid' => 'https://example.com/wp-content/uploads/img2.jpg'],
            ['ID' => '3', 'post_title' => 'Image 3', 'guid' => 'https://example.com/wp-content/uploads/img3.jpg'],
        ];

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions($this->uploadsDir);

        // Create a quarantine manifest that records attachment ID 2.
        $q          = $this->makeQuarantine();
        $manifestId = $q->beginManifest('job-excl-001');
        // quarantineAttachment with an empty file list: ID is recorded in the manifest
        // even when no files were physically moved (matches the real isolate behaviour
        // for already-absent files).
        $q->quarantineAttachment($manifestId, 2, '2024/01/img2.jpg', []);
        $q->finaliseManifest($manifestId);

        $cmd    = new MediaCleanCommand($q);
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed. Detail: ' . ($result['detail'] ?? '(none)'));

        // quarantined_count must reflect the one excluded ID.
        $this->assertSame(1, $result['quarantined_count'], 'quarantined_count must be 1.');

        // ID 2 must NOT appear in candidates.
        $candidateIds = array_column($result['candidates'], 'id');
        $this->assertNotContains(2, $candidateIds, 'Quarantined ID 2 must not appear in candidates.');

        // IDs 1 and 3 must appear in candidates.
        $this->assertContains(1, $candidateIds, 'Non-quarantined ID 1 must appear in candidates.');
        $this->assertContains(3, $candidateIds, 'Non-quarantined ID 3 must appear in candidates.');

        // total / unused_count must be 2 (quarantined ID excluded from count).
        $this->assertSame(2, $result['total'], 'total must be 2 (IDs 1 and 3).');
        $this->assertSame(2, $result['unused_count'], 'unused_count must equal total (2).');

        // total_attachments must be 2 (quarantined ID not walked).
        $this->assertSame(2, $result['total_attachments'], 'total_attachments must be 2 (quarantined ID not counted).');
    }

    // =========================================================================
    // Scenario 2: non-quarantined unreferenced attachment still appears
    // =========================================================================

    /**
     * Library: 3 attachments (IDs 10, 11, 12). None are referenced.
     * ID 10 is quarantined. IDs 11 and 12 are NOT quarantined.
     *
     * IDs 11 and 12 must still appear as candidates (regression guard).
     */
    public function testNonQuarantinedUnreferencedAttachmentStillAppears(): void
    {
        global $wpdb;

        $allRows = [
            ['ID' => '10', 'post_title' => 'Image 10', 'guid' => 'https://example.com/wp-content/uploads/img10.jpg'],
            ['ID' => '11', 'post_title' => 'Image 11', 'guid' => 'https://example.com/wp-content/uploads/img11.jpg'],
            ['ID' => '12', 'post_title' => 'Image 12', 'guid' => 'https://example.com/wp-content/uploads/img12.jpg'],
        ];

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions($this->uploadsDir);

        // Quarantine only ID 10.
        $q          = $this->makeQuarantine();
        $manifestId = $q->beginManifest('job-excl-002');
        $q->quarantineAttachment($manifestId, 10, '2024/01/img10.jpg', []);
        $q->finaliseManifest($manifestId);

        $cmd    = new MediaCleanCommand($q);
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        $candidateIds = array_column($result['candidates'], 'id');

        // Non-quarantined IDs must appear.
        $this->assertContains(11, $candidateIds, 'Non-quarantined ID 11 must appear as a candidate.');
        $this->assertContains(12, $candidateIds, 'Non-quarantined ID 12 must appear as a candidate.');

        // Quarantined ID must not appear.
        $this->assertNotContains(10, $candidateIds, 'Quarantined ID 10 must not appear as a candidate.');

        // quarantined_count reflects the one excluded ID.
        $this->assertSame(1, $result['quarantined_count']);

        // total / unused_count are 2.
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['unused_count']);
    }

    // =========================================================================
    // Scenario 3: absent quarantine dir => quarantined_count 0, scan unchanged
    // =========================================================================

    /**
     * Library: 4 attachments (IDs 20-23). No manifests on disk (quarantine dir
     * does not exist). All four attachments are unreferenced.
     *
     * Expected:
     *   - quarantined_count = 0.
     *   - All 4 IDs appear in candidates.
     *   - total = 4.
     *   - total_attachments = 4.
     *
     * This confirms that a missing quarantine dir does not break the scan and
     * produces no exclusions.
     */
    public function testAbsentQuarantineDirProducesZeroQuarantinedCount(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 20; $i <= 23; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Image {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions($this->uploadsDir);

        // Build a MediaQuarantine pointing at a content dir that has NO quarantine
        // sub-directory at all. quarantinedAttachmentIds() must return an empty array.
        $q = $this->makeQuarantine();
        // (Do NOT call beginManifest — the quarantine dir never gets created.)

        $cmd    = new MediaCleanCommand($q);
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed even with no quarantine dir.');

        // No exclusions.
        $this->assertSame(0, $result['quarantined_count'], 'quarantined_count must be 0 when no manifests exist.');

        // All 4 attachments must appear.
        $candidateIds = array_column($result['candidates'], 'id');
        sort($candidateIds);
        $this->assertSame([20, 21, 22, 23], $candidateIds, 'All 4 non-quarantined attachments must appear.');

        // Counts are unchanged from baseline.
        $this->assertSame(4, $result['total'], 'total must be 4.');
        $this->assertSame(4, $result['total_attachments'], 'total_attachments must be 4.');
        $this->assertSame(4, $result['unused_count'], 'unused_count must be 4.');
    }

    // =========================================================================
    // Bonus: multiple quarantined IDs across multiple manifests
    // =========================================================================

    /**
     * Library: 6 attachments (IDs 30-35). None are referenced.
     * Two manifests exist: one records IDs 31 and 33, another records ID 35.
     *
     * Expected:
     *   - quarantined_count = 3.
     *   - candidates contains IDs 30, 32, 34 only.
     *   - total = total_attachments = unused_count = 3.
     */
    public function testMultipleQuarantinedIdsAcrossManifestsAreAllExcluded(): void
    {
        global $wpdb;

        $allRows = [];
        for ($i = 30; $i <= 35; $i++) {
            $allRows[] = [
                'ID'         => (string)$i,
                'post_title' => "Image {$i}",
                'guid'       => "https://example.com/wp-content/uploads/img{$i}.jpg",
            ];
        }

        $wpdb = $this->makeWpdb($allRows, []); // @phpstan-ignore-line
        $this->stubWpFunctions($this->uploadsDir);

        $q = $this->makeQuarantine();

        // First manifest: IDs 31 and 33.
        $m1 = $q->beginManifest('job-multi-001');
        $q->quarantineAttachment($m1, 31, '2024/01/img31.jpg', []);
        $q->quarantineAttachment($m1, 33, '2024/01/img33.jpg', []);
        $q->finaliseManifest($m1);

        // Second manifest: ID 35.
        $m2 = $q->beginManifest('job-multi-002');
        $q->quarantineAttachment($m2, 35, '2024/01/img35.jpg', []);
        $q->finaliseManifest($m2);

        $cmd    = new MediaCleanCommand($q);
        $result = $cmd->execute([], ['action' => 'scan', 'offset' => 0, 'limit' => 100]);

        $this->assertTrue($result['ok'], 'Scan must succeed.');

        $this->assertSame(3, $result['quarantined_count'], 'quarantined_count must be 3 (IDs 31, 33, 35).');

        $candidateIds = array_column($result['candidates'], 'id');
        sort($candidateIds);
        $this->assertSame([30, 32, 34], $candidateIds, 'Only non-quarantined IDs 30, 32, 34 must appear.');

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['total_attachments']);
        $this->assertSame(3, $result['unused_count']);
    }
}
