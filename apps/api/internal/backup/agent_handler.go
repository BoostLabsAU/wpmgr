package backup

import (
	"encoding/json"
	"net/http"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// AgentHandler serves the agent-authenticated backup callbacks under /agent/v1.
// Every route runs behind the agent Authenticator; the site/tenant come from
// the verified Ed25519 identity on the context (NEVER a client header). The
// snapshot path param is always re-validated against that tenant scope, so an
// agent can only act on its own tenant's in-flight snapshots.
type AgentHandler struct {
	svc   *Service
	audit *audit.Recorder
}

// NewAgentHandler builds the agent-facing backup callback handler.
func NewAgentHandler(svc *Service, rec *audit.Recorder) *AgentHandler {
	return &AgentHandler{svc: svc, audit: rec}
}

// Register mounts the agent callbacks on the given group (already wrapped with
// the agent Authenticator middleware).
func (h *AgentHandler) Register(r *gin.RouterGroup) {
	r.POST("/backups/:snapshotId/presign", h.presign)
	r.POST("/backups/:snapshotId/manifest", h.manifest)
	// M5.6 / ADR-032: the phpbu agent runner POSTs phase progress here on every
	// stage transition + per-chunk during the custom PresignedS3 Sync.
	r.POST("/backups/:snapshotId/progress", h.progress)
	// ADR-048: the incremental agent GETs the previous snapshot's NDJSON file index.
	r.GET("/backups/:snapshotId/file-index", h.fileIndex)
}

// presign returns presigned PUT URLs for the candidate ciphertext chunk hashes
// that are NOT already stored for the tenant (incremental dedup). The s3 keys
// are content-addressed and tenant-namespaced, so a presign can never target
// another tenant's chunk prefix.
func (h *AgentHandler) presign(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}
	snapshotID, ok := uuidParam(c, "snapshotId", "invalid_snapshot_id")
	if !ok {
		return
	}
	var req agentcmd.PresignChunksRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if err := h.assertSnapshotSite(c, id.TenantID, snapshotID, id.SiteID); err != nil {
		httpx.Error(c, err)
		return
	}
	uploads, err := h.svc.PresignChunks(c.Request.Context(), id.TenantID, snapshotID, req.Hashes)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, agentcmd.PresignChunksResponse{
		Uploads:    uploads,
		TTLSeconds: h.svc.presignTTLSeconds(),
	})
}

// manifestPeek is the minimal shape we decode first to detect is_incremental
// before choosing the routing path.
type manifestPeek struct {
	IsIncremental bool `json:"is_incremental"`
}

// manifest records the agent-submitted manifest: it upserts not-yet-stored
// chunks, increments refcounts for every reference, inserts manifest entries,
// and completes the snapshot.
//
// ADR-048: when the raw body contains is_incremental=true the handler routes
// to service.SubmitIncrementalManifest. All other submissions go through the
// existing service.SubmitManifest path unchanged.
func (h *AgentHandler) manifest(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}
	snapshotID, ok := uuidParam(c, "snapshotId", "invalid_snapshot_id")
	if !ok {
		return
	}
	// Read and buffer the body so we can peek at is_incremental then re-decode
	// into the appropriate struct. The body is bounded by the agent's own size
	// constraints and is not public-internet input, so a 32 MiB cap is safe.
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, 32<<20)
	var raw json.RawMessage
	if err := c.ShouldBindJSON(&raw); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	if err := h.assertSnapshotSite(c, id.TenantID, snapshotID, id.SiteID); err != nil {
		httpx.Error(c, err)
		return
	}
	var peek manifestPeek
	_ = json.Unmarshal(raw, &peek)

	if peek.IsIncremental {
		var incReq agentcmd.IncrementalSubmitManifestRequest
		if err := json.Unmarshal(raw, &incReq); err != nil {
			httpx.Error(c, domain.Validation("invalid_body", "incremental manifest body is not valid JSON"))
			return
		}
		chunkRefs, stored, err := h.svc.SubmitIncrementalManifest(c.Request.Context(), id.TenantID, snapshotID, incReq)
		if err != nil {
			httpx.Error(c, err)
			return
		}
		h.recordCompleted(c, id.TenantID, snapshotID, id.SiteID, chunkRefs, stored)
		c.JSON(http.StatusOK, agentcmd.SubmitManifestResponse{
			OK:          true,
			ChunkCount:  chunkRefs,
			StoredCount: stored,
		})
		return
	}

	// Full-backup path (unchanged).
	var req agentcmd.SubmitManifestRequest
	if err := json.Unmarshal(raw, &req); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON"))
		return
	}
	chunkRefs, stored, err := h.svc.SubmitManifest(c.Request.Context(), id.TenantID, snapshotID, req)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	h.recordCompleted(c, id.TenantID, snapshotID, id.SiteID, chunkRefs, stored)
	c.JSON(http.StatusOK, agentcmd.SubmitManifestResponse{
		OK:          true,
		ChunkCount:  chunkRefs,
		StoredCount: stored,
	})
}

