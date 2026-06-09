package perf

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/rum"
)

// rumStubStore is a minimal in-memory stub satisfying the RumResultsReader fields.
type rumStubStore struct {
	rollups []rum.HourlyRollup
}

func (s *rumStubStore) GetHourlyRollups(_ context.Context, _, _ uuid.UUID, _ time.Time) ([]rum.HourlyRollup, error) {
	return s.rollups, nil
}

func (s *rumStubStore) ComputeP75(rollups []rum.HourlyRollup, minSampleCount int) []rum.P75Result {
	// Delegate to the real StorePostgres.ComputeP75 (pure computation, no DB).
	store := rum.NewStorePostgres(nil)
	return store.ComputeP75(rollups, minSampleCount)
}

// rumConfigRepo is a repo stub that returns a canned Config for GetConfig.
// Only GetConfig is needed by the rum handler; all other methods delegate to
// the existing fakeRepo from service_test.go so the interface is satisfied.
type rumConfigRepo struct {
	fakeRepo
	minSampleCount int
}

func (r *rumConfigRepo) GetConfig(_ context.Context, tenantID, siteID uuid.UUID) (Config, error) {
	return Config{
		TenantID:       tenantID,
		SiteID:         siteID,
		MinSampleCount: r.minSampleCount,
		RumEnabled:     true,
		RumSampleRate:  1.0,
	}, nil
}

// newRumTestHandler builds a minimal Handler for RUM handler tests.
func newRumTestHandler(reader *RumResultsReader, minSampleCount int) *Handler {
	svc := &Service{repo: &rumConfigRepo{minSampleCount: minSampleCount}}
	return &Handler{svc: svc, rum: reader}
}

// injectPrincipal injects a test principal into the gin.Context.
func injectPrincipal(c *gin.Context) {
	ctx := domain.WithPrincipal(c.Request.Context(), domain.Principal{
		TenantID: uuid.New(),
		Role:     string(authz.RoleAdmin),
	})
	c.Request = c.Request.WithContext(ctx)
}

// ---------------------------------------------------------------------------
// min_sample_count suppression tests
// ---------------------------------------------------------------------------

// TestRumSummary_suppressedBelowMinSampleCount verifies that slices with too
// few samples come back with suppressed=true and p75_ms=0 in the summary.
func TestRumSummary_suppressedBelowMinSampleCount(t *testing.T) {
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 5 // 5 samples < minSampleCount=100
	st := &rumStubStore{
		rollups: []rum.HourlyRollup{
			{
				RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
				SampleCount:  5,
				BucketCounts: counts,
			},
		},
	}
	reader := &RumResultsReader{
		GetHourlyRollups: st.GetHourlyRollups,
		ComputeP75:       st.ComputeP75,
	}
	h := newRumTestHandler(reader, 100)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/summary", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumSummary(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/summary", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("rumSummary: expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=true in response, body=%s", body)
	}
}

// TestRumSummary_notSuppressedAboveMinSampleCount verifies that slices meeting
// the floor come back with suppressed=false and a non-zero p75_ms.
func TestRumSummary_notSuppressedAboveMinSampleCount(t *testing.T) {
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 150 // 150 >= minSampleCount=100
	st := &rumStubStore{
		rollups: []rum.HourlyRollup{
			{
				RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
				SampleCount:  150,
				BucketCounts: counts,
				MaxValue:     200,
			},
		},
	}
	reader := &RumResultsReader{
		GetHourlyRollups: st.GetHourlyRollups,
		ComputeP75:       st.ComputeP75,
	}
	h := newRumTestHandler(reader, 100)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/summary", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumSummary(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/summary", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("rumSummary: expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=false for adequate sample count, body=%s", body)
	}
	if rumContains(body, `"p75_ms":0`) {
		t.Errorf("expected non-zero p75_ms for adequate sample count, body=%s", body)
	}
}

// TestRumResults_suppressedBelowMinSampleCount verifies the per-URL table also
// applies the suppression floor.
func TestRumResults_suppressedBelowMinSampleCount(t *testing.T) {
	counts := make([]int32, rum.NumBuckets)
	counts[5] = 3 // 3 < minSampleCount=50
	st := &rumStubStore{
		rollups: []rum.HourlyRollup{
			{
				RollupKey:    rum.RollupKey{URLPattern: "/shop", Metric: "lcp", Device: "mobile", Country: "GB"},
				SampleCount:  3,
				BucketCounts: counts,
			},
		},
	}
	reader := &RumResultsReader{
		GetHourlyRollups: st.GetHourlyRollups,
		ComputeP75:       st.ComputeP75,
	}
	h := newRumTestHandler(reader, 50)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumResults(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("rumResults: expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=true in per-URL results, body=%s", body)
	}
}

// TestRumSummary_nilReader verifies graceful degradation when the reader is nil.
func TestRumSummary_nilReader(t *testing.T) {
	h := newRumTestHandler(nil, 100)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/summary", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumSummary(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/summary", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("nil reader: expected 200, got %d", w.Code)
	}
	if !rumContains(w.Body.String(), `"metrics":[]`) {
		t.Errorf("nil reader: expected empty metrics array, body=%s", w.Body.String())
	}
}

// TestRumResults_nilReader verifies graceful degradation when the reader is nil.
func TestRumResults_nilReader(t *testing.T) {
	h := newRumTestHandler(nil, 100)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumResults(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("nil reader: expected 200, got %d", w.Code)
	}
	if !rumContains(w.Body.String(), `"items":[]`) {
		t.Errorf("nil reader: expected empty items array, body=%s", w.Body.String())
	}
}

// TestRumSummary_cwvRatings verifies the CWV rating thresholds produce the
// correct good/needs_improvement/poor labels.
func TestRumSummary_cwvRatings(t *testing.T) {
	cases := []struct {
		metric string
		p75Ms  float64
		want   string
	}{
		{"lcp", 2000, "good"},
		{"lcp", 3000, "needs_improvement"},
		{"lcp", 5000, "poor"},
		{"inp", 100, "good"},
		{"inp", 300, "needs_improvement"},
		{"inp", 600, "poor"},
		{"cls", 50, "good"},    // 50 milli-units = 0.05 raw (good threshold is 0.1/100 milli)
		{"cls", 200, "needs_improvement"}, // 0.2 raw
		{"cls", 300, "poor"},   // 0.3 raw
		{"fcp", 1000, "good"},
		{"fcp", 2500, "needs_improvement"},
		{"fcp", 4000, "poor"},
		{"ttfb", 500, "good"},
		{"ttfb", 1000, "needs_improvement"},
		{"ttfb", 2000, "poor"},
	}
	for _, tc := range cases {
		got := cwvRating(tc.metric, tc.p75Ms)
		if got != tc.want {
			t.Errorf("cwvRating(%q, %g) = %q, want %q", tc.metric, tc.p75Ms, got, tc.want)
		}
	}
}

// rumContains reports whether s contains the literal substring sub.
func rumContains(s, sub string) bool {
	if len(sub) == 0 || len(s) < len(sub) {
		return false
	}
	for i := 0; i <= len(s)-len(sub); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
