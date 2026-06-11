// Package portal serves the m66 read-only client portal endpoints.
//
// All routes live under /api/v1/portal and are gated by
// authz.RequireClientPortal() (session user resolved via client_members, no
// write permissions). Per-site sub-routes additionally carry
// authz.RequireSiteAccess("siteId"). No portal route takes a :clientId path
// parameter — client identity is derived from p.ClientIDs (eliminates IDOR).
package portal

import (
	"context"
	"net/http"
	"strconv"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/backup"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/report"
	"github.com/mosamlife/wpmgr/apps/api/internal/rum"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
	"github.com/mosamlife/wpmgr/apps/api/internal/uptime"
)

// SiteService is the subset of site.Service needed by the portal.
type SiteService interface {
	List(ctx context.Context, in site.ListInput) ([]site.Site, error)
}

// UptimeService is the subset of uptime.Service needed by the portal.
type UptimeService interface {
	Uptime(ctx context.Context, tenantID, siteID uuid.UUID, window time.Duration, seriesBuckets int) (uptime.UptimeReport, error)
}

// BackupRepo is the subset of backup.Repo needed by the portal.
type BackupRepo interface {
	ListSnapshotsForSite(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]backup.Snapshot, error)
}

// ReportService is the subset of report.Service needed by the portal.
type ReportService interface {
	PresignReportURLs(ctx context.Context, rpt report.GeneratedReport) (string, string)
}

// RumStore is the subset of rum.Store needed by the portal.
type RumStore interface {
	GetDailyRollups(ctx context.Context, siteID, tenantID uuid.UUID, sinceDay time.Time) ([]rum.DailyRollup, error)
	ComputeP75(rollups []rum.HourlyRollup, minSampleCount int) []rum.P75Result
}

// Handler serves the portal read-only endpoints.
type Handler struct {
	pool        *db.Pool
	sites       SiteService
	uptimeSvc   UptimeService
	backupRepo  BackupRepo
	reportSvc   ReportService
	rumStore    RumStore
}

// NewHandler builds the portal handler.
func NewHandler(pool *db.Pool, sites SiteService, uptimeSvc UptimeService, backupRepo BackupRepo, reportSvc ReportService, rumStore RumStore) *Handler {
	return &Handler{
		pool:       pool,
		sites:      sites,
		uptimeSvc:  uptimeSvc,
		backupRepo: backupRepo,
		reportSvc:  reportSvc,
		rumStore:   rumStore,
	}
}

// Register mounts portal routes on the authenticated v1 group.
// The portal group gate (RequireClientPortal) requires session + auth +
// RequireTenant (from v1) to have already run.
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/portal", authz.RequireClientPortal())
	g.GET("/overview", h.overview)
	g.GET("/sites", h.listSites)
	g.GET("/reports", h.listReports)
	g.GET("/reports/:reportId/download", h.downloadReport)

	perSite := g.Group("/sites/:siteId", authz.RequireSiteAccess("siteId"))
	perSite.GET("/uptime", h.siteUptime)
	perSite.GET("/backups", h.siteBackups)
	perSite.GET("/updates", h.siteUpdates)
	perSite.GET("/vitals", h.siteVitals)
}

// ---------------------------------------------------------------------------
// DTOs
// ---------------------------------------------------------------------------

type overviewClientDTO struct {
	ID      string  `json:"id"`
	Name    string  `json:"name"`
	LogoURL *string `json:"logo_url,omitempty"`
	Color   *string `json:"color,omitempty"`
}

type overviewDTO struct {
	Client      overviewClientDTO `json:"client"`
	AgencyName  string            `json:"agency_name"`
	SiteCount   int               `json:"site_count"`
	ReportCount int               `json:"report_count"`
}

type portalSiteDTO struct {
	ID           string  `json:"id"`
	Name         string  `json:"name"`
	URL          string  `json:"url"`
	Status       string  `json:"status"`
	LastBackupAt *string `json:"last_backup_at,omitempty"`
	TLSExpiresAt *string `json:"tls_expires_at,omitempty"`
}

type portalSiteListDTO struct {
	Items []portalSiteDTO `json:"items"`
}

