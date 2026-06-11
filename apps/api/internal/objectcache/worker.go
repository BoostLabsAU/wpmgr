package objectcache

import (
	"context"
	"log/slog"
	"time"

	"github.com/riverqueue/river"
)

// ---------------------------------------------------------------------------
// ObjectCacheStatsHistoryGCArgs - River periodic GC job
// ---------------------------------------------------------------------------

// ObjectCacheStatsHistoryGCArgs is the River job payload for the periodic
// stats-history GC. No fields: the worker uses fixed retention windows
// (7 days for raw rows: D4 decision).
type ObjectCacheStatsHistoryGCArgs struct{}

// Kind implements river.JobArgs.
func (ObjectCacheStatsHistoryGCArgs) Kind() string { return "objectcache_stats_history_gc" }

// ObjectCacheStatsHistoryGCWorker deletes site_object_cache_stats_history rows
// older than the raw retention window (7 days, per D4). It runs cross-tenant
// under InAgentTx (app.agent = 'on'), matching the CacheHitRatioHistoryGCWorker
// pattern. LIMIT 2000 per pass keeps each transaction short.
type ObjectCacheStatsHistoryGCWorker struct {
	river.WorkerDefaults[ObjectCacheStatsHistoryGCArgs]
	repo   *Repo
	logger *slog.Logger
}

// rawRetention is the D4-locked raw data retention window (7 days).
const rawRetention = 7 * 24 * time.Hour

// NewObjectCacheStatsHistoryGCWorker builds the GC worker.
func NewObjectCacheStatsHistoryGCWorker(repo *Repo, logger *slog.Logger) *ObjectCacheStatsHistoryGCWorker {
	if logger == nil {
		logger = slog.Default()
	}
	return &ObjectCacheStatsHistoryGCWorker{repo: repo, logger: logger}
}

// Work deletes rows older than 7 days in a single cross-tenant pass (InAgentTx).
func (w *ObjectCacheStatsHistoryGCWorker) Work(ctx context.Context, _ *river.Job[ObjectCacheStatsHistoryGCArgs]) error {
	cutoff := time.Now().UTC().Add(-rawRetention)
	deleted, err := w.repo.PruneHistory(ctx, cutoff)
	if err != nil {
		w.logger.Warn("objectcache stats history GC error", slog.Any("error", err))
		return err
	}
	if deleted > 0 {
		w.logger.Info("objectcache stats history GC", slog.Int64("rows_deleted", deleted))
	}
	return nil
}
