package model

import (
	"time"

	"github.com/google/uuid"
)

// JobKind is the action a media_optimization_jobs row represents.
type JobKind string

const (
	// JobOptimize encodes an attachment's variants to the target format.
	JobOptimize JobKind = "optimize"
	// JobRestore reverts an attachment to its pre-optimization state.
	JobRestore JobKind = "restore"
	// JobDeleteOriginals irreversibly deletes the archived originals.
	JobDeleteOriginals JobKind = "delete_originals"
	// JobSync enumerates the site's media library into site_media_assets.
	JobSync JobKind = "sync"
)

// Valid reports whether k is a known job kind.
func (k JobKind) Valid() bool {
	switch k {
	case JobOptimize, JobRestore, JobDeleteOriginals, JobSync:
		return true
	}
	return false
}

// JobState is the lifecycle state of a media_optimization_jobs row.
type JobState string

const (
	// JobQueued — created, awaiting agent action / encode.
	JobQueued JobState = "queued"
	// JobInProgress — the agent uploaded sources / the encoder is running.
	JobInProgress JobState = "in_progress"
	// JobSucceeded — every variant succeeded.
	JobSucceeded JobState = "succeeded"
	// JobPartiallySucceeded — some variants failed but at least one succeeded.
	JobPartiallySucceeded JobState = "partially_succeeded"
	// JobFailed — the job failed (no variant succeeded, or a hard error).
	JobFailed JobState = "failed"
	// JobCancelled — an operator cancelled the job before completion.
	JobCancelled JobState = "cancelled"
)

// Terminal reports whether the state is a final state (dup-delivery / cancel safe).
func (s JobState) Terminal() bool {
	switch s {
	case JobSucceeded, JobPartiallySucceeded, JobFailed, JobCancelled:
		return true
	}
	return false
}

// Job is one row of media_optimization_jobs. id is a ULID (TEXT) used as the
// agent's wpmgr_job_id cross-reference.
type Job struct {
	ID                string
	TenantID          uuid.UUID
	SiteID            uuid.UUID
	AssetID           *uuid.UUID
	WPAttachmentID    int64
	Kind              JobKind
	TargetFormat      string
	TargetQuality     string
	State             JobState
	BytesBefore       *int64
	BytesAfter        *int64
	VariantsTotal     int
	VariantsSucceeded int
	VariantsFailed    int
	ErrorReason       string
	InitiatorUserID   *uuid.UUID
	CreatedAt         time.Time
	StartedAt         *time.Time
	CompletedAt       *time.Time
	// SyncGeneration is the run generation stamped on a JobSync row at insert
	// (clock.Now().UnixMicro()). The sync-finalize callback reads it back to sweep
	// every asset whose sync_generation is older than this run. Nil/0 on non-sync
	// jobs and on legacy sync jobs created before M24.
	SyncGeneration *int64
	// EncodeRiverJobID is the River river_jobs.id for the media_encode job that
	// was enqueued at encode-ready time (m51). Nil for non-optimize jobs and for
	// rows created before the m51 migration. The cancel path uses this to cancel
	// the River job proactively so the encoder is never woken for discarded work.
	EncodeRiverJobID *int64
}
