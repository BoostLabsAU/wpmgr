package backup

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// Repo is the tenant-scoped persistence for backup snapshots/manifests/chunks/
// schedules, plus the cross-tenant scheduler enumeration. Every tenant-scoped
// method runs inside a tenant transaction so RLS enforces isolation even if a
// query omitted its tenant filter.
type Repo interface {
	// Snapshots.
	CreateSnapshot(ctx context.Context, in CreateSnapshotInput) (Snapshot, error)
	GetSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID) (Snapshot, error)
	// GetSnapshotScoped performs the same lookup as GetSnapshot but routes the
	// database transaction through pool.RunTenantTx keyed on the supplied
	// principal. For a site-scoped principal (Scope=="site") this activates
	// InScopedTenantTx so the RESTRICTIVE backup_snapshots_site_scope RLS
	// policy denies access to non-granted sites BEFORE any presigned URL is
	// minted. For org-scoped and legacy (Scope=="") principals it is
	// behaviourally identical to GetSnapshot. Used by PresignChunks and
	// PlanRestore as the gate lookup.
	GetSnapshotScoped(ctx context.Context, p db.ScopedPrincipal, tenantID, snapshotID uuid.UUID) (Snapshot, error)
	ListSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]Snapshot, error)
	MarkSnapshotRunning(ctx context.Context, tenantID, snapshotID uuid.UUID) (Snapshot, error)
	CompleteSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID, totalSize, chunkCount int64) (Snapshot, error)
	FailSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID, errMsg string) (Snapshot, error)
	// UpdateSnapshotProgress replaces the JSONB progress payload with the given
	// raw bytes (caller-validated JSON) and bumps progress_updated_at to now.
	// Tenant-scoped — the caller passes the tenant from the verified agent
	// identity, never from the request body.
	UpdateSnapshotProgress(ctx context.Context, tenantID, snapshotID uuid.UUID, progress []byte) (Snapshot, error)
	// ListStalledRunningSnapshots enumerates `status='running'` snapshots whose
	// last progress update (or start time, if no progress was ever posted) is
	// older than `threshold`. Cross-tenant — runs under `app.agent='on'` for the
	// watchdog periodic. The caller marks each stalled snapshot failed via
	// FailSnapshot (which IS tenant-scoped).
	ListStalledRunningSnapshots(ctx context.Context, threshold time.Duration) ([]StalledSnapshot, error)
	// GetLatestCompletedSnapshot returns the most recent completed snapshot for
	// (tenantID, siteID). Used by resolveChainForSite to determine is_incremental.
	// Returns domain.NotFound when no completed snapshot exists.
	GetLatestCompletedSnapshot(ctx context.Context, tenantID, siteID uuid.UUID) (Snapshot, error)

	// Manifest.
	ListManifest(ctx context.Context, tenantID, snapshotID uuid.UUID) ([]ManifestEntry, error)
	// RecordManifest atomically records a submitted manifest: it upserts each
	// referenced chunk (storing not-yet-stored ones), increments refcounts for
	// every chunk reference, inserts the manifest entries, and completes the
	// snapshot. Returns the total chunk references and how many chunks were newly
	// stored.
	RecordManifest(ctx context.Context, in RecordManifestInput) (chunkRefs, storedCount int64, err error)

	// Chunk dedup: which of the given hashes are already stored for the tenant.
	ExistingChunkHashes(ctx context.Context, tenantID uuid.UUID, hashes []string) (map[string]Chunk, error)

	// Schedules.
	GetSchedule(ctx context.Context, tenantID, siteID uuid.UUID) (Schedule, error)
	UpsertSchedule(ctx context.Context, in UpsertScheduleInput) (Schedule, error)
	// ListDueSchedules enumerates enabled, due schedules across ALL tenants for
	// the periodic scheduler (cross-tenant, under app.agent).
	ListDueSchedules(ctx context.Context, now time.Time, limit int32) ([]Schedule, error)
	// ListTenantsForGC enumerates tenants with completed snapshots across ALL
	// tenants for the periodic retention GC (cross-tenant, under app.agent).
	ListTenantsForGC(ctx context.Context) ([]uuid.UUID, error)
	// AdvanceScheduleRun records an enqueued scheduled backup and advances
	// next_run_at, tenant-scoped.
	AdvanceScheduleRun(ctx context.Context, tenantID, scheduleID uuid.UUID, next time.Time) error

	// Retention GC.
	ListExpiredSnapshots(ctx context.Context, tenantID uuid.UUID, before time.Time) ([]Snapshot, error)
	ListCompletedSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID) ([]SnapshotMeta, error)
	ListSiteIDsWithSnapshots(ctx context.Context, tenantID uuid.UUID) ([]uuid.UUID, error)
	SetSnapshotArchived(ctx context.Context, tenantID, snapshotID uuid.UUID, archived bool) error

	// ADR-050 MARK-AND-SWEEP retention GC.

	// DeleteSnapshot removes a snapshot row (manifest entries + file index cascade
	// via FK ON DELETE CASCADE). Metadata-only: it does NOT touch object storage
	// and does NOT decref chunks (refcount is observability-only post-ADR-050 —
	// chunk reachability is decided by the mark-and-sweep pass, never by refcount).
	// Tenant-scoped.
	DeleteSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID) error
	// ListInFlightSnapshotFloor returns the MIN(created_at) among pending/running
	// snapshots for the tenant, or the zero time when none are in flight. The GC
	// uses min(markStart, this) as the chunk-deletion grace floor so an in-flight
	// backup re-referencing an old chunk (whose manifest is not yet visible at
	// mark time) cannot have that chunk swept. Tenant-scoped.
	ListInFlightSnapshotFloor(ctx context.Context, tenantID uuid.UUID) (time.Time, error)
	// DBNow returns the database clock (SELECT now()). The GC captures markStart
	// from the DB — never the app clock — so the grace floor compares against the
	// same time source as backup_chunks.created_at. Tenant-scoped.
	DBNow(ctx context.Context, tenantID uuid.UUID) (time.Time, error)
	// SweepTenantChunks runs the ADR-050 chunk sweep for one tenant. It first
	// takes a SESSION-level per-tenant pg_try_advisory_lock(hashtext('backup_gc'),
	// hashtext(tenant)) (released via pg_advisory_unlock in a defer). If the lock
	// is not acquired it sets *acquired=false and returns nil (the tenant is
	// skipped — another sweep is in progress). Otherwise it sets *acquired=true
	// and streams every chunk keyset-paged by (created_at, blake3) using SHORT
	// per-page transactions, so no pooled connection is pinned across object-store
	// I/O (avoiding Cloud SQL's idle_in_transaction_session_timeout). For each
	// chunk it invokes del(SweepChunk) OUTSIDE any transaction — del does the
	// object-FIRST delete and returns true when the row should now be removed; the
	// repo then removes those rows in a SHORT delete tx, re-checking
	// GREATEST(created_at, last_referenced_at) < floor at the DB. The session lock
	// keeps the per-tenant sweep exclusive across the whole pass. Tenant-scoped.
	SweepTenantChunks(ctx context.Context, tenantID uuid.UUID, floor time.Time, acquired *bool, del func(c SweepChunk) (bool, error)) error

	// CompleteIncrementalManifest atomically records an incremental submission:
	// it inserts the backup_file_index rows, optionally records the DB-dump
	// manifest (chunk upsert + refcount + manifest insert), and completes the
	// snapshot — all in ONE transaction (ADR-050 STEP 2). Returns
	// (chunkRefs, storedCount). Tenant-scoped.
	CompleteIncrementalManifest(ctx context.Context, in CompleteIncrementalInput) (chunkRefs, storedCount int64, err error)

	// ADR-049 incremental restore chain planner.

	// ListChainSnapshots returns all snapshots belonging to chainID whose
	// generation is <= maxGeneration, ordered by generation ASC. The base
	// (generation 0) is included because the base snapshot's chain_id is set to
	// its own ID. Tenant-scoped. Returns an empty slice (not an error) when no
	// rows match.
	ListChainSnapshots(ctx context.Context, tenantID uuid.UUID, chainID uuid.UUID, maxGeneration int) ([]Snapshot, error)

	// ADR-048 incremental backup file index.

	// InsertFileIndexBatch inserts a batch of FileIndexEntry rows into
	// backup_file_index for a completed incremental snapshot. Tenant-scoped.
	InsertFileIndexBatch(ctx context.Context, tenantID, snapshotID uuid.UUID, entries []FileIndexEntry) error
	// CountFileIndex returns the number of backup_file_index rows for a snapshot.
	// Used by the streaming endpoint's soft-cap check. Tenant-scoped.
	CountFileIndex(ctx context.Context, tenantID, snapshotID uuid.UUID) (int64, error)
	// StreamFileIndex calls fn for each FileIndexEntry ordered by file_path ASC.
	// The iteration stops when fn returns a non-nil error. Uses a server-side
	// cursor to avoid loading all rows into memory. Tenant-scoped.
	StreamFileIndex(ctx context.Context, tenantID, snapshotID uuid.UUID, fn func(FileIndexEntry) error) error
	// UpdateSnapshotCycleStats stamps the incremental cycle telemetry counters
	// on a snapshot row after SubmitIncrementalManifest completes the snapshot.
	UpdateSnapshotCycleStats(ctx context.Context, tenantID, snapshotID uuid.UUID, in CycleStatsInput) error
}

