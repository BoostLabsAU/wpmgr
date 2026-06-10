package client

import (
	"net/http"
	"net/url"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/api/gen"
	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// Handler serves the operator-facing client management routes:
//
//	GET    /api/v1/clients                   — list clients (PermClientRead)
//	POST   /api/v1/clients                   — create client (PermClientManage)
//	PUT    /api/v1/clients/assignments        — bulk assign sites (PermClientManage)
//	GET    /api/v1/clients/:clientId          — get client (PermClientRead)
//	PATCH  /api/v1/clients/:clientId          — update client (PermClientManage)
//	DELETE /api/v1/clients/:clientId          — delete client (PermClientManage)
//
// All routes require RequireOrgScope (clients are org-level; site-scoped
// collaborators must never enumerate or mutate the client roster).
type Handler struct {
	svc   *Service
	audit *audit.Recorder
}

// NewHandler constructs the client handler.
func NewHandler(svc *Service, rec *audit.Recorder) *Handler {
	return &Handler{svc: svc, audit: rec}
}

// Register mounts all client routes on the authenticated /api/v1 group.
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/clients", authz.RequireOrgScope())
	g.GET("", authz.RequirePermission(authz.PermClientRead), h.list)
	g.POST("", authz.RequirePermission(authz.PermClientManage), h.create)
	// /assignments must be declared before /:clientId to avoid routing conflicts.
	g.PUT("/assignments", authz.RequirePermission(authz.PermClientManage), h.assignSites)
	g.GET("/:clientId", authz.RequirePermission(authz.PermClientRead), h.get)
	g.PATCH("/:clientId", authz.RequirePermission(authz.PermClientManage), h.update)
	g.DELETE("/:clientId", authz.RequirePermission(authz.PermClientManage), h.delete)
}

// ---------------------------------------------------------------------------
// Request/response DTOs
// ---------------------------------------------------------------------------

type createClientBody struct {
	Name         string  `json:"name"`
	ContactEmail *string `json:"contact_email"`
	Company      *string `json:"company"`
	Phone        *string `json:"phone"`
	Notes        *string `json:"notes"`
	Color        *string `json:"color"`
	LogoURL      *string `json:"logo_url"`
	// Timezone is an IANA timezone name (e.g. "America/New_York"). Defaults to
	// "UTC" when absent.
	Timezone *string `json:"timezone"`
}

type updateClientBody struct {
	Name         *string `json:"name"`
	ContactEmail *string `json:"contact_email"`
	Company      *string `json:"company"`
	Phone        *string `json:"phone"`
	Notes        *string `json:"notes"`
	Color        *string `json:"color"`
	LogoURL      *string `json:"logo_url"`
	// Timezone, when present, updates the client's IANA timezone.
	Timezone *string `json:"timezone"`
}

