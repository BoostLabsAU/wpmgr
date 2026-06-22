package portal

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/metrics"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

type stubSiteService struct {
	sites  []site.Site
	lastIn site.ListInput
}

func (s *stubSiteService) List(ctx context.Context, in site.ListInput) ([]site.Site, error) {
	s.lastIn = in
	return s.sites, nil
}

type recordingMetricsStore struct {
	aggregateIDs []uuid.UUID
	latestIDs    []uuid.UUID
}

func (m *recordingMetricsStore) QueryAggregate(ctx context.Context, tenantID, siteID uuid.UUID, window time.Duration) (metrics.Aggregate, error) {
	m.aggregateIDs = append(m.aggregateIDs, siteID)
	return metrics.Aggregate{Checks: 1, UptimePct: 99.9}, nil
}

func (m *recordingMetricsStore) QueryLatest(ctx context.Context, tenantID, siteID uuid.UUID) (metrics.Latest, error) {
	m.latestIDs = append(m.latestIDs, siteID)
	return metrics.Latest{Found: false}, nil
}

func buildListSitesEngine(h *Handler, p domain.Principal) *gin.Engine {
	gin.SetMode(gin.TestMode)
	r := gin.New()
	r.Use(func(c *gin.Context) {
		c.Request = c.Request.WithContext(domain.WithPrincipal(c.Request.Context(), p))
		c.Next()
	})
	g := r.Group("/api/v1")
	g.GET("/portal/sites", h.listSites)
	return r
}

func doListSites(r *gin.Engine) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/portal/sites", nil)
	r.ServeHTTP(rec, req)
	return rec
}

// TestListSites_FiltersOverBroadRepoBeforeMetrics pins the app-layer gate on
// GET /portal/sites. The repo may return tenant sites outside the portal
// principal's AllowedSiteIDs; this route must drop them before metrics
// enrichment runs.
func TestListSites_FiltersOverBroadRepoBeforeMetrics(t *testing.T) {
	tenantID := uuid.New()
	clientID := uuid.New()
	allowedSiteID := uuid.New()
	disallowedSiteID := uuid.New()

	sites := &stubSiteService{
		sites: []site.Site{
			{ID: allowedSiteID, TenantID: tenantID, Name: "Allowed", URL: "https://allowed.example", ConnectionState: site.StateConnected},
			{ID: disallowedSiteID, TenantID: tenantID, Name: "Stranger", URL: "https://stranger.example", ConnectionState: site.StateConnected},
		},
	}
	metricsStore := &recordingMetricsStore{}

	h := NewHandler(nil, sites, nil, nil, nil, nil)
	h.SetMetricsStore(metricsStore)

	p := domain.Principal{
		Type:           domain.PrincipalUser,
		UserID:         uuid.New(),
		TenantID:       tenantID,
		Role:           "client",
		ClientIDs:      []uuid.UUID{clientID},
		AllowedSiteIDs: []uuid.UUID{allowedSiteID},
	}

	rec := doListSites(buildListSitesEngine(h, p))
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}

	var body portalSiteListDTO
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if len(body.Items) != 1 {
		t.Fatalf("item count = %d, want 1", len(body.Items))
	}
	if body.Items[0].ID != allowedSiteID.String() {
		t.Fatalf("returned site id = %q, want allowed site only", body.Items[0].ID)
	}
	for _, item := range body.Items {
		if item.ID == disallowedSiteID.String() {
			t.Fatalf("site outside AllowedSiteIDs leaked in response")
		}
	}

	if sites.lastIn.TenantID != tenantID {
		t.Fatalf("repo TenantID = %v, want %v", sites.lastIn.TenantID, tenantID)
	}
	if sites.lastIn.Principal == nil {
		t.Fatal("repo List must receive a non-nil Principal")
	}

	for _, id := range metricsStore.aggregateIDs {
		if id == disallowedSiteID {
			t.Fatalf("metrics QueryAggregate called for disallowed site %s", id)
		}
	}
	for _, id := range metricsStore.latestIDs {
		if id == disallowedSiteID {
			t.Fatalf("metrics QueryLatest called for disallowed site %s", id)
		}
	}
	if len(metricsStore.aggregateIDs) != 1 || metricsStore.aggregateIDs[0] != allowedSiteID {
		t.Fatalf("QueryAggregate site IDs = %v, want [%s]", metricsStore.aggregateIDs, allowedSiteID)
	}
}

// TestListSites_EmptyAllowlistFailsClosed proves an empty AllowedSiteIDs set
// returns no items and skips metrics enrichment even when the repo is over-broad.
func TestListSites_EmptyAllowlistFailsClosed(t *testing.T) {
	tenantID := uuid.New()
	clientID := uuid.New()
	siteA := uuid.New()
	siteB := uuid.New()

	sites := &stubSiteService{
		sites: []site.Site{
			{ID: siteA, TenantID: tenantID, Name: "A", URL: "https://a.example", ConnectionState: site.StateConnected},
			{ID: siteB, TenantID: tenantID, Name: "B", URL: "https://b.example", ConnectionState: site.StateConnected},
		},
	}
	metricsStore := &recordingMetricsStore{}

	h := NewHandler(nil, sites, nil, nil, nil, nil)
	h.SetMetricsStore(metricsStore)

	p := domain.Principal{
		Type:           domain.PrincipalUser,
		UserID:         uuid.New(),
		TenantID:       tenantID,
		Role:           "client",
		ClientIDs:      []uuid.UUID{clientID},
		AllowedSiteIDs: nil,
	}

	rec := doListSites(buildListSitesEngine(h, p))
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}

	var body portalSiteListDTO
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if len(body.Items) != 0 {
		t.Fatalf("empty allowlist must return no items, got %d", len(body.Items))
	}
	if len(metricsStore.aggregateIDs) != 0 || len(metricsStore.latestIDs) != 0 {
		t.Fatalf("metrics must not run on empty allowlist: aggregate=%v latest=%v",
			metricsStore.aggregateIDs, metricsStore.latestIDs)
	}
}
