package events

import (
	"bufio"
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// flushableRecorder wraps httptest.ResponseRecorder so gin's writer implements
// http.Flusher (the SSE handler requires it before opening the stream).
type flushableRecorder struct {
	*httptest.ResponseRecorder
}

func (f *flushableRecorder) Flush() {}

// newSSEContext builds a gin.Context for GET /sites/events carrying the given
// principal and optional query string (e.g. "since=<cursor>").
func newSSEContext(t *testing.T, baseCtx context.Context, p domain.Principal, query string) (*gin.Context, *flushableRecorder) {
	t.Helper()
	gin.SetMode(gin.TestMode)
	rec := &flushableRecorder{ResponseRecorder: httptest.NewRecorder()}
	ginCtx, _ := gin.CreateTestContext(rec)
	url := "/sites/events"
	if query != "" {
		url += "?" + query
	}
	req := httptest.NewRequest(http.MethodGet, url, nil)
	// Attach both the cancellable context and the principal.
	req = req.WithContext(domain.WithPrincipal(baseCtx, p))
	ginCtx.Request = req
	return ginCtx, rec
}

// TestStreamIgnoresNonULIDCursor is the regression test for the poisoned-cursor
// bug: when a client reconnects with since=<UUIDv4> (minted by the old
// objectcache/service.go or report/events.go code), the SSE handler MUST NOT
// use the UUID as lastSent.  If it did, every subsequent ULID event (which is
// lexicographically smaller than a UUID) would be silently dropped by the
//
//	ev.ID <= lastSent
//
// dedupe guard — the tenant stream goes dark for the rest of the session.
//
// The fix (sse_handler.go): any non-ULID cursor value is sanitised to "" so
// the stream starts at the live position and the next ULID event (ID > "") is
// always delivered.
func TestStreamIgnoresNonULIDCursor(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()

	p := domain.Principal{
		Type:     domain.PrincipalUser,
		UserID:   uuid.New(),
		TenantID: tenantID,
	}

	hub := NewHub()

	// nil pool: the replay branch (`if since != ""`) is only entered when the
	// sanitised since is non-empty.  After sanitisation, since == "" → no
	// replay, no nil-pool panic.
	h := NewHandler(nil, hub)

	// A ULID event that will be delivered live via the hub.
	ulidEvent := site.ConnectionEvent{
		ID:       NewULID(time.Now()),
		Type:     "connection.established",
		TenantID: tenantID,
		SiteID:   siteID,
		TS:       time.Now().UTC(),
		Data:     map[string]any{"state": "connected"},
	}

	// Poison cursor: a UUIDv4 sorts AFTER every ULID because ULIDs start "01…"
	// and UUID hex bytes often start with higher characters.
	poisonCursor := uuid.New().String()

	streamCtx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	ginCtx, rec := newSSEContext(t, streamCtx, p, "since="+poisonCursor)

	done := make(chan struct{})
	go func() {
		defer close(done)
		h.stream(ginCtx)
	}()

	// Wait for the handler to subscribe to the hub before fanning out.
	if !waitForSubscriber(hub, tenantID, 300*time.Millisecond) {
		cancel()
		<-done
		t.Fatal("timed out waiting for SSE handler to subscribe to the hub")
	}

	hub.Fanout(ulidEvent)

	// Wait for the event frame to appear in the recorder body.
	waitForBody(rec, ulidEvent.ID, 500*time.Millisecond)

	// Stop the handler.
	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("handler goroutine did not exit after context cancel")
	}

	body := rec.Body.String()

	// The ULID event MUST appear — if the poison cursor was used as lastSent
	// the event would have been dropped (ULID < UUID lexicographically).
	if !strings.Contains(body, ulidEvent.ID) {
		t.Fatalf("ULID event was silently dropped — poisoned cursor was not sanitised.\n"+
			"since=%q  event.ID=%q\nbody:\n%s",
			poisonCursor, ulidEvent.ID, body)
	}

	// Confirm the poison cursor itself did not appear as an SSE id: line.
	scanner := bufio.NewScanner(strings.NewReader(body))
	for scanner.Scan() {
		line := scanner.Text()
		if strings.HasPrefix(line, "id: ") {
			sentID := strings.TrimPrefix(line, "id: ")
			if sentID == poisonCursor {
				t.Fatalf("poison cursor appeared as SSE id: line — sanitisation did not fire: %q", sentID)
			}
		}
	}
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// waitForSubscriber polls hub until tenantID has at least one active subscriber
// or the timeout elapses. Returns true on success.
func waitForSubscriber(hub *Hub, tenantID uuid.UUID, timeout time.Duration) bool {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if hub.SubscriberCount(tenantID) > 0 {
			return true
		}
		time.Sleep(5 * time.Millisecond)
	}
	return false
}

// waitForBody polls rec.Body until id appears or the timeout elapses.
func waitForBody(rec *flushableRecorder, id string, timeout time.Duration) {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if strings.Contains(rec.Body.String(), id) {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
}
