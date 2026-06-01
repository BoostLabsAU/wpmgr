package repo

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
)

const assetCols = `id, tenant_id, site_id, wp_attachment_id, title,
	original_path, original_url, original_mime, original_width, original_height,
	original_size_bytes, current_format, current_size_bytes, status, generation,
	compression_level, target_format, sizes_optimized, sizes_unoptimized,
	last_optimized_at, last_synced_at, created_at, updated_at, sync_generation`

func assetFromRow(row pgx.Row) (model.Asset, error) {
	var a model.Asset
	var width, height *int
	var compression, targetFormat *string
	var sizesOptRaw, sizesUnoptRaw []byte
	var lastOptimizedAt *time.Time
	if err := row.Scan(
		&a.ID, &a.TenantID, &a.SiteID, &a.WPAttachmentID, &a.Title,
		&a.OriginalPath, &a.OriginalURL, &a.OriginalMime, &width, &height,
		&a.OriginalSizeBytes, &a.CurrentFormat, &a.CurrentSizeBytes, &a.Status, &a.Generation,
		&compression, &targetFormat, &sizesOptRaw, &sizesUnoptRaw,
		&lastOptimizedAt, &a.LastSyncedAt, &a.CreatedAt, &a.UpdatedAt, &a.SyncGeneration,
	); err != nil {
		return model.Asset{}, err
	}
	a.OriginalWidth = width
	a.OriginalHeight = height
	if compression != nil {
		a.CompressionLevel = *compression
	}
	if targetFormat != nil {
		a.TargetFormat = *targetFormat
	}
	if len(sizesOptRaw) > 0 {
		_ = json.Unmarshal(sizesOptRaw, &a.SizesOptimized)
	}
	if len(sizesUnoptRaw) > 0 {
		_ = json.Unmarshal(sizesUnoptRaw, &a.SizesUnoptimized)
	}
	a.LastOptimizedAt = lastOptimizedAt
	return a, nil
}

// UpsertAssetInput is one attachment in an agent sync-batch.
type UpsertAssetInput struct {
	WPAttachmentID    int64
	Title             string
	OriginalPath      string
	OriginalURL       string
	OriginalMime      string
	OriginalWidth     *int
	OriginalHeight    *int
	OriginalSizeBytes int64
	// VariantCount is the image-FILE count for this attachment: 1 (full) + the
	// number of generated sub-sizes. Drives the "Images (incl. thumbnails)"
	// headline. 0 = agent did not report it (older agent); keep the existing value.
	VariantCount int
	// SavedBytes is the all-variant savings (sum over every optimized variant of
	// original-minus-optimized) the agent computes from the wpmgr_image_optimization
	// blob. Re-reported at sync so a re-sync heals already-optimized rows. 0 = not
	// optimized / not reported; keep the existing value.
	SavedBytes int64
}

