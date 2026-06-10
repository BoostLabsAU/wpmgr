package email

// events_test.go — unit tests for the email SSE event emission layer.
//
// Tests verify:
//   - ingest emits email.log_ingested after a successful batch
//   - a rapid second ingest within the throttle window does NOT re-emit
//   - webhook suppression path emits email.suppression_updated
//   - webhook bounce path emits email.bounce
//   - nil publisher is always a safe no-op (no panic)
//   - maskEmail produces expected masked output

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// ---------------------------------------------------------------------------
// capturePublisher is a test double that records every Publish call.
// ---------------------------------------------------------------------------

type capturePublisher struct {
	mu     sync.Mutex
	events []site.ConnectionEvent
}

func (p *capturePublisher) Publish(_ context.Context, ev site.ConnectionEvent) error {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.events = append(p.events, ev)
	return nil
}

func (p *capturePublisher) count() int {
	p.mu.Lock()
	defer p.mu.Unlock()
	return len(p.events)
}

func (p *capturePublisher) last() (site.ConnectionEvent, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if len(p.events) == 0 {
		return site.ConnectionEvent{}, false
	}
	return p.events[len(p.events)-1], true
}

func (p *capturePublisher) all() []site.ConnectionEvent {
	p.mu.Lock()
	defer p.mu.Unlock()
	out := make([]site.ConnectionEvent, len(p.events))
	copy(out, p.events)
	return out
}

// ---------------------------------------------------------------------------
// email.log_ingested — throttle behaviour
// ---------------------------------------------------------------------------

// TestPublishLogIngested_EmitsOnFirstCall verifies that the first ingest call
// for a site publishes exactly one email.log_ingested event.
func TestPublishLogIngested_EmitsOnFirstCall(t *testing.T) {
	pub := &capturePublisher{}
	throttle := newLogIngestThrottle()
	tenantID := uuid.New()
	siteID := uuid.New()

	publishLogIngested(context.Background(), pub, throttle, tenantID, siteID, 5)

	if pub.count() != 1 {
		t.Fatalf("expected 1 event after first ingest, got %d", pub.count())
	}
	ev, _ := pub.last()
	if ev.Type != site.EventEmailLogIngested {
		t.Errorf("expected type %q, got %q", site.EventEmailLogIngested, ev.Type)
	}
	if ev.TenantID != tenantID {
		t.Errorf("expected tenantID %s, got %s", tenantID, ev.TenantID)
	}
	if ev.SiteID != siteID {
		t.Errorf("expected siteID %s, got %s", siteID, ev.SiteID)
	}
	if ev.Data["count"] != 5 {
		t.Errorf("expected count=5 in payload, got %v", ev.Data["count"])
	}
	if ev.Data["site_id"] != siteID.String() {
		t.Errorf("expected site_id=%q in payload, got %v", siteID.String(), ev.Data["site_id"])
	}
}

// TestPublishLogIngested_ThrottledWithinWindow verifies that a rapid second
// ingest call for the same site within the throttle window does NOT emit.
func TestPublishLogIngested_ThrottledWithinWindow(t *testing.T) {
	pub := &capturePublisher{}
	throttle := newLogIngestThrottle()
	tenantID := uuid.New()
	siteID := uuid.New()

	// First call: should emit.
	publishLogIngested(context.Background(), pub, throttle, tenantID, siteID, 3)
	// Second call immediately after: must NOT emit (within throttle window).
	publishLogIngested(context.Background(), pub, throttle, tenantID, siteID, 7)

	if pub.count() != 1 {
		t.Errorf("expected exactly 1 emit (second call throttled), got %d", pub.count())
	}
}

// TestPublishLogIngested_AllowsAfterWindow verifies that a call after the
// throttle window has elapsed does emit again.
func TestPublishLogIngested_AllowsAfterWindow(t *testing.T) {
	// Manually backdating the throttle map to simulate elapsed time.
	pub := &capturePublisher{}
	throttle := newLogIngestThrottle()
	siteID := uuid.New()
	tenantID := uuid.New()

	// Seed the throttle with a timestamp well in the past.
	throttle.mu.Lock()
	throttle.lastEmit[siteID] = time.Now().Add(-(LogIngestedThrottle + time.Second))
	throttle.mu.Unlock()

	publishLogIngested(context.Background(), pub, throttle, tenantID, siteID, 2)

	if pub.count() != 1 {
		t.Errorf("expected 1 emit after throttle window expired, got %d", pub.count())
	}
}

// TestPublishLogIngested_DifferentSitesIndependent verifies that throttles are
// tracked independently per site — two different sites each emit on first call.
func TestPublishLogIngested_DifferentSitesIndependent(t *testing.T) {
	pub := &capturePublisher{}
	throttle := newLogIngestThrottle()
	tenantID := uuid.New()
	siteA := uuid.New()
	siteB := uuid.New()

	publishLogIngested(context.Background(), pub, throttle, tenantID, siteA, 1)
	publishLogIngested(context.Background(), pub, throttle, tenantID, siteB, 1)

	if pub.count() != 2 {
		t.Errorf("expected 2 events (one per site), got %d", pub.count())
	}
}

