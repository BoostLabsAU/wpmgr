<?php
/**
 * Regression test for the 0.21.0 "base records ZERO files" bug.
 *
 * The single-pass + inline-upload refactor (commit 2475ec2) is the production
 * scan path: TaskRunner::runScanFiles() calls IncrementalScanner::enableInlineUpload()
 * before scanning, so single-chunk files are presigned + PUT straight from RAM.
 * The prior IncrementalScannerSinglePassTest exercises the scanner with inline
 * upload DISABLED, so it never covered this path. This test runs the scanner with
 * inline upload ENABLED over a fresh BASE (empty prev index) and asserts the
 * invariant that became the live regression:
 *
 *   a BASE over N files yields N changed[] rows — one per file (NOT zero) — each
 *   with its size, mtime, file_blake3, and ORDERED chunk_hashes. changed[] is the
 *   array TaskRunner threads into IncrementalEncryptAndUpload::submitIncrementalManifest,
 *   which builds files_entries one-to-one from it.
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
final class IncrementalScannerInlineRecordTest extends TestCase
{
    private string $root = '';
    private string $scratch = '';
    private int $chunkBytes = 0;

    protected function set_up(): void
    {
        parent::set_up();
        $base          = sys_get_temp_dir() . '/wpmgr-inline-' . bin2hex(random_bytes(6));
        $this->root    = $base . '/content';
        $this->scratch = $base . '/scratch';
        mkdir($this->root, 0755, true);
        mkdir($this->scratch, 0755, true);
        // Small chunk size so a couple of fixtures exercise the multi-chunk
        // (spill-to-scratch) path alongside the dominant single-chunk inline path.
        $this->chunkBytes = 1024;
    }

    protected function tear_down(): void
    {
        $this->rmdirR(dirname($this->root));
        parent::tear_down();
    }

    private function writeFile(string $rel, string $content): void
    {
        $abs = $this->root . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($abs, $content);
    }

    private function noopProgress(): callable
    {
        return static function (string $phase, array $detail): void {
        };
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rmdirR($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** A transport that, like the CP on a fresh base, has NONE of the chunks. */
    private function freshBaseTransport(): BackupTransport
    {
        return new class extends BackupTransport {
            public function __construct()
            {
            }

            public function presignChunks(string $endpoint, string $snapshotId, array $hashes): array
            {
                $out = [];
                foreach ($hashes as $h) {
                    $out[$h] = 'https://put/' . $h;
                }
                return $out;
            }

            public function putChunksMulti(array $urlsByHash, callable $getBytes, int $concurrency = 6): array
            {
                $r = [];
                foreach ($urlsByHash as $h => $_url) {
                    $bytes = $getBytes($h);
                    $r[$h] = is_string($bytes);
                }
                return $r;
            }
        };
    }

    /**
     * The core regression: a BASE with INLINE UPLOAD ENABLED records one changed[]
     * row per file — NOT zero — with the full per-file record (size/mtime/blake3/
     * ordered chunk_hashes) that becomes a files_entries row.
     */
    public function test_base_with_inline_upload_records_every_file(): void
    {
        $fixtures = [
            'plugins/a/index.php' => "<?php\necho 'a';\n",                       // tiny single chunk -> inline
            'themes/t/style.css'  => str_repeat('x', $this->chunkBytes),        // exactly 1 chunk -> inline
            'mu-plugins/x.php'    => "<?php // x\n",                             // tiny single chunk -> inline
            'uploads/big.bin'     => str_repeat('B', $this->chunkBytes * 2 + 7),// 3 chunks -> spill
            'uploads/two.bin'     => str_repeat('C', $this->chunkBytes * 2),    // 2 chunks -> spill
        ];
        $contents = [];
        foreach ($fixtures as $rel => $content) {
            $this->writeFile($rel, $content);
            $contents[$rel] = $content;
        }

        $scanner = new IncrementalScanner($this->root, $this->chunkBytes, $this->freshBaseTransport());
        // Production enables inline upload (TaskRunner::runScanFiles).
        $scanner->enableInlineUpload('https://cp.example/presign', 'snap-base-0');

        $prev   = [];
        $result = $scanner->scanFiles($prev, $this->scratch, [], $this->noopProgress());

        // One changed[] row per file — the exact rows that become files_entries.
        $this->assertCount(
            count($fixtures),
            $result['changed'],
            'BASE must record one changed[] row per file (got ' . count($result['changed']) . ')'
        );
        $this->assertSame(count($fixtures), $result['files_changed']);

        $changed = [];
        foreach ($result['changed'] as $e) {
            $changed[$e['file_path']] = $e;
        }

        foreach ($fixtures as $rel => $_c) {
            $this->assertArrayHasKey($rel, $changed, "missing $rel in changed[]");
            $entry = $changed[$rel];

            $this->assertSame(strlen($contents[$rel]), $entry['file_size'], "wrong file_size for $rel");
            $this->assertGreaterThan(0, $entry['file_mtime'], "missing file_mtime for $rel");
            $this->assertNotSame('', $entry['file_blake3'], "empty file_blake3 for $rel");
            $this->assertSame(Blake3::hashHex($contents[$rel]), $entry['file_blake3'], "wrong file_blake3 for $rel");

            // Ordered chunk_hashes match a fresh slice-and-hash of the content.
            $expected = [];
            $offset   = 0;
            $len      = strlen($contents[$rel]);
            while ($offset < $len) {
                $expected[] = Blake3::hashHex(substr($contents[$rel], $offset, $this->chunkBytes));
                $offset    += $this->chunkBytes;
            }
            $this->assertSame($expected, array_values($entry['chunk_hashes']), "ordered chunk_hashes mismatch for $rel");
        }

        // Inline path actually fired: the single-chunk files were PUT from RAM.
        $this->assertGreaterThanOrEqual(3, count($result['uploaded_hashes']), 'inline upload did not record any hashes');
    }
}