// CreateSnapshotInput creates a pending snapshot.
type CreateSnapshotInput struct {
	TenantID     uuid.UUID
	SiteID       uuid.UUID
	CreatedBy    uuid.UUID
	Kind         string
	AgeRecipient string
	// ADR-048 incremental fields. Zero values produce a full-base snapshot row.
	IsIncremental    bool
	ParentSnapshotID *uuid.UUID
	BaseSnapshotID   *uuid.UUID
	ChainID          *uuid.UUID
	Generation       int
}

// CycleStatsInput is the set of incremental telemetry counters stamped at
// SubmitIncrementalManifest time.
type CycleStatsInput struct {
	CycleFilesScanned  int64
	CycleFilesChanged  int64
	CycleFilesDeleted  int64
	CycleBytesUploaded int64
}

// UpsertScheduleInput creates/updates a per-site schedule.
type UpsertScheduleInput struct {
	TenantID           uuid.UUID
	SiteID             uuid.UUID
	Cadence            string
	Kind               string
	Enabled            bool
	RetentionDays      int32
	MonthlyArchiveKeep int32
	NextRunAt          time.Time
	// New timing fields (M17).
	RunHour        int32
	RunMinute      int32
	DayOfWeek      *int32
	DayOfMonth     *int32
	FrequencyHours *int32
	KeepLast       int32
	// ADR-048 P5: per-schedule incremental opt-in + optional base-window override.
	IncrementalEnabled bool
	BaseWindowDays     *int32
}

// StalledSnapshot is the cross-tenant projection used by the M5.6 progress
// watchdog: enough to mark the snapshot failed in its own tenant scope.
type StalledSnapshot struct {
	ID                uuid.UUID
	TenantID          uuid.UUID
	SiteID            uuid.UUID
	StartedAt         *time.Time
	ProgressUpdatedAt *time.Time
}

// SnapshotMeta is the slim projection used by the retention archive computation.
// ADR-050 widened it to carry the chain columns so the mark-and-sweep GC can do
// chain-aware expansion (pin a carry-forward chunk's origin generation under a
// live tip) and pick the highest retained generation per chain.
type SnapshotMeta struct {
	ID            uuid.UUID
	CreatedAt     time.Time
	Archived      bool
	ChainID       *uuid.UUID
	Generation    int
	IsIncremental bool
}

// SweepChunk is the slim projection the sweep streams: enough to test
// a chunk against the live set + grace floor and to delete its object + row.
// ADR-050 data-loss fix: LastReferencedAt is carried so the per-row delete
// decision uses GREATEST(CreatedAt, LastReferencedAt) < floor — an OLD chunk an
// in-flight backup re-referenced via tenant-global dedup has a fresh
// LastReferencedAt and is therefore protected.
type SweepChunk struct {
	Blake3           string
	S3Key            string
	CreatedAt        time.Time
	LastReferencedAt time.Time
}

// CompleteIncrementalInput is the atomic-completion payload for ADR-050 STEP 2:
// it folds the file-index batch insert, optional DB-manifest recording, and the
// snapshot completion into ONE transaction so a concurrent sweep can never
// observe status='completed' before the file_index rows it must walk are
// visible.
type CompleteIncrementalInput struct {
	TenantID   uuid.UUID
	SnapshotID uuid.UUID
	// FileEntries are the backup_file_index rows (changed files + tombstones).
	FileEntries []FileIndexEntry
	// DBManifest, when non-nil, records the DB-dump manifest entries + chunks via
	// the same RecordManifest logic (chunk upsert + refcount + manifest insert +
	// snapshot completion). When nil the snapshot is completed directly with the
	// supplied TotalSize/ChunkRefs (the files-only path).
	DBManifest *RecordManifestInput
	// TotalSize / ChunkRefs are used only on the files-only path (DBManifest nil)
	// to complete the snapshot.
	TotalSize int64
	ChunkRefs int64
}

// ChunkUpload describes a chunk reference in a submitted manifest: its hash,
// ciphertext size, and content-addressed s3 key.
type ChunkUpload struct {
	Blake3 string
	Size   int64
	S3Key  string
}

// RecordManifestInput is the validated input for recording a submitted manifest.
type RecordManifestInput struct {
	TenantID   uuid.UUID
	SnapshotID uuid.UUID
	Entries    []ManifestEntryInput
	// Chunks is the de-duplicated set of distinct chunks referenced by the
	// manifest (hash -> upload metadata) used to upsert backup_chunks rows.
	Chunks map[string]ChunkUpload
}

// ManifestEntryInput is one file/db entry to persist.
type ManifestEntryInput struct {
	Path        string
	EntryKind   string
	TableName   string
	ChunkHashes []string
	Size        int64
	Mode        int32
}

type pgRepo struct {
	pool *db.Pool
}

// NewRepo builds a Repo backed by the pgx pool with RLS enforcement.
func NewRepo(pool *db.Pool) Repo { return &pgRepo{pool: pool} }

func (r *pgRepo) CreateSnapshot(ctx context.Context, in CreateSnapshotInput) (Snapshot, error) {
	var out Snapshot
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		var createdBy pgtype.UUID
		if in.CreatedBy != uuid.Nil {
			createdBy = pgtype.UUID{Bytes: in.CreatedBy, Valid: true}
		}
		row, err := sqlc.New(tx).CreateBackupSnapshot(ctx, sqlc.CreateBackupSnapshotParams{
			TenantID:     in.TenantID,
			SiteID:       in.SiteID,
			CreatedBy:    createdBy,
			Kind:         in.Kind,
			AgeRecipient: in.AgeRecipient,
		})
		if err != nil {
			return domain.Internal("backup_snapshot_create_failed", "failed to create snapshot").WithCause(err)
		}
		out = toSnapshot(row)

		// ADR-048/050: stamp incremental chain fields when this is an incremental
		// run. We do this with a raw UPDATE because the sqlc-generated
		// CreateBackupSnapshot predates the m44 columns — updating the generated
		// code requires regenerating sqlc which is out of scope for this migration.
		// The UPDATE is within the same transaction.
		//
		// chain_id resolution (ADR-050, m46): a generation-0 snapshot (a full base
		// OR a plain full backup) anchors its OWN chain, so chain_id = its own id
		// when no explicit chain_id was supplied. Without this a base's chain_id
		// stays NULL and the whole chain is unresolvable by ListChainSnapshots /
		// planRestoreChain / the retention-GC mark walk. Increments always pass an
		// explicit ChainID (the base's). This is the forward counterpart to the m46
		// backfill of existing bases.
		resolvedChainID := in.ChainID
		if resolvedChainID == nil && in.Generation == 0 {
			id := out.ID
			resolvedChainID = &id
		}
		if in.IsIncremental || in.Generation > 0 || in.ParentSnapshotID != nil || resolvedChainID != nil {
			var parentID, baseID, chainID *[16]byte
			if in.ParentSnapshotID != nil {
				b := [16]byte(*in.ParentSnapshotID)
				parentID = &b
			}
			if in.BaseSnapshotID != nil {
				b := [16]byte(*in.BaseSnapshotID)
				baseID = &b
			}
			if resolvedChainID != nil {
				b := [16]byte(*resolvedChainID)
				chainID = &b
			}
			_, uerr := tx.Exec(ctx,
				`UPDATE backup_snapshots
				    SET is_incremental=$3, parent_snapshot_id=$4, base_snapshot_id=$5,
				        chain_id=$6, generation=$7
				  WHERE id=$1 AND tenant_id=$2`,
				out.ID, in.TenantID,
				in.IsIncremental,
				parentID,
				baseID,
				chainID,
				in.Generation,
			)
			if uerr != nil {
				return domain.Internal("backup_snapshot_create_failed", "failed to stamp incremental fields").WithCause(uerr)
			}
			out.IsIncremental = in.IsIncremental
			out.ParentSnapshotID = in.ParentSnapshotID
			out.BaseSnapshotID = in.BaseSnapshotID
			out.ChainID = resolvedChainID
			out.Generation = in.Generation
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) GetLatestCompletedSnapshot(ctx context.Context, tenantID, siteID uuid.UUID) (Snapshot, error) {
	var out Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			snapshotSelectColumns+` FROM backup_snapshots
			  WHERE tenant_id=$1 AND site_id=$2 AND status='completed'
			  ORDER BY created_at DESC
			  LIMIT 1`,
			tenantID, siteID,
		)
		s, err := scanSnapshotWithChainFields(row)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "no completed snapshot found for site")
			}
			return domain.Internal("backup_snapshot_get_failed", "failed to query latest snapshot").WithCause(err)
		}
		out = s
		return nil
	})
	return out, err
}

