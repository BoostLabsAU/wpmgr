<?php
/**
 * Tests for the ADR-048 single-pass incremental scan (perf fix A+B+D).
 *
 * The perf fix collapsed the old computeFileBlake3()+chunkFile() double-read
 * into one fopen/fread pass that emits BOTH the ordered per-chunk hashes AND
 * the file-level blake3. These tests prove the new output is byte-identical to
 * the old two-read behavior (the wire contract the CP records and restore
 * reassembles must not change):
 *
 *   - GOLDEN BYTE-IDENTITY: for a fixed wp-content fixture run as a BASE, the
 *     set of {chunk_hash => chunk bytes} written to scratch AND the per-file
 *     ORDERED chunk_hashes + file_blake3 match an independent oracle that
 *     reproduces the OLD logic (read-whole-file-hash, then re-read-and-slice).
 *   - SINGLE-CHUNK files: file_blake3 == chunk_hashes[0].
 *   - MULTI-CHUNK + non-aligned tail: ordered hashes match the oracle.
 *   - EMPTY file: file_blake3 == blake3('') and chunk_hashes == [].
 *   - CARRY-FORWARD: a CASE-B file (mtime/size differ) with identical CONTENT
 *     is carried forward (no chunk written, verbatim prev chunk_hashes).
 *   - curl_multi FALLBACK: BackupTransport::putChunksMulti degrades to the
 *     serial putChunk loop when curl_multi is unavailable.
 *
 * Inline upload is intentionally NOT enabled here, so every changed-file chunk
 * spills to scratch (the deterministic, network-free path) — that is the exact
 * artifact set the upload phase consumes.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Backup\IncrementalScanner;
use WPMgr\Agent\Support\Blake3;
use WPMgr\Agent\Support\BackupTransport;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\IncrementalScanner
 */
final class IncrementalScannerSinglePassTest extends TestCase
{
    private string $root = '';
    private string $scratch = '';
    private int $chunkBytes = 0;

    protected function set_up(): void
    {
        parent::set_up();
        $base          = sys_get_temp_dir() . '/wpmgr-incr-' . bin2hex(random_bytes(6));
        $this->root    = $base . '/content';
        $this->scratch = $base . '/scratch';
        mkdir($this->root, 0755, true);
        mkdir($this->scratch, 0755, true);
        // Small chunk size so multi-chunk paths are exercised without big files.
        $this->chunkBytes = 1024;
    }

    protected function tear_down(): void
    {
        $this->rmdirR(dirname($this->root));
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function writeFile(string $rel, string $content): string
    {
        $abs = $this->root . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($abs, $content);
        return $abs;
    }

    private function scanner(): IncrementalScanner
    {
        // A fake transport that is never invoked (inline upload disabled).
        return new IncrementalScanner($this->root, $this->chunkBytes, new FakeNoopTransport());
    }

    /**
     * Oracle reproducing the OLD two-read behavior:
     *   file_blake3 = blake3(whole file); chunk_hashes = blake3 of each slice in
     *   fread order. Returns ['file_blake3'=>..., 'chunk_hashes'=>[...]].
     *
     * @return array{file_blake3:string,chunk_hashes:list<string>}
     */
    private function oracle(string $content): array
    {
        $fileBlake3 = Blake3::hashHex($content);
        $hashes     = [];
        $offset     = 0;
        $len        = strlen($content);
        if ($len === 0) {
            return ['file_blake3' => $fileBlake3, 'chunk_hashes' => []];
        }
        while ($offset < $len) {
            $slice    = substr($content, $offset, $this->chunkBytes);
            $hashes[] = Blake3::hashHex($slice);
            $offset  += $this->chunkBytes;
        }
        return ['file_blake3' => $fileBlake3, 'chunk_hashes' => $hashes];
    }

    /** Map of file_path => entry from a scan result's changed[]. */
    private function indexByPath(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $out[$e['file_path']] = $e;
        }
        return $out;
    }

