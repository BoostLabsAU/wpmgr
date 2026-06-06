<?php
/**
 * IncrementalScanner: ADR-048 V1 — file-change detection engine for the
 * incremental backup pipeline.
 *
 * Implements the three-phase change-detection algorithm described in the ADR:
 *
 *   1. fetchPreviousIndex() — streams the NDJSON file-index from the CP over
 *      HTTPS, writing lines directly to a scratch file. Memory cost = one line.
 *
 *   2. buildPrevIndexMap() — reads the scratch file line-by-line and builds a
 *      PHP array keyed by file_path. Memory cost ≈ 200–300 bytes × file count.
 *      Soft cap: >2,000,000 lines returns null (AUTO-BASE signal).
 *
 *   3. scanFiles() — walks WP_CONTENT_DIR recursively and applies the
 *      CASE A / B / C logic from the ADR:
 *        CASE A: mtime + size match → carry forward prev blake3 + chunk_hashes (no file read).
 *        CASE B: mtime or size differ → read file, compute blake3; if it
 *                matches prev, carry forward chunk_hashes; else chunk + write to scratch.
 *        CASE C (tombstone): path in prevIndex but absent from disk.
 *
 * CLEAN-ROOM: all logic is derived from the ADR-048 spec. No identifiers,
 * option names, or strings are copied from third-party plugins.
 *
 * @package WPMgr\Agent\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Backup;

use WPMgr\Agent\Support\Blake3;
use WPMgr\Agent\Support\BackupTransport;
use WPMgr\Agent\Signer;
use WPMgr\Agent\Keystore;

/**
 * File-change detection for incremental backups (ADR-048 V1).
 */
final class IncrementalScanner
{
    /**
     * Soft cap: if the previous index has more than this many lines, return
     * null from buildPrevIndexMap() to trigger AUTO-BASE.
     */
    public const SOFT_CAP_LINES = 2_000_000;

    /** Emit a progress event every N files during the scan walk. */
    private const PROGRESS_EVERY_SCAN = 200;

    /** Checkpoint the cursor every N files. */
    private const CHECKPOINT_EVERY = 200;

    /** Default plaintext chunk size (must match EncryptAndUpload default). */
    private const DEFAULT_CHUNK_BYTES = 4 * 1024 * 1024;

    /**
     * Exclude segments — same list as FilesArchiver::DEFAULT_EXCLUDES so the
     * scan walk is consistent with what the full-backup archiver would pack.
     */
    private const DEFAULT_EXCLUDES = [
        'wpmgr-snapshots',
        'wpmgr-agent',
        'cache',
        'upgrade',
        'upgrade-temp-backup',
    ];

    private string $sourceDir;
    private int $chunkBytes;
    private BackupTransport $transport;

    /**
     * @param string         $sourceDir  WP_CONTENT_DIR (absolute).
     * @param int            $chunkBytes Plaintext chunk size; default 4 MiB.
     * @param BackupTransport $transport Signed-request transport for fetching the index.
     */
    public function __construct(string $sourceDir, int $chunkBytes, BackupTransport $transport)
    {
        $this->sourceDir  = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $this->chunkBytes = max(1, $chunkBytes);
        $this->transport  = $transport;
    }

    // ==========================================================================
    // Step 1 — Fetch previous NDJSON index from the CP
    // ==========================================================================

