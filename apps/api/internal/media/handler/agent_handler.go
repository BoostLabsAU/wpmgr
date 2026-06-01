package handler

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"

	"github.com/gin-gonic/gin"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/service"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// maxMediaAgentBody bounds each agent media-callback body. The sync-batch page
// (≤200 attachments) and the presign/status payloads are all small JSON, well
// under the agent middleware's 4 MiB buffer cap.
const maxMediaAgentBody = 4 << 20

// AgentHandler serves the agent-authenticated media callbacks under /agent/v1.
// Every route runs behind the agent Authenticator; the site + tenant come from
// the verified Ed25519 identity on the context (NEVER a client header). Each job
// is re-asserted to that tenant+site in the service before any mutation.
type AgentHandler struct {
	svc *service.Service
}

// NewAgentHandler builds the agent-facing media callback handler.
func NewAgentHandler(svc *service.Service) *AgentHandler {
	return &AgentHandler{svc: svc}
}

// Register mounts the callbacks on the agent-authenticated group.
func (h *AgentHandler) Register(r *gin.RouterGroup) {
	r.POST("/media/sync-batch", h.syncBatch)
	r.POST("/media/sync-finalize", h.syncFinalize)
	r.POST("/media/asset-deleted", h.assetDeleted)
	r.POST("/media/presign", h.presign)
	r.POST("/media/encode-ready", h.encodeReady)
	r.POST("/media/job-status", h.jobStatus)
	r.POST("/media/restore-status", h.restoreStatus)
	// ADR-044: auto-optimize on upload trigger.
	r.POST("/media/auto-optimize", h.autoOptimize)
}

// ---------------------------------------------------------------------------
// agent DTOs
// ---------------------------------------------------------------------------

type syncBatchAttachmentDTO struct {
	WPAttachmentID    int64  `json:"wp_attachment_id"`
	Title             string `json:"title"`
	OriginalPath      string `json:"original_path"`
	OriginalURL       string `json:"original_url"`
	OriginalMime      string `json:"original_mime"`
	OriginalWidth     *int   `json:"original_width"`
	OriginalHeight    *int   `json:"original_height"`
	OriginalSizeBytes int64  `json:"original_size_bytes"`
	// VariantCount: 1 (full) + generated sub-sizes. Drives "Images (incl. thumbs)".
	VariantCount int `json:"variant_count"`
	// SavedBytes: all-variant savings (re-reported at sync to heal optimized rows).
	SavedBytes int64 `json:"saved_bytes"`
}

type syncBatchBody struct {
	JobID       string                   `json:"job_id"`
	Attachments []syncBatchAttachmentDTO `json:"attachments"`
}

type assetDeletedBody struct {
	WPAttachmentID int64 `json:"wp_attachment_id"`
}

type syncFinalizeBody struct {
	JobID string `json:"job_id"`
}

type presignVariantDTO struct {
	Name       string `json:"name"`
	SourceSize int64  `json:"source_size"`
	SourceMime string `json:"source_mime"`
}

type presignBody struct {
	JobID    string              `json:"job_id"`
	Variants []presignVariantDTO `json:"variants"`
}

type encodeReadyBody struct {
	JobID    string              `json:"job_id"`
	Variants []presignVariantDTO `json:"variants"`
}

type jobStatusBody struct {
	JobID            string         `json:"job_id"`
	AppliedVariants  []string       `json:"applied_variants"`
	SizesUnoptimized looseStringMap `json:"sizes_unoptimized"`
	CurrentFormat    string         `json:"current_format"`
	CurrentSizeBytes int64          `json:"current_size_bytes"`
	BytesBefore      *int64         `json:"bytes_before"`
	BytesAfter       *int64         `json:"bytes_after"`
	// SavedBytes is the all-variant savings (sum over every optimized variant of
	// original-minus-optimized). Drives the dashboard "Bytes saved" rollup.
	SavedBytes       *int64         `json:"saved_bytes"`
	CompressionLevel string         `json:"compression_level"`
	TargetFormat     string         `json:"target_format"`
	RewriteStats     map[string]any `json:"rewrite_stats"`
	Error            string         `json:"error"`
}

// looseStringMap is map[string]string that ALSO accepts a JSON array `[]` or
// `null` as an empty map. PHP's json_encode renders an EMPTY associative array
// as `[]` (a JSON array), not `{}` — so the agent's sizes_unoptimized arrives as
// `[]` exactly when every variant optimized successfully. Decoding that into a
// plain Go map fails, 422-ing the (successful!) apply callback and stranding the
// job. Tolerating `[]`/`null` here keeps the success path green.
type looseStringMap map[string]string

