package site

import (
	"reflect"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/api/gen"
)

// TestToAPIUptimeFields asserts that the four new uptime summary fields
// (up, uptime_pct, avg_latency_ms, tls_expires_at) are populated correctly
// when the Site model carries uptime data from repo.List enrichment.
func TestToAPIUptimeFields(t *testing.T) {
	now := time.Date(2026, 6, 16, 12, 0, 0, 0, time.UTC)
	pct := 99.98
	latency := 234.7
	up := true

	s := Site{
		ID:              uuid.New(),
		TenantID:        uuid.New(),
		URL:             "https://example.com",
		Name:            "Example",
		Status:          "active",
		HealthStatus:    "healthy",
		ConnectionState: StateConnected,
		Tags:            []string{},
		CreatedAt:       now,
		UpdatedAt:       now,
		// Uptime fields populated as they would be after repo.List enrichment.
		UptimeUp:     &up,
		UptimePct30d: &pct,
		AvgLatencyMs: &latency,
		TLSExpiresAt: &now,
	}

	out := toAPI(s)

	// Verify the OptBool for "up" (current up/down from latest probe).
	if !out.Up.Set {
		t.Fatalf("expected Up to be set, got absent")
	}
	if out.Up.Value != true {
		t.Fatalf("expected Up=true, got %v", out.Up.Value)
	}

	// Verify the OptFloat64 for "uptime_pct" (30-day uptime percentage).
	if !out.UptimePct.Set {
		t.Fatalf("expected UptimePct to be set, got absent")
	}
	if out.UptimePct.Value != 99.98 {
		t.Fatalf("expected UptimePct=99.98, got %v", out.UptimePct.Value)
	}

	// Verify the OptInt32 for "avg_latency_ms" (float→int32 truncation).
	if !out.AvgLatencyMs.Set {
		t.Fatalf("expected AvgLatencyMs to be set, got absent")
	}
	if out.AvgLatencyMs.Value != 234 {
		t.Fatalf("expected AvgLatencyMs=234 (truncated from 234.7), got %v", out.AvgLatencyMs.Value)
	}

	// Verify the OptDateTime for "tls_expires_at".
	if !out.TLSExpiresAt.Set {
		t.Fatalf("expected TLSExpiresAt to be set, got absent")
	}
	if !out.TLSExpiresAt.Value.Equal(now) {
		t.Fatalf("expected TLSExpiresAt=%v, got %v", now, out.TLSExpiresAt.Value)
	}
}

// TestToAPIUptimeFieldsAbsentWhenUnprobed asserts that when none of the
// uptime fields are populated (site has never been probed), the Opt fields
// are absent (Set=false) so they omit from the JSON response.
func TestToAPIUptimeFieldsAbsentWhenUnprobed(t *testing.T) {
	now := time.Date(2026, 6, 16, 12, 0, 0, 0, time.UTC)
	s := Site{
		ID:              uuid.New(),
		TenantID:        uuid.New(),
		URL:             "https://example.com",
		Name:            "Example",
		Status:          "active",
		HealthStatus:    "healthy",
		ConnectionState: StateConnected,
		Tags:            []string{},
		CreatedAt:       now,
		UpdatedAt:       now,
		// No uptime fields set — site has never been probed.
	}

	out := toAPI(s)

	if out.Up.Set {
		t.Fatalf("expected Up to be absent for unprobed site, got Set=true value=%v", out.Up.Value)
	}
	if out.UptimePct.Set {
		t.Fatalf("expected UptimePct to be absent for unprobed site, got Set=true value=%v", out.UptimePct.Value)
	}
	if out.AvgLatencyMs.Set {
		t.Fatalf("expected AvgLatencyMs to be absent for unprobed site, got Set=true value=%v", out.AvgLatencyMs.Value)
	}
	if out.TLSExpiresAt.Set {
		t.Fatalf("expected TLSExpiresAt to be absent for unprobed site, got Set=true value=%v", out.TLSExpiresAt.Value)
	}
}

// TestSiteGenStructHasUptimeJSONTags verifies that the generated gen.Site struct
// carries the exact JSON field names the web agent wires to, asserting the
// OpenAPI contract and regen are in sync. These names are load-bearing —
// the TypeScript client reads them verbatim.
func TestSiteGenStructHasUptimeJSONTags(t *testing.T) {
	wantTags := map[string]string{
		"Up":           "up",
		"UptimePct":    "uptime_pct",
		"AvgLatencyMs": "avg_latency_ms",
		"TLSExpiresAt": "tls_expires_at",
	}
	st := reflect.TypeOf(gen.Site{})
	for fieldName, wantJSON := range wantTags {
		f, ok := st.FieldByName(fieldName)
		if !ok {
			t.Errorf("gen.Site has no field %q — regen may be stale or field was renamed", fieldName)
			continue
		}
		gotJSON := f.Tag.Get("json")
		if gotJSON != wantJSON {
			t.Errorf("gen.Site.%s json tag = %q, want %q", fieldName, gotJSON, wantJSON)
		}
	}
}
