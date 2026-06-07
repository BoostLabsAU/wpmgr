<?php
/**
 * ADR-051 / 0.21.6 END-TO-END prevMap-pipeline test.
 *
 * This is the test that the live archive-delta bug slipped past: every prior
 * incremental test injected a prevMap DIRECTLY into FilesArchiver::archive().
 * NONE exercised the on-the-wire round-trip that decides whether the prevMap
 * is even populated:
 *
 *     base archive  ->  emits files.list (real wire bytes)
 *                   ->  files.list split into chunks (upload pipeline)
 *                   ->  CP presigns the chunks (PrevFilesListChunks)
 *     increment     ->  TaskRunner::loadPrevFilesListMap() FETCHES + CONCATS
 *                   ->  FilesArchiver::loadPrevMap() PARSES
 *                   ->  FilesArchiver::archive() FILTERS
 *
 * If ANY link in that pipeline is broken (empty PrevFilesListChunks, a failed
 * presigned GET, an encrypted-vs-plaintext mismatch, a parse/relpath-key
 * mismatch) the prevMap comes back EMPTY and the increment re-archives the
 * WHOLE site while still reporting success — exactly the 104 MB full-rearchive
 * seen on msm.oscod.dev (snapshot 7797b8c0, gen 1).
 *
 * The test drives the REAL, FINAL TaskRunner::runArchivingFiles() (via
 * reflection). It does NOT stub anything: the prev files.list chunks are written
 * to real local files and handed to the runner as `file://` URLs, which the
 * production TaskRunner::fetchUrl() fetches via its file_get_contents fallback.
 * saveTaskState is a safe no-op without a $wpdb global. So the prevMap is built
 * by the exact production fetch + concat + parse path, then filtered by the
 * exact production FilesArchiver.
 *
 * Core assertions (FAIL pre-fix when the prevMap is empty, PASS post-fix):
 *   files_changed == the changed/new count   (NOT all files)
 *   files_carried == the unchanged count     (carry-forward proven)
 *
 * @package WPMgr\Agent\Tests\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Backup;

use WPMgr\Agent\Backup\FilesArchiver;
use WPMgr\Agent\Backup\TaskRunner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\TaskRunner
 * @covers \WPMgr\Agent\Backup\FilesArchiver
 */
final class PrevMapPipelineE2ETest extends TestCase
{
    private string $root      = '';
    private string $sourceDir = '';
    private string $scratch   = '';
    private string $chunkDir  = '';

