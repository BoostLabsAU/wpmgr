package site

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// fakeRechecker stubs AgentRechecker: returns rawMeta on success, err on failure.
type fakeRechecker struct {
	rawMeta []byte
	err     error
	calls   int
}

func (f *fakeRechecker) MetadataRaw(_ context.Context, _ uuid.UUID, _ string) ([]byte, error) {
	f.calls++
	return f.rawMeta, f.err
}

// fakeConnSvc is a minimal ConnectionService stub for the recheck handler tests.
// Only RecordHeartbeat is exercised in these tests.
type fakeConnSvc struct {
	heartbeatCalls int
	heartbeatErr   error
	// postHeartbeatState is the state returned by the second Get call when
	// heartbeatErr == nil, simulating a recovery transition.
	postHeartbeatState ConnectionState
}

func (f *fakeConnSvc) RecordHeartbeat(_ context.Context, _ HeartbeatInput) (HeartbeatResult, error) {
	f.heartbeatCalls++
	return HeartbeatResult{}, f.heartbeatErr
}

// Implement remaining ConnectionService interface methods as no-ops for this stub.
func (f *fakeConnSvc) MintEnrollmentCode(_ context.Context, _ MintEnrollmentInput) (EnrollmentCode, error) {
	return EnrollmentCode{}, nil
}
func (f *fakeConnSvc) ConsumeEnrollmentCode(_ context.Context, _ ConsumeEnrollmentInput) (Site, error) {
	return Site{}, nil
}
func (f *fakeConnSvc) MarkDegraded(_ context.Context, _ uuid.UUID) error { return nil }
func (f *fakeConnSvc) MarkDisconnected(_ context.Context, _ uuid.UUID, _ string) error {
	return nil
}
func (f *fakeConnSvc) RecordLastWill(_ context.Context, _ uuid.UUID, _ string) error { return nil }
func (f *fakeConnSvc) Revoke(_ context.Context, _ ActorSiteInput) (Site, error) {
	return Site{}, nil
}
func (f *fakeConnSvc) Archive(_ context.Context, _ ActorSiteInput) error       { return nil }
func (f *fakeConnSvc) Restore(_ context.Context, _ ActorSiteInput) (Site, error) {
	return Site{}, nil
}
func (f *fakeConnSvc) BeginReEnrollment(_ context.Context, _ ActorSiteInput) (EnrollmentCode, error) {
	return EnrollmentCode{}, nil
}
func (f *fakeConnSvc) CancelEnrollment(_ context.Context, _ ActorSiteInput) error { return nil }

// recheckRepo extends freshRepo with call counting on Get so we can observe
// the second Get (post-heartbeat state refresh).
type recheckRepo struct {
	freshRepo
	getCalls           int
	postHeartbeatState ConnectionState
}

func (r *recheckRepo) Get(ctx context.Context, tenantID, id uuid.UUID) (Site, error) {
	r.getCalls++
	s, err := r.freshRepo.Get(ctx, tenantID, id)
	// Second Get call: simulate the recovered state.
	if r.getCalls >= 2 && r.postHeartbeatState != "" {
		s.ConnectionState = r.postHeartbeatState
	}
	return s, err
}

func (r *recheckRepo) UpdateMetadata(_ context.Context, tenantID, siteID uuid.UUID, _ Metadata, _ []byte) (Site, error) {
	return Site{ID: siteID, TenantID: tenantID, ConnectionState: StateConnected}, nil
}

// buildRecheckEngine builds a minimal gin engine for recheck tests. Injects the
// principal (tenantID + userID) on context, matching what RequireAuth produces.
func buildRecheckEngine(h *Handler, tenantID uuid.UUID) *gin.Engine {
	gin.SetMode(gin.TestMode)
	r := gin.New()
	r.Use(func(c *gin.Context) {
		ctx := domain.WithTenantID(c.Request.Context(), tenantID)
		p := domain.Principal{
			Type:     domain.PrincipalUser,
			UserID:   uuid.New(),
			TenantID: tenantID,
		}
		ctx = domain.WithPrincipal(ctx, p)
		c.Request = c.Request.WithContext(ctx)
		c.Next()
	})
	r.POST("/api/v1/sites/:siteId/recheck", h.recheck)
	return r
}

func doRecheck(r *gin.Engine, siteID uuid.UUID) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost,
		"/api/v1/sites/"+siteID.String()+"/recheck", nil)
	r.ServeHTTP(rec, req)
	return rec
}

// rawMetadataJSON is a minimal agent metadata body that DecodeMetadataBytes
// can parse (same shape as what the agent's metadata command returns).
const rawMetadataJSON = `{"wp_version":"6.5","php_version":"8.2","multisite":false,"plugins":[],"themes":[]}`

