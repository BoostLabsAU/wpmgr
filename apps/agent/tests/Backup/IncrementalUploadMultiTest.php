<?php
/**
 * Tests for the ADR-048 incremental upload phase after the perf fix (D + B):
 * concurrent PUTs via BackupTransport::putChunksMulti and the inline-upload
 * resume accounting.
 *
 * These prove the upload phase:
 *   - presigns ALL changed-file chunk hashes (dedup is the CP's job),
 *   - PUTs (via putChunksMulti) ONLY the presigned hashes that aren't already
 *     uploaded (inline during scan, or a prior pass) and that exist on scratch,
 *   - marks dedup hits (presign omitted them) done WITHOUT a PUT,
 *   - throws on any un-acked PUT failure (so the run retries),
 *   - never expects a scratch file for an inline-uploaded hash.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use Brain\Monkey;
use WPMgr\Agent\Backup\IncrementalEncryptAndUpload;
use WPMgr\Agent\Support\BackupTransport;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Backup\IncrementalEncryptAndUpload
 */
final class IncrementalUploadMultiTest extends TestCase
{
    private string $scratch = '';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        $this->scratch = sys_get_temp_dir() . '/wpmgr-upl-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0755, true);
    }

    protected function tear_down(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->scratch);
        Monkey\tearDown();
        parent::tear_down();
    }

    private function spill(string $hash, string $bytes): void
    {
        file_put_contents($this->scratch . '/chunks-' . $hash . '.bin', $bytes);
    }

    private function noopProgress(): callable
    {
        return static function (string $phase, array $detail): void {
        };
    }

    private function pipeline(BackupTransport $t): IncrementalEncryptAndUpload
    {
        return new IncrementalEncryptAndUpload(
            $t,
            'snap-1',
            'age1xxxx',
            'https://cp.example/presign',
            'https://cp.example/manifest',
            4 * 1024 * 1024
        );
    }

    public function test_only_missing_presigned_hashes_are_put_and_dedup_hits_are_marked_done(): void
    {
        // 3 chunks across 2 files. h_stored is a dedup hit (CP omits it from
        // presign). h_a and h_b are missing -> presigned -> PUT.
        $this->spill('h_a', 'AAAA');
        $this->spill('h_b', 'BBBB');
        $this->spill('h_stored', 'CCCC');

        $changed = [
            ['file_path' => 'p/a.php', 'file_size' => 4, 'file_mtime' => 1, 'file_blake3' => 'h_a', 'chunk_hashes' => ['h_a']],
            ['file_path' => 'p/b.php', 'file_size' => 8, 'file_mtime' => 1, 'file_blake3' => 'fb', 'chunk_hashes' => ['h_b', 'h_stored']],
        ];

        $transport = new RecordingTransport(
            // presign returns ONLY the missing ones (dedup omits h_stored).
            ['h_a' => 'https://put/a', 'h_b' => 'https://put/b'],
            // every PUT succeeds.
            static fn(string $h): bool => true
        );

        $result = $this->pipeline($transport)->uploadChunks($changed, $this->scratch, [], $this->noopProgress());

        // Both missing chunks were PUT exactly once; the dedup hit was not.
        sort($transport->putHashes);
        $this->assertSame(['h_a', 'h_b'], $transport->putHashes);

        // All three hashes end up marked uploaded (2 PUT + 1 dedup hit).
        sort($result['uploaded_hashes']);
        $this->assertSame(['h_a', 'h_b', 'h_stored'], $result['uploaded_hashes']);
        $this->assertSame(2, $result['chunks_put']);
        $this->assertSame(8, $result['bytes_uploaded']); // 4 + 4

        // Scratch files for PUT chunks + the dedup hit are cleaned up.
        $this->assertFileDoesNotExist($this->scratch . '/chunks-h_a.bin');
        $this->assertFileDoesNotExist($this->scratch . '/chunks-h_b.bin');
        $this->assertFileDoesNotExist($this->scratch . '/chunks-h_stored.bin');
    }

    public function test_inline_uploaded_hash_is_skipped_and_no_scratch_required(): void
    {
        // h_inline was already PUT inline during the scan pass (no scratch file
        // exists for it). The CP still lists it in presign (it asks for all it
        // doesn't have yet), but the resume cursor marks it done so we must NOT
        // try to read it off scratch (which would be the fatal "missing local
        // chunk").
        $this->spill('h_disk', 'DDDD');

        $changed = [
            ['file_path' => 'p/x.php', 'file_size' => 4, 'file_mtime' => 1, 'file_blake3' => 'h_inline', 'chunk_hashes' => ['h_inline']],
            ['file_path' => 'p/y.php', 'file_size' => 4, 'file_mtime' => 1, 'file_blake3' => 'h_disk', 'chunk_hashes' => ['h_disk']],
        ];

        $transport = new RecordingTransport(
            ['h_inline' => 'https://put/i', 'h_disk' => 'https://put/d'],
            static fn(string $h): bool => true
        );

        // Resume cursor seeds h_inline as already uploaded (the runner merges the
        // scanner's inline uploaded_hashes into this).
        $resume = ['uploaded_hashes' => ['h_inline']];

        $result = $this->pipeline($transport)->uploadChunks($changed, $this->scratch, $resume, $this->noopProgress());

        // Only h_disk is PUT; h_inline is skipped (never read from scratch).
        $this->assertSame(['h_disk'], $transport->putHashes);
        sort($result['uploaded_hashes']);
        $this->assertSame(['h_disk', 'h_inline'], $result['uploaded_hashes']);
    }

    public function test_put_failure_throws_so_the_run_retries(): void
    {
        $this->spill('h_ok', 'OKOK');
        $this->spill('h_bad', 'BAD!');

        $changed = [
            ['file_path' => 'p/a.php', 'file_size' => 4, 'file_mtime' => 1, 'file_blake3' => 'h_ok', 'chunk_hashes' => ['h_ok']],
            ['file_path' => 'p/b.php', 'file_size' => 4, 'file_mtime' => 1, 'file_blake3' => 'h_bad', 'chunk_hashes' => ['h_bad']],
        ];

        $transport = new RecordingTransport(
            ['h_ok' => 'https://put/ok', 'h_bad' => 'https://put/bad'],
            // h_bad fails its PUT.
            static fn(string $h): bool => $h !== 'h_bad'
        );

        $this->expectException(\RuntimeException::class);
        $this->pipeline($transport)->uploadChunks($changed, $this->scratch, [], $this->noopProgress());
    }

    /**
     * REGRESSION (0.21.x DB-only base): the scan reported changed files but the
     * per-file records were lost upstream (e.g. a wiped sub_state). Submitting an
     * empty files_entries while filesChanged > 0 would record a silent, useless
     * DB-only snapshot. submitIncrementalManifest() must refuse and throw so the
     * run is retried instead of completing with zero files.
     */
    public function test_submit_manifest_refuses_empty_files_when_scan_reported_changes(): void
    {
        $transport = new RecordingTransport([], static fn(string $h): bool => true);
        $pipeline  = $this->pipeline($transport);

        // changedFiles empty, but the scan reported 7 changed files.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty files_entries/');
        $pipeline->submitIncrementalManifest(
            [],            // changedFiles — LOST upstream
            [],            // tombstones
            [['path' => 'database.sql.gz', 'entry_kind' => 'db', 'chunks' => []]], // db_entries present
            10,            // filesScanned
            7,             // filesChanged > 0 -> guard fires
            0,             // filesDeleted
            0,             // bytesUploaded
            $this->noopProgress()
        );
    }

    /**
     * The guard must NOT fire for a legitimate zero-change increment: no changed
     * files AND filesChanged == 0 is a valid (metadata-only / tombstone-only)
     * submission. Here we also have no tombstones, so the only way this throws is
     * a false-positive guard. We stub wp_remote_post to a 2xx so the real submit
     * path completes without network.
     */
    public function test_submit_manifest_allows_empty_files_on_zero_change_increment(): void
    {
        Monkey\Functions\when('wp_json_encode')->alias(static fn($d) => json_encode($d));
        Monkey\Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Monkey\Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Monkey\Functions\when('is_wp_error')->justReturn(false);

        $transport = new RecordingTransport([], static fn(string $h): bool => true);
        // Use a manifest endpoint with a path so parse_url succeeds.
        $pipeline  = new IncrementalEncryptAndUpload(
            $transport,
            'snap-1',
            'age1xxxx',
            'https://cp.example/presign',
            'https://cp.example/manifest',
            4 * 1024 * 1024
        );

        // Signer is constructed inside submitIncrementalManifest; if it fails the
        // test would error before our assertion. Guard that with a try/skip.
        try {
            $pipeline->submitIncrementalManifest(
                [], [], [], 5, 0, 0, 0, $this->noopProgress()
            );
            $this->addToAssertionCount(1); // Reached here = guard did not false-fire.
        } catch (\RuntimeException $e) {
            // A signing failure (no keystore in unit env) is acceptable and is NOT
            // the empty-files guard; only fail if the guard misfired.
            $this->assertStringNotContainsString('empty files_entries', $e->getMessage());
        }
    }
}