func (r *pgRepo) GetSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID) (Snapshot, error) {
	var out Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			snapshotSelectColumns+` FROM backup_snapshots WHERE id=$1 AND tenant_id=$2`,
			snapshotID, tenantID,
		)
		s, err := scanSnapshotWithChainFields(row)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_get_failed", "failed to load snapshot").WithCause(err)
		}
		out = s
		return nil
	})
	return out, err
}

// GetSnapshotScoped performs the same lookup as GetSnapshot but routes the
// transaction through pool.RunTenantTx so a site-scoped principal activates
// InScopedTenantTx. For org-scoped/legacy principals behaviour is identical
// to GetSnapshot. See the Repo interface for the full contract.
func (r *pgRepo) GetSnapshotScoped(ctx context.Context, p db.ScopedPrincipal, tenantID, snapshotID uuid.UUID) (Snapshot, error) {
	var out Snapshot
	err := r.pool.RunTenantTx(ctx, p, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			snapshotSelectColumns+` FROM backup_snapshots WHERE id=$1 AND tenant_id=$2`,
			snapshotID, tenantID,
		)
		s, err := scanSnapshotWithChainFields(row)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_get_failed", "failed to load snapshot").WithCause(err)
		}
		out = s
		return nil
	})
	return out, err
}

func (r *pgRepo) ListSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]Snapshot, error) {
	var out []Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			snapshotSelectColumns+` FROM backup_snapshots
			  WHERE tenant_id=$1 AND site_id=$2
			  ORDER BY created_at DESC
			  LIMIT $3 OFFSET $4`,
			tenantID, siteID, limit, offset,
		)
		if err != nil {
			return domain.Internal("backup_snapshot_list_failed", "failed to list snapshots").WithCause(err)
		}
		defer rows.Close()
		out = make([]Snapshot, 0)
		for rows.Next() {
			s, serr := scanSnapshotWithChainFields(rows)
			if serr != nil {
				return domain.Internal("backup_snapshot_list_scan_failed", "failed to scan snapshot row").WithCause(serr)
			}
			out = append(out, s)
		}
		return rows.Err()
	})
	return out, err
}

func (r *pgRepo) MarkSnapshotRunning(ctx context.Context, tenantID, snapshotID uuid.UUID) (Snapshot, error) {
	return r.mutateSnapshot(ctx, tenantID, func(q *sqlc.Queries) (sqlc.BackupSnapshot, error) {
		return q.MarkBackupSnapshotRunning(ctx, sqlc.MarkBackupSnapshotRunningParams{ID: snapshotID, TenantID: tenantID})
	}, "backup_snapshot_run_failed")
}

func (r *pgRepo) CompleteSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID, totalSize, chunkCount int64) (Snapshot, error) {
	return r.mutateSnapshot(ctx, tenantID, func(q *sqlc.Queries) (sqlc.BackupSnapshot, error) {
		return q.CompleteBackupSnapshot(ctx, sqlc.CompleteBackupSnapshotParams{
			ID: snapshotID, TenantID: tenantID, TotalSize: totalSize, ChunkCount: chunkCount,
		})
	}, "backup_snapshot_complete_failed")
}

func (r *pgRepo) FailSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID, errMsg string) (Snapshot, error) {
	return r.mutateSnapshot(ctx, tenantID, func(q *sqlc.Queries) (sqlc.BackupSnapshot, error) {
		return q.FailBackupSnapshot(ctx, sqlc.FailBackupSnapshotParams{ID: snapshotID, TenantID: tenantID, Error: errMsg})
	}, "backup_snapshot_fail_failed")
}

// UpdateSnapshotProgress is tenant-scoped (RLS enforces it); the agent handler
// passes the tenant from the verified Ed25519 identity.
func (r *pgRepo) UpdateSnapshotProgress(ctx context.Context, tenantID, snapshotID uuid.UUID, progress []byte) (Snapshot, error) {
	return r.mutateSnapshot(ctx, tenantID, func(q *sqlc.Queries) (sqlc.BackupSnapshot, error) {
		return q.UpdateBackupSnapshotProgress(ctx, sqlc.UpdateBackupSnapshotProgressParams{
			ID: snapshotID, TenantID: tenantID, Progress: progress,
		})
	}, "backup_snapshot_progress_failed")
}

// ListStalledRunningSnapshots runs cross-tenant under app.agent='on' (same
// pattern as ListDueSchedules / ListTenantsForGC). The watchdog then transitions
// each row to failed via FailSnapshot (tenant-scoped).
func (r *pgRepo) ListStalledRunningSnapshots(ctx context.Context, threshold time.Duration) ([]StalledSnapshot, error) {
	var out []StalledSnapshot
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		// pgtype.Interval round-trips as microseconds — convert duration to µs.
		ival := pgtype.Interval{Microseconds: threshold.Microseconds(), Valid: true}
		rows, err := sqlc.New(tx).ListStalledRunningSnapshots(ctx, ival)
		if err != nil {
			return domain.Internal("backup_snapshot_stalled_list_failed", "failed to list stalled snapshots").WithCause(err)
		}
		out = make([]StalledSnapshot, 0, len(rows))
		for _, row := range rows {
			s := StalledSnapshot{ID: row.ID, TenantID: row.TenantID, SiteID: row.SiteID}
			if row.StartedAt.Valid {
				t := row.StartedAt.Time
				s.StartedAt = &t
			}
			if row.ProgressUpdatedAt.Valid {
				t := row.ProgressUpdatedAt.Time
				s.ProgressUpdatedAt = &t
			}
			out = append(out, s)
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) mutateSnapshot(ctx context.Context, tenantID uuid.UUID, fn func(*sqlc.Queries) (sqlc.BackupSnapshot, error), code string) (Snapshot, error) {
	var out Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := fn(sqlc.New(tx))
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal(code, "failed to update snapshot").WithCause(err)
		}
		out = toSnapshot(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) ListManifest(ctx context.Context, tenantID, snapshotID uuid.UUID) ([]ManifestEntry, error) {
	var out []ManifestEntry
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListManifestEntries(ctx, sqlc.ListManifestEntriesParams{SnapshotID: snapshotID, TenantID: tenantID})
		if err != nil {
			return domain.Internal("backup_manifest_list_failed", "failed to list manifest entries").WithCause(err)
		}
		out = make([]ManifestEntry, 0, len(rows))
		for _, row := range rows {
			out = append(out, toManifestEntry(row))
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) RecordManifest(ctx context.Context, in RecordManifestInput) (int64, int64, error) {
	var chunkRefs, storedCount, totalSize int64
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)

		// 1. Upsert each distinct chunk (idempotent; content-addressed). A chunk
		// whose row's refcount is still 0 after the upsert and that did not exist
		// before is "newly stored". We detect newly-stored by checking existence
		// first.
		for hash, up := range in.Chunks {
			_, getErr := q.GetBackupChunk(ctx, sqlc.GetBackupChunkParams{TenantID: in.TenantID, Blake3: hash})
			existed := getErr == nil
			if getErr != nil && !errors.Is(getErr, pgx.ErrNoRows) {
				return domain.Internal("backup_chunk_get_failed", "failed to check chunk existence").WithCause(getErr)
			}
			if _, err := q.UpsertBackupChunk(ctx, sqlc.UpsertBackupChunkParams{
				TenantID: in.TenantID, Blake3: hash, S3Key: up.S3Key, Size: up.Size,
			}); err != nil {
				return domain.Internal("backup_chunk_upsert_failed", "failed to upsert chunk").WithCause(err)
			}
			if !existed {
				storedCount++
			}
		}

		// 2. Insert manifest entries and increment refcounts for every chunk
		// reference (a chunk referenced N times across entries gets +N).
		referenced := map[string]struct{}{}
		for _, e := range in.Entries {
			if _, err := q.CreateManifestEntry(ctx, sqlc.CreateManifestEntryParams{
				SnapshotID:  in.SnapshotID,
				TenantID:    in.TenantID,
				Path:        e.Path,
				EntryKind:   e.EntryKind,
				TableName:   e.TableName,
				ChunkHashes: e.ChunkHashes,
				Size:        e.Size,
				Mode:        e.Mode,
			}); err != nil {
				return domain.Internal("backup_manifest_insert_failed", "failed to insert manifest entry").WithCause(err)
			}
			totalSize += e.Size
			for _, h := range e.ChunkHashes {
				if _, err := q.IncrementChunkRefcount(ctx, sqlc.IncrementChunkRefcountParams{TenantID: in.TenantID, Blake3: h}); err != nil {
					return domain.Internal("backup_chunk_incref_failed", "failed to increment chunk refcount").WithCause(err)
				}
				referenced[h] = struct{}{}
				chunkRefs++
			}
		}

		// 2b. ADR-050 belt: keep every referenced chunk's last_referenced_at fresh
		// at completion so a just-completed snapshot's chunks also clear the sweep's
		// GREATEST(created_at, last_referenced_at) < floor predicate, not only the
		// presign-time dedup touch.
		if terr := touchReferencedChunks(ctx, tx, in.TenantID, referenced); terr != nil {
			return terr
		}

		// 3. Complete the snapshot.
		if _, err := q.CompleteBackupSnapshot(ctx, sqlc.CompleteBackupSnapshotParams{
			ID: in.SnapshotID, TenantID: in.TenantID, TotalSize: totalSize, ChunkCount: chunkRefs,
		}); err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_complete_failed", "failed to complete snapshot").WithCause(err)
		}
		return nil
	})
	return chunkRefs, storedCount, err
}

