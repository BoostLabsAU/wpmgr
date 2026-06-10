package email

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"
)

// Enqueuer is the interface the Service uses to enqueue River jobs.
// *RiverEnqueuer satisfies it. Declared as an interface so the service is
// unit-testable with a fake enqueuer.
type Enqueuer interface {
	EnqueueOrgConfigPropagate(ctx context.Context, tenantID uuid.UUID) error
}

// RiverEnqueuer enqueues email jobs onto River. It satisfies Enqueuer.
type RiverEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverEnqueuer builds a RiverEnqueuer around the River client.
func NewRiverEnqueuer(client *river.Client[pgx.Tx]) *RiverEnqueuer {
	return &RiverEnqueuer{client: client}
}

// EnqueueOrgConfigPropagate inserts an org-config propagation job.
// No UniqueOpts — each org-config save enqueues independently; the worker is
// idempotent (option upsert on the agent) so duplicate runs are safe.
func (e *RiverEnqueuer) EnqueueOrgConfigPropagate(ctx context.Context, tenantID uuid.UUID) error {
	if _, err := e.client.Insert(ctx, OrgConfigPropagateArgs{TenantID: tenantID}, nil); err != nil {
		return fmt.Errorf("enqueue email_org_config_propagate: %w", err)
	}
	return nil
}
