package perf

// beacon_push_test.go — tests for the RUM beacon-key push gap (Deliverable A).
//
// Verified behavior:
//   - On first-enable (RumEnabled=true, BeaconKeySet=false): the agent push
//     payload carries a non-empty RumBeaconKey and a non-empty RumIngestURL.
//   - On a subsequent push (RumEnabled=true, BeaconKeySet=true): the agent
//     push payload has an empty RumBeaconKey (plaintext not re-sent).
//   - RumIngestURL is always set to cpBaseURL+"/rum/ingest" when RumEnabled.

import (
	"context"
	"sync"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
)

// recordingAgent wraps the existing fakeAgent and records the most recent
// SyncPerfConfig request so tests can assert on the pushed payload.
type recordingAgent struct {
	fakeAgent
	mu          sync.Mutex
	lastPerfReq agentcmd.PerfConfigRequest
}

func (a *recordingAgent) SyncPerfConfig(_ context.Context, _ uuid.UUID, _ string, req agentcmd.PerfConfigRequest) (agentcmd.PerfConfigResult, error) {
	a.mu.Lock()
	a.lastPerfReq = req
	a.mu.Unlock()
	return agentcmd.PerfConfigResult{OK: true}, nil
}

// fakeBeaconKeyRepo is a minimal in-memory stub satisfying what
// toPerfConfigRequest / SetBeaconKeyRepo needs from *rum.BeaconKeyRepo.
// We can't use the real BeaconKeyRepo in unit tests (it needs a DB), so we
// wire SetBeaconKeyRepo with nil (keys won't be stored) and instead test the
// toPerfConfigRequest function directly — the one that builds the push payload.
//
// The correct end-to-end path through UpdateConfig requires a live DB; we
// therefore test the payload-building function directly and separately test
// the UpdateConfig flow via the service-level integration tests that use a live
// fakeRepo.

// ---------------------------------------------------------------------------
// toPerfConfigRequest payload tests (pure, no DB)
// ---------------------------------------------------------------------------

// TestToPerfConfigRequest_includesBeaconKeyOnFirstEnable verifies that when a
// freshBeaconKey is provided, the push payload carries it in RumBeaconKey.
func TestToPerfConfigRequest_includesBeaconKeyOnFirstEnable(t *testing.T) {
	cfg := Config{
		RumEnabled:    true,
		RumSampleRate: 0.5,
		BeaconKeySet:  false, // newly set — key was just generated
	}
	const freshKey = "aBcDeFgHiJkLmNoPqRsTuVwXyZ01234" // dummy plaintext
	const cpBase = "https://manage.example.com"

	req := toPerfConfigRequest(cfg, freshKey, cpBase)

	if req.RumBeaconKey != freshKey {
		t.Errorf("RumBeaconKey = %q, want %q", req.RumBeaconKey, freshKey)
	}
	if req.RumIngestURL != cpBase+"/rum/ingest" {
		t.Errorf("RumIngestURL = %q, want %q", req.RumIngestURL, cpBase+"/rum/ingest")
	}
	if !req.RumEnabled {
		t.Error("RumEnabled must be true when cfg.RumEnabled=true")
	}
	if req.RumSampleRate != 0.5 {
		t.Errorf("RumSampleRate = %g, want 0.5", req.RumSampleRate)
	}
}

// TestToPerfConfigRequest_omitsBeaconKeyOnSubsequentPush verifies that when
// freshBeaconKey is "" (unchanged key), the push carries an empty RumBeaconKey.
// The CP stores only the hash and cannot resend the plaintext on subsequent
// pushes; the agent must retain its own copy.
func TestToPerfConfigRequest_omitsBeaconKeyOnSubsequentPush(t *testing.T) {
	cfg := Config{
		RumEnabled:   true,
		BeaconKeySet: true, // already provisioned
	}
	req := toPerfConfigRequest(cfg, "" /*freshBeaconKey=unchanged*/, "https://cp.example.com")

	if req.RumBeaconKey != "" {
		t.Errorf("RumBeaconKey must be empty on unchanged push, got %q", req.RumBeaconKey)
	}
	// But IngestURL is still set because RumEnabled=true.
	if req.RumIngestURL == "" {
		t.Error("RumIngestURL must be set when RumEnabled=true, even on unchanged push")
	}
}

// TestToPerfConfigRequest_ingestURLEmptyWhenRumDisabled verifies that the
// ingest URL is omitted when RumEnabled=false.
func TestToPerfConfigRequest_ingestURLEmptyWhenRumDisabled(t *testing.T) {
	cfg := Config{RumEnabled: false}
	req := toPerfConfigRequest(cfg, "", "https://cp.example.com")

	if req.RumIngestURL != "" {
		t.Errorf("RumIngestURL must be empty when RumEnabled=false, got %q", req.RumIngestURL)
	}
}

// TestToPerfConfigRequest_ingestURLTrailingSlashStripped verifies that a
// cpBaseURL with a trailing slash does not produce a double slash.
func TestToPerfConfigRequest_ingestURLTrailingSlashStripped(t *testing.T) {
	cfg := Config{RumEnabled: true}
	req := toPerfConfigRequest(cfg, "", "https://cp.example.com/")

	want := "https://cp.example.com/rum/ingest"
	if req.RumIngestURL != want {
		t.Errorf("RumIngestURL = %q, want %q", req.RumIngestURL, want)
	}
}

// ---------------------------------------------------------------------------
// UpdateConfig integration (uses fakeRepo + recordingAgent + nil beaconRepo)
// ---------------------------------------------------------------------------

// TestUpdateConfig_recordingAgentReceivesRumFields verifies that when
// UpdateConfig is called with RumEnabled=true on a new site (BeaconKeySet=false
// in the stored config), the agent push contains RumEnabled=true and a
// non-empty RumIngestURL.
//
// NOTE: The freshBeaconKey field will be empty because SetBeaconKeyRepo is not
// wired in this test (the real BeaconKeyRepo requires a live DB). The
// beacon-key generation path is tested separately via the pure function tests
// above (TestToPerfConfigRequest_includesBeaconKeyOnFirstEnable). The
// integration path (SetBeaconKeyRepo wired, no key exists) is exercised by the
// e2e tests against a live DB. Here we validate the agent push shape.
func TestUpdateConfig_recordingAgentReceivesRumFields(t *testing.T) {
	repo := &fakeRepo{
		config:      Config{RumEnabled: false, BeaconKeySet: false},
		configFound: true,
	}
	agent := &recordingAgent{}
	svc := NewService(repo, nil, &fakeEvents{}, nil)
	svc.SetAgentClient(agent, &fakeSites{url: "https://example.com"})
	svc.cpBaseURL = "https://manage.example.com"

	in := UpdateConfigInput{Config: Config{
		TenantID:      uuid.New(),
		SiteID:        uuid.New(),
		RumEnabled:    true,
		RumSampleRate: 1.0,
	}}

	_, err := svc.UpdateConfig(context.Background(), in.Config.TenantID, in.Config.SiteID, in)
	if err != nil {
		t.Fatalf("UpdateConfig: %v", err)
	}

	agent.mu.Lock()
	got := agent.lastPerfReq
	agent.mu.Unlock()

	if !got.RumEnabled {
		t.Error("push payload: RumEnabled must be true")
	}
	if got.RumIngestURL != "https://manage.example.com/rum/ingest" {
		t.Errorf("push payload: RumIngestURL = %q, want %q", got.RumIngestURL, "https://manage.example.com/rum/ingest")
	}
}