type assignSitesBody struct {
	ClientID *string  `json:"client_id"`
	SiteIDs  []string `json:"site_ids"`
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

func (h *Handler) list(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	includeArchived := c.Query("include_archived") == "true"
	clients, err := h.svc.List(c.Request.Context(), p.TenantID, includeArchived)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	items := make([]gen.AgencyClient, 0, len(clients))
	for _, cl := range clients {
		items = append(items, toAPI(cl))
	}
	c.JSON(http.StatusOK, gen.AgencyClientList{Items: items})
}

func (h *Handler) create(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	var body createClientBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	cl, err := h.svc.Create(c.Request.Context(), CreateInput{
		TenantID:     p.TenantID,
		Name:         body.Name,
		ContactEmail: body.ContactEmail,
		Company:      body.Company,
		Phone:        body.Phone,
		Notes:        body.Notes,
		Color:        body.Color,
		LogoURL:      body.LogoURL,
		Timezone:     body.Timezone,
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}
	h.record(c, p.TenantID, audit.ActionClientCreated, cl.ID.String(),
		map[string]any{"name": cl.Name})
	c.JSON(http.StatusCreated, toAPI(cl))
}

func (h *Handler) get(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	cl, err := h.svc.Get(c.Request.Context(), p.TenantID, clientID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, toAPI(cl))
}

func (h *Handler) update(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	var body updateClientBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	cl, err := h.svc.Update(c.Request.Context(), UpdateInput{
		TenantID:     p.TenantID,
		ID:           clientID,
		Name:         body.Name,
		ContactEmail: body.ContactEmail,
		Company:      body.Company,
		Phone:        body.Phone,
		Notes:        body.Notes,
		Color:        body.Color,
		LogoURL:      body.LogoURL,
		Timezone:     body.Timezone,
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}
	h.record(c, p.TenantID, audit.ActionClientUpdated, cl.ID.String(),
		map[string]any{"name": cl.Name})
	c.JSON(http.StatusOK, toAPI(cl))
}

func (h *Handler) delete(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	clientID, err := uuid.Parse(c.Param("clientId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_client_id", "clientId is not a valid UUID"))
		return
	}
	if err := h.svc.Delete(c.Request.Context(), p.TenantID, clientID); err != nil {
		httpx.Error(c, err)
		return
	}
	h.record(c, p.TenantID, audit.ActionClientDeleted, clientID.String(), nil)
	c.Status(http.StatusNoContent)
}

func (h *Handler) assignSites(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	var body assignSitesBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if len(body.SiteIDs) == 0 {
		httpx.Error(c, domain.Validation("site_ids_required", "site_ids must not be empty"))
		return
	}

	siteIDs := make([]uuid.UUID, 0, len(body.SiteIDs))
	for _, raw := range body.SiteIDs {
		id, err := uuid.Parse(raw)
		if err != nil {
			httpx.Error(c, domain.Validation("invalid_site_id", "site_ids contains an invalid UUID: "+raw))
			return
		}
		siteIDs = append(siteIDs, id)
	}

	var clientID *uuid.UUID
	if body.ClientID != nil && *body.ClientID != "" {
		id, err := uuid.Parse(*body.ClientID)
		if err != nil {
			httpx.Error(c, domain.Validation("invalid_client_id", "client_id is not a valid UUID"))
			return
		}
		clientID = &id
	}

	result, err := h.svc.AssignSites(c.Request.Context(), AssignInput{
		TenantID: p.TenantID,
		ClientID: clientID,
		SiteIDs:  siteIDs,
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}
	h.record(c, p.TenantID, audit.ActionClientSitesAssigned, "",
		map[string]any{"site_count": result.Updated, "client_id": body.ClientID})
	c.JSON(http.StatusOK, gen.AssignSitesResponse{Updated: result.Updated})
}

// ---------------------------------------------------------------------------
// DTO mapping
// ---------------------------------------------------------------------------

func toAPI(c Client) gen.AgencyClient {
	out := gen.AgencyClient{
		ID:        c.ID,
		TenantID:  c.TenantID,
		Name:      c.Name,
		SiteCount: c.SiteCount,
		Timezone:  c.Timezone,
		CreatedAt: c.CreatedAt,
		UpdatedAt: c.UpdatedAt,
	}
	if c.ContactEmail != nil {
		out.ContactEmail = gen.NewOptString(*c.ContactEmail)
	}
	if c.Company != nil {
		out.Company = gen.NewOptString(*c.Company)
	}
	if c.Phone != nil {
		out.Phone = gen.NewOptString(*c.Phone)
	}
	if c.Notes != nil {
		out.Notes = gen.NewOptString(*c.Notes)
	}
	if c.Color != nil {
		out.Color = gen.NewOptString(*c.Color)
	}
	if c.LogoURL != nil {
		// logo_url is typed as OptURI in gen — parse it; skip if unparseable.
		if u, err := parseURI(*c.LogoURL); err == nil {
			out.LogoURL = gen.NewOptURI(u)
		}
	}
	if c.ArchivedAt != nil {
		out.ArchivedAt = gen.NewOptDateTime(*c.ArchivedAt)
	}
	return out
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func parseURI(raw string) (url.URL, error) {
	u, err := url.Parse(raw)
	if err != nil || u == nil {
		return url.URL{}, err
	}
	return *u, nil
}

func (h *Handler) record(c *gin.Context, tenantID uuid.UUID, action, targetID string, meta map[string]any) {
	if h.audit == nil {
		return
	}
	actorType := audit.ActorSystem
	actorID := ""
	if p, ok := domain.PrincipalFromContext(c.Request.Context()); ok {
		actorType = audit.ActorUser
		if p.Type == domain.PrincipalAPIKey {
			actorType = audit.ActorAPIKey
		}
		actorID = p.ActorID()
	}
	_, _ = h.audit.Record(c.Request.Context(), audit.Event{
		TenantID:   tenantID,
		ActorType:  actorType,
		ActorID:    actorID,
		Action:     action,
		TargetType: "client",
		TargetID:   targetID,
		Metadata:   meta,
	})
}
