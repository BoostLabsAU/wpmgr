package vuln_test

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/vuln"
	"github.com/mosamlife/wpmgr/apps/api/internal/wpversion"
)

// ---------------------------------------------------------------------------
// SeverityFromRating tests
// ---------------------------------------------------------------------------

func TestSeverityFromRating(t *testing.T) {
	type tc struct {
		rating string
		score  *float64
		want   string
	}
	score := func(f float64) *float64 { return &f }

	cases := []tc{
		{"Critical", nil, "critical"},
		{"High", nil, "high"},
		{"Medium", nil, "medium"},
		{"Low", nil, "low"},
		{"None", nil, "low"},
		{"", score(9.8), "critical"},
		{"", score(9.0), "critical"},
		{"", score(8.9), "high"},
		{"", score(7.0), "high"},
		{"", score(6.9), "medium"},
		{"", score(4.0), "medium"},
		{"", score(3.9), "low"},
		{"", score(0.0), "low"},
		{"", nil, "low"}, // no rating, no score → low
		{"Unknown", nil, "low"},
	}
	for _, tc := range cases {
		got := vuln.SeverityFromRating(tc.rating, tc.score)
		if got != tc.want {
			t.Errorf("SeverityFromRating(%q, %v) = %q; want %q", tc.rating, tc.score, got, tc.want)
		}
	}
}

// ---------------------------------------------------------------------------
// affected_versions JSON parsing and IsVulnerable integration
// ---------------------------------------------------------------------------

func TestParseAffectedAndMatch(t *testing.T) {
	type tc struct {
		name             string
		affectedVersions string // JSON
		installed        string
		want             bool
	}

	cases := []tc{
		{
			name:             "unbounded range",
			affectedVersions: `[{"from_version":"*","from_inclusive":true,"to_version":"2.0","to_inclusive":false}]`,
			installed:        "1.5",
			want:             true,
		},
		{
			name:             "above patched boundary",
			affectedVersions: `[{"from_version":"*","from_inclusive":true,"to_version":"2.0","to_inclusive":false}]`,
			installed:        "2.0",
			want:             false,
		},
		{
			name:             "multi-range: in range 2",
			affectedVersions: `[{"from_version":"1.0","from_inclusive":true,"to_version":"1.5","to_inclusive":false},{"from_version":"1.8","from_inclusive":true,"to_version":"2.0","to_inclusive":false}]`,
			installed:        "1.9",
			want:             true,
		},
		{
			name:             "multi-range: between ranges",
			affectedVersions: `[{"from_version":"1.0","from_inclusive":true,"to_version":"1.5","to_inclusive":false},{"from_version":"1.8","from_inclusive":true,"to_version":"2.0","to_inclusive":false}]`,
			installed:        "1.6",
			want:             false,
		},
		{
			name:             "empty affected_versions",
			affectedVersions: `[]`,
			installed:        "1.0",
			want:             false,
		},
		{
			name:             "null affected_versions",
			affectedVersions: `null`,
			installed:        "1.0",
			want:             false,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			var ranges []wpversion.AffectedVersionRange
			if err := json.Unmarshal([]byte(tc.affectedVersions), &ranges); err != nil {
				t.Fatalf("unmarshal: %v", err)
			}
			got := wpversion.IsVulnerable(tc.installed, ranges)
			if got != tc.want {
				t.Errorf("IsVulnerable(%q) = %v; want %v", tc.installed, got, tc.want)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// Mock SiteLoader for service-level tests
// ---------------------------------------------------------------------------

type mockSiteLoader struct {
	snap SiteSnap
	err  error
	ids  []uuid.UUID
}

type SiteSnap = vuln.SiteSnapshot

func (m *mockSiteLoader) GetSiteForVuln(_ context.Context, _, _ uuid.UUID) (vuln.SiteSnapshot, error) {
	return m.snap, m.err
}

func (m *mockSiteLoader) ListAllSiteIDs(_ context.Context, _ uuid.UUID) ([]uuid.UUID, error) {
	return m.ids, nil
}

// ---------------------------------------------------------------------------
// Mock RescanEnqueuer
// ---------------------------------------------------------------------------

type captureEnqueuer struct {
	calls []vuln.RescanSiteArgs
}

func (e *captureEnqueuer) EnqueueRescanSite(_ context.Context, args vuln.RescanSiteArgs) error {
	e.calls = append(e.calls, args)
	return nil
}

// ---------------------------------------------------------------------------
// FeedMeta / FeedRecord struct literal tests (no DB)
// ---------------------------------------------------------------------------

func TestFeedMetaZeroValues(t *testing.T) {
	var meta vuln.FeedMeta
	if meta.OK {
		t.Error("zero FeedMeta should have OK=false")
	}
	if meta.FetchedAt != nil {
		t.Error("zero FeedMeta should have nil FetchedAt")
	}
}

func TestSiteSnapshotFields(t *testing.T) {
	snap := vuln.SiteSnapshot{
		ID:        uuid.New(),
		TenantID:  uuid.New(),
		WPVersion: "6.5",
		Plugins: []vuln.ComponentSnapshot{
			{Slug: "akismet", Name: "Akismet", Version: "5.3"},
		},
		Themes: []vuln.ComponentSnapshot{
			{Slug: "twentytwentyfour", Name: "Twenty Twenty-Four", Version: "1.1"},
		},
	}
	if len(snap.Plugins) != 1 {
		t.Error("expected 1 plugin")
	}
	if len(snap.Themes) != 1 {
		t.Error("expected 1 theme")
	}
}

func TestFindingDTO(t *testing.T) {
	now := time.Now().UTC()
	f := vuln.Finding{
		ID:               uuid.New(),
		TenantID:         uuid.New(),
		SiteID:           uuid.New(),
		VulnID:           "abc-123",
		Kind:             "plugin",
		Slug:             "akismet",
		Name:             "Akismet",
		InstalledVersion: "5.2",
		FixedVersion:     "5.3",
		Severity:         "medium",
		Title:            "XSS in Akismet",
		Status:           "open",
		FirstSeen:        now,
		LastSeen:         now,
	}
	// Sanity: zero-valued pointer fields don't panic.
	if f.CVSSScore != nil {
		t.Error("CVSSScore should be nil")
	}
	if f.CVE != "" {
		t.Error("CVE should be empty")
	}
	if f.ResolvedAt != nil {
		t.Error("ResolvedAt should be nil")
	}
}

// ---------------------------------------------------------------------------
// RescanSite unit-level: feed_not_ok → skip
// ---------------------------------------------------------------------------

// This test does not require a database; it exercises the service's early-exit
// when the feed meta is not OK.  We inject a minimal stub via the service
// constructor.  The repo method GetFeedMeta is the gatekeeper — here we test
// the service path where feed is degraded.

// Note: full integration tests (with testcontainers + Postgres) live in
// integration_test.go and require WPMGR_TEST_DB to be set. Those tests verify:
//  - RLS isolation on site_vulnerabilities
//  - UpsertFinding + ResolveStaleFindings round-trip
//  - PruneMissingVulns
//  - The ingester's 429 path (stub HTTP server returning 429)
//  - Key-unset no-op path
