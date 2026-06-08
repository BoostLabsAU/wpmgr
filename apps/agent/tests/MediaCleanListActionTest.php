<?php
/**
 * MediaCleanListActionTest — verifies the "list" action of MediaCleanCommand
 * and the underlying MediaQuarantine::listManifestsDetailed() method.
 *
 * Covers:
 *   - listManifestsDetailed() returns an empty array when nothing is quarantined.
 *   - listManifestsDetailed() returns the correct shape after a two-attachment isolate:
 *       manifest_id, job_id, isolated_at, total_files, and entries with
 *       attachment_id + file_count for both attachments.
 *   - The "list" action via MediaCleanCommand::execute() returns ok=true with the
 *       frozen wire shape, and the manifest carries the correct manifest_id,
 *       isolated_at, total_files, and both attachment entries.
 *   - The "list" action requires no params and has no side effects (idempotent).
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
 * @covers \WPMgr\Agent\Media\MediaQuarantine::listManifestsDetailed
 * @covers \WPMgr\Agent\Commands\MediaCleanCommand
 */
final class MediaCleanListActionTest extends TestCase
{
    /** Isolated temp wp-content root for this test run. */
    private string $wpContent = '';

    /** Temp uploads dir (simulates wp-content/uploads). */
    private string $uploadsDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // Isolated temp tree — every test run gets its own directory.
        $tmp              = sys_get_temp_dir() . '/wpmgr-list-' . bin2hex(random_bytes(6));
        $this->wpContent  = $tmp . '/wp-content';
        $this->uploadsDir = $this->wpContent . '/uploads';
        mkdir($this->uploadsDir . '/2024/05', 0755, true);

