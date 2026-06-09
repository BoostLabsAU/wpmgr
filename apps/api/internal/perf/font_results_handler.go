package perf

import (
	"context"
	"encoding/json"
	"io"
	"net/http"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// maxFontResultsBody is the size cap for the agent's font results push.
const maxFontResultsBody = 32 << 10 // 32 KiB — enough for a batch of font results

// FontResultsReader is the read-seam for the operator font results list route.
// *Repo satisfies it via ListFontResultsForSite.
type FontResultsReader struct {
	List func(ctx context.Context, tenantID, siteID uuid.UUID, limit, offset int32) ([]FontResultDTO, error)
}

// fontResults handles GET /api/v1/sites/:siteId/perf/fonts.
// Returns a paginated list of font catalog rows for the dashboard.
func (h *Handler) fontResults(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}
	limit, offset := pageParams(c)
	if h.fonts == nil {
		c.JSON(http.StatusOK, gin.H{"items": []FontResultDTO{}})
		return
	}
	items, err := h.fonts.List(c.Request.Context(), p.TenantID, siteID, limit, offset)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if items == nil {
		items = []FontResultDTO{}
	}
	c.JSON(http.StatusOK, gin.H{"items": items})
}

// ---------------------------------------------------------------------------
// Agent path — POST /agent/v1/fonts/results
// ---------------------------------------------------------------------------

// fontResultsAgentRepo is the persistence interface used by FontResultsAgentHandler.
// *Repo satisfies it.
type fontResultsAgentRepo interface {
	UpsertFontResult(ctx context.Context, in UpsertFontResultInput) (FontResult, error)
}

// FontResultsAgentHandler serves the agent-authenticated font results callback
// at POST /agent/v1/fonts/results. The agent pushes one or more font result
// updates (after transcoding/subsetting completes or fails); the CP upserts
// the font_results catalog row for each.
//
// SECURITY: tenant_id + site_id ALWAYS come from the VERIFIED agent identity,
// NEVER from the body. This is the same invariant applied to font_transcode_results
// (lines 115-119 of font_handler.go) and to the media optimizer endpoints.
type FontResultsAgentHandler struct {
	repo fontResultsAgentRepo
}

// NewFontResultsAgentHandler wires the handler.
func NewFontResultsAgentHandler(repo *Repo) *FontResultsAgentHandler {
	return &FontResultsAgentHandler{repo: repo}
}

// Register mounts the route on the agent-authenticated group.
func (h *FontResultsAgentHandler) Register(r *gin.RouterGroup) {
	r.POST("/fonts/results", h.upsertFontResults)
}

// fontResultItem is the per-font element in the agent's push payload.
type fontResultItem struct {
	SourceHash   string `json:"source_hash"`
	Family       string `json:"family,omitempty"`
	SourceFile   string `json:"source_file,omitempty"`
	OriginalExt  string `json:"original_ext,omitempty"`
	OriginalSize int    `json:"original_size"`
	Woff2Size    int    `json:"woff2_size,omitempty"`
	SubsetSize   int    `json:"subset_size,omitempty"`
	UnicodeRange string `json:"unicode_range,omitempty"`
	// State is the agent-reported lifecycle: pending|ready|subset|negative.
	State       string `json:"state"`
	ErrorDetail string `json:"error_detail,omitempty"`
}

// fontResultsRequest is the body of POST /agent/v1/fonts/results.
type fontResultsRequest struct {
	Results []fontResultItem `json:"results"`
}

// upsertFontResults handles POST /agent/v1/fonts/results.
//
// CONTRACT (authoritative — match this exactly, agent eng):
//
//   - Auth: Ed25519 signed-request middleware (agent group). Site + tenant come
//     from the VERIFIED identity, NEVER the body.
//   - Request: application/json — fontResultsRequest (array of fontResultItem).
//   - Response 200: {"ok": true, "stored": N} where N is items successfully upserted.
//   - Response 400: invalid body, empty results, or invalid source_hash.
//   - Response 401: agent identity missing.
//
// The CP computes savings_pct at upsert: 1 - min(woff2_size, subset_size) / original_size.
// Items with an invalid source_hash are skipped (counted in "skipped" in the response).
// Items where state is not a known value are stored as-is; the DB CHECK constraint
// rejects truly unknown values at the persistence layer.
func (h *FontResultsAgentHandler) upsertFontResults(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}

	body, err := io.ReadAll(io.LimitReader(c.Request.Body, maxFontResultsBody))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "could not read request body"))
		return
	}
	var req fontResultsRequest
	if err := json.Unmarshal(body, &req); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON: "+err.Error()))
		return
	}
	if len(req.Results) == 0 {
		httpx.Error(c, domain.Validation("empty_results", "results must not be empty"))
		return
	}

	ctx := c.Request.Context()
	stored := 0
	skipped := 0

	for _, item := range req.Results {
		// Validate source_hash: must be exactly 64 lowercase hex chars (BLAKE3).
		if !validFontSourceHash(item.SourceHash) {
			skipped++
			continue
		}

		// SECURITY: tenant_id + site_id from the verified agent identity, not the body.
		_, uErr := h.repo.UpsertFontResult(ctx, UpsertFontResultInput{
			TenantID:     id.TenantID,
			SiteID:       id.SiteID,
			SourceHash:   item.SourceHash,
			Family:       item.Family,
			SourceFile:   item.SourceFile,
			OriginalExt:  item.OriginalExt,
			OriginalSize: item.OriginalSize,
			Woff2Size:    item.Woff2Size,
			SubsetSize:   item.SubsetSize,
			UnicodeRange: item.UnicodeRange,
			State:        FontResultState(item.State),
			ErrorDetail:  item.ErrorDetail,
		})
		if uErr != nil {
			// Non-fatal: log the error but continue processing the remaining items.
			// The agent will retry on the next build cycle.
			skipped++
			continue
		}
		stored++
	}

	c.JSON(http.StatusOK, gin.H{
		"ok":      true,
		"stored":  stored,
		"skipped": skipped,
	})
}

// validFontSourceHash reports whether h is exactly 64 lowercase hex characters.
// Duplicates the logic from the media/font package to avoid an import cycle
// (font_results_handler.go is in the perf package, not media/font).
func validFontSourceHash(h string) bool {
	if len(h) != 64 {
		return false
	}
	for _, ch := range h {
		if !((ch >= '0' && ch <= '9') || (ch >= 'a' && ch <= 'f')) {
			return false
		}
	}
	return true
}
