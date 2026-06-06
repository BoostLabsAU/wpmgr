<?php
/**
 * IncrementalEncryptAndUpload: ADR-048 V1 — upload pipeline for the
 * incremental backup engine.
 *
 * Unlike the full-backup EncryptAndUpload which processes zip-part artifacts,
 * this class operates on RAW per-file plaintext chunks written to scratch by
 * IncrementalScanner::scanFiles(). The dedup mechanism is identical: call the
 * CP presign endpoint with all chunk hashes; the CP returns presigned PUT URLs
 * ONLY for hashes it does not already have. Carry-forward chunks (unchanged
 * files) are never re-uploaded — their chunk hashes reference blobs already
 * in S3 from the parent snapshot.
 *
 * ENCRYPT_CHUNKS = false for V1 (plaintext chunks; same as EncryptAndUpload).
 *
 * Wire shape (IncrementalSubmitManifestRequest) follows the frozen ADR-048
 * contract verbatim. The manifest_endpoint is the same CP URL as for a full
 * backup; the CP differentiates by is_incremental=true.
 *
 * CLEAN-ROOM: no code or identifiers copied from third-party backup plugins.
 *
 * @package WPMgr\Agent\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Backup;

use WPMgr\Agent\Support\BackupTransport;
use WPMgr\Agent\Backup\Destinations\CpDestination;
use WPMgr\Agent\Backup\Destinations\DestinationResolver;

/**
 * Upload + manifest-submit pipeline for the ADR-048 incremental engine.
 */
final class IncrementalEncryptAndUpload
{
    /** Emit a progress event every N PUT calls. */
    private const PROGRESS_EVERY_UPLOAD = 4;

    private BackupTransport $transport;
    private string $snapshotId;
    private string $ageRecipient;
    private string $presignEndpoint;
    private string $manifestEndpoint;
    private int $chunkBytes;

    /**
     * @param BackupTransport $transport        Signed-request transport.
     * @param string          $snapshotId       In-flight snapshot id.
     * @param string          $ageRecipient     age1... public recipient (for manifest provenance).
     * @param string          $presignEndpoint  Absolute CP presign URL.
     * @param string          $manifestEndpoint Absolute CP manifest URL.
     * @param int             $chunkBytes       Plaintext chunk size (informational for this class).
     * @param array<string,mixed>|null $destinationParams Optional ADR-036 destination override.
     */
    public function __construct(
        BackupTransport $transport,
        string $snapshotId,
        string $ageRecipient,
        string $presignEndpoint,
        string $manifestEndpoint,
        int $chunkBytes,
        ?array $destinationParams = null
    ) {
        $this->transport        = $transport;
        $this->snapshotId       = $snapshotId;
        $this->ageRecipient     = $ageRecipient;
        $this->presignEndpoint  = $presignEndpoint;
        $this->manifestEndpoint = $manifestEndpoint;
        $this->chunkBytes       = max(1, $chunkBytes);
    }

    /**
     * Upload all changed/new file chunks to the CP-presigned object store.
     *
     * Reuses the EncryptAndUpload presign + PUT dedup pattern:
     *   1. Collect all unique chunk hashes from $changedFiles.
     *   2. Call presign_endpoint to get PUT URLs for ONLY the missing ones.
     *   3. PUT each missing chunk, unlink the local file after success.
     *   4. Unlink local files for hashes the CP already had (dedup hits).
     *
     * Carry-forward files (in $carryForward) are NOT uploaded — their chunks
     * already live in S3 from the parent snapshot.
     *
     * @param list<array{file_path:string,file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}> $changedFiles
     * @param string   $scratchDir   Per-run scratch dir where chunk-*.bin files live.
     * @param array<string,mixed> $resume Upload-pass resume cursor.
     * @param callable $progress function(string $phase, array $detail): void
     * @return array<string,mixed> Upload cursor with done=true and telemetry.
     * @throws \RuntimeException On transport-level PUT failure.
     */
    public function uploadChunks(array $changedFiles, string $scratchDir, array $resume, callable $progress): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        if (!empty($resume['done'])) {
            return $resume;
        }

        // Gather all unique chunk hashes from changed files.
        /** @var array<string,int> $allHashSet hash => 1 */
        $allHashSet = [];
        foreach ($changedFiles as $fileEntry) {
            if (!isset($fileEntry['chunk_hashes']) || !is_array($fileEntry['chunk_hashes'])) {
                continue;
            }
            foreach ($fileEntry['chunk_hashes'] as $h) {
                if (is_string($h) && $h !== '') {
                    $allHashSet[$h] = 1;
                }
            }
        }
        $allHashes   = array_keys($allHashSet);
        $chunksTotal = count($allHashes);

