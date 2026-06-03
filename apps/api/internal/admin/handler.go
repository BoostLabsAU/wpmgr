package admin

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// Handler serves the superadmin area under /api/v1/admin.
type Handler struct {
	svc  *Service
	pool *db.Pool
}

// NewHandler builds an admin Handler.
func NewHandler(svc *Service, pool *db.Pool) *Handler {
	return &Handler{svc: svc, pool: pool}
}

// Register mounts the admin routes on the auth-gated (not tenant-gated)
// v1Auth group. The requireSuperadmin middleware gates the entire sub-group.
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/admin", requireSuperadmin(h.pool))
	g.GET("/stats", h.stats)
	g.GET("/users", h.listUsers)
	g.DELETE("/users/:userId", h.deleteUser)
	g.PATCH("/users/:userId", h.setStatus)
	g.POST("/users/:userId/resend-verification", h.resendVerification)
}

// requireSuperadmin is a Gin middleware that returns 403 unless the
// authenticated principal has is_superadmin=true. It does a targeted
// single-column DB read (no joins) against the users table, which has no RLS,
// so it runs on the bare pool without any tenant context.
func requireSuperadmin(pool *db.Pool) gin.HandlerFunc {
	return func(c *gin.Context) {
		p, ok := domain.PrincipalFromContext(c.Request.Context())
		if !ok || p.Type != domain.PrincipalUser {
			httpx.Error(c, domain.Forbidden("superadmin_required", "superadmin access required"))
			c.Abort()
			return
		}
		var isSA bool
		err := pool.QueryRow(c.Request.Context(),
			`SELECT is_superadmin FROM users WHERE id = $1`, p.UserID,
		).Scan(&isSA)
		if err != nil || !isSA {
			httpx.Error(c, domain.Forbidden("superadmin_required", "superadmin access required"))
			c.Abort()
			return
		}
		c.Next()
	}
}

func (h *Handler) stats(c *gin.Context) {
	s, err := h.svc.Stats(c.Request.Context())
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{
		"users":         s.Users,
		"organizations": s.Orgs,
		"sites":         s.Sites,
	})
}

func (h *Handler) listUsers(c *gin.Context) {
	search := c.Query("search")
	limit := parseInt32(c.Query("limit"), 50)
	offset := parseInt32(c.Query("offset"), 0)
	users, err := h.svc.ListUsers(c.Request.Context(), search, limit, offset)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	items := make([]gin.H, 0, len(users))
	for _, u := range users {
		items = append(items, userToJSON(u))
	}
	c.JSON(http.StatusOK, gin.H{"items": items})
}

func (h *Handler) deleteUser(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	targetID, err := uuid.Parse(c.Param("userId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_user_id", "userId is not a valid UUID"))
		return
	}
	res, err := h.svc.DeleteUser(c.Request.Context(), p.UserID, targetID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	kept := make([]gin.H, 0, len(res.KeptOrgs))
	for _, o := range res.KeptOrgs {
		kept = append(kept, gin.H{"id": o.ID, "name": o.Name, "site_count": o.SiteCount})
	}
	c.JSON(http.StatusOK, gin.H{
		"deleted_orgs":         res.DeletedOrgs,
		"kept_orgs_with_sites": kept,
	})
}

type setStatusBody struct {
	Status string `json:"status"`
}

func (h *Handler) setStatus(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	targetID, err := uuid.Parse(c.Param("userId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_user_id", "userId is not a valid UUID"))
		return
	}
	var body setStatusBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	updated, err := h.svc.SetStatus(c.Request.Context(), p.UserID, targetID, body.Status)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, userToJSON(updated))
}

func (h *Handler) resendVerification(c *gin.Context) {
	targetID, err := uuid.Parse(c.Param("userId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_user_id", "userId is not a valid UUID"))
		return
	}
	if err := h.svc.ResendVerification(c.Request.Context(), targetID); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func userToJSON(u AdminUser) gin.H {
	m := gin.H{
		"id":             u.ID,
		"email":          u.Email,
		"name":           u.Name,
		"status":         u.Status,
		"email_verified": u.EmailVerified,
		"created_at":     u.CreatedAt,
		"is_superadmin":  u.IsSuperadmin,
		"org_count":      u.OrgCount,
	}
	if u.LastLoginAt != nil {
		m["last_login_at"] = *u.LastLoginAt
	}
	return m
}

func parseInt32(s string, def int32) int32 {
	if s == "" {
		return def
	}
	n, err := strconv.ParseInt(s, 10, 32)
	if err != nil || n < 0 {
		return def
	}
	if n > 200 {
		return 200
	}
	return int32(n)
}
