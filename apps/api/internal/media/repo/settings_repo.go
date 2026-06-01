package repo

import (
	"context"
	"errors"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
)

const settingsCols = `tenant_id, site_id, auto_optimize_enabled,
	auto_target_format, auto_target_quality, created_at, updated_at`

func settingsFromRow(row pgx.Row) (model.MediaSettings, error) {
	var s model.MediaSettings
	if err := row.Scan(
		&s.TenantID, &s.SiteID, &s.AutoOptimizeEnabled,
		&s.AutoTargetFormat, &s.AutoTargetQuality,
		&s.CreatedAt, &s.UpdatedAt,
	); err != nil {
		return model.MediaSettings{}, err
	}
	return s, nil
}

// GetMediaSettings returns the stored media settings for a site (tenant-scoped,
// operator path). Returns (zero, false, nil) when no row exists yet so the
// caller can surface the defaults without an error.
func (r *Repo) GetMediaSettings(ctx context.Context, tenantID, siteID uuid.UUID) (model.MediaSettings, bool, error) {
	var out model.MediaSettings
	var found bool
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT `+settingsCols+`
			 FROM site_media_settings
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID)
		s, err := settingsFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil // not found — caller gets default
		}
		if err != nil {
			return domain.Internal("media_settings_get_failed", "failed to get media settings").WithCause(err)
		}
		out = s
		found = true
		return nil
	})
	return out, found, err
}

// UpsertMediaSettingsInput is the operator-supplied fields for the PUT /media/settings
// endpoint. All three fields are required (validated at the service/handler layer).
type UpsertMediaSettingsInput struct {
	AutoOptimizeEnabled bool
	AutoTargetFormat    string
	AutoTargetQuality   string
}

// UpsertMediaSettings inserts or updates the per-site media settings row under
// the tenant GUC. updated_at is always refreshed. Returns the stored row.
func (r *Repo) UpsertMediaSettings(ctx context.Context, tenantID, siteID uuid.UUID, in UpsertMediaSettingsInput) (model.MediaSettings, error) {
	var out model.MediaSettings
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`INSERT INTO site_media_settings
				(tenant_id, site_id, auto_optimize_enabled, auto_target_format,
				 auto_target_quality, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, $5, now(), now())
			 ON CONFLICT (site_id) DO UPDATE
			   SET auto_optimize_enabled = EXCLUDED.auto_optimize_enabled,
			       auto_target_format    = EXCLUDED.auto_target_format,
			       auto_target_quality   = EXCLUDED.auto_target_quality,
			       updated_at            = now()
			 RETURNING `+settingsCols,
			tenantID, siteID, in.AutoOptimizeEnabled, in.AutoTargetFormat, in.AutoTargetQuality)
		s, err := settingsFromRow(row)
		if err != nil {
			return domain.Internal("media_settings_upsert_failed", "failed to upsert media settings").WithCause(err)
		}
		out = s
		return nil
	})
	return out, err
}

// GetMediaSettingsAgent returns the stored media settings for a site under the
// agent GUC (InAgentTx). Used by HandleAutoOptimize for the defense-in-depth
// re-check that auto_optimize_enabled is actually on before queuing jobs.
// Returns (zero, false, nil) when no row exists.
func (r *Repo) GetMediaSettingsAgent(ctx context.Context, tenantID, siteID uuid.UUID) (model.MediaSettings, bool, error) {
	var out model.MediaSettings
	var found bool
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT `+settingsCols+`
			 FROM site_media_settings
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID)
		s, err := settingsFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return domain.Internal("media_settings_get_agent_failed", "failed to get media settings (agent)").WithCause(err)
		}
		out = s
		found = true
		return nil
	})
	return out, found, err
}
