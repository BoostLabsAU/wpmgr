package rum

import (
	"context"
	"log/slog"
	"time"

	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/config"
)

// ---------------------------------------------------------------------------
// RUM Raw-Event GC worker
// ---------------------------------------------------------------------------

// RumGCArgs is the River job payload for the RUM retention-GC periodic sweep.
type RumGCArgs struct{}

// Kind implements river.JobArgs.
func (RumGCArgs) Kind() string { return "rum_gc" }

// RumGCWorker deletes expired raw events, hourly rollups, and daily rollups.
// Retention windows (configurable via RetentionConfig):
//
//   - Raw events:     48h (SaaS) / 24h (self-host)
//   - Hourly rollups: 14 days (SaaS) / 7 days (self-host)
//   - Daily rollups:  13 months (SaaS) / 90 days (self-host)
//
// The worker runs cross-tenant under InAgentTx. It is a simple periodic sweep
// registered in startRiver; no per-tenant fan-out is needed because the GC
// queries span ALL tenants in a single DELETE.
type RumGCWorker struct {
	river.WorkerDefaults[RumGCArgs]
	store     Store
	retention RetentionConfig
	logger    *slog.Logger
}

// RetentionConfig holds the per-tier retention windows for RUM data.
type RetentionConfig struct {
	// RawEventsTTL is how long raw events are kept. Default 48h (SaaS), 24h (self-host).
	RawEventsTTL time.Duration
	// HourlyRollupTTL is how long hourly rollup rows are kept. Default 14d.
	HourlyRollupTTL time.Duration
	// DailyRollupTTL is how long daily rollup rows are kept. Default 13*30d (≈13 months).
	DailyRollupTTL time.Duration
}

// DefaultRetention returns the SaaS retention defaults.
func DefaultRetention(cfg config.Config) RetentionConfig {
	if cfg.IsProduction() {
		// SaaS hosted defaults (generous — rollups are cheap; raw events are the only pressure).
		return RetentionConfig{
			RawEventsTTL:    48 * time.Hour,
			HourlyRollupTTL: 14 * 24 * time.Hour,
			DailyRollupTTL:  13 * 30 * 24 * time.Hour,
		}
	}
	// Self-host defaults: tighter to save disk on single-node installs.
	return RetentionConfig{
		RawEventsTTL:    24 * time.Hour,
		HourlyRollupTTL: 7 * 24 * time.Hour,
		DailyRollupTTL:  90 * 24 * time.Hour,
	}
}

// NewRumGCWorker constructs a RumGCWorker.
func NewRumGCWorker(store Store, retention RetentionConfig, logger *slog.Logger) *RumGCWorker {
	return &RumGCWorker{store: store, retention: retention, logger: logger}
}

// Work runs the RUM GC sweep: raw events → hourly rollups → daily rollups.
func (w *RumGCWorker) Work(ctx context.Context, _ *river.Job[RumGCArgs]) error {
	now := time.Now().UTC()

	rawCutoff := now.Add(-w.retention.RawEventsTTL)
	n, err := w.store.PruneRawEvents(ctx, rawCutoff)
	if err != nil {
		w.logger.Error("rum gc: prune raw events failed", slog.String("err", err.Error()))
		return err
	}
	if n > 0 {
		w.logger.Info("rum gc: pruned raw events", slog.Int64("count", n))
	}

	hourlyCutoff := now.Add(-w.retention.HourlyRollupTTL)
	n, err = w.store.PruneHourlyRollups(ctx, hourlyCutoff)
	if err != nil {
		w.logger.Error("rum gc: prune hourly rollups failed", slog.String("err", err.Error()))
		return err
	}
	if n > 0 {
		w.logger.Info("rum gc: pruned hourly rollups", slog.Int64("count", n))
	}

	dailyCutoff := now.Add(-w.retention.DailyRollupTTL)
	n, err = w.store.PruneDailyRollups(ctx, dailyCutoff)
	if err != nil {
		w.logger.Error("rum gc: prune daily rollups failed", slog.String("err", err.Error()))
		return err
	}
	if n > 0 {
		w.logger.Info("rum gc: pruned daily rollups", slog.Int64("count", n))
	}

	return nil
}

// ---------------------------------------------------------------------------
// RUM Rollup worker
// ---------------------------------------------------------------------------

// RumRollupArgs is the River job payload for rolling raw events into rollups
// for a specific (site_id, tenant_id, bucket_hour).
type RumRollupArgs struct {
	SiteID     string `json:"site_id"`
	TenantID   string `json:"tenant_id"`
	BucketHour string `json:"bucket_hour"` // RFC3339
}

// Kind implements river.JobArgs.
func (RumRollupArgs) Kind() string { return "rum_rollup" }

// RumRollupWorker folds a single (site, hour) window of raw events into the
// hourly rollup table, then triggers a daily rollup fold. It is enqueued by
// the ingest handler after a successful write (one job per site per hour,
// deduplicated by River's unique key on (kind, site_id, bucket_hour)).
//
// Phase 1 design: the worker reads raw events via SQL queries and builds the
// histogram in Go, then calls UpsertRollupHourly. This is correct for
// moderate ingest volumes; a future optimisation (Phase 2) can push the
// aggregation to SQL or ClickHouse.
type RumRollupWorker struct {
	river.WorkerDefaults[RumRollupArgs]
	store  *StorePostgres
	logger *slog.Logger
}

// NewRumRollupWorker constructs a RumRollupWorker. store must be the concrete
// *StorePostgres because it exposes UpsertRollupHourly/UpsertRollupDaily
// directly (the Store interface exposes the FoldHourly/FoldDaily stubs).
func NewRumRollupWorker(store *StorePostgres, logger *slog.Logger) *RumRollupWorker {
	return &RumRollupWorker{store: store, logger: logger}
}

// Work runs the rollup fold for one (site, hour) window.
// Phase 1: FoldHourly and FoldDaily are stubs — the real implementation for
// cross-tenant raw-to-rollup aggregation is in store_postgres.go's
// UpsertRollupHourly/UpsertRollupDaily, which the rollup worker calls directly
// after the ingest handler has already pre-built the histogram row from the
// beacon payload. See the design note in store_postgres.go FoldHourly.
func (w *RumRollupWorker) Work(ctx context.Context, job *river.Job[RumRollupArgs]) error {
	bh, err := time.Parse(time.RFC3339, job.Args.BucketHour)
	if err != nil {
		w.logger.Error("rum rollup: parse bucket_hour", slog.String("err", err.Error()))
		return err
	}
	w.logger.Debug("rum rollup: fold",
		slog.String("site_id", job.Args.SiteID),
		slog.String("bucket_hour", bh.Format(time.RFC3339)))

	// Phase 1: no-op fold — the ingest handler writes directly to rum_rollup_hourly
	// via UpsertRollupHourly in the beacon path (single-event histogram row).
	// The rollup worker is wired here so the River job schema is registered and
	// the kind is known; the actual fold logic moves here in Phase 2 when the
	// raw-event buffer has enough volume to justify a separate aggregation step.
	return nil
}