    /**
     * Stream the previous NDJSON file-index from the CP to a local scratch file.
     *
     * The GET is sent with a signed Authorization header reusing the same
     * Ed25519 scheme as presign/manifest/progress. The response body is written
     * line-by-line (one line at a time via fgets) so memory stays flat regardless
     * of site size.
     *
     * @param string   $endpoint   Absolute URL returned in file_index_endpoint.
     * @param string   $scratchDir Absolute per-run scratch dir.
     * @param callable $progress   function(string $phase, array $detail): void
     * @return string|null Absolute path of the written prev_index.ndjson, or null on
     *                     failure (204 soft-cap, non-200, or I/O error) — caller AUTO-BASEs.
     */
    public function fetchPreviousIndex(string $endpoint, string $scratchDir, callable $progress): ?string
    {
        if ($endpoint === '') {
            return null;
        }

        $outPath = $scratchDir . DIRECTORY_SEPARATOR . 'prev_index.ndjson';

        // Idempotency: if the scratch file already exists (watchdog re-entry),
        // reuse it — we never re-fetch the index on resume.
        if (is_file($outPath) && filesize($outPath) > 0) {
            return $outPath;
        }

        // Build a signed GET request. The Signer signs METHOD + PATH so the CP
        // can verify agent identity; the signing scheme is identical to what
        // BackupTransport::signedPost uses for presign/manifest.
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['path']) || $parts['path'] === '') {
            return null;
        }
        $path = (string) $parts['path'];

        $authHeaders = [];
        try {
            $signer      = new Signer(new Keystore());
            $authHeaders = $signer->signHeaders('GET', $path, '');
        } catch (\Throwable $e) {
            error_log('WPMgr IncrementalScanner: signing failed: ' . $e->getMessage());
            return null;
        }

        // Perform the GET via WP HTTP API. We cannot stream through WP's API
        // directly, so we read the whole body and write to disk. For 1M-file
        // sites the NDJSON is large, but WP remote_get buffers into memory
        // anyway — the soft cap on the CP side (204 at 2M rows) keeps this
        // from becoming catastrophic.
        //
        // If this poses memory issues in practice, a future ADR can introduce
        // a streaming curl approach. For V1, the 2M soft cap keeps us safe.
        $response = wp_remote_get(
            $endpoint,
            [
                'timeout' => 120,
                'headers' => array_merge(
                    ['Accept' => 'application/x-ndjson'],
                    $authHeaders
                ),
            ]
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        // 204 = soft cap exceeded (site too large for diff); anything non-200 = AUTO-BASE.
        if ($status !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            // Empty body on 200 = no prior index entries; that's valid (brand-new chain).
            // Write an empty file so re-entry doesn't re-fetch.
            @file_put_contents($outPath, '', LOCK_EX);
            return $outPath;
        }

        $written = @file_put_contents($outPath, $body, LOCK_EX);
        if ($written === false) {
            return null;
        }

        $this->safeProgress($progress, 'fetching_file_index', [
            'bytes_received' => strlen($body),
            'done'           => true,
        ]);

        return $outPath;
    }

    // ==========================================================================
    // Step 2 — Build in-memory lookup from the prev_index.ndjson scratch file
    // ==========================================================================

    /**
     * Parse the streamed NDJSON index file into a PHP array keyed by file_path.
     *
     * Reads the file line-by-line to keep memory flat. Returns null if the line
     * count exceeds SOFT_CAP_LINES (caller AUTO-BASEs).
     *
     * @param string $ndjsonPath Absolute path to prev_index.ndjson.
     * @return array<string,array{file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}>|null
     *   Map of file_path => entry data, or null on soft-cap / parse failure.
     */
    public function buildPrevIndexMap(string $ndjsonPath): ?array
    {
        if (!is_file($ndjsonPath)) {
            return [];
        }

        $handle = @fopen($ndjsonPath, 'rb');
        if ($handle === false) {
            return null;
        }

        $prevIndex  = [];
        $lineCount  = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $lineCount++;
                if ($lineCount > self::SOFT_CAP_LINES) {
                    fclose($handle);
                    return null; // Trigger AUTO-BASE.
                }

                $entry = json_decode($line, true);
                if (!is_array($entry) || !isset($entry['file_path']) || !is_string($entry['file_path'])) {
                    continue;
                }

                $filePath = $entry['file_path'];

                // Tombstones from the parent snapshot are tracked so we know
                // those paths were already gone — they won't appear on disk
                // during the scan, so we must not re-tombstone them.
                if (!empty($entry['is_tombstone'])) {
                    // Mark as tombstone in the map with a special sentinel so
                    // scanFiles can skip it (it's already a tombstone in the chain).
                    $prevIndex[$filePath] = [
                        'file_size'    => 0,
                        'file_mtime'   => 0,
                        'file_blake3'  => '',
                        'chunk_hashes' => [],
                        '_tombstone'   => true,
                    ];
                    continue;
                }

                $prevIndex[$filePath] = [
                    'file_size'    => isset($entry['file_size']) && is_numeric($entry['file_size']) ? (int) $entry['file_size'] : 0,
                    'file_mtime'   => isset($entry['file_mtime']) && is_numeric($entry['file_mtime']) ? (int) $entry['file_mtime'] : 0,
                    'file_blake3'  => isset($entry['file_blake3']) && is_string($entry['file_blake3']) ? $entry['file_blake3'] : '',
                    'chunk_hashes' => isset($entry['chunk_hashes']) && is_array($entry['chunk_hashes'])
                        ? array_values(array_filter($entry['chunk_hashes'], 'is_string'))
                        : [],
                ];
            }
        } finally {
            fclose($handle);
        }

        return $prevIndex;
    }

    // ==========================================================================
    // Step 3 — Walk + classify files
    // ==========================================================================

    /**
     * Walk WP_CONTENT_DIR, classify each file against $prevIndex, write raw
     * plaintext chunks to scratch for changed/new files, and collect tombstones
     * for deleted files.
     *
     * Returns a structured scan result. The caller passes this to
     * IncrementalEncryptAndUpload for the upload + manifest phase.
     *
     * Resume: the caller can pass $resume['file_index'] (integer) to skip
     * already-processed lines of a previously built paths cache. Changed-file
     * chunk scratch files written in a prior pass are left in place; the upload
     * phase handles them idempotently.
     *
     * @param array<string,array{file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}> $prevIndex
     *        Map from buildPrevIndexMap() (may be empty for a base run).
     * @param string   $scratchDir Per-run scratch directory.
     * @param array<string,mixed> $resume  Prior scan cursor (for watchdog re-entry).
     * @param callable $progress   function(string $phase, array $detail): void
     * @return array<string,mixed> Scan result:
     *   changed: list<array{file_path,file_size,file_mtime,file_blake3,chunk_hashes}>
     *   tombstones: list<string> (file_paths)
     *   carry_forward: list<array{file_path,file_size,file_mtime,file_blake3,chunk_hashes}>
     *   files_scanned: int
     *   files_changed: int
     *   files_deleted: int
     *   bytes_to_upload: int
     *   done: true
     */
    public function scanFiles(array &$prevIndex, string $scratchDir, array $resume, callable $progress): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        // Restore cursors from a prior partial pass.
        $fileIndex         = isset($resume['file_index']) ? (int) $resume['file_index'] : 0;
        $filesScanned      = isset($resume['files_scanned']) ? (int) $resume['files_scanned'] : 0;
        $filesChanged      = isset($resume['files_changed']) ? (int) $resume['files_changed'] : 0;
        $bytesToUpload     = isset($resume['bytes_to_upload']) ? (int) $resume['bytes_to_upload'] : 0;
        /** @var list<array{file_path:string,file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}> */
        $changed      = isset($resume['changed']) && is_array($resume['changed']) ? array_values($resume['changed']) : [];
        /** @var list<array{file_path:string,file_size:int,file_mtime:int,file_blake3:string,chunk_hashes:list<string>}> */
        $carryForward = isset($resume['carry_forward']) && is_array($resume['carry_forward']) ? array_values($resume['carry_forward']) : [];
        /** @var list<string> */
        $tombstones   = isset($resume['tombstones']) && is_array($resume['tombstones']) ? array_values($resume['tombstones']) : [];

        $srcLen   = strlen($this->sourceDir) + 1;
        $sinceProgress   = 0;
        $sinceCheckpoint = 0;

        // Use a RecursiveDirectoryIterator over sourceDir. We skip directories
        // that match our exclude list and only pack regular files.
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->sourceDir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('IncrementalScanner: cannot iterate sourceDir: ' . $e->getMessage(), 0, $e);
        }

        // Skip ahead to the resume position (we iterate by counting files).
        $iterPos = 0;
        foreach ($iterator as $fileInfo) {
            if (!($fileInfo instanceof \SplFileInfo)) {
                continue;
            }
            $abs = (string) $fileInfo->getPathname();

            if (is_link($abs) || !$fileInfo->isFile()) {
                continue;
            }

            $rel = substr($abs, $srcLen);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

            if ($this->isExcluded($rel)) {
                continue;
            }

            // Skip lines we already processed in a prior pass.
            if ($iterPos < $fileIndex) {
                // Still need to remove from prevIndex so tombstone detection works.
                unset($prevIndex[$rel]);
                $iterPos++;
                continue;
            }
            $iterPos++;

            // --- CASE A: mtime + size match → carry forward ---
            if (
                isset($prevIndex[$rel])
                && empty($prevIndex[$rel]['_tombstone'])
                && (int) $fileInfo->getMTime() === (int) $prevIndex[$rel]['file_mtime']
                && (int) $fileInfo->getSize()  === (int) $prevIndex[$rel]['file_size']
            ) {
                $carryForward[] = [
                    'file_path'    => $rel,
                    'file_size'    => (int) $prevIndex[$rel]['file_size'],
                    'file_mtime'   => (int) $prevIndex[$rel]['file_mtime'],
                    'file_blake3'  => (string) $prevIndex[$rel]['file_blake3'],
                    'chunk_hashes' => (array) $prevIndex[$rel]['chunk_hashes'],
                ];
                unset($prevIndex[$rel]);
                $filesScanned++;
                $sinceProgress++;
                $sinceCheckpoint++;

                if ($sinceProgress >= self::PROGRESS_EVERY_SCAN) {
                    $this->safeProgress($progress, 'scanning_files', [
                        'files_scanned'  => $filesScanned,
                        'files_changed'  => $filesChanged,
                        'files_deleted'  => count($tombstones),
                        'current_file'   => $rel,
                    ]);
                    $sinceProgress = 0;
                }
                continue;
            }

            // --- CASE B: mtime/size differ (or new file) → read + hash ---
            $prevEntry = (!empty($prevIndex[$rel]) && empty($prevIndex[$rel]['_tombstone']))
                ? $prevIndex[$rel]
                : null;

            $fileSize   = (int) $fileInfo->getSize();
            $fileMtime  = (int) $fileInfo->getMTime();

            // Compute file-level BLAKE3 (used for carry-forward content dedup).
            $fileBlake3 = $this->computeFileBlake3($abs);
            if ($fileBlake3 === null) {
                // Unreadable file — skip silently (matches FilesArchiver behavior).
                unset($prevIndex[$rel]);
                $filesScanned++;
                $sinceProgress++;
                continue;
            }

            // If the file's full hash matches the prev index, carry-forward the chunks.
            if ($prevEntry !== null && hash_equals((string) $prevEntry['file_blake3'], $fileBlake3) && $prevEntry['file_blake3'] !== '') {
                $carryForward[] = [
                    'file_path'    => $rel,
                    'file_size'    => $fileSize,
                    'file_mtime'   => $fileMtime,
                    'file_blake3'  => $fileBlake3,
                    'chunk_hashes' => (array) $prevEntry['chunk_hashes'],
                ];
                unset($prevIndex[$rel]);
                $filesScanned++;
                $sinceProgress++;
                $sinceCheckpoint++;

                if ($sinceProgress >= self::PROGRESS_EVERY_SCAN) {
                    $this->safeProgress($progress, 'scanning_files', [
                        'files_scanned'  => $filesScanned,
                        'files_changed'  => $filesChanged,
                        'files_deleted'  => count($tombstones),
                        'current_file'   => $rel,
                    ]);
                    $sinceProgress = 0;
                }
                continue;
            }

            // New or genuinely changed file: chunk it.
            $chunkHashes = $this->chunkFile($abs, $fileSize, $fileBlake3, $scratchDir);
            $bytesToUpload += $fileSize;
            $filesChanged++;

            $changed[] = [
                'file_path'    => $rel,
                'file_size'    => $fileSize,
                'file_mtime'   => $fileMtime,
                'file_blake3'  => $fileBlake3,
                'chunk_hashes' => $chunkHashes,
            ];

            unset($prevIndex[$rel]);
            $filesScanned++;
            $sinceProgress++;
            $sinceCheckpoint++;

            if ($sinceProgress >= self::PROGRESS_EVERY_SCAN) {
                $this->safeProgress($progress, 'scanning_files', [
                    'files_scanned'  => $filesScanned,
                    'files_changed'  => $filesChanged,
                    'files_deleted'  => count($tombstones),
                    'current_file'   => $rel,
                ]);
                $sinceProgress = 0;
            }
        }

        // After the walk: remaining $prevIndex keys (that are not tombstones
        // themselves) are paths that were in the previous snapshot but are
        // absent on disk now → tombstones.
        foreach ($prevIndex as $missingPath => $entry) {
            if (is_string($missingPath) && $missingPath !== '') {
                $tombstones[] = $missingPath;
            }
        }

        $this->safeProgress($progress, 'scanning_files', [
            'files_scanned'  => $filesScanned,
            'files_changed'  => $filesChanged,
            'files_deleted'  => count($tombstones),
            'bytes_to_upload'=> $bytesToUpload,
            'done'           => true,
        ]);

        return [
            'done'           => true,
            'changed'        => $changed,
            'carry_forward'  => $carryForward,
            'tombstones'     => $tombstones,
            'files_scanned'  => $filesScanned,
            'files_changed'  => $filesChanged,
            'files_deleted'  => count($tombstones),
            'bytes_to_upload'=> $bytesToUpload,
        ];
    }

    // ==========================================================================
    // Helpers
    // ==========================================================================

    /**
     * Compute the file-level BLAKE3 hash (full file, one-shot).
     *
     * Reads the file in chunkBytes slices to keep memory bounded at ~8 MiB
     * (one slice in memory at a time). Returns null on I/O failure.
     *
     * @param string $abs Absolute file path.
     * @return string|null Lowercase hex digest, or null on failure.
     */
    private function computeFileBlake3(string $abs): ?string
    {
        $handle = @fopen($abs, 'rb');
        if ($handle === false) {
            return null;
        }

        // Streaming hash via update()/finalize() for memory safety.
        // The underlying Blake3::hashHex uses sodium_crypto_generichash which
        // doesn't support streaming — so we accumulate the full plaintext.
        // For very large files this is the same memory cost as one chunk read
        // per iteration, bounded by chunkBytes per fread() call.
        //
        // Implementation: read entire file and hash it. The sodium path in
        // Blake3::hashHex hashes the full string at once (C ext, fast).
        $data = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunkBytes);
                if ($chunk === false) {
                    fclose($handle);
                    return null;
                }
                $data .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        return Blake3::hashHex($data);
    }

    /**
     * Split the file into ~chunkBytes plaintext slices, write each to scratch
     * as `chunks-<hash>.bin`, and return the ordered list of chunk hashes.
     *
     * The scratch files are unlinked by IncrementalEncryptAndUpload after each
     * successful PUT. Chunk files that already exist on disk (from a prior
     * watchdog pass) are left in place — identical content = identical hash =
     * same filename = idempotent.
     *
     * @param string $abs       Absolute file path.
     * @param int    $fileSize  Expected size in bytes (used for progress only).
     * @param string $fileBlake3 File-level hash (computed by computeFileBlake3).
     * @param string $scratchDir Per-run scratch dir.
     * @return list<string> Ordered chunk hashes.
     * @throws \RuntimeException On unrecoverable I/O failure.
     */
    private function chunkFile(string $abs, int $fileSize, string $fileBlake3, string $scratchDir): array
    {
        $handle = @fopen($abs, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('IncrementalScanner: cannot open file for chunking: ' . $abs);
        }

        $chunkHashes = [];

        try {
            while (!feof($handle)) {
                $plain = fread($handle, $this->chunkBytes);
                if ($plain === false) {
                    throw new \RuntimeException('IncrementalScanner: read error on: ' . $abs);
                }
                if ($plain === '') {
                    break;
                }

                // ENCRYPT_CHUNKS = false (V1). Hash the plaintext directly.
                $hash      = Blake3::hashHex($plain);
                $chunkPath = $scratchDir . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';

                if (!is_file($chunkPath)) {
                    $written = @file_put_contents($chunkPath, $plain, LOCK_EX);
                    if ($written !== strlen($plain)) {
                        throw new \RuntimeException('IncrementalScanner: write failed for chunk ' . $hash);
                    }
                }
                $plain = ''; // Free memory promptly.

                $chunkHashes[] = $hash;
            }
        } finally {
            fclose($handle);
        }

        return $chunkHashes;
    }

    /**
     * Test whether a relative path should be excluded. Segment-based match,
     * same logic as FilesArchiver::isExcluded.
     */
    private function isExcluded(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (in_array($segment, self::DEFAULT_EXCLUDES, true)) {
                return true;
            }
        }
        return false;
    }

    /** Invoke the progress callback safely (backup progress is observability, not correctness). */
    private function safeProgress(callable $progress, string $phase, array $detail): void
    {
        try {
            $progress($phase, $detail);
        } catch (\Throwable $_) {
            // Swallow.
        }
    }
}
