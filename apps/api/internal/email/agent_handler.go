package email

import (
	"encoding/json"
	"io"
	"net/http"
	"time"

	"github.com/gin-gonic/gin"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// maxAgentEmailLogBytes bounds the agent-pushed email log batch body.
// At ~2 KiB per entry (subject + addresses + response JSON + body) a 500-entry
// batch is ≤ 1 MiB. We cap at 4 MiB for safety.
const maxAgentEmailLogBytes = 4 << 20

// AgentHandler serves the agent-authenticated email ingest route at
// POST /agent/v1/email/log.
//
// Auth: Ed25519 signed-request middleware on the /agent/v1 group. The
// tenant_id and site_id come exclusively from the verified agent identity on
// the context — NEVER from the request body (mirrors activity ingest).
type AgentHandler struct {
	svc      *Service
	pub      EventPublisher    // may be nil; guarded before use
	throttle *logIngestThrottle
}

// NewAgentHandler wires the handler against the email service.
func NewAgentHandler(svc *Service) *AgentHandler {
	return &AgentHandler{svc: svc, throttle: newLogIngestThrottle()}
}

// NewAgentHandlerWithPublisher wires the handler with an SSE event publisher so
// it can emit email.log_ingested after each successful ingest batch.
func NewAgentHandlerWithPublisher(svc *Service, pub EventPublisher) *AgentHandler {
	return &AgentHandler{svc: svc, pub: pub, throttle: newLogIngestThrottle()}
}

// SetPublisher wires the SSE event publisher after construction. Mirrors
// SetAgentClient — called from main.go after the publisher is available.
func (h *AgentHandler) SetPublisher(pub EventPublisher) {
	h.pub = pub
}

// Register mounts the route on the agent-authenticated group.
func (h *AgentHandler) Register(r *gin.RouterGroup) {
	r.POST("/email/log", h.ingestLog)
}

// ---------------------------------------------------------------------------
// wire types for the agent ingest request / response
// ---------------------------------------------------------------------------

// ingestLogRequest is the agent push body.
type ingestLogRequest struct {
	Entries []ingestLogEntry `json:"entries"`
}

// ingestLogEntry is one send record from the agent's local wpmgr_email_log.
// Field names mirror the agent table columns exactly so Phase-3b integration
// requires no conversion on the agent side.
type ingestLogEntry struct {
	AgentSeq    int64          `json:"agent_seq"`
	MessageID   string         `json:"message_id"`
	ToAddresses []string       `json:"to_addresses"`
	FromAddress string         `json:"from_address"`
	Subject     string         `json:"subject"`
	Provider    string         `json:"provider"`
	Status      string         `json:"status"`      // sent | failed
	Response    map[string]any `json:"response"`    // provider response, may be null
	Error       string         `json:"error"`
	Retries     int            `json:"retries"`
	ResentCount int            `json:"resent_count"`
	BodyStored  bool           `json:"body_stored"`
	Body        *string        `json:"body"`
	CreatedAt   time.Time      `json:"created_at"` // RFC3339
}

// ---------------------------------------------------------------------------
// handler
// ---------------------------------------------------------------------------

func (h *AgentHandler) ingestLog(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}

	body, err := io.ReadAll(io.LimitReader(c.Request.Body, maxAgentEmailLogBytes))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "could not read request body"))
		return
	}
	var req ingestLogRequest
	if uerr := json.Unmarshal(body, &req); uerr != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON: "+uerr.Error()))
		return
	}

	// Map wire types to domain IngestEntry.
	entries := make([]IngestEntry, 0, len(req.Entries))
	for _, e := range req.Entries {
		resp := e.Response
		if resp == nil {
			resp = map[string]any{}
		}
		if len(e.ToAddresses) == 0 {
			e.ToAddresses = []string{}
		}
		entries = append(entries, IngestEntry{
			AgentSeq:    e.AgentSeq,
			MessageID:   e.MessageID,
			ToAddresses: e.ToAddresses,
			FromAddress: e.FromAddress,
			Subject:     e.Subject,
			Provider:    e.Provider,
			Status:      e.Status,
			Response:    resp,
			Error:       e.Error,
			Retries:     e.Retries,
			ResentCount: e.ResentCount,
			BodyStored:  e.BodyStored,
			Body:        e.Body,
			CreatedAt:   e.CreatedAt,
		})
	}

	result, ierr := h.svc.IngestLogBatch(c.Request.Context(), id.TenantID, id.SiteID, entries)
	if ierr != nil {
		httpx.Error(c, ierr)
		return
	}

	// Throttled SSE emit: notify the email dashboard that new log entries have
	// landed for this site. At most once per LogIngestedThrottle per site so a
	// burst of agent catch-up pushes does not flood the event bus.
	publishLogIngested(c.Request.Context(), h.pub, h.throttle, id.TenantID, id.SiteID, len(entries))

	c.JSON(http.StatusOK, gin.H{"acked_through": result.AckedThrough})
}
