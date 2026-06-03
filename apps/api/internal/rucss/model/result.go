// Package model holds the RUCSS domain view-models. They are plain structs
// decoupled from the sqlc row types so the service/handler/worker layers do not
// import internal/db/sqlc directly (the repo maps sqlc rows <-> these models).
package model

import (
	"time"

	"github.com/google/uuid"
)

// Result is a cached "used CSS" computation for one (site, structure_hash). The
// minimised CSS itself lives in object storage at S3Key (gzip-compressed); only
// the metadata is in Postgres.
type Result struct {
	ID            uuid.UUID
	TenantID      uuid.UUID
	SiteID        uuid.UUID
	StructureHash string
	URL           string

	OriginalCSSBytes int
	UsedCSSBytes     int
	ReductionPct     float64
	UsedCSSS3Key     string

	SelectorsTotal   int
	SelectorsKept    int
	SelectorsDropped int
	ComputeMs        int

	CreatedAt  time.Time
	LastUsedAt time.Time
}

// JobState enumerates the rucss_jobs lifecycle.
type JobState string

const (
	JobStateQueued  JobState = "queued"
	JobStateRunning JobState = "running"
	JobStateDone    JobState = "done"
	JobStateFailed  JobState = "failed"
)

// Job tracks one RUCSS computation request through the queue. The id is a
// caller-supplied string key (the River-job correlation id) so a flurry of
// identical requests dedups onto the same job row.
type Job struct {
	ID            string
	TenantID      uuid.UUID
	SiteID        uuid.UUID
	StructureHash string
	URL           string
	State         JobState
	ErrorReason   string
	ResultID      uuid.UUID // uuid.Nil until a result is attached
	CreatedAt     time.Time
	CompletedAt   *time.Time
}

// Stats is the per-pass summary surfaced to the UI / audit. It mirrors the
// engine.Stats fields the control plane persists.
type Stats struct {
	OriginalBytes    int     `json:"original_bytes"`
	UsedBytes        int     `json:"used_bytes"`
	ReductionPct     float64 `json:"reduction_pct"`
	SelectorsTotal   int     `json:"selectors_total"`
	SelectorsKept    int     `json:"selectors_kept"`
	SelectorsDropped int     `json:"selectors_dropped"`
	FellBack         bool    `json:"fell_back"`
	Note             string  `json:"note,omitempty"`
	ComputeMs        int     `json:"compute_ms"`
}
