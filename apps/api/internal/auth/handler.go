package auth

import (
	"context"
	"net/http"
	"net/netip"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/api/gen"
	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// TenantCreator creates a tenant and returns its ID. The auth domain depends on
// this narrow capability (provided by the tenant service) rather than importing
// the tenant package, keeping the dependency one-directional.
type TenantCreator func(ctx context.Context, name, slug string) (uuid.UUID, error)

// Handler serves the authentication endpoints (/auth/*).
type Handler struct {
	svc       *Service
	sessions  *SessionManager
	oidc      *OIDCProvider
	newTenant TenantCreator
}

// NewHandler builds an auth Handler.
func NewHandler(svc *Service, sessions *SessionManager, oidc *OIDCProvider, newTenant TenantCreator) *Handler {
	return &Handler{svc: svc, sessions: sessions, oidc: oidc, newTenant: newTenant}
}

// Register mounts the auth routes on the root engine group.
func (h *Handler) Register(r gin.IRouter) {
	g := r.Group("/auth")
	g.POST("/register", h.register)
	g.POST("/login", h.login)
	g.POST("/logout", h.logout)
	g.GET("/me", h.me)
	g.PATCH("/me", h.updateProfile)
	g.POST("/me/password", h.changePassword)
	// ADR-045 Phase 2 — public, unauthenticated password reset.
	g.POST("/password/forgot", h.forgotPassword)
	g.POST("/password/reset", h.resetPassword)
	// ADR-045 Phase 3 — public email verification for self-serve signup.
	g.POST("/verify-email", h.verifyEmail)
	g.POST("/verification/resend", h.resendVerification)
	g.GET("/oidc/login", h.oidcLogin)
	g.GET("/oidc/callback", h.oidcCallback)
}

type loginBody struct {
	Email    string `json:"email"`
	Password string `json:"password"`
}

func (h *Handler) login(c *gin.Context) {
	var body loginBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	res, err := h.svc.Login(c.Request.Context(), body.Email, body.Password)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if err := h.sessions.Login(c.Request.Context(), res.User.ID, res.ActiveTenant); err != nil {
		httpx.Error(c, domain.Internal("session_failed", "failed to establish session").WithCause(err))
		return
	}
	out := toMe(res.User, res.Memberships, res.ActiveTenant)
	c.JSON(http.StatusOK, &out)
}

type registerBody struct {
	Email      string `json:"email"`
	Password   string `json:"password"`
	Name       string `json:"name"`
	TenantName string `json:"tenant_name"`
	TenantSlug string `json:"tenant_slug"`
}

func (h *Handler) register(c *gin.Context) {
	var body registerBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	in := RegisterInput{
		Email:      body.Email,
		Password:   body.Password,
		Name:       body.Name,
		TenantName: body.TenantName,
		TenantSlug: body.TenantSlug,
	}

	// First account on a fresh install bootstraps frictionlessly (no SMTP exists
	// yet): it is created verified + active and gets an immediate session. Every
	// later signup is OPEN self-serve, returns a generic pending response, and
	// must verify by email before logging in (ADR-045 Phase 3).
	if count, _ := h.svc.CountUsers(c.Request.Context()); count == 0 {
		res, err := h.svc.Bootstrap(c.Request.Context(), in, h.newTenant)
		if err != nil {
			httpx.Error(c, err)
			return
		}
		if err := h.sessions.Login(c.Request.Context(), res.User.ID, res.ActiveTenant); err != nil {
			httpx.Error(c, domain.Internal("session_failed", "failed to establish session").WithCause(err))
			return
		}
		out := toMe(res.User, res.Memberships, res.ActiveTenant)
		c.JSON(http.StatusCreated, &out)
		return
	}

	if err := h.svc.RegisterSelfServe(c.Request.Context(), in, h.newTenant); err != nil {
		// Only validation errors (weak password / bad email) surface; existence
		// is never leaked.
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true, "pending": true})
}

