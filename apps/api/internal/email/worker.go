package email

import (
	"context"
	"log/slog"
	"time"

	"github.com/riverqueue/river"
)

// ---------------------------------------------------------------------------
// Webhook dedup GC worker (Phase 4a)
// ---------------------------------------------------------------------------

// WebhookDedupGCArgs is the River job payload for the webhook dedup GC sweep.
type WebhookDedupGCArgs struct{}

// Kind implements river.JobArgs.
func (WebhookDedupGCArgs) Kind() string { return "email_webhook_dedup_gc" }

// webhookDedupRetention is how long dedup rows are kept. 7 days is intentionally
// shorter than any provider's maximum retry window so the table stays small while
// still covering all realistic re-deliveries.
const webhookDedupRetention = 7 * 24 * time.Hour

// WebhookDedupGCWorker prunes email_webhook_events rows older than 7 days.
type WebhookDedupGCWorker struct {
	river.WorkerDefaults[WebhookDedupGCArgs]
	svc    *Service
	logger *slog.Logger
}

// NewWebhookDedupGCWorker constructs the worker.
func NewWebhookDedupGCWorker(svc *Service, logger *slog.Logger) *WebhookDedupGCWorker {
	return &WebhookDedupGCWorker{svc: svc, logger: logger}
}

// Work runs the GC sweep.
func (w *WebhookDedupGCWorker) Work(ctx context.Context, _ *river.Job[WebhookDedupGCArgs]) error {
	cutoff := time.Now().UTC().Add(-webhookDedupRetention)
	deleted, err := w.svc.PruneWebhookDedup(ctx, cutoff)
	if err != nil {
		w.logger.Error("webhook dedup gc: prune failed", slog.String("err", err.Error()))
		return err
	}
	if deleted > 0 {
		w.logger.Info("webhook dedup gc: completed",
			slog.Int64("rows_deleted", deleted),
			slog.Time("cutoff", cutoff),
		)
	}
	return nil
}

// ---------------------------------------------------------------------------
// Email log retention GC worker (Phase 3)
// ---------------------------------------------------------------------------

// EmailLogGCArgs is the River job payload for the email log retention-GC sweep.
type EmailLogGCArgs struct{}

// Kind implements river.JobArgs.
func (EmailLogGCArgs) Kind() string { return "email_log_gc" }

// EmailLogGCWorker deletes expired site_email_log rows. It respects the
// per-site retention_days from site_email_config (default 14 days). The GC
// runs cross-tenant under InAgentTx. It loops until the batch returns 0 rows
// so that a single periodic invocation drains a large backlog without relying
// on frequent scheduling.
//
// A hard cutoff of 366 days is applied as a safety backstop so no log row can
// survive more than a year regardless of the per-site setting.
type EmailLogGCWorker struct {
	river.WorkerDefaults[EmailLogGCArgs]
	svc    *Service
	logger *slog.Logger
}

// hardCutoff is the absolute maximum age for any email log row. Rows older
// than this are deleted even if the per-site retention_days would allow them
// to survive longer.
const hardCutoff = 366 * 24 * time.Hour

// gcBatchSize is the number of rows deleted per batch iteration. Kept small
// enough to avoid long table locks on a busy table.
const gcBatchSize = 1000

// NewEmailLogGCWorker constructs the worker.
func NewEmailLogGCWorker(svc *Service, logger *slog.Logger) *EmailLogGCWorker {
	return &EmailLogGCWorker{svc: svc, logger: logger}
}

// Work runs the GC sweep. It issues batched DELETEs until the batch returns 0,
// then returns. River's default retry policy handles transient failures.
func (w *EmailLogGCWorker) Work(ctx context.Context, _ *river.Job[EmailLogGCArgs]) error {
	cutoff := time.Now().UTC().Add(-hardCutoff)
	total := int64(0)
	for {
		deleted, err := w.svc.PruneOldLogs(ctx, cutoff, gcBatchSize)
		if err != nil {
			w.logger.Error("email log gc: prune batch failed", slog.String("err", err.Error()))
			return err
		}
		total += deleted
		if deleted == 0 {
			break
		}
	}
	if total > 0 {
		w.logger.Info("email log gc: completed",
			slog.Int64("rows_deleted", total),
			slog.Time("cutoff", cutoff),
		)
	}
	return nil
}
