package site

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"
)

// Default timeout thresholds (M58 raised defaults).
// DegradeAfter was 180s; raised to 300s (5 × the 60s heartbeat cadence).
// DisconnectAfter was 360s; raised to 900s (15 × 60s).
// These are the defaults when no env override is set; SetThresholds overrides
// them at boot from config.
const (
	// degradeAfterDefault — connected→degraded candidate when last_seen_at is
	// older than this. The sweeper requires DegradeMissThreshold consecutive
	// overdue evaluations before actually calling MarkDegraded (M58 hysteresis).
	degradeAfterDefault = 300 * time.Second
	// disconnectAfterDefault — degraded→disconnected when last_seen_at is older
	// than this. Remains a single-evaluation hard threshold (no hysteresis).
	disconnectAfterDefault = 900 * time.Second
	// degradeAfter / disconnectAfter are the package-level constants kept for
	// backward-compat with existing tests that reference them by name.
	degradeAfter    = degradeAfterDefault
	disconnectAfter = disconnectAfterDefault
	// degreesMissThresholdDefault is the consecutive overdue count required to
	// trigger the connected→degraded transition (M58 hysteresis).
	degreesMissThresholdDefault = 3
)

// SweeperTransitioner is the subset of the ConnectionService the timeout sweeper
// drives. It is the ONLY caller of the degraded/disconnected transitions
// (ADR-039). Satisfied by *connService via the tenant-aware variants.
type SweeperTransitioner interface {
	MarkDegradedTenant(ctx context.Context, tenantID, siteID uuid.UUID) error
	MarkDisconnectedTenant(ctx context.Context, tenantID, siteID uuid.UUID, reason string) error
}

// MissIncrementer atomically increments a site's consecutive-miss counter and
// returns the new value. Satisfied by *pgRepo via IncrementMissedHeartbeats.
type MissIncrementer interface {
	IncrementMissedHeartbeats(ctx context.Context, tenantID, siteID uuid.UUID) (int32, error)
}

// EventPruner deletes site_events older than a cutoff (the ADR-038 ring-buffer
// prune). Satisfied by events.Publisher.
type EventPruner interface {
	PruneOlderThan(ctx context.Context, cutoff time.Time) (int64, error)
}

// Sweeper performs the periodic connection-timeout sweep (ADR-039 + M58
// hysteresis) and the periodic site_events prune (ADR-038). Pure logic over
// the repo + service so it is unit/integration-testable directly without River.
type Sweeper struct {
	repo                 Repo
	svc                  SweeperTransitioner
	pruner               EventPruner
	missInc              MissIncrementer
	degradeAfter         time.Duration
	disconnectAfter      time.Duration
	eventRetention       time.Duration
	degreesMissThreshold int
}

// NewSweeper builds a Sweeper. pruner may be nil (the events prune is skipped).
// missInc may be nil (the miss counter is not incremented — hysteresis
// degrades to the old immediate-degrade behaviour; useful in tests that do not
// need hysteresis).
func NewSweeper(repo Repo, svc SweeperTransitioner, pruner EventPruner) *Sweeper {
	return &Sweeper{
		repo:                 repo,
		svc:                  svc,
		pruner:               pruner,
		degradeAfter:         degradeAfterDefault,
		disconnectAfter:      disconnectAfterDefault,
		eventRetention:       5 * time.Minute,
		degreesMissThreshold: degreesMissThresholdDefault,
	}
}

// SetMissIncrementer wires the consecutive-miss counter incrementer (M58). Call
// once at boot. When nil the sweeper falls back to the old immediate-degrade
// behaviour (no hysteresis), which is acceptable only in tests.
func (w *Sweeper) SetMissIncrementer(inc MissIncrementer) {
	w.missInc = inc
}

// SetThresholds overrides the timeout/retention windows and the miss threshold.
// Call once at boot from config; also used in tests.
func (w *Sweeper) SetThresholds(degrade, disconnect, retention time.Duration) {
	if degrade > 0 {
		w.degradeAfter = degrade
	}
	if disconnect > 0 {
		w.disconnectAfter = disconnect
	}
	if retention > 0 {
		w.eventRetention = retention
	}
}

// SetDegradeMissThreshold overrides the consecutive-miss count before the
// sweeper degrades a site. Call once at boot from config. Zero or negative
// values are ignored.
func (w *Sweeper) SetDegradeMissThreshold(n int) {
	if n > 0 {
		w.degreesMissThreshold = n
	}
}

