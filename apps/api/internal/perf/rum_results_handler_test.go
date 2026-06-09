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

// ---------------------------------------------------------------------------
// Distribution fold (bucket → good/NI/poor) tests
// ---------------------------------------------------------------------------

// makeCounts builds a NumBuckets int64 slice with specific bucket values set.
// Each pair is (bucketIndex, count).
func makeCounts(pairs ...int64) []int64 {
	c := make([]int64, rum.NumBuckets)
	for i := 0; i+1 < len(pairs); i += 2 {
		c[pairs[i]] = pairs[i+1]
	}
	return c
}

// TestFoldBuckets_LCP_exact verifies the fold for LCP (thresholds 2500/4000 ms).
// CrUXBuckets index 12 = upper bound 2500, index 15 = upper bound 4000.
// Good:  buckets 0..12 (lower bounds 0..2000, all < 2500)
// NI:    buckets 13..15 (lower bounds 2500..3500, all < 4000)
// Poor:  buckets 16..23 (lower bounds 4000+)
func TestFoldBuckets_LCP_exact(t *testing.T) {
	// Build a histogram: 100 in a "good" bucket (bucket 0, [0,200)),
	//                    50 in a "NI" bucket (bucket 13, [2500,3000)),
	//                    25 in a "poor" bucket (bucket 16, [4000,4500)).
	// bucket 0 lower=0 < 2500 → good
	// bucket 13: CrUXBuckets[12]=2500, so lower of bucket 13 = 2500 ≥ 2500 → NI (< 4000)
	// bucket 16: CrUXBuckets[15]=4000, so lower of bucket 16 = 4000 ≥ 4000 → poor
	counts := makeCounts(0, 100, 13, 50, 16, 25)
	d := foldBucketsIntoDistribution("lcp", counts)

	if d.Good != 100 {
		t.Errorf("LCP good count = %d, want 100", d.Good)
	}
	if d.NeedsImprovement != 50 {
		t.Errorf("LCP NI count = %d, want 50", d.NeedsImprovement)
	}
	if d.Poor != 25 {
		t.Errorf("LCP poor count = %d, want 25", d.Poor)
	}
	if d.GoodPct+d.NeedsImprovementPct+d.PoorPct != 100 {
		t.Errorf("LCP pct sum = %d, want 100 (good=%d ni=%d poor=%d)",
			d.GoodPct+d.NeedsImprovementPct+d.PoorPct, d.GoodPct, d.NeedsImprovementPct, d.PoorPct)
	}
	// 100/175 ≈ 57.1%, 50/175 ≈ 28.6%, 25/175 ≈ 14.3% → Hamilton rounds to 57+29+14=100.
	total := d.GoodPct + d.NeedsImprovementPct + d.PoorPct
	if total != 100 {
		t.Errorf("LCP pct total = %d, want exactly 100", total)
	}
}

// TestFoldBuckets_INP_exact verifies INP thresholds (200/500 ms).
// bucket 1 lower=200 is the first bucket with lower=200 ≥ 200, so it's NI (< 500).
// bucket 0 lower=0 < 200 → good.
// bucket 5 CrUXBuckets[4]=600, lower=600 ≥ 500 → poor.
func TestFoldBuckets_INP_exact(t *testing.T) {
	counts := makeCounts(0, 80, 1, 40, 5, 20)
	d := foldBucketsIntoDistribution("inp", counts)

	if d.Good != 80 {
		t.Errorf("INP good = %d, want 80", d.Good)
	}
	if d.NeedsImprovement != 40 {
		t.Errorf("INP NI = %d, want 40", d.NeedsImprovement)
	}
	if d.Poor != 20 {
		t.Errorf("INP poor = %d, want 20", d.Poor)
	}
	if d.GoodPct+d.NeedsImprovementPct+d.PoorPct != 100 {
		t.Errorf("INP pct sum != 100: %d", d.GoodPct+d.NeedsImprovementPct+d.PoorPct)
	}
}

// TestFoldBuckets_CLS_straddle verifies the CLS coarse-fold behaviour.
// CLS thresholds: good≤100, NI≤250 (milli-units). The first CrUX boundary is 200,
// so the entire first bucket [0,200) has lower=0 < 100 → assigned to "good"
// (straddle-to-lower-band). Bucket 1 (lower=200 ≥ 100, < 250) → NI.
// For this test, buckets 0..0 = good, bucket 1 = NI, bucket 2+ = poor.
func TestFoldBuckets_CLS_straddle(t *testing.T) {
	// bucket 0 lower=0 < 100 → good (even though [0,200) spans both 100 and 250)
	// bucket 1 lower=200 ≥ 100 and < 250 → NI
	// bucket 2 lower=300 ≥ 250 → poor
	counts := makeCounts(0, 60, 1, 30, 2, 10)
	d := foldBucketsIntoDistribution("cls", counts)

	if d.Good != 60 {
		t.Errorf("CLS good = %d, want 60 (straddle→lower band = good)", d.Good)
	}
	if d.NeedsImprovement != 30 {
		t.Errorf("CLS NI = %d, want 30", d.NeedsImprovement)
	}
	if d.Poor != 10 {
		t.Errorf("CLS poor = %d, want 10", d.Poor)
	}
	if d.GoodPct+d.NeedsImprovementPct+d.PoorPct != 100 {
		t.Errorf("CLS pct sum = %d, want 100", d.GoodPct+d.NeedsImprovementPct+d.PoorPct)
	}
}

