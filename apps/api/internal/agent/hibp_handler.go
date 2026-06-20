package agent

import (
	"context"
	"net/http"
	"regexp"
	"strings"

	"github.com/gin-gonic/gin"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// hibpPrefixRe validates that the prefix path parameter is exactly 5 uppercase
// hex characters. Lowercase is rejected so the caller (agent) normalises before
// sending (aligning with the hibpPrefixRe in the security service).
var hibpPrefixRe = regexp.MustCompile(`^[A-F0-9]{5}$`)

// HIBPRangeGetter is the subset of security.Service the HIBP handler needs.
// Declared as an interface so tests can substitute a fake without spinning up
// the SSRF transport or a Postgres connection.
type HIBPRangeGetter interface {
	GetHIBPRange(ctx context.Context, prefix string) (string, error)
}

// HIBPHandler serves GET /agent/v1/security/hibp/range/{prefix} — the
// agent-authenticated endpoint through which the agent obtains the HIBP Pwned
// Passwords range body for a 5-char SHA-1 prefix.
//
// Only the 5-char prefix is transmitted; the agent performs the suffix match
// locally and never sends the full password or full hash to the CP.
//
// The response body is the raw SUFFIX:COUNT text from the HIBP range API
// (possibly with Add-Padding decoy lines). An empty body means either a clean
// prefix (zero breach matches) or a fail-open (HIBP unreachable) — the agent
// must treat both identically: the password is considered not breached.
type HIBPHandler struct {
	svc HIBPRangeGetter
}

// NewHIBPHandler wires the handler.
func NewHIBPHandler(svc HIBPRangeGetter) *HIBPHandler {
	return &HIBPHandler{svc: svc}
}

// Register mounts the route on the agent-authenticated group.
// The route lives under /agent/v1 (same group as all other agent endpoints).
func (h *HIBPHandler) Register(r *gin.RouterGroup) {
	r.GET("/security/hibp/range/:prefix", h.getRange)
}

func (h *HIBPHandler) getRange(c *gin.Context) {
	// Agent identity check: the Ed25519 middleware already verified the signed
	// request; IdentityFromContext gives us the resolved site/tenant. We don't
	// need the tenant here (HIBP cache is global) but we reject unauthenticated
	// calls in the same way all agent endpoints do.
	if _, ok := IdentityFromContext(c.Request.Context()); !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}

	prefix := strings.ToUpper(strings.TrimSpace(c.Param("prefix")))
	if !hibpPrefixRe.MatchString(prefix) {
		httpx.Error(c, domain.Validation("invalid_hibp_prefix",
			"prefix must be exactly 5 uppercase hex characters (A-F0-9)"))
		return
	}

	body, err := h.svc.GetHIBPRange(c.Request.Context(), prefix)
	if err != nil {
		// GetHIBPRange is fail-open: validation errors surface here; infra errors
		// return empty body upstream. Map any remaining errors to 422/500.
		httpx.Error(c, err)
		return
	}

	// Return the raw SUFFIX:COUNT body as plain text so the agent can parse it
	// directly without a JSON wrapper. An empty body = no breach data (or fail-open).
	c.Data(http.StatusOK, "text/plain; charset=utf-8", []byte(body))
}
