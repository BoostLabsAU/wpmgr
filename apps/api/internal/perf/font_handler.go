package perf

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/font"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// maxFontTranscodeBody is the size cap for the agent's font transcode request.
const maxFontTranscodeBody = 8 << 10 // 8 KiB — small JSON envelope

// fontTranscodeDailyCap is the maximum number of new font_transcode_results
// rows a single tenant may create in one UTC calendar day. This bounds the
// cost of a hostile agent minting unlimited distinct hashes.
const fontTranscodeDailyCap = 500

// FontTranscodeEnqueuer enqueues font_transcode River jobs. *media.FontRiverEnqueuer
// satisfies it.
type FontTranscodeEnqueuer interface {
	EnqueueTranscode(ctx context.Context, args font.TranscodeArgs) (int64, error)
}

// fontTranscodeRepo is the persistence interface used by FontAgentHandler.
// *Repo satisfies it.
type fontTranscodeRepo interface {
	GetFontTranscodeResult(ctx context.Context, tenantID uuid.UUID, sourceHash string) (FontTranscodeResult, error)
	CountTodayFontTranscodeEnqueues(ctx context.Context, tenantID uuid.UUID) (int, error)
	UpsertFontTranscodeJob(ctx context.Context, tenantID, siteID uuid.UUID, sourceHash string, riverJobID int64) (FontTranscodeResult, error)
}

// FontSourcePresigner mints presigned PUT and GET URLs for font object-storage
// keys. *blobstore.Store satisfies it.
//
// PresignPut is called on the first enqueue to mint the source-upload URL.
// PresignGet is called on every ready-state response to give the agent a
// scoped, short-TTL presigned GET URL so the agent can fetch the WOFF2 bytes
// directly. The agent MUST NOT presign or construct a storage key itself —
// that would reintroduce the path-traversal risk this design prevents.
//
// Both methods MUST only ever be called with GuardStorageKey-validated,
// server-derived, tenant-scoped keys.
type FontSourcePresigner interface {
	PresignPut(ctx context.Context, key string, ttl time.Duration) (string, error)
	PresignGet(ctx context.Context, key string, ttl time.Duration) (string, error)
}

// FontAgentHandler serves the agent-authenticated font transcode endpoint at
// POST /agent/v1/fonts/transcode. It is wired separately from AgentHandler
// so the font_transcode enqueuer (which has a River client dependency) can be
// optionally nil (the endpoint returns a degraded response).
type FontAgentHandler struct {
	repo      fontTranscodeRepo
	enqueuer  FontTranscodeEnqueuer
	presigner FontSourcePresigner
	// presignTTL is the TTL for both source-upload PUT and WOFF2-fetch GET
	// presigned URLs.
	presignTTL time.Duration
}

// NewFontAgentHandler wires the handler. enqueuer and presigner may be nil
// (the endpoint returns state="negative" with an "unavailable" detail so the
// agent falls back to serving the original).
func NewFontAgentHandler(repo *Repo, enqueuer FontTranscodeEnqueuer, presigner FontSourcePresigner, presignTTL time.Duration) *FontAgentHandler {
	if presignTTL <= 0 {
		presignTTL = 15 * time.Minute
	}
	return &FontAgentHandler{
		repo:       repo,
		enqueuer:   enqueuer,
		presigner:  presigner,
		presignTTL: presignTTL,
	}
}

// Register mounts the route on the agent-authenticated group.
func (h *FontAgentHandler) Register(r *gin.RouterGroup) {
	r.POST("/fonts/transcode", h.transcodeRequest)
}