func TestRecheckHappyPathConnected200(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now().Add(-time.Second)
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateConnected},
		},
		postHeartbeatState: StateConnected,
	}
	rechecker := &fakeRechecker{rawMeta: []byte(rawMetadataJSON)}
	connSvc := &fakeConnSvc{}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)

	rec := doRecheck(buildRecheckEngine(h, tenantID), siteID)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body=%s", rec.Code, rec.Body.String())
	}
	if rechecker.calls != 1 {
		t.Fatalf("rechecker.MetadataRaw should be called once, got %d", rechecker.calls)
	}
	if connSvc.heartbeatCalls != 1 {
		t.Fatalf("RecordHeartbeat should be called once, got %d", connSvc.heartbeatCalls)
	}
	var resp RecheckResponse
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp.ConnectionState == "" {
		t.Fatal("connection_state must be set")
	}
}

// TestRecheckAgentUnreachable502 verifies that a transport error from the agent
// returns a 502 with code "agent_unreachable" and does NOT trigger a heartbeat
// call (the site's state is left unchanged).
func TestRecheckAgentUnreachable502(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now().Add(-30 * time.Second)
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateDegraded},
		},
	}
	rechecker := &fakeRechecker{err: context.DeadlineExceeded}
	connSvc := &fakeConnSvc{}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)

	rec := doRecheck(buildRecheckEngine(h, tenantID), siteID)

	if rec.Code != http.StatusBadGateway {
		t.Fatalf("status = %d, want 502; body=%s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "agent_unreachable") {
		t.Fatalf("body should contain agent_unreachable: %s", rec.Body.String())
	}
	if connSvc.heartbeatCalls != 0 {
		t.Fatalf("RecordHeartbeat must NOT be called when agent is unreachable, got %d calls",
			connSvc.heartbeatCalls)
	}
}

// TestRecheckRecoveryReportedInResponse verifies that when a degraded site
// recovers to connected after the recheck, the response body carries
// recovered=true and connection_state="connected".
func TestRecheckRecoveryReportedInResponse(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now().Add(-10 * time.Minute)
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateDegraded},
		},
		postHeartbeatState: StateConnected, // simulates recovery transition
	}
	rechecker := &fakeRechecker{rawMeta: []byte(rawMetadataJSON)}
	connSvc := &fakeConnSvc{}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)

	rec := doRecheck(buildRecheckEngine(h, tenantID), siteID)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body=%s", rec.Code, rec.Body.String())
	}
	var resp RecheckResponse
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp.ConnectionState != string(StateConnected) {
		t.Fatalf("connection_state = %q, want %q", resp.ConnectionState, StateConnected)
	}
	if !resp.Recovered {
		t.Fatal("recovered should be true when site transitioned from degraded to connected")
	}
}

// countingLimiter is a test-double RecheckLimiter: it allows at most maxAllowed
// calls per key, then blocks subsequent ones with a fixed retry hint. It never
// sleeps — the "interval elapsed" test case resets the counter directly.
type countingLimiter struct {
	maxAllowed int
	counts     map[string]int
}

func newCountingLimiter(max int) *countingLimiter {
	return &countingLimiter{maxAllowed: max, counts: map[string]int{}}
}

func (l *countingLimiter) Allow(_ context.Context, key string, _ int) (bool, time.Duration) {
	l.counts[key]++
	if l.counts[key] <= l.maxAllowed {
		return true, 0
	}
	return false, 15 * time.Second
}

// resetKey resets the call counter for key, simulating the interval having
// elapsed (the real limiter refills its token bucket over time).
func (l *countingLimiter) resetKey(key string) { delete(l.counts, key) }

// TestRecheckRateLimitBlocks429 verifies that a second recheck for the same
// (tenant, site) within the window is rejected with 429 "recheck_rate_limited"
// and does NOT dispatch the metadata command.
func TestRecheckRateLimitBlocks429(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now().Add(-time.Second)
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateConnected},
		},
		postHeartbeatState: StateConnected,
	}
	rechecker := &fakeRechecker{rawMeta: []byte(rawMetadataJSON)}
	connSvc := &fakeConnSvc{}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)
	// Allow exactly 1 call, then block.
	lim := newCountingLimiter(1)
	h.SetRecheckLimiter(lim)

	engine := buildRecheckEngine(h, tenantID)

	// First call: must succeed (within budget).
	rec1 := doRecheck(engine, siteID)
	if rec1.Code != http.StatusOK {
		t.Fatalf("first recheck: status = %d, want 200; body=%s", rec1.Code, rec1.Body.String())
	}
	if rechecker.calls != 1 {
		t.Fatalf("first recheck: rechecker.calls = %d, want 1", rechecker.calls)
	}

	// Second call: must be blocked with 429 and must NOT dispatch the command.
	rec2 := doRecheck(engine, siteID)
	if rec2.Code != http.StatusTooManyRequests {
		t.Fatalf("second recheck: status = %d, want 429; body=%s", rec2.Code, rec2.Body.String())
	}
	// rechecker.calls must still be 1 — the rate-limited path returns early.
	if rechecker.calls != 1 {
		t.Fatalf("rate-limited recheck must not dispatch metadata command; rechecker.calls = %d, want 1", rechecker.calls)
	}
	// Body must contain the structured code and a positive retry_after_seconds.
	var body map[string]any
	if err := json.NewDecoder(rec2.Body).Decode(&body); err != nil {
		t.Fatalf("decode 429 body: %v", err)
	}
	if body["code"] != "recheck_rate_limited" {
		t.Fatalf("429 body code = %q, want %q", body["code"], "recheck_rate_limited")
	}
	if _, ok := body["retry_after_seconds"]; !ok {
		t.Fatal("429 body must contain retry_after_seconds")
	}
	// Retry-After header must be present.
	if rec2.Header().Get("Retry-After") == "" {
		t.Fatal("429 response must carry a Retry-After header")
	}
}