func (m *looseStringMap) UnmarshalJSON(b []byte) error {
	t := bytes.TrimSpace(b)
	if len(t) == 0 || string(t) == "null" || string(t) == "[]" {
		*m = map[string]string{}
		return nil
	}
	var mm map[string]string
	if err := json.Unmarshal(b, &mm); err != nil {
		return err
	}
	*m = mm
	return nil
}

type restoreStatusBody struct {
	JobID    string `json:"job_id"`
	Restored bool   `json:"restored"`
	Error    string `json:"error"`
}

// autoOptimizeAttachment mirrors syncBatchAttachmentDTO: the agent sends the
// full attachment row so the CP can upsert it before gating, fixing the
// fresh-upload skip (ADR-044 §fix).
type autoOptimizeAttachment struct {
	WPAttachmentID    int64  `json:"wp_attachment_id"`
	Title             string `json:"title"`
	OriginalPath      string `json:"original_path"`
	OriginalURL       string `json:"original_url"`
	OriginalMime      string `json:"original_mime"`
	OriginalWidth     *int   `json:"original_width"`
	OriginalHeight    *int   `json:"original_height"`
	OriginalSizeBytes int64  `json:"original_size_bytes"`
	VariantCount      int    `json:"variant_count"`
	SavedBytes        int64  `json:"saved_bytes"`
}

// autoOptimizeBody is the POST body for POST /agent/v1/media/auto-optimize (ADR-044).
// The agent sends the debounced, deduped set of newly-uploaded attachments with
// their full metadata so the CP can upsert rows before optimizing.
type autoOptimizeBody struct {
	Attachments []autoOptimizeAttachment `json:"attachments"`
}

// ---------------------------------------------------------------------------
// route handlers
// ---------------------------------------------------------------------------

func (h *AgentHandler) syncBatch(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body syncBatchBody
	if !decode(c, &body) {
		return
	}
	rows := make([]repo.UpsertAssetInput, 0, len(body.Attachments))
	for _, a := range body.Attachments {
		rows = append(rows, repo.UpsertAssetInput{
			WPAttachmentID:    a.WPAttachmentID,
			Title:             a.Title,
			OriginalPath:      a.OriginalPath,
			OriginalURL:       a.OriginalURL,
			OriginalMime:      a.OriginalMime,
			OriginalWidth:     a.OriginalWidth,
			OriginalHeight:    a.OriginalHeight,
			OriginalSizeBytes: a.OriginalSizeBytes,
			VariantCount:      a.VariantCount,
			SavedBytes:        a.SavedBytes,
		})
	}
	n, err := h.svc.HandleSyncBatch(c.Request.Context(), id.TenantID, id.SiteID, service.SyncBatchInput{
		JobID:       body.JobID,
		Attachments: rows,
	})
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"upserted_count": n})
}

func (h *AgentHandler) assetDeleted(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body assetDeletedBody
	if !decode(c, &body) {
		return
	}
	if body.WPAttachmentID <= 0 {
		httpx.Error(c, domain.Validation("invalid_wp_attachment_id", "wp_attachment_id must be a positive integer"))
		return
	}
	if err := h.svc.HandleAssetDeleted(c.Request.Context(), id.TenantID, id.SiteID, body.WPAttachmentID); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *AgentHandler) syncFinalize(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body syncFinalizeBody
	if !decode(c, &body) {
		return
	}
	if body.JobID == "" {
		httpx.Error(c, domain.Validation("invalid_job_id", "job_id is required"))
		return
	}
	if err := h.svc.HandleSyncFinalize(c.Request.Context(), id.TenantID, id.SiteID, body.JobID); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *AgentHandler) presign(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body presignBody
	if !decode(c, &body) {
		return
	}
	variants := make([]service.PresignVariant, 0, len(body.Variants))
	for _, v := range body.Variants {
		variants = append(variants, service.PresignVariant{Name: v.Name, SourceSize: v.SourceSize, SourceMime: v.SourceMime})
	}
	urls, err := h.svc.HandlePresign(c.Request.Context(), id.TenantID, id.SiteID, body.JobID, variants)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"uploads": urls})
}