// verifyEmail handles POST /auth/verify-email. Consumes the token, activates the
// account, and establishes a session so the user lands logged in.
func (h *Handler) verifyEmail(c *gin.Context) {
	var body struct {
		Token string `json:"token"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	res, err := h.svc.VerifyEmail(c.Request.Context(), body.Token)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if err := h.sessions.Login(c.Request.Context(), res.User.ID, res.ActiveTenant); err != nil {
		httpx.Error(c, domain.Internal("session_failed", "failed to establish session").WithCause(err))
		return
	}
	out := toMe(res.User, res.Memberships, res.ActiveTenant)
	c.JSON(http.StatusOK, &out)
}

// resendVerification handles POST /auth/verification/resend. ALWAYS 200 (generic).
func (h *Handler) resendVerification(c *gin.Context) {
	var body struct {
		Email string `json:"email"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		c.JSON(http.StatusOK, gin.H{"ok": true})
		return
	}
	_ = h.svc.ResendVerification(c.Request.Context(), body.Email)
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *Handler) logout(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	if p.TenantID != uuid.Nil {
		_, _ = h.svc.audit.Record(c.Request.Context(), audit.Event{
			TenantID:   p.TenantID,
			ActorType:  audit.ActorUser,
			ActorID:    p.UserID.String(),
			Action:     audit.ActionLogout,
			TargetType: "user",
			TargetID:   p.UserID.String(),
		})
	}
	if err := h.sessions.Destroy(c.Request.Context()); err != nil {
		httpx.Error(c, domain.Internal("logout_failed", "failed to destroy session").WithCause(err))
		return
	}
	c.Status(http.StatusNoContent)
}