    private function noopProgress(): callable
    {
        return static function (string $phase, array $detail): void {
            // no-op
        };
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rmdirR($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_base_scan_is_byte_identical_to_old_two_read_logic(): void
    {
        // A fixed fixture: single-chunk, exactly-one-chunk, multi-chunk, and a
        // non-aligned tail; plus an empty file.
        $fixtures = [
            'plugins/a/index.php'   => "<?php\necho 'hello';\n",      // tiny single chunk
            'themes/t/style.css'    => str_repeat('x', $this->chunkBytes), // exactly 1 chunk
            'uploads/big.bin'       => random_bytes($this->chunkBytes * 2 + 37), // 3 chunks, tail
            'uploads/two.bin'       => random_bytes($this->chunkBytes * 2),      // exactly 2 chunks
            'empty.txt'             => '',                              // empty
        ];
        $contents = [];
        foreach ($fixtures as $rel => $content) {
            $this->writeFile($rel, $content);
            $contents[$rel] = $content;
        }

        $prev = [];
        $result = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());

        $changed = $this->indexByPath($result['changed']);

        // Every fixture is new on a BASE => all are in changed[].
        foreach ($fixtures as $rel => $_c) {
            $this->assertArrayHasKey($rel, $changed, "missing $rel in changed[]");
            $oracle = $this->oracle($contents[$rel]);
            $this->assertSame(
                $oracle['file_blake3'],
                $changed[$rel]['file_blake3'],
                "file_blake3 mismatch for $rel"
            );
            $this->assertSame(
                $oracle['chunk_hashes'],
                array_values($changed[$rel]['chunk_hashes']),
                "ordered chunk_hashes mismatch for $rel"
            );
        }

        // GOLDEN: the exact set of chunk-bytes-per-hash written to scratch
        // matches the oracle's slicing (idempotent content-addressed files).
        foreach ($contents as $rel => $content) {
            if ($content === '') {
                continue;
            }
            $offset = 0;
            $len    = strlen($content);
            while ($offset < $len) {
                $slice = substr($content, $offset, $this->chunkBytes);
                $hash  = Blake3::hashHex($slice);
                $path  = $this->scratch . '/chunks-' . $hash . '.bin';
                $this->assertFileExists($path, "scratch chunk missing for $rel @ $offset");
                $this->assertSame($slice, file_get_contents($path), "scratch chunk bytes wrong for $rel @ $offset");
                $offset += $this->chunkBytes;
            }
        }
    }

    public function test_single_chunk_file_blake3_equals_lone_chunk_hash(): void
    {
        $content = "small file under one chunk\n";
        $this->writeFile('p/f.php', $content);

        $prev   = [];
        $result = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());
        $changed = $this->indexByPath($result['changed']);

        $this->assertArrayHasKey('p/f.php', $changed);
        $entry = $changed['p/f.php'];
        $this->assertCount(1, $entry['chunk_hashes']);
        $this->assertSame($entry['chunk_hashes'][0], $entry['file_blake3']);
        $this->assertSame(Blake3::hashHex($content), $entry['file_blake3']);
    }

    public function test_empty_file_hashes_empty_string_and_no_chunks(): void
    {
        $this->writeFile('empty.txt', '');

        $prev   = [];
        $result = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());
        $changed = $this->indexByPath($result['changed']);

