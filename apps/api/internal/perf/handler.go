package perf

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// Handler serves the operator-facing Performance Suite routes under
// /api/v1/sites/{siteId}/perf|cache|db|rucss/... plus the portfolio bulk routes
// under /api/v1/cache/*.
type Handler struct {
	svc   *Service
	rucss *RucssResultsReader
	audit *audit.Recorder
}

// RucssResultsReader is the subset of the rucss repo the operator results route
// needs. *rucss/repo.Repo satisfies it via ListForSite; a thin adapter maps the
// rucss model.Result to the DTO. Declared here as func seams so the handler does
// not import the rucss model directly.
type RucssResultsReader struct {
	List  func(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]RucssResultDTO, error)
	Clear func(ctx context.Context, tenantID, siteID uuid.UUID) (int, error)
}

// NewHandler builds the operator handler. rucss may be nil (RUCSS results route
// degrades to an empty list).
func NewHandler(svc *Service, rucss *RucssResultsReader, rec *audit.Recorder) *Handler {
	return &Handler{svc: svc, rucss: rucss, audit: rec}
}

// Register mounts the routes on the authenticated /api/v1 group. RequireSiteAccess
// is applied on the per-site group so every sub-route inherits the collaborator
// allowlist gate (belt-and-braces in front of the m36 RLS).
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/sites/:siteId", authz.RequireSiteAccess("siteId"))

	g.GET("/perf/config", authz.RequirePermission(authz.PermSitePerfConfig), h.getConfig)
	g.PUT("/perf/config", authz.RequirePermission(authz.PermSitePerfConfig), h.putConfig)

	// All per-site perf routes live under the /perf/ namespace (the dashboard
	// builds every URL as /sites/{id}/perf + suffix).
	g.GET("/perf/cache/stats", authz.RequirePermission(authz.PermSiteRead), h.getCacheStats)
	g.POST("/perf/cache/purge", authz.RequirePermission(authz.PermSiteCachePurge), h.purge)
	g.POST("/perf/cache/preload", authz.RequirePermission(authz.PermSiteCachePurge), h.preload)
	g.POST("/perf/cache/enable", authz.RequirePermission(authz.PermSiteCacheManage), h.enable)
	g.POST("/perf/cache/disable", authz.RequirePermission(authz.PermSiteCacheManage), h.disable)

	g.POST("/perf/db/clean", authz.RequirePermission(authz.PermSiteCacheManage), h.dbClean)

	g.GET("/perf/rucss/results", authz.RequirePermission(authz.PermSiteRead), h.rucssResults)
	g.POST("/perf/rucss/clear", authz.RequirePermission(authz.PermSitePerfConfig), h.rucssClear)
	g.POST("/perf/rucss/compute", authz.RequirePermission(authz.PermSitePerfConfig), h.rucssCompute)

	// Portfolio bulk routes. RequireSiteAccess is enforced PER-SITE inside the
	// handlers (each site_id is checked against the principal's allowlist) since
	// the route param is a body array, not a path :siteId.
	r.POST("/cache/bulk-purge", authz.RequirePermission(authz.PermSiteCachePurge), h.bulkPurge)
	r.PUT("/cache/bulk-config", authz.RequirePermission(authz.PermSitePerfConfig), h.bulkConfig)
}

// ---------------------------------------------------------------------------
// per-site config
// ---------------------------------------------------------------------------

func (h *Handler) getConfig(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	cfg, err := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, toConfigDTO(cfg))
}

func (h *Handler) putConfig(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	var body perfConfigDTO
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}
	cfg := fromConfigDTO(body, p.TenantID, siteID)
	in := UpdateConfigInput{Config: cfg}
	if body.CDNCredentials != nil {
		in.CDNCredentialsRaw = &CDNCredentials{
			Provider: body.CDNProvider,
			APIToken: body.CDNCredentials.APIToken,
			ZoneID:   body.CDNCredentials.ZoneID,
			Zone:     body.CDNCredentials.Zone,
		}
	}
	saved, err := h.svc.UpdateConfig(c.Request.Context(), p.TenantID, siteID, in)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		// Non-domain = agent push failure after a successful store. Return 200 with
		// the stored config; surface the warning in a header (mirrors security).
		c.Header("X-Agent-Push-Warning", err.Error())
		c.JSON(http.StatusOK, toConfigDTO(saved))
		return
	}
	h.record(c, p, audit.ActionPerfConfigUpdated, siteID, map[string]any{
		"config_version": saved.ConfigVersion,
		"cache_enabled":  saved.CacheEnabled,
	})
	c.JSON(http.StatusOK, toConfigDTO(saved))
}

