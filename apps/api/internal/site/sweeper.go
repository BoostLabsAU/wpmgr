package site

import (
	"context"
	"log/slog"
	"sync"
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

	// defaultVerifyTimeout is the per-dial timeout for active liveness checks.
	defaultVerifyTimeout = 8 * time.Second
	// defaultVerifyConcurrency is the maximum number of concurrent active-verify
	// dials per sweep tick.
	defaultVerifyConcurrency = 8
	// defaultSweepBudget is the wall-clock budget for the active-verify phase
	// of each sweep tick. Sites not verified within this window are skipped
	// (they re-enter the list on the next 15 s tick).
	defaultSweepBudget = 12 * time.Second
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

// HeartbeatRecorder can reset the miss counter and refresh last_seen_at for a
// site (the active-verify recovery path). Satisfied by ConnectionService.
type HeartbeatRecorder interface {
	RecordHeartbeat(ctx context.Context, in HeartbeatInput) (HeartbeatResult, error)
}

// AgentVerifier performs an active CP-initiated liveness check against a site's
// agent. Implemented by *agentcmd.Client via VerifyReachable.
//
// Returns (alive=true) when the agent answers the ping or metadata command,
// (alive=false, err=nil) when the agent is unreachable, and (false, err) on
// an unexpected infrastructure failure (JWT-mint failure etc.).
// fallbackUsed indicates that the ping command was not available on the agent
// and the metadata command was used instead (old-agent path).
type AgentVerifier interface {
	VerifyReachable(ctx context.Context, siteID uuid.UUID, siteURL string) (alive bool, fallbackUsed bool, err error)
}

// Sweeper performs the periodic connection-timeout sweep (ADR-039 + M58
// hysteresis + 0.44.0 active verify) and the periodic site_events prune
// (ADR-038). Pure logic over the repo + service so it is unit/integration-
// testable directly without River.
type Sweeper struct {
	repo                 Repo
	svc                  SweeperTransitioner
	pruner               EventPruner
	missInc              MissIncrementer
	heartbeatRec         HeartbeatRecorder
	verifier             AgentVerifier
	degradeAfter         time.Duration
	disconnectAfter      time.Duration
	eventRetention       time.Duration
	degreesMissThreshold int
	// active-verify knobs (0.44.0)
	activeVerify      bool
	verifyTimeout     time.Duration
	verifyConcurrency int
	sweepBudget       time.Duration
	logger            *slog.Logger
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
		activeVerify:         true,
		verifyTimeout:        defaultVerifyTimeout,
		verifyConcurrency:    defaultVerifyConcurrency,
		sweepBudget:          defaultSweepBudget,
	}
}

// SetMissIncrementer wires the consecutive-miss counter incrementer (M58). Call
// once at boot. When nil the sweeper falls back to the old immediate-degrade
// behaviour (no hysteresis), which is acceptable only in tests.
func (w *Sweeper) SetMissIncrementer(inc MissIncrementer) {
	w.missInc = inc
}

// SetHeartbeatRecorder wires the service that resets the miss counter and
// refreshes last_seen_at when an active verify succeeds. Call once at boot.
// When nil, a successful active verify is logged but the counter is not reset
// (the site will degrade on the next tick unless a passive heartbeat arrives).
func (w *Sweeper) SetHeartbeatRecorder(rec HeartbeatRecorder) {
	w.heartbeatRec = rec
}

// SetVerifier wires the active-verify dialer (0.44.0). Call once at boot.
// When nil (or when activeVerify=false) the sweeper operates in passive mode —
// identical to the pre-0.44.0 behaviour.
func (w *Sweeper) SetVerifier(v AgentVerifier) {
	w.verifier = v
}

// SetActiveVerify enables or disables the active-verify phase. When false, the
// sweeper degrades/disconnects purely on the passive heartbeat staleness
// threshold (pre-0.44.0 behaviour). Controlled by WPMGR_SWEEP_ACTIVE_VERIFY.
func (w *Sweeper) SetActiveVerify(enabled bool) {
	w.activeVerify = enabled
}

// SetVerifyTimeout overrides the per-dial timeout (default 8 s).
// Controlled by WPMGR_SWEEP_VERIFY_TIMEOUT.
func (w *Sweeper) SetVerifyTimeout(d time.Duration) {
	if d > 0 {
		w.verifyTimeout = d
	}
}

// SetVerifyConcurrency overrides the maximum concurrent dials per tick
// (default 8). Controlled by WPMGR_SWEEP_VERIFY_CONCURRENCY.
func (w *Sweeper) SetVerifyConcurrency(n int) {
	if n > 0 {
		w.verifyConcurrency = n
	}
}

