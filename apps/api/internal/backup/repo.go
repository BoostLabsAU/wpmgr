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
	// DeleteSnapshotAndDecref deletes a snapshot, decrements the refcount of every
	// chunk its manifest referenced, and returns the s3 keys of chunks that
	// reached refcount zero (the caller deletes those objects from storage, then
	// calls DeleteOrphanChunks). Tenant-scoped, atomic.
	DeleteSnapshotAndDecref(ctx context.Context, tenantID, snapshotID uuid.UUID) (orphans []Orphan, err error)
	DeleteOrphanChunks(ctx context.Context, tenantID uuid.UUID, hashes []string) error

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
type SnapshotMeta struct {
	ID        uuid.UUID
	CreatedAt time.Time
	Archived  bool
}

// Orphan is a chunk that reached refcount zero during snapshot deletion.
type Orphan struct {
	Blake3 string
	S3Key  string
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

		// ADR-048: stamp incremental chain fields when this is an incremental run.
		// We do this with a raw UPDATE because the sqlc-generated CreateBackupSnapshot
		// predates the m44 columns — updating the generated code requires regenerating
		// sqlc which is out of scope for this migration. The UPDATE is within the
		// same transaction and is a no-op when all values are zero/nil/false.
		if in.IsIncremental || in.Generation > 0 || in.ParentSnapshotID != nil {
			var parentID, baseID, chainID *[16]byte
			if in.ParentSnapshotID != nil {
				b := [16]byte(*in.ParentSnapshotID)
				parentID = &b
			}
			if in.BaseSnapshotID != nil {
				b := [16]byte(*in.BaseSnapshotID)
				baseID = &b
			}
			if in.ChainID != nil {
				b := [16]byte(*in.ChainID)
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
			out.ChainID = in.ChainID
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
			`SELECT id, tenant_id, site_id, created_by, kind, status, age_recipient,
			        total_size, chunk_count, error, archived, progress, progress_updated_at,
			        started_at, finished_at, created_at, updated_at,
			        is_incremental, parent_snapshot_id, base_snapshot_id, chain_id, generation,
			        cycle_files_scanned, cycle_files_changed, cycle_files_deleted, cycle_bytes_uploaded
			   FROM backup_snapshots
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
		row, err := sqlc.New(tx).GetBackupSnapshot(ctx, sqlc.GetBackupSnapshotParams{ID: snapshotID, TenantID: tenantID})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_get_failed", "failed to load snapshot").WithCause(err)
		}
		out = toSnapshot(row)
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
		row, err := sqlc.New(tx).GetBackupSnapshot(ctx, sqlc.GetBackupSnapshotParams{ID: snapshotID, TenantID: tenantID})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("backup_snapshot_not_found", "backup snapshot not found")
			}
			return domain.Internal("backup_snapshot_get_failed", "failed to load snapshot").WithCause(err)
		}
		out = toSnapshot(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) ListSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]Snapshot, error) {
	var out []Snapshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListBackupSnapshotsForSite(ctx, sqlc.ListBackupSnapshotsForSiteParams{
			TenantID: tenantID, SiteID: siteID, Limit: limit, Offset: offset,
		})
		if err != nil {
			return domain.Internal("backup_snapshot_list_failed", "failed to list snapshots").WithCause(err)
		}
		out = make([]Snapshot, 0, len(rows))
		for _, row := range rows {
			out = append(out, toSnapshot(row))
		}
		return nil
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
				chunkRefs++
			}
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
		rows, err := sqlc.New(tx).ListBackupChunksByHashes(ctx, sqlc.ListBackupChunksByHashesParams{TenantID: tenantID, Column2: hashes})
		if err != nil {
			return domain.Internal("backup_chunk_existing_failed", "failed to query existing chunks").WithCause(err)
		}
		for _, row := range rows {
			out[row.Blake3] = toChunk(row)
		}
		return nil
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
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListCompletedSnapshotsForSite(ctx, sqlc.ListCompletedSnapshotsForSiteParams{TenantID: tenantID, SiteID: siteID})
		if err != nil {
			return domain.Internal("backup_completed_list_failed", "failed to list completed snapshots").WithCause(err)
		}
		out = make([]SnapshotMeta, 0, len(rows))
		for _, row := range rows {
			out = append(out, SnapshotMeta{ID: row.ID, CreatedAt: row.CreatedAt, Archived: row.Archived})
		}
		return nil
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

func (r *pgRepo) DeleteSnapshotAndDecref(ctx context.Context, tenantID, snapshotID uuid.UUID) ([]Orphan, error) {
	var orphans []Orphan
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		entries, err := q.ListManifestEntries(ctx, sqlc.ListManifestEntriesParams{SnapshotID: snapshotID, TenantID: tenantID})
		if err != nil {
			return domain.Internal("backup_manifest_list_failed", "failed to list manifest entries").WithCause(err)
		}
		for _, e := range entries {
			for _, h := range e.ChunkHashes {
				row, derr := q.DecrementChunkRefcount(ctx, sqlc.DecrementChunkRefcountParams{TenantID: tenantID, Blake3: h})
				if derr != nil {
					if errors.Is(derr, pgx.ErrNoRows) {
						continue // chunk already gone (concurrent GC); skip.
					}
					return domain.Internal("backup_chunk_decref_failed", "failed to decrement chunk refcount").WithCause(derr)
				}
				if row.Refcount == 0 {
					orphans = append(orphans, Orphan{Blake3: row.Blake3, S3Key: row.S3Key})
				}
			}
		}
		// Delete the snapshot (manifest entries cascade).
		if _, err := q.DeleteBackupSnapshot(ctx, sqlc.DeleteBackupSnapshotParams{ID: snapshotID, TenantID: tenantID}); err != nil {
			return domain.Internal("backup_snapshot_delete_failed", "failed to delete snapshot").WithCause(err)
		}
		return nil
	})
	return orphans, err
}

func (r *pgRepo) DeleteOrphanChunks(ctx context.Context, tenantID uuid.UUID, hashes []string) error {
	if len(hashes) == 0 {
		return nil
	}
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		for _, h := range hashes {
			if _, err := q.DeleteOrphanChunk(ctx, sqlc.DeleteOrphanChunkParams{TenantID: tenantID, Blake3: h}); err != nil {
				return domain.Internal("backup_orphan_delete_failed", "failed to delete orphan chunk").WithCause(err)
			}
		}
		return nil
	})
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
			`SELECT id, tenant_id, site_id, created_by, kind, status, age_recipient,
			        total_size, chunk_count, error, archived, progress, progress_updated_at,
			        started_at, finished_at, created_at, updated_at,
			        is_incremental, parent_snapshot_id, base_snapshot_id, chain_id, generation,
			        cycle_files_scanned, cycle_files_changed, cycle_files_deleted, cycle_bytes_uploaded
			   FROM backup_snapshots
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
