<?php
/**
 * Tests for #187 Track A: selective components + exclusions + core component.
 *
 * Coverage:
 *   1. Selective archiving — only selected components appear in the output.
 *   2. CoreFilesArchiver — archives ABSPATH PHP files, emits entry_kind="core".
 *   3. RestoreRunner::classifyArtifactKind — core.gNNN.partMMM.zip → 'core'.
 *   4. Extension exclusion — files whose extension is in exclude_extensions are skipped.
 *   5. File-size exclusion — files exceeding exclude_file_size_mb are skipped.
 *   6. Path-segment exclusion — caller-supplied exclude_paths merged with defaults.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use ReflectionClass;
use WPMgr\Agent\Backup\CoreFilesArchiver;
use WPMgr\Agent\Backup\EncryptAndUpload;
use WPMgr\Agent\Backup\FilesArchiver;
use WPMgr\Agent\Backup\RestoreRunner;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\FilesArchiver
 * @covers \WPMgr\Agent\Backup\CoreFilesArchiver
 * @covers \WPMgr\Agent\Backup\RestoreRunner
 * @covers \WPMgr\Agent\Backup\EncryptAndUpload
 */
final class SelectiveBackupTest extends TestCase
{
    private string $sourceDir = '';
    private string $outDir    = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-selective-' . bin2hex(random_bytes(4));
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

    // ==================================================================
    // TEST 1: Selective components — only selected buckets appear.
    // ==================================================================

    /**
     * When include_components=['plugins'] the archiver packs ONLY the plugins/
     * subtree and produces zero part files for themes/, uploads/, and the
     * wp-content catch-all.
     */
    public function test_selective_components_only_plugins_archived(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Build a minimal wp-content-like tree.
        mkdir($this->sourceDir . '/plugins/my-plugin', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/my-plugin/my-plugin.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes/twentytwenty', 0755, true);
        file_put_contents($this->sourceDir . '/themes/twentytwenty/style.css', '/* theme */');
        mkdir($this->sourceDir . '/uploads/2024', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/2024/photo.jpg', str_repeat('J', 100));
        mkdir($this->sourceDir . '/mu-plugins', 0755, true);
        file_put_contents($this->sourceDir . '/mu-plugins/loader.php', '<?php // mu');

        $archiver = new FilesArchiver(
            $this->sourceDir,
            [],
            ['include_components' => ['plugins']],
            0
        );

        $result = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false);
        self::assertIsArray($result['parts']);
        self::assertIsArray($result['part_kinds']);
        self::assertCount(count($result['parts']), $result['part_kinds']);

        // Only 'plugin' kind parts should appear.
        foreach ($result['part_kinds'] as $kind) {
            self::assertSame('plugin', $kind, 'Only plugin kind should be archived');
        }

        // Each part must contain only plugins/ entries.
        $seenPaths = [];
        foreach ($result['parts'] as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . $partName;
            self::assertFileExists($partPath);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry      = (string) $zip->getNameIndex($i);
                $seenPaths[] = $entry;
                self::assertStringStartsWith('plugins/', $entry, 'Non-plugin entry must not appear: ' . $entry);
            }
            $zip->close();
        }

        // The themes/, uploads/, mu-plugins/ files must not appear anywhere.
        foreach ($seenPaths as $entry) {
            self::assertStringNotContainsString('themes/', $entry);
            self::assertStringNotContainsString('uploads/', $entry);
            self::assertStringNotContainsString('mu-plugins/', $entry);
        }

        // Selective run: files_total reflects only the plugin file.
        self::assertSame(1, $result['files_total']);
    }

    /**
     * When include_components is absent (empty), all components are archived.
     */
    public function test_absent_include_components_archives_all(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/foo.php', '<?php // a');
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/themes/bar.css', '/* b */');

        $archiver = new FilesArchiver($this->sourceDir, [], [], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false);
        // At least one plugin part and one theme part must appear.
        $kinds = $result['part_kinds'] ?? [];
        self::assertContains('plugin', $kinds, 'plugin kind must appear when all components archived');
        self::assertContains('theme', $kinds, 'theme kind must appear when all components archived');
    }

