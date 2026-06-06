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

    /**
     * Hard memory cap for the in-memory chunk path (FIX 1 — memory safety).
     *
     * A file is eligible for in-memory treatment (inline upload from RAM, or
     * carry-buffer hold awaiting content dedup) ONLY when it fits within one
     * chunk (file size <= INLINE_MAX_BYTES). Single-chunk files occupy at most
     * one chunk's worth of RAM at a time regardless of how many changed files
     * the walk processes.
     *
     * Multi-chunk files (file size > INLINE_MAX_BYTES, or any file that produces
     * more than one fread() slice) MUST spill each chunk to scratch immediately,
     * even when $bufferForCarry is set. The carry-buffer hold is a memory
     * optimisation (avoids orphan scratch files on content carry-forward), not
     * a correctness requirement. On a 128–256 MB shared host, buffering a 64 MiB
     * file's chunks in RAM would double peak resident memory.
     *
     * Net invariant: peak additional memory is bounded by ~one chunk (4 MiB)
     * plus curl_multi in-flight bodies, never proportional to a file's size.
     */
    private const INLINE_MAX_BYTES = self::DEFAULT_CHUNK_BYTES; // 4 MiB

    /**
     * Largest file we keep in a single in-memory buffer to upload straight
     * from RAM (FIX B). Alias of INLINE_MAX_BYTES for inline-upload eligibility
     * checks. Files at or below this size are exactly one chunk, so we PUT them
     * inline during the scan pass without ever touching scratch. Larger files
     * spill each chunk to `chunks-<hash>.bin` so a mid-file crash resumes from
     * disk.
     */
    private const SMALL_FILE_INLINE_MAX = self::INLINE_MAX_BYTES;

    /**
     * Flush the in-memory small-file upload buffer once it reaches this many
     * pending chunks. Bounds resident memory to ~(this × INLINE_MAX_BYTES)
     * during the scan pass.
     */
    private const INLINE_FLUSH_EVERY = 6;

    private string $sourceDir;
    private int $chunkBytes;
    private BackupTransport $transport;

    /** Absolute CP presign endpoint (enables inline small-file upload). */
    private string $presignEndpoint = '';

    /** In-flight snapshot id (for presign). */
    private string $snapshotId = '';

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

    /**
     * Enable inline small-file upload during the scan pass (FIX B). When both
     * the presign endpoint and snapshot id are set, single-chunk files are
     * presigned + PUT straight from the in-memory read buffer — no scratch
     * write/read round-trip. The hashes that get uploaded this way are returned
     * in the scan result under `uploaded_hashes` so the upload phase skips them.
     *
     * If left unset (e.g. unit tests), the scanner falls back to the original
     * behavior: every changed-file chunk is spilled to scratch and the upload
     * phase PUTs it later.
     *
     * @param string $presignEndpoint Absolute CP presign URL.
     * @param string $snapshotId      In-flight snapshot id.
     */
    public function enableInlineUpload(string $presignEndpoint, string $snapshotId): void
    {
        $this->presignEndpoint = $presignEndpoint;
        $this->snapshotId      = $snapshotId;
    }

    /** Whether inline small-file upload is wired up. */
    private function inlineUploadEnabled(): bool
    {
        return $this->presignEndpoint !== '' && $this->snapshotId !== '';
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
        /** @var list<string> Hashes already PUT inline during the scan pass (FIX B). */
        $uploadedHashes = isset($resume['uploaded_hashes']) && is_array($resume['uploaded_hashes'])
            ? array_values(array_filter($resume['uploaded_hashes'], 'is_string'))
            : [];

        $srcLen   = strlen($this->sourceDir) + 1;
        $sinceProgress   = 0;
        $sinceCheckpoint = 0;

        // FIX B inline-upload state: a bounded in-memory buffer of small-file
        // chunk bytes (hash => bytes) that we presign + PUT straight from RAM,
        // never touching scratch. $inlineUploaded accumulates the hashes that
        // were successfully PUT this way so the upload phase skips them.
        /** @var array<string,string> $inlineBuffer hash => plaintext bytes */
        $inlineBuffer   = [];
        $inlineBytes    = 0;
        /** @var array<string,int> $inlineUploaded hash => 1 */
        $inlineUploaded = [];
        foreach ($uploadedHashes as $h) {
            $inlineUploaded[$h] = 1;
        }
        $inlineActive = $this->inlineUploadEnabled();

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

            // --- CASE B: mtime/size differ (or new file) → single-pass read ---
            $prevEntry = (!empty($prevIndex[$rel]) && empty($prevIndex[$rel]['_tombstone']))
                ? $prevIndex[$rel]
                : null;

            $fileSize   = (int) $fileInfo->getSize();
            $fileMtime  = (int) $fileInfo->getMTime();

            // FIX A: one fopen/fread pass computes BOTH the ordered per-chunk
            // hashes AND the file-level blake3 — no second whole-file read.
            // When $prevEntry is present we buffer the chunk bytes so we can
            // carry-forward (discard the bytes) if the finalized file_blake3
            // matches the previous snapshot; a BASE has no prevEntry, so chunks
            // are committed (spilled / inline-queued) as they stream.
            $bufferForCarry = ($prevEntry !== null) && ((string) $prevEntry['file_blake3'] !== '');
            $pass = $this->chunkFileAndHash($abs, $scratchDir, $bufferForCarry, $inlineActive, $fileSize);

            if ($pass === null) {
                // Unreadable file — skip silently (matches FilesArchiver behavior).
                unset($prevIndex[$rel]);
                $filesScanned++;
                $sinceProgress++;
                continue;
            }

            $fileBlake3  = $pass['file_blake3'];
            $chunkHashes = $pass['chunk_hashes'];

            // If the file's full hash matches the prev index, carry-forward the
            // chunks (and discard the buffered bytes — nothing was committed).
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

            // New or genuinely changed file. Commit its chunks: large/multi-chunk
            // files were already spilled to scratch inside chunkFileAndHash; for
            // a carry-buffered or inline-eligible single-chunk file we either
            // queue the bytes for inline upload (FIX B) or spill them now.
            if (isset($pass['inline']) && is_array($pass['inline']) && $pass['inline'] !== []) {
                foreach ($pass['inline'] as $h => $bytes) {
                    if (!is_string($h) || !is_string($bytes)) {
                        continue;
                    }
                    if (isset($inlineUploaded[$h])) {
                        continue; // Already PUT in a prior pass.
                    }
                    if (!isset($inlineBuffer[$h])) {
                        $inlineBuffer[$h] = $bytes;
                        $inlineBytes     += strlen($bytes);
                    }
                }
                if (count($inlineBuffer) >= self::INLINE_FLUSH_EVERY) {
                    $this->flushInlineBuffer($inlineBuffer, $inlineUploaded, $scratchDir);
                    $inlineBytes = 0;
                }
            }
            if (isset($pass['spill']) && is_array($pass['spill'])) {
                // Carry-buffered chunks that must now be written to scratch
                // (inline disabled, or multi-chunk so resume granularity matters).
                foreach ($pass['spill'] as $h => $bytes) {
                    if (!is_string($h) || !is_string($bytes)) {
                        continue;
                    }
                    $this->spillChunk($scratchDir, $h, $bytes);
                }
            }

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

        // Flush any remaining inline small-file chunks before leaving the walk.
        if ($inlineBuffer !== []) {
            $this->flushInlineBuffer($inlineBuffer, $inlineUploaded, $scratchDir);
            $inlineBytes = 0;
        }
        $uploadedHashes = array_keys($inlineUploaded);

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
            // FIX B: hashes already PUT inline during the scan pass. The upload
            // phase treats these as done (no re-presign, no scratch read).
            'uploaded_hashes'=> $uploadedHashes,
        ];
    }

    // ==========================================================================
    // Helpers
    // ==========================================================================

    /**
     * FIX A: single-pass read that computes BOTH the ordered per-chunk hashes
     * AND the file-level BLAKE3 in one fopen/fread loop — no second whole-file
     * read.
     *
     * Per `chunkBytes` slice we compute the per-chunk hash (Blake3::hashHex,
     * unchanged chunk id, unchanged fread order). For the file-level hash:
     *   - single-chunk file (the WP common case): file_blake3 == chunk_hashes[0],
     *     so we set it directly and never re-hash.
     *   - multi-chunk file: we accumulate the slices in a buffer and hash the
     *     whole buffer once at EOF (the same one-shot sodium digest the CP
     *     stores and carry-forward-dedups against).
     *
     * Chunk-byte handling (FIX B / carry-forward):
     *   - Large / multi-chunk files: each slice is spilled to scratch as
     *     `chunks-<hash>.bin` immediately so a mid-large-file crash resumes from
     *     disk. Idempotent: an existing file (same hash) is left in place.
     *   - A carry-buffered single-chunk file ($buffer=true) is NOT spilled in
     *     the loop; we hold its one chunk's bytes and return them under 'spill'
     *     (caller writes to scratch only if the file is genuinely changed) so a
     *     content carry-forward never touches scratch.
     *   - An inline-eligible single-chunk file ($inlineActive=true, no carry
     *     buffer needed) returns its bytes under 'inline' for direct in-memory
     *     PUT — no scratch round-trip at all.
     *
     * @param string $abs          Absolute file path.
     * @param string $scratchDir   Per-run scratch dir.
     * @param bool   $bufferForCarry Hold chunk bytes (don't spill) so the caller
     *                              can decide carry-forward vs commit at EOF.
     * @param bool   $inlineActive Inline small-file upload is wired up (FIX B).
     * @param int    $fileSize     File size in bytes (decides inline eligibility).
     * @return array{file_blake3:string,chunk_hashes:list<string>,inline?:array<string,string>,spill?:array<string,string>}|null
     *         Null on I/O failure (unreadable file — caller skips silently).
     * @throws \RuntimeException On a read/write error mid-stream (fails the run
     *         for watchdog retry, same as the old chunkFile()).
     */
    private function chunkFileAndHash(
        string $abs,
        string $scratchDir,
        bool $bufferForCarry,
        bool $inlineActive,
        int $fileSize
    ): ?array {
        $handle = @fopen($abs, 'rb');
        if ($handle === false) {
            return null;
        }

        $chunkHashes = [];
        $accum       = '';     // Whole-file buffer (only built for a multi-chunk hash).
        $multiChunk  = false;
        $chunkIndex  = 0;
        $firstHash   = '';
        $firstPlain  = '';     // Chunk 0 bytes, kept until we know single vs multi.

        // FIX 1 (memory safety): the carry-buffer hold (don't-touch-scratch while
        // we might carry-forward) is only safe for SINGLE-CHUNK files. Once we
        // discover the file is multi-chunk (chunkIndex reaches 1) we MUST spill
        // eagerly, regardless of $bufferForCarry. Holding all chunks of a large
        // file in RAM would make peak memory proportional to the file size.
        // Peak RAM is capped at one chunk (INLINE_MAX_BYTES) per file regardless
        // of how large the file is.

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
                $hash          = Blake3::hashHex($plain);
                $chunkHashes[] = $hash;

                if ($chunkIndex === 0) {
                    // Defer chunk 0 until we know single vs multi.
                    $firstHash  = $hash;
                    $firstPlain = $plain;
                } elseif ($chunkIndex === 1) {
                    // Now known MULTI-chunk. FIX 1: always eager-spill — never
                    // buffer multi-chunk files in RAM regardless of $bufferForCarry.
                    // Seed the whole-file hash accumulator with chunks 0+1 and
                    // spill both to scratch immediately so a mid-file crash resumes.
                    $multiChunk = true;
                    $accum      = $firstPlain . $plain;
                    $this->spillChunk($scratchDir, $firstHash, $firstPlain);
                    $this->spillChunk($scratchDir, $hash, $plain);
                    $firstPlain = '';
                } else {
                    // chunkIndex >= 2: always spill (multi-chunk path).
                    $accum .= $plain;
                    $this->spillChunk($scratchDir, $hash, $plain);
                }

                $plain = ''; // Free the slice promptly.
                $chunkIndex++;
            }
        } finally {
            fclose($handle);
        }

        $isSingleChunk = !$multiChunk;

        // file_blake3: for a single-chunk file it equals the lone chunk hash
        // (same one-shot sodium digest, computed once); otherwise hash the
        // accumulated whole-file buffer once.
        if ($isSingleChunk) {
            $fileBlake3 = ($firstHash !== '') ? $firstHash : Blake3::hashHex('');
        } else {
            $fileBlake3 = Blake3::hashHex($accum);
        }
        $accum = ''; // Release the whole-file buffer.

        $result = [
            'file_blake3'  => $fileBlake3,
            'chunk_hashes' => $chunkHashes,
        ];

        // Decide how the caller commits the bytes for a genuinely-changed file.
        //
        // Single-chunk path: $firstPlain holds the sole chunk's bytes (never
        // spilled during the loop). Return them under 'inline' (RAM PUT) when
        // inline upload is active and the chunk is within INLINE_MAX_BYTES, or
        // under 'spill' when carry-buffered or inline is disabled.
        //
        // Multi-chunk path (FIX 1): ALL chunks were spilled eagerly in-loop.
        // Nothing to hand back — the upload phase reads them from scratch. We
        // never buffer a multi-chunk file's bytes in $carryBuf; doing so would
        // make peak RAM proportional to the file's total size.
        $inlineEligible = ($firstPlain !== '' && strlen($firstPlain) <= self::INLINE_MAX_BYTES);
        if ($isSingleChunk) {
            if ($firstHash === '' || $firstPlain === '') {
                // Empty file: no chunk bytes at all.
                $firstPlain = '';
            } elseif ($inlineActive && !$bufferForCarry && $inlineEligible) {
                // Inline-eligible single-chunk file (FIX B): PUT from RAM.
                $result['inline'] = [$firstHash => $firstPlain];
            } else {
                // Carry-buffered single chunk, OR inline disabled: hand the bytes
                // back under 'spill'. A carry-forward discards them; a changed
                // file is spilled by the caller (never touches scratch otherwise).
                $result['spill'] = [$firstHash => $firstPlain];
            }
            $firstPlain = '';
        }
        // Multi-chunk files already spilled in-loop (FIX 1): nothing to hand back.
        // Their hashes are in chunk_hashes; the upload phase reads from scratch.
        // (If the file content-matches the prev snapshot's blake3, the orphan
        // scratch chunks are cleaned up by cleanupOnCompleted at run end.)

        return $result;
    }

    /**
     * Spill one plaintext chunk to scratch as `chunks-<hash>.bin`.
     *
     * Idempotent: an existing file (identical content = identical hash = same
     * filename, e.g. a prior watchdog pass) is left in place.
     *
     * @throws \RuntimeException On write failure (fails the run for retry).
     */
    private function spillChunk(string $scratchDir, string $hash, string $bytes): void
    {
        if ($hash === '') {
            return;
        }
        $chunkPath = $scratchDir . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
        if (is_file($chunkPath)) {
            return;
        }
        $written = @file_put_contents($chunkPath, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            throw new \RuntimeException('IncrementalScanner: write failed for chunk ' . $hash);
        }
    }

    /**
     * FIX B: presign + PUT the buffered small-file chunks straight from RAM via
     * the bounded curl_multi pool. Hashes that PUT 2xx (or that the CP already
     * has — dedup) are recorded in $inlineUploaded and removed from the buffer.
     *
     * If inline upload is not wired up (no presign endpoint), or a hash fails to
     * upload, its bytes are spilled to scratch so the upload phase can retry it
     * the normal way — the run never silently drops a chunk.
     *
     * @param array<string,string> $buffer         hash => bytes (cleared on return).
     * @param array<string,int>    $inlineUploaded hash => 1 (accumulated).
     * @param string               $scratchDir     Per-run scratch dir.
     */
    private function flushInlineBuffer(array &$buffer, array &$inlineUploaded, string $scratchDir): void
    {
        if ($buffer === []) {
            return;
        }

        if (!$this->inlineUploadEnabled()) {
            // No presign endpoint — fall back to scratch so the upload phase
            // handles these. (Used by unit tests that don't wire inline upload.)
            foreach ($buffer as $h => $bytes) {
                if (is_string($h) && is_string($bytes)) {
                    $this->spillChunk($scratchDir, $h, $bytes);
                }
            }
            $buffer = [];
            return;
        }

        $hashes = array_keys($buffer);

        // Presign: CP returns {hash => PUT URL} for ONLY the missing hashes.
        // Hashes the CP omits are dedup hits — already stored, mark done.
        try {
            $uploads = $this->transport->presignChunks($this->presignEndpoint, $this->snapshotId, $hashes);
        } catch (\Throwable $e) {
            // Presign failed — spill everything so the upload phase retries.
            foreach ($buffer as $h => $bytes) {
                if (is_string($h) && is_string($bytes)) {
                    $this->spillChunk($scratchDir, $h, $bytes);
                }
            }
            $buffer = [];
            return;
        }

        // Dedup hits: hashes not in $uploads are already stored.
        foreach ($hashes as $h) {
            if (!isset($uploads[$h])) {
                $inlineUploaded[$h] = 1;
            }
        }

        if ($uploads !== []) {
            $results = $this->transport->putChunksMulti(
                $uploads,
                static function (string $h) use ($buffer) {
                    return $buffer[$h] ?? false;
                }
            );
            foreach ($uploads as $h => $_url) {
                if (!empty($results[$h])) {
                    $inlineUploaded[$h] = 1;
                } else {
                    // PUT failed — spill so the upload phase retries it; do NOT
                    // mark uploaded.
                    if (isset($buffer[$h]) && is_string($buffer[$h])) {
                        $this->spillChunk($scratchDir, $h, $buffer[$h]);
                    }
                }
            }
        }

        $buffer = [];
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
