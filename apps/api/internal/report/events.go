package report

// events.go — SSE event emission for the report domain (m64).
//
// One event type is emitted on the shared tenant site-events bus:
//
//	report.completed — emitted after a report reaches terminal status
//	                   (completed or failed). SiteID = uuid.Nil (NULL) →
//	                   tenant-wide fan-out to all active streams.
//
// The pattern mirrors email/events.go exactly: a local EventPublisher
// interface avoids importing the events implementation package directly.

import (
	"context"
	"log/slog"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// EventReportCompleted is the SSE event type for a report reaching a terminal
// state. Added to connection.go beside EventEmailConfigPropagated.
const EventReportCompleted = "report.completed"

// EventPublisher is the subset of site.EventPublisher needed by the report
// worker. Defined locally to avoid a direct import of the events
// implementation package (mirrors email/events.go:38-40).
type EventPublisher interface {
	Publish(ctx context.Context, ev site.ConnectionEvent) error
}

// publishReportCompleted emits a report.completed SSE event with
// SiteID=uuid.Nil for tenant-wide fan-out. Failures are logged; they never
// block the report completion path.
func publishReportCompleted(ctx context.Context, pub EventPublisher, tenantID, clientID, reportID uuid.UUID, status string) {
	if pub == nil {
		return
	}
	ev := site.ConnectionEvent{
		ID:       newEventID(),
		Type:     EventReportCompleted,
		TenantID: tenantID,
		SiteID:   uuid.Nil, // tenant-wide fan-out
		TS:       time.Now().UTC(),
		Data: map[string]any{
			"report_id": reportID.String(),
			"client_id": clientID.String(),
			"status":    status,
		},
	}
	if err := pub.Publish(ctx, ev); err != nil {
		slog.Warn("report: publish report.completed event failed",
			slog.String("report_id", reportID.String()),
			slog.Any("error", err))
	}
}

// newEventID mints a simple time-based event ID (not ULID to avoid a dep).
// The site-events bus accepts any monotonic string ID for ?since= replay.
func newEventID() string {
	return uuid.New().String()
}