func (r *pgRepo) ExistingChunkHashes(ctx context.Context, tenantID uuid.UUID, hashes []string) (map[string]Chunk, error) {
	out := map[string]Chunk{}
	if len(hashes) == 0 {
		return out, nil
	}
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		// ADR-050 mark-and-sweep data-loss fix: this is the dedup oracle
		// PresignChunks relies on to decide "already stored, skip upload" WITHOUT
		// re-uploading — which would otherwise leave an OLD chunk's created_at
		// ancient while an in-flight (status='running', not yet in the mark set)
		// backup re-references it. So we bump last_referenced_at = now() (DB clock)
		// for exactly the chunks we report as existing, in the SAME statement as
		// the existence read (UPDATE ... RETURNING). This guarantees any chunk
		// reported existing has just been touched, so a concurrent sweep — which
		// deletes only when GREATEST(created_at, last_referenced_at) < floor —
		// cannot delete it: its last_referenced_at >= the in-flight snapshot's
		// start >= inflightFloor >= effectiveFloor. Raw SQL (not sqlc) so the
		// touch and the read share one round-trip.
		rows, err := tx.Query(ctx,
			`UPDATE backup_chunks
			    SET last_referenced_at = now(), updated_at = now()
			  WHERE tenant_id = $1 AND blake3 = ANY($2::text[])
			RETURNING id, tenant_id, blake3, s3_key, size, refcount,
			          created_at, updated_at`,
			tenantID, hashes,
		)
		if err != nil {
			return domain.Internal("backup_chunk_existing_failed", "failed to query existing chunks").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var c Chunk
			if serr := rows.Scan(
				&c.ID, &c.TenantID, &c.Blake3, &c.S3Key, &c.Size, &c.Refcount,
				&c.CreatedAt, &c.UpdatedAt,
			); serr != nil {
				return domain.Internal("backup_chunk_existing_scan_failed", "failed to scan existing chunk row").WithCause(serr)
			}
			out[c.Blake3] = c
		}
		return rows.Err()
	})
	return out, err
}

func (r *pgRepo) GetSchedule(ctx context.Context, tenantID, siteID uuid.UUID) (Schedule, error) {
	var out Schedule
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetBackupScheduleForSite(ctx, sqlc.GetBackupScheduleForSiteParams{TenantID: tenantID, SiteID: siteID})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_schedule_not_found", "backup schedule not found")
			}
			return domain.Internal("backup_schedule_get_failed", "failed to load schedule").WithCause(err)
		}
		out = toSchedule(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) UpsertSchedule(ctx context.Context, in UpsertScheduleInput) (Schedule, error) {
	var out Schedule
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		// Convert int32 pointer fields to *int16 for sqlc.
		var dow *int16
		if in.DayOfWeek != nil {
			v := int16(*in.DayOfWeek)
			dow = &v
		}
		var dom *int16
		if in.DayOfMonth != nil {
			v := int16(*in.DayOfMonth)
			dom = &v
		}
		var fh *int16
		if in.FrequencyHours != nil {
			v := int16(*in.FrequencyHours)
			fh = &v
		}
		row, err := sqlc.New(tx).UpsertBackupSchedule(ctx, sqlc.UpsertBackupScheduleParams{
			TenantID:           in.TenantID,
			SiteID:             in.SiteID,
			Cadence:            in.Cadence,
			Kind:               in.Kind,
			Enabled:            in.Enabled,
			RetentionDays:      in.RetentionDays,
			MonthlyArchiveKeep: in.MonthlyArchiveKeep,
			NextRunAt:          in.NextRunAt,
			RunHour:            int16(in.RunHour),
			RunMinute:          int16(in.RunMinute),
			DayOfWeek:          dow,
			DayOfMonth:         dom,
			FrequencyHours:     fh,
			KeepLast:           in.KeepLast,
			IncrementalEnabled: in.IncrementalEnabled,
			BaseWindowDays:     in.BaseWindowDays,
		})
		if err != nil {
			return domain.Internal("backup_schedule_upsert_failed", "failed to save schedule").WithCause(err)
		}
		out = toSchedule(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) ListDueSchedules(ctx context.Context, now time.Time, limit int32) ([]Schedule, error) {
	var out []Schedule
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListDueBackupSchedules(ctx, sqlc.ListDueBackupSchedulesParams{NextRunAt: now, Limit: limit})
		if err != nil {
			return domain.Internal("backup_schedule_due_failed", "failed to list due schedules").WithCause(err)
		}
		out = make([]Schedule, 0, len(rows))
		for _, row := range rows {
			out = append(out, toSchedule(row))
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) ListTenantsForGC(ctx context.Context) ([]uuid.UUID, error) {
	var out []uuid.UUID
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListTenantsWithCompletedSnapshots(ctx)
		if err != nil {
			return domain.Internal("backup_gc_tenants_failed", "failed to list tenants for GC").WithCause(err)
		}
		out = rows
		return nil
	})
	return out, err
}

func (r *pgRepo) AdvanceScheduleRun(ctx context.Context, tenantID, scheduleID uuid.UUID, next time.Time) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		_, err := sqlc.New(tx).AdvanceBackupScheduleRun(ctx, sqlc.AdvanceBackupScheduleRunParams{ID: scheduleID, TenantID: tenantID, NextRunAt: next})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_schedule_not_found", "backup schedule not found")
			}
			return domain.Internal("backup_schedule_advance_failed", "failed to advance schedule").WithCause(err)
		}
		return nil
	})
}

