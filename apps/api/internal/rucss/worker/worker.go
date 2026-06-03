// Package worker is the River integration for the RUCSS domain: the
// rucss_process job, its worker, and a RiverEnqueuer. Phase 6 wires the worker
// into cmd/wpmgr/main.go's startRiver via the exported Workers/RegisterWorker
// helpers; this package itself does NOT touch main.go.
package worker

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/blobstore"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/service"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// RucssQueue is the dedicated River queue for RUCSS computations. A purge is
// CPU-bound (HTML parse + cascadia matching over the whole stylesheet); a small
// bounded pool keeps a burst of agent requests from starving other work.
const RucssQueue = "rucss_process"

// RucssTimeout caps the wall-clock budget for one purge. A large real-world
// page+stylesheet purges in well under a second; 60s is generous headroom that
// still lets River reclaim a wedged job.
const RucssTimeout = 60 * time.Second

// RucssArgs is the River job payload for one RUCSS computation. It carries only
// IDs + the small scalars the worker needs; the (large) HTML/CSS source bytes
// are fetched by a SourceFetcher from the location the enqueuer recorded
// (typically a temp object the Phase-6 ingest endpoint uploaded), so the River
// row stays tiny.
type RucssArgs struct {
	TenantID      uuid.UUID `json:"tenant_id"`
	SiteID        uuid.UUID `json:"site_id"`
	JobID         string    `json:"job_id"`
	StructureHash string    `json:"structure_hash"`
	URL           string    `json:"url,omitempty"`
	// SourceKey locates the HTML+CSS bundle the worker fetches (e.g. an S3 key).
	SourceKey string `json:"source_key,omitempty"`
	// Safelist is the per-site css_rucss_include_selectors at enqueue time.
	Safelist []string `json:"safelist,omitempty"`
}

// Kind implements river.JobArgs.
func (RucssArgs) Kind() string { return "rucss_process" }

// InsertOpts pins the job to the dedicated RUCSS queue.
func (RucssArgs) InsertOpts() river.InsertOpts {
	return river.InsertOpts{Queue: RucssQueue}
}

// Source is the resolved HTML+CSS for a RUCSS job.
type Source struct {
	HTML []byte
	CSS  []byte
}

// SourceFetcher resolves the HTML+CSS bundle for a job from wherever the
// enqueuer stashed it (object storage, an inline cache, …). Phase 6 supplies the
// concrete implementation; the worker depends only on this interface so it can
// be unit-tested with a fake.
type SourceFetcher interface {
	Fetch(ctx context.Context, args RucssArgs) (Source, error)
}

// SourceDeleter removes the temp HTML+CSS bundle once the worker has consumed it
// (success) or the job has failed terminally and will NOT be retried. The page
// HTML in that bundle must not be retained in object storage past the single
// computation it feeds (security: source page output is sensitive). Optional —
// when nil the worker relies on the backstop lifecycle/sweeper to reap orphans.
// Phase 6's *perf.rucssSourceFetcher satisfies both this and SourceFetcher.
type SourceDeleter interface {
	DeleteSource(ctx context.Context, args RucssArgs) error
}

// EventPublisher publishes the rucss.* SSE envelope on the shared tenant bus.
// *events.Publisher (which satisfies site.EventPublisher) is injected.
type EventPublisher interface {
	Publish(ctx context.Context, ev site.ConnectionEvent) error
}

// Worker runs one RUCSS computation: fetch source -> service.ComputeOrGetCached
// -> record done/failed -> publish rucss.completed / rucss.failed.
type Worker struct {
	river.WorkerDefaults[RucssArgs]
	svc     *service.Service
	src     SourceFetcher
	del     SourceDeleter
	jobs    JobLifecycle
	events  EventPublisher
	logger  *slog.Logger
	timeout time.Duration
}

// JobLifecycle is the worker's view of the job store (running/done/failed). It
// is satisfied by *repo.Repo (see the adapter used at construction in main /
// tests). Kept minimal so the worker is unit-testable.
type JobLifecycle interface {
	MarkRunning(ctx context.Context, tenantID uuid.UUID, jobID string) error
	MarkDone(ctx context.Context, tenantID uuid.UUID, jobID string, resultID uuid.UUID) error
	MarkFailed(ctx context.Context, tenantID uuid.UUID, jobID, reason string) error
}