// ---------------------------------------------------------------------------
// cache stats / purge / preload / enable / disable
// ---------------------------------------------------------------------------

func (h *Handler) getCacheStats(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	stats, err := h.svc.GetCacheStats(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, toCacheStatsDTO(stats))
}

func (h *Handler) purge(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	var body purgeBody
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}
	scope := PurgeKind(body.Scope)
	deleteEverything := body.Scope == "all" && body.DeleteEverything

	// The destructive "delete everything" flavour requires the higher admin-gated
	// permission. We re-check it here rather than on the route because the route
	// allows the normal purge permission.
	if deleteEverything {
		if !h.allows(c, authz.PermSiteCacheDeleteAll) {
			httpx.Error(c, domain.Forbidden("insufficient_permission", "delete-everything requires the cache delete-all permission"))
			return
		}
	}

	in := PurgeInput{
		Scope:            scope,
		URLs:             body.URLs,
		InitiatorID:      p.GetUserID(),
		DeleteEverything: deleteEverything,
	}
	if body.URL != "" {
		in.URLs = append(in.URLs, body.URL)
	}
	entry, detail, err := h.svc.Purge(c.Request.Context(), p.TenantID, siteID, in)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		// Agent rejection: surface as 200 ok=false (mirrors security.unblockIP).
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	action := audit.ActionCachePurged
	if deleteEverything {
		action = audit.ActionCacheDeleteEverything
	}
	h.record(c, p, action, siteID, map[string]any{
		"kind":       string(scope),
		"urls_count": entry.URLsCount,
	})
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail, "purge_id": entry.ID.String()})
}

func (h *Handler) preload(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	detail, err := h.svc.Preload(c.Request.Context(), p.TenantID, siteID, p.GetUserID())
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail})
}

func (h *Handler) enable(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	detail, err := h.svc.EnableCache(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	h.record(c, p, audit.ActionCacheEnabled, siteID, nil)
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail})
}

func (h *Handler) disable(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	detail, err := h.svc.DisableCache(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	h.record(c, p, audit.ActionCacheDisabled, siteID, nil)
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail})
}

func (h *Handler) dbClean(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	detail, rows, err := h.svc.DBClean(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	h.record(c, p, audit.ActionDbCleaned, siteID, map[string]any{"rows_cleaned": rows})
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail, "rows_cleaned": rows})
}

// ---------------------------------------------------------------------------
// rucss results
// ---------------------------------------------------------------------------

func (h *Handler) rucssResults(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	limit, offset := pageParams(c)
	if h.rucss == nil {
		c.JSON(http.StatusOK, gin.H{"items": []RucssResultDTO{}})
		return
	}
	items, err := h.rucss.List(c.Request.Context(), p.TenantID, siteID, limit, offset)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if items == nil {
		items = []RucssResultDTO{}
	}
	c.JSON(http.StatusOK, gin.H{"items": items})
}

func (h *Handler) rucssClear(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	if h.rucss == nil || h.rucss.Clear == nil {
		c.JSON(http.StatusOK, gin.H{"ok": true, "cleared": 0})
		return
	}
	cleared, err := h.rucss.Clear(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true, "cleared": cleared})
}

// rucssComputeBody is the optional request body for POST /perf/rucss/compute. An
// empty body computes the home page.
type rucssComputeBody struct {
	URLs []string `json:"urls,omitempty"`
}

func (h *Handler) rucssCompute(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	var body rucssComputeBody
	if c.Request.ContentLength > 0 {
		if err := bindJSON(c, &body); err != nil {
			httpx.Error(c, err)
			return
		}
	}
	detail, err := h.svc.ComputeRucss(c.Request.Context(), p.TenantID, siteID, body.URLs)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}
	h.record(c, p, audit.ActionPerfConfigUpdated, siteID, map[string]any{"rucss_compute": true})
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail})
}

// ---------------------------------------------------------------------------
// portfolio bulk
// ---------------------------------------------------------------------------

