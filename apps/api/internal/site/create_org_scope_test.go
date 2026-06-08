package site

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// buildCreateEngine wires a minimal Gin engine for POST /api/v1/sites with the
// same middleware chain as the real server (RequirePermission + RequireOrgScope).
// The principal is injected directly onto the request context by a leading
// middleware so authn+tenant resolution is bypassed; we only exercise the authz
// layer and the route-registration under test.
func buildCreateEngine(h *Handler, principal domain.Principal) *gin.Engine {
	gin.SetMode(gin.TestMode)
	r := gin.New()
	// Inject the principal on every request (mirrors the real auth middleware).
	r.Use(func(c *gin.Context) {
		c.Request = c.Request.WithContext(domain.WithPrincipal(c.Request.Context(), principal))
		c.Next()
	})
	g := r.Group("/api/v1")
	// Register with the real middleware chain from handler.go:
	//   RequirePermission(PermSiteWrite) + RequireOrgScope()
	g.POST("/sites",
		authz.RequirePermission(authz.PermSiteWrite),
		authz.RequireOrgScope(),
		h.create,
	)
	return r
}

func doCreateSite(r *gin.Engine, body string) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/sites",
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	r.ServeHTTP(rec, req)
	return rec
}

// TestPostSitesForbiddenForSiteScopedActor verifies Fix 2: a site-scoped
// collaborator (Scope == "site") is rejected with 403 on POST /sites even when
// their role grants PermSiteWrite on one site. An org-scoped member passes.
func TestPostSitesForbiddenForSiteScopedActor(t *testing.T) {
	tenantID := uuid.New()
	sharedSiteID := uuid.New()

	svc := NewService(&fakeRepo{}, domain.NewValidator(), domain.SystemClock{})
	h := NewHandler(svc, nil, "")

	t.Run("site-scoped collaborator gets 403", func(t *testing.T) {
		// A site-scoped principal: has PermSiteWrite via role "operator" but is
		// scoped to a single site only.
		p := domain.Principal{
			Type:           domain.PrincipalUser,
			UserID:         uuid.New(),
			TenantID:       tenantID,
			Role:           "operator",
			Scope:          domain.ScopeSite,
			AllowedSiteIDs: []uuid.UUID{sharedSiteID},
		}
		rec := doCreateSite(buildCreateEngine(h, p), `{"url":"https://probe.example.com","name":"Probe"}`)
		if rec.Code != http.StatusForbidden {
			t.Fatalf("expected 403 for site-scoped actor, got %d", rec.Code)
		}
	})

	t.Run("org-scoped operator passes authz (may fail business logic)", func(t *testing.T) {
		// An org-scoped member: RequireOrgScope must not block them.
		p := domain.Principal{
			Type:     domain.PrincipalUser,
			UserID:   uuid.New(),
			TenantID: tenantID,
			Role:     "operator",
			Scope:    domain.ScopeOrg,
		}
		rec := doCreateSite(buildCreateEngine(h, p), `{"url":"https://new.example.com","name":"New"}`)
		// 201 (or a 4xx from business logic) — either way, NOT 403 from RequireOrgScope.
		if rec.Code == http.StatusForbidden {
			t.Fatalf("org-scoped operator should not be blocked by RequireOrgScope, got 403")
		}
	})
}
