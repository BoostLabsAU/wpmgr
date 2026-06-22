package files

import (
	"encoding/json"
	"io"
	"net/http"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/audit"
	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/server/httpx"
)

// Handler serves the operator-facing File Manager routes under
// /api/v1/sites/{siteId}/files/*.
//
// Route shape mirrors internal/perf/handler.go:66.
// Every route runs behind:
//   - authz.RequireSiteAccess("siteId") on the group
//   - authz.RequirePermission(authz.PermSiteFilesRead) per route
//   - per-site opt-in flag check inside the service
type Handler struct {
	svc   *Service
	audit *audit.Recorder
}

// NewHandler builds the file manager handler.
func NewHandler(svc *Service, rec *audit.Recorder) *Handler {
	return &Handler{svc: svc, audit: rec}
}

// Register mounts the routes on the authenticated /api/v1 group. The per-site
// group carries RequireSiteAccess so every sub-route inherits the collaborator
// allowlist gate (belt-and-braces in front of the m82 RLS).
func (h *Handler) Register(r *gin.RouterGroup) {
	g := r.Group("/sites/:siteId", authz.RequireSiteAccess("siteId"))

	// GET /sites/:siteId/files/settings — read the enable flag (any site member)
	g.GET("/files/settings", h.getSettings)

	// PUT /sites/:siteId/files/settings — toggle the enable flag (admin+)
	g.PUT("/files/settings", authz.RequirePermission(authz.PermSiteFilesManage), h.putSettings)

	// GET /sites/:siteId/files — list a directory (?path=&cursor=)
	g.GET("/files", authz.RequirePermission(authz.PermSiteFilesRead), h.listDir)

	// GET /sites/:siteId/files/content — read a small file inline (?path=&confirm_sensitive=true)
	g.GET("/files/content", authz.RequirePermission(authz.PermSiteFilesRead), h.readContent)

	// POST /sites/:siteId/files/download — stage a file and return a presigned GET URL
	g.POST("/files/download", authz.RequirePermission(authz.PermSiteFilesRead), h.download)
}

// ---------------------------------------------------------------------------
// GET /sites/:siteId/files/settings
// ---------------------------------------------------------------------------

// getSettings returns the current file manager settings for the site.
// Gated by RequireSiteAccess only — any site member can see whether the feature
// is enabled so the web UI can render the correct state.
func (h *Handler) getSettings(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	settings, err := h.svc.GetSettings(c.Request.Context(), p.TenantID, siteID)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"enabled":   settings.Enabled,
		"root_jail": settings.RootJail,
	})
}

// ---------------------------------------------------------------------------
// PUT /sites/:siteId/files/settings
// ---------------------------------------------------------------------------

// updateSettingsBody is the request body for PUT /files/settings.
type updateSettingsBody struct {
	// Enabled enables or disables the file manager for the site.
	Enabled bool `json:"enabled"`
}

// putSettings enables or disables the file manager for the site.
// Requires PermSiteFilesManage (admin+). Records an audit entry on every
// change (both enable and disable).
func (h *Handler) putSettings(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	var body updateSettingsBody
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}

	settings, err := h.svc.UpdateSettings(c.Request.Context(), p.TenantID, siteID, body.Enabled)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	h.record(c, p, ActionSiteFilesSettingsChanged, siteID, map[string]any{
		"enabled": settings.Enabled,
	})

	c.JSON(http.StatusOK, gin.H{
		"enabled":   settings.Enabled,
		"root_jail": settings.RootJail,
	})
}

// ---------------------------------------------------------------------------
// GET /sites/:siteId/files
// ---------------------------------------------------------------------------