// NewWorker builds the RUCSS worker. src/jobs/events may be nil in degraded
// environments; the worker handles nil dependencies gracefully (a nil source
// fetcher yields a recorded failure, never a panic). If src ALSO implements
// SourceDeleter (Phase 6's fetcher does), the worker reaps the temp source
// bundle after it has been consumed or has failed terminally.
func NewWorker(svc *service.Service, src SourceFetcher, jobs JobLifecycle, events EventPublisher, logger *slog.Logger) *Worker {
	if logger == nil {
		logger = slog.Default()
	}
	w := &Worker{
		svc:     svc,
		src:     src,
		jobs:    jobs,
		events:  events,
		logger:  logger,
		timeout: RucssTimeout,
	}
	if del, ok := src.(SourceDeleter); ok {
		w.del = del
	}
	return w
}

// Timeout overrides River's default per-job context deadline. See RucssTimeout.
func (w *Worker) Timeout(*river.Job[RucssArgs]) time.Duration { return w.timeout }

// Work runs one RUCSS computation.
//
// Failure model (mirrors backup.SqlInspectLegacyWorker): a recorded terminal
// failure (engine kept-all, store unwired, bad job) returns nil so River does
// NOT retry; a transient infra error (source fetch / store write transport)
// returns the error so River retries with backoff. The job NEVER panics.
//
// Source-bundle lifecycle (FIX 1): the agent-posted HTML+CSS bundle the ingest
// endpoint stashed at a.SourceKey is sensitive page output and MUST NOT persist
// in object storage past the single computation it feeds. We delete it on the
// terminal paths only — the SUCCESS path and a TERMINAL failure AFTER the fetch
// — because on those paths River will NOT retry and the bundle is spent. We
// deliberately do NOT delete on a transient/retryable error at or before the
// fetch (River retries and must be able to re-fetch the bundle). Delete is
// idempotent, so a race with the backstop sweeper is harmless. A backstop
// periodic sweeper (RucssSweepArgs) reaps orphans for jobs that never run.
func (w *Worker) Work(ctx context.Context, job *river.Job[RucssArgs]) error {
	a := job.Args

	if w.svc == nil {
		return fmt.Errorf("rucss worker: service unwired")
	}

	w.markRunning(ctx, a)

	if w.src == nil {
		return w.failTerminal(ctx, a, "source fetcher unwired")
	}
	srcBundle, err := w.src.Fetch(ctx, a)
	if err != nil {
		// Source unavailable: this can be transient (object storage blip) — let
		// River retry. If it is a hard miss the job will exhaust its retries and
		// River records the failure in its own metrics. Do NOT delete here: a retry
		// must be able to re-fetch the bundle.
		return fmt.Errorf("rucss worker: fetch source: %w", err)
	}

	res, err := w.svc.ComputeOrGetCached(ctx, service.ComputeInput{
		TenantID:      a.TenantID,
		SiteID:        a.SiteID,
		StructureHash: a.StructureHash,
		URL:           a.URL,
		HTML:          srcBundle.HTML,
		CSS:           srcBundle.CSS,
		Safelist:      a.Safelist,
	})
	if err != nil {
		// A store-write / DB error is potentially transient — return it so River
		// retries. The job stays in 'running' (we did not mark it failed) so a
		// successful retry can complete it. Do NOT delete the source: the retry
		// re-fetches it.
		return fmt.Errorf("rucss worker: compute: %w", err)
	}

	// Success: the bundle is spent and the job will not be retried — reap it.
	w.deleteSource(ctx, a)

	w.markDone(ctx, a, res.Result.ID)
	w.publish(ctx, a, site.EventRucssCompleted, map[string]any{
		"job_id":         a.JobID,
		"structure_hash": a.StructureHash,
		"result_id":      res.Result.ID.String(),
		"cache_hit":      res.CacheHit,
		"reduction_pct":  res.Stats.ReductionPct,
		"used_bytes":     res.Stats.UsedBytes,
		"original_bytes": res.Stats.OriginalBytes,
		"fell_back":      res.Stats.FellBack,
	})
	w.logger.Info("rucss computed",
		slog.String("site_id", a.SiteID.String()),
		slog.String("structure_hash", a.StructureHash),
		slog.Bool("cache_hit", res.CacheHit),
		slog.Float64("reduction_pct", res.Stats.ReductionPct))
	return nil
}