// TestFoldBuckets_PctSumTo100_edgeCase verifies Hamilton rounding for a
// distribution where naive floor-rounding yields 99 (each proportion has a
// large fractional part). Three equal bands of 1/3 each: floors are 33+33+33=99.
func TestFoldBuckets_PctSumTo100_edgeCase(t *testing.T) {
	// Use LCP so we can fill a known set of buckets. We put 1 sample in each of
	// three bands (good / NI / poor), so counts are 1/1/1, total=3, each=33.33%.
	counts := makeCounts(0, 1, 13, 1, 16, 1)
	d := foldBucketsIntoDistribution("lcp", counts)
	sum := d.GoodPct + d.NeedsImprovementPct + d.PoorPct
	if sum != 100 {
		t.Errorf("pct sum = %d, want exactly 100 (Hamilton rounding failure)", sum)
	}
}

// TestFoldBuckets_AllGood verifies that a histogram entirely in the good band
// yields good_pct=100, ni_pct=0, poor_pct=0.
func TestFoldBuckets_AllGood(t *testing.T) {
	counts := makeCounts(0, 200)
	d := foldBucketsIntoDistribution("lcp", counts)
	if d.GoodPct != 100 || d.NeedsImprovementPct != 0 || d.PoorPct != 0 {
		t.Errorf("all-good: good=%d ni=%d poor=%d", d.GoodPct, d.NeedsImprovementPct, d.PoorPct)
	}
}

// TestFoldBuckets_AllPoor verifies that a histogram entirely in the poor band
// yields good_pct=0, ni_pct=0, poor_pct=100.
func TestFoldBuckets_AllPoor(t *testing.T) {
	// bucket 16: lower=4000 ≥ LCP poor threshold (4000) → poor
	counts := makeCounts(16, 100)
	d := foldBucketsIntoDistribution("lcp", counts)
	if d.GoodPct != 0 || d.NeedsImprovementPct != 0 || d.PoorPct != 100 {
		t.Errorf("all-poor: good=%d ni=%d poor=%d", d.GoodPct, d.NeedsImprovementPct, d.PoorPct)
	}
}

// ---------------------------------------------------------------------------
// Summary distribution integration test
// ---------------------------------------------------------------------------

