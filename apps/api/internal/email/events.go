package email

// events.go — SSE event emission for the email domain (m59 Phase 4 SSE).
//
// Three event types are emitted on the shared tenant site-events bus:
//
//   email.log_ingested        — after an agent log-ingest batch lands (throttled per site)
//   email.suppression_updated — when a suppression row is written or deleted
//   email.bounce              — when a site_email_log row is flipped to bounced/complained
//
// Each handler that needs to emit holds an EventPublisher (defined locally as an
// interface, mirroring the rum package's pattern to avoid a direct import of the
// events implementation and keep the email package testable in isolation). The
// production *siteevents.Publisher satisfies all three.

import (
	"context"
	"sync"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// LogIngestedThrottle is the minimum interval between email.log_ingested SSE
// emits for the same site. A burst of agent pushes (e.g. catch-up sync) must
// not flood the event bus. 10 s matches a conservative ingest cycle; the
// dashboard re-fetches on each event so the operator always sees fresh data
// within one throttle window.
const LogIngestedThrottle = 10 * time.Second

// EventPublisher is the subset of site.EventPublisher needed by the email
// handlers. Defined locally so the email package does not import the events
// implementation package directly (avoids a potential circular dependency; the
// concrete *siteevents.Publisher is injected from main.go). The interface is
// identical to the one used by the rum package.
type EventPublisher interface {
	Publish(ctx context.Context, ev site.ConnectionEvent) error
}

// logIngestThrottle tracks the last email.log_ingested emit timestamp per site.
// It is embedded in AgentHandler and is safe for concurrent use.
type logIngestThrottle struct {
	mu       sync.Mutex
	lastEmit map[uuid.UUID]time.Time
}

func newLogIngestThrottle() *logIngestThrottle {
	return &logIngestThrottle{lastEmit: make(map[uuid.UUID]time.Time)}
}

// allow returns true and advances the clock when more than LogIngestedThrottle
// has elapsed since the last emit for siteID. Returns false (and does NOT
// update the map) when the site is within the throttle window.
func (t *logIngestThrottle) allow(siteID uuid.UUID) bool {
	now := time.Now().UTC()
	t.mu.Lock()
	defer t.mu.Unlock()
	if now.Sub(t.lastEmit[siteID]) < LogIngestedThrottle {
		return false
	}
	t.lastEmit[siteID] = now
	return true
}

// publishLogIngested emits email.log_ingested for siteID if the throttle window
// has elapsed. Noop when pub is nil.
func publishLogIngested(ctx context.Context, pub EventPublisher, throttle *logIngestThrottle, tenantID, siteID uuid.UUID, count int) {
	if pub == nil {
		return
	}
	if !throttle.allow(siteID) {
		return
	}
	_ = pub.Publish(ctx, site.ConnectionEvent{
		Type:     site.EventEmailLogIngested,
		TenantID: tenantID,
		SiteID:   siteID,
		Data: map[string]any{
			"site_id": siteID.String(),
			"count":   count,
		},
	})
}

// publishSuppressionUpdated emits email.suppression_updated. siteID may be
// uuid.Nil for fleet-wide (org-level) suppression rows. Noop when pub is nil.
func publishSuppressionUpdated(ctx context.Context, pub EventPublisher, tenantID uuid.UUID, siteID *uuid.UUID, maskedEmail, reason string) {
	if pub == nil {
		return
	}
	// Determine the SSE SiteID. Site-scoped events carry the site UUID; org-wide
	// suppression rows have no specific site — uuid.Nil causes the publisher to
	// store a NULL site_id, which the SSE hub fans out to all active streams for
	// the tenant (every dashboard tab receives it regardless of the site in view).
	var evSiteID uuid.UUID
	var siteIDStr interface{} = nil
	if siteID != nil {
		evSiteID = *siteID
		siteIDStr = siteID.String()
	}
	_ = pub.Publish(ctx, site.ConnectionEvent{
		Type:     site.EventEmailSuppressionUpdated,
		TenantID: tenantID,
		SiteID:   evSiteID,
		Data: map[string]any{
			"site_id": siteIDStr,
			"email":   maskedEmail,
			"reason":  reason,
		},
	})
}

// publishBounce emits email.bounce when a log row is flipped to bounced or
// complained. Noop when pub is nil.
func publishBounce(ctx context.Context, pub EventPublisher, tenantID, siteID uuid.UUID, messageID, status string) {
	if pub == nil {
		return
	}
	_ = pub.Publish(ctx, site.ConnectionEvent{
		Type:     site.EventEmailBounce,
		TenantID: tenantID,
		SiteID:   siteID,
		Data: map[string]any{
			"site_id":    siteID.String(),
			"message_id": messageID,
			"status":     status,
		},
	})
}

// maskEmail returns a privacy-safe representation of an email address for SSE
// payloads. The local part is replaced with the first character and asterisks;
// the domain is kept so the operator can identify the provider without exposing
// the full address. Example: "alice@example.com" → "a****@example.com".
// When the address is empty or has no '@', the full string is returned as-is
// (the caller is responsible for not passing raw PII; this is belt-and-braces).
func maskEmail(email string) string {
	for i := 0; i < len(email); i++ {
		if email[i] == '@' {
			local := email[:i]
			domain := email[i:]
			if len(local) == 0 {
				return email
			}
			masked := string(local[0])
			for j := 1; j < len(local); j++ {
				masked += "*"
			}
			return masked + domain
		}
	}
	return email
}
