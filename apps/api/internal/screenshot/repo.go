package screenshot

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

// Repo is the persistence interface for site screenshots.
type Repo interface {
	// Get returns the screenshot row for the given tenant-scoped site.
	// Returns domain.NotFound when the row does not exist.
	Get(ctx context.Context, tenantID, siteID uuid.UUID) (Screenshot, error)

	// MarkPending upserts a pending row (operator-path, InTenantTx).
	MarkPending(ctx context.Context, tenantID, siteID uuid.UUID) (Screenshot, error)

	// MarkReady records a successful capture (worker-path, InAgentTx).
	MarkReady(ctx context.Context, in MarkReadyInput) (Screenshot, error)

	// MarkFailed records a failed capture (worker-path, InAgentTx).
	MarkFailed(ctx context.Context, tenantID, siteID uuid.UUID, reason string) (Screenshot, error)

	// ListForSites batch-fetches screenshots for the given site IDs under a
	// single tenant (operator-path, InTenantTx). Used by repo.List enrichment.
	ListForSites(ctx context.Context, tenantID uuid.UUID, siteIDs []uuid.UUID) ([]Screenshot, error)
}

// MarkReadyInput carries the fields for a successful capture write.
type MarkReadyInput struct {
	SiteID          uuid.UUID
	TenantID        uuid.UUID
	ScreenshotKey   string
	ScreenshotKey2x string
	Width           int32
	Height          int32
	CapturedAt      time.Time
	Etag            *string
}

type pgRepo struct {
	pool *db.Pool
}

// NewRepo builds a Repo backed by the pgx pool.
func NewRepo(pool *db.Pool) Repo {
	return &pgRepo{pool: pool}
}

func (r *pgRepo) Get(ctx context.Context, tenantID, siteID uuid.UUID) (Screenshot, error) {
	var out Screenshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetScreenshot(ctx, sqlc.GetScreenshotParams{
			SiteID:   siteID,
			TenantID: tenantID,
		})
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("screenshot_not_found", "screenshot not found")
			}
			return domain.Internal("screenshot_get_failed", "failed to load screenshot").WithCause(err)
		}
		out = toModel(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) MarkPending(ctx context.Context, tenantID, siteID uuid.UUID) (Screenshot, error) {
	var out Screenshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).UpsertScreenshotPending(ctx, sqlc.UpsertScreenshotPendingParams{
			SiteID:   siteID,
			TenantID: tenantID,
		})
		if err != nil {
			return domain.Internal("screenshot_pending_failed", "failed to mark screenshot pending").WithCause(err)
		}
		out = toModel(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) MarkReady(ctx context.Context, in MarkReadyInput) (Screenshot, error) {
	var out Screenshot
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).UpsertScreenshotReady(ctx, sqlc.UpsertScreenshotReadyParams{
			SiteID:          in.SiteID,
			TenantID:        in.TenantID,
			ScreenshotKey:   in.ScreenshotKey,
			ScreenshotKey2x: in.ScreenshotKey2x,
			Width:           in.Width,
			Height:          in.Height,
			CapturedAt:      pgTimestamptz(in.CapturedAt),
			Etag:            in.Etag,
		})
		if err != nil {
			return domain.Internal("screenshot_ready_failed", "failed to mark screenshot ready").WithCause(err)
		}
		out = toModel(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) MarkFailed(ctx context.Context, tenantID, siteID uuid.UUID, reason string) (Screenshot, error) {
	var out Screenshot
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).UpsertScreenshotFailed(ctx, sqlc.UpsertScreenshotFailedParams{
			SiteID:       siteID,
			TenantID:     tenantID,
			FailedReason: &reason,
		})
		if err != nil {
			return domain.Internal("screenshot_failed_failed", "failed to mark screenshot failed").WithCause(err)
		}
		out = toModel(row)
		return nil
	})
	return out, err
}

func (r *pgRepo) ListForSites(ctx context.Context, tenantID uuid.UUID, siteIDs []uuid.UUID) ([]Screenshot, error) {
	if len(siteIDs) == 0 {
		return nil, nil
	}
	var out []Screenshot
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListScreenshotsForSites(ctx, sqlc.ListScreenshotsForSitesParams{
			TenantID: tenantID,
			SiteIds:  siteIDs,
		})
		if err != nil {
			return domain.Internal("screenshot_list_failed", "failed to list screenshots").WithCause(err)
		}
		out = make([]Screenshot, 0, len(rows))
		for _, row := range rows {
			out = append(out, toModel(row))
		}
		return nil
	})
	return out, err
}

func pgTimestamptz(t time.Time) pgtype.Timestamptz {
	return pgtype.Timestamptz{Time: t, Valid: true}
}

func toModel(row sqlc.SiteScreenshot) Screenshot {
	m := Screenshot{
		SiteID:          row.SiteID,
		TenantID:        row.TenantID,
		ScreenshotKey:   row.ScreenshotKey,
		ScreenshotKey2x: row.ScreenshotKey2x,
		Width:           row.Width,
		Height:          row.Height,
		Status:          row.Status,
		FailedReason:    row.FailedReason,
		Etag:            row.Etag,
		CreatedAt:       row.CreatedAt,
		UpdatedAt:       row.UpdatedAt,
	}
	if row.CapturedAt.Valid {
		t := row.CapturedAt.Time
		m.CapturedAt = &t
	}
	return m
}