func (h *Handler) bulkPurge(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	var body bulkPurgeBody
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}
	results := make([]bulkResultDTO, 0, len(body.SiteIDs))
	for _, raw := range body.SiteIDs {
		siteID, perr := uuid.Parse(raw)
		if perr != nil {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: "invalid site id"})
			continue
		}
		if !p.CanAccessSite(siteID) {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: "forbidden"})
			continue
		}
		_, detail, err := h.svc.Purge(c.Request.Context(), p.TenantID, siteID, PurgeInput{
			Scope:       PurgeKindAll,
			InitiatorID: p.GetUserID(),
		})
		if err != nil {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: err.Error()})
			continue
		}
		h.record(c, p, audit.ActionCachePurged, siteID, map[string]any{"kind": "all", "bulk": true})
		results = append(results, bulkResultDTO{SiteID: raw, OK: true, Detail: detail})
	}
	c.JSON(http.StatusOK, gin.H{"results": results})
}

func (h *Handler) bulkConfig(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	var body bulkConfigBody
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}
	preset, ok := presetConfig(body.Preset)
	if !ok {
		httpx.Error(c, domain.Validation("invalid_preset", "preset must be one of: balanced, aggressive, safe"))
		return
	}
	results := make([]bulkResultDTO, 0, len(body.SiteIDs))
	for _, raw := range body.SiteIDs {
		siteID, perr := uuid.Parse(raw)
		if perr != nil {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: "invalid site id"})
			continue
		}
		if !p.CanAccessSite(siteID) {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: "forbidden"})
			continue
		}
		// Merge the preset toggles onto the site's existing config so the bulk
		// apply does not clobber per-site CDN/cache include lists.
		cur, gerr := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
		if gerr != nil {
			results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: gerr.Error()})
			continue
		}
		applyPreset(&cur, preset)
		saved, err := h.svc.UpdateConfig(c.Request.Context(), p.TenantID, siteID, UpdateConfigInput{Config: cur})
		if err != nil {
			if _, isDomain := domain.AsDomain(err); isDomain {
				results = append(results, bulkResultDTO{SiteID: raw, OK: false, Detail: err.Error()})
				continue
			}
			// Agent push warning is non-fatal: config is stored.
			h.record(c, p, audit.ActionPerfConfigUpdated, siteID, map[string]any{"preset": body.Preset, "bulk": true})
			results = append(results, bulkResultDTO{SiteID: raw, OK: true, Detail: "stored; agent push warning: " + err.Error()})
			continue
		}
		h.record(c, p, audit.ActionPerfConfigUpdated, siteID, map[string]any{"preset": body.Preset, "bulk": true})
		results = append(results, bulkResultDTO{SiteID: raw, OK: true, Detail: "applied", ConfigVersion: saved.ConfigVersion})
	}
	c.JSON(http.StatusOK, gin.H{"results": results})
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func parseSiteID(c *gin.Context) (uuid.UUID, bool) {
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return uuid.Nil, false
	}
	return siteID, true
}

func bindJSON(c *gin.Context, dst any) error {
	dec := json.NewDecoder(io.LimitReader(c.Request.Body, 1<<20)) // 1 MiB config/body cap
	if err := dec.Decode(dst); err != nil {
		return domain.Validation("invalid_body", "request body is not valid JSON: "+err.Error())
	}
	return nil
}

func pageParams(c *gin.Context) (limit, offset int32) {
	limit = 50
	if s := c.Query("limit"); s != "" {
		if n, err := strconv.Atoi(s); err == nil && n > 0 && n <= 500 {
			limit = int32(n)
		}
	}
	if s := c.Query("offset"); s != "" {
		if n, err := strconv.Atoi(s); err == nil && n >= 0 {
			offset = int32(n)
		}
	}
	return limit, offset
}

func (h *Handler) allows(c *gin.Context, perm authz.Permission) bool {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		return false
	}
	return authz.Allows(authz.Role(p.Role), perm)
}

func (h *Handler) record(c *gin.Context, p domain.Principal, action string, siteID uuid.UUID, meta map[string]any) {
	if h.audit == nil {
		return
	}
	actorType := audit.ActorUser
	if p.Type == domain.PrincipalAPIKey {
		actorType = audit.ActorAPIKey
	}
	_, _ = h.audit.Record(c.Request.Context(), audit.Event{
		TenantID:   p.TenantID,
		ActorType:  actorType,
		ActorID:    p.ActorID(),
		Action:     action,
		TargetType: "site",
		TargetID:   siteID.String(),
		Metadata:   meta,
	})
}