// UpsertAssetsAgent upserts a batch of attachments under the agent GUC. New rows
// land in 'pending'; existing rows refresh their library metadata + last_synced_at
// but PRESERVE their optimization status/sizes (the agent's apply callback owns
// those). Every upserted row is stamped with the run's syncGen so the sync-finalize
// callback can sweep any asset NOT touched by this run (the attachment is gone in
// WP). Returns the number of rows affected. tenantID/siteID come from the verified
// Ed25519 identity (NOT a client header).
func (r *Repo) UpsertAssetsAgent(ctx context.Context, tenantID, siteID uuid.UUID, syncGen int64, rows []UpsertAssetInput) (int64, error) {
	if len(rows) == 0 {
		return 0, nil
	}
	var affected int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		for _, a := range rows {
			tag, err := tx.Exec(ctx,
				`INSERT INTO site_media_assets
					(id, tenant_id, site_id, wp_attachment_id, title, original_path,
					 original_url, original_mime, original_width, original_height,
					 original_size_bytes, current_format, current_size_bytes, status,
					 sync_generation, variant_count, saved_bytes,
					 last_synced_at, created_at, updated_at)
				 VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, $7, $8, $9, $10,
				         'original', $10, 'pending', $11, $12, $13, now(), now(), now())
				 ON CONFLICT (site_id, wp_attachment_id) DO UPDATE
				   SET title               = EXCLUDED.title,
				       original_path        = EXCLUDED.original_path,
				       original_url         = EXCLUDED.original_url,
				       original_mime        = EXCLUDED.original_mime,
				       original_width       = EXCLUDED.original_width,
				       original_height      = EXCLUDED.original_height,
				       -- original_size_bytes is the FULL image file's bytes (M26 full-file
				       -- semantic — the figure WordPress's own File-size shows, NOT a sum of
				       -- sub-sizes). EXACT-SET when the agent reports a positive value, else
				       -- keep the existing value. NOT GREATEST: GREATEST could only RAISE the
				       -- value, which permanently LATCHED an over-counted total (the old
				       -- sum-of-renditions bug) so a corrected re-sync could never heal it
				       -- down. Exact-set lets a re-sync with the fixed agent self-correct.
				       original_size_bytes  = CASE WHEN EXCLUDED.original_size_bytes > 0
				                                   THEN EXCLUDED.original_size_bytes
				                                   ELSE site_media_assets.original_size_bytes END,
				       -- variant_count (image-file count) + saved_bytes (all-variant savings)
				       -- exact-set on a positive report so a re-sync heals already-optimized
				       -- rows; a 0 (pending / older agent) preserves the existing value.
				       variant_count        = CASE WHEN EXCLUDED.variant_count > 0
				                                   THEN EXCLUDED.variant_count
				                                   ELSE site_media_assets.variant_count END,
				       saved_bytes          = CASE WHEN EXCLUDED.saved_bytes > 0
				                                   THEN EXCLUDED.saved_bytes
				                                   ELSE site_media_assets.saved_bytes END,
				       sync_generation      = EXCLUDED.sync_generation,
				       last_synced_at       = now(),
				       updated_at           = now()`,
				tenantID, siteID, a.WPAttachmentID, a.Title, a.OriginalPath,
				a.OriginalURL, a.OriginalMime, a.OriginalWidth, a.OriginalHeight,
				a.OriginalSizeBytes, syncGen, a.VariantCount, a.SavedBytes,
			)
			if err != nil {
				return domain.Internal("media_asset_upsert_failed", "failed to upsert media asset").WithCause(err)
			}
			affected += tag.RowsAffected()
		}
		return nil
	})
	return affected, err
}

// DeleteAssetAgent removes a single attachment's asset row (and its jobs) under
// the agent GUC — the real-time hook the agent fires when a WP attachment is
// deleted. media_optimization_jobs.asset_id is FK ON DELETE SET NULL (it does NOT
// cascade), so the jobs for this attachment are deleted FIRST and explicitly,
// otherwise a stale, orphaned job row would linger. media_variant_results cascade
// off the jobs. Returns the number of asset rows deleted (0 = idempotent no-op).
// tenantID/siteID come from the verified Ed25519 identity (NOT a client header).
func (r *Repo) DeleteAssetAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (int64, error) {
	var deleted int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		if _, err := tx.Exec(ctx,
			`DELETE FROM media_optimization_jobs
			 WHERE tenant_id = $1 AND site_id = $2 AND wp_attachment_id = $3`,
			tenantID, siteID, wpAttachmentID); err != nil {
			return domain.Internal("media_jobs_delete_failed", "failed to delete media jobs for attachment").WithCause(err)
		}
		tag, err := tx.Exec(ctx,
			`DELETE FROM site_media_assets
			 WHERE tenant_id = $1 AND site_id = $2 AND wp_attachment_id = $3`,
			tenantID, siteID, wpAttachmentID)
		if err != nil {
			return domain.Internal("media_asset_delete_failed", "failed to delete media asset").WithCause(err)
		}
		deleted = tag.RowsAffected()
		return nil
	})
	return deleted, err
}