/**
 * A BackupTransport double recording presign + multi-PUT activity.
 */
final class RecordingTransport extends BackupTransport
{
    /** @var array<string,string> */
    private array $presignResult;
    /** @var callable */
    private $putOk;
    /** @var list<string> Hashes that putChunksMulti attempted to PUT. */
    public array $putHashes = [];

    /**
     * @param array<string,string> $presignResult hash => url (only missing hashes)
     * @param callable             $putOk         function(string $hash): bool
     */
    public function __construct(array $presignResult, callable $putOk)
    {
        // Skip parent ctor — no Signer needed.
        $this->presignResult = $presignResult;
        $this->putOk         = $putOk;
    }

    public function presignChunks(string $endpoint, string $snapshotId, array $hashes): array
    {
        // Return only the subset of requested hashes that we "don't have".
        $out = [];
        foreach ($hashes as $h) {
            if (isset($this->presignResult[$h])) {
                $out[$h] = $this->presignResult[$h];
            }
        }
        return $out;
    }

    public function putChunksMulti(array $urlsByHash, callable $getBytes, int $concurrency = 6): array
    {
        $results = [];
        foreach ($urlsByHash as $hash => $url) {
            // Exercise the lazy byte fetch (proves we read the right scratch file).
            $bytes = $getBytes($hash);
            if (!is_string($bytes)) {
                $results[$hash] = false;
                continue;
            }
            $this->putHashes[] = $hash;
            $results[$hash]    = (bool) ($this->putOk)($hash);
        }
        return $results;
    }
}