// TestPublishLogIngested_NilPublisher verifies that a nil publisher is a
// safe no-op and does not panic.
func TestPublishLogIngested_NilPublisher(t *testing.T) {
	throttle := newLogIngestThrottle()
	// Must not panic.
	publishLogIngested(context.Background(), nil, throttle, uuid.New(), uuid.New(), 10)
}

// ---------------------------------------------------------------------------
// email.suppression_updated
// ---------------------------------------------------------------------------

// TestPublishSuppressionUpdated_SiteScoped verifies that a site-scoped
// suppression emits with the correct site_id in the payload.
func TestPublishSuppressionUpdated_SiteScoped(t *testing.T) {
	pub := &capturePublisher{}
	tenantID := uuid.New()
	siteID := uuid.New()

	publishSuppressionUpdated(context.Background(), pub, tenantID, &siteID, "a****@example.com", "hard_bounce")

	if pub.count() != 1 {
		t.Fatalf("expected 1 event, got %d", pub.count())
	}
	ev, _ := pub.last()
	if ev.Type != site.EventEmailSuppressionUpdated {
		t.Errorf("expected type %q, got %q", site.EventEmailSuppressionUpdated, ev.Type)
	}
	if ev.SiteID != siteID {
		t.Errorf("expected SiteID=%s, got %s", siteID, ev.SiteID)
	}
	if ev.Data["reason"] != "hard_bounce" {
		t.Errorf("expected reason=hard_bounce, got %v", ev.Data["reason"])
	}
	if ev.Data["email"] != "a****@example.com" {
		t.Errorf("expected masked email, got %v", ev.Data["email"])
	}
	// site_id in payload must be the UUID string.
	if ev.Data["site_id"] != siteID.String() {
		t.Errorf("expected site_id=%q in payload, got %v", siteID.String(), ev.Data["site_id"])
	}
}

// TestPublishSuppressionUpdated_OrgWide verifies that a fleet-wide (site_id=nil)
// suppression emits with site_id=null in the payload and uuid.Nil as SiteID on
// the ConnectionEvent (so the publisher stores NULL site_id and fans to all streams).
func TestPublishSuppressionUpdated_OrgWide(t *testing.T) {
	pub := &capturePublisher{}
	tenantID := uuid.New()

	publishSuppressionUpdated(context.Background(), pub, tenantID, nil, "b***@domain.com", "manual")

	if pub.count() != 1 {
		t.Fatalf("expected 1 event, got %d", pub.count())
	}
	ev, _ := pub.last()
	if ev.SiteID != uuid.Nil {
		t.Errorf("expected SiteID=uuid.Nil for org-wide event, got %s", ev.SiteID)
	}
	// payload site_id must be nil (JSON null equivalent via interface{} nil).
	if ev.Data["site_id"] != nil {
		t.Errorf("expected payload site_id=nil for org-wide event, got %v", ev.Data["site_id"])
	}
}

// TestPublishSuppressionUpdated_NilPublisher verifies a nil publisher is safe.
func TestPublishSuppressionUpdated_NilPublisher(t *testing.T) {
	siteID := uuid.New()
	publishSuppressionUpdated(context.Background(), nil, uuid.New(), &siteID, "x@y.com", "manual")
}

// ---------------------------------------------------------------------------
// email.bounce
// ---------------------------------------------------------------------------

// TestPublishBounce_Emits verifies the email.bounce event carries the correct
// type, site_id, message_id, and status fields.
func TestPublishBounce_Emits(t *testing.T) {
	pub := &capturePublisher{}
	tenantID := uuid.New()
	siteID := uuid.New()

	publishBounce(context.Background(), pub, tenantID, siteID, "msg-abc-123", "bounced")

	if pub.count() != 1 {
		t.Fatalf("expected 1 event, got %d", pub.count())
	}
	ev, _ := pub.last()
	if ev.Type != site.EventEmailBounce {
		t.Errorf("expected type %q, got %q", site.EventEmailBounce, ev.Type)
	}
	if ev.SiteID != siteID {
		t.Errorf("expected SiteID=%s, got %s", siteID, ev.SiteID)
	}
	if ev.Data["message_id"] != "msg-abc-123" {
		t.Errorf("expected message_id=msg-abc-123, got %v", ev.Data["message_id"])
	}
	if ev.Data["status"] != "bounced" {
		t.Errorf("expected status=bounced, got %v", ev.Data["status"])
	}
}

// TestPublishBounce_NilPublisher verifies a nil publisher is safe.
func TestPublishBounce_NilPublisher(t *testing.T) {
	publishBounce(context.Background(), nil, uuid.New(), uuid.New(), "msg", "bounced")
}