// deleteSource reaps the temp HTML+CSS source bundle once the worker is done
// with it (success or a post-fetch terminal failure). It is a no-op when no
// deleter is wired (degraded env) or the job carries no source key. A delete
// failure is logged but never fails the job — the backstop sweeper / lifecycle
// rule is the safety net, and Delete is idempotent.
func (w *Worker) deleteSource(ctx context.Context, a RucssArgs) {
	if w.del == nil || a.SourceKey == "" {
		return
	}
	if err := w.del.DeleteSource(ctx, a); err != nil {
		w.logger.Warn("rucss delete source bundle failed",
			slog.String("job_id", a.JobID),
			slog.String("source_key", a.SourceKey),
			slog.Any("error", err))
	}
}

// failTerminal records a terminal failure and publishes rucss.failed, returning
// nil so River does not retry an un-retryable job (e.g. unwired plumbing). It
// also reaps the temp source bundle: the job will not be retried, so a re-fetch
// will never happen and the page HTML must not linger in object storage.
func (w *Worker) failTerminal(ctx context.Context, a RucssArgs, reason string) error {
	w.deleteSource(ctx, a)
	w.markFailed(ctx, a, reason)
	w.publish(ctx, a, site.EventRucssFailed, map[string]any{
		"job_id":         a.JobID,
		"structure_hash": a.StructureHash,
		"error":          reason,
	})
	w.logger.Warn("rucss job failed (terminal)",
		slog.String("site_id", a.SiteID.String()),
		slog.String("structure_hash", a.StructureHash),
		slog.String("reason", reason))
	return nil
}

func (w *Worker) markRunning(ctx context.Context, a RucssArgs) {
	if w.jobs == nil || a.JobID == "" {
		return
	}
	if err := w.jobs.MarkRunning(ctx, a.TenantID, a.JobID); err != nil {
		w.logger.Warn("rucss mark running failed", slog.String("job_id", a.JobID), slog.Any("error", err))
	}
}

func (w *Worker) markDone(ctx context.Context, a RucssArgs, resultID uuid.UUID) {
	if w.jobs == nil || a.JobID == "" {
		return
	}
	if err := w.jobs.MarkDone(ctx, a.TenantID, a.JobID, resultID); err != nil {
		w.logger.Warn("rucss mark done failed", slog.String("job_id", a.JobID), slog.Any("error", err))
	}
}

func (w *Worker) markFailed(ctx context.Context, a RucssArgs, reason string) {
	if w.jobs == nil || a.JobID == "" {
		return
	}
	if err := w.jobs.MarkFailed(ctx, a.TenantID, a.JobID, reason); err != nil {
		w.logger.Warn("rucss mark failed failed", slog.String("job_id", a.JobID), slog.Any("error", err))
	}
}

func (w *Worker) publish(ctx context.Context, a RucssArgs, eventType string, data map[string]any) {
	if w.events == nil {
		return
	}
	_ = w.events.Publish(ctx, site.ConnectionEvent{
		Type:     eventType,
		TenantID: a.TenantID,
		SiteID:   a.SiteID,
		Data:     data,
	})
}

// ---------------------------------------------------------------------------
// enqueuer + registration helpers (Phase 6 wiring seams)
// ---------------------------------------------------------------------------

// RiverEnqueuer enqueues RUCSS jobs onto River.
type RiverEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverEnqueuer builds an enqueuer around the River client.
func NewRiverEnqueuer(client *river.Client[pgx.Tx]) *RiverEnqueuer {
	return &RiverEnqueuer{client: client}
}

// EnqueueRucss inserts one rucss_process job. Unique opts collapse a burst of
// identical (structure_hash) requests for a site into a single in-flight job —
// the worker is idempotent (the service dedups + upserts) so a stray duplicate
// is harmless, but de-duping at enqueue keeps the queue shallow.
func (e *RiverEnqueuer) EnqueueRucss(ctx context.Context, a RucssArgs) error {
	opts := &river.InsertOpts{
		Queue: RucssQueue,
		UniqueOpts: river.UniqueOpts{
			ByArgs:   true,
			ByPeriod: 5 * time.Minute,
		},
	}
	if _, err := e.client.Insert(ctx, a, opts); err != nil {
		return fmt.Errorf("enqueue rucss: %w", err)
	}
	return nil
}

// RegisterWorker adds the RUCSS worker to a River workers registry. Phase 6
// calls this from startRiver instead of hand-writing river.AddWorker.
func RegisterWorker(workers *river.Workers, w *Worker) {
	if w == nil {
		return
	}
	river.AddWorker(workers, w)
}

// Queues returns the River queue config this domain needs, for merging into the
// startRiver queues map. MaxWorkers is bounded so RUCSS bursts don't starve
// other queues. Phase 6 merges this into its map[string]river.QueueConfig.
func Queues(maxWorkers int) map[string]river.QueueConfig {
	if maxWorkers <= 0 {
		maxWorkers = 4
	}
	return map[string]river.QueueConfig{
		RucssQueue: {MaxWorkers: maxWorkers},
	}
}

