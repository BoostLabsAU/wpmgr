package site

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// fakeScreenshotEnricher records whether EnrichSites was invoked.
type fakeScreenshotEnricher struct{ called bool }

func (f *fakeScreenshotEnricher) EnrichSites(context.Context, uuid.UUID, []Site) error {
	f.called = true
	return nil
}

// TestServiceSetScreenshotEnricher_WiresServiceOwnRepo guards the v0.49.1 bug:
// the screenshot enricher was wired onto a SECOND site.NewRepo instance, not the
// one held inside the Service that serves GET /api/v1/sites, so list enrichment
// was a permanent silent no-op (every card showed the "never" placeholder even
// though ready screenshots existed in object storage). This asserts the
// Service-level setter lands the enricher on the Service's own repo — the exact
// instance List() delegates to.
func TestServiceSetScreenshotEnricher_WiresServiceOwnRepo(t *testing.T) {
	svc := NewService(NewRepo(nil), domain.NewValidator(), domain.SystemClock{})

	enr := &fakeScreenshotEnricher{}
	svc.SetScreenshotEnricher(enr)

	r, ok := svc.repo.(*pgRepo)
	if !ok {
		t.Fatalf("service repo is not *pgRepo: %T", svc.repo)
	}
	if r.screenshotEnr == nil {
		t.Fatal("SetScreenshotEnricher did not wire the enricher onto the service's own repo")
	}
	// And it must be the very enricher we passed (not some other wiring).
	if err := r.screenshotEnr.EnrichSites(context.Background(), uuid.Nil, nil); err != nil {
		t.Fatalf("EnrichSites returned error: %v", err)
	}
	if !enr.called {
		t.Fatal("wired enricher is not the one provided to SetScreenshotEnricher")
	}
}