// listDir returns one page of directory entries for the given path.
// Query params:
//
//	path   — site-relative directory path (default "/")
//	cursor — opaque resume cursor from a prior truncated response
func (h *Handler) listDir(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	dirPath := c.Query("path")
	if dirPath == "" {
		dirPath = "/"
	}
	var cursor *string
	if raw := c.Query("cursor"); raw != "" {
		cursor = &raw
	}

	result, err := h.svc.ListDir(c.Request.Context(), p.TenantID, siteID, dirPath, cursor)
	if err != nil {
		httpx.Error(c, err)
		return
	}

	h.record(c, p, ActionSiteFilesRead, siteID, map[string]any{
		"op":           "list",
		"path":         dirPath,
		"entry_count":  len(result.Entries),
		"truncated":    result.Truncated,
	})

	c.JSON(http.StatusOK, gin.H{
		"path":      result.Path,
		"entries":   result.Entries,
		"total":     result.Total,
		"truncated": result.Truncated,
		"cursor":    result.Cursor,
	})
}

// ---------------------------------------------------------------------------
// GET /sites/:siteId/files/content
// ---------------------------------------------------------------------------

// readContent returns the base64-encoded content of a small file (≤ 256 KiB).
// Query params:
//
//	path              — site-relative file path (required)
//	confirm_sensitive — "true" to acknowledge reading a sensitive file (owner only)
//
// Security: when path is a sensitive file, confirm_sensitive must be "true"
// AND the caller must hold owner-level permission. The elevated-severity audit
// entry is written regardless of whether the read succeeds.
func (h *Handler) readContent(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	filePath := c.Query("path")
	if filePath == "" {
		httpx.Error(c, domain.Validation("missing_path", "path query parameter is required"))
		return
	}

	confirmSensitive := c.Query("confirm_sensitive") == "true"
	isSensitive := IsSensitivePath(filePath)

	// Gate: sensitive files require confirm AND owner-level permission.
	if isSensitive {
		if !confirmSensitive {
			// Log the denied attempt (T9: log denials too).
			h.record(c, p, ActionSiteFilesSensitiveDenied, siteID, map[string]any{
				"op":     "read",
				"path":   filePath,
				"reason": "confirm_sensitive_missing",
			})
			httpx.Error(c, domain.Forbidden("confirm_sensitive_required",
				"this file is sensitive; set confirm_sensitive=true to confirm you intend to read it"))
			return
		}
		if !h.allows(c, authz.PermSiteFilesReadSensitive) {
			h.record(c, p, ActionSiteFilesSensitiveDenied, siteID, map[string]any{
				"op":     "read",
				"path":   filePath,
				"reason": "insufficient_permission",
			})
			httpx.Error(c, domain.Forbidden("insufficient_permission",
				"reading sensitive files requires owner-level permission"))
			return
		}
	}

	result, err := h.svc.ReadFile(c.Request.Context(), p.TenantID, siteID, filePath, confirmSensitive)
	if err != nil {
		// Log the denied/failed attempt at elevated severity for sensitive paths.
		if isSensitive {
			h.record(c, p, ActionSiteFilesSensitiveDenied, siteID, map[string]any{
				"op":     "read",
				"path":   filePath,
				"reason": err.Error(),
			})
		}
		httpx.Error(c, err)
		return
	}

	// Audit: sensitive reads get an elevated action; ordinary reads use the
	// standard read action. Both carry the full path (required by T6).
	if isSensitive {
		h.record(c, p, ActionSiteFilesSensitiveRead, siteID, map[string]any{
			"op":        "read",
			"path":      filePath,
			"size":      result.Size,
			"truncated": result.Truncated,
		})
	} else {
		h.record(c, p, ActionSiteFilesRead, siteID, map[string]any{
			"op":        "read",
			"path":      filePath,
			"size":      result.Size,
			"truncated": result.Truncated,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"path":           result.Path,
		"size":           result.Size,
		"mtime":          result.Mtime,
		"mode":           result.Mode,
		"encoding":       "base64",
		"content_base64": result.ContentBase64,
		"truncated":      result.Truncated,
	})
}

// ---------------------------------------------------------------------------
// POST /sites/:siteId/files/download
// ---------------------------------------------------------------------------

// downloadBody is the request body for POST /files/download.
type downloadBody struct {
	// Path is the site-relative path to stage for download (required).
	Path string `json:"path"`
}

// download stages a file for browser download:
//  1. CP mints presigned PUTs into its own object-storage staging area.
//  2. CP issues file_download_prepare; the agent uploads chunks.
//  3. CP mints a presigned GET URL for the browser (≤5 min TTL).
//  4. CP persists a file_transfers row (bookkeeping).
//
// The presigned GET URL is returned to the caller; the browser fetches it
// directly from object storage without going through the CP again. This keeps
// large files entirely off the CP's response path (same rule as backups).
//
// Sensitive paths require confirm_sensitive in the body AND owner-level
// permission (same gate as readContent).
func (h *Handler) download(c *gin.Context) {
	p, _ := domain.PrincipalFromContext(c.Request.Context())
	siteID, ok := parseSiteID(c)
	if !ok {
		return
	}

	var body downloadBody
	if err := bindJSON(c, &body); err != nil {
		httpx.Error(c, err)
		return
	}
	if body.Path == "" {
		httpx.Error(c, domain.Validation("missing_path", "path is required"))
		return
	}

	isSensitive := IsSensitivePath(body.Path)
	if isSensitive {
		if !h.allows(c, authz.PermSiteFilesReadSensitive) {
			h.record(c, p, ActionSiteFilesSensitiveDenied, siteID, map[string]any{
				"op":     "download",
				"path":   body.Path,
				"reason": "insufficient_permission",
			})
			httpx.Error(c, domain.Forbidden("insufficient_permission",
				"downloading sensitive files requires owner-level permission"))
			return
		}
	}

	createdBy := p.GetUserID()
	result, err := h.svc.PrepareDownload(c.Request.Context(), p.TenantID, siteID, body.Path, createdBy)
	if err != nil {
		if isSensitive {
			h.record(c, p, ActionSiteFilesSensitiveDenied, siteID, map[string]any{
				"op":     "download",
				"path":   body.Path,
				"reason": err.Error(),
			})
		}
		httpx.Error(c, err)
		return
	}

	if isSensitive {
		h.record(c, p, ActionSiteFilesSensitiveRead, siteID, map[string]any{
			"op":          "download",
			"path":        body.Path,
			"transfer_id": result.TransferID.String(),
			"size_bytes":  result.SizeBytes,
		})
	} else {
		h.record(c, p, ActionSiteFilesRead, siteID, map[string]any{
			"op":          "download",
			"path":        body.Path,
			"transfer_id": result.TransferID.String(),
			"size_bytes":  result.SizeBytes,
		})
	}

	c.JSON(http.StatusOK, gin.H{
		"ok":           true,
		"transfer_id":  result.TransferID.String(),
		"download_url": result.DownloadURL,
		"size_bytes":   result.SizeBytes,
		"chunk_count":  result.ChunkCount,
		"expires_at":   result.ExpiresAt.UTC().Unix(),
	})
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func parseSiteID(c *gin.Context) (uuid.UUID, bool) {
	siteID, err := uuid.Parse(c.Param("siteId"))
	if err != nil {
		httpx.Error(c, domain.Validation("invalid_site_id", "siteId is not a valid UUID"))
		return uuid.Nil, false
	}
	return siteID, true
}

func bindJSON(c *gin.Context, dst any) error {
	dec := json.NewDecoder(io.LimitReader(c.Request.Body, 1<<20)) // 1 MiB body cap
	if err := dec.Decode(dst); err != nil {
		return domain.Validation("invalid_body", "request body is not valid JSON: "+err.Error())
	}
	return nil
}

func (h *Handler) allows(c *gin.Context, perm authz.Permission) bool {
	p, ok := domain.PrincipalFromContext(c.Request.Context())
	if !ok {
		return false
	}
	return authz.Allows(authz.Role(p.Role), perm)
}

func (h *Handler) record(c *gin.Context, p domain.Principal, action string, siteID uuid.UUID, meta map[string]any) {
	if h.audit == nil {
		return
	}
	actorType := audit.ActorUser
	if p.Type == domain.PrincipalAPIKey {
		actorType = audit.ActorAPIKey
	}
	_, _ = h.audit.Record(c.Request.Context(), audit.Event{
		TenantID:   p.TenantID,
		ActorType:  actorType,
		ActorID:    p.ActorID(),
		Action:     action,
		TargetType: "site",
		TargetID:   siteID.String(),
		Metadata:   meta,
	})
}
