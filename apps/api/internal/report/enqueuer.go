package report

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"
)

// RiverEnqueuer enqueues GenerateArgs onto River. It satisfies Enqueuer.
type RiverEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverEnqueuer builds a RiverEnqueuer around the started River client.
func NewRiverEnqueuer(client *river.Client[pgx.Tx]) *RiverEnqueuer {
	return &RiverEnqueuer{client: client}
}

// EnqueueGenerate inserts one report_generate job.
func (e *RiverEnqueuer) EnqueueGenerate(ctx context.Context, args GenerateArgs) error {
	if _, err := e.client.Insert(ctx, args, nil); err != nil {
		return fmt.Errorf("enqueue report_generate: %w", err)
	}
	return nil
}