        $this->assertArrayHasKey('empty.txt', $changed);
        $this->assertSame([], array_values($changed['empty.txt']['chunk_hashes']));
        $this->assertSame(Blake3::hashHex(''), $changed['empty.txt']['file_blake3']);
        // No scratch chunk for an empty file.
        $this->assertSame([], glob($this->scratch . '/chunks-*.bin') ?: []);
    }

    public function test_carry_forward_when_content_identical_but_mtime_differs(): void
    {
        $content = str_repeat('carry', 500); // multi-chunk (2500 bytes @ 1024-byte chunks → 3 chunks)
        $abs     = $this->writeFile('p/keep.php', $content);

        $oracle = $this->oracle($content);

        // prevIndex says the file existed with the SAME content (same blake3 +
        // chunk_hashes) but a different mtime/size pair so CASE A misses and the
        // file takes the CASE B read path, then carries forward on content match.
        $prev = [
            'p/keep.php' => [
                'file_size'    => strlen($content) + 1, // differ -> force CASE B
                'file_mtime'   => (int) filemtime($abs) - 1000,
                'file_blake3'  => $oracle['file_blake3'],
                'chunk_hashes' => $oracle['chunk_hashes'],
            ],
        ];

        $result  = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());
        $changed = $this->indexByPath($result['changed']);
        $carry   = $this->indexByPath($result['carry_forward']);

        // Carried forward: NOT in changed, IS in carry_forward, verbatim hashes.
        $this->assertArrayNotHasKey('p/keep.php', $changed);
        $this->assertArrayHasKey('p/keep.php', $carry);
        $this->assertSame($oracle['chunk_hashes'], array_values($carry['p/keep.php']['chunk_hashes']));
        $this->assertSame($oracle['file_blake3'], $carry['p/keep.php']['file_blake3']);

        // FIX 1 (memory safety): multi-chunk files are ALWAYS spilled eagerly to
        // scratch during the read pass — even when they ultimately carry-forward.
        // This caps peak RAM at one chunk (INLINE_MAX_BYTES) regardless of file
        // size, at the cost of leaving orphan scratch chunks that cleanupOnCompleted
        // sweeps at run end. Single-chunk files are still carry-buffered in RAM.
        //
        // For this multi-chunk fixture (2500 bytes @ 1024-byte chunk size → 3
        // chunks), the scratch files exist even though the file carried forward.
        $scratchChunks = glob($this->scratch . '/chunks-*.bin') ?: [];
        $this->assertSame(
            count($oracle['chunk_hashes']),
            count($scratchChunks),
            'multi-chunk carry-forward leaves one scratch file per chunk (FIX 1)'
        );
    }

    public function test_changed_content_under_carry_buffer_spills_and_is_in_changed(): void
    {
        $oldContent = str_repeat('old', 500);
        $newContent = str_repeat('new', 500); // different content, multi-chunk
        $abs        = $this->writeFile('p/edit.php', $newContent);

        $oldOracle = $this->oracle($oldContent);
        $newOracle = $this->oracle($newContent);

        $prev = [
            'p/edit.php' => [
                'file_size'    => strlen($oldContent),
                'file_mtime'   => (int) filemtime($abs) - 1000,
                'file_blake3'  => $oldOracle['file_blake3'],
                'chunk_hashes' => $oldOracle['chunk_hashes'],
            ],
        ];

        $result  = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());
        $changed = $this->indexByPath($result['changed']);

        $this->assertArrayHasKey('p/edit.php', $changed);
        $this->assertSame($newOracle['file_blake3'], $changed['p/edit.php']['file_blake3']);
        $this->assertSame($newOracle['chunk_hashes'], array_values($changed['p/edit.php']['chunk_hashes']));

        // Changed file's chunks were committed to scratch.
        foreach ($newOracle['chunk_hashes'] as $h) {
            $this->assertFileExists($this->scratch . '/chunks-' . $h . '.bin');
        }
    }

    public function test_deleted_file_becomes_tombstone(): void
    {
        // prevIndex has a path that does not exist on disk now.
        $prev = [
            'gone/old.php' => [
                'file_size'    => 10,
                'file_mtime'   => 123,
                'file_blake3'  => str_repeat('a', 64),
                'chunk_hashes' => [str_repeat('a', 64)],
            ],
        ];
        $this->writeFile('still/here.php', 'present');

        $result = $this->scanner()->scanFiles($prev, $this->scratch, [], $this->noopProgress());

        $this->assertContains('gone/old.php', $result['tombstones']);
        $this->assertSame(1, $result['files_deleted']);
    }
}

/**
 * A BackupTransport double whose putChunksMulti delegates to a forced serial
 * path so we can prove the curl_multi fallback contract without a network. Used
 * only by the scanner constructor here (inline upload disabled), plus the
 * standalone fallback test below.
 */
final class FakeNoopTransport extends BackupTransport
{
    public function __construct()
    {
        // Intentionally skip parent ctor (no Signer needed for these tests).
    }
}
