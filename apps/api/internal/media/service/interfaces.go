package service

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// Repo is the persistence surface the service needs. *repo.Repo satisfies it;
// declared as an interface so service-layer unit tests can fake it under
// CGO_ENABLED=0 (no Postgres / no encoder).
type Repo interface {
	// assets
	UpsertAssetsAgent(ctx context.Context, tenantID, siteID uuid.UUID, syncGen int64, rows []repo.UpsertAssetInput) (int64, error)
	DeleteAssetAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (int64, error)
	SweepStaleAssetsAgent(ctx context.Context, tenantID, siteID uuid.UUID, gen int64) (int64, error)
	ListAssets(ctx context.Context, in repo.ListAssetsInput) ([]model.Asset, string, error)
	GetAsset(ctx context.Context, tenantID, assetID uuid.UUID) (model.Asset, error)
	GetAssetByWPIDAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (model.Asset, bool, error)
	ListPendingAssetIDs(ctx context.Context, tenantID, siteID uuid.UUID, limit int) ([]model.Asset, error)
	SetAssetStatus(ctx context.Context, tenantID, assetID uuid.UUID, status model.AssetStatus) error
	ApplyOptimizedAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64, in repo.ApplyOptimizedInput) (model.Asset, error)
	RestoreAssetAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (model.Asset, error)
	Summary(ctx context.Context, tenantID, siteID uuid.UUID) (model.AssetSummary, error)
	// media settings
	GetMediaSettings(ctx context.Context, tenantID, siteID uuid.UUID) (model.MediaSettings, bool, error)
	GetMediaSettingsAgent(ctx context.Context, tenantID, siteID uuid.UUID) (model.MediaSettings, bool, error)
	UpsertMediaSettings(ctx context.Context, tenantID, siteID uuid.UUID, in repo.UpsertMediaSettingsInput) (model.MediaSettings, error)
	// jobs
	InsertJob(ctx context.Context, tenantID uuid.UUID, in repo.InsertJobInput) (model.Job, error)
	GetJob(ctx context.Context, tenantID uuid.UUID, jobID string) (model.Job, error)
	GetJobAgent(ctx context.Context, jobID string) (model.Job, error)
	HasInFlightOptimizeJobAgent(ctx context.Context, tenantID, siteID uuid.UUID, wpAttachmentID int64) (bool, error)
	ListJobs(ctx context.Context, in repo.ListJobsInput) ([]model.Job, string, error)
	MarkJobInProgressAgent(ctx context.Context, jobID string, variantsTotal int) error
	FinalizeJobAgent(ctx context.Context, jobID string, in repo.FinalizeJobInput) (model.Job, error)
	CancelJobs(ctx context.Context, tenantID, siteID uuid.UUID) (repo.CancelJobsResult, error)
	// SetEncodeRiverJobID stores the River job ID on a media_optimization_jobs
	// row so the cancel path can later cancel that River job proactively (m51).
	// Best-effort: a failure is logged by the caller but does not fail the encode.
	SetEncodeRiverJobID(ctx context.Context, jobID string, riverJobID int64) error
	// variants
	UpsertVariantAgent(ctx context.Context, tenantID uuid.UUID, in repo.UpsertVariantInput) error
	ListVariantsForJob(ctx context.Context, tenantID uuid.UUID, jobID string) ([]model.VariantResult, error)
	CountVariantStatesAgent(ctx context.Context, jobID string) (succeeded, failed int, err error)
}

// EncodeEnqueuer inserts media_encode River jobs and cancels them when
// needed. *media.RiverEnqueuer satisfies it; the main API wires it
// post-River-start. Insert-only on the API side — MaxWorkers=0 on the
// media_encode queue; the encoder binary runs the actual workers.
type EncodeEnqueuer interface {
	// EnqueueEncode inserts one media_encode River job and returns the assigned
	// River job ID (stored on the media_optimization_jobs row via m51 so the
	// cancel path can cancel it proactively).
	EnqueueEncode(ctx context.Context, args model.EncodeArgs) (int64, error)
	// CancelEncodeJob cancels an enqueued media_encode River job by its River
	// job ID. Best-effort and idempotent: already-terminal or not-found IDs are
	// treated as success (log + continue). Returns an error only for unexpected
	// River client failures.
	CancelEncodeJob(ctx context.Context, riverJobID int64) error
}

// EncoderWaker nudges the scale-to-zero media-encoder awake right after a job is
// enqueued. The encoder is a pull worker (it polls Postgres), so at
// min-instances=0 nothing would cold-start it on an enqueue without this poke.
// *media.EncoderWaker satisfies it; a nil waker (self-host / not wired) is a
// no-op, as is a disabled one.
type EncoderWaker interface {
	Kick()
}

// AgentMediaClient is the subset of agentcmd.Client the service dispatches.
// *agentcmd.Client satisfies it; declared as an interface so the service stays
// free of the SSRF transport in tests, and so a nil/disabled commander degrades
// gracefully.
type AgentMediaClient interface {
	MediaOptimize(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.MediaOptimizeRequest) (agentcmd.MediaOptimizeResponse, error)
	MediaSync(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.MediaSyncRequest) (agentcmd.MediaSyncResponse, error)
	MediaRestore(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.MediaRestoreRequest) (agentcmd.MediaRestoreResponse, error)
	MediaDeleteOriginals(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.MediaDeleteOriginalsRequest) (agentcmd.MediaDeleteOriginalsResponse, error)
	SyncMediaConfig(ctx context.Context, siteID uuid.UUID, siteURL string, req agentcmd.MediaConfigRequest) (agentcmd.MediaConfigResult, error)
}

// SiteLookup resolves the slim site projection the media service needs (agent
// URL + enrollment). Wired in main via a narrow adapter to keep this package
// free of the site service import.
type SiteLookup interface {
	GetMediaSiteInfo(ctx context.Context, tenantID, siteID uuid.UUID) (MediaSiteInfo, error)
}

// MediaSiteInfo is the slim site projection for media dispatch.
type MediaSiteInfo struct {
	URL      string
	Enrolled bool
}

// Presigner mints presigned PUT/GET URLs for media temp objects. *blobstore.Store
// satisfies it. The CP NEVER reads image bytes itself — presigned URLs only.
type Presigner interface {
	PresignPut(ctx context.Context, key string, ttl time.Duration) (string, error)
	PresignGet(ctx context.Context, key string, ttl time.Duration) (string, error)
	Delete(ctx context.Context, key string) error
	List(ctx context.Context, prefix string) ([]string, error)
}

// EventPublisher publishes media.* SSE envelopes on the shared tenant bus.
// *events.Publisher (which satisfies site.EventPublisher) is injected.
type EventPublisher interface {
	Publish(ctx context.Context, ev site.ConnectionEvent) error
}
