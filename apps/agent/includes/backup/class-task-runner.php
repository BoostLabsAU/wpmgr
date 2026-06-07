<?php
/**
 * TaskRunner: M5.6 / ADR-033 — the state-machine driver that turns a row in
 * `wpmgr_backup_tasks` into a completed backup snapshot.
 *
 * This is the only "active" component in the state-machine pipeline: the
 * REST handler (Phase D) writes the task row, calls fastcgi_finish_request(),
 * then invokes TaskRunner::run() in the same PHP process. The watchdog hook
 * (also Phase D) calls TaskRunner::run() on re-entry. Both entry points are
 * idempotent — the state machine reads `phase` + `sub_state` and resumes from
 * wherever the last invocation left off.
 *
 * Phase transitions (closed set; matches CP backup.allowedProgressPhases):
 *
 *   queued
 *     -> dumping_db          (kind in {db, full})
 *     -> archiving_files     (kind == files)
 *
 *   dumping_db
 *     -> encrypting_uploading (kind == db; skip files)
 *     -> archiving_files      (kind == full)
 *
 *   archiving_files
 *     -> encrypting_uploading
 *
 *   encrypting_uploading
 *     -> submitting_manifest
 *
 *   submitting_manifest
 *     -> completed
 *
 *   completed | failed: terminal (re-entry is a no-op)
 *
 * On any uncaught exception from a phase handler we mark the task `failed`,
 * post one `failed` progress event, and return — TaskRunner::run() NEVER
 * throws. The CP watchdog notices the snapshot stalled in {dumping_db,
 * archiving_files, encrypting_uploading, submitting_manifest} and surfaces
 * the failure to the operator.
 *
 * Watchdog re-entry semantics:
 *   - Re-entries are gated by `resume_count < max_resumes` (default cap 6).
 *     The cap is enforced by the watchdog handler in Phase D before it calls
 *     us; we don't touch resume_count here. But we DO update last_progress_at
 *     on every meaningful boundary so the watchdog can tell live work apart
 *     from a stalled handler.
 *
 * Persistence pattern:
 *   - $wpdb->update() with explicit prepared-arg formats. Single-row updates
 *     keyed by snapshot_id; no transactions required (the task row is the
 *     authority; sub_state is monotonically progress-only).
 *   - DB writes from inside per-chunk progress callbacks are throttled to one
 *     per 5 s (PROGRESS_DB_THROTTLE_SECONDS) to keep the per-chunk overhead
 *     bounded on big archives — the in-memory $lastDbUpdate just tracks the
 *     last write; persisted state is still correct on every phase boundary
 *     because the phase-end save is unconditional.
 *
 * @package WPMgr\Agent\Backup
 */

declare(strict_types=1);

namespace WPMgr\Agent\Backup;

use WPMgr\Agent\Phpbu\ProgressClient;
use WPMgr\Agent\Schema;
use WPMgr\Agent\Signer;
use WPMgr\Agent\Keystore;
use WPMgr\Agent\Support\AgeCrypto;
use WPMgr\Agent\Support\BackupTransport;

/**
 * State-machine driver for a single backup task row.
 *
 * Declared `final` — exactly one TaskRunner per backup invocation, instantiated
 * by BackupCommand (Phase D) and by the watchdog handler. No subclassing
 * intended; the public API is a single `run()` method.
 */
final class TaskRunner
{
    /** Closed set of phase names — matches CP /progress allowedProgressPhases. */
    public const PHASE_QUEUED               = 'queued';
    public const PHASE_DUMPING_DB           = 'dumping_db';
    public const PHASE_ARCHIVING_FILES      = 'archiving_files';
    public const PHASE_ENCRYPTING_UPLOADING = 'encrypting_uploading';
    public const PHASE_SUBMITTING_MANIFEST  = 'submitting_manifest';
    public const PHASE_COMPLETED            = 'completed';
    public const PHASE_FAILED               = 'failed';

    // ADR-048 incremental phases (agent → CP SSE events).
    public const PHASE_FETCH_INDEX         = 'fetching_file_index';
    public const PHASE_SCAN_FILES          = 'scanning_files';
    public const PHASE_UPLOAD_INCREMENTAL  = 'uploading_incremental';
    public const PHASE_INCREMENTAL_FALLBACK = 'incremental_fallback';

    /** Valid snapshot kinds (mirror of the CP backup_contract.go Kind enum). */
    public const KIND_FILES = 'files';
    public const KIND_DB    = 'db';
    public const KIND_FULL  = 'full';

    /** Minimum seconds between in-phase DB writes to last_progress_at. */
    private const PROGRESS_DB_THROTTLE_SECONDS = 5;

    /**
     * Max encoded sub_state size we will write INLINE into the
     * wpmgr_backup_tasks.sub_state column. Above this, the bulky cursor is
     * spilled to a scratch sidecar file and the DB column carries only a small
     * pointer (+ params, which the watchdog needs to rehydrate the runner).
     *
     * Why this exists (the 0-files-base bug): on a real site with thousands of
     * changed files, the incremental scan cursor (scan.changed[] + carry_forward[]
     * + incr_upload.uploaded_hashes[]) serializes to hundreds of KB — often >1 MB.
     * MySQL/MariaDB reject any single statement larger than @@max_allowed_packet
     * (default 1 MiB on many hosts, smaller on shared hosting). `$wpdb->update()`
     * then returns FALSE and the row keeps its PRIOR value — silently. The next
     * watchdog/cron re-entry reloads that stale row, scan.changed[] is gone, and
     * the incremental manifest is submitted with ZERO files_entries (a useless
     * DB-only snapshot). 48 KiB is comfortably below the smallest realistic
     * packet limit, so the inline pointer write always fits.
     */
    private const SUBSTATE_INLINE_MAX_BYTES = 48 * 1024;

    /**
     * Sub_state keys that must stay INLINE in the DB column even when the rest
     * is spilled to the sidecar. `params` is required by the watchdog to
     * rehydrate the runner on re-entry; the `_*` control flags are tiny and
     * drive phase routing. Everything else (scan, incr_upload, encrypt, db,
     * upload, files) can live in the sidecar.
     *
     * @var list<string>
     */
    private const SUBSTATE_INLINE_KEYS = [
        'params',
        '_is_incremental',
        '_auto_base',
        '_prev_index_path',
        '_fallback_reason',
        'incr_manifest_done',
        'last_error',
        'failed_in',
        'reason_code',
    ];

    /** Basename of the per-run sub_state sidecar inside the scratch dir. */
    private const SUBSTATE_SIDECAR_BASENAME = 'task_substate.json';

    /**
     * @var array{snapshot_id:string,kind:string,age_recipient:string,presign_endpoint:string,
     *            manifest_endpoint:string,progress_endpoint:string,chunk_bytes:int,
     *            scratch_dir:string,wp_content_path:string,db:array<string,string>,
     *            is_incremental?:bool,parent_snapshot_id?:string,base_snapshot_id?:string,
     *            generation?:int,file_index_endpoint?:string}
     */
    private array $params;

    /** Unix-seconds of the last DB write to last_progress_at (throttle). */
    private int $lastDbUpdate = 0;

    private ?ProgressClient $progressClient = null;

