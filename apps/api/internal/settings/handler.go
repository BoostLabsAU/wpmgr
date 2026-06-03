package settings

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// Handler serves the instance SMTP settings under /api/v1/settings/smtp. Reads
// are admin+ (to render the masked form); writes + send-test are owner-only
// (PermSMTPManage). RequireOrgScope() blocks site-scoped collaborators.
type Handler struct {
	svc   *Service
	audit *audit.Recorder
}

// NewHandler builds the settings Handler.
func NewHandler(svc *Service, rec *audit.Recorder) *Handler {
	return &Handler{svc: svc, audit: rec}
}

// Register mounts the SMTP settings routes.
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/settings/smtp", authz.RequireOrgScope())
	g.GET("", authz.RequireRole(authz.RoleAdmin), h.get)
	g.PUT("", authz.RequirePermission(authz.PermSMTPManage), h.put)
	g.POST("/test", authz.RequirePermission(authz.PermSMTPManage), h.test)
}

func (h *Handler) get(c *gin.Context) {
	out, err := h.svc.Get(c.Request.Context())
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, out)
}

func (h *Handler) put(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	var body SMTPUpdate
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	out, err := h.svc.Update(c.Request.Context(), body, p.UserID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if h.audit != nil {
		_, _ = h.audit.Record(c.Request.Context(), audit.Event{
			TenantID:   p.TenantID,
			ActorType:  audit.ActorUser,
			ActorID:    p.ActorID(),
			Action:     "smtp.settings.update",
			TargetType: "smtp_settings",
			TargetID:   "instance",
			Metadata: map[string]any{
				"enabled":  out.Enabled,
				"host":     out.Host,
				"tls_mode": out.TLSMode,
			},
		})
	}
	c.JSON(http.StatusOK, out)
}

type testBody struct {
	ToAddress string `json:"to_address"`
}

type testResult struct {
	OK      bool   `json:"ok"`
	Message string `json:"message"`
}

// test sends a test email through the stored config. Failures are returned as a
// 200 {ok:false, message} so the UI can show the scrubbed reason inline (the
// message never contains internal IPs/hostnames).
func (h *Handler) test(c *gin.Context) {
	var body testBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if err := h.svc.SendTest(c.Request.Context(), body.ToAddress); err != nil {
		c.JSON(http.StatusOK, testResult{OK: false, Message: err.Error()})
		return
	}
	c.JSON(http.StatusOK, testResult{OK: true, Message: "Test email sent to " + body.ToAddress})
}