func (r *pgRepo) ListExpiredSnapshots(ctx context.Context, tenantID uuid.UUID, before time.Time) ([]Snapshot, error) {
	var out []Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListExpiredBackupSnapshots(ctx, sqlc.ListExpiredBackupSnapshotsParams{TenantID: tenantID, CreatedAt: before})
		if err != nil {
			return domain.Internal("backup_expired_list_failed", "failed to list expired snapshots").WithCause(err)
		}
		out = make([]Snapshot, 0, len(rows))
		for _, row := range rows {
			out = append(out, toSnapshot(row))
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) ListCompletedSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID) ([]SnapshotMeta, error) {
	var out []SnapshotMeta
	// Raw SQL (not sqlc) because ADR-050 widened the projection to carry the
	// chain columns; regenerating sqlc is out of scope for this migration (same
	// m44/m46 raw-query precedent).
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			`SELECT id, created_at, archived, chain_id, generation, is_incremental
			   FROM backup_snapshots
			  WHERE tenant_id = $1 AND site_id = $2 AND status = 'completed'
			  ORDER BY created_at DESC`,
			tenantID, siteID,
		)
		if err != nil {
			return domain.Internal("backup_completed_list_failed", "failed to list completed snapshots").WithCause(err)
		}
		defer rows.Close()
		out = make([]SnapshotMeta, 0)
		for rows.Next() {
			var m SnapshotMeta
			var chainID pgtype.UUID
			if serr := rows.Scan(&m.ID, &m.CreatedAt, &m.Archived, &chainID, &m.Generation, &m.IsIncremental); serr != nil {
				return domain.Internal("backup_completed_scan_failed", "failed to scan completed snapshot row").WithCause(serr)
			}
			if chainID.Valid {
				id := uuid.UUID(chainID.Bytes)
				m.ChainID = &id
			}
			out = append(out, m)
		}
		return rows.Err()
	})
	return out, err
}

func (r *pgRepo) ListSiteIDsWithSnapshots(ctx context.Context, tenantID uuid.UUID) ([]uuid.UUID, error) {
	var out []uuid.UUID
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListBackupSiteIDsForTenant(ctx, tenantID)
		if err != nil {
			return domain.Internal("backup_site_ids_failed", "failed to list backup site ids").WithCause(err)
		}
		out = rows
		return nil
	})
	return out, err
}

func (r *pgRepo) SetSnapshotArchived(ctx context.Context, tenantID, snapshotID uuid.UUID, archived bool) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		if err := sqlc.New(tx).SetBackupSnapshotArchived(ctx, sqlc.SetBackupSnapshotArchivedParams{ID: snapshotID, TenantID: tenantID, Archived: archived}); err != nil {
			return domain.Internal("backup_snapshot_archive_failed", "failed to set snapshot archived").WithCause(err)
		}
		return nil
	})
}

// DeleteSnapshot removes a snapshot metadata-only (ADR-050). Manifest entries
// and file-index rows cascade via their FK ON DELETE CASCADE. It deliberately
// does NOT decref chunks and does NOT touch object storage: post-ADR-050 the
// only authority over chunk liveness is the mark-and-sweep pass.
func (r *pgRepo) DeleteSnapshot(ctx context.Context, tenantID, snapshotID uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		if _, err := sqlc.New(tx).DeleteBackupSnapshot(ctx, sqlc.DeleteBackupSnapshotParams{ID: snapshotID, TenantID: tenantID}); err != nil {
			return domain.Internal("backup_snapshot_delete_failed", "failed to delete snapshot").WithCause(err)
		}
		return nil
	})
}

func (r *pgRepo) ListInFlightSnapshotFloor(ctx context.Context, tenantID uuid.UUID) (time.Time, error) {
	var out time.Time
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		var floor pgtype.Timestamptz
		row := tx.QueryRow(ctx,
			`SELECT min(created_at)::timestamptz
			   FROM backup_snapshots
			  WHERE tenant_id = $1 AND status IN ('pending','running')`,
			tenantID,
		)
		if serr := row.Scan(&floor); serr != nil {
			return domain.Internal("backup_inflight_floor_failed", "failed to read in-flight snapshot floor").WithCause(serr)
		}
		if floor.Valid {
			out = floor.Time
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) DBNow(ctx context.Context, tenantID uuid.UUID) (time.Time, error) {
	var out time.Time
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		if serr := tx.QueryRow(ctx, `SELECT now()`).Scan(&out); serr != nil {
			return domain.Internal("backup_db_now_failed", "failed to read database clock").WithCause(serr)
		}
		return nil
	})
	return out, err
}

func (r *pgRepo) SweepTenantChunks(ctx context.Context, tenantID uuid.UUID, floor time.Time, acquired *bool, del func(c SweepChunk) (bool, error)) (err error) {
	*acquired = false

	// 1. PIN ONE pooled connection for the WHOLE sweep pass. The per-tenant GC
	//    advisory lock is SESSION-scoped, so it only stays held while every later
	//    statement runs on the SAME backing session. Taking it inside a pooled tx
	//    that commits would return the connection to the pool and silently drop
	//    the lock — then a second concurrent same-tenant pass could ALSO acquire
	//    it and both would sweep. Acquire + Release bookends the session; the
	//    short per-page / per-chunk txns below all run on this one conn.
	conn, aerr := r.pool.Acquire(ctx)
	if aerr != nil {
		return domain.Internal("backup_gc_conn_failed", "failed to acquire GC connection").WithCause(aerr)
	}
	defer conn.Release()

	// 2. Take the SESSION-level per-tenant GC advisory lock ON THE PINNED CONN.
	//    Two-int form: (hashtext('backup_gc'), hashtext(tenant)) — namespaced so
	//    it collides only with another GC sweep for the SAME tenant. Because it is
	//    held on this one conn for the whole pass, a concurrent same-tenant pass
	//    fails pg_try_advisory_lock and skips.
	var got bool
	if lerr := conn.QueryRow(ctx,
		`SELECT pg_try_advisory_lock(hashtext('backup_gc'), hashtext($1))`,
		tenantID.String(),
	).Scan(&got); lerr != nil {
		return domain.Internal("backup_gc_lock_failed", "failed to take GC advisory lock").WithCause(lerr)
	}
	if !got {
		return nil // another sweep holds it; *acquired stays false.
	}
	*acquired = true
	// Always release the session lock on the SAME conn, on every path (incl.
	// error), BEFORE Release returns the conn to the pool. Best-effort: a failed
	// unlock is harmless — the lock is session-scoped and also drops when the
	// session closes.
	defer func() {
		_, _ = conn.Exec(ctx,
			`SELECT pg_advisory_unlock(hashtext('backup_gc'), hashtext($1))`,
			tenantID.String(),
		)
	}()

	// 3. Stream every chunk, keyset-paged by (created_at, blake3). Each page read
	//    AND each per-chunk delete is its OWN short transaction ON THE PINNED CONN
	//    (conn.Begin) so no long tx pins idle across object-store I/O (avoiding
	//    Cloud SQL's idle_in_transaction_session_timeout) while the session lock
	//    stays held the whole pass. Every such tx replicates InTenantTx's RLS
	//    setup (SET LOCAL app.tenant_id) so RLS still scopes the reads/deletes.
	const pageSize = 5000
	var (
		haveCursor bool
		curTime    time.Time
		curHash    string
	)
	for {
		// 3a. SHORT read tx on the pinned conn: fetch one page of candidates.
		var batch []SweepChunk
		readErr := r.inTenantTxOnConn(ctx, conn, tenantID, func(tx pgx.Tx) error {
			var (
				rows pgx.Rows
				qerr error
			)
			if !haveCursor {
				rows, qerr = tx.Query(ctx,
					`SELECT blake3, s3_key, created_at, last_referenced_at
					   FROM backup_chunks
					  WHERE tenant_id = $1
					  ORDER BY created_at ASC, blake3 ASC
					  LIMIT $2`,
					tenantID, pageSize,
				)
			} else {
				rows, qerr = tx.Query(ctx,
					`SELECT blake3, s3_key, created_at, last_referenced_at
					   FROM backup_chunks
					  WHERE tenant_id = $1
					    AND (created_at, blake3) > ($2, $3)
					  ORDER BY created_at ASC, blake3 ASC
					  LIMIT $4`,
					tenantID, curTime, curHash, pageSize,
				)
			}
			if qerr != nil {
				return domain.Internal("backup_sweep_list_failed", "failed to list chunks for sweep").WithCause(qerr)
			}
			defer rows.Close()
			for rows.Next() {
				var c SweepChunk
				if serr := rows.Scan(&c.Blake3, &c.S3Key, &c.CreatedAt, &c.LastReferencedAt); serr != nil {
					return domain.Internal("backup_sweep_scan_failed", "failed to scan sweep chunk row").WithCause(serr)
				}
				batch = append(batch, c)
			}
			if rerr := rows.Err(); rerr != nil {
				return domain.Internal("backup_sweep_iter_failed", "failed iterating sweep chunks").WithCause(rerr)
			}
			return nil
		})
		if readErr != nil {
			return readErr
		}

		// 3b. Per-candidate: a SHORT per-chunk tx on the pinned conn that holds a
		//     row-level FOR UPDATE lock ACROSS the object delete. This serializes the
		//     object delete against a concurrent dedup touch (ExistingChunkHashes'
		//     UPDATE ... last_referenced_at=now()): once we lock the row, that UPDATE
		//     BLOCKS until we commit, and we re-check the floor under the lock so a
		//     touch that won the race makes us skip (no object delete). Because chunk
		//     keys are content-addressed this serialization is REQUIRED — without the
		//     held lock a touch->re-upload could re-PUT the same key the sweep then
		//     deletes.
		for _, c := range batch {
			if serr := r.sweepOneChunk(ctx, conn, tenantID, c, floor, del); serr != nil {
				return serr
			}
		}

		if len(batch) < pageSize {
			return nil
		}
		last := batch[len(batch)-1]
		curTime, curHash, haveCursor = last.CreatedAt, last.Blake3, true
	}
}

