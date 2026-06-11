package portal

import (
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/report"
)

// TestFilterSiteReports_AllowedSiteIDsIsTheGate pins the security-critical
// post-filter between the report aggregator and the portal summary response.
// The aggregator lists ALL sites of a client under InTenantTx (no site-scope
// RLS), so this filter is what guarantees a portal principal only ever sees
// sites in its AllowedSiteIDs. If a refactor drops or weakens it, this test
// fails.
func TestFilterSiteReports_AllowedSiteIDsIsTheGate(t *testing.T) {
	allowedA := uuid.New()
	allowedB := uuid.New()
	strangerC := uuid.New()

	reports := []report.SiteReport{
		{SiteID: allowedA, Name: "a"},
		{SiteID: strangerC, Name: "c"},
		{SiteID: allowedB, Name: "b"},
	}
	allowed := map[uuid.UUID]struct{}{
		allowedA: {},
		allowedB: {},
	}

	got := filterSiteReports(reports, allowed)
	if len(got) != 2 {
		t.Fatalf("filtered count = %d, want 2 (stranger site must be dropped)", len(got))
	}
	for _, sr := range got {
		if sr.SiteID == strangerC {
			t.Fatalf("site outside AllowedSiteIDs leaked through the filter")
		}
	}

	// Empty allowlist fails closed: nothing passes.
	if got := filterSiteReports(reports, map[uuid.UUID]struct{}{}); len(got) != 0 {
		t.Fatalf("empty allowlist must filter everything, got %d", len(got))
	}

	// Nil input degrades to empty, never panics.
	if got := filterSiteReports(nil, allowed); len(got) != 0 {
		t.Fatalf("nil reports must yield empty, got %d", len(got))
	}
}

// TestWorstCWVRating covers the rating collapse used for the per-site vitals
// chip (no raw p75 values reach portal clients; only the worst rating).
func TestWorstCWVRating(t *testing.T) {
	cases := []struct {
		name    string
		ratings []string
		want    string
	}{
		{"empty", nil, ""},
		{"all good", []string{"good", "good"}, "good"},
		{"one needs-improvement", []string{"good", "needs-improvement"}, "needs-improvement"},
		{"poor dominates", []string{"good", "needs-improvement", "poor"}, "poor"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			metrics := make([]report.PerfMetric, 0, len(tc.ratings))
			for _, r := range tc.ratings {
				metrics = append(metrics, report.PerfMetric{Rating: r})
			}
			if got := worstCWVRating(metrics); got != tc.want {
				t.Fatalf("worstCWVRating(%v) = %q, want %q", tc.ratings, got, tc.want)
			}
		})
	}
}