        // Already-uploaded hashes from a prior partial pass.
        /** @var array<string,int> $uploadedHashes */
        $uploadedHashes = [];
        if (isset($resume['uploaded_hashes']) && is_array($resume['uploaded_hashes'])) {
            foreach ($resume['uploaded_hashes'] as $h) {
                if (is_string($h) && $h !== '') {
                    $uploadedHashes[$h] = 1;
                }
            }
        }
        $bytesUploaded = isset($resume['bytes_uploaded']) ? (int) $resume['bytes_uploaded'] : 0;
        $filesDone     = isset($resume['files_done']) ? (int) $resume['files_done'] : 0;
        $putCount      = 0;
        $chunksDone    = count($uploadedHashes);
        $sinceTick     = 0;

        if ($chunksTotal > 0) {
            // Presign: CP returns {hash => presignedPutURL} for ONLY missing hashes.
            $uploads = $this->transport->presignChunks($this->presignEndpoint, $this->snapshotId, $allHashes);

            // PUT missing chunks.
            foreach ($uploads as $hash => $url) {
                if (!is_string($hash) || $hash === '' || !is_string($url) || $url === '') {
                    continue;
                }
                if (isset($uploadedHashes[$hash])) {
                    continue;
                }

                $chunkPath = $scratchDir . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                if (!is_file($chunkPath)) {
                    // If the chunk file is gone it means a prior pass already
                    // PUT it and unlinked it, but the cursor wasn't saved. Since
                    // the CP asked for it again (it's in $uploads), we have a
                    // consistency issue. Treat as fatal — the caller's top-level
                    // catch will increment resume_count.
                    throw new \RuntimeException('IncrementalEncryptAndUpload: missing local chunk for upload: ' . $hash);
                }

                $bytes = @file_get_contents($chunkPath);
                if ($bytes === false) {
                    throw new \RuntimeException('IncrementalEncryptAndUpload: cannot read chunk: ' . $chunkPath);
                }

                $ok = $this->transport->putChunk($url, $bytes);
                if (!$ok) {
                    throw new \RuntimeException('IncrementalEncryptAndUpload: PUT failed for chunk: ' . $hash);
                }
                $bytesUploaded += strlen($bytes);
                $bytes          = '';

                @unlink($chunkPath);

                $uploadedHashes[$hash] = 1;
                $putCount++;
                $chunksDone++;
                $sinceTick++;

                if ($sinceTick >= self::PROGRESS_EVERY_UPLOAD) {
                    $this->safeProgress($progress, 'uploading_incremental', [
                        'chunks_done'    => $chunksDone,
                        'chunks_total'   => $chunksTotal,
                        'bytes_uploaded' => $bytesUploaded,
                        'files_done'     => $filesDone,
                    ]);
                    $sinceTick = 0;
                }
            }

            // Cleanup: unlink local files for hashes the CP already had (dedup hits).
            foreach ($allHashes as $hash) {
                if (isset($uploadedHashes[$hash])) {
                    continue;
                }
                if (isset($uploads[$hash])) {
                    continue;
                }
                $chunkPath = $scratchDir . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                if (is_file($chunkPath)) {
                    @unlink($chunkPath);
                }
                $uploadedHashes[$hash] = 1;
                $chunksDone++;
            }
        }

        $this->safeProgress($progress, 'uploading_incremental', [
            'chunks_done'    => $chunksDone,
            'chunks_total'   => $chunksTotal,
            'bytes_uploaded' => $bytesUploaded,
            'files_done'     => count($changedFiles),
            'done'           => true,
        ]);

