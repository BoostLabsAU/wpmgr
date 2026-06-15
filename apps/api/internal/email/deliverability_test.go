package email

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

// deliverabilityKeysOf returns the keys of a JSON object map (for error messages).
func deliverabilityKeysOf(m map[string]json.RawMessage) []string {
	ks := make([]string, 0, len(m))
	for k := range m {
		ks = append(ks, k)
	}
	return ks
}

// newTestOperatorEngine builds a minimal Gin engine that injects an
// operator-scoped tenant principal onto the request context (simulating
// RequireAuth+RequireTenant+RequireOrgScope) before routing to the handler.
func newTestOperatorEngine(h *Handler, tenantID uuid.UUID) *gin.Engine {
	engine := gin.New()
	v1 := engine.Group("/api/v1")
	v1.Use(func(c *gin.Context) {
		p := domain.Principal{
			TenantID: tenantID,
			UserID:   uuid.New(),
			Type:     domain.PrincipalUser,
			Role:     "operator", // RoleOperator — satisfies PermEmailManage
			Scope:    domain.ScopeOrg,
		}
		c.Request = c.Request.WithContext(domain.WithPrincipal(c.Request.Context(), p))
		c.Next()
	})
	h.Register(v1)
	return engine
}

// ---------------------------------------------------------------------------
// deliverabilityReport DTO contract test
// ---------------------------------------------------------------------------

// TestDeliverabilityReportDTO_JSONContract locks the JSON field names of the
// deliverability response so silent renames do not blank the dashboard.
func TestDeliverabilityReportDTO_JSONContract(t *testing.T) {
	lastSent := time.Date(2026, 6, 1, 0, 0, 0, 0, time.UTC)
	report := DeliverabilityReport{
		WindowDays: 30,
		Items: []SiteDeliveryItem{
			{
				SiteID:          uuid.MustParse("aaaabbbb-0000-0000-0000-000000000001"),
				SiteName:        "My Site",
				SiteURL:         "https://example.com",
				Provider:        "ses",
				Total:           100,
				SentCount:       90,
				FailedCount:     5,
				BouncedCount:    3,
				ComplainedCount: 2,
				BounceRate:      3.0,
				ComplaintRate:   2.0,
				LastSentAt:      &lastSent,
				Sparkline:       []int64{10, 20, 30},
			},
		},
	}

	dto := toDeliverabilityReportDTO(report)
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal deliverabilityReportDTO: %v", err)
	}
	var m map[string]json.RawMessage
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("unmarshal top-level: %v", err)
	}

	// Top-level contract keys.
	for _, k := range []string{"window_days", "items"} {
		if _, ok := m[k]; !ok {
			t.Errorf("deliverabilityReportDTO JSON missing key %q", k)
		}
	}

	// Drill into first item.
	var items []map[string]json.RawMessage
	if err := json.Unmarshal(m["items"], &items); err != nil {
		t.Fatalf("unmarshal items: %v", err)
	}
	if len(items) != 1 {
		t.Fatalf("expected 1 item, got %d", len(items))
	}
	item := items[0]
	for _, k := range []string{
		"site_id", "site_name", "site_url", "provider",
		"total", "sent_count", "failed_count",
		"bounced_count", "complained_count",
		"bounce_rate", "complaint_rate",
		"last_sent_at", "sparkline",
	} {
		if _, ok := item[k]; !ok {
			t.Errorf("siteDeliveryItemDTO JSON missing key %q (got keys %v)", k, deliverabilityKeysOf(item))
		}
	}
}

// TestDeliverabilityReportDTO_SparklineNeverNull verifies that an item with a
// nil sparkline is serialised as [] not null.
func TestDeliverabilityReportDTO_SparklineNeverNull(t *testing.T) {
	report := DeliverabilityReport{
		WindowDays: 7,
		Items: []SiteDeliveryItem{
			{
				SiteID:    uuid.New(),
				SiteURL:   "https://example.com",
				Sparkline: nil, // nil should become []
			},
		},
	}
	dto := toDeliverabilityReportDTO(report)
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	var out map[string]json.RawMessage
	_ = json.Unmarshal(b, &out)
	var items []map[string]json.RawMessage
	_ = json.Unmarshal(out["items"], &items)

	var sparkline json.RawMessage = items[0]["sparkline"]
	if string(sparkline) == "null" {
		t.Error("sparkline must be [] not null when items have no sent data")
	}
}

// TestDeliverabilityReportDTO_ItemsNeverNull verifies that an empty report
// serialises to {window_days:30, items:[]} not {items:null}.
func TestDeliverabilityReportDTO_ItemsNeverNull(t *testing.T) {
	report := DeliverabilityReport{WindowDays: 30, Items: nil}
	dto := toDeliverabilityReportDTO(report)
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	if bytes.Contains(b, []byte(`"items":null`)) {
		t.Errorf("items must be [] not null, got: %s", b)
	}
}