// SweepStaleAssetsAgent removes every asset in a site whose sync_generation is
// older than gen — i.e. the attachment was NOT seen by the latest sync run, so it
// is gone in WP. Runs under the agent GUC (sync-finalize callback). The jobs for
// the stale attachments are deleted FIRST (FK is ON DELETE SET NULL, not cascade,
// so they would otherwise orphan); media_variant_results cascade off the jobs.
// Returns the number of asset rows swept. Never call with gen<=0 (see service).
func (r *Repo) SweepStaleAssetsAgent(ctx context.Context, tenantID, siteID uuid.UUID, gen int64) (int64, error) {
	var swept int64
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		// Delete the jobs of every stale attachment first (by the same site +
		// stale-asset set) so no FK-SET-NULL orphan remains.
		if _, err := tx.Exec(ctx,
			`DELETE FROM media_optimization_jobs
			 WHERE tenant_id = $1 AND site_id = $2
			   AND wp_attachment_id IN (
			       SELECT wp_attachment_id FROM site_media_assets
			       WHERE tenant_id = $1 AND site_id = $2 AND sync_generation < $3
			   )`,
			tenantID, siteID, gen); err != nil {
			return domain.Internal("media_jobs_sweep_failed", "failed to sweep stale media jobs").WithCause(err)
		}
		tag, err := tx.Exec(ctx,
			`DELETE FROM site_media_assets
			 WHERE tenant_id = $1 AND site_id = $2 AND sync_generation < $3`,
			tenantID, siteID, gen)
		if err != nil {
			return domain.Internal("media_assets_sweep_failed", "failed to sweep stale media assets").WithCause(err)
		}
		swept = tag.RowsAffected()
		return nil
	})
	return swept, err
}

// ListAssetsInput is the cursor-paginated dashboard query.
type ListAssetsInput struct {
	TenantID uuid.UUID
	SiteID   uuid.UUID
	Limit    int
	// Cursor is the last seen asset id (created_at, id) — opaque to the caller.
	Cursor string
	Status string // optional filter on status
	Format string // optional filter on current_format
	Search string // optional ILIKE on title/original_path
}

// ListAssets returns a page of assets ordered by created_at DESC, id DESC, plus
// a next cursor (empty when exhausted).
func (r *Repo) ListAssets(ctx context.Context, in ListAssetsInput) ([]model.Asset, string, error) {
	limit := in.Limit
	if limit <= 0 || limit > 200 {
		limit = 50
	}
	var out []model.Asset
	var nextCursor string
	mimeList := media.OptimizableMimesSQLList()
	err := r.pool.InTenantTx(ctx, in.TenantID, func(tx pgx.Tx) error {
		args := []any{in.TenantID, in.SiteID}
		q := `SELECT ` + assetCols + `
			  FROM site_media_assets
			  WHERE tenant_id = $1 AND site_id = $2`
		switch in.Status {
		case "":
			// no filter
		case "unsupported":
			// Non-optimizable MIME types in the pending/restored bucket — no DB column,
			// derived purely from status + mime.
			q += ` AND status IN ('pending','restored') AND lower(original_mime) NOT IN (` + mimeList + `)`
		case "pending":
			// Pending/restored AND mime is optimizable — the true "work to do" set.
			q += ` AND status IN ('pending','restored') AND lower(original_mime) IN (` + mimeList + `)`
		default:
			args = append(args, in.Status)
			q += ` AND status = $` + strconv.Itoa(len(args))
		}
		if in.Format != "" {
			args = append(args, in.Format)
			q += ` AND current_format = $` + strconv.Itoa(len(args))
		}
		if in.Search != "" {
			// Strip LIKE metacharacters so a search term can't smuggle wildcards
			// that DoS the index scan; the value is also bound as a parameter.
			args = append(args, "%"+trimLikeWildcards(in.Search)+"%")
			n := strconv.Itoa(len(args))
			q += ` AND (title ILIKE $` + n + ` OR original_path ILIKE $` + n + `)`
		}
		if in.Cursor != "" {
			if cid, err := uuid.Parse(in.Cursor); err == nil {
				args = append(args, cid)
				n := strconv.Itoa(len(args))
				// keyset: rows strictly "after" the cursor in (created_at DESC, id DESC)
				// order. The id tiebreaker is REQUIRED: a sync inserts each batch in ONE
				// transaction, so up to ~200 assets share the EXACT same created_at. A bare
				// `created_at < cursor` skips every tied row at a page boundary — that is
				// what paginated a 456-asset library down to ~318. The row-value comparison
				// `(created_at, id) < (cursor_created_at, cursor_id)` matches the ORDER BY
				// exactly, so no row is skipped or duplicated across pages.
				q += ` AND (created_at, id) < ((SELECT created_at FROM site_media_assets WHERE id = $` +
					n + `), $` + n + `)`
			}
		}
		args = append(args, limit+1)
		q += ` ORDER BY created_at DESC, id DESC LIMIT $` + strconv.Itoa(len(args))

		rows, err := tx.Query(ctx, q, args...)
		if err != nil {
			return domain.Internal("media_assets_list_failed", "failed to list media assets").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			a, err := assetFromRow(rows)
			if err != nil {
				return domain.Internal("media_assets_list_failed", "failed to read media asset").WithCause(err)
			}
			out = append(out, a)
		}
		if err := rows.Err(); err != nil {
			return err
		}
		if len(out) > limit {
			nextCursor = out[limit-1].ID.String()
			out = out[:limit]
		}
		return nil
	})
	return out, nextCursor, err
}