type incidentDTO struct {
	StartedAt       string `json:"started_at"`
	EndedAt         string `json:"ended_at,omitempty"`
	DurationSeconds int64  `json:"duration_seconds"`
}

type portalUptimeDTO struct {
	Range        string        `json:"range"`
	UptimePct    float64       `json:"uptime_pct"`
	AvgLatencyMs float64       `json:"avg_latency_ms"`
	TLSExpiresAt *string       `json:"tls_expires_at,omitempty"`
	Incidents    []incidentDTO `json:"incidents"`
}

type portalBackupItemDTO struct {
	ID          string  `json:"id"`
	Kind        string  `json:"kind"`
	Status      string  `json:"status"`
	SizeBytes   int64   `json:"size_bytes"`
	CreatedAt   string  `json:"created_at"`
	CompletedAt *string `json:"completed_at,omitempty"`
}

type portalBackupListDTO struct {
	Items []portalBackupItemDTO `json:"items"`
}

type portalUpdateItemDTO struct {
	Type        string  `json:"type"`
	Name        string  `json:"name"`
	FromVersion string  `json:"from_version"`
	ToVersion   string  `json:"to_version"`
	Status      string  `json:"status"`
	FinishedAt  *string `json:"finished_at,omitempty"`
}

type portalUpdateListDTO struct {
	Items []portalUpdateItemDTO `json:"items"`
}

type portalVitalMetricDTO struct {
	Metric  string  `json:"metric"`
	P75     float64 `json:"p75"`
	Rating  string  `json:"rating"`
	Samples int64   `json:"samples"`
}

type portalVitalsDTO struct {
	Range   string                 `json:"range"`
	Metrics []portalVitalMetricDTO `json:"metrics"`
}

type portalReportItemDTO struct {
	ID          string  `json:"id"`
	ClientID    string  `json:"client_id"`
	PeriodStart string  `json:"period_start"`
	PeriodEnd   string  `json:"period_end"`
	CreatedAt   string  `json:"created_at"`
	CompletedAt *string `json:"completed_at,omitempty"`
}

type portalReportListDTO struct {
	Items []portalReportItemDTO `json:"items"`
}

type portalDownloadDTO struct {
	URL       string `json:"url"`
	ExpiresAt string `json:"expires_at"`
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

func (h *Handler) overview(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || len(p.ClientIDs) == 0 {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}

	var brands []sqlc.GetClientBrandsByIDsRow
	var tenantName string
	var reportCount int

	err := h.pool.RunTenantTx(c.Request.Context(), p, func(tx pgx.Tx) error {
		var qerr error
		brands, qerr = sqlc.New(tx).GetClientBrandsByIDs(c.Request.Context(), sqlc.GetClientBrandsByIDsParams{
			Ids:      p.ClientIDs,
			TenantID: p.TenantID,
		})
		if qerr != nil {
			return qerr
		}
		_ = tx.QueryRow(c.Request.Context(),
			"SELECT name FROM tenants WHERE id = $1", p.TenantID,
		).Scan(&tenantName)
		// Report count (cross-client gate: client_id = ANY(p.ClientIDs)).
		_ = tx.QueryRow(c.Request.Context(),
			`SELECT count(*) FROM generated_reports WHERE tenant_id = $1 AND client_id = ANY($2::uuid[]) AND status = 'completed'`,
			p.TenantID, p.ClientIDs,
		).Scan(&reportCount)
		return nil
	})
	if err != nil {
		httpx.Error(c, domain.Internal("portal_overview_failed", "failed to load portal overview").WithCause(err))
		return
	}

	// Use the earliest-created client for primary branding (ordered ASC in query).
	var primary sqlc.GetClientBrandsByIDsRow
	if len(brands) > 0 {
		primary = brands[0]
	}

	// Site count from p.AllowedSiteIDs (already scoped to the client's sites).
	dto := overviewDTO{
		Client: overviewClientDTO{
			ID:      primary.ID.String(),
			Name:    primary.Name,
			LogoURL: primary.LogoUrl,
			Color:   primary.Color,
		},
		AgencyName:  tenantName,
		SiteCount:   len(p.AllowedSiteIDs),
		ReportCount: reportCount,
	}
	c.JSON(http.StatusOK, dto)
}

func (h *Handler) listSites(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}

	// Pass the principal so repo.List uses RunTenantTx and the InScopedTenantTx
	// path restricts rows via app.allowed_site_ids (the RESTRICTIVE site_scope
	// policy). Without Principal the repo falls back to InTenantTx, which would
	// return ALL tenant sites instead of just the client's sites.
	sites, err := h.sites.List(c.Request.Context(), site.ListInput{
		TenantID:  p.TenantID,
		Limit:     200,
		Offset:    0,
		Principal: p,
	})
	if err != nil {
		httpx.Error(c, domain.Internal("portal_sites_failed", "failed to list sites").WithCause(err))
		return
	}

	items := make([]portalSiteDTO, 0, len(sites))
	for _, s := range sites {
		d := portalSiteDTO{
			ID:     s.ID.String(),
			Name:   s.Name,
			URL:    s.URL,
			Status: string(s.ConnectionState),
		}
		if s.LastBackupAt != nil {
			v := s.LastBackupAt.UTC().Format(time.RFC3339)
			d.LastBackupAt = &v
		}
		items = append(items, d)
	}
	c.JSON(http.StatusOK, portalSiteListDTO{Items: items})
}