// ---------------------------------------------------------------------------
// Service.HandleWebhookEvent — SSE integration
// ---------------------------------------------------------------------------

// TestHandleWebhookEvent_EmitsSuppressionAndBounce verifies that the service
// emits email.suppression_updated and email.bounce when a hard_bounce webhook
// event arrives with tenant + site metadata.
func TestHandleWebhookEvent_EmitsSuppressionAndBounce(t *testing.T) {
	pub := &capturePublisher{}
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, discardLogger())
	svc.repo = repo
	svc.SetPublisher(pub)

	tenantID := uuid.New()
	siteID := uuid.New()

	err := svc.HandleWebhookEvent(context.Background(), WebhookEventInput{
		Provider:        "sendgrid",
		ProviderEventID: "msg-event-001",
		TenantID:        &tenantID,
		SiteID:          &siteID,
		Email:           "victim@example.com",
		EventType:       "hard_bounce",
	})
	if err != nil {
		t.Fatalf("HandleWebhookEvent: unexpected error: %v", err)
	}

	evs := pub.all()
	if len(evs) < 2 {
		t.Fatalf("expected at least 2 events (suppression_updated + bounce), got %d", len(evs))
	}

	// Find each event type in the emitted set.
	var gotSuppression, gotBounce bool
	for _, ev := range evs {
		switch ev.Type {
		case site.EventEmailSuppressionUpdated:
			gotSuppression = true
			if ev.TenantID != tenantID {
				t.Errorf("suppression_updated: wrong tenantID %s", ev.TenantID)
			}
			if ev.SiteID != siteID {
				t.Errorf("suppression_updated: wrong siteID %s", ev.SiteID)
			}
		case site.EventEmailBounce:
			gotBounce = true
			if ev.SiteID != siteID {
				t.Errorf("email.bounce: wrong siteID %s", ev.SiteID)
			}
			if ev.Data["message_id"] != "msg-event-001" {
				t.Errorf("email.bounce: expected message_id=msg-event-001, got %v", ev.Data["message_id"])
			}
		}
	}
	if !gotSuppression {
		t.Error("expected email.suppression_updated event not emitted")
	}
	if !gotBounce {
		t.Error("expected email.bounce event not emitted")
	}
}

// TestHandleWebhookEvent_NoEmitWithoutTenantMetadata verifies that when the
// webhook event has no tenant metadata (unresolvable provider callback), no SSE
// events are emitted (the service logs and drops gracefully).
func TestHandleWebhookEvent_NoEmitWithoutTenantMetadata(t *testing.T) {
	pub := &capturePublisher{}
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, discardLogger())
	svc.repo = repo
	svc.SetPublisher(pub)

	err := svc.HandleWebhookEvent(context.Background(), WebhookEventInput{
		Provider:        "ses",
		ProviderEventID: "orphan-001",
		TenantID:        nil, // no metadata
		SiteID:          nil,
		Email:           "orphan@example.com",
		EventType:       "hard_bounce",
	})
	if err != nil {
		t.Fatalf("expected no error for orphaned event, got: %v", err)
	}
	if pub.count() != 0 {
		t.Errorf("expected 0 events for orphaned webhook (no tenant), got %d", pub.count())
	}
}

// TestHandleWebhookEvent_NilPublisherSafe verifies that HandleWebhookEvent with
// no publisher wired does not panic and still returns nil.
func TestHandleWebhookEvent_NilPublisherSafe(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, discardLogger())
	svc.repo = repo
	// pub deliberately not set (nil)

	tenantID := uuid.New()
	siteID := uuid.New()
	err := svc.HandleWebhookEvent(context.Background(), WebhookEventInput{
		Provider:        "mailgun",
		ProviderEventID: "mg-001",
		TenantID:        &tenantID,
		SiteID:          &siteID,
		Email:           "test@example.com",
		EventType:       "complaint",
	})
	if err != nil {
		t.Fatalf("expected no error with nil publisher: %v", err)
	}
}

// ---------------------------------------------------------------------------
// maskEmail
// ---------------------------------------------------------------------------

func TestMaskEmail(t *testing.T) {
	cases := []struct {
		in   string
		want string
	}{
		{"alice@example.com", "a****@example.com"},
		{"a@b.com", "a@b.com"},          // single-char local — no masking needed
		{"ab@x.org", "a*@x.org"},
		{"", ""},                         // empty passthrough
		{"notanemail", "notanemail"},      // no @ — passthrough
		{"@domain.com", "@domain.com"},   // empty local — passthrough
	}
	for _, tc := range cases {
		got := maskEmail(tc.in)
		if got != tc.want {
			t.Errorf("maskEmail(%q) = %q, want %q", tc.in, got, tc.want)
		}
	}
}