// fileIndex streams the backup_file_index rows for a snapshot as NDJSON.
// ADR-048: GET /agent/v1/backups/:snapshotId/file-index
//
// The snapshotId in the URL is the PARENT snapshot (the prior completed run
// whose index the agent needs to compute the diff for the current run).
//
// Returns 200 with Content-Type: application/x-ndjson on success.
// Returns 204 when the snapshot's file index exceeds the soft cap (2M rows)
// so the agent AUTO-BASEs instead of streaming.
// Returns 404 when the snapshot is not found or doesn't belong to this agent's site.
func (h *AgentHandler) fileIndex(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}
	snapshotID, ok := uuidParam(c, "snapshotId", "invalid_snapshot_id")
	if !ok {
		return
	}

	// Load the PARENT snapshot once: it gates the request to this agent's site
	// AND tells us whether to serve the chain-MERGED effective tree (gen>=1
	// increment) or the single-snapshot index (gen-0 base / legacy non-chain).
	parent, err := h.svc.repo.GetSnapshot(c.Request.Context(), id.TenantID, snapshotID)
	if err != nil {
		httpx.Error(c, err)
		return
	}
	if parent.SiteID != id.SiteID {
		httpx.Error(c, domain.Forbidden("snapshot_site_mismatch", "the snapshot does not belong to this site"))
		return
	}

	// A gen-0 base (or a legacy non-chain snapshot) serves its own index, which
	// IS the full effective tree. A chained increment must serve the merged
	// view of generations 0..parent.Generation so the agent diffs against the
	// live file set (the same view a restore would reconstruct), not just this
	// increment's delta rows. This is the exact base-vs-chain predicate used by
	// reachableChunks and PlanRestore.
	isBase := parent.ChainID == nil || (parent.Generation == 0 && !parent.IsIncremental)

	if isBase {
		// Soft cap over this snapshot's own row count.
		count, cerr := h.svc.repo.CountFileIndex(c.Request.Context(), id.TenantID, snapshotID)
		if cerr != nil {
			httpx.Error(c, cerr)
			return
		}
		if count > fileIndexSoftCap {
			c.Status(http.StatusNoContent)
			return
		}

		c.Header("Content-Type", "application/x-ndjson")
		c.Status(http.StatusOK)
		enc := json.NewEncoder(c.Writer)
		var rowsWritten int
		streamErr := h.svc.repo.StreamFileIndex(c.Request.Context(), id.TenantID, snapshotID, func(e FileIndexEntry) error {
			if encErr := enc.Encode(fileIndexRow(e)); encErr != nil {
				return encErr
			}
			rowsWritten++
			if rowsWritten%1000 == 0 {
				c.Writer.Flush()
			}
			return nil
		})
		if streamErr != nil {
			// The response headers are already sent; we can only log here.
			_ = streamErr
		}
		c.Writer.Flush()
		return
	}

	// Chained increment: build the MERGED effective tree (gen 0..parent.Generation),
	// then apply the soft cap over the MERGED path count (the single-snapshot
	// CountFileIndex would under-count this increment's own delta rows). The merge
	// already materialises the surviving set in memory (bounded by the live file
	// count), so collecting once and counting is a single DB pass.
	merged := make([]FileIndexEntry, 0)
	collectErr := h.svc.repo.StreamChainEffectiveFileIndex(
		c.Request.Context(), id.TenantID, *parent.ChainID, parent.Generation,
		func(e FileIndexEntry) error {
			merged = append(merged, e)
			return nil
		},
	)
	if collectErr != nil {
		httpx.Error(c, collectErr)
		return
	}
	if int64(len(merged)) > fileIndexSoftCap {
		c.Status(http.StatusNoContent)
		return
	}

	c.Header("Content-Type", "application/x-ndjson")
	c.Status(http.StatusOK)
	enc := json.NewEncoder(c.Writer)
	for i, e := range merged {
		if encErr := enc.Encode(fileIndexRow(e)); encErr != nil {
			// Headers already sent; nothing to do but stop.
			break
		}
		// Flush every 1000 rows so the agent can start processing early.
		if (i+1)%1000 == 0 {
			c.Writer.Flush()
		}
	}
	c.Writer.Flush()
}