    /**
     * Per-run incremental backup timing + counter accumulator (FIX 3).
     *
     * Populated by runScanFiles() and runUploadIncremental(). Keys:
     *   scan_s           float  — wall-clock seconds in the scan phase.
     *   upload_s         float  — wall-clock seconds in the upload phase.
     *   files_scanned    int    — total files walked.
     *   files_changed    int    — files with new/changed content.
     *   chunks_created   int    — total unique chunks across changed files.
     *   bytes_read       int    — bytes of changed files (bytes_to_upload).
     *   scratch_chunk_writes int — chunks written to scratch (not inline).
     *   inline_uploads   int    — single-chunk files PUT inline from RAM.
     *   presign_calls    int    — presign round-trips (upload phase).
     *   put_count        int    — chunks actually PUT over the wire.
     *   bytes_uploaded   int    — bytes uploaded to object store.
     *
     * Emitted at end of PHASE_UPLOAD_INCREMENTAL via error_log() + a
     * non-breaking 'timings' field in the final progress payload.
     *
     * @var array<string,int|float>
     */
    private array $incrTimings = [];

    /**
     * @param array{snapshot_id:string,kind:string,age_recipient:string,
     *              presign_endpoint:string,manifest_endpoint:string,
     *              progress_endpoint:string,chunk_bytes:int,scratch_dir:string,
     *              wp_content_path:string,db:array<string,string>} $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;

        // ProgressClient is the existing M5.6 signed `/progress` POSTer. We
        // construct lazily so unit tests that don't touch network don't have
        // to stub Signer/Keystore.
        if (
            class_exists(ProgressClient::class)
            && class_exists(Signer::class)
            && class_exists(Keystore::class)
            && ($this->params['progress_endpoint'] ?? '') !== ''
            && ($this->params['snapshot_id'] ?? '') !== ''
        ) {
            try {
                $this->progressClient = new ProgressClient(
                    (string) $this->params['progress_endpoint'],
                    (string) $this->params['snapshot_id'],
                    new Signer(new Keystore())
                );
            } catch (\Throwable $_) {
                // Progress is best-effort; never let construction failure
                // (e.g. missing keystore in a degraded host) abort a backup.
                $this->progressClient = null;
            }
        }
    }

    /**
     * Drive the task to completion (or to the next checkpoint a watchdog can
     * resume from). NEVER throws — top-level catch translates any escape into
     * a `failed` phase + progress post.
     *
     * @return string Terminal phase reached this invocation. One of
     *                PHASE_COMPLETED, PHASE_FAILED, or — if a future phase
     *                handler yields mid-run on a soft cap — the in-progress
     *                phase. Today every handler runs the phase to completion
     *                or throws, so this returns COMPLETED or FAILED.
     */
    public function run(): string
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $currentPhase = self::PHASE_QUEUED;

