package objectcache

import (
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

// Handler serves the operator-facing Object Cache routes under
// /api/v1/sites/{siteId}/perf/object-cache/...
// All routes require RequireSiteAccess("siteId") (applied by Register via the
// parent group) and per-route permission gates.
type Handler struct {
	svc   *Service
	audit *audit.Recorder
}

// NewHandler wires the handler.
func NewHandler(svc *Service, rec *audit.Recorder) *Handler {
	return &Handler{svc: svc, audit: rec}
}

// Register mounts the routes on the authenticated /api/v1 group. The caller
// MUST apply RequireSiteAccess("siteId") to the group before calling Register
// (the perf handler already does this for the parent /sites/:siteId group).
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/sites/:siteId", authz.RequireSiteAccess("siteId"))

	// Config CRUD.
	g.GET("/perf/object-cache/config", authz.RequirePermission(authz.PermSiteRead), h.getConfig)
	g.PUT("/perf/object-cache/config", authz.RequirePermission(authz.PermSitePerfConfig), h.putConfig)

	// Lifecycle actions (PermSiteCacheManage = operator+).
	g.POST("/perf/object-cache/test", authz.RequirePermission(authz.PermSiteCacheManage), h.test)
	g.POST("/perf/object-cache/enable", authz.RequirePermission(authz.PermSiteCacheManage), h.enable)
	g.POST("/perf/object-cache/disable", authz.RequirePermission(authz.PermSiteCacheManage), h.disable)

	// Flush: same permission as page-cache purge.
	g.POST("/perf/object-cache/flush", authz.RequirePermission(authz.PermSiteCachePurge), h.flush)

	// Stats history: read-only.
	g.GET("/perf/object-cache/stats-history", authz.RequirePermission(authz.PermSiteRead), h.statsHistory)
}

// ---------------------------------------------------------------------------
// handlers
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

	var body ConfigPutDTO
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}

	// Fetch the current stored config to use as a merge base.
	base, _ := h.svc.GetConfig(c.Request.Context(), p.TenantID, siteID)
	newCfg, passwordRaw := fromConfigPutDTO(body, base)

	saved, err := h.svc.UpdateConfig(c.Request.Context(), p.TenantID, siteID, UpdateConfigInput{
		Config:      newCfg,
		PasswordRaw: passwordRaw,
	})
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		// Agent push failure after a successful store: return 200 with warning.
		c.Header("X-Agent-Push-Warning", err.Error())
		c.JSON(http.StatusOK, toConfigDTO(saved))
		return
	}

	h.record(c, p, audit.ActionObjectCacheConfigUpdated, siteID, map[string]any{
		"has_password":      saved.HasPassword,
		"scheme":            saved.Scheme,
		"analytics_enabled": saved.AnalyticsEnabled,
		"serializer":        saved.Serializer,
		"compression":       saved.Compression,
	})
	c.JSON(http.StatusOK, toConfigDTO(saved))
}

// testBody is the optional PUT body for POST /perf/object-cache/test.
// If password is supplied it overrides the stored password for this test only
// (does not save it -- the operator may be testing before saving).
type testBody struct {
	Password string `json:"password,omitempty"`
}

func (h *Handler) test(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	var body testBody
	// Body is optional.
	if c.Request.ContentLength > 0 {
		if err := bindJSON(c, &body); err != nil {
			httpx.Error(c, err)
			return
		}
	}

	_, result, err := h.svc.Test(c.Request.Context(), p.TenantID, siteID, body.Password)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}

	h.record(c, p, audit.ActionObjectCacheTested, siteID, map[string]any{
		"ok":          result.OK,
		"config_hash": result.ConfigHash,
	})
	c.JSON(http.StatusOK, result)
}

func (h *Handler) enable(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	_, err := h.svc.Enable(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}

	h.record(c, p, audit.ActionObjectCacheEnabled, siteID, nil)
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *Handler) disable(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	_, err := h.svc.Disable(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}

	h.record(c, p, audit.ActionObjectCacheDisabled, siteID, map[string]any{"flushed": true})
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// flushBody is the request body for POST /perf/object-cache/flush.
type flushBody struct {
	Scope string `json:"scope"` // all | site | group
	Group string `json:"group,omitempty"`
}

func (h *Handler) flush(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	var body flushBody
	if c.Request.ContentLength > 0 {
		if err := bindJSON(c, &body); err != nil {
			httpx.Error(c, err)
			return
		}
	}
	if body.Scope == "" {
		body.Scope = "all"
	}

	detail, err := h.svc.Flush(c.Request.Context(), FlushInput{
		SiteID:      siteID,
		TenantID:    p.TenantID,
		Scope:       body.Scope,
		Group:       body.Group,
		InitiatorID: p.GetUserID(),
	})
	if err != nil {
		if _, isDomain := domain.AsDomain(err); isDomain {
			httpx.Error(c, err)
			return
		}
		c.JSON(http.StatusOK, gin.H{"ok": false, "detail": err.Error()})
		return
	}

	h.record(c, p, audit.ActionObjectCacheFlushed, siteID, map[string]any{
		"scope": body.Scope,
		"group": body.Group,
	})
	c.JSON(http.StatusOK, gin.H{"ok": true, "detail": detail})
}

func (h *Handler) statsHistory(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	days := 90
	if s := c.Query("days"); s != "" {
		if n, err := strconv.Atoi(s); err == nil {
			days = n
		}
	}

	resp, err := h.svc.GetStatsHistory(c.Request.Context(), p.TenantID, siteID, days)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, resp)
}

// ---------------------------------------------------------------------------
// helpers (mirrors perf/handler.go helpers)
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
	dec := json.NewDecoder(io.LimitReader(c.Request.Body, 1<<20))
	if err := dec.Decode(dst); err != nil {
		return domain.Validation("invalid_body", "request body is not valid JSON: "+err.Error())
	}
	return nil
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
