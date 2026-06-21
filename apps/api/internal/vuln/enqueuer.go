package vuln

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"
)

// RiverRescanEnqueuer satisfies RescanEnqueuer using a River client.
type RiverRescanEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverRescanEnqueuer builds a RiverRescanEnqueuer.
func NewRiverRescanEnqueuer(client *river.Client[pgx.Tx]) *RiverRescanEnqueuer {
	return &RiverRescanEnqueuer{client: client}
}

// EnqueueRescanSite inserts a RescanSiteArgs job into the rescan River queue.
func (e *RiverRescanEnqueuer) EnqueueRescanSite(ctx context.Context, args RescanSiteArgs) error {
	_, err := e.client.Insert(ctx, args, &river.InsertOpts{
		Queue: RescanSiteQueue,
	})
	if err != nil {
		return fmt.Errorf("enqueue vuln rescan site: %w", err)
	}
	return nil
}

// FeedRefreshEnqueuer enqueues an immediate Wordfence feed refresh job.
// Implemented by RiverFeedRefreshEnqueuer; the admin package depends on this
// interface (not the concrete type) to avoid an import cycle.
type FeedRefreshEnqueuer interface {
	EnqueueFeedRefresh(ctx context.Context) error
}

// RiverFeedRefreshEnqueuer satisfies FeedRefreshEnqueuer using a River client.
type RiverFeedRefreshEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverFeedRefreshEnqueuer builds a RiverFeedRefreshEnqueuer.
func NewRiverFeedRefreshEnqueuer(client *river.Client[pgx.Tx]) *RiverFeedRefreshEnqueuer {
	return &RiverFeedRefreshEnqueuer{client: client}
}

// EnqueueFeedRefresh inserts a FeedRefreshArgs job. The job is deduplicated by
// ByArgs so at most one is pending/running at a time; a no-op insert is returned
// when one is already queued.
func (e *RiverFeedRefreshEnqueuer) EnqueueFeedRefresh(ctx context.Context) error {
	_, err := e.client.Insert(ctx, FeedRefreshArgs{}, &river.InsertOpts{
		Queue: FeedRefreshQueue,
		UniqueOpts: river.UniqueOpts{
			ByArgs:   true,
			ByPeriod: 5 * 60 * 1_000_000_000, // 5 minutes — same-key re-trigger is idempotent
		},
	})
	if err != nil {
		return fmt.Errorf("enqueue vuln feed refresh: %w", err)
	}
	return nil
}