func (h *Handler) siteUptime(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return
	}

	rangeParam := c.DefaultQuery("range", "30d")
	window := parseUptimeWindow(rangeParam)

	if h.uptimeSvc == nil {
		c.JSON(http.StatusOK, portalUptimeDTO{Range: rangeParam, Incidents: []incidentDTO{}})
		return
	}

	rpt, err := h.uptimeSvc.Uptime(c.Request.Context(), p.TenantID, siteID, window, 0)
	if err != nil {
		if e, ok2 := domain.AsDomain(err); ok2 && e.Kind == domain.KindNotFound {
			httpx.Error(c, domain.NotFound("site_not_found", "site not found"))
			return
		}
		httpx.Error(c, domain.Internal("portal_uptime_failed", "failed to load uptime").WithCause(err))
		return
	}

	dto := portalUptimeDTO{
		Range:     rangeParam,
		UptimePct: rpt.UptimePct,
		AvgLatencyMs: rpt.AvgLatencyMs,
		Incidents: []incidentDTO{},
	}
	if rpt.TLSExpiry != nil {
		v := rpt.TLSExpiry.UTC().Format(time.RFC3339)
		dto.TLSExpiresAt = &v
	}
	c.JSON(http.StatusOK, dto)
}

func (h *Handler) siteBackups(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return
	}

	limitStr := c.DefaultQuery("limit", "20")
	limit, _ := strconv.Atoi(limitStr)
	if limit <= 0 || limit > 20 {
		limit = 20
	}

	if h.backupRepo == nil {
		c.JSON(http.StatusOK, portalBackupListDTO{Items: []portalBackupItemDTO{}})
		return
	}

	snaps, err := h.backupRepo.ListSnapshotsForSite(c.Request.Context(), p.TenantID, siteID, int32(limit), 0)
	if err != nil {
		httpx.Error(c, domain.Internal("portal_backups_failed", "failed to list backups").WithCause(err))
		return
	}

	items := make([]portalBackupItemDTO, 0, len(snaps))
	for _, s := range snaps {
		// Status filter: completed only per spec. The repo returns all statuses;
		// filter here so the limit is consistent.
		if s.Status != backup.StatusCompleted {
			continue
		}
		d := portalBackupItemDTO{
			ID:        s.ID.String(),
			Kind:      s.Kind,
			Status:    s.Status,
			SizeBytes: s.TotalSize,
			CreatedAt: s.CreatedAt.UTC().Format(time.RFC3339),
		}
		if s.FinishedAt != nil {
			v := s.FinishedAt.UTC().Format(time.RFC3339)
			d.CompletedAt = &v
		}
		items = append(items, d)
	}
	c.JSON(http.StatusOK, portalBackupListDTO{Items: items})
}

