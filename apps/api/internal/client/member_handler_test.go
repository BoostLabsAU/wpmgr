package client

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/google/uuid"
)

// fakeInviteService is a test double for InviteService.
type fakeInviteService struct {
	acceptLink   string
	invitationID uuid.UUID
	expiresAt    time.Time
	emailSent    bool
	err          error
}

func (f *fakeInviteService) CreateClientInvitation(
	_ context.Context, _, _, _ uuid.UUID, _ string,
) (acceptLink string, invitationID uuid.UUID, expiresAt time.Time, emailSent bool, err error) {
	return f.acceptLink, f.invitationID, f.expiresAt, f.emailSent, f.err
}

// TestAddMember_InviteServiceInterface confirms that fakeInviteService satisfies
// InviteService at compile time.
func TestAddMember_InviteServiceInterface(t *testing.T) {
	var _ InviteService = &fakeInviteService{}
}

// TestInviteResultDTO_EmailSentField verifies that the inviteResultDTO struct
// carries an email_sent field with the correct JSON tag, that it is emitted
// as true/false (not omitted), and that the accept_link is present.
// This is the JSON-shape regression lock for the handler's wire response.
func TestInviteResultDTO_EmailSentField(t *testing.T) {
	// email_sent=true path.
	dtoTrue := inviteResultDTO{
		Email:     "test@example.com",
		Invited:   true,
		EmailSent: true,
	}
	bTrue, err := json.Marshal(dtoTrue)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	var mTrue map[string]interface{}
	if err := json.Unmarshal(bTrue, &mTrue); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if _, ok := mTrue["email_sent"]; !ok {
		t.Error("email_sent must be present in marshaled inviteResultDTO")
	}
	if mTrue["email_sent"] != true {
		t.Errorf("expected email_sent=true, got %v", mTrue["email_sent"])
	}

	// email_sent=false path (must be emitted, not omitted).
	dtoFalse := inviteResultDTO{Email: "test@example.com", Invited: false, EmailSent: false}
	bFalse, _ := json.Marshal(dtoFalse)
	var mFalse map[string]interface{}
	_ = json.Unmarshal(bFalse, &mFalse)
	if _, ok := mFalse["email_sent"]; !ok {
		t.Error("email_sent must be present even when false (not omitempty)")
	}
	if mFalse["email_sent"] != false {
		t.Errorf("expected email_sent=false, got %v", mFalse["email_sent"])
	}

	// accept_link must be present (never omitted regardless of invite state).
	dtoWithLink := inviteResultDTO{
		Email:      "portal@example.com",
		Invited:    true,
		EmailSent:  false,
		AcceptLink: "https://manage.wpmgr.app/accept?token=xyz",
	}
	bLink, _ := json.Marshal(dtoWithLink)
	var mLink map[string]interface{}
	_ = json.Unmarshal(bLink, &mLink)
	if v, ok := mLink["accept_link"]; !ok || v == "" {
		t.Error("accept_link must be present in the response JSON")
	}
}

// TestInviteResultDTO_ExistingUser verifies that an existing-user result has
// email_sent=false and invited=false.
func TestInviteResultDTO_ExistingUser(t *testing.T) {
	uid := uuid.New().String()
	cat := time.Now().UTC().Format("2006-01-02T15:04:05Z")
	dto := inviteResultDTO{
		Email:      "existing@example.com",
		Invited:    false,
		EmailSent:  false,
		UserID:     &uid,
		CreatedAt:  &cat,
		AcceptLink: "https://manage.wpmgr.app/portal",
	}
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	var m map[string]interface{}
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if m["invited"] != false {
		t.Errorf("expected invited=false for existing user, got %v", m["invited"])
	}
	if m["email_sent"] != false {
		t.Errorf("expected email_sent=false for existing user, got %v", m["email_sent"])
	}
}
