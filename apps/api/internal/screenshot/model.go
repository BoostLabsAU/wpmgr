// Package screenshot manages the capture, storage, and serving of per-site
// WordPress homepage screenshots. Captures run in the media-encoder binary
// (the only process with headless Chromium); the main API wires the enqueue
// path + the DTO enrichment of Site responses.
package screenshot

import (
	"time"

	"github.com/google/uuid"
)

// Screenshot is the domain model for one site's screenshot state.
type Screenshot struct {
	SiteID          uuid.UUID
	TenantID        uuid.UUID
	ScreenshotKey   string     // GCS/S3 object key (1x WebP)
	ScreenshotKey2x string     // GCS/S3 object key (2x WebP); empty when not yet captured
	Width           int32
	Height          int32
	Status          string // "pending" | "ready" | "failed"
	FailedReason    *string
	CapturedAt      *time.Time
	Etag            *string
	CreatedAt       time.Time
	UpdatedAt       time.Time
}

// CaptureReason describes why a capture was requested.
type CaptureReason string

const (
	ReasonEnroll    CaptureReason = "enroll"
	ReasonScheduled CaptureReason = "scheduled"
	ReasonManual    CaptureReason = "manual"
)

// CaptureArgs is the River job payload for site_screenshot_capture.
// It is a pure-Go type (no CGO) so the main API can enqueue it without
// importing the capture worker.
type CaptureArgs struct {
	SiteID   uuid.UUID     `json:"site_id"`
	TenantID uuid.UUID     `json:"tenant_id"`
	SiteURL  string        `json:"site_url"`
	Reason   CaptureReason `json:"reason"`
	Attempt  int           `json:"attempt"`
}

// ScreenshotQueue is the River queue the capture worker subscribes to.
// Concurrency is capped low (Chromium is memory-heavy; 1–2 concurrent tabs).
const ScreenshotQueue = "site_screenshot"

// DefaultPresignTTL is the TTL for presigned screenshot GET URLs vended to
// the web. 1 hour is generous — the web can refetch on next mount.
const DefaultPresignTTL = 1 * time.Hour