// TestRecheckRateLimitIndependentSites verifies that blocking one (tenant,site)
// key does not prevent a recheck on a different site.
func TestRecheckRateLimitIndependentSites(t *testing.T) {
	tenantID := uuid.New()
	siteA := uuid.New()
	siteB := uuid.New()
	seen := time.Now().Add(-time.Second)
	makeRepo := func() *recheckRepo {
		return &recheckRepo{
			freshRepo: freshRepo{
				enrolled: true,
				lastSeen: &seen,
				site:     Site{URL: "https://example.com", ConnectionState: StateConnected},
			},
			postHeartbeatState: StateConnected,
		}
	}

	rechecker := &fakeRechecker{rawMeta: []byte(rawMetadataJSON)}
	connSvc := &fakeConnSvc{}

	svc := NewService(makeRepo(), domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)
	// Allow 1 call per key, then block.
	lim := newCountingLimiter(1)
	h.SetRecheckLimiter(lim)

	engine := buildRecheckEngine(h, tenantID)

	// Exhaust site-A's budget.
	_ = doRecheck(engine, siteA)                   // allowed
	recA2 := doRecheck(engine, siteA)              // blocked
	if recA2.Code != http.StatusTooManyRequests {
		t.Fatalf("site-A second recheck: status = %d, want 429", recA2.Code)
	}

	// Site-B must still be allowed (independent key).
	recB := doRecheck(engine, siteB)
	if recB.Code != http.StatusOK {
		t.Fatalf("site-B recheck after site-A blocked: status = %d, want 200; body=%s",
			recB.Code, recB.Body.String())
	}
}

// TestRecheckRateLimitAllowsAfterInterval verifies that after the limiter's
// interval has logically elapsed (simulated by resetting the counter), the same
// (tenant,site) is allowed again without a real sleep.
func TestRecheckRateLimitAllowsAfterInterval(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now().Add(-time.Second)
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateConnected},
		},
		postHeartbeatState: StateConnected,
	}
	rechecker := &fakeRechecker{rawMeta: []byte(rawMetadataJSON)}
	connSvc := &fakeConnSvc{}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	h.SetRechecker(rechecker)
	h.SetConnectionService(connSvc)
	lim := newCountingLimiter(1)
	h.SetRecheckLimiter(lim)

	engine := buildRecheckEngine(h, tenantID)

	// Exhaust the budget.
	_ = doRecheck(engine, siteID)
	if rec := doRecheck(engine, siteID); rec.Code != http.StatusTooManyRequests {
		t.Fatalf("pre-reset second call: status = %d, want 429", rec.Code)
	}

	// Simulate the interval elapsing by resetting the key.
	key := tenantID.String() + "|" + siteID.String()
	lim.resetKey(key)

	// The site must be allowed again after the simulated reset.
	recAfter := doRecheck(engine, siteID)
	if recAfter.Code != http.StatusOK {
		t.Fatalf("post-interval recheck: status = %d, want 200; body=%s",
			recAfter.Code, recAfter.Body.String())
	}
}

// TestRecheckDisabledWhenNoRechecker returns 503 when the rechecker is not wired.
func TestRecheckDisabledWhenNoRechecker(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	seen := time.Now()
	baseRepo := &recheckRepo{
		freshRepo: freshRepo{
			enrolled: true,
			lastSeen: &seen,
			site:     Site{URL: "https://example.com", ConnectionState: StateConnected},
		},
	}

	svc := NewService(baseRepo, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")
	// rechecker intentionally not set

	rec := doRecheck(buildRecheckEngine(h, tenantID), siteID)

	// domain.Unavailable maps to HTTP 501 (feature/key not configured).
	if rec.Code != http.StatusNotImplemented {
		t.Fatalf("status = %d, want 501; body=%s", rec.Code, rec.Body.String())
	}
}