    protected function set_up(): void
    {
        parent::set_up();
        $this->root      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmgr-prevmap-e2e-' . bin2hex(random_bytes(4));
        $this->sourceDir = $this->root . DIRECTORY_SEPARATOR . 'wp-content';
        $this->scratch   = $this->root . DIRECTORY_SEPARATOR . 'scratch';
        $this->chunkDir  = $this->root . DIRECTORY_SEPARATOR . 'prevchunks';
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->scratch, 0755, true);
        mkdir($this->chunkDir, 0755, true);
    }

    protected function tear_down(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
        parent::tear_down();
    }

    /**
     * The headline e2e: a base emits files.list -> the increment fetches THAT
     * SAME files.list over the (local file://) wire -> archives ONLY the changed
     * subset. Asserts files_changed == changed count and files_carried ==
     * unchanged count — NOT all-changed.
     */
    public function test_increment_over_wire_archives_only_changed_subset(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        // ---------- base gen-0 tree (5 files) ----------
        mkdir($this->sourceDir . '/plugins', 0755, true);
        mkdir($this->sourceDir . '/themes', 0755, true);
        mkdir($this->sourceDir . '/uploads', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/keep1.php', str_repeat('K', 120));
        file_put_contents($this->sourceDir . '/plugins/keep2.php', str_repeat('L', 140));
        file_put_contents($this->sourceDir . '/plugins/changed.php', 'CHANGED-v0');
        file_put_contents($this->sourceDir . '/themes/changed.css', 'CSS-v0');
        file_put_contents($this->sourceDir . '/uploads/old.jpg', str_repeat('J', 200));

        // Run a REAL gen-0 archive to produce the base's on-the-wire files.list.
        $g0Out = $this->root . DIRECTORY_SEPARATOR . 'g0';
        mkdir($g0Out, 0755, true);
        $arch0 = new FilesArchiver($this->sourceDir, [], [], 0);
        $r0    = $arch0->archive($g0Out, [], static function (): void {});
        self::assertSame(true, $r0['done'] ?? null);

        $baseFilesList = $g0Out . DIRECTORY_SEPARATOR . FilesArchiver::FILES_LIST_NAME;
        self::assertFileExists($baseFilesList, 'base must emit a files.list');
        $wireBytes = (string) file_get_contents($baseFilesList);
        self::assertNotSame('', $wireBytes, 'base files.list must carry the real wire bytes');

        // ---------- chunk the base files.list exactly like the upload pipeline ----------
        // ENCRYPT_CHUNKS=false (V0): chunks are PLAINTEXT, content-addressed.
        // Use a SMALL chunk size so the files.list spans MULTIPLE chunks — this
        // proves the in-order concat in loadPrevFilesListMap reassembles a list
        // whose lines straddle chunk boundaries (the multi-chunk wire case).
        $prevChunks = $this->chunkToFileUrls($wireBytes, 24);
        self::assertGreaterThan(1, count($prevChunks), 'files.list must span multiple chunks for a real test');

        // ---------- mutate the tree for gen-1 ----------
        // change 2, add 1, delete 1, keep 2 unchanged.
        usleep(1100000); // advance mtimes past gen-0
        file_put_contents($this->sourceDir . '/plugins/changed.php', 'CHANGED-v1-MUCH-LONGER');
        file_put_contents($this->sourceDir . '/themes/changed.css', 'CSS-v1-MUCH-LONGER');
        file_put_contents($this->sourceDir . '/uploads/new.png', str_repeat('N', 90)); // added
        unlink($this->sourceDir . '/uploads/old.jpg');                                  // deleted
        // keep1.php and keep2.php are untouched.

        // ---------- drive the REAL increment pipeline ----------
        $runner      = $this->makeRunner($prevChunks, 1);
        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        // ---------- the headline assertions ----------
        // 3 changed/new: plugins/changed.php, themes/changed.css, uploads/new.png.
        // 2 carried:     plugins/keep1.php, plugins/keep2.php.
        self::assertSame(3, (int) $result['files_changed'], 'exactly the 3 changed/new files are packed');
        self::assertSame(2, (int) $result['files_carried'], 'exactly the 2 unchanged files are carried forward (NOT re-archived)');
        self::assertSame(1, (int) $result['tombstones_count'], 'the deleted file is a tombstone');
        self::assertSame(5, (int) $result['prevmap_size'], 'prevMap parsed all 5 base entries from the wire');

        // Hard regression guard against the live bug: a full re-archive would
        // pack ALL FIVE present files (changed=5, carried=0).
        self::assertNotSame(5, (int) $result['files_changed'], 'increment must NOT re-archive the whole site');

        // Prove the packed zip really contains only the changed/new files.
        $packed = $this->packedRelpaths($this->scratch, $result['parts']);
        sort($packed);
        self::assertSame(
            ['plugins/changed.php', 'themes/changed.css', 'uploads/new.png'],
            $packed,
            'only the changed + new files are in the archive parts'
        );
        self::assertNotContains('plugins/keep1.php', $packed);
        self::assertNotContains('plugins/keep2.php', $packed);
    }

    /**
     * Negative control: when the wire fetch yields the WRONG bytes (the
     * encrypted-vs-plaintext / format-mismatch failure mode) the prevMap parses
     * to ZERO entries even though chunks WERE sent. The 0.21.6 guard must:
     *   (a) NOT silently treat it as a legit base, and
     *   (b) fall back to a full re-archive (correct, if not delta) rather than
     *       a half-broken diff.
     * This pins the empty/null-prevMap link the brief flagged as the prime suspect.
     */
    public function test_garbage_prev_bytes_fall_back_to_full_not_silent_partial(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/a.php', str_repeat('A', 50));
        file_put_contents($this->sourceDir . '/plugins/b.php', str_repeat('B', 60));

        // Chunks ARE presigned (non-empty), but the bytes returned are NOT the
        // plaintext "rel\tsize\tmtime" format — they parse to zero entries.
        $garbage    = "\x00\x01\x02not-a-files-list\xff\xfe";
        $prevChunks = $this->chunkToFileUrls($garbage, 8);
        self::assertNotSame([], $prevChunks, 'the corrupt prev list still presigns chunks');

        $runner      = $this->makeRunner($prevChunks, 1);
        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        // Full fallback: both files are packed (changed=2, carried=0). The point
        // is that it is CORRECT (no file lost), and the guard logged the anomaly
        // instead of pretending the empty prevMap was a base.
        self::assertSame(2, (int) $result['files_changed'], 'a corrupt prev list falls back to a full re-archive, not a partial diff');
        self::assertSame(0, (int) $result['files_carried']);
        self::assertSame(0, (int) $result['tombstones_count'], 'no tombstones invented from a corrupt prev list');
    }

    /**
     * A legit gen-0 base (no prev chunks) archives everything as new, with NO
     * tombstones — the empty-vs-corrupt distinction the guard preserves.
     */
    public function test_no_prev_chunks_is_a_clean_base(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip not available');
        }

        mkdir($this->sourceDir . '/plugins', 0755, true);
        file_put_contents($this->sourceDir . '/plugins/a.php', 'A');
        file_put_contents($this->sourceDir . '/plugins/b.php', 'B');

        $runner      = $this->makeRunner([], 0); // no prev chunks => base
        $subStateOut = $this->invokeRunArchivingFiles($runner, []);
        $result      = $subStateOut['files'];

        self::assertSame(2, (int) $result['files_changed'], 'a base archives all files as new');
        self::assertSame(0, (int) $result['files_carried']);
        self::assertSame(0, (int) $result['tombstones_count']);
        self::assertSame(0, (int) $result['prevmap_size']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Split $bytes into content-addressed plaintext chunks of $chunkBytes each,
     * write each to a local file, and return the RestoreChunk-shaped descriptors
     * (as the CP sends in prev_files_list_chunks) with `file://` URLs the
     * production fetchUrl() will GET — in stream order.
     *
     * @return list<array{hash:string,url:string,size:int}>
     */
    private function chunkToFileUrls(string $bytes, int $chunkBytes): array
    {
        $descriptors = [];
        $offset      = 0;
        $len         = strlen($bytes);
        $i           = 0;
        while ($offset < $len) {
            $slice = substr($bytes, $offset, $chunkBytes);
            $hash  = hash('sha256', $slice);
            $path  = $this->chunkDir . DIRECTORY_SEPARATOR . sprintf('%04d-%s.bin', $i, $hash);
            file_put_contents($path, $slice);
            $descriptors[] = [
                'hash' => $hash,
                'url'  => 'file://' . $path, // production fetchUrl() reads this
                'size' => strlen($slice),
            ];
            $offset += $chunkBytes;
            $i++;
        }
        return $descriptors;
    }

    /**
     * @param list<array{hash:string,url:string,size:int}> $prevChunks
     */
    private function makeRunner(array $prevChunks, int $generation): TaskRunner
    {
        return new TaskRunner([
            'snapshot_id'            => 'e2e-' . bin2hex(random_bytes(3)),
            'kind'                   => 'files',
            'age_recipient'          => 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqsxxxxx',
            'presign_endpoint'       => '',
            'manifest_endpoint'      => '',
            'progress_endpoint'      => '', // keeps progressClient null (no network)
            'chunk_bytes'            => 4 * 1024 * 1024,
            'scratch_dir'            => $this->scratch,
            'wp_content_path'        => $this->sourceDir,
            'db'                     => [],
            'is_incremental'         => true,
            'parent_snapshot_id'     => 'parent-xyz',
            'base_snapshot_id'       => 'base-xyz',
            'generation'             => $generation,
            'prev_files_list_chunks' => $prevChunks,
        ]);
    }

    /**
     * @param array<string,mixed> $subState
     * @return array<string,mixed>
     */
    private function invokeRunArchivingFiles(TaskRunner $runner, array $subState): array
    {
        // runArchivingFiles is private; PHP 8.1+ reflection invokes it without
        // setAccessible(). This drives the EXACT production code path.
        $ref = new \ReflectionMethod(TaskRunner::class, 'runArchivingFiles');
        /** @var array<string,mixed> $out */
        $out = $ref->invoke($runner, $subState);
        return $out;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
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