// sweepOneChunk runs the FIX-A per-chunk critical section for one sweep candidate
// inside a SHORT transaction on the pinned conn:
//
//  1. SELECT ... FOR UPDATE locks the row (a concurrent ExistingChunkHashes dedup
//     touch on this chunk now BLOCKS until this tx commits) and re-reads the
//     FRESH created_at / last_referenced_at.
//  2. Re-check GREATEST(created_at, last_referenced_at) < floor UNDER the lock.
//     If a touch won the race the boundary is now >= floor -> skip (no delete).
//  3. del(freshChunk) consults the live set + floor and, when still deletable,
//     deletes the OBJECT while we STILL HOLD the lock (idempotent; 404 == ok).
//  4. DELETE the row (object-FIRST/row-SECOND), then COMMIT releases the lock.
//
// Object-first/row-second within the locked tx preserves crash self-heal: a
// crash after the object delete but before COMMIT rolls the tx back, leaving the
// row present with its object gone — the dangling-row case the next sweep heals
// idempotently. A missing row (already swept by a prior partial pass) is a no-op.
func (r *pgRepo) sweepOneChunk(ctx context.Context, conn sweepConn, tenantID uuid.UUID, c SweepChunk, floor time.Time, del func(SweepChunk) (bool, error)) error {
	return r.inTenantTxOnConn(ctx, conn, tenantID, func(tx pgx.Tx) error {
		// 1. Lock the row and re-read the fresh liveness boundary.
		fresh := SweepChunk{Blake3: c.Blake3}
		row := tx.QueryRow(ctx,
			`SELECT s3_key, created_at, last_referenced_at
			   FROM backup_chunks
			  WHERE tenant_id = $1 AND blake3 = $2
			  FOR UPDATE`,
			tenantID, c.Blake3,
		)
		if serr := row.Scan(&fresh.S3Key, &fresh.CreatedAt, &fresh.LastReferencedAt); serr != nil {
			if errors.Is(serr, pgx.ErrNoRows) {
				return nil // row already gone — nothing to do.
			}
			return domain.Internal("backup_sweep_lock_failed", "failed to lock sweep chunk row").WithCause(serr)
		}

		// 2. Re-check the grace floor under the lock. A dedup touch that committed
		//    before we took the lock raised last_referenced_at to ~now, so the
		//    boundary is now >= floor and we MUST keep the chunk.
		boundary := fresh.CreatedAt
		if fresh.LastReferencedAt.After(boundary) {
			boundary = fresh.LastReferencedAt
		}
		if !boundary.Before(floor) {
			return nil // a touch won the race — keep object + row.
		}

		// 3. del consults the live set + floor on the FRESH projection and, when the
		//    chunk is still deletable, deletes the OBJECT while we hold the lock.
		remove, ferr := del(fresh)
		if ferr != nil {
			return ferr
		}
		if !remove {
			return nil // del decided to keep (live, or re-checked floor).
		}

		// 4. Row-SECOND: delete the row, still under the held lock and re-checking
		//    GREATEST(...) < floor at the DB (defense-in-depth).
		return r.deleteSweptChunkOnTx(ctx, tx, tenantID, c.Blake3, floor)
	})
}

// sweepConn is the minimal pinned-connection surface the sweep needs: just the
// ability to begin a transaction. *pgxpool.Conn satisfies it.
type sweepConn interface {
	Begin(ctx context.Context) (pgx.Tx, error)
}

