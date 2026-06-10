package email

import (
	"context"
	"log/slog"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"

	// tzdata embeds the full IANA timezone database so nextDigestAt can
	// call time.LoadLocation without relying on the host OS timezone data.
	_ "time/tzdata"
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

// ---------------------------------------------------------------------------
// m62 Area 1 — Org-config propagation worker
// ---------------------------------------------------------------------------

// OrgConfigPropagateArgs is the River job payload for org-config fan-out.
type OrgConfigPropagateArgs struct {
	TenantID uuid.UUID `json:"tenant_id"`
}

// Kind implements river.JobArgs.
func (OrgConfigPropagateArgs) Kind() string { return "email_org_config_propagate" }

// OrgConfigPropagateWorker fans the org-wide email config out to all enrolled
// inheriting sites for the tenant. At most 8 sites are pushed concurrently.
// Per-site failures are logged but never fatal for the job.
type OrgConfigPropagateWorker struct {
	river.WorkerDefaults[OrgConfigPropagateArgs]
	svc    *Service
	logger *slog.Logger
}

// Timeout overrides the default River job timeout to 15 minutes.
func (w *OrgConfigPropagateWorker) Timeout(_ *river.Job[OrgConfigPropagateArgs]) time.Duration {
	return 15 * time.Minute
}

// NewOrgConfigPropagateWorker constructs the worker.
func NewOrgConfigPropagateWorker(svc *Service, logger *slog.Logger) *OrgConfigPropagateWorker {
	return &OrgConfigPropagateWorker{svc: svc, logger: logger}
}

// Work runs the propagation fan-out.
func (w *OrgConfigPropagateWorker) Work(ctx context.Context, job *river.Job[OrgConfigPropagateArgs]) error {
	result, err := w.svc.PropagateOrgConfig(ctx, job.Args.TenantID)
	if err != nil {
		w.logger.Error("email propagate: failed",
			slog.String("tenant_id", job.Args.TenantID.String()),
			slog.Any("error", err),
		)
		return err
	}
	if result.Total > 0 {
		w.logger.Info("email propagate: completed",
			slog.String("tenant_id", job.Args.TenantID.String()),
			slog.Int("synced", result.Synced),
			slog.Int("failed", result.Failed),
			slog.Int("total", result.Total),
		)
	}
	return nil
}

// ---------------------------------------------------------------------------
// m62 Area 4 — Digest scheduler worker
// ---------------------------------------------------------------------------

// DigestArgs is the River job payload for the digest scheduler tick.
type DigestArgs struct{}

// Kind implements river.JobArgs.
func (DigestArgs) Kind() string { return "email_digest_scheduler" }

// DigestWorker checks for due email digests on every periodic tick (hourly)
// and sends them. The claim-advance pattern ensures each digest period is sent
// exactly once even under concurrent worker instances.
type DigestWorker struct {
	river.WorkerDefaults[DigestArgs]
	svc    *Service
	logger *slog.Logger
}

// NewDigestWorker constructs the worker.
func NewDigestWorker(svc *Service, logger *slog.Logger) *DigestWorker {
	return &DigestWorker{svc: svc, logger: logger}
}

// Work scans for due digest rows, claims each atomically, and sends the digest.
func (w *DigestWorker) Work(ctx context.Context, _ *river.Job[DigestArgs]) error {
	// Fetch all due digest rows (next_digest_at <= now, digest_enabled=true).
	due, err := w.svc.repo.ListDueDigests(ctx, 100)
	if err != nil {
		w.logger.Error("email digest: list due failed", slog.Any("error", err))
		return err
	}

	for _, settings := range due {
		if !settings.Enabled || !settings.DigestEnabled {
			continue
		}
		if len(settings.Recipients) == 0 {
			continue
		}

		// Compute the period window [prev, now).
		now := time.Now().UTC()
		var from time.Time
		if settings.NextDigestAt != nil {
			// The period start was the *previous* next_digest_at value;
			// use the one we're about to claim.
			from = *settings.NextDigestAt
		} else {
			from = now.Add(-7 * 24 * time.Hour) // fallback: last week
		}
		// Compute the next scheduled time.
		newNextAt := nextDigestAt(settings.DigestCadence, settings.DigestDay, settings.DigestHour, settings.Timezone)
		if newNextAt == nil {
			continue
		}

		// Claim: atomically advance next_digest_at. Concurrent workers that
		// race on the same row will get pgx.ErrNoRows (mapped to ErrNotFound).
		_, claimErr := w.svc.repo.ClaimAdvanceDigest(ctx, settings.TenantID, *newNextAt)
		if claimErr != nil {
			if claimErr == ErrNotFound {
				// Another worker already claimed this row — skip.
				continue
			}
			w.logger.Warn("email digest: claim failed",
				slog.String("tenant_id", settings.TenantID.String()),
				slog.Any("error", claimErr),
			)
			continue
		}

		// Build and send digest; skip when no activity.
		data, buildErr := w.svc.buildDigestData(ctx, settings.TenantID, settings, from, now)
		if buildErr != nil {
			w.logger.Warn("email digest: build data failed",
				slog.String("tenant_id", settings.TenantID.String()),
				slog.Any("error", buildErr),
			)
			continue
		}
		if data == nil {
			// Total=0 — skip per spec.
			continue
		}

		if w.svc.mailer == nil {
			continue
		}
		if err := w.svc.mailer.Enqueue(ctx, settings.TenantID, settings.Recipients, "email_digest", data); err != nil {
			w.logger.Warn("email digest: enqueue failed",
				slog.String("tenant_id", settings.TenantID.String()),
				slog.Any("error", err),
			)
		} else {
			w.logger.Info("email digest: sent",
				slog.String("tenant_id", settings.TenantID.String()),
				slog.Int("recipients", len(settings.Recipients)),
			)
		}
	}
	return nil
}