func (h *Handler) me(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || p.Type != domain.PrincipalUser {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	u, memberships, err := h.svc.Me(c.Request.Context(), p.UserID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	out := toMe(u, memberships, p.TenantID)
	c.JSON(http.StatusOK, &out)
}

// updateProfileBody is the request body for PATCH /auth/me.
type updateProfileBody struct {
	Name string `json:"name"`
}

// updateProfile handles PATCH /auth/me — update the authenticated user's
// display name. Email is intentionally not editable here.
func (h *Handler) updateProfile(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || p.Type != domain.PrincipalUser {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	var body updateProfileBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	u, memberships, err := h.svc.UpdateProfile(c.Request.Context(), p.UserID, body.Name)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	out := toMe(u, memberships, p.TenantID)
	c.JSON(http.StatusOK, &out)
}

// changePasswordBody is the request body for POST /auth/me/password.
type changePasswordBody struct {
	CurrentPassword string `json:"current_password"`
	NewPassword     string `json:"new_password"`
}

// changePassword handles POST /auth/me/password — verify current, set new.
func (h *Handler) changePassword(c *gin.Context) {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok || p.Type != domain.PrincipalUser {
		httpx.Error(c, domain.Unauthorized("unauthenticated", "authentication required"))
		return
	}
	var body changePasswordBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if err := h.svc.ChangePassword(c.Request.Context(), p.UserID, body.CurrentPassword, body.NewPassword); err != nil {
		httpx.Error(c, err)
		return
	}
	// Keep THIS session alive (its auth_at now predates password_changed_at,
	// which would otherwise log it out); other sessions are invalidated.
	h.sessions.RefreshAuthAt(c.Request.Context())
	c.Status(http.StatusNoContent)
}

// forgotPasswordBody is the request body for POST /auth/password/forgot.
type forgotPasswordBody struct {
	Email string `json:"email"`
}

// forgotPassword handles POST /auth/password/forgot. ALWAYS returns 200 {ok:true}
// (enumeration-safe) whether or not the email maps to an account.
func (h *Handler) forgotPassword(c *gin.Context) {
	var body forgotPasswordBody
	if err := c.ShouldBindJSON(&body); err != nil {
		// Even a malformed body returns the generic OK shape (no oracle).
		c.JSON(http.StatusOK, gin.H{"ok": true})
		return
	}
	_ = h.svc.RequestPasswordReset(c.Request.Context(), body.Email, clientAddr(c))
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// resetPasswordBody is the request body for POST /auth/password/reset.
type resetPasswordBody struct {
	Token    string `json:"token"`
	Password string `json:"password"`
}

// resetPassword handles POST /auth/password/reset. Consumes the token + sets the
// new password; never establishes a session. Bad/expired/used tokens → 410.
func (h *Handler) resetPassword(c *gin.Context) {
	var body resetPasswordBody
	if err := c.ShouldBindJSON(&body); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if err := h.svc.ResetPassword(c.Request.Context(), body.Token, body.Password, clientAddr(c)); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// clientAddr parses gin's resolved client IP into a netip.Addr (invalid when
// unparseable). Used to rate-limit + record the requesting IP.
func clientAddr(c *gin.Context) netip.Addr {
	addr, err := netip.ParseAddr(c.ClientIP())
	if err != nil {
		return netip.Addr{}
	}
	return addr
}

func (h *Handler) oidcLogin(c *gin.Context) {
	if !h.oidc.Enabled() {
		httpx.Error(c, domain.Unavailable("oidc_disabled", "OIDC is not configured"))
		return
	}
	url, state, nonce, verifier, err := h.oidc.AuthCodeURL()
	if err != nil {
		httpx.Error(c, domain.Internal("oidc_url_failed", "failed to build authorization URL").WithCause(err))
		return
	}
	h.sessions.putOAuth(c.Request.Context(), state, nonce, verifier)
	c.Redirect(http.StatusFound, url)
}

func (h *Handler) oidcCallback(c *gin.Context) {
	if !h.oidc.Enabled() {
		httpx.Error(c, domain.Unavailable("oidc_disabled", "OIDC is not configured"))
		return
	}
	state, nonce, verifier := h.sessions.takeOAuth(c.Request.Context())
	if state == "" || c.Query("state") != state {
		httpx.Error(c, domain.Unauthorized("oidc_state_mismatch", "OIDC state mismatch or expired"))
		return
	}
	code := c.Query("code")
	if code == "" {
		httpx.Error(c, domain.Unauthorized("oidc_no_code", "OIDC callback missing code"))
		return
	}
	claims, err := h.oidc.Exchange(c.Request.Context(), code, verifier, nonce)
	if err != nil {
		httpx.Error(c, domain.Unauthorized("oidc_exchange_failed", "OIDC verification failed"))
		return
	}
	res, err := h.svc.UpsertOIDCUser(c.Request.Context(), claims.Issuer, claims.Subject, claims.Email, claims.EmailVerified, claims.Name, h.newTenant)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if err := h.sessions.Login(c.Request.Context(), res.User.ID, res.ActiveTenant); err != nil {
		httpx.Error(c, domain.Internal("session_failed", "failed to establish session").WithCause(err))
		return
	}
	out := toMe(res.User, res.Memberships, res.ActiveTenant)
	c.JSON(http.StatusOK, &out)
}

func toMe(u User, memberships []Membership, active uuid.UUID) gen.Me {
	me := gen.Me{User: toAPIUser(u), Memberships: toAPIMemberships(memberships)}
	if active != uuid.Nil {
		me.ActiveTenantID = gen.NewOptUUID(active)
	}
	return me
}

func toAPIUser(u User) gen.User {
	out := gen.User{
		ID:           u.ID,
		Email:        u.Email,
		Name:         u.Name,
		CreatedAt:    u.CreatedAt,
		UpdatedAt:    u.UpdatedAt,
		IsSuperadmin: gen.NewOptBool(u.IsSuperadmin),
	}
	if u.LastLoginAt != nil {
		out.LastLoginAt = gen.NewOptDateTime(*u.LastLoginAt)
	}
	return out
}

func toAPIMembership(m Membership) gen.Membership {
	return gen.Membership{UserID: m.UserID, TenantID: m.TenantID, Role: gen.Role(m.Role)}
}

func toAPIMemberships(ms []Membership) []gen.Membership {
	out := make([]gen.Membership, 0, len(ms))
	for _, m := range ms {
		out = append(out, toAPIMembership(m))
	}
	return out
}

// roleOrDefault parses a role string, defaulting to viewer when empty.
func roleOrDefault(s string) authz.Role {
	if s == "" {
		return authz.RoleViewer
	}
	return authz.Role(s)
}
