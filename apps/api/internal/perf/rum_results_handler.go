package perf

import (
	"context"
	"net/http"
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

	metrics := make([]RumMetricSummary, 0, len(p75s))
	for _, r := range p75s {
		ms := RumMetricSummary{
			Metric:      r.Metric,
			Device:      r.Device,
			Country:     r.Country,
			P75Ms:       r.P75Milli,
			SampleCount: r.SampleCount,
			Suppressed:  r.P75Milli == 0 && r.SampleCount < int64(minSampleCount),
		}
		if !ms.Suppressed {
			ms.Rating = cwvRating(r.Metric, r.P75Milli)
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
