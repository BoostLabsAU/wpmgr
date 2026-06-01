// Package model holds the PURE-Go domain types for the Media Optimizer
// (ADR-043). It carries NO CGO dependency — in particular the River job type
// EncodeArgs (encodeargs.go) lives here so the main API (cmd/wpmgr) can
// client.Insert it WITHOUT ever importing the lilliput-backed encoder. The
// EncodeWorker that consumes EncodeArgs (and the encoder it imports) lives in
// the encoder-only import set (cmd/media-encoder).
package model

import (
	"time"

	"github.com/google/uuid"
)

// AssetStatus is the lifecycle state of a site_media_assets row. It mirrors the
// agent's wpmgr_image_optimization blob status plus the in-flight states the CP
// tracks while a job runs.
type AssetStatus string

const (
	// AssetPending — known to the CP (synced) but not yet optimized.
	AssetPending AssetStatus = "pending"
	// AssetOptimizing — an optimize job is in flight for this attachment.
	AssetOptimizing AssetStatus = "optimizing"
	// AssetOptimized — optimized variants are live on the site.
	AssetOptimized AssetStatus = "optimized"
	// AssetFailed — the last optimize job failed for every variant.
	AssetFailed AssetStatus = "failed"
	// AssetRestoring — a restore job is in flight.
	AssetRestoring AssetStatus = "restoring"
	// AssetRestored — the attachment was restored to its pre-optimization state.
	AssetRestored AssetStatus = "restored"
	// AssetExcluded — source format is not optimizable (not jpeg/png).
	AssetExcluded AssetStatus = "excluded"
	// AssetOriginalsDeleted — originals were deleted; restore is impossible.
	AssetOriginalsDeleted AssetStatus = "originals_deleted"
)

// CurrentFormat values: the format the optimized variants are in now.
const (
	FormatOriginal = "original"
	FormatWebP     = "webp"
	FormatAVIF     = "avif"
)

// Compression levels (per ADR-043 §4).
const (
	CompressionLossy    = "lossy"
	CompressionLossless = "lossless"
)

// Asset is one row of site_media_assets — the CP's mirror of a WP attachment's
// optimization state. The blob on the site stays authoritative for restore; the
// CP holds only metadata + status (NO image bytes — ADR-043 §2).
type Asset struct {
	ID                uuid.UUID
	TenantID          uuid.UUID
	SiteID            uuid.UUID
	WPAttachmentID    int64
	Title             string
	OriginalPath      string
	OriginalURL       string
	OriginalMime      string
	OriginalWidth     *int
	OriginalHeight    *int
	OriginalSizeBytes int64
	CurrentFormat     string
	CurrentSizeBytes  int64
	Status            AssetStatus
	Generation        int
	// SyncGeneration is the run generation of the last agent sync that upserted
	// this row (M24). The sync-finalize sweep removes rows on an older generation.
	SyncGeneration   int64
	CompressionLevel string
	TargetFormat     string
	// SizesOptimized is the list of registered size names successfully optimized.
	SizesOptimized []string
	// SizesUnoptimized maps size name -> human reason (Unsupported source format…).
	SizesUnoptimized map[string]string
	LastOptimizedAt  *time.Time
	LastSyncedAt     time.Time
	CreatedAt        time.Time
	UpdatedAt        time.Time
}

// AssetSummary is the dashboard rollup returned alongside the asset list.
type AssetSummary struct {
	Total      int64 `json:"total"`
	Optimized  int64 `json:"optimized"`
	Pending    int64 `json:"pending"`
	Failed     int64 `json:"failed"`
	BytesSaved int64 `json:"bytes_saved"`
	// Unsupported counts assets whose original_mime is not in the optimizable
	// set (e.g. webp, avif, svg, heic) and whose status is pending or restored.
	// These are synced but can never be optimized; they are derived from the
	// existing status + mime columns with NO DB migration.
	Unsupported int64 `json:"unsupported"`
	// TotalImages is the image-FILE count across optimizable attachments — every
	// upload's full image PLUS its generated sub-sizes (variant_count). This is the
	// "incl. thumbnails" headline; it always reads ≥ OptimizedImages (the summary
	// floors it at the optimized-variant count so a not-yet-resynced row never
	// shows total < optimized). M26.
	TotalImages int64 `json:"total_images"`
	// OptimizedImages is how many of those image files are optimized — the sum of
	// each optimized asset's sizes_optimized length (full + each thumbnail done). M26.
	OptimizedImages int64 `json:"optimized_images"`
}