// ---------------------------------------------------------------------------
// FIX 1 backstop: RUCSS source-bundle sweeper (orphan reaper)
// ---------------------------------------------------------------------------

// RucssSourcePrefix is the object-storage key namespace for the temp HTML+CSS
// source bundles the agent ingest endpoint stashes (perf.rucssBundleKey writes
// "rucss-src/{tenant}/{site}/{job}.bin"). The sweeper lists + reaps under it.
const RucssSourcePrefix = "rucss-src/"

// RucssSweepMaxAge is how old a source bundle must be before the backstop
// sweeper deletes it. The worker reaps its own bundle inline on success/terminal
// failure within milliseconds; anything still present after this window is an
// orphan whose job never ran (enqueue failed, River row lost, …). 60s is well
// past the worker's own delete latency.
const RucssSweepMaxAge = 60 * time.Second

// BundleReaper is the object-storage surface the backstop sweeper needs: list
// the source-bundle objects with their ages, and delete the stale ones.
// *blobstore.Store satisfies it.
type BundleReaper interface {
	ListWithModified(ctx context.Context, prefix string) ([]blobstore.ObjectInfo, error)
	Delete(ctx context.Context, key string) error
}

// RucssSweepArgs is the periodic backstop-sweep job payload.
type RucssSweepArgs struct{}

// Kind implements river.JobArgs.
func (RucssSweepArgs) Kind() string { return "rucss_source_sweep" }

// RucssSweepWorker is the backstop that deletes orphaned RUCSS source bundles
// (page HTML) that the inline per-job delete never reached because the job
// never ran. It is the safety net for the "never retain page HTML in object
// storage" invariant; an equivalent bucket lifecycle rule (expire objects under
// "rucss-src/" after ~1 minute) would also satisfy the requirement and is the
// recommended alternative on managed S3/GCS — this River periodic exists so the
// guarantee holds even on backends without lifecycle support (SeaweedFS/MinIO).
type RucssSweepWorker struct {
	river.WorkerDefaults[RucssSweepArgs]
	reaper BundleReaper
	maxAge time.Duration
	now    func() time.Time
	logger *slog.Logger
}

// NewRucssSweepWorker builds the backstop sweeper. reaper may be nil (degraded
// env) — Work is then a no-op. maxAge <= 0 defaults to RucssSweepMaxAge.
func NewRucssSweepWorker(reaper BundleReaper, maxAge time.Duration, logger *slog.Logger) *RucssSweepWorker {
	if logger == nil {
		logger = slog.Default()
	}
	if maxAge <= 0 {
		maxAge = RucssSweepMaxAge
	}
	return &RucssSweepWorker{reaper: reaper, maxAge: maxAge, now: time.Now, logger: logger}
}

// Work lists every object under rucss-src/ and deletes the ones older than
// maxAge. It always returns nil: a transient list/delete error is logged and
// retried on the next periodic tick (no point failing the job — it is a
// best-effort reaper, not a correctness-critical path).
func (w *RucssSweepWorker) Work(ctx context.Context, _ *river.Job[RucssSweepArgs]) error {
	if w.reaper == nil {
		return nil
	}
	objs, err := w.reaper.ListWithModified(ctx, RucssSourcePrefix)
	if err != nil {
		w.logger.Warn("rucss source sweep: list failed", slog.Any("error", err))
		return nil
	}
	cutoff := w.now().Add(-w.maxAge)
	var deleted int
	for _, o := range objs {
		// A zero LastModified (backend did not report one) is treated as stale so
		// the bundle is never retained forever; the inline delete already handles
		// the live path, so this only ever touches true orphans.
		if !o.LastModified.IsZero() && o.LastModified.After(cutoff) {
			continue
		}
		if derr := w.reaper.Delete(ctx, o.Key); derr != nil {
			w.logger.Warn("rucss source sweep: delete failed",
				slog.String("key", o.Key), slog.Any("error", derr))
			continue
		}
		deleted++
	}
	if deleted > 0 {
		w.logger.Info("rucss source sweep reaped orphans", slog.Int("deleted", deleted))
	}
	return nil
}

// RegisterSweepWorker adds the backstop sweeper to a River workers registry.
func RegisterSweepWorker(workers *river.Workers, w *RucssSweepWorker) {
	if w == nil {
		return
	}
	river.AddWorker(workers, w)
}
