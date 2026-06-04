// Package repo is the persistence layer for the RUCSS domain. It wraps the m36
// sqlc queries (rucss_results / rucss_jobs) and maps the generated row types
// to/from internal/rucss/model so the service/worker layers never import
// internal/db/sqlc directly.
//
// Tx discipline (mirrors internal/media/repo): operator-facing reads run under
// InTenantTx (app.tenant_id GUC); the worker's result/job writes run under
// InAgentTx (app.agent GUC) because the RUCSS computation is a background
// system actor whose tenant is known and re-asserted on every row. last_used_at
// touches run under InTenantTx (an operator/agent read promoting cache warmth).
package repo

import (
	"context"
	"errors"
	"math/big"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
)

// Repo bundles the two RUCSS tables behind one handle over the shared pool.
type Repo struct {
	pool *db.Pool
}

// NewRepo wires a Repo with the shared pgx pool.
func NewRepo(pool *db.Pool) *Repo {
	return &Repo{pool: pool}
}

// ErrNotFound is returned by GetByHash when no cached result exists.
var ErrNotFound = errors.New("rucss: result not found")

// GetByHash looks up a cached result for (site, structure_hash). The lookup is
// tenant-scoped (the row carries tenant_id and RLS gates it). Returns
// ErrNotFound when absent. last_used_at is NOT touched here — call TouchLastUsed
// explicitly on a cache hit.
func (r *Repo) GetByHash(ctx context.Context, tenantID, siteID uuid.UUID, structureHash string) (model.Result, error) {
	var out model.Result
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetRucssResultByHash(ctx, sqlc.GetRucssResultByHashParams{
			SiteID:        siteID,
			StructureHash: structureHash,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = resultFromRow(row)
		return nil
	})
	if err != nil {
		return model.Result{}, err
	}
	return out, nil
}

// UpsertInput is the data written by the worker after a successful purge.
type UpsertInput struct {
	TenantID         uuid.UUID
	SiteID           uuid.UUID
	StructureHash    string
	URL              string
	OriginalCSSBytes int
	UsedCSSBytes     int
	ReductionPct     float64
	UsedCSSS3Key     string
	SelectorsTotal   int
	SelectorsKept    int
	SelectorsDropped int
	ComputeMs        int
}

// Upsert inserts-or-updates a cached result. Runs under InAgentTx: the worker is
// a cross-tenant system actor and the row's tenant_id is re-asserted from the
// verified job args.
func (r *Repo) Upsert(ctx context.Context, in UpsertInput) (model.Result, error) {
	var out model.Result
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpsertRucssResult(ctx, sqlc.UpsertRucssResultParams{
			TenantID:         in.TenantID,
			SiteID:           in.SiteID,
			StructureHash:    in.StructureHash,
			Url:              strPtr(in.URL),
			OriginalCssBytes: i32Ptr(in.OriginalCSSBytes),
			UsedCssBytes:     i32Ptr(in.UsedCSSBytes),
			ReductionPct:     numericFromFloat(in.ReductionPct),
			UsedCssS3Key:     in.UsedCSSS3Key,
			SelectorsTotal:   i32Ptr(in.SelectorsTotal),
			SelectorsKept:    i32Ptr(in.SelectorsKept),
			SelectorsDropped: i32Ptr(in.SelectorsDropped),
			ComputeMs:        i32Ptr(in.ComputeMs),
		})
		if qerr != nil {
			return qerr
		}
		out = resultFromRow(row)
		return nil
	})
	if err != nil {
		return model.Result{}, err
	}
	return out, nil
}

// TouchLastUsed bumps last_used_at on a cache hit (LRU eviction signal). Runs
// under InTenantTx — the toucher is the operator/agent read path.
func (r *Repo) TouchLastUsed(ctx context.Context, tenantID, siteID uuid.UUID, structureHash string) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		return sqlc.New(tx).TouchRucssResultLastUsed(ctx, sqlc.TouchRucssResultLastUsedParams{
			SiteID:        siteID,
			StructureHash: structureHash,
		})
	})
}

// InsertJob creates a queued job row. Runs under InAgentTx (system actor).
func (r *Repo) InsertJob(ctx context.Context, j model.Job) (model.Job, error) {
	var out model.Job
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).InsertRucssJob(ctx, sqlc.InsertRucssJobParams{
			ID:            j.ID,
			TenantID:      j.TenantID,
			SiteID:        j.SiteID,
			StructureHash: strPtr(j.StructureHash),
			Url:           strPtr(j.URL),
		})
		if qerr != nil {
			return qerr
		}
		out = jobFromRow(row)
		return nil
	})
	if err != nil {
		return model.Job{}, err
	}
	return out, nil
}