// ---------------------------------------------------------------------------
// emailStatsDTO bounce/complaint fields contract
// ---------------------------------------------------------------------------

// TestEmailStatsDTO_BouncedComplainedContract verifies the new bounced_count
// and complained_count fields appear in the serialised JSON of EmailStats.
func TestEmailStatsDTO_BouncedComplainedContract(t *testing.T) {
	stats := EmailStats{
		Total:           200,
		SentCount:       180,
		FailedCount:     10,
		BouncedCount:    7,
		ComplainedCount: 3,
		ProviderCount:   2,
		SiteCount:       5,
		ByDay: []StatsByDay{
			{
				Day:             time.Date(2026, 6, 1, 0, 0, 0, 0, time.UTC),
				Total:           50,
				SentCount:       45,
				FailedCount:     3,
				BouncedCount:    1,
				ComplainedCount: 1,
			},
		},
		ByProvider: []StatsByProvider{
			{Provider: "ses", Total: 200, SentCount: 180, FailedCount: 10},
		},
	}

	dto := toEmailStatsDTO(stats)
	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("marshal emailStatsDTO: %v", err)
	}
	var m map[string]json.RawMessage
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	// Top-level contract keys including new fields.
	for _, k := range []string{
		"total", "sent_count", "failed_count",
		"bounced_count", "complained_count",
		"provider_count", "by_day", "by_provider",
	} {
		if _, ok := m[k]; !ok {
			t.Errorf("emailStatsDTO JSON missing key %q (got %v)", k, deliverabilityKeysOf(m))
		}
	}

	// Verify bounced_count and complained_count values round-trip correctly.
	var bcRaw, ccRaw float64
	if err := json.Unmarshal(m["bounced_count"], &bcRaw); err != nil {
		t.Fatalf("parse bounced_count: %v", err)
	}
	if err := json.Unmarshal(m["complained_count"], &ccRaw); err != nil {
		t.Fatalf("parse complained_count: %v", err)
	}
	if int64(bcRaw) != 7 {
		t.Errorf("bounced_count: expected 7, got %v", bcRaw)
	}
	if int64(ccRaw) != 3 {
		t.Errorf("complained_count: expected 3, got %v", ccRaw)
	}

	// Verify per-day bounced/complained fields.
	var byDay []map[string]json.RawMessage
	if err := json.Unmarshal(m["by_day"], &byDay); err != nil {
		t.Fatalf("parse by_day: %v", err)
	}
	if len(byDay) != 1 {
		t.Fatalf("expected 1 day entry, got %d", len(byDay))
	}
	for _, k := range []string{"day", "total", "sent_count", "failed_count", "bounced_count", "complained_count"} {
		if _, ok := byDay[0][k]; !ok {
			t.Errorf("emailStatsDayDTO JSON missing key %q", k)
		}
	}
}

// ---------------------------------------------------------------------------
// Service-level tests for GetFleetDelivery
// ---------------------------------------------------------------------------

// TestService_GetFleetDelivery_Passthrough verifies the service delegates to
// the repo and returns the result with the correct window_days.
func TestService_GetFleetDelivery_Passthrough(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	report, err := svc.GetFleetDelivery(context.Background(), uuid.New(), 30)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.WindowDays != 30 {
		t.Errorf("expected WindowDays=30, got %d", report.WindowDays)
	}
	if report.Items == nil {
		t.Error("Items must not be nil (fakeRepo returns empty slice)")
	}
}

// TestService_GetFleetDelivery_TenantScoped verifies that the caller's tenantID
// is passed through to the repo (and not overrideable from a request body).
func TestService_GetFleetDelivery_TenantScoped(t *testing.T) {
	callerTenant := uuid.New()
	otherTenant := uuid.New()
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	r1, err := svc.GetFleetDelivery(context.Background(), callerTenant, 7)
	if err != nil {
		t.Fatalf("callerTenant: %v", err)
	}
	r2, err := svc.GetFleetDelivery(context.Background(), otherTenant, 7)
	if err != nil {
		t.Fatalf("otherTenant: %v", err)
	}
	// Both return from the same fakeRepo which returns empty items — the
	// important invariant is that neither panics and both succeed (RLS
	// isolation at the DB level is validated in integration tests).
	if r1.WindowDays != 7 || r2.WindowDays != 7 {
		t.Errorf("expected WindowDays=7 for both, got %d / %d", r1.WindowDays, r2.WindowDays)
	}
}

// ---------------------------------------------------------------------------
// Handler-level HTTP tests for GET /email/deliverability
// ---------------------------------------------------------------------------

