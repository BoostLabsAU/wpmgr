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