// SetLogger wires a structured logger. When nil, verify outcomes are silently
// discarded (acceptable in tests).
func (w *Sweeper) SetLogger(l *slog.Logger) {
	w.logger = l
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

// verifyResult is the outcome of one active-verify dial.
type verifyResult struct {
	ref          SiteRef
	alive        bool
	fallbackUsed bool
	duration     time.Duration
	err          error
	skipped      bool // budget exhausted before this site was checked
}

// activeVerifyBatch runs concurrent active-verify dials for the given sites,
// bounded by w.verifyConcurrency and the supplied deadline. Sites that cannot
// be reached before the deadline are marked skipped (no state transition).
func (w *Sweeper) activeVerifyBatch(ctx context.Context, refs []SiteRef, deadline time.Time) []verifyResult {
	results := make([]verifyResult, len(refs))
	if w.verifier == nil || len(refs) == 0 {
		for i, ref := range refs {
			results[i] = verifyResult{ref: ref, skipped: true}
		}
		return results
	}

	sem := make(chan struct{}, w.verifyConcurrency)
	var wg sync.WaitGroup
	for i, ref := range refs {
		i, ref := i, ref

		// Check the sweep budget before launching a new goroutine.
		if time.Now().After(deadline) {
			results[i] = verifyResult{ref: ref, skipped: true}
			continue
		}

		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()

			remaining := time.Until(deadline)
			if remaining <= 0 {
				results[i] = verifyResult{ref: ref, skipped: true}
				return
			}
			timeout := w.verifyTimeout
			if remaining < timeout {
				timeout = remaining
			}
			dialCtx, cancel := context.WithTimeout(ctx, timeout)
			defer cancel()

			start := time.Now()
			alive, fallback, err := w.verifier.VerifyReachable(dialCtx, ref.ID, ref.URL)
			results[i] = verifyResult{
				ref:          ref,
				alive:        alive,
				fallbackUsed: fallback,
				duration:     time.Since(start),
				err:          err,
			}
		}()
	}
	wg.Wait()
	return results
}

// logVerify emits a structured debug/info log line for an active-verify
// outcome. No-op when no logger is wired.
func (w *Sweeper) logVerify(phase string, r verifyResult) {
	if w.logger == nil {
		return
	}
	if r.skipped {
		w.logger.Debug("sweep active verify skipped (budget exhausted)",
			slog.String("phase", phase),
			slog.String("site_id", r.ref.ID.String()),
		)
		return
	}
	lvl := slog.LevelDebug
	if !r.alive {
		lvl = slog.LevelInfo
	}
	w.logger.Log(context.Background(), lvl, "sweep active verify",
		slog.String("phase", phase),
		slog.String("site_id", r.ref.ID.String()),
		slog.Bool("alive", r.alive),
		slog.Bool("fallback_used", r.fallbackUsed),
		slog.Duration("duration", r.duration),
	)
}

// recordHeartbeat calls RecordHeartbeat on the wired recorder (if any). A
// failure here is best-effort and is swallowed: the site already passed a
// successful active-verify, so the liveness signal should not be discarded
// just because the DB write raced or failed transiently.
func (w *Sweeper) recordHeartbeat(ctx context.Context, ref SiteRef) {
	if w.heartbeatRec == nil {
		return
	}
	_, _ = w.heartbeatRec.RecordHeartbeat(ctx, HeartbeatInput{
		TenantID: ref.TenantID,
		SiteID:   ref.ID,
	})
}

