package perf

import (
	"context"
	"net/http"
	"sort"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/rum"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// RumResultsReader is the read-seam for the operator RUM results routes.
// rum.StorePostgres satisfies it via GetHourlyRollups + ComputeP75; a thin
// adapter is wired in cmd/wpmgr/main.go via SetRumResultsReader.
type RumResultsReader struct {
	// GetHourlyRollups returns hourly rollup rows for a site since the given time.
	GetHourlyRollups func(ctx context.Context, siteID, tenantID uuid.UUID, since time.Time) ([]rum.HourlyRollup, error)
	// ComputeP75 interpolates the 75th percentile from rollup rows.
	// Rows below minSampleCount have P75Milli == 0 (suppressed).
	ComputeP75 func(rollups []rum.HourlyRollup, minSampleCount int) []rum.P75Result
	// GetDailyRollups returns daily rollup rows for a site since the given date.
	// Used by the trend endpoint (GetRumRollupDaily window read). The trend
	// handler aggregates in-Go via rum.InterpolateP75FromCounts so no separate
	// ComputeP75Daily callback is needed on this struct.
	GetDailyRollups func(ctx context.Context, siteID, tenantID uuid.UUID, since time.Time) ([]rum.DailyRollup, error)
}

// SetRumResultsReader wires the RUM results list reader. When nil the
// /perf/rum and /perf/rum/summary endpoints return empty responses.
func (h *Handler) SetRumResultsReader(r *RumResultsReader) { h.rum = r }

// rumSummary handles GET /api/v1/sites/:siteId/perf/rum/summary.
// Returns site-level Core Web Vitals p75 over the requested window (default
// 28 days, matching CrUX/GSC), with good/needs-improvement/poor ratings and
// the min_sample_count suppression floor enforced server-side.
func (h *Handler) rumSummary(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	// Parse optional window_days query param (default 28, clamped 1–365).
	windowDays := 28
	if s := c.Query("window_days"); s != "" {
		var n int
		if _, err := parseIntParam(s, &n, 1, 365); err == nil {
			windowDays = n
		}
	}

	if h.rum == nil {
		c.JSON(http.StatusOK, RumSummaryDTO{
			WindowDays:     windowDays,
			MinSampleCount: 0,
			Metrics:        []RumMetricSummary{},
		})
		return
	}

	// Fetch the site config to retrieve min_sample_count.
	cfg, cfgErr := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
	if cfgErr != nil {
		httpx.Error(c, cfgErr)
		return
	}
	minSampleCount := cfg.MinSampleCount
	if minSampleCount <= 0 {
		minSampleCount = 30 // default floor: matches column DEFAULT 30 (m57)
	}

	since := time.Now().UTC().AddDate(0, 0, -windowDays)
	rollups, err := h.rum.GetHourlyRollups(c.Request.Context(), siteID, p.TenantID, since)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	p75s := h.rum.ComputeP75(rollups, minSampleCount)

	// Build a (metric, device, country) → summed int64 bucket_counts map so we
	// can compute the distribution for each slice without a second DB round-trip.
	// We reuse the same rollups slice that ComputeP75 already consumed above.
	type sliceKey struct {
		metric  string
		device  string
		country string
	}
	bucketSums := make(map[sliceKey][]int64)
	for _, r := range rollups {
		k := sliceKey{metric: r.Metric, device: r.Device, country: r.Country}
		sums, ok := bucketSums[k]
		if !ok {
			sums = make([]int64, rum.NumBuckets)
			bucketSums[k] = sums
		}
		for i, c := range r.BucketCounts {
			if i < rum.NumBuckets {
				sums[i] += int64(c)
			}
		}
	}

	metrics := make([]RumMetricSummary, 0, len(p75s))
	for _, r := range p75s {
		suppressed := r.P75Milli == 0 && r.SampleCount < int64(minSampleCount)
		ms := RumMetricSummary{
			Metric:      r.Metric,
			Device:      r.Device,
			Country:     r.Country,
			P75Ms:       r.P75Milli,
			SampleCount: r.SampleCount,
			Suppressed:  suppressed,
		}
		if !suppressed {
			ms.Rating = cwvRating(r.Metric, r.P75Milli)
			// Populate the distribution bar from the already-summed bucket counts.
			k := sliceKey{metric: r.Metric, device: r.Device, country: r.Country}
			if sums, ok := bucketSums[k]; ok {
				ms.Distribution = foldBucketsIntoDistribution(r.Metric, sums)
			}
		}
		metrics = append(metrics, ms)
	}
	if metrics == nil {
		metrics = []RumMetricSummary{}
	}

	c.JSON(http.StatusOK, RumSummaryDTO{
		WindowDays:     windowDays,
		MinSampleCount: minSampleCount,
		Metrics:        metrics,
	})
}

// rumResults handles GET /api/v1/sites/:siteId/perf/rum.
// Returns per-URL/metric/device p75 breakdown rows for the dashboard table.
// All slices below min_sample_count are returned with Suppressed=true and
// P75Ms=0 — the dashboard renders "insufficient samples" for those rows.
func (h *Handler) rumResults(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	// Parse optional window_days query param (default 28, clamped 1–365).
	windowDays := 28
	if s := c.Query("window_days"); s != "" {
		var n int
		if _, err := parseIntParam(s, &n, 1, 365); err == nil {
			windowDays = n
		}
	}

	if h.rum == nil {
		c.JSON(http.StatusOK, gin.H{"items": []RumResultDTO{}})
		return
	}

	cfg, cfgErr := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
	if cfgErr != nil {
		httpx.Error(c, cfgErr)
		return
	}
	minSampleCount := cfg.MinSampleCount
	if minSampleCount <= 0 {
		minSampleCount = 100
	}

	since := time.Now().UTC().AddDate(0, 0, -windowDays)
	rollups, err := h.rum.GetHourlyRollups(c.Request.Context(), siteID, p.TenantID, since)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	// Group rollups by (url_pattern, metric, device, country) for per-URL p75.
	// We reuse ComputeP75 which groups by (metric, device, country); to get
	// per-URL rows we group the raw rollups first then call ComputeP75 per group.
	type urlKey struct {
		urlPattern string
		metric     string
		device     string
		country    string
	}
	byURL := make(map[urlKey][]rum.HourlyRollup)
	for _, r := range rollups {
		k := urlKey{
			urlPattern: r.URLPattern,
			metric:     r.Metric,
			device:     r.Device,
			country:    r.Country,
		}
		byURL[k] = append(byURL[k], r)
	}

	items := make([]RumResultDTO, 0, len(byURL))
	for k, rows := range byURL {
		p75s := h.rum.ComputeP75(rows, minSampleCount)
		for _, r := range p75s {
			suppressed := r.P75Milli == 0 && r.SampleCount < int64(minSampleCount)
			item := RumResultDTO{
				URLPattern:  k.urlPattern,
				Metric:      r.Metric,
				Device:      r.Device,
				Country:     r.Country,
				P75Ms:       r.P75Milli,
				SampleCount: r.SampleCount,
				Suppressed:  suppressed,
			}
			if !suppressed {
				item.Rating = cwvRating(r.Metric, r.P75Milli)
			}
			items = append(items, item)
		}
	}
	if items == nil {
		items = []RumResultDTO{}
	}

	c.JSON(http.StatusOK, gin.H{"items": items})
}

// rumTrend handles GET /api/v1/sites/:siteId/perf/rum/trend.
// Returns a per-metric daily p75 trend series over window_days days (default 28,
// clamped to [1,90]). Each metric entry is a slice of RumTrendDayPoint ordered
// ascending by day. Days with zero rollup rows are omitted entirely; days below
// the min_sample_count floor appear with suppressed=true and p75_ms=0 so the
// client can render a gap rather than a misleading zero. CLS p75_ms is in
// milli-units (value*1000); the client divides by 1000 for display.
//
// Auth: same canReadSite gate as rumSummary (RequireSiteAccess + PermSiteRead,
// applied by the route registration in handler.go).
func (h *Handler) rumTrend(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	// Parse optional window_days (default 28, clamped [1,90]).
	windowDays := 28
	if s := c.Query("window_days"); s != "" {
		var n int
		if _, err := parseIntParam(s, &n, 1, 90); err == nil {
			windowDays = n
		}
	}

	if h.rum == nil || h.rum.GetDailyRollups == nil {
		c.JSON(http.StatusOK, emptyTrendResponse(windowDays, 0))
		return
	}

	// Fetch config for min_sample_count.
	cfg, cfgErr := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
	if cfgErr != nil {
		httpx.Error(c, cfgErr)
		return
	}
	minSampleCount := cfg.MinSampleCount
	if minSampleCount <= 0 {
		minSampleCount = 30
	}

	since := time.Now().UTC().AddDate(0, 0, -windowDays)
	dailyRows, err := h.rum.GetDailyRollups(c.Request.Context(), siteID, p.TenantID, since)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	// Optional device filter (e.g. "desktop", "mobile"). When absent, all devices
	// are included and the aggregation collapses device + country into one
	// site-level point per (metric, day).
	deviceFilter := c.Query("device")

	// Aggregate: collapse url_patterns and countries (and optionally devices) into
	// one histogram per (metric, device, day). Working from dailyRows avoids a
	// second DB call and collapses all countries into one site-level point per day.
	type rumTrendKey struct {
		metric string
		device string
		day    time.Time
	}
	type rumTrendAcc struct {
		counts      []int64
		sampleCount int64
		maxVal      int32
	}
	accs := make(map[rumTrendKey]*rumTrendAcc)
	for _, r := range dailyRows {
		if deviceFilter != "" && r.Device != deviceFilter {
			continue
		}
		day := r.BucketDay.UTC().Truncate(24 * time.Hour)
		k := rumTrendKey{metric: r.Metric, device: r.Device, day: day}
		acc, found := accs[k]
		if !found {
			acc = &rumTrendAcc{counts: make([]int64, rum.NumBuckets), maxVal: r.MaxValue}
			accs[k] = acc
		}
		acc.sampleCount += r.SampleCount
		if r.MaxValue > acc.maxVal {
			acc.maxVal = r.MaxValue
		}
		for i, cnt := range r.BucketCounts {
			if i < rum.NumBuckets {
				acc.counts[i] += int64(cnt)
			}
		}
	}

	// Sort keys: (metric ASC, device ASC, day ASC) for deterministic output.
	sortedKeys := make([]rumTrendKey, 0, len(accs))
	for k := range accs {
		sortedKeys = append(sortedKeys, k)
	}
	sort.Slice(sortedKeys, func(i, j int) bool {
		a, b := sortedKeys[i], sortedKeys[j]
		if a.metric != b.metric {
			return a.metric < b.metric
		}
		if a.device != b.device {
			return a.device < b.device
		}
		return a.day.Before(b.day)
	})

	// Build the per-metric point slices.
	metricsMap := make(map[string][]RumTrendDayPoint)
	for _, k := range sortedKeys {
		tacc := accs[k]
		suppressed := tacc.sampleCount < int64(minSampleCount)
		pt := RumTrendDayPoint{
			Day:         k.day.Format("2006-01-02"),
			SampleCount: tacc.sampleCount,
			Suppressed:  suppressed,
		}
		if !suppressed {
			pt.P75Ms = rum.InterpolateP75FromCounts(tacc.counts, tacc.sampleCount, tacc.maxVal)
			pt.Rating = cwvRating(k.metric, pt.P75Ms)
		}
		metricsMap[k.metric] = append(metricsMap[k.metric], pt)
	}

	// Ensure all 5 standard metrics are present (empty slice if no data).
	for _, m := range []string{"lcp", "inp", "cls", "fcp", "ttfb"} {
		if _, exists := metricsMap[m]; !exists {
			metricsMap[m] = []RumTrendDayPoint{}
		}
	}

	c.JSON(http.StatusOK, RumTrendResponse{
		WindowDays:     windowDays,
		MinSampleCount: minSampleCount,
		Metrics:        metricsMap,
	})
}


// emptyTrendResponse returns an empty RumTrendResponse for the nil-reader path.
func emptyTrendResponse(windowDays, minSampleCount int) RumTrendResponse {
	m := make(map[string][]RumTrendDayPoint)
	for _, metric := range []string{"lcp", "inp", "cls", "fcp", "ttfb"} {
		m[metric] = []RumTrendDayPoint{}
	}
	return RumTrendResponse{
		WindowDays:     windowDays,
		MinSampleCount: minSampleCount,
		Metrics:        m,
	}
}

// parseIntParam parses an integer from a string, clamping to [min, max].
// Returns an error if the string is not a valid integer. Sets *out on success.
func parseIntParam(s string, out *int, minV, maxV int) (int, error) {
	var n int
	for _, ch := range s {
		if ch < '0' || ch > '9' {
			return 0, domain.Validation("invalid_param", "not a valid integer")
		}
		n = n*10 + int(ch-'0')
	}
	if n < minV {
		n = minV
	}
	if n > maxV {
		n = maxV
	}
	*out = n
	return n, nil
}
