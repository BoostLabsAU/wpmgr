<?php
/**
 * Smoke tests for FilesArchiver: fresh archive run on a tiny temp tree,
 * verifying rotation, exclude honouring, and readability of the produced
 * `<component>.gNNN.partMMM.zip` files (generation-namespaced).
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
final class FilesArchiverTest extends TestCase
{
    private string $sourceDir = '';

    private string $outDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-files-archiver-' . bin2hex(random_bytes(4));
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

    public function test_archive_packs_files_excludes_and_rotates(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Three small files at the root, well under any per-part cap.
        file_put_contents($this->sourceDir . '/alpha.txt',   str_repeat('A', 16 * 1024));
        file_put_contents($this->sourceDir . '/beta.txt',    str_repeat('B', 16 * 1024));
        file_put_contents($this->sourceDir . '/gamma.txt',   str_repeat('C', 16 * 1024));

        // One excluded subdir (`cache` is in DEFAULT_EXCLUDES). Anything we
        // drop in here MUST NOT appear in the produced archive parts.
        mkdir($this->sourceDir . '/cache', 0755, true);
        file_put_contents($this->sourceDir . '/cache/should-not-appear.txt', 'EXCLUDED');

        // Force rotation by capping each part at well below one file's
        // contribution. ZipArchive's central directory + headers push the
        // part well past `max_part_bytes` after each entry, triggering
        // rotation after each file is added.
        $archiver = new FilesArchiver(
            $this->sourceDir,
            [],
            [
                'max_part_bytes'   => 1024, // 1 KiB — far smaller than any test file.
                'max_part_entries' => 10000,
            ]
        );

        $progressCalls = [];
        $progress      = function (string $phase, array $detail) use (&$progressCalls): void {
            $progressCalls[] = ['phase' => $phase, 'detail' => $detail];
        };

        $result = $archiver->archive($this->outDir, [], $progress);

        // Terminal sub-state.
        self::assertSame(true, $result['done'] ?? null);
        self::assertSame(3, $result['files_total'] ?? null, 'cache/ subdir should be excluded');
        self::assertIsArray($result['parts'] ?? null);
        self::assertGreaterThanOrEqual(2, count($result['parts']), 'small max_part_bytes should force rotation');
        self::assertSame(count($result['parts']), $result['parts_total'] ?? null);
        self::assertGreaterThan(0, (int) ($result['bytes_written'] ?? 0));

        // Every claimed part must exist and be a valid readable zip.
        $seenEntries = [];
        foreach ($result['parts'] as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . $partName;
            self::assertFileExists($partPath, 'part archive missing: ' . $partName);
            // ADR-051: part names are generation-namespaced (gen 0 here).
            self::assertMatchesRegularExpression('/^wp-content\.g000\.part\d{3}\.zip$/', $partName);

            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true, 'part not a valid zip: ' . $partName);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry         = (string) $zip->getNameIndex($i);
                $seenEntries[] = $entry;
                // The excluded file's basename must never appear.
                self::assertStringNotContainsString('should-not-appear', $entry);
                // Sanity: entry contents should match what we wrote.
                $bytes = $zip->getFromIndex($i);
                self::assertIsString($bytes);
                self::assertNotSame('EXCLUDED', $bytes);
            }
            $zip->close();
        }

        // Exactly the three included files made it in.
        sort($seenEntries);
        self::assertSame(['alpha.txt', 'beta.txt', 'gamma.txt'], $seenEntries);

        // Final progress tick reports done.
        self::assertNotEmpty($progressCalls);
        $last = $progressCalls[count($progressCalls) - 1];
        self::assertSame('archiving_files', $last['phase']);
        self::assertSame(true, $last['detail']['done'] ?? null);
        self::assertSame(3, $last['detail']['files_total'] ?? null);
    }

    public function test_archive_resume_continues_from_cursor(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Five files; we run once with a tight cap to force at least one
        // rotation, then start again from the resume cursor and assert the
        // total file count is still complete.
        for ($i = 0; $i < 5; $i++) {
            file_put_contents($this->sourceDir . '/file-' . $i . '.txt', str_repeat((string) $i, 4 * 1024));
        }

        $archiver = new FilesArchiver($this->sourceDir, [], ['max_part_bytes' => 512]);
        $noop     = static function (): void {};

        $result = $archiver->archive($this->outDir, [], $noop);

        // Full run should be complete in one shot here (archive() loops
        // until end-of-cache); we then synthesise a resume by re-invoking
        // with the *returned* sub-state, which should be a no-op since
        // file_index == total_files.
        self::assertTrue($result['done'] ?? false);
        self::assertSame(5, $result['files_total']);

        $resumeCursor = [
            'cache_file'      => $this->outDir . DIRECTORY_SEPARATOR . 'paths.cache',
            'total_files'     => $result['files_total'],
            'file_index'      => $result['files_total'],
            'current_part'    => $result['parts_total'] + 1,
            'parts_completed' => $result['parts'],
            'bytes_written'   => $result['bytes_written'],
        ];

        $again = $archiver->archive($this->outDir, $resumeCursor, $noop);
        self::assertTrue($again['done'] ?? false);
        self::assertSame(5, $again['files_total']);
    }

    public function test_constructor_rejects_missing_source_dir(): void
    {
        $this->expectException(\RuntimeException::class);
        new FilesArchiver('/this/path/does/not/exist/for/sure');
    }

    /**
     * M1 regression: wpmgr-object-cache-config.php must never appear in any
     * produced archive part. The file lives at wp-content root (segment match)
     * so the DEFAULT_EXCLUDES entry 'wpmgr-object-cache-config.php' must catch it.
     */
    public function test_object_cache_config_excluded_from_archive(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // One normal file + the credential config file at the wp-content root.
        file_put_contents($this->sourceDir . '/normal.txt', str_repeat('N', 1024));
        file_put_contents(
            $this->sourceDir . '/wpmgr-object-cache-config.php',
            "<?php\nreturn ['password' => 'supersecret'];\n"
        );

        $archiver = new FilesArchiver($this->sourceDir);
        $noop     = static function (): void {};

        $result = $archiver->archive($this->outDir, [], $noop);
        self::assertTrue($result['done'] ?? false);

        // Only the normal file should be packed; the credential file is excluded.
        self::assertSame(1, $result['files_total'], 'wpmgr-object-cache-config.php must be excluded from the archive');

        // Inspect every produced zip part to confirm the config file is absent.
        foreach ($result['parts'] as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . $partName;
            self::assertFileExists($partPath);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                self::assertStringNotContainsString(
                    'wpmgr-object-cache-config.php',
                    $entry,
                    'Credential config file leaked into backup archive at entry: ' . $entry
                );
            }
            $zip->close();
        }
    }

    /**
     * Verify 'wpmgr-object-cache-config.php' is in DEFAULT_EXCLUDES so
     * the segment-match logic in isExcluded() catches it at the top level
     * and also inside any subdirectory (belt-and-suspenders for future layouts).
     */
    public function test_object_cache_config_in_default_excludes(): void
    {
        self::assertContains(
            'wpmgr-object-cache-config.php',
            FilesArchiver::DEFAULT_EXCLUDES,
            'wpmgr-object-cache-config.php must be listed in DEFAULT_EXCLUDES'
        );
    }

    /**
     * Recursively delete a directory tree (used by tear_down).
     *
     * @param string $path Absolute path.
     * @return void
     */
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
