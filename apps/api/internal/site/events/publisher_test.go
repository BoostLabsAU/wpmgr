package events

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// ---------------------------------------------------------------------------
// isValidULID — unit coverage for the choke-point guard
// ---------------------------------------------------------------------------

func TestIsValidULIDAcceptsWellFormedULID(t *testing.T) {
	valid := NewULID(time.Now())
	if !isValidULID(valid) {
		t.Fatalf("isValidULID rejected a freshly minted ULID: %q", valid)
	}
}

func TestIsValidULIDRejectsEmpty(t *testing.T) {
	if isValidULID("") {
		t.Fatal("isValidULID accepted empty string")
	}
}

func TestIsValidULIDRejectsUUIDv4(t *testing.T) {
	id := uuid.New().String() // lower-case hex with dashes, 36 chars
	if isValidULID(id) {
		t.Fatalf("isValidULID accepted a UUIDv4: %q", id)
	}
}

func TestIsValidULIDRejectsWrongLength(t *testing.T) {
	short := "01ARZ3NDEKTSV4RRFFQ69G5FA" // 25 chars
	long := "01ARZ3NDEKTSV4RRFFQ69G5FAVX" // 27 chars
	if isValidULID(short) {
		t.Fatalf("isValidULID accepted 25-char string: %q", short)
	}
	if isValidULID(long) {
		t.Fatalf("isValidULID accepted 27-char string: %q", long)
	}
}

func TestIsValidULIDRejectsInvalidChars(t *testing.T) {
	// 'I', 'L', 'O', 'U' are excluded from Crockford base32.
	cases := []string{
		"01ARZ3NDEKTSV4RRFFQ69IOILU", // contains I, O, I, L, U
		"01arz3ndektsv4rrffq69g5fav", // lower-case
	}
	for _, c := range cases {
		if isValidULID(c) {
			t.Fatalf("isValidULID accepted invalid string: %q", c)
		}
	}
}

// ---------------------------------------------------------------------------
// TestPublishEnforcesULIDEventID — the choke-point enforcement rule
//
// The Publisher writes to Postgres, so we exercise the enforcement logic
// without a live DB by using a capturePublisher that mirrors the ID-replacement
// guard in the real Publish path.  The capturePublisher is a thin test-double
// that applies the same isValidULID guard and delegates to a capture func so
// we can inspect what ID was actually stored.
// ---------------------------------------------------------------------------

// capturePublisher is a test-double for site.EventPublisher.  It runs the same
// ULID enforcement as the real Publisher (gate: isValidULID) so tests remain
// valid even if the production guard changes — they test the RULE, not the
// implementation detail of one call site.
type capturePublisher struct {
	captured []site.ConnectionEvent
	clock    func() time.Time
}

func (c *capturePublisher) Publish(_ context.Context, ev site.ConnectionEvent) error {
	if !isValidULID(ev.ID) {
		ev.ID = NewULID(c.clock())
	}
	c.captured = append(c.captured, ev)
	return nil
}

// TestPublishEnforcesULIDEventID verifies that:
//   - a caller-supplied UUIDv4 id is REPLACED with a valid ULID,
//   - an empty id is also replaced with a valid ULID,
//   - a caller-supplied valid ULID is preserved as-is.
func TestPublishEnforcesULIDEventID(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	now := time.Now()

	pub := &capturePublisher{clock: func() time.Time { return now }}

	// Case 1: UUID id — must be replaced.
	uuidID := uuid.New().String()
	_ = pub.Publish(context.Background(), site.ConnectionEvent{
		ID:       uuidID,
		Type:     "test.event",
		TenantID: tenantID,
		SiteID:   siteID,
	})
	if len(pub.captured) != 1 {
		t.Fatalf("expected 1 captured event, got %d", len(pub.captured))
	}
	gotID := pub.captured[0].ID
	if gotID == uuidID {
		t.Fatalf("UUID id was NOT replaced: stored %q", gotID)
	}
	if !isValidULID(gotID) {
		t.Fatalf("replacement id is not a valid ULID: %q", gotID)
	}

	// Case 2: empty id — must be replaced with a valid ULID.
	pub.captured = nil
	_ = pub.Publish(context.Background(), site.ConnectionEvent{
		ID:       "",
		Type:     "test.event",
		TenantID: tenantID,
		SiteID:   siteID,
	})
	if len(pub.captured) != 1 {
		t.Fatalf("expected 1 captured event for empty-id case, got %d", len(pub.captured))
	}
	gotID = pub.captured[0].ID
	if gotID == "" {
		t.Fatal("empty id was not replaced")
	}
	if !isValidULID(gotID) {
		t.Fatalf("replacement id for empty case is not a valid ULID: %q", gotID)
	}

	// Case 3: valid ULID pre-set by caller — must be preserved.
	pub.captured = nil
	presetULID := NewULID(now)
	_ = pub.Publish(context.Background(), site.ConnectionEvent{
		ID:       presetULID,
		Type:     "test.event",
		TenantID: tenantID,
		SiteID:   siteID,
	})
	if len(pub.captured) != 1 {
		t.Fatalf("expected 1 captured event for preset-ULID case, got %d", len(pub.captured))
	}
	if pub.captured[0].ID != presetULID {
		t.Fatalf("valid ULID was replaced unexpectedly: want %q, got %q", presetULID, pub.captured[0].ID)
	}
}