// UpdateJobStateInput advances a job's lifecycle.
type UpdateJobStateInput struct {
	TenantID    uuid.UUID
	JobID       string
	State       model.JobState
	ErrorReason string
	ResultID    uuid.UUID // uuid.Nil leaves result_id unset
	Done        bool      // when true, completed_at is stamped now()
}

// UpdateJobState advances the job lifecycle (queued->running->done|failed). Runs
// under InAgentTx (the worker writes it). Returns the updated job.
func (r *Repo) UpdateJobState(ctx context.Context, in UpdateJobStateInput) (model.Job, error) {
	var out model.Job
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).UpdateRucssJobState(ctx, sqlc.UpdateRucssJobStateParams{
			State:       string(in.State),
			ErrorReason: strPtr(in.ErrorReason),
			ResultID:    uuidToPg(in.ResultID),
			Done:        in.Done,
			ID:          in.JobID,
			TenantID:    in.TenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = jobFromRow(row)
		return nil
	})
	if err != nil {
		return model.Job{}, err
	}
	return out, nil
}

// MarkRunning transitions a job to 'running'. Convenience over UpdateJobState
// for the worker's lifecycle calls.
func (r *Repo) MarkRunning(ctx context.Context, tenantID uuid.UUID, jobID string) error {
	_, err := r.UpdateJobState(ctx, UpdateJobStateInput{
		TenantID: tenantID,
		JobID:    jobID,
		State:    model.JobStateRunning,
	})
	return err
}

// MarkDone transitions a job to 'done', attaching its result id and stamping
// completed_at.
func (r *Repo) MarkDone(ctx context.Context, tenantID uuid.UUID, jobID string, resultID uuid.UUID) error {
	_, err := r.UpdateJobState(ctx, UpdateJobStateInput{
		TenantID: tenantID,
		JobID:    jobID,
		State:    model.JobStateDone,
		ResultID: resultID,
		Done:     true,
	})
	return err
}

// MarkFailed transitions a job to 'failed' with an error reason and stamps
// completed_at.
func (r *Repo) MarkFailed(ctx context.Context, tenantID uuid.UUID, jobID, reason string) error {
	_, err := r.UpdateJobState(ctx, UpdateJobStateInput{
		TenantID:    tenantID,
		JobID:       jobID,
		State:       model.JobStateFailed,
		ErrorReason: reason,
		Done:        true,
	})
	return err
}

// GetJob fetches one job by id (tenant-scoped). Returns ErrNotFound when absent.
func (r *Repo) GetJob(ctx context.Context, tenantID uuid.UUID, jobID string) (model.Job, error) {
	var out model.Job
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).GetRucssJob(ctx, sqlc.GetRucssJobParams{
			ID:       jobID,
			TenantID: tenantID,
		})
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return qerr
		}
		out = jobFromRow(row)
		return nil
	})
	if err != nil {
		return model.Job{}, err
	}
	return out, nil
}