// transcodeRequest handles POST /agent/v1/fonts/transcode.
//
// CONTRACT (authoritative — match this exactly, agent eng):
//
//   - Auth: Ed25519 signed-request middleware (agent group). Site + tenant come
//     from the VERIFIED identity, NEVER the body.
//   - Request: application/json — FontTranscodeRequest.
//   - Response 200: FontTranscodeResponse.
//     state=="pending"  → job enqueued; source_put_url present on first enqueue
//     (agent must PUT source bytes there). Poll on next build.
//     state=="ready"    → woff2_get_url is a short-TTL presigned GET URL for
//     the server-derived WOFF2 object; agent fetches WOFF2 bytes directly.
//     woff2_key is also present (informational). The agent MUST NOT presign
//     or build a storage key itself.
//     state=="negative" → permanent failure; serve original forever.
//   - Response 400: invalid source_hash (not 64 hex chars) or source_size <= 0.
//   - Response 429: daily enqueue cap exceeded for this tenant.
//   - Response 503: enqueuer not wired (only in degraded deployments).
//
// Size cap: 10 MiB enforced by ErrFontTooLarge before the job is enqueued.
// Storage keys are SERVER-DERIVED from tenant identity + validated hash; the
// agent never supplies nor learns the internal key prefix.
func (h *FontAgentHandler) transcodeRequest(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}

	body, err := io.ReadAll(io.LimitReader(c.Request.Body, maxFontTranscodeBody))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "could not read request body"))
		return
	}
	var in agentcmd.FontTranscodeRequest
	if err := json.Unmarshal(body, &in); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON: "+err.Error()))
		return
	}

	// SECURITY: validate source_hash strictly. Reject anything that is not
	// exactly 64 lowercase hex chars. This prevents path-traversal and ensures
	// the derived storage keys are safe before they reach the presigner.
	if !font.ValidSourceHash(in.SourceHash) {
		httpx.Error(c, domain.Validation("invalid_source_hash", "source_hash must be exactly 64 lowercase hex characters (BLAKE3)"))
		return
	}

	// source_size must be positive so the encoder is not given a phantom job.
	if in.SourceSize <= 0 {
		httpx.Error(c, domain.Validation("invalid_source_size", "source_size must be > 0"))
		return
	}

	if in.SourceSize > font.MaxFontBytes {
		c.JSON(http.StatusOK, agentcmd.FontTranscodeResponse{
			State:       string(FontTranscodeNegative),
			ErrorDetail: "source exceeds 10 MiB limit",
		})
		return
	}

	ctx := c.Request.Context()

	// Check for an existing result first (avoid duplicate jobs).
	existing, gerr := h.repo.GetFontTranscodeResult(ctx, id.TenantID, in.SourceHash)
	if gerr == nil {
		// Row exists — return its current state. No source_put_url: the source
		// was already uploaded when the row was first created. If the job is
		// ready, mint a presigned GET URL so the agent can fetch the WOFF2
		// without building or presigning any key itself.
		woff2GetURL := h.presignWoff2GetURL(ctx, existing, in.SourceHash)
		c.JSON(http.StatusOK, fontResultToResponse(existing, "", woff2GetURL))
		return
	}
	if gerr != ErrNotFound {
		httpx.Error(c, gerr)
		return
	}

	// No row yet — check the daily enqueue cap before proceeding.
	count, cerr := h.repo.CountTodayFontTranscodeEnqueues(ctx, id.TenantID)
	if cerr != nil {
		httpx.Error(c, fmt.Errorf("font transcode: daily cap check: %w", cerr))
		return
	}
	if count >= fontTranscodeDailyCap {
		c.JSON(http.StatusTooManyRequests, gin.H{
			"error":  "font_transcode_daily_cap",
			"detail": fmt.Sprintf("daily font transcode enqueue cap (%d) reached for this tenant", fontTranscodeDailyCap),
		})
		return
	}

	// No row yet — enqueue a new job.
	if h.enqueuer == nil {
		c.JSON(http.StatusServiceUnavailable, agentcmd.FontTranscodeResponse{
			State:       string(FontTranscodeNegative),
			ErrorDetail: "font transcode enqueuer not available",
		})
		return
	}

	// SERVER-DERIVED keys. The agent never supplies the key; the CP derives
	// them from the verified tenant identity and validated hash.
	sourceKey := font.DeriveSourceKey(id.TenantID, in.SourceHash)
	// woff2Key is not needed in the enqueue path: the worker re-derives it from
	// TenantID + SourceHash, and on ready-state polls presignWoff2GetURL
	// re-derives it from the DB row's TenantID + the caller-validated hash.

	args := font.TranscodeArgs{
		TenantID:   id.TenantID,
		SiteID:     id.SiteID,
		SourceHash: in.SourceHash,
		SourceKey:  sourceKey, // server-derived; worker re-derives and ignores this
		SourceSize: in.SourceSize,
	}
	riverJobID, eqErr := h.enqueuer.EnqueueTranscode(ctx, args)
	if eqErr != nil {
		httpx.Error(c, fmt.Errorf("font transcode: enqueue: %w", eqErr))
		return
	}

	// Record the pending row.
	result, uErr := h.repo.UpsertFontTranscodeJob(ctx, id.TenantID, id.SiteID, in.SourceHash, riverJobID)
	if uErr != nil {
		// Enqueue succeeded but we couldn't persist the row. Return pending so
		// the agent retries; River will deduplicate the job on the next attempt.
		c.JSON(http.StatusOK, agentcmd.FontTranscodeResponse{
			State: string(FontTranscodePending),
		})
		return
	}

	// Mint a presigned PUT URL for the source upload. The agent MUST PUT the
	// raw font bytes to this URL before the encoder can read the source.
	// source_put_url is only present on the FIRST enqueue response.
	// A new job is always state=pending so no woff2_get_url is needed here.
	var sourcePutURL string
	if h.presigner != nil {
		var presignErr error
		sourcePutURL, presignErr = h.presigner.PresignPut(ctx, sourceKey, h.presignTTL)
		if presignErr != nil {
			// Non-fatal: log and return pending without a put URL. The agent
			// will retry on the next build, at which point the row already exists
			// and it will get back state=pending without a put URL — the encoder
			// will retry when the source arrives. Log prominently so ops can
			// investigate storage connectivity.
			c.JSON(http.StatusOK, fontResultToResponse(result, "", ""))
			return
		}
	}

	c.JSON(http.StatusOK, fontResultToResponse(result, sourcePutURL, ""))
}