// inTenantTxOnConn runs fn inside a transaction begun ON THE PINNED CONN, with
// app.tenant_id set for the lifetime of the tx (SET LOCAL) — mirroring
// db.Pool.InTenantTx's RLS setup exactly, but WITHOUT returning the connection to
// the pool, so the surrounding session advisory lock stays held. The tx commits
// when fn returns nil and rolls back otherwise.
func (r *pgRepo) inTenantTxOnConn(ctx context.Context, conn sweepConn, tenantID uuid.UUID, fn func(tx pgx.Tx) error) error {
	tx, err := conn.Begin(ctx)
	if err != nil {
		return domain.Internal("backup_sweep_tx_begin_failed", "failed to begin sweep tx").WithCause(err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	if _, serr := tx.Exec(ctx, "SELECT set_config('app.tenant_id', $1, true)", tenantID.String()); serr != nil {
		return domain.Internal("backup_sweep_set_tenant_failed", "failed to set app.tenant_id for sweep tx").WithCause(serr)
	}
	if ferr := fn(tx); ferr != nil {
		return ferr
	}
	if cerr := tx.Commit(ctx); cerr != nil {
		return domain.Internal("backup_sweep_tx_commit_failed", "failed to commit sweep tx").WithCause(cerr)
	}
	return nil
}

// deleteSweptChunkOnTx removes a chunk row by hash, re-checking the grace-floor
// predicate at the DB (defense-in-depth: a chunk re-referenced after the sweep
// read it has a fresh last_referenced_at and so fails GREATEST(...) < floor).
// Runs on the supplied SHORT delete transaction. The caller deletes the object
// FIRST (idempotent), then this.
func (r *pgRepo) deleteSweptChunkOnTx(ctx context.Context, tx pgx.Tx, tenantID uuid.UUID, blake3 string, floor time.Time) error {
	if _, err := tx.Exec(ctx,
		`DELETE FROM backup_chunks
		  WHERE tenant_id = $1 AND blake3 = $2
		    AND GREATEST(created_at, last_referenced_at) < $3`,
		tenantID, blake3, floor,
	); err != nil {
		return domain.Internal("backup_swept_chunk_delete_failed", "failed to delete swept chunk row").WithCause(err)
	}
	return nil
}

// touchReferencedChunks bumps last_referenced_at = now() for the given chunk
// hashes inside the supplied completion transaction (ADR-050 belt). It is a
// no-op for an empty set. Sharing the tx means a completed snapshot's chunks are
// stamped fresh atomically with its completion, so the sweep's
// GREATEST(created_at, last_referenced_at) < floor predicate keeps them.
func touchReferencedChunks(ctx context.Context, tx pgx.Tx, tenantID uuid.UUID, referenced map[string]struct{}) error {
	if len(referenced) == 0 {
		return nil
	}
	hashes := make([]string, 0, len(referenced))
	for h := range referenced {
		hashes = append(hashes, h)
	}
	if _, err := tx.Exec(ctx,
		`UPDATE backup_chunks
		    SET last_referenced_at = now(), updated_at = now()
		  WHERE tenant_id = $1 AND blake3 = ANY($2::text[])`,
		tenantID, hashes,
	); err != nil {
		return domain.Internal("backup_chunk_touch_failed", "failed to refresh chunk last_referenced_at").WithCause(err)
	}
	return nil
}

// CompleteIncrementalManifest folds the file-index insert, optional DB-manifest
// recording, and snapshot completion into ONE transaction (ADR-050 STEP 2).
func (r *pgRepo) CompleteIncrementalManifest(ctx context.Context, in CompleteIncrementalInput) (int64, int64, error) {
	var chunkRefs, storedCount int64
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)

		// 1. Insert backup_file_index rows (changed files + tombstones).
		for _, e := range in.FileEntries {
			hashes := e.ChunkHashes
			if hashes == nil {
				hashes = []string{}
			}
			if _, err := tx.Exec(ctx,
				`INSERT INTO backup_file_index
				   (tenant_id, snapshot_id, file_path, file_size, file_mtime,
				    file_blake3, chunk_hashes, is_tombstone)
				 VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
				 ON CONFLICT (snapshot_id, file_path) DO UPDATE
				   SET file_size    = EXCLUDED.file_size,
				       file_mtime   = EXCLUDED.file_mtime,
				       file_blake3  = EXCLUDED.file_blake3,
				       chunk_hashes = EXCLUDED.chunk_hashes,
				       is_tombstone = EXCLUDED.is_tombstone`,
				in.TenantID, in.SnapshotID, e.FilePath, e.FileSize, e.FileMtime,
				e.FileBlake3, hashes, e.IsTombstone,
			); err != nil {
				return domain.Internal("backup_file_index_insert_failed", "failed to insert file index entry").WithCause(err)
			}
		}

		// 2. DB-inline path: record the DB-dump manifest (chunk upsert + refcount +
		//    manifest insert + completion) inside this same tx. Mirrors
		//    RecordManifest's body so completion stays atomic with the file-index
		//    rows above.
		if in.DBManifest != nil {
			var totalSize int64
			referenced := map[string]struct{}{}
			for hash, up := range in.DBManifest.Chunks {
				_, getErr := q.GetBackupChunk(ctx, sqlc.GetBackupChunkParams{TenantID: in.TenantID, Blake3: hash})
				existed := getErr == nil
				if getErr != nil && !errors.Is(getErr, pgx.ErrNoRows) {
					return domain.Internal("backup_chunk_get_failed", "failed to check chunk existence").WithCause(getErr)
				}
				if _, err := q.UpsertBackupChunk(ctx, sqlc.UpsertBackupChunkParams{
					TenantID: in.TenantID, Blake3: hash, S3Key: up.S3Key, Size: up.Size,
				}); err != nil {
					return domain.Internal("backup_chunk_upsert_failed", "failed to upsert chunk").WithCause(err)
				}
				if !existed {
					storedCount++
				}
			}
			for _, e := range in.DBManifest.Entries {
				if _, err := q.CreateManifestEntry(ctx, sqlc.CreateManifestEntryParams{
					SnapshotID:  in.SnapshotID,
					TenantID:    in.TenantID,
					Path:        e.Path,
					EntryKind:   e.EntryKind,
					TableName:   e.TableName,
					ChunkHashes: e.ChunkHashes,
					Size:        e.Size,
					Mode:        e.Mode,
				}); err != nil {
					return domain.Internal("backup_manifest_insert_failed", "failed to insert manifest entry").WithCause(err)
				}
				totalSize += e.Size
				for _, h := range e.ChunkHashes {
					if _, err := q.IncrementChunkRefcount(ctx, sqlc.IncrementChunkRefcountParams{TenantID: in.TenantID, Blake3: h}); err != nil {
						return domain.Internal("backup_chunk_incref_failed", "failed to increment chunk refcount").WithCause(err)
					}
					referenced[h] = struct{}{}
					chunkRefs++
				}
			}
			// ADR-050 belt: refresh last_referenced_at for the DB-dump's chunks at
			// completion (see RecordManifest). The carry-forward file chunks are kept
			// fresh by the presign-time touch in ExistingChunkHashes.
			if terr := touchReferencedChunks(ctx, tx, in.TenantID, referenced); terr != nil {
				return terr
			}
			if _, err := q.CompleteBackupSnapshot(ctx, sqlc.CompleteBackupSnapshotParams{
				ID: in.SnapshotID, TenantID: in.TenantID, TotalSize: totalSize, ChunkCount: chunkRefs,
			}); err != nil {
				if errors.Is(err, pgx.ErrNoRows) {
					return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
				}
				return domain.Internal("backup_snapshot_complete_failed", "failed to complete snapshot").WithCause(err)
			}
			return nil
		}

		// 3. Files-only path: complete the snapshot directly with caller-supplied
		//    counters.
		if _, err := q.CompleteBackupSnapshot(ctx, sqlc.CompleteBackupSnapshotParams{
			ID: in.SnapshotID, TenantID: in.TenantID, TotalSize: in.TotalSize, ChunkCount: in.ChunkRefs,
		}); err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_complete_failed", "failed to complete snapshot").WithCause(err)
		}
		chunkRefs = in.ChunkRefs
		return nil
	})
	return chunkRefs, storedCount, err
}

func toSnapshot(s sqlc.BackupSnapshot) Snapshot {
	out := Snapshot{
		ID:           s.ID,
		TenantID:     s.TenantID,
		SiteID:       s.SiteID,
		Kind:         s.Kind,
		Status:       s.Status,
		AgeRecipient: s.AgeRecipient,
		TotalSize:    s.TotalSize,
		ChunkCount:   s.ChunkCount,
		Error:        s.Error,
		Archived:     s.Archived,
		CreatedAt:    s.CreatedAt,
		UpdatedAt:    s.UpdatedAt,
	}
	if s.CreatedBy.Valid {
		id := uuid.UUID(s.CreatedBy.Bytes)
		out.CreatedBy = &id
	}
	if s.StartedAt.Valid {
		t := s.StartedAt.Time
		out.StartedAt = &t
	}
	if s.FinishedAt.Valid {
		t := s.FinishedAt.Time
		out.FinishedAt = &t
	}
	out.Progress = s.Progress
	if s.ProgressUpdatedAt.Valid {
		t := s.ProgressUpdatedAt.Time
		out.ProgressUpdatedAt = &t
	}
	return out
}

func toManifestEntry(m sqlc.BackupManifestEntry) ManifestEntry {
	hashes := m.ChunkHashes
	if hashes == nil {
		hashes = []string{}
	}
	return ManifestEntry{
		ID:          m.ID,
		SnapshotID:  m.SnapshotID,
		TenantID:    m.TenantID,
		Path:        m.Path,
		EntryKind:   m.EntryKind,
		TableName:   m.TableName,
		ChunkHashes: hashes,
		Size:        m.Size,
		Mode:        m.Mode,
		CreatedAt:   m.CreatedAt,
	}
}

func toChunk(c sqlc.BackupChunk) Chunk {
	return Chunk{
		ID:        c.ID,
		TenantID:  c.TenantID,
		Blake3:    c.Blake3,
		S3Key:     c.S3Key,
		Size:      c.Size,
		Refcount:  c.Refcount,
		CreatedAt: c.CreatedAt,
		UpdatedAt: c.UpdatedAt,
	}
}

func toSchedule(s sqlc.BackupSchedule) Schedule {
	out := Schedule{
		ID:                 s.ID,
		TenantID:           s.TenantID,
		SiteID:             s.SiteID,
		Cadence:            s.Cadence,
		Kind:               s.Kind,
		Enabled:            s.Enabled,
		RetentionDays:      s.RetentionDays,
		MonthlyArchiveKeep: s.MonthlyArchiveKeep,
		RunHour:            int32(s.RunHour),
		RunMinute:          int32(s.RunMinute),
		KeepLast:           s.KeepLast,
		IncrementalEnabled: s.IncrementalEnabled,
		BaseWindowDays:     s.BaseWindowDays,
		NextRunAt:          s.NextRunAt,
		CreatedAt:          s.CreatedAt,
		UpdatedAt:          s.UpdatedAt,
	}
	if s.DayOfWeek != nil {
		v := int32(*s.DayOfWeek)
		out.DayOfWeek = &v
	}
	if s.DayOfMonth != nil {
		v := int32(*s.DayOfMonth)
		out.DayOfMonth = &v
	}
	if s.FrequencyHours != nil {
		v := int32(*s.FrequencyHours)
		out.FrequencyHours = &v
	}
	if s.LastRunAt.Valid {
		t := s.LastRunAt.Time
		out.LastRunAt = &t
	}
	return out
}