        return [
            'done'            => true,
            'chunks_total'    => $chunksTotal,
            'chunks_put'      => $putCount,
            'bytes_uploaded'  => $bytesUploaded,
            'uploaded_hashes' => array_keys($uploadedHashes),
        ];
    }

    /**
     * Submit the incremental manifest to the CP manifest endpoint.
     *
     * Builds the IncrementalSubmitManifestRequest wire shape per ADR-048:
     *   - files_entries: changed/new files + tombstones (carry-forward excluded).
     *   - db_entries: DB dump manifest entries using the existing ManifestEntry shape.
     *   - is_incremental: true.
     *   - cycle_* telemetry counters.
     *
     * Idempotent: a 4xx "already recorded" response is treated as success.
     *
     * @param list<array{file_path:string,file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}> $changedFiles
     * @param list<string> $tombstones   Deleted file paths.
     * @param list<array<string,mixed>>  $dbEntries    DB manifest entries (existing ManifestEntry shape).
     * @param int          $filesScanned
     * @param int          $filesChanged
     * @param int          $filesDeleted
     * @param int          $bytesUploaded
     * @param callable     $progress
     * @throws \RuntimeException On transport failure.
     */
    public function submitIncrementalManifest(
        array $changedFiles,
        array $tombstones,
        array $dbEntries,
        int $filesScanned,
        int $filesChanged,
        int $filesDeleted,
        int $bytesUploaded,
        callable $progress
    ): void {
        @set_time_limit(0);
        @ignore_user_abort(true);

        // Build files_entries: changed/new files first, then tombstones.
        $filesEntries = [];

        foreach ($changedFiles as $f) {
            $filesEntries[] = [
                'file_path'    => (string) ($f['file_path'] ?? ''),
                'file_size'    => (int) ($f['file_size'] ?? 0),
                'file_mtime'   => (int) ($f['file_mtime'] ?? 0),
                'file_blake3'  => (string) ($f['file_blake3'] ?? ''),
                'chunk_hashes' => array_values((array) ($f['chunk_hashes'] ?? [])),
                'is_tombstone' => false,
            ];
        }

        foreach ($tombstones as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $filesEntries[] = [
                'file_path'    => $path,
                'file_size'    => 0,
                'file_mtime'   => 0,
                'file_blake3'  => '',
                'chunk_hashes' => [],
                'is_tombstone' => true,
            ];
        }

        $body = (string) wp_json_encode([
            'snapshot_id'           => $this->snapshotId,
            'age_recipient'         => $this->ageRecipient,
            'is_incremental'        => true,
            'files_entries'         => $filesEntries,
            'db_entries'            => $dbEntries,
            'cycle_files_scanned'   => $filesScanned,
            'cycle_files_changed'   => $filesChanged,
            'cycle_files_deleted'   => $filesDeleted,
            'cycle_bytes_uploaded'  => $bytesUploaded,
        ]);

        if ($body === '') {
            throw new \RuntimeException('IncrementalEncryptAndUpload: failed to encode incremental manifest');
        }

        // Sign and POST using the same WP HTTP API path as BackupTransport.
        // We build the signed headers manually here because BackupTransport's
        // submitManifest() sends the existing non-incremental shape.
        $parts = parse_url($this->manifestEndpoint);
        if (!is_array($parts) || !isset($parts['path']) || $parts['path'] === '') {
            throw new \RuntimeException('IncrementalEncryptAndUpload: invalid manifest endpoint');
        }
        $path = (string) $parts['path'];

        $authHeaders = [];
        try {
            $signer      = new \WPMgr\Agent\Signer(new \WPMgr\Agent\Keystore());
            $authHeaders = $signer->signHeaders('POST', $path, $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'IncrementalEncryptAndUpload: manifest signing failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $authHeaders
        );

        $response = wp_remote_post(
            $this->manifestEndpoint,
            [
                'timeout' => 30,
                'headers' => $headers,
                'body'    => $body,
            ]
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            throw new \RuntimeException('IncrementalEncryptAndUpload: manifest submit — CP unreachable');
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        // 4xx "already recorded" = idempotent success.
        if ($status >= 200 && $status < 300) {
            $this->safeProgress($progress, 'submitting_manifest', [
                'done'          => true,
                'is_incremental'=> true,
                'files_entries' => count($filesEntries),
                'db_entries'    => count($dbEntries),
            ]);
            return;
        }

        if ($status >= 400 && $status < 500) {
            // Treat all 4xx as "already recorded" (idempotent). The CP
            // returns a 409/422 when the manifest was already submitted.
            $this->safeProgress($progress, 'submitting_manifest', [
                'done'           => true,
                'is_incremental' => true,
                'idempotent_hit' => true,
                'status'         => $status,
            ]);
            return;
        }

        throw new \RuntimeException(
            'IncrementalEncryptAndUpload: manifest submit returned HTTP ' . $status
        );
    }

    /** Invoke the progress callback safely. */
    private function safeProgress(callable $progress, string $phase, array $detail): void
    {
        try {
            $progress($phase, $detail);
        } catch (\Throwable $_) {
            // Swallow — progress is observability, not correctness.
        }
    }
}
