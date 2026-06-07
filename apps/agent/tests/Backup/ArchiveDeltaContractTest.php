<?php
/**
 * ADR-051 archive-delta agent<->CP CONTRACT test (agent side).
 *
 * This proves the AGENT emits the EXACT wire strings the CP restore overlay
 * reads — the canonical contract that drifted between the parallel agent/CP
 * builds:
 *
 *   1. files.list artifact entry_kind == "files-list"  (NOT the default "file"),
 *      so the CP detects the archive-delta model and presigns it as the next
 *      increment's PrevFilesListChunks.
 *   2. tombstones are per-path manifest entries: entry_kind == "tombstones",
 *      mode == 0 (TombstoneModeDelete), EMPTY chunk list — read by the CP via
 *      ListManifest with no chunk fetch (NOT a chunked "tombstones.list" file).
 *   3. archive part names are generation-namespaced
 *      (`<component>.gNNN.partMMM.zip`) so gen-0 and gen-1 parts never collide
 *      by name on the restore overlay.
 *
 * It drives the REAL FilesArchiver (base gen-0 -> increment gen-1 with a
 * change + an add + a delete + an unchanged carry-forward) and the REAL agent
 * manifest classification (EncryptAndUpload::entryKind) and tombstone emission
 * (TaskRunner::appendTombstoneEntries), then asserts the emitted strings.
 *
 * Both private methods are exercised via reflection — they are the actual
 * production code path, not a re-implementation, so this test FAILS on the
 * pre-fix drift and PASSES after the fix.
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use WPMgr\Agent\Backup\EncryptAndUpload;
use WPMgr\Agent\Backup\FilesArchiver;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\FilesArchiver
 * @covers \WPMgr\Agent\Backup\EncryptAndUpload
 * @covers \WPMgr\Agent\Backup\TaskRunner
 */
final class ArchiveDeltaContractTest extends TestCase
{
    private string $sourceDir = '';
    private string $outDir    = '';