// GetAssetByWPIDAgent returns a single asset by (site_id, wp_attachment_id)
// under the agent GUC (auto-optimize callback path). Returns (zero, false, nil)
// when no row exists — the periodic sync backfills it, so the auto-optimize
// handler skips unknown ids rather than failing.
func (r *Repo) GetAssetByWPIDAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (model.Asset, bool, error) {
	var out model.Asset
	var found bool
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT `+assetCols+`
			 FROM site_media_assets
			 WHERE tenant_id = $1 AND site_id = $2 AND wp_attachment_id = $3`,
			tenantID, siteID, wpAttachmentID)
		a, err := assetFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return domain.Internal("media_asset_get_by_wp_failed", "failed to get media asset by wp_attachment_id").WithCause(err)
		}
		out = a
		found = true
		return nil
	})
	return out, found, err
}

// GetAsset returns a single asset by id (tenant-scoped).
func (r *Repo) GetAsset(ctx context.Context, tenantID, assetID uuid.UUID) (model.Asset, error) {
	var out model.Asset
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT `+assetCols+` FROM site_media_assets WHERE tenant_id = $1 AND id = $2`,
			tenantID, assetID)
		a, err := assetFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return domain.NotFound("media_asset_not_found", "media asset not found")
		}
		if err != nil {
			return domain.Internal("media_asset_get_failed", "failed to get media asset").WithCause(err)
		}
		out = a
		return nil
	})
	return out, err
}

// ListPendingAssetIDs returns up to limit asset ids in a site that are eligible
// for optimization (pending or failed, optimizable mime). Used by the
// "all_pending" optimize path. The mime IN-list is derived from
// media.OptimizableMimesSQLList() so it always matches the domain definition.
func (r *Repo) ListPendingAssetIDs(ctx context.Context, tenantID, siteID uuid.UUID, limit int) ([]model.Asset, error) {
	if limit <= 0 || limit > 1000 {
		limit = 500
	}
	mimeList := media.OptimizableMimesSQLList()
	var out []model.Asset
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		rows, err := tx.Query(ctx,
			`SELECT `+assetCols+`
			 FROM site_media_assets
			 WHERE tenant_id = $1 AND site_id = $2 AND status IN ('pending', 'failed')
			   AND lower(original_mime) IN (`+mimeList+`)
			 ORDER BY created_at ASC
			 LIMIT $3`,
			tenantID, siteID, limit)
		if err != nil {
			return domain.Internal("media_pending_list_failed", "failed to list pending assets").WithCause(err)
		}
		defer rows.Close()
		for rows.Next() {
			a, err := assetFromRow(rows)
			if err != nil {
				return domain.Internal("media_pending_list_failed", "failed to read pending asset").WithCause(err)
			}
			out = append(out, a)
		}
		return rows.Err()
	})
	return out, err
}