// rowScanner is a minimal interface satisfied by pgx.Row and pgx.Rows.Scan.
type rowScanner interface {
	Scan(dest ...any) error
}

// snapshotSelectColumns is the canonical projection for a full backup_snapshots
// row, including the ADR-048/050 chain columns (is_incremental … chain_id,
// generation) and the cycle counter columns. The sqlc-generated SELECT *
// queries expand to the pre-m44 column list and so omit these; raw reads that
// must surface incremental metadata use this constant paired with
// scanSnapshotWithChainFields. The column order MUST match that scan helper's
// Scan() argument order exactly.
const snapshotSelectColumns = `SELECT id, tenant_id, site_id, created_by, kind, status, age_recipient,
        total_size, chunk_count, error, archived, progress, progress_updated_at,
        started_at, finished_at, created_at, updated_at,
        is_incremental, parent_snapshot_id, base_snapshot_id, chain_id, generation,
        cycle_files_scanned, cycle_files_changed, cycle_files_deleted, cycle_bytes_uploaded`

// scanSnapshotWithChainFields scans a row that includes the ADR-048 chain
// columns (is_incremental … cycle_bytes_uploaded). The SELECT must project all
// standard snapshot columns plus the four chain UUID columns and the four cycle
// counter columns in the exact order listed here.
func scanSnapshotWithChainFields(row rowScanner) (Snapshot, error) {
	var (
		s               Snapshot
		createdBy       pgtype.UUID
		startedAt       pgtype.Timestamptz
		finishedAt      pgtype.Timestamptz
		progressUpdated pgtype.Timestamptz
		parentID        pgtype.UUID
		baseID          pgtype.UUID
		chainID         pgtype.UUID
	)
	err := row.Scan(
		&s.ID, &s.TenantID, &s.SiteID, &createdBy, &s.Kind, &s.Status,
		&s.AgeRecipient, &s.TotalSize, &s.ChunkCount, &s.Error, &s.Archived,
		&s.Progress, &progressUpdated, &startedAt, &finishedAt,
		&s.CreatedAt, &s.UpdatedAt,
		&s.IsIncremental, &parentID, &baseID, &chainID, &s.Generation,
		&s.CycleFilesScanned, &s.CycleFilesChanged, &s.CycleFilesDeleted, &s.CycleBytesUploaded,
	)
	if err != nil {
		return Snapshot{}, err
	}
	if createdBy.Valid {
		id := uuid.UUID(createdBy.Bytes)
		s.CreatedBy = &id
	}
	if startedAt.Valid {
		t := startedAt.Time
		s.StartedAt = &t
	}
	if finishedAt.Valid {
		t := finishedAt.Time
		s.FinishedAt = &t
	}
	if progressUpdated.Valid {
		t := progressUpdated.Time
		s.ProgressUpdatedAt = &t
	}
	if parentID.Valid {
		id := uuid.UUID(parentID.Bytes)
		s.ParentSnapshotID = &id
	}
	if baseID.Valid {
		id := uuid.UUID(baseID.Bytes)
		s.BaseSnapshotID = &id
	}
	if chainID.Valid {
		id := uuid.UUID(chainID.Bytes)
		s.ChainID = &id
	}
	return s, nil
}

// ----------------------------------------------------------------------------
// ADR-048 file index repo implementations
// ----------------------------------------------------------------------------

func (r *pgRepo) InsertFileIndexBatch(ctx context.Context, tenantID, snapshotID uuid.UUID, entries []FileIndexEntry) error {
	if len(entries) == 0 {
		return nil
	}
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		for _, e := range entries {
			hashes := e.ChunkHashes
			if hashes == nil {
				hashes = []string{}
			}
			_, err := tx.Exec(ctx,
				`INSERT INTO backup_file_index
				   (tenant_id, snapshot_id, file_path, file_size, file_mtime,
				    file_blake3, chunk_hashes, is_tombstone)
				 VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
				 ON CONFLICT (snapshot_id, file_path) DO UPDATE
				   SET file_size    = EXCLUDED.file_size,
				       file_mtime   = EXCLUDED.file_mtime,
				       file_blake3  = EXCLUDED.file_blake3,
				       chunk_hashes = EXCLUDED.chunk_hashes,
				       is_tombstone = EXCLUDED.is_tombstone`,
				tenantID, snapshotID, e.FilePath, e.FileSize, e.FileMtime,
				e.FileBlake3, hashes, e.IsTombstone,
			)
			if err != nil {
				return domain.Internal("backup_file_index_insert_failed", "failed to insert file index entry").WithCause(err)
			}
		}
		return nil
	})
}

func (r *pgRepo) CountFileIndex(ctx context.Context, tenantID, snapshotID uuid.UUID) (int64, error) {
	var count int64
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT count(*) FROM backup_file_index WHERE tenant_id=$1 AND snapshot_id=$2`,
			tenantID, snapshotID,
		)
		return row.Scan(&count)
	})
	if err != nil {
		return 0, domain.Internal("backup_file_index_count_failed", "failed to count file index").WithCause(err)
	}
	return count, nil
}

func (r *pgRepo) StreamFileIndex(ctx context.Context, tenantID, snapshotID uuid.UUID, fn func(FileIndexEntry) error) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			`SELECT id, tenant_id, snapshot_id, file_path, file_size, file_mtime,
			        file_blake3, chunk_hashes, is_tombstone, created_at
			   FROM backup_file_index
			  WHERE tenant_id=$1 AND snapshot_id=$2
			  ORDER BY file_path ASC`,
			tenantID, snapshotID,
		)
		if err != nil {
			return domain.Internal("backup_file_index_stream_failed", "failed to stream file index").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			var e FileIndexEntry
			var hashes []string
			if serr := rows.Scan(
				&e.ID, &e.TenantID, &e.SnapshotID, &e.FilePath, &e.FileSize,
				&e.FileMtime, &e.FileBlake3, &hashes, &e.IsTombstone, &e.CreatedAt,
			); serr != nil {
				return domain.Internal("backup_file_index_scan_failed", "failed to scan file index row").WithCause(serr)
			}
			if hashes == nil {
				hashes = []string{}
			}
			e.ChunkHashes = hashes
			if ferr := fn(e); ferr != nil {
				return ferr
			}
		}
		return rows.Err()
	})
}

func (r *pgRepo) UpdateSnapshotCycleStats(ctx context.Context, tenantID, snapshotID uuid.UUID, in CycleStatsInput) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		_, err := tx.Exec(ctx,
			`UPDATE backup_snapshots
			    SET cycle_files_scanned=$3, cycle_files_changed=$4,
			        cycle_files_deleted=$5, cycle_bytes_uploaded=$6,
			        updated_at=now()
			  WHERE id=$1 AND tenant_id=$2`,
			snapshotID, tenantID,
			in.CycleFilesScanned, in.CycleFilesChanged,
			in.CycleFilesDeleted, in.CycleBytesUploaded,
		)
		if err != nil {
			return domain.Internal("backup_snapshot_cycle_stats_failed", "failed to update cycle stats").WithCause(err)
		}
		return nil
	})
}

// ----------------------------------------------------------------------------
// ADR-049 incremental restore chain planner repo methods
// ----------------------------------------------------------------------------

// ListChainSnapshots returns all snapshots for (tenantID, chainID) with
// generation <= maxGeneration, ordered by generation ASC. The base snapshot
// (generation 0) has chain_id = its own ID, so it is included when chainID
// matches. This uses the raw-SQL path (not sqlc) because the result columns
// include the ADR-048 chain fields that the generated queries do not select.
func (r *pgRepo) ListChainSnapshots(ctx context.Context, tenantID uuid.UUID, chainID uuid.UUID, maxGeneration int) ([]Snapshot, error) {
	var out []Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			snapshotSelectColumns+` FROM backup_snapshots
			  WHERE tenant_id = $1
			    AND chain_id  = $2
			    AND generation <= $3
			  ORDER BY generation ASC`,
			tenantID, chainID, maxGeneration,
		)
		if err != nil {
			return domain.Internal("backup_chain_snapshots_list_failed", "failed to list chain snapshots").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			s, serr := scanSnapshotWithChainFields(rows)
			if serr != nil {
				return domain.Internal("backup_chain_snapshots_scan_failed", "failed to scan chain snapshot row").WithCause(serr)
			}
			out = append(out, s)
		}
		return rows.Err()
	})
	return out, err
}