// ListForSite returns a page of cached results for a site, most-recently-used
// first. Runs under InTenantTx (operator read).
func (r *Repo) ListForSite(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]model.Result, error) {
	if limit <= 0 {
		limit = 50
	}
	var out []model.Result
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, qerr := sqlc.New(tx).ListRucssResultsForSite(ctx, sqlc.ListRucssResultsForSiteParams{
			TenantID:  tenantID,
			SiteID:    siteID,
			RowOffset: offset,
			RowLimit:  limit,
		})
		if qerr != nil {
			return qerr
		}
		out = make([]model.Result, 0, len(rows))
		for _, row := range rows {
			out = append(out, resultFromRow(row))
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

// DeleteForSite removes ALL cached RUCSS results for a site and returns the
// number of rows deleted. Runs under InTenantTx: the operator's active tenant
// scopes the delete and the rucss_results_tenant_isolation RLS policy enforces
// tenant_id = app.tenant_id; the explicit tenant_id predicate below is
// belt-and-braces in front of that policy. After this, the next render whose
// structure_hash matched a deleted row misses the cache (GetByHash ->
// ErrNotFound), so the agent re-serves full CSS and re-triggers a compute —
// which is exactly the "clear results so I can re-run the pipeline" semantics.
// A raw scoped DELETE is used (rather than a sqlc query) because it is a single
// trivial tenant+site predicate and avoids a codegen roundtrip. The freed
// used_css_s3_key objects are deterministic per (site, structure_hash) and are
// overwritten on recompute, so they are intentionally not purged here.
func (r *Repo) DeleteForSite(ctx context.Context, tenantID, siteID uuid.UUID) (int, error) {
	var deleted int
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		tag, derr := tx.Exec(ctx,
			`DELETE FROM rucss_results WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID)
		if derr != nil {
			return derr
		}
		deleted = int(tag.RowsAffected())
		return nil
	})
	if err != nil {
		return 0, err
	}
	return deleted, nil
}

// ---------------------------------------------------------------------------
// row <-> model mapping
// ---------------------------------------------------------------------------

func resultFromRow(row sqlc.RucssResult) model.Result {
	return model.Result{
		ID:               row.ID,
		TenantID:         row.TenantID,
		SiteID:           row.SiteID,
		StructureHash:    row.StructureHash,
		URL:              derefStr(row.Url),
		OriginalCSSBytes: derefI32(row.OriginalCssBytes),
		UsedCSSBytes:     derefI32(row.UsedCssBytes),
		ReductionPct:     floatFromNumeric(row.ReductionPct),
		UsedCSSS3Key:     row.UsedCssS3Key,
		SelectorsTotal:   derefI32(row.SelectorsTotal),
		SelectorsKept:    derefI32(row.SelectorsKept),
		SelectorsDropped: derefI32(row.SelectorsDropped),
		ComputeMs:        derefI32(row.ComputeMs),
		CreatedAt:        row.CreatedAt,
		LastUsedAt:       row.LastUsedAt,
	}
}

func jobFromRow(row sqlc.RucssJob) model.Job {
	j := model.Job{
		ID:            row.ID,
		TenantID:      row.TenantID,
		SiteID:        row.SiteID,
		StructureHash: derefStr(row.StructureHash),
		URL:           derefStr(row.Url),
		State:         model.JobState(row.State),
		ErrorReason:   derefStr(row.ErrorReason),
	}
	if row.ResultID.Valid {
		j.ResultID = row.ResultID.Bytes
	}
	j.CreatedAt = row.CreatedAt
	if row.CompletedAt.Valid {
		t := row.CompletedAt.Time
		j.CompletedAt = &t
	}
	return j
}

// ---------------------------------------------------------------------------
// pgtype / pointer helpers
// ---------------------------------------------------------------------------

func strPtr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func derefStr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

func i32Ptr(v int) *int32 {
	x := int32(v)
	return &x
}

func derefI32(p *int32) int {
	if p == nil {
		return 0
	}
	return int(*p)
}

func uuidToPg(id uuid.UUID) pgtype.UUID {
	return pgtype.UUID{Bytes: id, Valid: id != uuid.Nil}
}

// numericFromFloat converts a percentage (e.g. 57.46) into a pgtype.Numeric.
// reduction_pct is NUMERIC(5,2) in the schema; we round to 2 decimals by
// scaling to an integer mantissa with exponent -2.
func numericFromFloat(f float64) pgtype.Numeric {
	if f < 0 {
		f = 0
	}
	// Scale to 2 decimal places: 57.46 -> mantissa 5746, exp -2.
	mant := big.NewInt(int64(f*100 + 0.5))
	return pgtype.Numeric{Int: mant, Exp: -2, Valid: true}
}

// floatFromNumeric converts a pgtype.Numeric back to a float64 for the view
// model. An invalid (NULL) numeric maps to 0.
func floatFromNumeric(n pgtype.Numeric) float64 {
	if !n.Valid || n.Int == nil {
		return 0
	}
	f := new(big.Float).SetInt(n.Int)
	// apply exponent: value = Int * 10^Exp
	scale := new(big.Float).SetFloat64(pow10(int(n.Exp)))
	f.Mul(f, scale)
	out, _ := f.Float64()
	return out
}

func pow10(exp int) float64 {
	v := 1.0
	if exp >= 0 {
		for i := 0; i < exp; i++ {
			v *= 10
		}
		return v
	}
	for i := 0; i < -exp; i++ {
		v /= 10
	}
	return v
}
