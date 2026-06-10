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

// createdAtLayouts lists the timestamp formats the agent may send, tried in
// order. RFC3339Nano is first (most precise), then RFC3339, then two MySQL-style
// UTC variants that older or PHP-adjacent code emits.
var createdAtLayouts = []string{
	time.RFC3339Nano,
	time.RFC3339,
	"2006-01-02 15:04:05",
	"2006-01-02T15:04:05",
}

// parseCreatedAt parses a JSON-encoded timestamp from the agent. raw is the
// raw JSON value of the created_at field (may be a quoted string, null, or
// absent/empty). On any parse failure it returns time.Now().UTC() so a single
// bad timestamp never causes a 422 on the whole batch.
func parseCreatedAt(raw json.RawMessage) time.Time {
	if len(raw) == 0 {
		return time.Now().UTC()
	}
	// Unmarshal the JSON string value.
	var s string
	if err := json.Unmarshal(raw, &s); err != nil || s == "" {
		return time.Now().UTC()
	}
	for _, layout := range createdAtLayouts {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC()
		}
	}
	return time.Now().UTC()
}

// coerceResponse normalises the JSON-encoded provider response field into the
// map[string]any that IngestEntry.Response expects. The agent legitimately sends
// a plain string (e.g. "SMTP send OK"), a JSON object, null, or nothing at all.
// This function never returns nil, never 422s the batch:
//
//   - null / empty → empty map
//   - JSON object  → unmarshal directly into map[string]any
//   - JSON string  → map{"summary": <unwrapped string value>}
//   - anything else (number, bool, array) → map{"summary": <raw JSON text>}
func coerceResponse(raw json.RawMessage) map[string]any {
	if len(raw) == 0 {
		return map[string]any{}
	}
	// null literal.
	if string(raw) == "null" {
		return map[string]any{}
	}

	first := raw[0]

	// JSON object: decode directly.
	if first == '{' {
		var m map[string]any
		if err := json.Unmarshal(raw, &m); err == nil {
			return m
		}
		// Malformed object — treat as raw summary.
		return map[string]any{"summary": string(raw)}
	}

	// JSON string: unwrap quotes so the value is readable.
	if first == '"' {
		var s string
		if err := json.Unmarshal(raw, &s); err == nil {
			return map[string]any{"summary": s}
		}
		return map[string]any{"summary": string(raw)}
	}

	// Number, bool, array, or anything else: store raw text.
	return map[string]any{"summary": string(raw)}
}

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
	pub      EventPublisher // may be nil; guarded before use
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
//
// Response is json.RawMessage rather than map[string]any so the batch JSON
// decodes successfully even when the agent sends a plain string, null, or any
// non-object value. coerceResponse normalises it afterwards.
//
// CreatedAt is json.RawMessage rather than time.Time so a MySQL-format or
// otherwise non-RFC3339 timestamp does not fail json.Unmarshal on the whole
// batch. parseCreatedAt normalises it afterwards.
type ingestLogEntry struct {
	AgentSeq    int64           `json:"agent_seq"`
	MessageID   string          `json:"message_id"`
	ToAddresses []string        `json:"to_addresses"`
	FromAddress string          `json:"from_address"`
	Subject     string          `json:"subject"`
	Provider    string          `json:"provider"`
	Status      string          `json:"status"`   // sent | failed
	Response    json.RawMessage `json:"response"` // provider response — any JSON shape
	Error       string          `json:"error"`
	Retries     int             `json:"retries"`
	ResentCount int             `json:"resent_count"`
	BodyStored  bool            `json:"body_stored"`
	Body        *string         `json:"body"`
	CreatedAt   json.RawMessage `json:"created_at"` // any parseable timestamp string
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

	// Map wire types to domain IngestEntry. coerceResponse and parseCreatedAt
	// guarantee that no per-entry field shape variation can cause a 422: a
	// plain-string response becomes {"summary":…} and an unparseable timestamp
	// defaults to now.
	entries := make([]IngestEntry, 0, len(req.Entries))
	for _, e := range req.Entries {
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
			Response:    coerceResponse(e.Response),
			Error:       e.Error,
			Retries:     e.Retries,
			ResentCount: e.ResentCount,
			BodyStored:  e.BodyStored,
			Body:        e.Body,
			CreatedAt:   parseCreatedAt(e.CreatedAt),
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