func (h *Handler) siteUpdates(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return
	}

	limitStr := c.DefaultQuery("limit", "50")
	limit, _ := strconv.Atoi(limitStr)
	if limit <= 0 || limit > 50 {
		limit = 50
	}

	var tasks []sqlc.ListAppliedTasksForSiteRow
	err = h.pool.RunTenantTx(c.Request.Context(), p, func(tx pgx.Tx) error {
		var qerr error
		tasks, qerr = sqlc.New(tx).ListAppliedTasksForSite(c.Request.Context(), sqlc.ListAppliedTasksForSiteParams{
			SiteID:   siteID,
			TenantID: p.TenantID,
			RowLimit: int32(limit),
		})
		return qerr
	})
	if err != nil {
		httpx.Error(c, domain.Internal("portal_updates_failed", "failed to list updates").WithCause(err))
		return
	}

	items := make([]portalUpdateItemDTO, 0, len(tasks))
	for _, t := range tasks {
		d := portalUpdateItemDTO{
			Type:        t.TargetType,
			Name:        t.TargetSlug,
			FromVersion: t.FromVersion,
			ToVersion:   t.ToVersion,
			Status:      t.Status,
		}
		if t.FinishedAt.Valid {
			v := t.FinishedAt.Time.UTC().Format(time.RFC3339)
			d.FinishedAt = &v
		}
		items = append(items, d)
	}
	c.JSON(http.StatusOK, portalUpdateListDTO{Items: items})
}

func (h *Handler) siteVitals(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return
	}

	rangeParam := c.DefaultQuery("range", "28d")

	if h.rumStore == nil {
		c.JSON(http.StatusOK, portalVitalsDTO{Range: rangeParam, Metrics: []portalVitalMetricDTO{}})
		return
	}

	sinceDay := time.Now().UTC().AddDate(0, 0, -28)
	rollups, err := h.rumStore.GetDailyRollups(c.Request.Context(), siteID, p.TenantID, sinceDay)
	if err != nil {
		httpx.Error(c, domain.Internal("portal_vitals_failed", "failed to load vitals").WithCause(err))
		return
	}

	// Convert daily rollups to HourlyRollup shape for ComputeP75 (compatible
	// subset; bucket_counts and sample_count are the only fields used).
	hourlyShape := make([]rum.HourlyRollup, 0, len(rollups))
	for _, dr := range rollups {
		hourlyShape = append(hourlyShape, rum.HourlyRollup{
			RollupKey:    dr.RollupKey,
			SampleCount:  dr.SampleCount,
			BucketCounts: dr.BucketCounts,
		})
	}

	// Filter to device="" (all-devices aggregate, the 0.33.5 convention) for
	// the portal. A blank device row is the cross-device aggregate.
	allDevices := make([]rum.HourlyRollup, 0, len(hourlyShape))
	for _, r := range hourlyShape {
		if r.Device == "" {
			allDevices = append(allDevices, r)
		}
	}

	results := h.rumStore.ComputeP75(allDevices, 50)
	metrics := make([]portalVitalMetricDTO, 0, len(results))
	for _, r := range results {
		if r.Metric != "lcp" && r.Metric != "inp" && r.Metric != "cls" {
			continue
		}
		if r.P75Milli == 0 {
			continue // suppressed (below min sample count)
		}
		metrics = append(metrics, portalVitalMetricDTO{
			Metric:  r.Metric,
			P75:     r.P75Milli,
			Rating:  vitalRating(r.Metric, r.P75Milli),
			Samples: r.SampleCount,
		})
	}
	c.JSON(http.StatusOK, portalVitalsDTO{Range: rangeParam, Metrics: metrics})
}

func (h *Handler) listReports(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || len(p.ClientIDs) == 0 {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}

	var rows []sqlc.ListCompletedReportsForClientsRow
	err := h.pool.RunTenantTx(c.Request.Context(), p, func(tx pgx.Tx) error {
		var qerr error
		rows, qerr = sqlc.New(tx).ListCompletedReportsForClients(c.Request.Context(), sqlc.ListCompletedReportsForClientsParams{
			TenantID:        p.TenantID,
			ClientIds:       p.ClientIDs,
			CursorCreatedAt: pgtype.Timestamptz{Valid: false},
			CursorID:        uuid.Nil,
			RowLimit:        50,
		})
		return qerr
	})
	if err != nil {
		httpx.Error(c, domain.Internal("portal_reports_failed", "failed to list reports").WithCause(err))
		return
	}

	items := make([]portalReportItemDTO, 0, len(rows))
	for _, r := range rows {
		d := portalReportItemDTO{
			ID:          r.ID.String(),
			ClientID:    r.ClientID.String(),
			PeriodStart: r.PeriodStart.UTC().Format("2006-01-02"),
			PeriodEnd:   r.PeriodEnd.UTC().Format("2006-01-02"),
			CreatedAt:   r.CreatedAt.UTC().Format(time.RFC3339),
		}
		if r.CompletedAt.Valid {
			v := r.CompletedAt.Time.UTC().Format(time.RFC3339)
			d.CompletedAt = &v
		}
		items = append(items, d)
	}
	c.JSON(http.StatusOK, portalReportListDTO{Items: items})
}