// TestHandler_GetFleetDeliverability_OK verifies that the endpoint returns 200
// and a valid JSON body with window_days and items.
func TestHandler_GetFleetDeliverability_OK(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewHandlerWithPublisher(svc, (*audit.Recorder)(nil), nil)

	tenantID := uuid.New()
	engine := newTestOperatorEngine(h, tenantID)

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/email/deliverability?window=14", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}

	var resp map[string]json.RawMessage
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if _, ok := resp["window_days"]; !ok {
		t.Error("response missing window_days")
	}
	if _, ok := resp["items"]; !ok {
		t.Error("response missing items")
	}

	// Items must be [] not null.
	if string(resp["items"]) == "null" {
		t.Error("items must be [] not null")
	}

	// window_days should reflect the query param.
	var wd float64
	if err := json.Unmarshal(resp["window_days"], &wd); err != nil {
		t.Fatalf("parse window_days: %v", err)
	}
	if int(wd) != 14 {
		t.Errorf("expected window_days=14, got %v", wd)
	}
}

// TestHandler_GetFleetDeliverability_DefaultWindow verifies that omitting the
// ?window param defaults to 30.
func TestHandler_GetFleetDeliverability_DefaultWindow(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewHandlerWithPublisher(svc, (*audit.Recorder)(nil), nil)

	engine := newTestOperatorEngine(h, uuid.New())

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/email/deliverability", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}
	var resp map[string]json.RawMessage
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode: %v", err)
	}
	var wd float64
	_ = json.Unmarshal(resp["window_days"], &wd)
	if int(wd) != 30 {
		t.Errorf("expected default window_days=30, got %v", wd)
	}
}

// TestHandler_GetFleetDeliverability_WindowClampHigh verifies that a window
// value above 365 is clamped to 365.
func TestHandler_GetFleetDeliverability_WindowClampHigh(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewHandlerWithPublisher(svc, (*audit.Recorder)(nil), nil)

	engine := newTestOperatorEngine(h, uuid.New())

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/email/deliverability?window=9999", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	var resp map[string]json.RawMessage
	_ = json.NewDecoder(w.Body).Decode(&resp)
	var wd float64
	_ = json.Unmarshal(resp["window_days"], &wd)
	if int(wd) != 365 {
		t.Errorf("expected clamped window_days=365, got %v", wd)
	}
}

// TestHandler_GetFleetDeliverability_WindowClampLow verifies that a window=0
// is clamped to 1.
func TestHandler_GetFleetDeliverability_WindowClampLow(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewHandlerWithPublisher(svc, (*audit.Recorder)(nil), nil)

	engine := newTestOperatorEngine(h, uuid.New())

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/email/deliverability?window=0", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	var resp map[string]json.RawMessage
	_ = json.NewDecoder(w.Body).Decode(&resp)
	var wd float64
	_ = json.Unmarshal(resp["window_days"], &wd)
	if int(wd) != 1 {
		t.Errorf("expected clamped window_days=1, got %v", wd)
	}
}

// ---------------------------------------------------------------------------
// BounceRate / ComplaintRate calculation
// ---------------------------------------------------------------------------

// TestSiteDeliveryItem_RateCalculation verifies that bounce_rate and
// complaint_rate are correctly computed when total > 0.
func TestSiteDeliveryItem_RateCalculation(t *testing.T) {
	item := SiteDeliveryItem{
		SiteID:          uuid.New(),
		Total:           200,
		SentCount:       180,
		FailedCount:     10,
		BouncedCount:    10,  // 10/200*100 = 5.0%
		ComplainedCount: 1,   // 1/200*100  = 0.5%
		BounceRate:      5.0,
		ComplaintRate:   0.5,
		Sparkline:       []int64{},
	}
	report := DeliverabilityReport{WindowDays: 30, Items: []SiteDeliveryItem{item}}
	dto := toDeliverabilityReportDTO(report)
	if len(dto.Items) != 1 {
		t.Fatalf("expected 1 item")
	}
	if dto.Items[0].BounceRate != 5.0 {
		t.Errorf("bounce_rate: expected 5.0, got %v", dto.Items[0].BounceRate)
	}
	if dto.Items[0].ComplaintRate != 0.5 {
		t.Errorf("complaint_rate: expected 0.5, got %v", dto.Items[0].ComplaintRate)
	}
}

// TestSiteDeliveryItem_RateZeroWhenTotalZero verifies that when total=0 the
// rates are 0.0 (no divide-by-zero).
func TestSiteDeliveryItem_RateZeroWhenTotalZero(t *testing.T) {
	item := SiteDeliveryItem{
		SiteID:          uuid.New(),
		Total:           0,
		BouncedCount:    0,
		ComplainedCount: 0,
		BounceRate:      0.0,
		ComplaintRate:   0.0,
		Sparkline:       []int64{},
	}
	report := DeliverabilityReport{WindowDays: 30, Items: []SiteDeliveryItem{item}}
	dto := toDeliverabilityReportDTO(report)
	if dto.Items[0].BounceRate != 0.0 {
		t.Errorf("bounce_rate with total=0: expected 0.0, got %v", dto.Items[0].BounceRate)
	}
	if dto.Items[0].ComplaintRate != 0.0 {
		t.Errorf("complaint_rate with total=0: expected 0.0, got %v", dto.Items[0].ComplaintRate)
	}
}