    // ==================================================================
    // TEST 1B: Contract-drift test — CP singular vocab through TaskRunner.
    //
    // This drives the REAL CP->agent param path (the params the agent receives
    // in BackupRequest.Components), not FilesArchiver directly. It exercises
    // the full normalization chain:
    //   CP sends components=["plugin","db"], include_db=true/false
    //   -> TaskRunner::runArchivingFiles maps singular kinds to FilesArchiver
    //   -> FilesArchiver::KIND_TO_BUCKET normalizes to internal bucket keys
    //   -> Only plugins bucket archived; themes/uploads/wp-content skipped
    //   -> TaskRunner::shouldDumpDb() honors include_db to skip the DB dump
    // ==================================================================

    /**
     * CP singular vocab: components=["plugin","db"] with include_db=false.
     *
     * Asserts via TaskRunner::runArchivingFiles (real production path):
     *   - Only 'plugin' kind parts are produced.
     *   - themes/, uploads/, and wp-content entries are absent from all parts.
     *   - DB dump is NOT attempted (include_db=false skips runDumpDatabase).
     *   - files_total == 1 (only the plugin file).
     */
    public function test_cp_singular_vocab_plugin_db_archives_only_plugins_no_db(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Build a wp-content-like tree with one file per component.
        mkdir($this->sourceDir . '/plugins/my-plugin', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/my-plugin/plugin.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes/mytheme', 0755, true);
        file_put_contents($this->sourceDir . '/themes/mytheme/style.css', '/* theme */');
        mkdir($this->sourceDir . '/uploads/2024', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/2024/photo.jpg', str_repeat('J', 100));
        mkdir($this->sourceDir . '/mu-plugins', 0755, true);
        file_put_contents($this->sourceDir . '/mu-plugins/loader.php', '<?php // mu');

        // Build a TaskRunner with the exact params the CP sends (singular vocab,
        // include_db=false because "db" is listed but the DB dump is skipped
        // when components does not include db; here we test include_db=false).
        $runner = $this->makeSelectiveRunner([
            'components' => ['plugin', 'db'],
            'include_db' => false, // "db" listed but include_db=false = skip dump
        ]);

        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertTrue($result['done'] ?? false, 'archive must complete');
        self::assertIsArray($result['parts']);
        self::assertIsArray($result['part_kinds']);
        self::assertCount(count($result['parts']), $result['part_kinds']);

        // Only 'plugin' kind parts should appear.
        foreach ($result['part_kinds'] as $kind) {
            self::assertSame('plugin', $kind, 'only plugin kind expected; got: ' . $kind);
        }

        // Open every part and assert no themes/uploads/mu-plugins entries.
        $seenPaths = [];
        foreach ($result['parts'] as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . (string) $partName;
            self::assertFileExists($partPath, 'part file must exist: ' . $partName);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true, 'part must be a valid zip: ' . $partName);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $seenPaths[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }

        foreach ($seenPaths as $entry) {
            self::assertStringStartsWith('plugins/', $entry, 'non-plugin entry must not appear: ' . $entry);
            self::assertStringNotContainsString('themes/', $entry, 'themes must be absent');
            self::assertStringNotContainsString('uploads/', $entry, 'uploads must be absent');
            self::assertStringNotContainsString('mu-plugins/', $entry, 'mu-plugins (wp-content bucket) must be absent');
        }

        // Exactly 1 file (the plugin file).
        self::assertSame(1, $result['files_total'], 'only the one plugin file should be packed');

        // sub_state must NOT contain a 'db' key (DB dump was skipped).
        self::assertArrayNotHasKey('db', $subStateOut, 'DB dump must be absent when include_db=false');
    }

    /**
     * CP singular vocab: components=["plugin"], no include_db field.
     *
     * Confirms omitting "db" from components AND omitting include_db (nil in Go)
     * still restricts file archiving to plugins only. In practice the CP would
     * set include_db=false here; this test covers the nil/absent case to ensure
     * backward compatibility with older CP versions that may not send the field.
     */
    public function test_cp_singular_vocab_plugin_only_excludes_all_other_file_buckets(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/p.php', '<?php // p');
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/themes/t.css', '/* t */');
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/u.jpg', 'JPEG');

        // components=['plugin'], no include_db => only plugins bucket archived.
        $runner      = $this->makeSelectiveRunner(['components' => ['plugin']]);
        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertTrue($result['done'] ?? false);
        foreach ($result['part_kinds'] as $kind) {
            self::assertSame('plugin', $kind);
        }
        self::assertSame(1, $result['files_total']);
    }

    /**
     * shouldDumpDb: include_db=false must skip the DB dump regardless of kind.
     */
    public function test_should_dump_db_false_skips_db_dump(): void
    {
        $rc     = new \ReflectionClass(TaskRunner::class);
        $method = $rc->getMethod('shouldDumpDb');

        // include_db=false -> always skip.
        $runner = $this->makeSelectiveRunner(['components' => ['plugin'], 'include_db' => false]);
        self::assertFalse($method->invoke($runner), 'include_db=false must skip DB dump');
    }

    /**
     * shouldDumpDb: include_db=true must dump regardless of kind.
     */
    public function test_should_dump_db_true_forces_db_dump(): void
    {
        $rc     = new \ReflectionClass(TaskRunner::class);
        $method = $rc->getMethod('shouldDumpDb');

        // include_db=true -> always dump.
        $runner = $this->makeSelectiveRunner(['components' => ['db'], 'include_db' => true]);
        self::assertTrue($method->invoke($runner), 'include_db=true must dump DB');
    }

    /**
     * shouldDumpDb: absent include_db falls back to kind-based logic.
     * kind=files -> no dump; kind=full -> dump.
     */
    public function test_should_dump_db_absent_falls_back_to_kind(): void
    {
        $rc     = new \ReflectionClass(TaskRunner::class);
        $method = $rc->getMethod('shouldDumpDb');

        $filesRunner = $this->makeSelectiveRunner([], 'files');
        self::assertFalse($method->invoke($filesRunner), 'kind=files with no include_db must skip DB dump');

        $fullRunner = $this->makeSelectiveRunner([], 'full');
        self::assertTrue($method->invoke($fullRunner), 'kind=full with no include_db must dump DB');
    }

    // ==================================================================
    // TEST 2: CoreFilesArchiver archives ABSPATH PHP files with kind="core".
    // ==================================================================

    /**
     * CoreFilesArchiver emits part files whose basenames match
     * `core.gNNN.partMMM.zip` and whose manifest entry_kind is "core".
     */
    public function test_core_archiver_packs_abspath_php_and_subdirs(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Simulate a minimal ABSPATH:
        //   wp-config.php      <- root PHP
        //   wp-login.php       <- root PHP
        //   wp-admin/admin.php <- inside wp-admin/ subtree
        //   wp-includes/load.php <- inside wp-includes/ subtree
        //   wp-content/        <- must NOT be packed
        //   .htaccess          <- non-PHP root file, must NOT be packed
        $absPath = $this->sourceDir;
        file_put_contents($absPath . '/wp-config.php', '<?php // config');
        file_put_contents($absPath . '/wp-login.php', '<?php // login');
        file_put_contents($absPath . '/.htaccess', 'Options -Indexes');
        mkdir($absPath . '/wp-admin', 0755, true);
        file_put_contents($absPath . '/wp-admin/admin.php', '<?php // admin');
        mkdir($absPath . '/wp-includes', 0755, true);
        file_put_contents($absPath . '/wp-includes/load.php', '<?php // load');
        mkdir($absPath . '/wp-content', 0755, true);
        file_put_contents($absPath . '/wp-content/index.php', '<?php // content-index');

        $archiver = new CoreFilesArchiver($absPath, 0);
        $result   = $archiver->archive($this->outDir, static function (): void {});

        self::assertTrue($result['done'] ?? false);
        self::assertIsArray($result['parts']);
        self::assertIsArray($result['part_kinds']);
        self::assertNotEmpty($result['parts'], 'CoreFilesArchiver must emit at least one part');

        // Every part must match the core.gNNN.partMMM.zip naming convention.
        foreach ($result['parts'] as $partName) {
            self::assertMatchesRegularExpression(
                '/^core\.g\d{3}\.part\d{3}\.zip$/',
                $partName,
                'core part must match naming convention'
            );
        }

        // Every part_kind must be 'core'.
        foreach ($result['part_kinds'] as $kind) {
            self::assertSame(CoreFilesArchiver::ENTRY_KIND, $kind);
        }

        // The archive must contain wp-config.php, wp-login.php, and the subtree files.
        $seenEntries = [];
        foreach ($result['parts'] as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . $partName;
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($partPath) === true, 'core part not a valid zip: ' . $partName);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $seenEntries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        self::assertContains('wp-config.php', $seenEntries, 'wp-config.php must be in core archive');
        self::assertContains('wp-login.php', $seenEntries, 'wp-login.php must be in core archive');
        self::assertContains('wp-admin/admin.php', $seenEntries, 'wp-admin/ subtree must be in core archive');
        self::assertContains('wp-includes/load.php', $seenEntries, 'wp-includes/ subtree must be in core archive');

        // wp-content/ MUST NOT be included.
        foreach ($seenEntries as $entry) {
            self::assertStringNotContainsString('wp-content', $entry, 'wp-content must not appear in core archive');
        }
        // .htaccess is a non-PHP root file — MUST NOT be included.
        self::assertNotContains('.htaccess', $seenEntries, '.htaccess must not appear in core archive');
    }

    /**
     * EncryptAndUpload::entryKind correctly classifies core part names
     * (both generation-namespaced and any future legacy form).
     */
    public function test_encrypt_and_upload_entry_kind_core(): void
    {
        self::assertSame('core', $this->invokeEntryKind('core.g000.part001.zip'));
        self::assertSame('core', $this->invokeEntryKind('core.g001.part042.zip'));
        // Must not be misclassified as 'file'.
        self::assertNotSame('file', $this->invokeEntryKind('core.g000.part001.zip'));
    }

    /**
     * RestoreRunner::classifyArtifactKind correctly classifies core part names
     * as 'core' — not as 'file' (legacy fallback) or any other kind.
     */
    public function test_restore_runner_classifies_core_parts_as_core(): void
    {
        self::assertSame('core', $this->invokeClassifyArtifactKind('core.g000.part001.zip'));
        self::assertSame('core', $this->invokeClassifyArtifactKind('core.g001.part042.zip'));
        // Regression: must NOT fall through to the legacy 'file' bucket.
        self::assertNotSame('file', $this->invokeClassifyArtifactKind('core.g000.part001.zip'));
    }

    /**
     * RestoreRunner::classifyArtifactKind still classifies other kinds correctly
     * after the 'core' classifier was added.
     */
    public function test_restore_runner_classifies_non_core_kinds_unchanged(): void
    {
        self::assertSame('plugin',     $this->invokeClassifyArtifactKind('plugins.g000.part001.zip'));
        self::assertSame('theme',      $this->invokeClassifyArtifactKind('themes.g001.part001.zip'));
        self::assertSame('upload',     $this->invokeClassifyArtifactKind('uploads.g000.part001.zip'));
        self::assertSame('wp-content', $this->invokeClassifyArtifactKind('wp-content.g000.part001.zip'));
        // Legacy (no generation infix) still classifies via componentKindFromPartName.
        self::assertSame('wp-content', $this->invokeClassifyArtifactKind('wp-content.part001.zip'));
        self::assertSame('db',         $this->invokeClassifyArtifactKind('database.sql.gz'));
        // A truly unknown zip (no matching component prefix) falls back to 'file'.
        self::assertSame('file',       $this->invokeClassifyArtifactKind('unknown-content.part001.zip'));
        self::assertSame('inspection', $this->invokeClassifyArtifactKind('sql-inspection.json'));
    }

    // ==================================================================
    // TEST 3: Extension exclusion applied in pack loop.
    // ==================================================================

    /**
     * Files whose extension is in exclude_extensions are skipped; files with
     * other extensions pass through normally.
     */
    public function test_extension_exclusion_skips_matching_files(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Create files with both an excluded and an included extension.
        file_put_contents($this->sourceDir . '/debug.log', 'should be excluded');
        file_put_contents($this->sourceDir . '/backup.bak', 'should be excluded');
        file_put_contents($this->sourceDir . '/index.php', '<?php // kept');

        $archiver = new FilesArchiver(
            $this->sourceDir,
            [],
            ['exclude_extensions' => ['log', 'bak']],
            0
        );
        $result = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false);
        self::assertSame(1, $result['files_total'], 'Only the .php file should be packed');

        $seenEntries = [];
        foreach ($result['parts'] as $partName) {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($this->outDir . DIRECTORY_SEPARATOR . $partName) === true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $seenEntries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        self::assertNotContains('debug.log', $seenEntries, '.log file must be excluded');
        self::assertNotContains('backup.bak', $seenEntries, '.bak file must be excluded');
        self::assertContains('index.php', $seenEntries, '.php file must be included');
    }

    /**
     * Extension exclusion is case-insensitive: a file named `Debug.LOG`
     * is excluded when `log` is in the exclude list.
     */
    public function test_extension_exclusion_is_case_insensitive(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        file_put_contents($this->sourceDir . '/Debug.LOG', 'UPPER CASE LOG');
        file_put_contents($this->sourceDir . '/keep.php', '<?php // keep');

        $archiver = new FilesArchiver($this->sourceDir, [], ['exclude_extensions' => ['log']], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertSame(1, $result['files_total'], '.LOG (upper) must be excluded by lower-case exclude rule');
    }

    // ==================================================================
    // TEST 4: File-size exclusion applied in pack loop.
    // ==================================================================

    /**
     * Files larger than exclude_file_size_mb MiB are skipped; smaller files
     * are packed normally.
     */
    public function test_filesize_exclusion_skips_large_files(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // 1 KiB file — well under 1 MiB cap — must be packed.
        file_put_contents($this->sourceDir . '/small.dat', str_repeat('S', 1024));
        // 2 MiB file — over the 1 MiB cap — must be skipped.
        file_put_contents($this->sourceDir . '/large.dat', str_repeat('L', 2 * 1024 * 1024));

        $archiver = new FilesArchiver($this->sourceDir, [], ['exclude_file_size_mb' => 1], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false);
        self::assertSame(1, $result['files_total'], 'Only the small file should be packed');

        $seenEntries = [];
        foreach ($result['parts'] as $partName) {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($this->outDir . DIRECTORY_SEPARATOR . $partName) === true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $seenEntries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        self::assertContains('small.dat', $seenEntries, 'small file must be packed');
        self::assertNotContains('large.dat', $seenEntries, 'large file must be excluded by size cap');
    }

    /**
     * exclude_file_size_mb=0 means no size filter — all files are packed.
     */
    public function test_filesize_exclusion_disabled_when_zero(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        file_put_contents($this->sourceDir . '/medium.dat', str_repeat('M', 500 * 1024)); // 500 KiB

        $archiver = new FilesArchiver($this->sourceDir, [], ['exclude_file_size_mb' => 0], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertSame(1, $result['files_total'], 'No size filter: medium file must be packed');
    }

    // ==================================================================
    // TEST 5: Path-segment exclusion (caller-supplied + defaults).
    // ==================================================================

    /**
     * Caller-supplied exclude_paths segments are merged with DEFAULT_EXCLUDES
     * and applied during the discovery walk.
     */
    public function test_extra_exclude_paths_are_applied(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Standard default exclude ('cache') + a caller-supplied one ('tmp').
        mkdir($this->sourceDir . '/cache', 0755, true);
        file_put_contents($this->sourceDir . '/cache/cached.html', 'EXCLUDED-DEFAULT');
        mkdir($this->sourceDir . '/tmp', 0755, true);
        file_put_contents($this->sourceDir . '/tmp/work.bin', 'EXCLUDED-CALLER');
        file_put_contents($this->sourceDir . '/keep.txt', 'INCLUDED');

        $archiver = new FilesArchiver($this->sourceDir, ['tmp'], [], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertSame(1, $result['files_total'], 'Only keep.txt should pass through');

        $seenEntries = [];
        foreach ($result['parts'] as $partName) {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($this->outDir . DIRECTORY_SEPARATOR . $partName) === true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $seenEntries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        self::assertNotContains('cache/cached.html', $seenEntries, 'cache/ (default exclude) must be excluded');
        self::assertNotContains('tmp/work.bin', $seenEntries, 'tmp/ (caller exclude) must be excluded');
        self::assertContains('keep.txt', $seenEntries, 'keep.txt must be included');
    }

    /**
     * Exclusion is also applied in incremental mode (prevMap != null). A file
     * whose extension matches the exclude list is skipped even when it is new
     * (not in prevMap) — the prevMap check happens after the exclusion gate.
     */
    public function test_extension_exclusion_applies_in_incremental_mode(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        file_put_contents($this->sourceDir . '/new.log', 'NEW LOG — must be excluded');
        file_put_contents($this->sourceDir . '/new.php', '<?php // must be packed');

        // prevMap is empty — both files are "new". But .log must still be excluded.
        $prevMap  = [];
        $archiver = new FilesArchiver($this->sourceDir, [], ['exclude_extensions' => ['log']], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {}, $prevMap);

        self::assertSame(1, $result['files_total'], 'Only .php should be packed even with empty prevMap');
    }

    // ==================================================================
    // TEST 6: core-only selection — the #187 over-archive bug regression.
    //
    // When components=["core"] and include_db=false:
    //   - nextAfterQueued() correctly routes to PHASE_ARCHIVING_FILES (so
    //     CoreFilesArchiver can still run if include_core=true).
    //   - FilesArchiver receives an active-but-empty include_components filter
    //     and must produce ZERO wp-content parts.
    //   - The DB dump is skipped.
    //   - A present-but-empty include_components filter never means "archive all".
    // ==================================================================

    /**
     * components=["core"], include_db=false, include_core=false:
     * FilesArchiver must produce zero parts (no wp-content/plugins/themes/uploads).
     * The DB dump must be skipped.
     *
     * This is the canonical regression test for the #187 over-archive bug:
     * a core-only selection must NOT cause all of wp-content to be archived.
     */
    public function test_core_only_components_produces_no_wp_content_parts(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Build a wp-content-like tree with files in every bucket.
        mkdir($this->sourceDir . '/plugins/my-plugin', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/my-plugin/plugin.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes/mytheme', 0755, true);
        file_put_contents($this->sourceDir . '/themes/mytheme/style.css', '/* theme */');
        mkdir($this->sourceDir . '/uploads/2024', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/2024/photo.jpg', str_repeat('J', 100));
        mkdir($this->sourceDir . '/mu-plugins', 0755, true);
        file_put_contents($this->sourceDir . '/mu-plugins/loader.php', '<?php // mu-plugins (wp-content bucket)');

        // components=["core"], include_db=false — the exact CP params for a
        // core-only backup.  include_core is intentionally omitted here so we
        // exercise the FilesArchiver branch in isolation (CoreFilesArchiver is a
        // separate code path inside runArchivingFiles guarded by include_core).
        $runner = $this->makeSelectiveRunner([
            'components' => ['core'],
            'include_db' => false,
        ]);

        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertTrue($result['done'] ?? false, 'archive() must complete even with no file-kind components');

        // FilesArchiver must produce ZERO parts — core is not a wp-content bucket.
        self::assertSame(
            [],
            $result['parts'] ?? [],
            'core-only selection must produce zero wp-content parts (FilesArchiver should archive nothing)'
        );
        self::assertSame(
            [],
            $result['part_kinds'] ?? [],
            'part_kinds must be empty when no wp-content components are selected'
        );

        // Sanity: no wp-content entries of any kind should exist.
        $seenPaths = [];
        foreach (($result['parts'] ?? []) as $partName) {
            $partPath = $this->outDir . DIRECTORY_SEPARATOR . (string) $partName;
            if (!file_exists($partPath)) {
                continue;
            }
            $zip = new \ZipArchive();
            if ($zip->open($partPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $seenPaths[] = (string) $zip->getNameIndex($i);
                }
                $zip->close();
            }
        }
        self::assertEmpty($seenPaths, 'No entries must appear in any produced parts for a core-only selection');

        // DB dump must be absent — include_db=false skips the dump.
        self::assertArrayNotHasKey('db', $subStateOut, 'DB dump must not run when include_db=false');
    }

    /**
     * nextAfterQueued() routing for components=["core"], include_db=false:
     * must route to PHASE_ARCHIVING_FILES (not PHASE_ENCRYPTING_UPLOADING)
     * so that CoreFilesArchiver can run inside runArchivingFiles when include_core=true.
     */
    public function test_next_after_queued_routes_to_archiving_files_for_core_only(): void
    {
        $rc     = new \ReflectionClass(TaskRunner::class);
        $method = $rc->getMethod('nextAfterQueued');

        $runner = $this->makeSelectiveRunner([
            'components' => ['core'],
            'include_db' => false,
        ], 'full');

        $next = $method->invoke($runner);
        self::assertSame(
            TaskRunner::PHASE_ARCHIVING_FILES,
            $next,
            'nextAfterQueued() must route to PHASE_ARCHIVING_FILES for core-only so CoreFilesArchiver can run'
        );
    }

    /**
     * A present-but-empty include_components filter in FilesArchiver never means
     * "archive all" — it means "filter is active, archive nothing from wp-content".
     * Contrast with an absent include_components (no key in opts) which means
     * "no filter, archive everything" (full-backup / legacy path).
     */
    public function test_files_archiver_present_but_empty_include_components_archives_nothing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // Tree with files in the plugins bucket.
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/foo.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/themes/bar.css', '/* theme */');

        // include_components key IS present but maps to [] after normalization
        // (e.g. it was passed by TaskRunner when components=["core"]).
        $archiver = new FilesArchiver(
            $this->sourceDir,
            [],
            ['include_components' => []],  // present key, empty array
            0
        );

        $result = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false, 'archive must complete');
        self::assertSame(
            [],
            $result['parts'],
            'A present-but-empty include_components must produce zero parts, not archive everything'
        );
        self::assertSame(0, $result['files_total'], 'files_total must be 0 when all components are excluded');
    }

    /**
     * Full-backup path: absent include_components key still archives all components
     * (backward-compatible with pre-m49 CP versions that never send the key).
     */
    public function test_files_archiver_absent_include_components_archives_all(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/foo.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/themes/bar.css', '/* theme */');

        // No include_components key at all — the full-backup / legacy path.
        $archiver = new FilesArchiver($this->sourceDir, [], [], 0);
        $result   = $archiver->archive($this->outDir, [], static function (): void {});

        self::assertTrue($result['done'] ?? false);
        $kinds = $result['part_kinds'] ?? [];
        self::assertContains('plugin', $kinds, 'plugin parts must appear when no filter is active');
        self::assertContains('theme', $kinds, 'theme parts must appear when no filter is active');
        self::assertSame(2, $result['files_total'], 'Both files must be archived when no filter is present');
    }

    /**
     * components=["core","db"], include_db=false:
     * Neither component maps to a wp-content file-archiver bucket.
     * FilesArchiver must produce zero parts.
     */
    public function test_core_db_components_with_include_db_false_produces_no_file_parts(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/p.php', '<?php // plugin');
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/u.jpg', 'JPEG');

        $runner = $this->makeSelectiveRunner([
            'components' => ['core', 'db'],
            'include_db' => false,
        ]);

        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertTrue($result['done'] ?? false);
        self::assertSame([], $result['parts'] ?? [], 'core+db selection must produce zero wp-content parts');
        self::assertSame(0, $result['files_total'], 'files_total must be 0 — no file-archiver components selected');
    }

    /**
     * components=["plugin","theme"], include_db=false:
     * Still archives only plugins and themes — the pre-existing behavior
     * must remain unchanged by the #187 fix.
     */
    public function test_plugin_theme_components_unchanged_by_fix(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/foo.php', '<?php // plugin');
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/themes/bar.css', '/* theme */');
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/img.jpg', 'JPEG');

        $runner = $this->makeSelectiveRunner([
            'components' => ['plugin', 'theme'],
            'include_db' => false,
        ]);

        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertTrue($result['done'] ?? false);

        $kinds = $result['part_kinds'] ?? [];
        self::assertContains('plugin', $kinds, 'plugin kind must appear');
        self::assertContains('theme', $kinds, 'theme kind must appear');
        foreach ($kinds as $kind) {
            self::assertNotSame('upload', $kind, 'upload must not appear when not selected');
            self::assertNotSame('wp-content', $kind, 'wp-content bucket must not appear when not selected');
        }
        self::assertSame(2, $result['files_total'], 'exactly 2 files (one plugin, one theme) must be archived');
    }

    // ==================================================================
    // TaskRunner helpers for the CP-vocab tests
    // ==================================================================

    /**
     * Build a TaskRunner with the exact params the CP sends on the wire.
     * Drives the real component-normalization path without a live $wpdb or
     * network.
     *
     * @param array<string,mixed> $extraParams  Params to merge over the base
     *                                          (e.g. components, include_db).
     * @param string              $kind         Snapshot kind (files|db|full).
     */
    private function makeSelectiveRunner(array $extraParams = [], string $kind = 'files'): TaskRunner
    {
        return new TaskRunner(array_merge([
            'snapshot_id'       => 'sel-' . bin2hex(random_bytes(3)),
            'kind'              => $kind,
            'age_recipient'     => 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq',
            'presign_endpoint'  => '',
            'manifest_endpoint' => '',
            'progress_endpoint' => '',
            'chunk_bytes'       => 4 * 1024 * 1024,
            'scratch_dir'       => $this->outDir,
            'wp_content_path'   => $this->sourceDir,
            'db'                => ['host' => 'localhost', 'user' => 'wp', 'password' => 'wp', 'name' => 'wp', 'prefix' => 'wp_'],
        ], $extraParams));
    }

    /**
     * Invoke TaskRunner::runArchivingFiles (private) via reflection. Returns the
     * updated sub_state returned by the method.
     *
     * @param array<string,mixed> $subState
     * @return array<string,mixed>
     */
    private function invokeRunArchivingFiles(TaskRunner $runner, array $subState): array
    {
        $ref = new \ReflectionMethod(TaskRunner::class, 'runArchivingFiles');
        /** @var array<string,mixed> $out */
        $out = $ref->invoke($runner, $subState);
        return $out;
    }

    // ==================================================================
    // Reflection helpers
    // ==================================================================

    /**
     * Invoke EncryptAndUpload::entryKind (private) via reflection.
     */
    private function invokeEntryKind(string $logical): string
    {
        // We need a minimal EncryptAndUpload instance. All constructor deps are
        // typed but we can mock them at the interface level via anonymous classes
        // or we can use null coalescing. The simplest path is to construct a
        // stub and reflect — but EncryptAndUpload requires concrete collaborators.
        // Reflect the static-ish method instead via a stub reflection.
        // Alternate: reflect without instantiation by handing null to the
        // ReflectionMethod and supplying a throw-away object.
        $rc     = new ReflectionClass(EncryptAndUpload::class);
        $method = $rc->getMethod('entryKind');
        $method->setAccessible(true);

        // EncryptAndUpload has required constructor args; build the minimum
        // stub using a partial-mock approach compatible with PHP 8.0+.
        // We supply a no-op anonymous class for each interface argument.
        $obj = $rc->newInstanceWithoutConstructor();
        return (string) $method->invoke($obj, $logical);
    }

    /**
     * Invoke RestoreRunner::classifyArtifactKind (private) via reflection.
     */
    private function invokeClassifyArtifactKind(string $logical): string
    {
        $rc     = new ReflectionClass(RestoreRunner::class);
        $method = $rc->getMethod('classifyArtifactKind');
        $method->setAccessible(true);
        $obj    = $rc->newInstanceWithoutConstructor();
        return (string) $method->invoke($obj, $logical);
    }

    /**
     * Recursively delete a directory tree.
     */
    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        $entries = @scandir($path);
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