        try {
            // ---- Seed or load the task row. ------------------------------
            $task = $this->loadTask();
            if ($task === null) {
                $this->seedTask();
                $task = $this->loadTask();
                if ($task === null) {
                    // Seed failed (unwritable schema / missing $wpdb). We
                    // can't drive a state machine without a row.
                    throw new \RuntimeException('TaskRunner: cannot create task row');
                }
            }
            $currentPhase = (string) $task['phase'];
            $subState     = (array) $task['sub_state'];

            // Terminal? Re-entry is a no-op.
            if ($currentPhase === self::PHASE_COMPLETED || $currentPhase === self::PHASE_FAILED) {
                return $currentPhase;
            }

            // ---- Phase dispatch loop. ------------------------------------
            // Each branch runs ONE phase to completion (or throws), persists
            // the new sub_state, advances `phase`, and falls through to the
            // next iteration. The loop exits when phase==completed.

            while ($currentPhase !== self::PHASE_COMPLETED) {
                switch ($currentPhase) {
                    case self::PHASE_QUEUED:
                        // First entry: announce we're alive and pick the next phase.
                        $this->postProgress('queued', ['kind' => $this->kind(), 'started_at' => time()]);
                        $next = $this->nextAfterQueued();
                        $this->saveTaskState($next, $subState);
                        $currentPhase = $next;
                        break;

                    // ---- ADR-048 incremental phases ----

                    case self::PHASE_FETCH_INDEX:
                        $subState = $this->runFetchIndex($subState);
                        $next = $subState['_auto_base'] ?? false
                            ? self::PHASE_INCREMENTAL_FALLBACK
                            : self::PHASE_SCAN_FILES;
                        $this->saveTaskState($next, $subState);
                        $currentPhase = $next;
                        break;

                    case self::PHASE_SCAN_FILES:
                        $subState = $this->runScanFiles($subState);
                        $this->saveTaskState(self::PHASE_DUMPING_DB, $subState);
                        $currentPhase = self::PHASE_DUMPING_DB;
                        break;

                    case self::PHASE_INCREMENTAL_FALLBACK:
                        // AUTO-BASE: emit progress and convert to a full-backup run.
                        $this->postProgress(self::PHASE_INCREMENTAL_FALLBACK, [
                            'reason' => (string) ($subState['_fallback_reason'] ?? 'no usable base index'),
                        ]);
                        // Clear incremental flag so the subsequent phases use
                        // the full-backup pipeline unchanged.
                        $subState['_is_incremental'] = false;
                        $this->saveTaskState(self::PHASE_DUMPING_DB, $subState);
                        $currentPhase = self::PHASE_DUMPING_DB;
                        break;

                    case self::PHASE_UPLOAD_INCREMENTAL:
                        // runUploadIncremental both uploads chunks AND submits
                        // the IncrementalSubmitManifestRequest in one phase, so
                        // we transition directly to COMPLETED (skipping the full-
                        // backup PHASE_SUBMITTING_MANIFEST which uses a different
                        // manifest shape and would throw on missing encrypt.entries).
                        $subState = $this->runUploadIncremental($subState);
                        $this->saveTaskState(self::PHASE_COMPLETED, $subState);
                        $currentPhase = self::PHASE_COMPLETED;
                        break;

                    // ---- standard full-backup phases ----

                    case self::PHASE_DUMPING_DB:
                        $subState = $this->runDumpingDb($subState);
                        $next     = $this->nextAfterDumpingDb($subState);
                        $this->saveTaskState($next, $subState);
                        $currentPhase = $next;
                        break;

                    case self::PHASE_ARCHIVING_FILES:
                        $subState = $this->runArchivingFiles($subState);
                        $this->saveTaskState(self::PHASE_ENCRYPTING_UPLOADING, $subState);
                        $currentPhase = self::PHASE_ENCRYPTING_UPLOADING;
                        break;

                    case self::PHASE_ENCRYPTING_UPLOADING:
                        $subState = $this->runEncryptingUploading($subState);
                        $this->saveTaskState(self::PHASE_SUBMITTING_MANIFEST, $subState);
                        $currentPhase = self::PHASE_SUBMITTING_MANIFEST;
                        break;

                    case self::PHASE_SUBMITTING_MANIFEST:
                        $this->runSubmittingManifest($subState);
                        $this->saveTaskState(self::PHASE_COMPLETED, $subState);
                        $currentPhase = self::PHASE_COMPLETED;
                        break;

                    default:
                        throw new \RuntimeException('TaskRunner: unknown phase ' . $currentPhase);
                }
            }

            // ---- Completion: cleanup + ack. ------------------------------
            $this->cleanupOnCompleted();
            $this->postProgress(self::PHASE_COMPLETED, ['snapshot_id' => $this->snapshotId()]);

            return self::PHASE_COMPLETED;
        } catch (\Throwable $e) {
            error_log('WPMgr TaskRunner: phase ' . $currentPhase . ' failed: ' . $e->getMessage());

            // Best-effort: persist `failed` + post one progress event. If
            // either of these throws we swallow and still return 'failed' —
            // the watchdog and operator will see a stale row.
            try {
                $this->saveTaskState(self::PHASE_FAILED, [
                    'last_error' => substr($e->getMessage(), 0, 240),
                    'failed_in'  => $currentPhase,
                ]);
            } catch (\Throwable $_) {
                // Swallow.
            }
            try {
                $this->postProgress(self::PHASE_FAILED, [
                    'stage'   => $currentPhase,
                    'message' => substr($e->getMessage(), 0, 240),
                ]);
            } catch (\Throwable $_) {
                // Swallow.
            }

            // Bug 2 fix: also DELETE the failed row so a delayed watchdog
            // cron event can't re-enter and re-emit a stale phantom
            // /presign call. The CP-side audit + the just-posted `failed`
            // progress event have already recorded the failure for ops.
            try {
                global $wpdb;
                if (is_object($wpdb)) {
                    $tasksTable = $this->prefix() . Schema::BACKUP_TASKS_TABLE;
                    /** @phpstan-ignore-next-line */
                    @$wpdb->delete($tasksTable, ['snapshot_id' => $this->snapshotId()], ['%s']);
                }
            } catch (\Throwable $_) {
                // Swallow.
            }

            return self::PHASE_FAILED;
        }
    }

    // ==================================================================
    // Phase handlers
    // ==================================================================

    /**
     * Decide the next phase after `queued`.
     *
     * For incremental runs (is_incremental=true in params): PHASE_FETCH_INDEX.
     * For files-only full snapshots: PHASE_ARCHIVING_FILES (skip DB dump).
     * For all other full runs (db or full kind): PHASE_DUMPING_DB.
     */
    private function nextAfterQueued(): string
    {
        if ($this->isIncremental()) {
            return self::PHASE_FETCH_INDEX;
        }
        return $this->kind() === self::KIND_FILES
            ? self::PHASE_ARCHIVING_FILES
            : self::PHASE_DUMPING_DB;
    }

    /**
     * Decide the next phase after `dumping_db`.
     *
     * For incremental runs that haven't fallen back to AUTO-BASE:
     *   → PHASE_UPLOAD_INCREMENTAL (skip the zip-archiver).
     * For DB-only full snapshots: PHASE_ENCRYPTING_UPLOADING.
     * For full snapshots: PHASE_ARCHIVING_FILES.
     *
     * @param array<string,mixed> $subState Current sub_state (used to detect AUTO-BASE).
     */
    private function nextAfterDumpingDb(array $subState = []): string
    {
        // If we are in an incremental run that has NOT fallen back to AUTO-BASE,
        // skip the files archiver and go straight to per-file chunk upload.
        if ($this->isIncremental() && !empty($subState['_is_incremental'])) {
            return self::PHASE_UPLOAD_INCREMENTAL;
        }
        return $this->kind() === self::KIND_DB
            ? self::PHASE_ENCRYPTING_UPLOADING
            : self::PHASE_ARCHIVING_FILES;
    }

    /**
     * Run the DB-dump phase to completion. Writes `<scratch>/database.sql.gz`
     * and returns the new sub_state with `db.done=true`.
     *
     * @param array<string,mixed> $subState Current sub_state.
     * @return array<string,mixed> Updated sub_state.
     */
    private function runDumpingDb(array $subState): array
    {
        $outPath = $this->scratchDir() . DIRECTORY_SEPARATOR . 'database.sql.gz';
        $resume  = isset($subState['db']) && is_array($subState['db']) ? $subState['db'] : [];

        $this->ensureScratchDir();

        // Persist a fresh last_progress_at before the long-running call so
        // the watchdog sees activity even if we never callback.
        $this->saveTaskState(self::PHASE_DUMPING_DB, $subState);

        $dumper = new DbDumper($this->dbCreds());
        $result = $dumper->dump($outPath, $resume, function (string $phase, array $detail): void {
            $this->onPhaseProgress($phase, $detail);
        });

        $subState['db'] = $result;
        return $subState;
    }

    /**
     * Run the files-archive phase to completion. Writes
     * `<scratch>/wp-content.partNNN.zip` files and returns sub_state with
     * `files.done=true`.
     *
     * @param array<string,mixed> $subState Current sub_state.
     * @return array<string,mixed> Updated sub_state.
     */
    private function runArchivingFiles(array $subState): array
    {
        $resume = isset($subState['files']) && is_array($subState['files']) ? $subState['files'] : [];

        $this->ensureScratchDir();
        $this->saveTaskState(self::PHASE_ARCHIVING_FILES, $subState);

        $archiver = new FilesArchiver($this->wpContentPath());
        $result   = $archiver->archive($this->scratchDir(), $resume, function (string $phase, array $detail): void {
            $this->onPhaseProgress($phase, $detail);
        });

        $subState['files'] = $result;
        return $subState;
    }

    /**
     * Run pass 1 (encrypt) + pass 2 (upload) of the chunk pipeline. Assembles
     * the artifact list from prior sub_state, then drives EncryptAndUpload.
     *
     * @param array<string,mixed> $subState Current sub_state.
     * @return array<string,mixed> Updated sub_state (`encrypt.entries` for pass 3).
     */
    private function runEncryptingUploading(array $subState): array
    {
        $artifacts = $this->assembleArtifacts($subState);
        if ($artifacts === []) {
            throw new \RuntimeException('TaskRunner: no artifacts to encrypt');
        }

        $pipeline = new EncryptAndUpload(
            new AgeCrypto(),
            new BackupTransport(new Signer(new Keystore())),
            $this->snapshotId(),
            (string) $this->params['age_recipient'],
            (string) $this->params['presign_endpoint'],
            (string) $this->params['manifest_endpoint'],
            (int) ($this->params['chunk_bytes'] ?? EncryptAndUpload::DEFAULT_CHUNK_BYTES)
        );

        // ---- Pass 1: encrypt. ----
        $encResume = isset($subState['encrypt']) && is_array($subState['encrypt']) ? $subState['encrypt'] : [];

        $this->saveTaskState(self::PHASE_ENCRYPTING_UPLOADING, $subState);

        $encCursor = $pipeline->encryptChunks(
            $this->scratchDir(),
            $artifacts,
            $encResume,
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );
        $subState['encrypt'] = $encCursor;
        // Checkpoint between passes so an upload-pass crash doesn't redo
        // the (CPU-expensive) encrypt pass.
        $this->saveTaskState(self::PHASE_ENCRYPTING_UPLOADING, $subState);

        // ---- Pass 2: upload. ----
        $uploadResume = isset($subState['upload']) && is_array($subState['upload']) ? $subState['upload'] : [];
        // Pass scratch_dir via the cursor so EncryptAndUpload knows where
        // to find the chunks-<hash>.age files.
        $uploadResume['scratch_dir'] = $this->scratchDir();

        $upCursor = $pipeline->uploadChunks(
            $encCursor,
            $uploadResume,
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );
        $subState['upload'] = $upCursor;
        return $subState;
    }

    /**
     * Run pass 3 (submit manifest). Reads the prepared entries list out of
     * `sub_state.encrypt.entries`; the runner is intentionally pass-through
     * here — EncryptAndUpload owns the entries shape end-to-end.
     *
     * @param array<string,mixed> $subState Current sub_state.
     */
    private function runSubmittingManifest(array $subState): void
    {
        $encrypt = (isset($subState['encrypt']) && is_array($subState['encrypt'])) ? $subState['encrypt'] : [];
        $entries = (isset($encrypt['entries']) && is_array($encrypt['entries'])) ? $encrypt['entries'] : [];
        if ($entries === []) {
            throw new \RuntimeException('TaskRunner: sub_state.encrypt.entries missing for manifest submit');
        }

        $pipeline = new EncryptAndUpload(
            new AgeCrypto(),
            new BackupTransport(new Signer(new Keystore())),
            $this->snapshotId(),
            (string) $this->params['age_recipient'],
            (string) $this->params['presign_endpoint'],
            (string) $this->params['manifest_endpoint'],
            (int) ($this->params['chunk_bytes'] ?? EncryptAndUpload::DEFAULT_CHUNK_BYTES)
        );

        $this->saveTaskState(self::PHASE_SUBMITTING_MANIFEST, $subState);

        $pipeline->submitManifest($entries, function (string $phase, array $detail): void {
            $this->onPhaseProgress($phase, $detail);
        });
    }

    // ==================================================================
    // ADR-048 Incremental phase handlers
    // ==================================================================

    /**
     * PHASE_FETCH_INDEX — stream the previous NDJSON file-index from the CP
     * to a scratch file. On any failure (non-200, I/O error, empty endpoint)
     * set _auto_base=true so the caller falls back to the full-backup pipeline.
     *
     * @param array<string,mixed> $subState
     * @return array<string,mixed> Updated sub_state.
     */
    private function runFetchIndex(array $subState): array
    {
        $this->ensureScratchDir();
        $this->saveTaskState(self::PHASE_FETCH_INDEX, $subState);

        $endpoint = $this->fileIndexEndpoint();

        if ($endpoint === '') {
            // ADR-048 BASE bootstrap: an empty file_index_endpoint is the CP's
            // signal that this is a gen-0 base increment with no parent index to
            // diff against. Stay incremental with an EMPTY previous index so the
            // scan phase classifies every on-disk file as new and the upload
            // phase writes a full backup_file_index (instead of falling back to a
            // full zip, which would write manifest entries and no file index).
            // buildPrevIndexMap() returns an empty map for a missing path, so an
            // empty _prev_index_path yields a clean base baseline.
            $subState['_auto_base']        = false;
            $subState['_is_incremental']   = true;
            $subState['_prev_index_path']  = '';
            return $subState;
        }

        $scanner = $this->makeScanner();

        $indexPath = $scanner->fetchPreviousIndex(
            $endpoint,
            $this->scratchDir(),
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );

        if ($indexPath === null) {
            $subState['_auto_base']       = true;
            $subState['_is_incremental']  = false;
            $subState['_fallback_reason'] = 'file_index fetch failed or returned 204';
            return $subState;
        }

        $subState['_auto_base']          = false;
        $subState['_is_incremental']     = true;
        $subState['_prev_index_path']    = $indexPath;
        return $subState;
    }

    /**
     * PHASE_SCAN_FILES — walk WP_CONTENT_DIR, classify files against the
     * previous index, write plaintext chunks for changed/new files.
     *
     * @param array<string,mixed> $subState
     * @return array<string,mixed> Updated sub_state with scan result.
     */
    private function runScanFiles(array $subState): array
    {
        $this->ensureScratchDir();
        $this->saveTaskState(self::PHASE_SCAN_FILES, $subState);

        $indexPath = (string) ($subState['_prev_index_path'] ?? '');
        $scanner   = $this->makeScanner();

        // FIX B: enable inline small-file upload during the scan pass. Single-
        // chunk files are presigned + PUT straight from the read buffer (no
        // scratch round-trip); the hashes uploaded this way come back in the
        // scan result under 'uploaded_hashes' and the upload phase skips them.
        $scanner->enableInlineUpload(
            (string) $this->params['presign_endpoint'],
            $this->snapshotId()
        );

        // Build prev-index map line-by-line.
        $prevIndex = $scanner->buildPrevIndexMap($indexPath);
        if ($prevIndex === null) {
            // Soft cap triggered — AUTO-BASE.
            $this->postProgress(self::PHASE_INCREMENTAL_FALLBACK, [
                'reason' => 'prev index exceeds 2,000,000 lines (soft cap)',
            ]);
            $subState['_auto_base']       = true;
            $subState['_is_incremental']  = false;
            $subState['_fallback_reason'] = 'prev index soft cap exceeded';
            // Fast-path to dumping_db as a full base.
            return $subState;
        }

        $scanResume = isset($subState['scan']) && is_array($subState['scan']) ? $subState['scan'] : [];

        // FIX 3 (instrumentation): measure wall-clock time for the scan pass.
        $scanStart  = microtime(true);

        $scanResult = $scanner->scanFiles(
            $prevIndex,
            $this->scratchDir(),
            $scanResume,
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );

        $scanElapsed = microtime(true) - $scanStart;

        // Accumulate scan-phase counters in $incrTimings (FIX 3).
        $inlineUploaded = isset($scanResult['uploaded_hashes']) && is_array($scanResult['uploaded_hashes'])
            ? count($scanResult['uploaded_hashes'])
            : 0;
        $this->incrTimings['scan_s']           = ($this->incrTimings['scan_s'] ?? 0.0) + $scanElapsed;
        $this->incrTimings['files_scanned']    = (int) ($scanResult['files_scanned'] ?? 0);
        $this->incrTimings['files_changed']    = (int) ($scanResult['files_changed'] ?? 0);
        $this->incrTimings['bytes_read']       = (int) ($scanResult['bytes_to_upload'] ?? 0);
        $this->incrTimings['inline_uploads']   = $inlineUploaded;

        $subState['scan']            = $scanResult;
        $subState['_is_incremental'] = true;  // Confirm incremental path is live.
        return $subState;
    }

    /**
     * PHASE_UPLOAD_INCREMENTAL — upload per-file plaintext chunks for
     * changed/new files, then submit the IncrementalSubmitManifestRequest.
     *
     * DB dump entries are assembled from sub_state.db (same as the full-backup
     * pipeline) and included in the manifest submission.
     *
     * @param array<string,mixed> $subState
     * @return array<string,mixed> Updated sub_state.
     */
    private function runUploadIncremental(array $subState): array
    {
        $this->ensureScratchDir();
        $this->saveTaskState(self::PHASE_UPLOAD_INCREMENTAL, $subState);

        $scanResult  = isset($subState['scan']) && is_array($subState['scan']) ? $subState['scan'] : [];
        $changedFiles = isset($scanResult['changed']) && is_array($scanResult['changed']) ? array_values($scanResult['changed']) : [];
        $tombstones   = isset($scanResult['tombstones']) && is_array($scanResult['tombstones']) ? array_values($scanResult['tombstones']) : [];
        $filesScanned = (int) ($scanResult['files_scanned'] ?? 0);
        $filesChanged = (int) ($scanResult['files_changed'] ?? 0);
        $filesDeleted = (int) ($scanResult['files_deleted'] ?? 0);

        $pipeline = new IncrementalEncryptAndUpload(
            new \WPMgr\Agent\Support\BackupTransport(new \WPMgr\Agent\Signer(new \WPMgr\Agent\Keystore())),
            $this->snapshotId(),
            (string) $this->params['age_recipient'],
            (string) $this->params['presign_endpoint'],
            (string) $this->params['manifest_endpoint'],
            (int) ($this->params['chunk_bytes'] ?? 4 * 1024 * 1024)
        );

        // Upload changed-file chunks. Seed the resume cursor with the hashes the
        // scanner already PUT inline (FIX B) so the upload phase neither re-PUTs
        // them nor expects them on scratch.
        $uploadResume = isset($subState['incr_upload']) && is_array($subState['incr_upload']) ? $subState['incr_upload'] : [];
        $scanUploaded = isset($scanResult['uploaded_hashes']) && is_array($scanResult['uploaded_hashes'])
            ? array_values(array_filter($scanResult['uploaded_hashes'], 'is_string'))
            : [];
        if ($scanUploaded !== []) {
            $existing = isset($uploadResume['uploaded_hashes']) && is_array($uploadResume['uploaded_hashes'])
                ? $uploadResume['uploaded_hashes']
                : [];
            $uploadResume['uploaded_hashes'] = array_values(array_unique(array_merge($existing, $scanUploaded)));
        }

        // FIX 3 (instrumentation): measure wall-clock time for the upload pass.
        $uploadStart = microtime(true);

        $uploadCursor = $pipeline->uploadChunks(
            $changedFiles,
            $this->scratchDir(),
            $uploadResume,
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );

        $uploadElapsed = microtime(true) - $uploadStart;

        $subState['incr_upload'] = $uploadCursor;
        $this->saveTaskState(self::PHASE_UPLOAD_INCREMENTAL, $subState);

        $bytesUploaded = (int) ($uploadCursor['bytes_uploaded'] ?? 0);

        // Build DB entries from the DB dump (same as assembleArtifacts but only
        // the DB component).
        $dbEntries = $this->assembleIncrementalDbEntries($subState);

        // Submit the incremental manifest.
        $pipeline->submitIncrementalManifest(
            $changedFiles,
            $tombstones,
            $dbEntries,
            $filesScanned,
            $filesChanged,
            $filesDeleted,
            $bytesUploaded,
            function (string $phase, array $detail): void {
                $this->onPhaseProgress($phase, $detail);
            }
        );

        // FIX 3 (instrumentation): accumulate upload-phase counters and emit the
        // end-of-run timing summary two ways — (1) a single error_log line for
        // server-side grep, and (2) a 'timings' object in the final progress
        // payload (non-breaking optional field; the Go CP ignores unknown keys).
        $chunksTotal    = (int) ($uploadCursor['chunks_total'] ?? 0);
        $putCount       = (int) ($uploadCursor['chunks_put'] ?? 0);
        $inlineUploads  = (int) ($this->incrTimings['inline_uploads'] ?? 0);
        // Chunks written to scratch = total chunks for changed files minus those
        // PUT inline during the scan pass (inline path never touches scratch).
        $scratchWrites  = max(0, $chunksTotal - $inlineUploads);

        $this->incrTimings['upload_s']           = ($this->incrTimings['upload_s'] ?? 0.0) + $uploadElapsed;
        $this->incrTimings['chunks_created']     = $chunksTotal;
        $this->incrTimings['scratch_chunk_writes'] = $scratchWrites;
        $this->incrTimings['presign_calls']      = 1; // One presign round-trip in the upload phase.
        $this->incrTimings['put_count']          = $putCount;
        $this->incrTimings['bytes_uploaded']     = $bytesUploaded;

        $t = $this->incrTimings;
        error_log(sprintf(
            '[wpmgr-agent] incr timings: scan=%.2fs upload=%.2fs files=%d changed=%d chunks=%d scratch_writes=%d inline=%d puts=%d bytes_up=%d',
            (float) ($t['scan_s'] ?? 0.0),
            (float) ($t['upload_s'] ?? 0.0),
            (int)   ($t['files_scanned'] ?? 0),
            (int)   ($t['files_changed'] ?? 0),
            (int)   ($t['chunks_created'] ?? 0),
            (int)   ($t['scratch_chunk_writes'] ?? 0),
            (int)   ($t['inline_uploads'] ?? 0),
            (int)   ($t['put_count'] ?? 0),
            (int)   ($t['bytes_uploaded'] ?? 0)
        ));

        // Emit the timings object in a progress payload so it lands in the CP
        // event log. 'timings' is an optional unknown field; the Go side ignores
        // it without a schema change.
        $this->postProgress('uploading_incremental', [
            'done'    => true,
            'timings' => [
                'scan_s'               => round((float) ($t['scan_s'] ?? 0.0), 3),
                'upload_s'             => round((float) ($t['upload_s'] ?? 0.0), 3),
                'files_scanned'        => (int) ($t['files_scanned'] ?? 0),
                'files_changed'        => (int) ($t['files_changed'] ?? 0),
                'chunks_created'       => (int) ($t['chunks_created'] ?? 0),
                'bytes_read'           => (int) ($t['bytes_read'] ?? 0),
                'scratch_chunk_writes' => (int) ($t['scratch_chunk_writes'] ?? 0),
                'inline_uploads'       => (int) ($t['inline_uploads'] ?? 0),
                'presign_calls'        => (int) ($t['presign_calls'] ?? 0),
                'put_count'            => (int) ($t['put_count'] ?? 0),
                'bytes_uploaded'       => (int) ($t['bytes_uploaded'] ?? 0),
            ],
        ]);

        $subState['incr_manifest_done'] = true;
        return $subState;
    }

    /**
     * Build the DB manifest entries for an incremental manifest submission.
     * These use the existing ManifestEntry shape (entry_kind='db') and are
     * assembled from sub_state.db + the encrypted DB chunk files on disk.
     *
     * In the incremental pipeline, the DB dump is still fully encrypted and
     * uploaded via the standard EncryptAndUpload pass (in PHASE_DUMPING_DB +
     * PHASE_UPLOAD_INCREMENTAL). We re-run the encrypt pass over the DB
     * artifact here to get the chunk list.
     *
     * @param array<string,mixed> $subState
     * @return list<array<string,mixed>>
     */
    private function assembleIncrementalDbEntries(array $subState): array
    {
        // If a prior pass already prepared db_entries in subState, reuse them.
        if (!empty($subState['incr_db_entries']) && is_array($subState['incr_db_entries'])) {
            return array_values($subState['incr_db_entries']);
        }

        $db      = (isset($subState['db']) && is_array($subState['db'])) ? $subState['db'] : [];
        $dbPath  = isset($db['output_path']) && is_string($db['output_path']) ? $db['output_path'] : '';

        if ($dbPath === '' || !is_file($dbPath)) {
            return [];
        }

        // Chunk the DB dump into plaintext BLAKE3-addressed entries.
        $chunkBytes = (int) ($this->params['chunk_bytes'] ?? 4 * 1024 * 1024);
        $chunkList  = [];
        $handle     = @fopen($dbPath, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            while (!feof($handle)) {
                $plain = fread($handle, $chunkBytes);
                if ($plain === false || $plain === '') {
                    break;
                }
                $hash      = \WPMgr\Agent\Support\Blake3::hashHex($plain);
                $size      = strlen($plain);
                $chunkPath = $this->scratchDir() . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                if (!is_file($chunkPath)) {
                    @file_put_contents($chunkPath, $plain, LOCK_EX);
                }
                $plain       = '';
                $chunkList[] = ['blake3' => $hash, 'size' => $size];
            }
        } finally {
            fclose($handle);
        }

        // Upload DB chunks via presign dedup.
        $allHashes = array_column($chunkList, 'blake3');
        if (!empty($allHashes)) {
            try {
                $transport = new \WPMgr\Agent\Support\BackupTransport(
                    new \WPMgr\Agent\Signer(new \WPMgr\Agent\Keystore())
                );
                $uploads = $transport->presignChunks(
                    (string) $this->params['presign_endpoint'],
                    $this->snapshotId(),
                    $allHashes
                );

                // FIX D: PUT the missing DB chunks concurrently (serial fallback
                // on hosts without ext-curl). Non-fatal: a failed PUT just leaves
                // the scratch file in place; the manifest still carries the chunk
                // list, preserving the prior DB-restore behavior.
                $toPut = [];
                foreach ($uploads as $hash => $url) {
                    if (!is_string($hash) || !is_string($url) || $url === '') {
                        continue;
                    }
                    $chunkPath = $this->scratchDir() . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                    if (is_file($chunkPath)) {
                        $toPut[$hash] = $url;
                    }
                }
                if ($toPut !== []) {
                    $scratch = $this->scratchDir();
                    $results = $transport->putChunksMulti(
                        $toPut,
                        static function (string $hash) use ($scratch) {
                            $path  = $scratch . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                            $bytes = @file_get_contents($path);
                            return $bytes === false ? false : $bytes;
                        }
                    );
                    foreach ($toPut as $hash => $_url) {
                        if (!empty($results[$hash])) {
                            @unlink($scratch . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin');
                        }
                    }
                }
                // Unlink dedup hits.
                foreach ($allHashes as $hash) {
                    if (!isset($uploads[$hash])) {
                        $chunkPath = $this->scratchDir() . DIRECTORY_SEPARATOR . 'chunks-' . $hash . '.bin';
                        if (is_file($chunkPath)) {
                            @unlink($chunkPath);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal — the manifest submit will still carry the chunk list.
                error_log('WPMgr TaskRunner: incremental DB chunk upload error: ' . $e->getMessage());
            }
        }

        $dbSize = array_sum(array_column($chunkList, 'size'));

        return [[
            'path'       => 'database.sql.gz',
            'entry_kind' => 'db',
            'table_name' => '',
            'mode'       => 0,
            'size'       => $dbSize,
            'chunks'     => $chunkList,
        ]];
    }

    // ==================================================================
    // Incremental helpers
    // ==================================================================

    /** Whether this run is incremental (set in params by BackupCommand). */
    private function isIncremental(): bool
    {
        return !empty($this->params['is_incremental']);
    }

    /** file_index_endpoint from params (empty string if not incremental). */
    private function fileIndexEndpoint(): string
    {
        return isset($this->params['file_index_endpoint']) && is_string($this->params['file_index_endpoint'])
            ? $this->params['file_index_endpoint']
            : '';
    }

    /** Build an IncrementalScanner using current params. */
    private function makeScanner(): IncrementalScanner
    {
        return new IncrementalScanner(
            $this->wpContentPath(),
            (int) ($this->params['chunk_bytes'] ?? 4 * 1024 * 1024),
            new \WPMgr\Agent\Support\BackupTransport(new \WPMgr\Agent\Signer(new \WPMgr\Agent\Keystore()))
        );
    }

    /**
     * Build the ordered artifact list (DB dump + files parts) from completed
     * sub_state. Order matters: the manifest entries reflect this order, and
     * the CP-side reconstructor expects DB before files.
     *
     * @param array<string,mixed> $subState Current sub_state.
     * @return list<array{path:string,logical:string}>
     */
    private function assembleArtifacts(array $subState): array
    {
        $artifacts = [];

        // DB artifact (only present for kind in {db, full}).
        $db = (isset($subState['db']) && is_array($subState['db'])) ? $subState['db'] : [];
        if (!empty($db['done']) && !empty($db['output_path']) && is_string($db['output_path'])) {
            $artifacts[] = [
                'path'    => (string) $db['output_path'],
                'logical' => 'database.sql.gz',
            ];
        }

        // Files artifacts (only present for kind in {files, full}). FilesArchiver
        // returns 'parts' as a list of basename strings — we map each to the
        // absolute scratch path.
        $files = (isset($subState['files']) && is_array($subState['files'])) ? $subState['files'] : [];
        if (!empty($files['done']) && isset($files['parts']) && is_array($files['parts'])) {
            foreach ($files['parts'] as $partName) {
                if (!is_string($partName) || $partName === '') {
                    continue;
                }
                $abs = $this->scratchDir() . DIRECTORY_SEPARATOR . $partName;
                $artifacts[] = [
                    'path'    => $abs,
                    'logical' => $partName,
                ];
            }
        }

        return $artifacts;
    }

    // ==================================================================
    // Persistence + progress
    // ==================================================================

    /**
     * Load the task row by snapshot_id. Returns null if the row doesn't exist.
     *
     * @return array{phase:string,kind:string,sub_state:array<string,mixed>,resume_count:int,max_resumes:int}|null
     */
    private function loadTask(): ?array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return null;
        }
        $table = $this->tableName();
        if ($table === '') {
            return null;
        }

        $sql = "SELECT phase, kind, sub_state, resume_count, max_resumes FROM {$table} WHERE snapshot_id = %s LIMIT 1";
        /** @phpstan-ignore-next-line — $wpdb is a runtime interface. */
        $prepared = $wpdb->prepare($sql, $this->snapshotId());
        /** @phpstan-ignore-next-line */
        $row      = $wpdb->get_row($prepared, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $sub = [];
        if (isset($row['sub_state']) && is_string($row['sub_state']) && $row['sub_state'] !== '') {
            $decoded = json_decode($row['sub_state'], true);
            if (is_array($decoded)) {
                // If the column holds a sidecar pointer (large cursor spilled to
                // scratch to stay under @@max_allowed_packet), rehydrate the full
                // sub_state from the sidecar file.
                $sub = $this->rehydrateSubState($decoded);
            }
        }

        return [
            'phase'        => (string) ($row['phase'] ?? self::PHASE_QUEUED),
            'kind'         => (string) ($row['kind'] ?? $this->kind()),
            'sub_state'    => $sub,
            'resume_count' => (int) ($row['resume_count'] ?? 0),
            'max_resumes'  => (int) ($row['max_resumes'] ?? 6),
        ];
    }

    /**
     * Insert a fresh task row in `queued` state. Caller-safe under retry: if
     * the row already exists (snapshot_id PK) the INSERT is a no-op.
     */
    private function seedTask(): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }
        $table = $this->tableName();
        if ($table === '') {
            return;
        }
        $now = time();

        // Use REPLACE/INSERT IGNORE semantics via $wpdb->query() with a raw
        // INSERT IGNORE — $wpdb->insert() doesn't surface the IGNORE qualifier
        // and would throw on duplicate-key collisions.
        $sql = "INSERT IGNORE INTO {$table}
                (snapshot_id, kind, phase, sub_state, started_at, last_progress_at, resume_count, max_resumes)
                VALUES (%s, %s, %s, %s, %d, %d, %d, %d)";
        /** @phpstan-ignore-next-line */
        $prepared = $wpdb->prepare(
            $sql,
            $this->snapshotId(),
            $this->kind(),
            self::PHASE_QUEUED,
            '{}',
            $now,
            $now,
            0,
            6
        );
        /** @phpstan-ignore-next-line */
        $wpdb->query($prepared);
    }

    /**
     * Persist phase + sub_state + bump last_progress_at. Called on every
     * phase boundary AND immediately before each long-running subprocess
     * (DbDumper / FilesArchiver / EncryptAndUpload) so the watchdog has a
     * recent timestamp even mid-phase.
     *
     * @param string              $phase    New phase.
     * @param array<string,mixed> $subState New sub_state to persist.
     */
    private function saveTaskState(string $phase, array $subState): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }
        $table = $this->tableName();
        if ($table === '') {
            return;
        }

        $now            = time();
        // JSON_INVALID_UTF8_SUBSTITUTE: a real WP site can hold file paths with
        // invalid UTF-8 bytes (e.g. latin1 filenames). Plain json_encode() returns
        // false on those, and the old `?: '{}'` fallback silently WIPED the entire
        // sub_state — including the just-computed scan.changed[] cursor — so a
        // watchdog re-entry would reload '{}' and submit an incremental manifest
        // with ZERO files_entries (a useless DB-only snapshot). Substituting the
        // bad bytes keeps the cursor intact across re-entries.
        $encoded        = json_encode($subState, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false || $encoded === '') {
            // Last-resort: never persist '{}' OVER a non-empty sub_state (that
            // would drop the resume cursor). Skip the write so the watchdog re-enters
            // from the last good state instead of a wiped one.
            error_log('WPMgr TaskRunner: sub_state json_encode failed for phase ' . $phase . ' — skipping state write to preserve the prior cursor');
            return;
        }

        // SIDECAR SPILL (0-files-base fix): a real incremental base over thousands
        // of changed files serializes the scan cursor to hundreds of KB — past
        // @@max_allowed_packet on many hosts. An over-packet UPDATE fails silently
        // (returns false) and the row keeps its PRIOR value, so a watchdog re-entry
        // reloads a cursor-less row and submits a 0-files manifest. To keep the DB
        // write always within the packet limit, when the encoded state is large we
        // write the FULL cursor to a scratch sidecar file and store only a tiny
        // pointer (+ the small inline keys the watchdog needs) in the column.
        $columnValue = $encoded;
        if (strlen($encoded) > self::SUBSTATE_INLINE_MAX_BYTES) {
            $pointer = $this->spillSubStateToSidecar($subState, $encoded);
            if ($pointer !== null) {
                $columnValue = $pointer;
            }
            // If the sidecar write failed, $columnValue stays = $encoded and we
            // fall through to the update-return check below, which now FAILS
            // LOUDLY instead of silently dropping the cursor.
        }

        $this->lastDbUpdate = $now;

        /** @phpstan-ignore-next-line */
        $result = $wpdb->update(
            $table,
            [
                'phase'            => $phase,
                'sub_state'        => $columnValue,
                'last_progress_at' => $now,
            ],
            ['snapshot_id' => $this->snapshotId()],
            ['%s', '%s', '%d'],
            ['%s']
        );

        // HONOR THE RETURN VALUE. $wpdb->update() returns false on a DB error —
        // most importantly an over-@@max_allowed_packet statement, which leaves
        // the row holding its PRIOR (cursor-less) value. Silently ignoring this
        // was the root cause of the 0-files base: the in-memory run continued,
        // but the persisted row a watchdog later reloaded had no scan.changed[].
        // After the sidecar spill the column value is tiny, so a false here is a
        // genuine DB fault — surface it so the run is retried rather than
        // completing with a stale/empty cursor.
        if ($result === false) {
            throw new \RuntimeException(
                'TaskRunner: sub_state persist failed (wpdb->update returned false) for phase '
                . $phase . ' — refusing to continue with an unpersisted cursor'
            );
        }
    }

    /**
     * Spill the full sub_state to a per-run scratch sidecar file and return a
     * small DB-column pointer that references it. The pointer keeps the inline
     * keys (params + control flags) the watchdog needs to rehydrate WITHOUT
     * reading the sidecar, plus `_substate_sidecar` (path) and a length guard.
     *
     * @param array<string,mixed> $subState Full sub_state.
     * @param string              $encoded  Already-encoded full sub_state (reused as the file body).
     * @return string|null JSON pointer to store in the column, or null if the
     *                     sidecar could not be written (caller keeps the inline
     *                     encoding and lets the update-return guard catch a
     *                     too-large write).
     */
    private function spillSubStateToSidecar(array $subState, string $encoded): ?string
    {
        $path = $this->subStateSidecarPath();
        if ($path === '') {
            return null;
        }
        // Atomic-ish write: write to a temp file then rename so a crash mid-write
        // never leaves a truncated sidecar that would reload as a lost cursor.
        $tmp = $path . '.' . getmypid() . '.tmp';
        $written = @file_put_contents($tmp, $encoded, LOCK_EX);
        if ($written === false || $written !== strlen($encoded)) {
            @unlink($tmp);
            return null;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }

        $pointer = ['_substate_sidecar' => $path, '_sidecar_len' => strlen($encoded)];
        foreach (self::SUBSTATE_INLINE_KEYS as $k) {
            if (array_key_exists($k, $subState)) {
                $pointer[$k] = $subState[$k];
            }
        }
        $encodedPointer = json_encode($pointer, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encodedPointer === false || $encodedPointer === '') {
            return null;
        }
        return $encodedPointer;
    }

    /**
     * Rehydrate a sub_state that may be a sidecar pointer. If the decoded column
     * value carries `_substate_sidecar`, read the full cursor from that scratch
     * file; otherwise return the decoded value unchanged.
     *
     * @param array<string,mixed> $decoded Decoded column value.
     * @return array<string,mixed> Full sub_state.
     */
    private function rehydrateSubState(array $decoded): array
    {
        if (empty($decoded['_substate_sidecar']) || !is_string($decoded['_substate_sidecar'])) {
            return $decoded;
        }
        $path = $decoded['_substate_sidecar'];
        if (!is_file($path)) {
            // Sidecar missing (scratch wiped). Fall back to the inline pointer
            // keys — the watchdog can still rehydrate params and re-derive the
            // scan from source on the next pass rather than submitting 0 files.
            error_log('WPMgr TaskRunner: sub_state sidecar missing at ' . $path . ' — falling back to inline keys');
            unset($decoded['_substate_sidecar'], $decoded['_sidecar_len']);
            return $decoded;
        }
        $body = @file_get_contents($path);
        if ($body === false || $body === '') {
            unset($decoded['_substate_sidecar'], $decoded['_sidecar_len']);
            return $decoded;
        }
        $full = json_decode($body, true);
        if (!is_array($full)) {
            unset($decoded['_substate_sidecar'], $decoded['_sidecar_len']);
            return $decoded;
        }
        return $full;
    }

    /** Absolute path of the per-run sub_state sidecar, or '' if scratch unknown. */
    private function subStateSidecarPath(): string
    {
        $dir = $this->scratchDir();
        if ($dir === '') {
            return '';
        }
        return $dir . DIRECTORY_SEPARATOR . self::SUBSTATE_SIDECAR_BASENAME;
    }

    /**
     * Touch last_progress_at without rewriting sub_state. Used inside the
     * per-chunk progress callbacks for cheap watchdog liveness signaling.
     * Throttled to once per PROGRESS_DB_THROTTLE_SECONDS so a chatty callback
     * doesn't pound the DB.
     */
    private function touchProgressTimestamp(): void
    {
        $now = time();
        if ($now - $this->lastDbUpdate < self::PROGRESS_DB_THROTTLE_SECONDS) {
            return;
        }
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }
        $table = $this->tableName();
        if ($table === '') {
            return;
        }
        $this->lastDbUpdate = $now;

        /** @phpstan-ignore-next-line */
        $wpdb->update(
            $table,
            ['last_progress_at' => $now],
            ['snapshot_id' => $this->snapshotId()],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Per-chunk progress callback. Touches last_progress_at (throttled) and
     * fire-and-forgets a `/progress` POST to the CP.
     *
     * @param string              $phase  Phase label.
     * @param array<string,mixed> $detail Phase telemetry.
     */
    private function onPhaseProgress(string $phase, array $detail): void
    {
        $this->touchProgressTimestamp();
        $this->postProgress($phase, $detail);
    }

    /**
     * Fire-and-forget signed POST to /progress. Failures are swallowed.
     *
     * @param string              $phase  Phase label (must be in the closed set).
     * @param array<string,mixed> $detail Phase telemetry.
     */
    private function postProgress(string $phase, array $detail): void
    {
        if ($this->progressClient === null) {
            return;
        }
        try {
            $this->progressClient->post($phase, $detail);
        } catch (\Throwable $_) {
            // Swallow — progress is observability, not correctness.
        }
    }

    // ==================================================================
    // Cleanup
    // ==================================================================

    /**
     * Best-effort cleanup of the scratch dir + dedup row. Called on COMPLETED.
     * Failures are swallowed: a leaked scratch file is a disk-space concern,
     * not a correctness concern, and a swept WP cron will eventually nuke the
     * per-run dir.
     */
    private function cleanupOnCompleted(): void
    {
        $scratch = $this->scratchDir();

        // 1. Chunk files (most should already be unlinked by uploadChunks
        //    after PUT success or dedup hit; defensive sweep).
        $chunks = @glob($scratch . DIRECTORY_SEPARATOR . 'chunks-*.age');
        if (is_array($chunks)) {
            foreach ($chunks as $f) {
                @unlink($f);
            }
        }
        // ADR-048: also clean up plaintext incremental chunks (.bin).
        $plainChunks = @glob($scratch . DIRECTORY_SEPARATOR . 'chunks-*.bin');
        if (is_array($plainChunks)) {
            foreach ($plainChunks as $f) {
                @unlink($f);
            }
        }
        // ADR-048: remove the previous-index NDJSON scratch file.
        $prevIndex = $scratch . DIRECTORY_SEPARATOR . 'prev_index.ndjson';
        if (is_file($prevIndex)) {
            @unlink($prevIndex);
        }
        // Remove the sub_state sidecar (large-cursor spill) on completion.
        $sidecar = $scratch . DIRECTORY_SEPARATOR . self::SUBSTATE_SIDECAR_BASENAME;
        if (is_file($sidecar)) {
            @unlink($sidecar);
        }

        // 2. Artifact files (DB dump + zip parts).
        $patterns = [
            $scratch . DIRECTORY_SEPARATOR . 'database.sql.gz',
            $scratch . DIRECTORY_SEPARATOR . 'paths.cache',
        ];
        foreach ($patterns as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        $zips = @glob($scratch . DIRECTORY_SEPARATOR . 'wp-content.part*.zip');
        if (is_array($zips)) {
            foreach ($zips as $f) {
                @unlink($f);
            }
        }

        // 3. The scratch dir itself (rmdir refuses if not empty — that's fine).
        @rmdir($scratch);

        // 4. The legacy `wpmgr_backup_runs` dedup row so a future backup of
        //    the same snapshot id can spawn. Best-effort: missing table or
        //    missing row are both acceptable.
        global $wpdb;
        if (is_object($wpdb)) {
            $runsTable = $this->prefix() . Schema::BACKUP_RUNS_TABLE;
            /** @phpstan-ignore-next-line */
            $wpdb->delete($runsTable, ['snapshot_id' => $this->snapshotId()], ['%s']);
        }

        // 5. DELETE the wpmgr_backup_tasks row (Bug 2 fix). Earlier design
        //    kept it at phase=completed for post-hoc debugging, but a kept
        //    row + a delayed wpmgr_backup_watchdog cron event = phantom
        //    re-entry into encrypting_uploading + a presignChunks 422 that
        //    surfaces to the UI as a misleading "encrypting_uploading
        //    failed" event during a subsequent restore. The defensive
        //    watchdog also DELETEs on the next tick if it sees a terminal
        //    row — this is the primary cleanup; the watchdog is belt &
        //    suspenders. Post-hoc debugging now relies on CP-side audit
        //    + the live-progress event log (CP DB), not the agent row.
        if (is_object($wpdb)) {
            $tasksTable = $this->prefix() . Schema::BACKUP_TASKS_TABLE;
            /** @phpstan-ignore-next-line */
            @$wpdb->delete($tasksTable, ['snapshot_id' => $this->snapshotId()], ['%s']);
        }
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Ensure the per-run scratch dir exists, mkdir -p semantics.
     */
    private function ensureScratchDir(): void
    {
        $dir = $this->scratchDir();
        if ($dir === '') {
            throw new \RuntimeException('TaskRunner: scratch_dir is empty');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('TaskRunner: cannot create scratch dir: ' . $dir);
        }
    }

    /** Snapshot id from params (always a non-empty string by construction). */
    private function snapshotId(): string
    {
        return (string) ($this->params['snapshot_id'] ?? '');
    }

    /** Snapshot kind from params; one of {files, db, full}. */
    private function kind(): string
    {
        return (string) ($this->params['kind'] ?? '');
    }

    /** Scratch dir for this snapshot's artifacts + chunk files. */
    private function scratchDir(): string
    {
        return (string) ($this->params['scratch_dir'] ?? '');
    }

    /** WP-content root the FilesArchiver walks. */
    private function wpContentPath(): string
    {
        return (string) ($this->params['wp_content_path'] ?? '');
    }

    /**
     * DB credentials handed to DbDumper. Same shape as DbDumper's contract.
     *
     * @return array{host:string,user:string,password:string,name:string,prefix:string}
     */
    private function dbCreds(): array
    {
        $db = isset($this->params['db']) && is_array($this->params['db']) ? $this->params['db'] : [];
        return [
            'host'     => (string) ($db['host'] ?? ''),
            'user'     => (string) ($db['user'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'name'     => (string) ($db['name'] ?? ''),
            'prefix'   => (string) ($db['prefix'] ?? ''),
        ];
    }

    /** Fully-qualified wpmgr_backup_tasks table name, or '' if no $wpdb. */
    private function tableName(): string
    {
        $p = $this->prefix();
        if ($p === '') {
            return '';
        }
        return $p . Schema::BACKUP_TASKS_TABLE;
    }

    /** Current $wpdb prefix, or '' if not in a WP runtime. */
    private function prefix(): string
    {
        global $wpdb;
        if (is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) {
            return $wpdb->prefix;
        }
        return '';
    }
}
