package invitation

import (
	"context"
	"errors"
	"testing"

	"github.com/google/uuid"
)

// fakeEnqueuer is a test double for InviteEnqueuer. It records the last call
// and can be configured to fail or to report SMTP as unconfigured.
type fakeEnqueuer struct {
	enabled      bool
	enqueueErr   error
	lastTemplate string
	lastRecip    []string
	lastData     map[string]any
}

func (f *fakeEnqueuer) Enabled(_ context.Context) bool { return f.enabled }

func (f *fakeEnqueuer) Enqueue(_ context.Context, _ uuid.UUID, recipients []string, template string, data map[string]any) error {
	f.lastTemplate = template
	f.lastRecip = recipients
	f.lastData = data
	return f.enqueueErr
}

// TestInviteEnqueuerInterface confirms that fakeEnqueuer satisfies the
// InviteEnqueuer interface at compile time.
func TestInviteEnqueuerInterface(t *testing.T) {
	var _ InviteEnqueuer = &fakeEnqueuer{}
}

// TestSendClientPortalInvite_EmailSent verifies that sendClientPortalInvite
// returns true when the enqueuer is enabled AND Enqueue succeeds, and that it
// uses the correct template name and the 6 required data keys.
func TestSendClientPortalInvite_EmailSent(t *testing.T) {
	enq := &fakeEnqueuer{enabled: true}
	sent := sendClientPortalInvite(context.Background(), enq, nil,
		"portal@example.com",
		"https://manage.wpmgr.app/accept?token=abc",
		"Alex", "Acme Corp", "Alex Agency")

	if !sent {
		t.Error("expected email_sent=true when SMTP configured and enqueue succeeds")
	}
	if enq.lastTemplate != "client_portal_invite" {
		t.Errorf("expected template %q, got %q", "client_portal_invite", enq.lastTemplate)
	}
	if len(enq.lastRecip) != 1 || enq.lastRecip[0] != "portal@example.com" {
		t.Errorf("unexpected recipients: %v", enq.lastRecip)
	}
	// Verify exactly the 6 required data keys are present.
	for _, k := range []string{"Name", "InviterName", "ClientName", "AgencyName", "AcceptURL", "ExpiresHours"} {
		if _, ok := enq.lastData[k]; !ok {
			t.Errorf("expected template data key %q to be set", k)
		}
	}
}

// TestSendClientPortalInvite_SMTPUnconfigured verifies that email_sent=false is
// returned when the enqueuer reports SMTP unconfigured, but Enqueue is still
// called (so the log row is created).
func TestSendClientPortalInvite_SMTPUnconfigured(t *testing.T) {
	enq := &fakeEnqueuer{enabled: false}
	sent := sendClientPortalInvite(context.Background(), enq, nil,
		"portal@example.com",
		"https://manage.wpmgr.app/accept?token=abc",
		"Alex", "Acme Corp", "Alex Agency")

	if sent {
		t.Error("expected email_sent=false when SMTP unconfigured")
	}
	// Enqueue should still have been called (best-effort log row).
	if enq.lastTemplate != "client_portal_invite" {
		t.Errorf("expected enqueue to be called with template %q even when SMTP unconfigured, got %q",
			"client_portal_invite", enq.lastTemplate)
	}
}

// TestSendClientPortalInvite_EnqueueError verifies that an enqueue failure
// yields email_sent=false and does NOT propagate the error.
func TestSendClientPortalInvite_EnqueueError(t *testing.T) {
	enq := &fakeEnqueuer{enabled: true, enqueueErr: errors.New("river unavailable")}
	sent := sendClientPortalInvite(context.Background(), enq, nil,
		"portal@example.com",
		"https://manage.wpmgr.app/accept?token=abc",
		"Alex", "Acme Corp", "Alex Agency")

	if sent {
		t.Error("expected email_sent=false when enqueue fails")
	}
}

// TestSendClientPortalInvite_NilEnqueuer verifies that a nil enqueuer yields
// email_sent=false with no panic.
func TestSendClientPortalInvite_NilEnqueuer(t *testing.T) {
	sent := sendClientPortalInvite(context.Background(), nil, nil,
		"portal@example.com",
		"https://manage.wpmgr.app/accept?token=abc",
		"Alex", "Acme Corp", "Alex Agency")

	if sent {
		t.Error("expected email_sent=false when enqueuer is nil")
	}
}
