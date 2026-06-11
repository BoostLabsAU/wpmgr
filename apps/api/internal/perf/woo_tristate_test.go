package perf

// Tests for the M67 WooCommerce fragments tri-state change.
//
// Covered cases:
//  1. Stats report with woo field absent: stored value is not touched (0 rows
//     returned from UpdateWooFragmentsSupported is logged, not errored).
//  2. Stats report with woo field=true: UpdateWooFragmentsSupported is called
//     with supported=true and probed_at is stamped (repo records the call).
//  3. GetConfig with no row returns a Config where WooThemeFragmentsSupported
//     is nil (the tri-state null), not false.
//  4. UpdateWooFragmentsSupported returning 0 rows (no config row yet) is
//     treated as a no-op by MarkWooFragmentsSupported (no error returned).

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
)

// wooFakeRepo extends fakeRepo with tracking for UpdateWooFragmentsSupported
// calls and a configurable rows-affected return.
type wooFakeRepo struct {
	fakeRepo
	mu            sync.Mutex
	wooCalls      []bool // supported values passed to UpdateWooFragmentsSupported
	wooRowsReturn int64  // rows affected to return (default 1)
}

func (r *wooFakeRepo) UpdateWooFragmentsSupported(_ context.Context, _ uuid.UUID, supported bool) (int64, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.wooCalls = append(r.wooCalls, supported)
	n := r.wooRowsReturn
	if n == 0 {
		// Zero-value means "not set"; default to 1 unless explicitly set to -1.
		n = 1
	}
	if r.wooRowsReturn == -1 {
		n = 0
	}
	return n, nil
}

// TestWooFieldAbsentInStatsReportLeavesStoredValueUntouched verifies that when
// the agent omits woo_theme_fragments_supported from the stats body (nil pointer),
// the handler does NOT call MarkWooFragmentsSupported.
func TestWooFieldAbsentInStatsReportLeavesStoredValueUntouched(t *testing.T) {
	gin.SetMode(gin.TestMode)
	repo := &wooFakeRepo{}
	svc := NewService(repo, nil, nil, nil)
	h := NewAgentHandler(svc, nil, nil)

	eng := gin.New()
	siteID, tenantID := uuid.New(), uuid.New()
	id := agent.Identity{SiteID: siteID, TenantID: tenantID}
	eng.POST("/agent/v1/cache/stats-report", withIdentity(id, h.statsReport))

	// Body with no woo_theme_fragments_supported field.
	body := `{"cached_pages_count":5,"cache_size_bytes":1024}`
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/cache/stats-report", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	repo.mu.Lock()
	calls := len(repo.wooCalls)
	repo.mu.Unlock()
	if calls != 0 {
		t.Fatalf("expected 0 UpdateWooFragmentsSupported calls when field absent, got %d", calls)
	}
}

// TestWooFieldTrueInStatsReportCallsMarkSupported verifies that when the agent
// sends woo_theme_fragments_supported=true, MarkWooFragmentsSupported is called
// with supported=true.
func TestWooFieldTrueInStatsReportCallsMarkSupported(t *testing.T) {
	gin.SetMode(gin.TestMode)
	repo := &wooFakeRepo{}
	svc := NewService(repo, nil, nil, nil)
	h := NewAgentHandler(svc, nil, nil)

	eng := gin.New()
	siteID, tenantID := uuid.New(), uuid.New()
	id := agent.Identity{SiteID: siteID, TenantID: tenantID}
	eng.POST("/agent/v1/cache/stats-report", withIdentity(id, h.statsReport))

	// Body with woo_theme_fragments_supported=true.
	body, _ := json.Marshal(map[string]any{
		"cached_pages_count":           3,
		"cache_size_bytes":             512,
		"woo_theme_fragments_supported": true,
	})
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/cache/stats-report", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	repo.mu.Lock()
	calls := repo.wooCalls
	repo.mu.Unlock()
	if len(calls) != 1 {
		t.Fatalf("expected 1 UpdateWooFragmentsSupported call, got %d", len(calls))
	}
	if !calls[0] {
		t.Fatalf("expected supported=true, got false")
	}
}

// TestGetConfigNoRowReturnsNullWooTristate verifies that defaultConfig (returned
// when no DB row exists) has WooThemeFragmentsSupported == nil.
func TestGetConfigNoRowReturnsNullWooTristate(t *testing.T) {
	svc := NewService(&fakeRepo{configFound: false}, nil, nil, nil)
	tenantID, siteID := uuid.New(), uuid.New()

	cfg, err := svc.GetConfig(context.Background(), tenantID, siteID)
	if err != nil {
		t.Fatalf("GetConfig: %v", err)
	}
	if cfg.WooThemeFragmentsSupported != nil {
		t.Fatalf("expected WooThemeFragmentsSupported nil for no-row default, got %v", cfg.WooThemeFragmentsSupported)
	}
	if cfg.WooFragmentsProbedAt != nil {
		t.Fatalf("expected WooFragmentsProbedAt nil for no-row default, got %v", cfg.WooFragmentsProbedAt)
	}
}

// TestMarkWooFragmentsSupportedZeroRowsIsNoOp verifies that when
// UpdateWooFragmentsSupported returns 0 rows (no config row exists yet for the
// site), MarkWooFragmentsSupported returns nil (no error propagated to the
// stats-report handler — the agent retries on its next heartbeat).
func TestMarkWooFragmentsSupportedZeroRowsIsNoOp(t *testing.T) {
	repo := &wooFakeRepo{wooRowsReturn: -1} // -1 sentinel -> returns 0 rows
	svc := NewService(repo, nil, nil, nil)

	err := svc.MarkWooFragmentsSupported(context.Background(), uuid.New(), true)
	if err != nil {
		t.Fatalf("expected nil error when 0 rows affected, got %v", err)
	}
}

// TestWooConfigDTONullableRoundTrip verifies that toConfigDTO correctly maps a
// nil WooThemeFragmentsSupported (the new tri-state null) to a nil *bool in the
// DTO, and that the JSON encoding produces a literal null (not absent/false).
func TestWooConfigDTONullableRoundTrip(t *testing.T) {
	cfg := defaultConfig(uuid.New(), uuid.New())
	// defaultConfig must produce nil, not false.
	if cfg.WooThemeFragmentsSupported != nil {
		t.Fatalf("defaultConfig produced non-nil WooThemeFragmentsSupported: %v", cfg.WooThemeFragmentsSupported)
	}

	dto := toConfigDTO(cfg)
	if dto.WooThemeFragmentsSupported != nil {
		t.Fatalf("toConfigDTO produced non-nil WooThemeFragmentsSupported: %v", dto.WooThemeFragmentsSupported)
	}

	// Marshal to JSON and confirm null encoding.
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	// The field must appear as null in the JSON output (not absent, since the
	// web depends on seeing it to render the "not yet detected" state).
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(b, &raw); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	v, ok := raw["woo_theme_fragments_supported"]
	if !ok {
		t.Fatal("woo_theme_fragments_supported must be present in JSON output")
	}
	if string(v) != "null" {
		t.Fatalf("expected JSON null for woo_theme_fragments_supported, got %s", string(v))
	}
}
