package media

import (
	"context"
	"errors"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
)

// RiverEnqueuer inserts media_encode jobs into River. It lives in the PURE-Go
// top-level media package (NO encoder import) so BOTH the main API and the
// media-encoder process can construct it. The main API registers the
// media_encode queue with MaxWorkers=0 (insert-only); the encoder process runs
// the workers. It satisfies service.EncodeEnqueuer.
type RiverEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewRiverEnqueuer builds an enqueuer around the River client.
func NewRiverEnqueuer(client *river.Client[pgx.Tx]) *RiverEnqueuer {
	return &RiverEnqueuer{client: client}
}

// EnqueueEncode inserts one media_encode job and returns the assigned River job
// ID. The River job ID is stored on the media_optimization_jobs row (m51) so
// the cancel path can cancel the River job proactively.
func (e *RiverEnqueuer) EnqueueEncode(ctx context.Context, args model.EncodeArgs) (int64, error) {
	res, err := e.client.Insert(ctx, args, nil)
	if err != nil {
		return 0, fmt.Errorf("enqueue media_encode: %w", err)
	}
	return res.Job.ID, nil
}

// CancelEncodeJob cancels an already-enqueued media_encode River job by its
// River job ID. It is best-effort and idempotent:
//   - If the job is already terminal (completed, cancelled, discarded) River
//     returns the job row unchanged — we treat that as success.
//   - If the job ID is not found (ErrNotFound) we treat that as success (the
//     job already ran and was deleted, or was never persisted).
//   - Any other error is returned so the caller can log it.
func (e *RiverEnqueuer) CancelEncodeJob(ctx context.Context, riverJobID int64) error {
	_, err := e.client.JobCancel(ctx, riverJobID)
	if err == nil {
		return nil
	}
	if errors.Is(err, river.ErrNotFound) {
		// Already gone — treat as success; the job ran to completion or was
		// cleaned up before we could cancel it.
		return nil
	}
	return fmt.Errorf("cancel media_encode river job %d: %w", riverJobID, err)
}
