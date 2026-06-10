package client

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// stubRepo is a no-op Repo used to test service validation without a real DB.
type stubRepo struct{}

func (r *stubRepo) List(_ context.Context, _ uuid.UUID, _ bool) ([]Client, error) {
	return nil, nil
}
func (r *stubRepo) Get(_ context.Context, _, _ uuid.UUID) (Client, error) { return Client{}, nil }
func (r *stubRepo) Create(_ context.Context, in CreateInput) (Client, error) {
	return Client{TenantID: in.TenantID, Name: in.Name}, nil
}
func (r *stubRepo) Update(_ context.Context, in UpdateInput) (Client, error) {
	name := ""
	if in.Name != nil {
		name = *in.Name
	}
	return Client{TenantID: in.TenantID, Name: name}, nil
}
func (r *stubRepo) Archive(_ context.Context, _, _ uuid.UUID) (Client, error) { return Client{}, nil }
func (r *stubRepo) Delete(_ context.Context, _, _ uuid.UUID) (int64, error)   { return 1, nil }
func (r *stubRepo) CountSites(_ context.Context, _, _ uuid.UUID) (int64, error) {
	return 0, nil
}
func (r *stubRepo) AssignSites(_ context.Context, in AssignInput) (int64, error) {
	return int64(len(in.SiteIDs)), nil
}

func newTestService() *Service {
	return NewService(&stubRepo{})
}

var (
	tenant1 = uuid.New()
)

// ---------------------------------------------------------------------------
// Create validation
// ---------------------------------------------------------------------------

func TestCreateRequiresTenant(t *testing.T) {
	svc := newTestService()
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: uuid.Nil,
		Name:     "ACME",
	})
	assertDomainCode(t, err, "tenant_required")
}

func TestCreateBlankNameRejected(t *testing.T) {
	svc := newTestService()
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "   ",
	})
	assertDomainCode(t, err, "name_required")
}

func TestCreateNameTooLong(t *testing.T) {
	svc := newTestService()
	longName := make([]byte, 201)
	for i := range longName {
		longName[i] = 'x'
	}
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     string(longName),
	})
	assertDomainCode(t, err, "name_too_long")
}

func TestCreateInvalidEmail(t *testing.T) {
	svc := newTestService()
	bad := "notanemail"
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID:     tenant1,
		Name:         "Acme",
		ContactEmail: &bad,
	})
	assertDomainCode(t, err, "invalid_email")
}

func TestCreateInvalidColor(t *testing.T) {
	svc := newTestService()
	bad := "red"
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		Color:    &bad,
	})
	assertDomainCode(t, err, "invalid_color")
}

func TestCreateValidColorAccepted(t *testing.T) {
	svc := newTestService()
	good := "#1a2b3c"
	cl, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		Color:    &good,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cl.Name != "Acme" {
		t.Fatalf("expected name Acme, got %q", cl.Name)
	}
}

// ---------------------------------------------------------------------------
// FIX-1: logo_url validation (defense-in-depth)
// ---------------------------------------------------------------------------

func TestCreateLogoURLHttpRejected(t *testing.T) {
	svc := newTestService()
	bad := "http://example.com/logo.png" // http scheme not allowed
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		LogoURL:  &bad,
	})
	assertDomainCode(t, err, "invalid_logo_url")
}

func TestCreateLogoURLLiteralIPRejected(t *testing.T) {
	svc := newTestService()
	tests := []string{
		"https://10.0.0.1/logo.png",
		"https://192.168.1.1/logo.png",
		"https://127.0.0.1/logo.png",
		"https://[::1]/logo.png",
	}
	for _, bad := range tests {
		bad := bad
		t.Run(bad, func(t *testing.T) {
			_, err := svc.Create(context.Background(), CreateInput{
				TenantID: tenant1,
				Name:     "Acme",
				LogoURL:  &bad,
			})
			assertDomainCode(t, err, "invalid_logo_url")
		})
	}
}

func TestCreateLogoURLTooLongRejected(t *testing.T) {
	svc := newTestService()
	long := "https://example.com/" + string(make([]byte, 2040)) + ".png"
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		LogoURL:  &long,
	})
	assertDomainCode(t, err, "logo_url_too_long")
}

func TestCreateLogoURLValidAccepted(t *testing.T) {
	svc := newTestService()
	good := "https://cdn.example.com/logos/acme.png"
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		LogoURL:  &good,
	})
	if err != nil {
		t.Fatalf("expected valid logo_url to be accepted, got: %v", err)
	}
}

func TestCreateLogoURLEmptyAccepted(t *testing.T) {
	svc := newTestService()
	empty := ""
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		LogoURL:  &empty,
	})
	if err != nil {
		t.Fatalf("empty logo_url should be accepted, got: %v", err)
	}
}

func TestCreateLogoURLNilAccepted(t *testing.T) {
	svc := newTestService()
	_, err := svc.Create(context.Background(), CreateInput{
		TenantID: tenant1,
		Name:     "Acme",
		LogoURL:  nil,
	})
	if err != nil {
		t.Fatalf("nil logo_url should be accepted, got: %v", err)
	}
}

// ---------------------------------------------------------------------------
// AssignSites validation
// ---------------------------------------------------------------------------

func TestAssignSitesRequiresTenant(t *testing.T) {
	svc := newTestService()
	_, err := svc.AssignSites(context.Background(), AssignInput{
		TenantID: uuid.Nil,
		SiteIDs:  []uuid.UUID{uuid.New()},
	})
	assertDomainCode(t, err, "tenant_required")
}

func TestAssignSitesRequiresSiteIDs(t *testing.T) {
	svc := newTestService()
	_, err := svc.AssignSites(context.Background(), AssignInput{
		TenantID: tenant1,
		SiteIDs:  nil,
	})
	assertDomainCode(t, err, "site_ids_required")
}

func TestAssignSitesBatchCap(t *testing.T) {
	svc := newTestService()
	ids := make([]uuid.UUID, maxSiteAssignBatch+1)
	for i := range ids {
		ids[i] = uuid.New()
	}
	_, err := svc.AssignSites(context.Background(), AssignInput{
		TenantID: tenant1,
		SiteIDs:  ids,
	})
	assertDomainCode(t, err, "too_many_sites")
}

func TestAssignSitesUnderCapSucceeds(t *testing.T) {
	svc := newTestService()
	ids := []uuid.UUID{uuid.New(), uuid.New()}
	result, err := svc.AssignSites(context.Background(), AssignInput{
		TenantID: tenant1,
		SiteIDs:  ids,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.Updated != 2 {
		t.Fatalf("expected 2 updated, got %d", result.Updated)
	}
}

// ---------------------------------------------------------------------------
// Delete validation
// ---------------------------------------------------------------------------

func TestDeleteRequiresTenant(t *testing.T) {
	svc := newTestService()
	err := svc.Delete(context.Background(), uuid.Nil, uuid.New())
	assertDomainCode(t, err, "tenant_required")
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// assertDomainCode fails the test if err is not a domain error with the given code.
func assertDomainCode(t *testing.T, err error, code string) {
	t.Helper()
	if err == nil {
		t.Fatalf("expected error with code %q, got nil", code)
	}
	de, ok := domain.AsDomain(err)
	if !ok {
		t.Fatalf("expected domain error with code %q, got %T: %v", code, err, err)
	}
	if de.Code != code {
		t.Fatalf("expected code %q, got %q (message: %s)", code, de.Code, de.Message)
	}
}