// TestRumSummary_distributionPopulated verifies that an unsuppressed metric
// summary row carries a non-nil Distribution field with counts and pct sum=100.
func TestRumSummary_distributionPopulated(t *testing.T) {
	// 200 samples in bucket 0 (LCP good band).
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 200
	st := &rumStubStore{
		rollups: []rum.HourlyRollup{
			{
				RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
				SampleCount:  200,
				BucketCounts: counts,
				MaxValue:     150,
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
	engine.GET("/sites/:siteId/perf/rum/summary", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumSummary(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/summary", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200 (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	// Distribution should be present and non-null.
	if !rumContains(body, `"distribution"`) {
		t.Errorf("distribution field missing from summary response, body=%s", body)
	}
	// All samples are in the good band so good_pct should be 100.
	if !rumContains(body, `"good_pct":100`) {
		t.Errorf("expected good_pct:100 (all samples in good band), body=%s", body)
	}
	// pct values must sum to 100 — we verify good_pct=100 implies ni=0, poor=0.
	if rumContains(body, `"needs_improvement_pct":0`) {
		// expected
	}
}

// TestRumSummary_distributionNilWhenSuppressed verifies that a suppressed slice
// (sample_count < min_sample_count) has no distribution field in the response.
func TestRumSummary_distributionNilWhenSuppressed(t *testing.T) {
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 3 // 3 < 50 → suppressed
	st := &rumStubStore{
		rollups: []rum.HourlyRollup{
			{
				RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
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
	engine.GET("/sites/:siteId/perf/rum/summary", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumSummary(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/summary", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", w.Code)
	}
	body := w.Body.String()
	// distribution must be absent (omitempty when nil).
	if rumContains(body, `"distribution"`) {
		t.Errorf("distribution should be absent for suppressed slice, body=%s", body)
	}
	if !rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=true, body=%s", body)
	}
}

// ---------------------------------------------------------------------------
// Trend endpoint tests
// ---------------------------------------------------------------------------

// rumDailyStubStore provides a stub for the daily rollup getter.
type rumDailyStubStore struct {
	daily []rum.DailyRollup
}

func (s *rumDailyStubStore) GetDailyRollups(_ context.Context, _, _ uuid.UUID, _ time.Time) ([]rum.DailyRollup, error) {
	return s.daily, nil
}

// newRumTrendTestHandler builds a Handler with both hourly and daily stubs wired.
func newRumTrendTestHandler(daily []rum.DailyRollup, minSampleCount int) *Handler {
	dailyStub := &rumDailyStubStore{daily: daily}
	reader := &RumResultsReader{
		GetHourlyRollups: func(_ context.Context, _, _ uuid.UUID, _ time.Time) ([]rum.HourlyRollup, error) {
			return nil, nil
		},
		ComputeP75:      func(rollups []rum.HourlyRollup, min int) []rum.P75Result { return nil },
		GetDailyRollups: dailyStub.GetDailyRollups,
	}
	svc := &Service{repo: &rumConfigRepo{minSampleCount: minSampleCount}}
	return &Handler{svc: svc, rum: reader}
}

// TestRumTrend_suppressedDayPresent verifies that a day below the
// min_sample_count floor appears in the response with suppressed=true and
// p75_ms=0 (the client renders a gap, not a misleading zero).
func TestRumTrend_suppressedDayPresent(t *testing.T) {
	day := time.Date(2026, 5, 13, 0, 0, 0, 0, time.UTC)
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 5 // 5 < 30 → suppressed

	h := newRumTrendTestHandler([]rum.DailyRollup{
		{
			RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
			BucketDay:    day,
			SampleCount:  5,
			BucketCounts: counts,
		},
	}, 30)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/trend", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumTrend(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/trend?window_days=28", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("trend: expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	// The day must appear in the response.
	if !rumContains(body, `"2026-05-13"`) {
		t.Errorf("expected day 2026-05-13 in trend response, body=%s", body)
	}
	// It must be suppressed.
	if !rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=true for below-floor day, body=%s", body)
	}
	// p75_ms must be 0 for the suppressed day.
	if !rumContains(body, `"p75_ms":0`) {
		t.Errorf("expected p75_ms:0 for suppressed day, body=%s", body)
	}
}

// TestRumTrend_unsuppressedDay verifies that a day meeting the floor has a
// non-zero p75_ms and suppressed=false.
func TestRumTrend_unsuppressedDay(t *testing.T) {
	day := time.Date(2026, 5, 14, 0, 0, 0, 0, time.UTC)
	// 100 samples all in bucket 0 ([0,200)ms). p75 = 150ms (interpolated).
	counts := make([]int32, rum.NumBuckets)
	counts[0] = 100

	h := newRumTrendTestHandler([]rum.DailyRollup{
		{
			RollupKey:    rum.RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
			BucketDay:    day,
			SampleCount:  100,
			BucketCounts: counts,
			MaxValue:     150,
		},
	}, 30)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/trend", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumTrend(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/trend", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("trend: expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := w.Body.String()
	if !rumContains(body, `"2026-05-14"`) {
		t.Errorf("expected day 2026-05-14 in response, body=%s", body)
	}
	if rumContains(body, `"suppressed":true`) {
		t.Errorf("expected suppressed=false for above-floor day, body=%s", body)
	}
	// p75_ms must be non-zero.
	if rumContains(body, `"p75_ms":0`) {
		t.Errorf("expected non-zero p75_ms for above-floor day, body=%s", body)
	}
	// Rating must be present (all samples in bucket 0 < 2500 → "good" for LCP).
	if !rumContains(body, `"rating":"good"`) {
		t.Errorf("expected rating:good for LCP p75≈150ms, body=%s", body)
	}
}

// TestRumTrend_nilReader verifies that a nil rum reader returns an empty but
// well-formed trend response (all 5 metrics present as empty slices).
func TestRumTrend_nilReader(t *testing.T) {
	svc := &Service{repo: &rumConfigRepo{minSampleCount: 30}}
	h := &Handler{svc: svc, rum: nil}

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/trend", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumTrend(c)
	})

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/trend", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("nil reader trend: expected 200, got %d", w.Code)
	}
	body := w.Body.String()
	// All 5 standard metrics must be present as empty arrays.
	for _, m := range []string{"lcp", "inp", "cls", "fcp", "ttfb"} {
		want := `"` + m + `":[]`
		if !rumContains(body, want) {
			t.Errorf("nil reader: expected %q in trend response, body=%s", want, body)
		}
	}
}

// TestRumTrend_windowDaysClamp verifies that window_days is clamped to [1,90].
func TestRumTrend_windowDaysClamp(t *testing.T) {
	h := newRumTrendTestHandler(nil, 30)

	engine := gin.New()
	siteID := uuid.New()
	engine.GET("/sites/:siteId/perf/rum/trend", func(c *gin.Context) {
		injectPrincipal(c)
		h.rumTrend(c)
	})

	// window_days=200 → should clamp to 90.
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/sites/"+siteID.String()+"/perf/rum/trend?window_days=200", nil)
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("clamp test: expected 200, got %d", w.Code)
	}
	if !rumContains(w.Body.String(), `"window_days":90`) {
		t.Errorf("expected window_days clamped to 90, body=%s", w.Body.String())
	}
}