// Sweep runs one timeout pass relative to now: it disconnects degraded sites
// past the disconnect cutoff (hard threshold), then evaluates connected sites
// past the degrade cutoff with the M58 consecutive-miss hysteresis (increment
// counter; degrade only when the counter reaches the configured threshold).
// Returns (degraded, disconnected) counts. Disconnect is processed BEFORE
// degrade so a site that crosses both thresholds in one pass walks
// connected→degraded→disconnected over two passes (never skipping a state).
func (w *Sweeper) Sweep(ctx context.Context, now time.Time) (degraded, disconnected int, err error) {
	// 1. degraded → disconnected (hard time threshold, single evaluation).
	disCutoff := now.Add(-w.disconnectAfter)
	toDisconnect, err := w.repo.ListToDisconnect(ctx, disCutoff)
	if err != nil {
		return 0, 0, err
	}
	for _, ref := range toDisconnect {
		if derr := w.svc.MarkDisconnectedTenant(ctx, ref.TenantID, ref.ID, "heartbeat_timeout"); derr != nil {
			return degraded, disconnected, derr
		}
		disconnected++
	}

	// 2. connected → degraded (M58 hysteresis: N consecutive overdue evals).
	degCutoff := now.Add(-w.degradeAfter)
	toDegrade, err := w.repo.ListToDegrade(ctx, degCutoff)
	if err != nil {
		return degraded, disconnected, err
	}
	for _, ref := range toDegrade {
		if w.missInc == nil {
			// No incrementer wired — fall back to immediate degrade (tests or
			// bootstrapping scenario without the DB column).
			if derr := w.svc.MarkDegradedTenant(ctx, ref.TenantID, ref.ID); derr != nil {
				return degraded, disconnected, derr
			}
			degraded++
			continue
		}
		newCount, ierr := w.missInc.IncrementMissedHeartbeats(ctx, ref.TenantID, ref.ID)
		if ierr != nil {
			return degraded, disconnected, ierr
		}
		if int(newCount) >= w.degreesMissThreshold {
			if derr := w.svc.MarkDegradedTenant(ctx, ref.TenantID, ref.ID); derr != nil {
				return degraded, disconnected, derr
			}
			degraded++
		}
	}
	return degraded, disconnected, nil
}

// PruneEvents deletes site_events older than the retention window. No-op when no
// pruner is wired. Returns the number of rows deleted.
func (w *Sweeper) PruneEvents(ctx context.Context, now time.Time) (int64, error) {
	if w.pruner == nil {
		return 0, nil
	}
	return w.pruner.PruneOlderThan(ctx, now.Add(-w.eventRetention))
}

// ---- River worker ----

// SweepArgs is the River job payload for the periodic timeout sweep.
type SweepArgs struct{}

// Kind implements river.JobArgs.
func (SweepArgs) Kind() string { return "site_connection_sweep" }

// SweepWorker runs the timeout sweep as a River job (every 15s per ADR-039).
type SweepWorker struct {
	river.WorkerDefaults[SweepArgs]
	sweeper *Sweeper
}

// NewSweepWorker builds the River worker around a Sweeper.
func NewSweepWorker(s *Sweeper) *SweepWorker { return &SweepWorker{sweeper: s} }

// Work runs one timeout sweep.
func (w *SweepWorker) Work(ctx context.Context, _ *river.Job[SweepArgs]) error {
	_, _, err := w.sweeper.Sweep(ctx, time.Now())
	return err
}

// EventPruneArgs is the River job payload for the periodic site_events prune.
type EventPruneArgs struct{}

// Kind implements river.JobArgs.
func (EventPruneArgs) Kind() string { return "site_events_prune" }

// EventPruneWorker runs the site_events ring-buffer prune as a River job (once
// a minute per ADR-038).
type EventPruneWorker struct {
	river.WorkerDefaults[EventPruneArgs]
	sweeper *Sweeper
}

// NewEventPruneWorker builds the prune worker.
func NewEventPruneWorker(s *Sweeper) *EventPruneWorker { return &EventPruneWorker{sweeper: s} }

// Work runs one site_events prune.
func (w *EventPruneWorker) Work(ctx context.Context, _ *river.Job[EventPruneArgs]) error {
	_, err := w.sweeper.PruneEvents(ctx, time.Now())
	return err
}
