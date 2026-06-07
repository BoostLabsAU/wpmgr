<?php
/**
 * ADR-051 / 0.21.5 unit tests for FilesArchiver incremental (archive-delta) mode.
 *
 * Covers:
 *   (a) size-changed file is INCLUDED in the increment.
 *   (b) mtime-bumped, same-size file is INCLUDED (conservative: size+mtime gate).
 *   (c) new file (not in prevMap) is INCLUDED.
 *   (d) deleted file (in prevMap but absent from disk) becomes a tombstone.
 *   (e) file re-added after deletion is NOT a tombstone (cancels the deletion).
 *   (f) unchanged file (same size, same mtime) is SKIPPED from the archive.
 *   (g) loadPrevMap() parses a files.list correctly.
 *   (h) every run (full and incremental) emits a files.list sidecar.
 *   (i) resume: tombstones from a prior walk are preserved via the on-disk file.
 *
 * 0.21.5 change: tombstones are returned as tombstones_file (path to the on-disk
 * tombstones.list) + tombstones_count (deletion count) rather than as an in-memory
 * PHP array. This keeps sub_state small regardless of deletion count and is the
 * "B" bound that complements the "A" sidecar-spill safety net in saveTaskState.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use WPMgr\Agent\Backup\FilesArchiver;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\FilesArchiver
 */