func (h *Handler) downloadReport(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || len(p.ClientIDs) == 0 {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	reportID, err := uuid.Parse(c.Param("reportId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_report_id", "reportId is not a valid UUID"))
		return
	}

	formatParam := c.DefaultQuery("format", "html")

	var row sqlc.GetCompletedReportForPortalRow
	err = h.pool.RunTenantTx(c.Request.Context(), p, func(tx pgx.Tx) error {
		var qerr error
		row, qerr = sqlc.New(tx).GetCompletedReportForPortal(c.Request.Context(), sqlc.GetCompletedReportForPortalParams{
			ID:        reportID,
			TenantID:  p.TenantID,
			ClientIds: p.ClientIDs,
		})
		return qerr
	})
	if err != nil {
		if err == pgx.ErrNoRows {
			// Opaque 404: does not reveal whether report exists for another client.
			httpx.Error(c, domain.NotFound("report_not_found", "report not found"))
			return
		}
		httpx.Error(c, domain.Internal("portal_report_download_failed", "failed to load report").WithCause(err))
		return
	}

	if h.reportSvc == nil {
		httpx.Error(c, domain.Unavailable("report_download_unavailable", "report download is not configured"))
		return
	}

	// Construct a domain GeneratedReport from the portal query row to reuse
	// the existing PresignReportURLs machinery.
	rpt := report.GeneratedReport{
		ID:          row.ID,
		TenantID:    row.TenantID,
		ClientID:    row.ClientID,
		PeriodStart: row.PeriodStart,
		PeriodEnd:   row.PeriodEnd,
		Status:      row.Status,
		HTMLBlobKey: row.HtmlBlobKey,
		PDFBlobKey:  row.PdfBlobKey,
	}
	if row.CompletedAt.Valid {
		t := row.CompletedAt.Time
		rpt.CompletedAt = &t
	}

	htmlURL, pdfURL := h.reportSvc.PresignReportURLs(c.Request.Context(), rpt)
	var url string
	switch formatParam {
	case "pdf":
		url = pdfURL
	default:
		url = htmlURL
	}
	if url == "" {
		httpx.Error(c, domain.Unavailable("report_download_unavailable", "report download URL could not be generated"))
		return
	}

	// Presigned URL expiry: mirrors report.presignTTL (15 minutes).
	expiresAt := time.Now().UTC().Add(15 * time.Minute)
	c.JSON(http.StatusOK, portalDownloadDTO{
		URL:       url,
		ExpiresAt: expiresAt.Format(time.RFC3339),
	})
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// parseUptimeWindow converts the range query param to a duration.
func parseUptimeWindow(r string) time.Duration {
	switch r {
	case "24h":
		return 24 * time.Hour
	case "7d":
		return 7 * 24 * time.Hour
	case "90d":
		return 90 * 24 * time.Hour
	default: // "30d"
		return 30 * 24 * time.Hour
	}
}

// vitalRating returns the CrUX-grade for a metric value (good/needs-improvement/poor).
func vitalRating(metric string, p75 float64) string {
	switch metric {
	case "lcp":
		if p75 < 2500 {
			return "good"
		} else if p75 < 4000 {
			return "needs-improvement"
		}
		return "poor"
	case "inp":
		if p75 < 200 {
			return "good"
		} else if p75 < 500 {
			return "needs-improvement"
		}
		return "poor"
	case "cls":
		// CLS values are in milli-units (thousandths of a unit).
		if p75 < 100 {
			return "good"
		} else if p75 < 250 {
			return "needs-improvement"
		}
		return "poor"
	}
	return "unknown"
}