    protected function set_up(): void
    {
        parent::set_up();
        $base            = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-adc-' . bin2hex(random_bytes(4));
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
    // CONTRACT #1: files.list artifact is tagged entry_kind="files-list".
    // ==================================================================

    public function test_files_list_artifact_entry_kind_is_files_list(): void
    {
        $kind = $this->invokeEntryKind(FilesArchiver::FILES_LIST_NAME);
        self::assertSame(
            'files-list',
            $kind,
            'the files.list artifact MUST be tagged entry_kind="files-list" so the CP detects the archive-delta model'
        );
        // Regression guard: it must NOT fall through to the default "file".
        self::assertNotSame('file', $kind);
    }

    // ==================================================================
    // CONTRACT #3: archive part names are generation-namespaced and the
    // backup-side classifier still maps them to the component kind.
    // ==================================================================

    public function test_part_names_are_generation_namespaced_and_classify(): void
    {
        // gen-0 and gen-1 plugins parts MUST differ by name (no collision).
        $g0 = FilesArchiver::partName('plugins', 0, 1);
        $g1 = FilesArchiver::partName('plugins', 1, 1);
        self::assertSame('plugins.g000.part001.zip', $g0);
        self::assertSame('plugins.g001.part001.zip', $g1);
        self::assertNotSame($g0, $g1, 'gen-0 and gen-1 part names MUST NOT collide');

        // The namespaced names still classify to the right component kind on
        // BOTH the backup side (entryKind) and the shared classifier.
        self::assertSame('plugin', $this->invokeEntryKind($g0));
        self::assertSame('plugin', $this->invokeEntryKind($g1));
        self::assertSame('plugin', FilesArchiver::componentKindFromPartName($g1));
        self::assertSame('theme', FilesArchiver::componentKindFromPartName(FilesArchiver::partName('themes', 2, 3)));
        self::assertSame('upload', FilesArchiver::componentKindFromPartName(FilesArchiver::partName('uploads', 1, 1)));
        self::assertSame('wp-content', FilesArchiver::componentKindFromPartName(FilesArchiver::partName('wp-content', 1, 1)));
        // A non-part name is not a component.
        self::assertSame('', FilesArchiver::componentKindFromPartName('database.sql.gz'));
    }

    public function test_real_archiver_emits_generation_namespaced_parts(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // A real plugins file so the archiver actually emits a plugins part.
        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/changed.php', str_repeat('A', 64));

        $archiverG1 = new FilesArchiver($this->sourceDir, [], [], 1);
        $result     = $archiverG1->archive($this->outDir, [], static function (): void {});

        self::assertSame(true, $result['done'] ?? null);
        self::assertNotEmpty($result['parts']);
        // Every emitted part name carries the gen-1 namespace.
        foreach ($result['parts'] as $partName) {
            self::assertMatchesRegularExpression(
                '/^[a-z\-]+\.g001\.part\d{3}\.zip$/',
                (string) $partName,
                'gen-1 part name must be generation-namespaced'
            );
        }
    }

    // ==================================================================
    // CONTRACT #2: tombstones are per-path entries (entry_kind="tombstones",
    // mode=Delete, empty chunks), appended by the REAL TaskRunner path.
    // ==================================================================

    public function test_tombstones_emitted_as_per_path_manifest_entries(): void
    {
        // Encrypt-pass entries (e.g. a files-list entry) the runner already has.
        $existing = [[
            'path'       => FilesArchiver::FILES_LIST_NAME,
            'entry_kind' => 'files-list',
            'table_name' => '',
            'mode'       => 0,
            'size'       => 10,
            'chunks'     => [['blake3' => 'aa', 'size' => 10]],
        ]];

        $subState = [
            'files' => [
                'done'       => true,
                'tombstones' => ['plugins/deleted.php', 'themes/gone.css'],
            ],
        ];

        $out = $this->invokeAppendTombstones($existing, $subState);

        // The pre-existing files-list entry is preserved.
        self::assertSame('files-list', $out[0]['entry_kind']);

        // Exactly two tombstone entries appended, one per deleted path.
        $tombs = array_values(array_filter(
            $out,
            static fn ($e) => ($e['entry_kind'] ?? '') === 'tombstones'
        ));
        self::assertCount(2, $tombs, 'one tombstone manifest entry per deleted path');

        $byPath = [];
        foreach ($tombs as $t) {
            $byPath[$t['path']] = $t;
        }
        foreach (['plugins/deleted.php', 'themes/gone.css'] as $rel) {
            self::assertArrayHasKey($rel, $byPath, "tombstone entry for $rel must exist");
            $t = $byPath[$rel];
            // EXACT contract: entry_kind="tombstones", mode=Delete(0), no chunks.
            self::assertSame('tombstones', $t['entry_kind']);
            self::assertSame(TaskRunner::TOMBSTONE_MODE_DELETE, $t['mode']);
            self::assertSame(0, $t['mode'], 'agent tombstones are mode=Delete (0)');
            self::assertSame([], $t['chunks'], 'tombstone entries carry NO chunks');
        }
    }

    public function test_tombstone_emission_is_idempotent_on_reentry(): void
    {
        $subState = ['files' => ['tombstones' => ['plugins/x.php']]];

        // First pass appends one tombstone entry.
        $first = $this->invokeAppendTombstones([], $subState);
        self::assertCount(1, $first);

        // Re-running over the already-appended entries must NOT duplicate.
        $second = $this->invokeAppendTombstones($first, $subState);
        $tombs  = array_filter($second, static fn ($e) => ($e['entry_kind'] ?? '') === 'tombstones');
        self::assertCount(1, $tombs, 'tombstone append must be idempotent on watchdog re-entry');
    }

    public function test_unsafe_tombstone_paths_are_dropped(): void
    {
        $subState = ['files' => ['tombstones' => [
            '/etc/passwd',           // absolute — dropped
            '../escape.php',         // traversal — dropped
            'plugins/legit.php',     // safe — kept
        ]]];

        $out   = $this->invokeAppendTombstones([], $subState);
        $paths = array_map(static fn ($e) => $e['path'], $out);

        self::assertContains('plugins/legit.php', $paths);
        self::assertNotContains('/etc/passwd', $paths);
        self::assertNotContains('../escape.php', $paths);
    }

    // ==================================================================
    // END-TO-END: base gen-0 -> increment gen-1 (change + add + delete +
    // unchanged carry-forward) through the REAL FilesArchiver, then assemble
    // the agent's emitted manifest contract for the increment and assert it
    // is the exact shape the CP overlay reconstructs from.
    // ==================================================================

    public function test_base_to_increment_emits_contract_for_overlay_restore(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // --- base gen-0 full tree ---
        mkdir($this->sourceDir . '/plugins', 0755, true);
        mkdir($this->sourceDir . '/themes', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/keep.php', 'KEEP-v0');
        file_put_contents($this->sourceDir . '/plugins/changed.php', 'CHANGED-v0');
        file_put_contents($this->sourceDir . '/plugins/deleted.php', 'DELETED-v0');
        file_put_contents($this->sourceDir . '/themes/changed.css', 'CSS-v0');

        $g0Out  = $this->outDir . '/g0';
        mkdir($g0Out, 0755, true);
        $arch0  = new FilesArchiver($this->sourceDir, [], [], 0);
        $r0     = $arch0->archive($g0Out, [], static function (): void {});
        self::assertSame(true, $r0['done'] ?? null);

        // Build the prev map from the base's files.list (the real diff seed).
        $prevMap = FilesArchiver::loadPrevMap($g0Out . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME);
        self::assertArrayHasKey('plugins/keep.php', $prevMap);

        // --- mutate the tree for gen-1: change 2, add 1, delete 1 ---
        usleep(1100000); // ensure mtimes advance past gen-0
        file_put_contents($this->sourceDir . '/plugins/changed.php', 'CHANGED-v1-longer');
        file_put_contents($this->sourceDir . '/themes/changed.css', 'CSS-v1-longer');
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($this->sourceDir . '/uploads/added.png', 'PNG-v1');
        unlink($this->sourceDir . '/plugins/deleted.php');

        $g1Out = $this->outDir . '/g1';
        mkdir($g1Out, 0755, true);
        $arch1 = new FilesArchiver($this->sourceDir, [], [], 1);
        $r1    = $arch1->archive($g1Out, [], static function (): void {}, $prevMap);
        self::assertSame(true, $r1['done'] ?? null);

        // The increment packed the changed + added files (NOT keep.php).
        $packed = $this->packedRelpaths($g1Out, $r1['parts']);
        self::assertContains('plugins/changed.php', $packed, 'changed file packed in gen-1');
        self::assertContains('themes/changed.css', $packed, 'changed file packed in gen-1');
        self::assertContains('uploads/added.png', $packed, 'added file packed in gen-1');
        self::assertNotContains('plugins/keep.php', $packed, 'unchanged file NOT re-packed (carry-forward)');

        // The deleted file is recorded as a tombstone (on-disk file, 0.21.5+).
        self::assertSame(1, $r1['tombstones_count'], 'deleted.php must produce tombstones_count=1');
        self::assertNotSame('', $r1['tombstones_file'], 'tombstones_file must be a non-empty path');
        $tbContent = (string) file_get_contents((string) $r1['tombstones_file']);
        self::assertStringContainsString('plugins/deleted.php', $tbContent);

        // gen-1 part names are namespaced — they cannot collide with gen-0.
        $g0Parts = array_map('strval', $r0['parts']);
        $g1Parts = array_map('strval', $r1['parts']);
        self::assertSame([], array_intersect($g0Parts, $g1Parts), 'gen-0 and gen-1 part names MUST NOT collide');

        // --- assemble the gen-1 manifest the agent submits (real classifier) ---
        $entries = [];
        foreach ($g1Parts as $partName) {
            $entries[] = ['path' => $partName, 'entry_kind' => $this->invokeEntryKind($partName)];
        }
        $entries[] = [
            'path'       => FilesArchiver::FILES_LIST_NAME,
            'entry_kind' => $this->invokeEntryKind(FilesArchiver::FILES_LIST_NAME),
        ];
        $entries = $this->invokeAppendTombstones($entries, ['files' => $r1]);

        // Index by kind for the contract assertions.
        $kinds = [];
        foreach ($entries as $e) {
            $kinds[$e['entry_kind']][] = $e;
        }

        // files-list present and correctly tagged.
        self::assertArrayHasKey('files-list', $kinds);
        self::assertSame(FilesArchiver::FILES_LIST_NAME, $kinds['files-list'][0]['path']);

        // tombstone present, mode=Delete, empty chunks.
        self::assertArrayHasKey('tombstones', $kinds);
        $deletedTomb = null;
        foreach ($kinds['tombstones'] as $t) {
            if ($t['path'] === 'plugins/deleted.php') {
                $deletedTomb = $t;
            }
        }
        self::assertNotNull($deletedTomb, 'deleted.php must be a tombstone manifest entry');
        self::assertSame(0, $deletedTomb['mode']);
        self::assertSame([], $deletedTomb['chunks']);

        // component parts classified (not the default "file").
        $partKinds = [];
        foreach ($entries as $e) {
            if (str_ends_with((string) $e['path'], '.zip')) {
                $partKinds[$e['entry_kind']] = true;
            }
        }
        self::assertArrayNotHasKey('file', $partKinds, 'no namespaced part may classify as the legacy "file" kind');
        self::assertTrue(isset($partKinds['plugin']) || isset($partKinds['theme']) || isset($partKinds['upload']));
    }

    // ==================================================================
    // Reflection helpers (exercise the REAL private production methods).
    // ==================================================================

    private function invokeEntryKind(string $logical): string
    {
        // PHP 8.1+ allows invoking a private method via reflection without
        // setAccessible(). entryKind() does not touch instance state, so an
        // instance built without the constructor is sufficient.
        $ref = new \ReflectionMethod(EncryptAndUpload::class, 'entryKind');
        $obj = (new \ReflectionClass(EncryptAndUpload::class))->newInstanceWithoutConstructor();
        return (string) $ref->invoke($obj, $logical);
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed>       $subState
     * @return list<array<string,mixed>>
     */
    private function invokeAppendTombstones(array $entries, array $subState): array
    {
        $ref = new \ReflectionMethod(TaskRunner::class, 'appendTombstoneEntries');
        $obj = (new \ReflectionClass(TaskRunner::class))->newInstanceWithoutConstructor();
        /** @var list<array<string,mixed>> $out */
        $out = $ref->invoke($obj, $entries, $subState);
        return $out;
    }

    /**
     * @param list<string> $parts
     * @return list<string> Relpaths packed across all parts.
     */
    private function packedRelpaths(string $dir, array $parts): array
    {
        $rels = [];
        foreach ($parts as $partName) {
            $zip = new \ZipArchive();
            if ($zip->open($dir . DIRECTORY_SEPARATOR . (string) $partName) !== true) {
                continue;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rels[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        }
        return $rels;
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