final class FilesArchiverIncrementalTest extends TestCase
{
    private string $sourceDir = '';
    private string $outDir    = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-fa-incr-' . bin2hex(random_bytes(4));
        $this->sourceDir = $base . DIRECTORY_SEPARATOR . 'src';
        $this->outDir    = $base . DIRECTORY_SEPARATOR . 'out';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->outDir, 0755, true);
    }

    protected function tear_down(): void
    {
        if ($this->sourceDir !== '' && is_dir($this->sourceDir)) {
            $this->rrmdir(dirname($this->sourceDir));
        }
        parent::tear_down();
    }

    // ==================================================================
    // (a) size-changed file is included
    // ==================================================================

    public function test_size_changed_file_is_included(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        $path = $this->sourceDir . '/plugins/size_changed.php';
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($path, str_repeat('A', 100));

        $mtime = filemtime($path);

        // Prev map says file was 50 bytes (different size).
        $prevMap = ['plugins/size_changed.php' => ['size' => 50, 'mtime' => (int) $mtime]];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(1, $result['files_total'], 'size-changed file must be included');
        self::assertNotEmpty($result['parts'], 'size-changed file must produce archive parts');
    }

    // ==================================================================
    // (b) mtime-bumped, same-size file is included
    // ==================================================================

    public function test_mtime_bumped_same_size_file_is_included(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        $path = $this->sourceDir . '/themes/style.css';
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($path, str_repeat('B', 200));
        $size  = filesize($path);
        $mtime = filemtime($path);

        // Prev map says same size but an OLDER mtime — current file is newer.
        $prevMap = ['themes/style.css' => ['size' => (int) $size, 'mtime' => (int) $mtime - 100]];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(1, $result['files_total'], 'mtime-bumped same-size file must be included');
    }

    // ==================================================================
    // (c) new file (not in prevMap) is included
    // ==================================================================

    public function test_new_file_not_in_prevmap_is_included(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        $path = $this->sourceDir . '/uploads/new-image.png';
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($path, str_repeat('C', 300));

        // Prev map has no entry for this file.
        $prevMap = [];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(1, $result['files_total'], 'new file absent from prevMap must be included');
    }

    // ==================================================================
    // (d) deleted file becomes a tombstone (on-disk file + count)
    // ==================================================================

    public function test_deleted_file_becomes_tombstone(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Disk has one file.
        $path = $this->sourceDir . '/plugins/kept.php';
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($path, 'kept');
        $size  = filesize($path);
        $mtime = filemtime($path);

        // Prev map has two files: kept (unchanged) and deleted (gone from disk).
        $prevMap = [
            'plugins/kept.php'    => ['size' => (int) $size, 'mtime' => (int) $mtime],
            'plugins/deleted.php' => ['size' => 42, 'mtime' => (int) $mtime - 1],
        ];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        // kept.php is unchanged — skipped from archive (0 packed files).
        self::assertSame(0, $result['files_total'], 'unchanged kept.php must be skipped');

        // 0.21.5: tombstone info is on-disk (tombstones_file + tombstones_count).
        self::assertSame(1, $result['tombstones_count'], 'one deletion must produce tombstones_count=1');
        self::assertNotSame('', $result['tombstones_file'], 'tombstones_file must be a non-empty path');

        // tombstones.list must exist on disk with the deleted relpath.
        $tbPath = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::TOMBSTONES_LIST_NAME;
        self::assertFileExists($tbPath);
        $lines = array_filter(explode("\n", (string) file_get_contents($tbPath)));
        self::assertContains('plugins/deleted.php', $lines);
    }

    // ==================================================================
    // (e) file re-added after deletion is NOT a tombstone
    // ==================================================================

    public function test_readded_file_is_not_tombstone(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // The file exists on disk now (re-added).
        $path = $this->sourceDir . '/plugins/readded.php';
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($path, 'hello');

        // Prev map has the file — but with an older mtime so it's "changed".
        // Since the file IS on disk, it won't be a tombstone regardless.
        $mtime   = filemtime($path);
        $prevMap = ['plugins/readded.php' => ['size' => 5, 'mtime' => (int) $mtime - 200]];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        // No tombstones: the file is present on disk, not deleted.
        self::assertSame(0, $result['tombstones_count'], 're-added file must not be tombstoned');
        self::assertSame('', $result['tombstones_file'], 'no tombstones.list when zero deletions');
    }

    // ==================================================================
    // (f) unchanged file is skipped
    // ==================================================================

    public function test_unchanged_file_is_skipped_from_archive(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        $path = $this->sourceDir . '/plugins/unchanged.php';
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($path, str_repeat('U', 512));
        $size  = filesize($path);
        $mtime = filemtime($path);

        // Prev map exactly matches: same size, same mtime (not newer).
        $prevMap = ['plugins/unchanged.php' => ['size' => (int) $size, 'mtime' => (int) $mtime]];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(0, $result['files_total'], 'unchanged file must be excluded from the archive');
        self::assertSame([], $result['parts'], 'no archive parts for a 0-file increment');
        self::assertSame(0, $result['tombstones_count']);
    }

    // ==================================================================
    // (g) loadPrevMap parses a files.list correctly
    // ==================================================================

    public function test_load_prev_map_parses_files_list(): void
    {
        $flPath = $this->outDir . DIRECTORY_SEPARATOR . 'test.files.list';
        $lines  = "plugins/foo.php\t1024\t1717776000\n"
                . "themes/style.css\t4096\t1717776100\n"
                . "uploads/photo.jpg\t204800\t1717776200\n";
        file_put_contents($flPath, $lines);

        $map = FilesArchiver::loadPrevMap($flPath);

        self::assertArrayHasKey('plugins/foo.php', $map);
        self::assertSame(1024, $map['plugins/foo.php']['size']);
        self::assertSame(1717776000, $map['plugins/foo.php']['mtime']);

        self::assertArrayHasKey('themes/style.css', $map);
        self::assertSame(4096, $map['themes/style.css']['size']);

        self::assertArrayHasKey('uploads/photo.jpg', $map);
        self::assertSame(204800, $map['uploads/photo.jpg']['size']);
        self::assertSame(1717776200, $map['uploads/photo.jpg']['mtime']);
    }

    public function test_load_prev_map_returns_empty_for_missing_file(): void
    {
        $map = FilesArchiver::loadPrevMap('/tmp/nonexistent-wpmgr-files.list');
        self::assertSame([], $map);
    }

    // ==================================================================
    // (h) every run emits a files.list sidecar (full and incremental)
    // ==================================================================

    public function test_full_run_emits_files_list_sidecar(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        file_put_contents($this->sourceDir . '/file1.txt', 'hello');
        file_put_contents($this->sourceDir . '/file2.txt', 'world');

        $archiver = new FilesArchiver($this->sourceDir);
        $archiver->archive($this->outDir, [], static function (): void {});

        $flPath = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME;
        self::assertFileExists($flPath, 'files.list must be created on a full backup run');

        $map = FilesArchiver::loadPrevMap($flPath);
        self::assertCount(2, $map, 'files.list must have one entry per packed file');
    }

    public function test_incremental_run_emits_files_list_for_complete_tree(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Two files on disk; one is changed, one is unchanged.
        $path1 = $this->sourceDir . '/plugins/changed.php';
        $path2 = $this->sourceDir . '/plugins/unchanged.php';
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($path1, 'NEW CONTENT');
        file_put_contents($path2, str_repeat('X', 100));

        $size2  = filesize($path2);
        $mtime2 = filemtime($path2);

        $prevMap = [
            'plugins/changed.php'   => ['size' => 5, 'mtime' => 1000],
            'plugins/unchanged.php' => ['size' => (int) $size2, 'mtime' => (int) $mtime2],
        ];

        $archiver = new FilesArchiver($this->sourceDir);
        $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        $flPath = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME;
        self::assertFileExists($flPath, 'files.list must be emitted on an incremental run');

        // files.list must contain BOTH files (complete snapshot picture),
        // even though only one was packed into the archive.
        $map = FilesArchiver::loadPrevMap($flPath);
        self::assertArrayHasKey('plugins/changed.php', $map, 'changed file must appear in files.list');
        self::assertArrayHasKey('plugins/unchanged.php', $map, 'unchanged file must also appear in files.list');
    }

    // ==================================================================
    // (i) resume: tombstones from a prior walk are preserved via the on-disk file
    // ==================================================================

    public function test_tombstones_in_resume_cursor_are_preserved(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // One file on disk (the other was deleted, represented in the resume cursor).
        $path = $this->sourceDir . '/uploads/current.jpg';
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($path, str_repeat('J', 64));

        // Write the tombstones.list file to disk (simulating what a prior walk wrote).
        $tombstonesFile = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::TOMBSTONES_LIST_NAME;
        file_put_contents($tombstonesFile, "uploads/deleted.jpg\n");

        // Simulate a watchdog re-entry with a pre-populated paths.cache.
        $cacheFile = $this->outDir . DIRECTORY_SEPARATOR . 'paths.cache';
        file_put_contents($cacheFile, "uploads\tuploads/current.jpg\n");

        $resumeCursor = [
            'cache_file'       => $cacheFile,
            'total_files'      => 1,
            'file_index'       => 0,
            'parts_completed'  => [],
            'tombstones_file'  => $tombstonesFile,
            'tombstones_count' => 1,
        ];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, $resumeCursor, static function (): void {});

        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(1, $result['tombstones_count'],
            'tombstones_count from the resume cursor must be preserved on re-entry');
        self::assertNotSame('', $result['tombstones_file'],
            'tombstones_file from the resume cursor must be preserved on re-entry');
    }

    // ==================================================================
    // Multi-case integration: mixed changed/new/unchanged/deleted
    // ==================================================================

    public function test_mixed_tree_produces_correct_archive_and_tombstones(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        mkdir($this->sourceDir . '/themes', 0755, true);

        // On-disk tree:
        file_put_contents($this->sourceDir . '/plugins/changed.php',   str_repeat('C', 200));
        file_put_contents($this->sourceDir . '/plugins/new.php',       str_repeat('N', 100));
        file_put_contents($this->sourceDir . '/themes/unchanged.css',  str_repeat('U', 400));
        // 'plugins/deleted.php' is NOT on disk.

        $size_unchanged  = (int) filesize($this->sourceDir . '/themes/unchanged.css');
        $mtime_unchanged = (int) filemtime($this->sourceDir . '/themes/unchanged.css');

        $prevMap = [
            'plugins/changed.php'  => ['size' => 50,              'mtime' => 1000],
            'themes/unchanged.css' => ['size' => $size_unchanged,  'mtime' => $mtime_unchanged],
            'plugins/deleted.php'  => ['size' => 99,              'mtime' => 1001],
        ];

        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(true, $result['done'] ?? null);

        // Packed: changed.php + new.php = 2 files. unchanged.css is skipped.
        self::assertSame(2, $result['files_total'], 'exactly 2 files (changed + new) must be packed');

        // Tombstone: deleted.php (on-disk file).
        self::assertSame(1, $result['tombstones_count'], 'exactly one deletion');
        $tbPath = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::TOMBSTONES_LIST_NAME;
        self::assertFileExists($tbPath, 'tombstones.list must be on disk');
        $lines = array_filter(explode("\n", (string) file_get_contents($tbPath)));
        self::assertContains('plugins/deleted.php', $lines);
        self::assertNotContains('themes/unchanged.css', $lines);

        // Verify the packed zip contains changed.php and new.php but NOT unchanged.css.
        $packedRels = [];
        foreach ($result['parts'] as $partName) {
            $zip = new \ZipArchive();
            $zip->open($this->outDir . DIRECTORY_SEPARATOR . $partName);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $packedRels[] = $zip->getNameIndex($i);
            }
            $zip->close();
        }
        self::assertContains('plugins/changed.php', $packedRels);
        self::assertContains('plugins/new.php', $packedRels);
        self::assertNotContains('themes/unchanged.css', $packedRels);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrmdir($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