// Sweep runs one timeout pass relative to now: it disconnects degraded sites
// past the disconnect cutoff (hard threshold), then evaluates connected sites
// past the degrade cutoff with the M58 consecutive-miss hysteresis (increment
// counter; degrade only when the counter reaches the configured threshold).
//
// When active verify is enabled (WPMGR_SWEEP_ACTIVE_VERIFY=true, the default),
// the sweeper dials the agent before executing either transition:
//
//   - Degrade path: when the miss counter would cross the threshold, first dial
//     the agent. A successful response resets the counter via RecordHeartbeat
//     and skips the degrade. A failed response degrades exactly as before.
//   - Disconnect path: before MarkDisconnectedTenant, dial the agent. A
//     successful response calls RecordHeartbeat (which may recover
//     degraded→connected). A failed response disconnects with reason
//     "agent_unreachable" instead of "heartbeat_timeout".
//
// Returns (degraded, disconnected) counts. Disconnect is processed BEFORE
// degrade so a site that crosses both thresholds in one pass walks
// connected→degraded→disconnected over two passes (never skipping a state).
func (w *Sweeper) Sweep(ctx context.Context, now time.Time) (degraded, disconnected int, err error) {
	budgetDeadline := now.Add(w.sweepBudget)

	// 1. degraded → disconnected (hard time threshold, single evaluation).
	disCutoff := now.Add(-w.disconnectAfter)
	toDisconnect, err := w.repo.ListToDisconnect(ctx, disCutoff)
	if err != nil {
		return 0, 0, err
	}

	if w.activeVerify && w.verifier != nil && len(toDisconnect) > 0 {
		vResults := w.activeVerifyBatch(ctx, toDisconnect, budgetDeadline)
		for _, vr := range vResults {
			w.logVerify("disconnect", vr)
			if vr.skipped {
				// Budget exhausted — skip this tick, no state change.
				continue
			}
			if vr.alive {
				// Agent is reachable — recover via RecordHeartbeat
				// (may transition degraded→connected) and skip the disconnect.
				w.recordHeartbeat(ctx, vr.ref)
				continue
			}
			// Agent unreachable — disconnect with the 0.44.0 reason string.
			if derr := w.svc.MarkDisconnectedTenant(ctx, vr.ref.TenantID, vr.ref.ID, "agent_unreachable"); derr != nil {
				return degraded, disconnected, derr
			}
			disconnected++
		}
	} else {
		// Passive mode: disconnect exactly as before.
		for _, ref := range toDisconnect {
			if derr := w.svc.MarkDisconnectedTenant(ctx, ref.TenantID, ref.ID, "heartbeat_timeout"); derr != nil {
				return degraded, disconnected, derr
			}
			disconnected++
		}
	}

	// 2. connected → degraded (M58 hysteresis: N consecutive overdue evals).
	degCutoff := now.Add(-w.degradeAfter)
	toDegrade, err := w.repo.ListToDegrade(ctx, degCutoff)
	if err != nil {
		return degraded, disconnected, err
	}

	// Identify which sites have crossed the miss threshold (need verify/degrade).
	// We must increment the counter first to know whether the threshold is
	// crossed, then conditionally verify before calling MarkDegradedTenant.
	type degradeCandidate struct {
		ref     SiteRef
		crossed bool // miss counter has crossed the threshold
	}
	candidates := make([]degradeCandidate, 0, len(toDegrade))
	for _, ref := range toDegrade {
		if w.missInc == nil {
			// No incrementer — fall back to immediate degrade (no hysteresis).
			candidates = append(candidates, degradeCandidate{ref: ref, crossed: true})
			continue
		}
		newCount, ierr := w.missInc.IncrementMissedHeartbeats(ctx, ref.TenantID, ref.ID)
		if ierr != nil {
			return degraded, disconnected, ierr
		}
		candidates = append(candidates, degradeCandidate{
			ref:     ref,
			crossed: int(newCount) >= w.degreesMissThreshold,
		})
	}

	// Collect the refs that actually need a transition decision.
	var toDegradeVerify []SiteRef
	for _, c := range candidates {
		if c.crossed {
			toDegradeVerify = append(toDegradeVerify, c.ref)
		}
	}

	if w.activeVerify && w.verifier != nil && len(toDegradeVerify) > 0 {
		vResults := w.activeVerifyBatch(ctx, toDegradeVerify, budgetDeadline)
		// Build a lookup by site ID for the verify results.
		vrByID := make(map[uuid.UUID]verifyResult, len(vResults))
		for _, vr := range vResults {
			vrByID[vr.ref.ID] = vr
		}
		for _, cand := range candidates {
			if !cand.crossed {
				continue
			}
			vr, ok := vrByID[cand.ref.ID]
			if !ok {
				// Should not happen; degrade conservatively.
				if derr := w.svc.MarkDegradedTenant(ctx, cand.ref.TenantID, cand.ref.ID); derr != nil {
					return degraded, disconnected, derr
				}
				degraded++
				continue
			}
			w.logVerify("degrade", vr)
			if vr.skipped {
				// Budget exhausted — skip, no state change this tick.
				continue
			}
			if vr.alive {
				// Agent is reachable — reset counter via RecordHeartbeat,
				// skip the degrade.
				w.recordHeartbeat(ctx, vr.ref)
				continue
			}
			// Agent unreachable — degrade exactly as before.
			if derr := w.svc.MarkDegradedTenant(ctx, cand.ref.TenantID, cand.ref.ID); derr != nil {
				return degraded, disconnected, derr
			}
			degraded++
		}
	} else {
		// Passive mode or no verifier: degrade all threshold-crossed candidates.
		for _, cand := range candidates {
			if !cand.crossed {
				continue
			}
			if derr := w.svc.MarkDegradedTenant(ctx, cand.ref.TenantID, cand.ref.ID); derr != nil {
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