// SetAssetStatus transitions an asset's status (tenant-scoped, operator path).
func (r *Repo) SetAssetStatus(ctx context.Context, tenantID, assetID uuid.UUID, status model.AssetStatus) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		_, err := tx.Exec(ctx,
			`UPDATE site_media_assets SET status = $3, updated_at = now()
			 WHERE tenant_id = $1 AND id = $2`,
			tenantID, assetID, status)
		if err != nil {
			return domain.Internal("media_asset_status_failed", "failed to set asset status").WithCause(err)
		}
		return nil
	})
}

// ApplyOptimizedInput is the agent's post-apply asset snapshot (the salient
// fields of the wpmgr_image_optimization blob — ADR-043 / media-postmeta-blob).
type ApplyOptimizedInput struct {
	CurrentFormat    string
	CurrentSizeBytes int64
	// OriginalSizeBytes is the agent's bytes_before — the FULL image file's bytes
	// (M26 full-file semantic: the size users expect per image, NOT a sum of
	// sub-sizes). Paired with CurrentSizeBytes (the optimized full) it gives a
	// full-vs-full per-image before/after. 0 = leave as-is.
	OriginalSizeBytes int64
	// SavedBytes is the all-variant savings — sum over every optimized variant
	// (full + each thumbnail) of original-minus-optimized bytes. This is what the
	// dashboard "Bytes saved" tile rolls up (the full-file original-current would
	// miss thumbnail savings). 0 = leave as-is.
	SavedBytes       int64
	Status           model.AssetStatus
	CompressionLevel string
	TargetFormat     string
	SizesOptimized   []string
	SizesUnoptimized map[string]string
}

// ApplyOptimizedAgent finalizes an asset row after the agent applies optimized
// variants on disk. Runs under the agent GUC; bumps generation; sets
// last_optimized_at. Returns the updated asset.
func (r *Repo) ApplyOptimizedAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64, in ApplyOptimizedInput) (model.Asset, error) {
	sizesOpt, _ := json.Marshal(orEmptySlice(in.SizesOptimized))
	sizesUnopt, _ := json.Marshal(orEmptyMap(in.SizesUnoptimized))
	var out model.Asset
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`UPDATE site_media_assets
			 SET current_format     = $4,
			     current_size_bytes  = $5,
			     status              = $6,
			     compression_level   = $7,
			     target_format       = $8,
			     sizes_optimized     = $9,
			     sizes_unoptimized   = $10,
			     original_size_bytes = CASE WHEN $11 > 0 THEN $11 ELSE original_size_bytes END,
			     saved_bytes         = CASE WHEN $12 > 0 THEN $12 ELSE saved_bytes END,
			     generation          = generation + 1,
			     last_optimized_at   = now(),
			     updated_at          = now()
			 WHERE tenant_id = $1 AND site_id = $2 AND wp_attachment_id = $3
			 RETURNING `+assetCols,
			tenantID, siteID, wpAttachmentID, in.CurrentFormat, in.CurrentSizeBytes,
			in.Status, nilIfEmpty(in.CompressionLevel), nilIfEmpty(in.TargetFormat),
			sizesOpt, sizesUnopt, in.OriginalSizeBytes, in.SavedBytes)
		a, err := assetFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return domain.NotFound("media_asset_not_found", "media asset not found for apply")
		}
		if err != nil {
			return domain.Internal("media_asset_apply_failed", "failed to apply optimized asset").WithCause(err)
		}
		out = a
		return nil
	})
	return out, err
}

