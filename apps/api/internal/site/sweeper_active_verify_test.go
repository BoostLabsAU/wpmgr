package site

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/google/uuid"
)

// ----------------------------------------------------------------------------
// fakes for active-verify tests
// ----------------------------------------------------------------------------

// fakeVerifier records calls and returns a pre-configured outcome.
type fakeVerifier struct {
	alive        bool
	fallbackUsed bool
	err          error
	calls        []verifyCall
}

type verifyCall struct {
	siteID  uuid.UUID
	siteURL string
}

func (f *fakeVerifier) VerifyReachable(_ context.Context, siteID uuid.UUID, siteURL string) (bool, bool, error) {
	f.calls = append(f.calls, verifyCall{siteID: siteID, siteURL: siteURL})
	return f.alive, f.fallbackUsed, f.err
}

// fakeHeartbeatRecorder records RecordHeartbeat calls.
type fakeHeartbeatRecorder struct {
	calls []HeartbeatInput
}

func (f *fakeHeartbeatRecorder) RecordHeartbeat(_ context.Context, in HeartbeatInput) (HeartbeatResult, error) {
	f.calls = append(f.calls, in)
	return HeartbeatResult{}, nil
}

// recordingTransitioner extends recordTransitioner to capture the disconnect
// reason string.
type recordingTransitioner struct {
	recordTransitioner
	disconnectReasons []string
}

func (t *recordingTransitioner) MarkDisconnectedTenant(_ context.Context, _, siteID uuid.UUID, reason string) error {
	t.recordTransitioner.disconnected = append(t.recordTransitioner.disconnected, siteID)
	t.disconnectReasons = append(t.disconnectReasons, reason)
	if t.recordTransitioner.states != nil {
		t.recordTransitioner.states[siteID] = StateDisconnected
	}
	return nil
}

// ----------------------------------------------------------------------------
// TestSweeperActiveVerifyKeepsConnected
//
// An overdue connected site where the agent answers ping → sweeper does NOT
// degrade it, miss counter is reset (RecordHeartbeat called).
// ----------------------------------------------------------------------------

func TestSweeperActiveVerifyKeepsConnected(t *testing.T) {
	id := uuid.New()
	tenant := uuid.New()
	repo := &sweepRepo{
		toDegrade: []SiteRef{{ID: id, TenantID: tenant, URL: "https://example.com"}},
	}
	inc := newFakeMissIncrementer()
	tr := &recordTransitioner{states: map[uuid.UUID]ConnectionState{}}
	hbRec := &fakeHeartbeatRecorder{}
	verifier := &fakeVerifier{alive: true}

	sw := NewSweeper(repo, tr, nil)
	sw.SetMissIncrementer(inc)
	sw.SetDegradeMissThreshold(1) // threshold = 1 so the first miss would degrade without verify
	sw.SetHeartbeatRecorder(hbRec)
	sw.SetVerifier(verifier)
	sw.SetActiveVerify(true)

	deg, _, err := sw.Sweep(context.Background(), time.Now())
	if err != nil {
		t.Fatalf("sweep error: %v", err)
	}
	if deg != 0 {
		t.Fatalf("expected no degrade (agent is alive), got %d", deg)
	}
	if len(tr.degraded) != 0 {
		t.Fatalf("MarkDegradedTenant called unexpectedly: %v", tr.degraded)
	}
	// RecordHeartbeat must be called to reset the miss counter.
	if len(hbRec.calls) != 1 {
		t.Fatalf("expected RecordHeartbeat called once, got %d", len(hbRec.calls))
	}
	if hbRec.calls[0].SiteID != id {
		t.Fatalf("RecordHeartbeat called for wrong site: %v", hbRec.calls[0].SiteID)
	}
	// VerifyReachable must have been called.
	if len(verifier.calls) != 1 {
		t.Fatalf("expected VerifyReachable called once, got %d", len(verifier.calls))
	}
	if verifier.calls[0].siteURL != "https://example.com" {
		t.Fatalf("VerifyReachable called with wrong URL: %q", verifier.calls[0].siteURL)
	}
}

// ----------------------------------------------------------------------------
// TestSweeperActiveVerifyFailDisconnects
//
// A degraded site past the disconnect threshold where the agent is dead →
// sweeper disconnects with reason "agent_unreachable".
// ----------------------------------------------------------------------------

func TestSweeperActiveVerifyFailDisconnects(t *testing.T) {
	id := uuid.New()
	tenant := uuid.New()
	repo := &sweepRepo{
		toDisconnect: []SiteRef{{ID: id, TenantID: tenant, URL: "https://dead.example.com"}},
	}
	tr := &recordingTransitioner{
		recordTransitioner: recordTransitioner{states: map[uuid.UUID]ConnectionState{}},
	}
	hbRec := &fakeHeartbeatRecorder{}
	verifier := &fakeVerifier{alive: false}

	sw := NewSweeper(repo, tr, nil)
	sw.SetHeartbeatRecorder(hbRec)
	sw.SetVerifier(verifier)
	sw.SetActiveVerify(true)

	_, dis, err := sw.Sweep(context.Background(), time.Now())
	if err != nil {
		t.Fatalf("sweep error: %v", err)
	}
	if dis != 1 {
		t.Fatalf("expected 1 disconnect, got %d", dis)
	}
	if len(tr.disconnectReasons) != 1 || tr.disconnectReasons[0] != "agent_unreachable" {
		t.Fatalf("expected disconnect reason 'agent_unreachable', got %v", tr.disconnectReasons)
	}
	// RecordHeartbeat must NOT be called (agent is unreachable).
	if len(hbRec.calls) != 0 {
		t.Fatalf("RecordHeartbeat should not be called when agent is unreachable, got %d calls", len(hbRec.calls))
	}
}