func (h *AgentHandler) encodeReady(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body encodeReadyBody
	if !decode(c, &body) {
		return
	}
	variants := make([]service.EncodeReadyVariant, 0, len(body.Variants))
	for _, v := range body.Variants {
		variants = append(variants, service.EncodeReadyVariant{Name: v.Name, SourceSize: v.SourceSize, SourceMime: v.SourceMime})
	}
	if err := h.svc.HandleEncodeReady(c.Request.Context(), id.TenantID, id.SiteID, body.JobID, variants); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *AgentHandler) jobStatus(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body jobStatusBody
	if !decode(c, &body) {
		return
	}
	if err := h.svc.HandleApplyStatus(c.Request.Context(), id.TenantID, id.SiteID, body.JobID, service.ApplyStatusInput{
		AppliedVariants:  body.AppliedVariants,
		SizesUnoptimized: map[string]string(body.SizesUnoptimized),
		CurrentFormat:    body.CurrentFormat,
		CurrentSizeBytes: body.CurrentSizeBytes,
		BytesBefore:      body.BytesBefore,
		BytesAfter:       body.BytesAfter,
		SavedBytes:       body.SavedBytes,
		CompressionLevel: body.CompressionLevel,
		TargetFormat:     body.TargetFormat,
		Error:            body.Error,
	}); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *AgentHandler) restoreStatus(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body restoreStatusBody
	if !decode(c, &body) {
		return
	}
	if err := h.svc.HandleRestoreStatus(c.Request.Context(), id.TenantID, id.SiteID, body.JobID, service.RestoreStatusInput{
		Restored: body.Restored,
		Error:    body.Error,
	}); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

func (h *AgentHandler) autoOptimize(c *gin.Context) {
	id, ok := identity(c)
	if !ok {
		return
	}
	var body autoOptimizeBody
	if !decode(c, &body) {
		return
	}
	if len(body.Attachments) == 0 {
		// Agent sent an empty batch — valid no-op; return accepted=0, skipped=0.
		c.JSON(http.StatusOK, gin.H{"accepted": 0, "skipped": 0})
		return
	}
	// Cap the batch BEFORE building the dedup map (security review M1): the body
	// is bounded at 4 MiB but not by attachment count, and HandleAutoOptimize runs
	// queries per attachment BEFORE the rate-limiter applies. The agent self-caps
	// at MaxSyncBatch (200), so this rejects only an abusive/compromised agent
	// attempting pre-limiter DB amplification. Mirrors HandleSyncBatch.
	if len(body.Attachments) > media.MaxSyncBatch {
		httpx.Error(c, domain.Validation("auto_optimize_batch_too_large", "auto-optimize batch exceeds the per-call cap"))
		return
	}
	// Deduplicate by wp_attachment_id (the debounce is best-effort; the agent may
	// ship duplicates across rapid back-to-back flushes). Skip rows with invalid ids.
	seen := make(map[int64]struct{}, len(body.Attachments))
	rows := make([]repo.UpsertAssetInput, 0, len(body.Attachments))
	for _, a := range body.Attachments {
		if a.WPAttachmentID <= 0 {
			continue // skip invalid ids rather than reject the whole batch
		}
		if _, dup := seen[a.WPAttachmentID]; dup {
			continue
		}
		seen[a.WPAttachmentID] = struct{}{}
		rows = append(rows, repo.UpsertAssetInput{
			WPAttachmentID:    a.WPAttachmentID,
			Title:             a.Title,
			OriginalPath:      a.OriginalPath,
			OriginalURL:       a.OriginalURL,
			OriginalMime:      a.OriginalMime,
			OriginalWidth:     a.OriginalWidth,
			OriginalHeight:    a.OriginalHeight,
			OriginalSizeBytes: a.OriginalSizeBytes,
			VariantCount:      a.VariantCount,
			SavedBytes:        a.SavedBytes,
		})
	}
	res, err := h.svc.HandleAutoOptimize(c.Request.Context(), id.TenantID, id.SiteID, rows)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"accepted": res.Accepted, "skipped": res.Skipped})
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func identity(c *gin.Context) (agent.Identity, bool) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return agent.Identity{}, false
	}
	return id, true
}

// decode reads the body with a hard cap BEFORE JSON decoding (the size cap
// belongs at the transport boundary), then unmarshals into dst.
func decode(c *gin.Context, dst any) bool {
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, maxMediaAgentBody+1024)
	body, err := io.ReadAll(c.Request.Body)
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "could not read request body or exceeds size cap"))
		return false
	}
	if err := json.Unmarshal(body, dst); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return false
	}
	return true
}