// fontResultToResponse builds the agent response from a DB row. sourcePutURL
// is non-empty only on the first-enqueue path. woff2GetURL is non-empty only
// when the state is ready (CP-minted presigned GET for the WOFF2 object).
func fontResultToResponse(r FontTranscodeResult, sourcePutURL, woff2GetURL string) agentcmd.FontTranscodeResponse {
	return agentcmd.FontTranscodeResponse{
		State:        string(r.State),
		SourcePutURL: sourcePutURL,
		Woff2Key:     r.Woff2Key,
		Woff2GetURL:  woff2GetURL,
		ErrorDetail:  r.ErrorDetail,
	}
}

// presignWoff2GetURL mints a short-TTL presigned GET URL for the WOFF2 object
// when the given result is in the ready state. On any other state, or when the
// presigner is nil, it returns "".
//
// SECURITY: The key is re-derived from the agent's verified tenant identity
// (r.TenantID, populated from the DB row whose tenant_id matches the
// JWT-bound identity) and the caller-validated sourceHash. GuardStorageKey
// validates the derived key before it reaches the presigner. The agent MUST
// NOT presign or construct any storage key itself.
func (h *FontAgentHandler) presignWoff2GetURL(ctx context.Context, r FontTranscodeResult, sourceHash string) string {
	if r.State != FontTranscodeReady || h.presigner == nil {
		return ""
	}
	// Re-derive the key from verified inputs. Defense in depth: never forward
	// r.Woff2Key from the DB row directly to the presigner — re-derive it from
	// the tenant identity + validated hash so the key is always tenant-scoped
	// and matches the shape the worker produced.
	woff2Key := font.DeriveWoff2Key(r.TenantID, sourceHash)
	if err := font.GuardStorageKey(woff2Key); err != nil {
		// Programming error: derived key failed guard. Degrade gracefully;
		// agent retries on the next build.
		_ = err
		return ""
	}
	url, err := h.presigner.PresignGet(ctx, woff2Key, h.presignTTL)
	if err != nil {
		// Non-fatal: storage connectivity issue. Agent retries on next build.
		return ""
	}
	return url
}