// ----------------------------------------------------------------------------
// TestSweeperVerifyBudgetDefersTransition
//
// When the sweep budget is already exhausted before the verify phase, all
// sites are skipped — no degrade or disconnect transitions fire.
// ----------------------------------------------------------------------------

func TestSweeperVerifyBudgetDefersTransition(t *testing.T) {
	id1 := uuid.New()
	id2 := uuid.New()
	tenant := uuid.New()
	repo := &sweepRepo{
		toDegrade:    []SiteRef{{ID: id1, TenantID: tenant, URL: "https://site1.example.com"}},
		toDisconnect: []SiteRef{{ID: id2, TenantID: tenant, URL: "https://site2.example.com"}},
	}
	tr := &recordingTransitioner{
		recordTransitioner: recordTransitioner{states: map[uuid.UUID]ConnectionState{}},
	}
	inc := newFakeMissIncrementer()
	// verifier: always alive — if it were called and returned alive, we'd
	// recover; if budget blocks it, neither transition fires either.
	verifier := &fakeVerifier{alive: true}

	sw := NewSweeper(repo, tr, nil)
	sw.SetMissIncrementer(inc)
	sw.SetDegradeMissThreshold(1)
	sw.SetVerifier(verifier)
	sw.SetActiveVerify(true)
	// Set a sweep budget of zero so the deadline is already in the past when
	// activeVerifyBatch is called — every site should be marked skipped.
	sw.sweepBudget = 0

	deg, dis, err := sw.Sweep(context.Background(), time.Now())
	if err != nil {
		t.Fatalf("sweep error: %v", err)
	}
	if deg != 0 || dis != 0 {
		t.Fatalf("expected no transitions when budget is exhausted, got deg=%d dis=%d", deg, dis)
	}
	if len(tr.degraded) != 0 || len(tr.disconnected) != 0 {
		t.Fatalf("unexpected transitions: degraded=%v disconnected=%v", tr.degraded, tr.disconnected)
	}
}

// ----------------------------------------------------------------------------
// TestSweeperVerifyDisabledEnv
//
// WPMGR_SWEEP_ACTIVE_VERIFY=false → passive behaviour identical to pre-0.44.0:
// stale connected site degrades, stale degraded site disconnects with
// reason "heartbeat_timeout".
// ----------------------------------------------------------------------------

func TestSweeperVerifyDisabledEnv(t *testing.T) {
	degradeID := uuid.New()
	disconnectID := uuid.New()
	tenant := uuid.New()
	repo := &sweepRepo{
		toDegrade:    []SiteRef{{ID: degradeID, TenantID: tenant, URL: "https://site1.example.com"}},
		toDisconnect: []SiteRef{{ID: disconnectID, TenantID: tenant, URL: "https://site2.example.com"}},
	}
	tr := &recordingTransitioner{
		recordTransitioner: recordTransitioner{states: map[uuid.UUID]ConnectionState{}},
	}
	// Wire a verifier, but disable active verify — it must NOT be called.
	calledVerify := false
	verifier := &callRecordingVerifier{fn: func(ctx context.Context, siteID uuid.UUID, siteURL string) (bool, bool, error) {
		calledVerify = true
		return false, false, errors.New("should not be called")
	}}

	sw := NewSweeper(repo, tr, nil)
	sw.SetVerifier(verifier)
	sw.SetActiveVerify(false) // passive mode

	deg, dis, err := sw.Sweep(context.Background(), time.Now())
	if err != nil {
		t.Fatalf("sweep error: %v", err)
	}
	// With no missInc wired, the sweeper falls back to immediate-degrade
	// behaviour (same as before M58).
	if deg != 1 {
		t.Fatalf("expected 1 degrade (passive mode), got %d", deg)
	}
	if dis != 1 {
		t.Fatalf("expected 1 disconnect (passive mode), got %d", dis)
	}
	if calledVerify {
		t.Fatal("VerifyReachable must not be called when active verify is disabled")
	}
	// Disconnect reason must be the passive-mode string, not "agent_unreachable".
	if len(tr.disconnectReasons) != 1 || tr.disconnectReasons[0] != "heartbeat_timeout" {
		t.Fatalf("expected passive disconnect reason 'heartbeat_timeout', got %v", tr.disconnectReasons)
	}
}

// callRecordingVerifier wraps a function as an AgentVerifier.
type callRecordingVerifier struct {
	fn func(context.Context, uuid.UUID, string) (bool, bool, error)
}

func (c *callRecordingVerifier) VerifyReachable(ctx context.Context, siteID uuid.UUID, siteURL string) (bool, bool, error) {
	return c.fn(ctx, siteID, siteURL)
}
