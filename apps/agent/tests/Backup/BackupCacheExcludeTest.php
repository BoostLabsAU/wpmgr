<?php
/**
 * Regression tests for scoped backup cache and staging excludes.
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
final class BackupCacheExcludeTest extends TestCase
{
    private string $sourceDir = '';

    private string $outDir = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-cache-excludes-' . bin2hex(random_bytes(4));
        $this->sourceDir = $base . DIRECTORY_SEPARATOR . 'src';
        $this->outDir    = $base . DIRECTORY_SEPARATOR . 'out';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->outDir, 0755, true);
    }

    protected function tear_down(): void
    {
        if ($this->sourceDir !== '' && is_dir(dirname($this->sourceDir))) {
            $this->rrmdir(dirname($this->sourceDir));
        }
        parent::tear_down();
    }

    public function test_plugin_vendor_cache_directory_is_archived(): void
    {
        $this->skipWhenZipUnavailable();

        $vendorFile = 'plugins/builderius/vendor-prefixed/symfony/cache/Adapter/ArrayAdapter.php';
        $this->writeSourceFile($vendorFile, '<?php // adapter');

        $result        = $this->runArchive();
        $filesList     = $this->readFilesList();
        $pluginEntries = $this->zipEntries($this->partsFromResult($result), '/^plugins\.g000\.part\d{3}\.zip$/');

        self::assertContains($vendorFile, $filesList, 'vendor cache file must appear in files.list');
        self::assertContains($vendorFile, $pluginEntries, 'vendor cache file must appear in a plugin part');
    }

    public function test_root_cache_directory_is_excluded(): void
    {
        $this->skipWhenZipUnavailable();

        $excluded = 'cache/page/index.html';
        $included = 'plugins/acme/plugin.php';
        $this->writeSourceFile($excluded, 'cached');
        $this->writeSourceFile($included, '<?php // plugin');

        $result    = $this->runArchive();
        $filesList = $this->readFilesList();
        $entries   = $this->zipEntries($this->partsFromResult($result));

        self::assertNotContains($excluded, $filesList, 'root cache file must not appear in files.list');
        self::assertNotContains($excluded, $entries, 'root cache file must not appear in archive parts');
        self::assertContains($included, $filesList, 'sibling plugin file proves the archive ran');
        self::assertContains($included, $entries, 'sibling plugin file must be archived');
    }

    public function test_uploads_cache_is_excluded_but_dated_uploads_are_archived(): void
    {
        $this->skipWhenZipUnavailable();

        $excluded = 'uploads/cache/thumb.jpg';
        $included = 'uploads/2024/photo.jpg';
        $this->writeSourceFile($excluded, 'thumb');
        $this->writeSourceFile($included, 'photo');

        $result    = $this->runArchive();
        $filesList = $this->readFilesList();
        $entries   = $this->zipEntries($this->partsFromResult($result));

        self::assertNotContains($excluded, $filesList, 'uploads cache file must not appear in files.list');
        self::assertNotContains($excluded, $entries, 'uploads cache file must not appear in archive parts');
        self::assertContains($included, $filesList, 'dated upload must appear in files.list');
        self::assertContains($included, $entries, 'dated upload must be archived');
    }

    public function test_update_staging_roots_are_excluded_but_plugin_directories_are_archived(): void
    {
        $this->skipWhenZipUnavailable();

        $rootUpgrade      = 'upgrade/tmp.txt';
        $rootTempBackup   = 'upgrade-temp-backup/tmp.txt';
        $pluginUpgrade    = 'plugins/acme/upgrade/Migrator.php';
        $pluginTempBackup = 'plugins/acme/upgrade-temp-backup/Compat.php';
        $this->writeSourceFile($rootUpgrade, 'upgrade');
        $this->writeSourceFile($rootTempBackup, 'backup');
        $this->writeSourceFile($pluginUpgrade, '<?php // migrator');
        $this->writeSourceFile($pluginTempBackup, '<?php // compat');

        $result    = $this->runArchive();
        $filesList = $this->readFilesList();
        $entries   = $this->zipEntries($this->partsFromResult($result));

        self::assertNotContains($rootUpgrade, $filesList, 'root upgrade file must not appear in files.list');
        self::assertNotContains($rootUpgrade, $entries, 'root upgrade file must not appear in archive parts');
        self::assertNotContains($rootTempBackup, $filesList, 'root upgrade-temp-backup file must not appear in files.list');
        self::assertNotContains($rootTempBackup, $entries, 'root upgrade-temp-backup file must not appear in archive parts');
        self::assertContains($pluginUpgrade, $filesList, 'plugin upgrade directory must appear in files.list');
        self::assertContains($pluginUpgrade, $entries, 'plugin upgrade directory must be archived');
        self::assertContains($pluginTempBackup, $filesList, 'plugin upgrade-temp-backup directory must appear in files.list');
        self::assertContains($pluginTempBackup, $entries, 'plugin upgrade-temp-backup directory must be archived');
    }

    public function test_default_exclude_constants_separate_segment_and_anchored_paths(): void
    {
        self::assertNotContains('cache', FilesArchiver::DEFAULT_EXCLUDES);
        self::assertNotContains('upgrade', FilesArchiver::DEFAULT_EXCLUDES);
        self::assertNotContains('upgrade-temp-backup', FilesArchiver::DEFAULT_EXCLUDES);
        self::assertContains('wpmgr-object-cache-config.php', FilesArchiver::DEFAULT_EXCLUDES);

        self::assertContains('cache', FilesArchiver::DEFAULT_PATH_EXCLUDES);
        self::assertContains('uploads/cache', FilesArchiver::DEFAULT_PATH_EXCLUDES);
        self::assertContains('upgrade', FilesArchiver::DEFAULT_PATH_EXCLUDES);
        self::assertContains('upgrade-temp-backup', FilesArchiver::DEFAULT_PATH_EXCLUDES);
    }

    private function skipWhenZipUnavailable(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }
    }

    private function writeSourceFile(string $relativePath, string $contents): void
    {
        $path = $this->sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return array<string,mixed>
     */
    private function runArchive(): array
    {
        $archiver = new FilesArchiver($this->sourceDir);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false, 'archive must complete');

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return list<string>
     */
    private function partsFromResult(array $result): array
    {
        self::assertIsArray($result['parts'] ?? null);
        return array_values(array_map('strval', $result['parts']));
    }

    /**
     * @return list<string>
     */
    private function readFilesList(): array
    {
        $path = $this->outDir . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME;
        self::assertFileExists($path, 'files.list must be emitted');

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        $paths = [];
        foreach ($lines as $line) {
            $parts   = explode("\t", (string) $line, 2);
            $paths[] = $parts[0];
        }
        sort($paths);

        return $paths;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private function zipEntries(array $parts, string $partNamePattern = ''): array
    {
        $entries = [];
        foreach ($parts as $partName) {
            if ($partNamePattern !== '' && preg_match($partNamePattern, $partName) !== 1) {
                continue;
            }

            $partPath = $this->outDir . DIRECTORY_SEPARATOR . $partName;
            self::assertFileExists($partPath, 'part archive missing: ' . $partName);

            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true, 'part must be a valid zip: ' . $partName);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        sort($entries);

        return $entries;
    }

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