// RestoreAssetAgent marks an asset restored (or originals_deleted-aware) after
// the agent reverts on disk. Runs under the agent GUC. Returns the updated asset.
func (r *Repo) RestoreAssetAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (model.Asset, error) {
	var out model.Asset
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`UPDATE site_media_assets
			 SET status             = 'restored',
			     current_format     = 'original',
			     current_size_bytes  = original_size_bytes,
			     compression_level   = NULL,
			     target_format       = NULL,
			     sizes_optimized     = '[]'::jsonb,
			     sizes_unoptimized   = '{}'::jsonb,
			     updated_at          = now()
			 WHERE tenant_id = $1 AND site_id = $2 AND wp_attachment_id = $3
			 RETURNING `+assetCols,
			tenantID, siteID, wpAttachmentID)
		a, err := assetFromRow(row)
		if errors.Is(err, pgx.ErrNoRows) {
			return domain.NotFound("media_asset_not_found", "media asset not found for restore")
		}
		if err != nil {
			return domain.Internal("media_asset_restore_failed", "failed to restore asset").WithCause(err)
		}
		out = a
		return nil
	})
	return out, err
}

// Summary returns the dashboard rollup for a site.
func (r *Repo) Summary(ctx context.Context, tenantID, siteID uuid.UUID) (model.AssetSummary, error) {
	mimeList := media.OptimizableMimesSQLList()
	var s model.AssetSummary
	err := r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		row := tx.QueryRow(ctx,
			`SELECT
			    count(*) AS total,
			    count(*) FILTER (WHERE status = 'optimized') AS optimized,
			    count(*) FILTER (WHERE status IN ('pending', 'restored')
			        AND lower(original_mime) IN (`+mimeList+`)) AS pending,
			    count(*) FILTER (WHERE status = 'failed') AS failed,
			    -- Bytes saved across ALL optimized variants (full + every thumbnail).
			    -- Prefer the agent-reported saved_bytes (the true all-variant savings);
			    -- fall back to the full-file (original - current) only for rows optimized
			    -- before the agent reported saved_bytes (heals on the next re-sync/optimize).
			    coalesce(sum(GREATEST(
			        CASE WHEN saved_bytes > 0 THEN saved_bytes
			             ELSE original_size_bytes - current_size_bytes END, 0))
			        FILTER (WHERE status = 'optimized'), 0) AS bytes_saved,
			    count(*) FILTER (WHERE status IN ('pending', 'restored')
			        AND lower(original_mime) NOT IN (`+mimeList+`)) AS unsupported,
			    -- Image files (incl. thumbnails) across optimizable attachments. Floor at
			    -- the optimized-variant count so a not-yet-resynced row (variant_count 0)
			    -- never makes total_images read below optimized_images. The FILTER also
			    -- admits any already-optimized row (defensive: an optimized row is always
			    -- an optimizable mime, but this keeps total_images >= optimized_images
			    -- even against anomalous data).
			    coalesce(sum(GREATEST(variant_count, coalesce(jsonb_array_length(sizes_optimized), 0)))
			        FILTER (WHERE lower(original_mime) IN (`+mimeList+`) OR status = 'optimized'), 0) AS total_images,
			    -- How many of those image files are optimized (sum of each optimized
			    -- asset's sizes_optimized length: full + each thumbnail done).
			    coalesce(sum(coalesce(jsonb_array_length(sizes_optimized), 0))
			        FILTER (WHERE status = 'optimized'), 0) AS optimized_images
			 FROM site_media_assets
			 WHERE tenant_id = $1 AND site_id = $2`,
			tenantID, siteID)
		if err := row.Scan(&s.Total, &s.Optimized, &s.Pending, &s.Failed, &s.BytesSaved, &s.Unsupported,
			&s.TotalImages, &s.OptimizedImages); err != nil {
			return domain.Internal("media_summary_failed", "failed to compute media summary").WithCause(err)
		}
		return nil
	})
	return s, err
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func nilIfEmpty(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func orEmptySlice(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}

func orEmptyMap(m map[string]string) map[string]string {
	if m == nil {
		return map[string]string{}
	}
	return m
}

// trimLikeWildcards is a tiny guard so a search term can't smuggle SQL LIKE
// wildcards that DoS the index scan. (Defensive; the param is already bound.)
func trimLikeWildcards(s string) string {
	return strings.NewReplacer("%", "", "_", "").Replace(s)
}
