package email

// agent_suppression_handler.go — agent-authenticated endpoint for suppression
// delta fetch (Phase 4a).
//
// Route: GET /agent/v1/email/suppression?since=<cursor>
//   - Auth: Ed25519 signed-request (same middleware as /agent/v1/email/log)
//   - tenant_id + site_id from the verified agent identity (never from query params)
//   - Returns suppression deltas (org-wide + this site) above the cursor
//     so the agent can cache them locally and check BEFORE sending
//
// Keyset cursor: ascending (created_at, id) — agent fetches "what's new since
// my last sync" and advances cursor after each successful sync.

import (
	"net/http"

	"github.com/gin-gonic/gin"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// AgentSuppressionHandler serves the agent suppression-fetch endpoint.
type AgentSuppressionHandler struct {
	svc *Service
}

// NewAgentSuppressionHandler wires the handler against the email service.
func NewAgentSuppressionHandler(svc *Service) *AgentSuppressionHandler {
	return &AgentSuppressionHandler{svc: svc}
}

// Register mounts the route on the agent-authenticated group.
func (h *AgentSuppressionHandler) Register(r *gin.RouterGroup) {
	r.GET("/email/suppression", h.fetchDeltas)
}

// fetchDeltas handles GET /agent/v1/email/suppression?since=<cursor>.
//
// Response: {"entries": [...], "next_cursor": "<opaque>"}
// The agent should store next_cursor and pass it as since= on the next call.
// An empty since= returns all suppression entries for this tenant+site.
func (h *AgentSuppressionHandler) fetchDeltas(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}

	since := c.Query("since")

	page, err := h.svc.ListSuppressionDeltas(c.Request.Context(), id.TenantID, id.SiteID, since)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	dtos := make([]suppressionDTO, 0, len(page.Entries))
	for _, s := range page.Entries {
		dtos = append(dtos, toSuppressionDTO(s))
	}
	c.JSON(http.StatusOK, gin.H{
		"entries":     dtos,
		"next_cursor": page.NextCursor,
	})
}