// fileIndexRow is the NDJSON wire shape the agent consumes (a SUBSET of
// FileIndexEntry): file_path, file_size, file_mtime, file_blake3, chunk_hashes,
// is_tombstone. Merged effective-tree entries are non-tombstone by construction.
func fileIndexRow(e FileIndexEntry) any {
	return struct {
		FilePath    string   `json:"file_path"`
		FileSize    int64    `json:"file_size"`
		FileMtime   int64    `json:"file_mtime"`
		FileBlake3  string   `json:"file_blake3"`
		ChunkHashes []string `json:"chunk_hashes"`
		IsTombstone bool     `json:"is_tombstone"`
	}{
		FilePath:    e.FilePath,
		FileSize:    e.FileSize,
		FileMtime:   e.FileMtime,
		FileBlake3:  e.FileBlake3,
		ChunkHashes: e.ChunkHashes,
		IsTombstone: e.IsTombstone,
	}
}

// progressDTO is the agent's progress POST shape. snapshot_id comes from the
// URL path, NEVER from the body — a compromised agent must not be able to
// target another snapshot by spoofing it in the JSON body.
type progressDTO struct {
	Phase       string         `json:"phase"`
	PhaseDetail map[string]any `json:"phase_detail"`
}

// progress records the agent runner's latest phase. The Ed25519 identity on the
// context proves the request comes from the snapshot's own site (re-asserted
// below via assertSnapshotSite). Failures to record progress MUST be visible
// to the agent (the runner uses the response status to decide whether to retry),
// but a 4xx for an unknown phase or oversized body is terminal — the runner
// drops the event and moves on rather than spinning.
func (h *AgentHandler) progress(c *gin.Context) {
	id, ok := agent.IdentityFromContext(c.Request.Context())
	if !ok {
		httpx.Error(c, domain.Unauthorized("agent_unauthenticated", "agent identity required"))
		return
	}
	snapshotID, ok := uuidParam(c, "snapshotId", "invalid_snapshot_id")
	if !ok {
		return
	}
	// Read the body with a hard cap BEFORE JSON decoding. ShouldBindJSON would
	// happily allocate however much memory the agent sends; the size cap belongs
	// at the transport boundary.
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, MaxProgressPayloadBytes+1024)
	var dto progressDTO
	if err := c.ShouldBindJSON(&dto); err != nil {
		httpx.Error(c, domain.Validation("invalid_body", "request body is not valid JSON or exceeds size cap"))
		return
	}
	if err := h.assertSnapshotSite(c, id.TenantID, snapshotID, id.SiteID); err != nil {
		httpx.Error(c, err)
		return
	}
	if _, err := h.svc.RecordProgress(c.Request.Context(), id.TenantID, snapshotID, dto.Phase, dto.PhaseDetail); err != nil {
		httpx.Error(c, err)
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// assertSnapshotSite verifies the snapshot exists in the agent's tenant AND
// belongs to the agent's own site, so a compromised agent cannot manipulate
// another site's snapshot even within its tenant.
func (h *AgentHandler) assertSnapshotSite(c *gin.Context, tenantID, snapshotID, siteID uuid.UUID) error {
	snap, err := h.svc.repo.GetSnapshot(c.Request.Context(), tenantID, snapshotID)
	if err != nil {
		return err
	}
	if snap.SiteID != siteID {
		return domain.Forbidden("snapshot_site_mismatch", "the snapshot does not belong to this site")
	}
	return nil
}

func (h *AgentHandler) recordCompleted(c *gin.Context, tenantID, snapshotID, siteID uuid.UUID, chunkRefs, stored int64) {
	if h.audit == nil {
		return
	}
	_, _ = h.audit.Record(c.Request.Context(), audit.Event{
		TenantID:   tenantID,
		ActorType:  audit.ActorSystem,
		Action:     ActionBackupCompleted,
		TargetType: "backup_snapshot",
		TargetID:   snapshotID.String(),
		Metadata: map[string]any{
			"site_id":      siteID.String(),
			"chunk_refs":   chunkRefs,
			"stored_count": stored,
		},
	})
}