        // Stub wp_upload_dir for quarantine internals.
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $this->uploadsDir,
            'baseurl' => 'https://example.com/wp-content/uploads',
        ]);

        // wp_json_encode used by finaliseManifest().
        Functions\when('wp_json_encode')->alias(static function ($data, int $flags = 0): string|false {
            return json_encode($data, $flags);
        });

        // wp_delete_file and wp_delete_attachment are not called by "list", but
        // stub them so that any accidental call fails loudly rather than fatally.
        Functions\when('wp_delete_file')->alias(static function (string $path): void {
            @unlink($path);
        });
        Functions\when('wp_delete_attachment')->justReturn(true);

        // get_the_title: return a predictable title keyed by attachment ID.
        Functions\when('get_the_title')->alias(static function (int $id): string {
            return "Attachment {$id}";
        });
    }

    protected function tear_down(): void
    {
        $this->rrmdir(dirname($this->wpContent));
        Monkey\tearDown();
        parent::tear_down();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeQuarantine(): MediaQuarantine
    {
        return new MediaQuarantine($this->wpContent);
    }

    private function makeCommand(): MediaCleanCommand
    {
        return new MediaCleanCommand($this->makeQuarantine());
    }

    private function createUploadFile(string $relPath, string $content = 'img'): string
    {
        $abs = $this->uploadsDir . '/' . ltrim($relPath, '/');
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($abs, $content);
        return $abs;
    }

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

    // =========================================================================
    // listManifestsDetailed() — empty when nothing is quarantined
    // =========================================================================

    public function testListManifestsDetailedIsEmptyWhenNothingQuarantined(): void
    {
        $q      = $this->makeQuarantine();
        $result = $q->listManifestsDetailed();

        $this->assertIsArray($result);
        $this->assertEmpty($result, 'listManifestsDetailed() must return [] when no manifests exist.');
    }

    // =========================================================================
    // listManifestsDetailed() — correct shape after two-attachment isolate
    // =========================================================================

    public function testListManifestsDetailedReturnsTwoAttachmentManifest(): void
    {
        // Create two upload files to quarantine.
        $file1Rel = '2024/05/photo-a.jpg';
        $file2Rel = '2024/05/photo-b.jpg';
        $abs1     = $this->createUploadFile($file1Rel, 'bytes-a');
        $abs2     = $this->createUploadFile($file2Rel, 'bytes-b');

        $q          = $this->makeQuarantine();
        $manifestId = $q->beginManifest('job-list-001');
        $q->quarantineAttachment($manifestId, 10, $file1Rel, [$abs1]);
        $q->quarantineAttachment($manifestId, 20, $file2Rel, [$abs2]);
        $q->finaliseManifest($manifestId);

        $manifests = $q->listManifestsDetailed();

        $this->assertCount(1, $manifests, 'Exactly one manifest must be returned.');

        $m = $manifests[0];

        // Top-level manifest fields.
        $this->assertSame($manifestId, $m['manifest_id']);
        $this->assertSame('job-list-001', $m['job_id']);
        $this->assertIsInt($m['isolated_at']);
        $this->assertGreaterThan(0, $m['isolated_at']);
        $this->assertSame(2, $m['total_files'], 'total_files must equal the sum of all entries\' file counts.');

        // entries array.
        $this->assertCount(2, $m['entries'], 'Two entries expected — one per attachment.');

        $entryIds = array_column($m['entries'], 'attachment_id');
        $this->assertContains(10, $entryIds, 'Attachment 10 must appear in entries.');
        $this->assertContains(20, $entryIds, 'Attachment 20 must appear in entries.');

        foreach ($m['entries'] as $entry) {
            $this->assertSame(1, $entry['file_count'], 'Each entry has 1 file.');
            $expectedTitle = "Attachment {$entry['attachment_id']}";
            $this->assertSame($expectedTitle, $entry['title']);
        }
    }

    // =========================================================================
    // "list" action via MediaCleanCommand::execute() — frozen wire shape
    // =========================================================================

    public function testListActionReturnsOkAndFrozenShape(): void
    {
        // Quarantine two attachments.
        $file1Rel = '2024/05/img-x.jpg';
        $file2Rel = '2024/05/img-y.jpg';
        $abs1     = $this->createUploadFile($file1Rel, 'x');
        $abs2     = $this->createUploadFile($file2Rel, 'y');

        $q          = $this->makeQuarantine();
        $manifestId = $q->beginManifest('job-list-cmd');
        $q->quarantineAttachment($manifestId, 100, $file1Rel, [$abs1]);
        $q->quarantineAttachment($manifestId, 200, $file2Rel, [$abs2]);
        $q->finaliseManifest($manifestId);

        // Execute through the command with the same quarantine instance.
        $cmd    = new MediaCleanCommand($q);
        $result = $cmd->execute([], ['action' => 'list']);

        $this->assertTrue($result['ok'], 'list action must return ok=true.');
        $this->assertArrayHasKey('manifests', $result, '"manifests" key must be present in list response.');

        $manifests = $result['manifests'];
        $this->assertIsArray($manifests);
        $this->assertCount(1, $manifests, 'Exactly one manifest entry expected.');

        $m = $manifests[0];
        $this->assertSame($manifestId, $m['manifest_id']);
        $this->assertIsInt($m['isolated_at']);
        $this->assertSame(2, $m['total_files']);

        $entryIds = array_column($m['entries'], 'attachment_id');
        $this->assertContains(100, $entryIds);
        $this->assertContains(200, $entryIds);
    }

    // =========================================================================
    // "list" action when no manifests exist — empty manifests array
    // =========================================================================

    public function testListActionReturnsEmptyManifestsWhenNothingQuarantined(): void
    {
        $cmd    = $this->makeCommand();
        $result = $cmd->execute([], ['action' => 'list']);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('manifests', $result);
        $this->assertIsArray($result['manifests']);
        $this->assertEmpty($result['manifests']);
    }

    // =========================================================================
    // "list" action requires no params and is idempotent
    // =========================================================================

    public function testListActionIsIdempotent(): void
    {
        // Quarantine one file.
        $fileRel = '2024/05/idem.jpg';
        $abs     = $this->createUploadFile($fileRel, 'idem');
        $q       = $this->makeQuarantine();
        $mid     = $q->beginManifest('job-idem');
        $q->quarantineAttachment($mid, 77, $fileRel, [$abs]);
        $q->finaliseManifest($mid);

        $cmd = new MediaCleanCommand($q);

        // Call list twice — both must return the same manifest count.
        $first  = $cmd->execute([], ['action' => 'list']);
        $second = $cmd->execute([], ['action' => 'list']);

        $this->assertCount(1, $first['manifests']);
        $this->assertCount(1, $second['manifests']);
        $this->assertSame(
            $first['manifests'][0]['manifest_id'],
            $second['manifests'][0]['manifest_id'],
            'Repeated list calls must return the same manifest_id.'
        );
    }

    // =========================================================================
    // total_files counts correctly for multi-file entries
    // =========================================================================

    public function testTotalFilesCountsAllFilesAcrossEntries(): void
    {
        // Attachment 30: two files (main + a thumbnail-size).
        $mainRel  = '2024/05/multi-main.jpg';
        $thumbRel = '2024/05/multi-150x150.jpg';
        $absMain  = $this->createUploadFile($mainRel, 'main-bytes');
        $absThumb = $this->createUploadFile($thumbRel, 'thumb-bytes');

        // Attachment 40: one file.
        $singleRel = '2024/05/single.jpg';
        $absSingle = $this->createUploadFile($singleRel, 'single-bytes');

        $q   = $this->makeQuarantine();
        $mid = $q->beginManifest('job-multifile');
        $q->quarantineAttachment($mid, 30, $mainRel, [$absMain, $absThumb]);
        $q->quarantineAttachment($mid, 40, $singleRel, [$absSingle]);
        $q->finaliseManifest($mid);

        $manifests = $q->listManifestsDetailed();

        $this->assertCount(1, $manifests);
        $m = $manifests[0];
        // 2 files for attachment 30 + 1 file for attachment 40 = 3 total.
        $this->assertSame(3, $m['total_files']);

        $byId = [];
        foreach ($m['entries'] as $entry) {
            $byId[$entry['attachment_id']] = $entry;
        }
        $this->assertSame(2, $byId[30]['file_count']);
        $this->assertSame(1, $byId[40]['file_count']);
    }
}
