package perf

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
)

// GetFontTranscodeResult returns the current font_transcode_results row for
// (sourceHash, tenantID). Returns ErrNotFound when no row exists yet.
// Operator read path (InTenantTx).
func (r *Repo) GetFontTranscodeResult(ctx context.Context, tenantID uuid.UUID, sourceHash string) (FontTranscodeResult, error) {
	var out FontTranscodeResult
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx, `
			SELECT source_hash, tenant_id, site_id, woff2_key, negative, error_detail
			  FROM font_transcode_results
			 WHERE source_hash = $1 AND tenant_id = $2
		`, sourceHash, tenantID)
		var woff2Key *string
		var errorDetail *string
		var negative bool
		if serr := row.Scan(&out.SourceHash, &out.TenantID, &out.SiteID, &woff2Key, &negative, &errorDetail); serr != nil {
			if errors.Is(serr, pgx.ErrNoRows) {
				return ErrNotFound
			}
			return serr
		}
		if woff2Key != nil {
			out.Woff2Key = *woff2Key
		}
		if errorDetail != nil {
			out.ErrorDetail = *errorDetail
		}
		if negative {
			out.State = FontTranscodeNegative
		} else {
			out.State = fontTranscodeState(out.Woff2Key)
		}
		return nil
	})
	if err != nil {
		return FontTranscodeResult{}, err
	}
	return out, nil
}

// UpsertFontTranscodeJob records a pending transcode job. It inserts the row if
// absent (state=pending, river_job_id set) or, for an existing pending row,
// updates the river_job_id. If the row is already ready or negative it is
// left untouched and the current state is returned. Runs under InAgentTx.
func (r *Repo) UpsertFontTranscodeJob(ctx context.Context, tenantID, siteID uuid.UUID, sourceHash string, riverJobID int64) (FontTranscodeResult, error) {
	var out FontTranscodeResult
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx, `
			INSERT INTO font_transcode_results (source_hash, tenant_id, site_id, river_job_id, updated_at)
			VALUES ($1, $2, $3, $4, now())
			ON CONFLICT (source_hash, tenant_id) DO UPDATE
			    SET river_job_id = EXCLUDED.river_job_id,
			        updated_at   = now()
			  WHERE font_transcode_results.woff2_key  IS NULL
			    AND font_transcode_results.negative    = false
			RETURNING source_hash, tenant_id, site_id, woff2_key, negative, error_detail
		`, sourceHash, tenantID, siteID, riverJobID)
		return scanFontResult(row, &out)
	})
	if err != nil {
		return FontTranscodeResult{}, err
	}
	return out, nil
}

// MarkFontTranscodeReady records a successful WOFF2 output. Called by the
// font_transcode River worker on completion. Runs under InAgentTx.
func (r *Repo) MarkFontTranscodeReady(ctx context.Context, tenantID uuid.UUID, sourceHash, woff2Key string) error {
	return r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		_, err := tx.Exec(ctx, `
			UPDATE font_transcode_results
			   SET woff2_key    = $1,
			       river_job_id = NULL,
			       updated_at   = now()
			 WHERE source_hash = $2 AND tenant_id = $3
		`, woff2Key, sourceHash, tenantID)
		return err
	})
}

// MarkFontTranscodeNegative records a permanent failure. The agent will serve
// the original font indefinitely; the CP never re-enqueues this hash.
// Runs under InAgentTx.
func (r *Repo) MarkFontTranscodeNegative(ctx context.Context, tenantID uuid.UUID, sourceHash, errorDetail string) error {
	return r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		_, err := tx.Exec(ctx, `
			UPDATE font_transcode_results
			   SET negative     = true,
			       error_detail = $1,
			       river_job_id = NULL,
			       updated_at   = now()
			 WHERE source_hash = $2 AND tenant_id = $3
		`, errorDetail, sourceHash, tenantID)
		return err
	})
}

// ListPendingFontTranscodeJobs returns (sourceHash, riverJobID) pairs where
// river_job_id IS NOT NULL and no result is recorded yet. Used by the watchdog
// to detect stalled jobs. Cross-tenant (InAgentTx).
func (r *Repo) ListPendingFontTranscodeJobs(ctx context.Context, olderThan time.Duration) ([]PendingFontJob, error) {
	var out []PendingFontJob
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, qerr := tx.Query(ctx, `
			SELECT source_hash, tenant_id, site_id, river_job_id
			  FROM font_transcode_results
			 WHERE river_job_id IS NOT NULL
			   AND woff2_key    IS NULL
			   AND negative     = false
			   AND updated_at   < now() - $1::interval
		`, durationToInterval(olderThan))
		if qerr != nil {
			return qerr
		}
		defer rows.Close()
		for rows.Next() {
			var j PendingFontJob
			if serr := rows.Scan(&j.SourceHash, &j.TenantID, &j.SiteID, &j.RiverJobID); serr != nil {
				return serr
			}
			out = append(out, j)
		}
		return rows.Err()
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

// PendingFontJob is the minimal view returned by ListPendingFontTranscodeJobs.
type PendingFontJob struct {
	SourceHash string
	TenantID   uuid.UUID
	SiteID     uuid.UUID
	RiverJobID int64
}

// CountTodayFontTranscodeEnqueues returns the number of font_transcode_results
// rows created for the given tenant today (UTC calendar day). This is used by
// the handler to enforce the daily per-tenant enqueue cap before inserting a
// new job. Runs under InTenantTx.
func (r *Repo) CountTodayFontTranscodeEnqueues(ctx context.Context, tenantID uuid.UUID) (int, error) {
	var n int
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx, `
			SELECT count(*)
			  FROM font_transcode_results
			 WHERE tenant_id  = $1
			   AND created_at >= date_trunc('day', now() AT TIME ZONE 'UTC')
		`, tenantID)
		return row.Scan(&n)
	})
	if err != nil {
		return 0, err
	}
	return n, nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func scanFontResult(row pgx.Row, out *FontTranscodeResult) error {
	var woff2Key *string
	var negative bool
	var errorDetail *string
	if err := row.Scan(&out.SourceHash, &out.TenantID, &out.SiteID, &woff2Key, &negative, &errorDetail); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		}
		return err
	}
	if woff2Key != nil {
		out.Woff2Key = *woff2Key
	}
	if errorDetail != nil {
		out.ErrorDetail = *errorDetail
	}
	if negative {
		out.State = FontTranscodeNegative
	} else {
		out.State = fontTranscodeState(out.Woff2Key)
	}
	return nil
}

func fontTranscodeState(woff2Key string) FontTranscodeState {
	if woff2Key != "" {
		return FontTranscodeReady
	}
	return FontTranscodePending
}
