package model

import (
	"time"

	"github.com/google/uuid"
)

// MediaSettings is one row of site_media_settings — the per-site auto-optimize
// configuration pushed to the agent via the sync_media_config CP→agent command
// (ADR-044). The CP is authoritative; the row survives an agent reinstall.
type MediaSettings struct {
	TenantID            uuid.UUID
	SiteID              uuid.UUID
	AutoOptimizeEnabled bool
	// AutoTargetFormat is the requested output format for auto-optimized uploads:
	// "avif", "webp", or "original". Validated by media.ValidTargetFormat.
	AutoTargetFormat string
	// AutoTargetQuality is the encode quality mode: "lossy" or "lossless".
	// Validated by media.ValidTargetQuality.
	AutoTargetQuality string
	CreatedAt         time.Time
	UpdatedAt         time.Time
}
