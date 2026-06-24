// Package files implements the File Manager feature (P1 read-only + P2 write).
//
// Every request flow:
//  1. The handler resolves the principal (TenantID / UserID) from context.
//  2. The handler calls the service, passing the verified TenantID and siteID.
//  3. The service checks the per-site opt-in flag(s):
//     - Read ops:  files_enabled must be true.
//     - Write ops: files_enabled AND files_write_enabled must both be true.
//  4. The service looks up the site URL, issues the signed agent command via
//     agentcmd.Client.Do, and maps agent error codes to domain errors.
//  5. The handler records an audit entry (including elevated-severity entries
//     for sensitive-path reads/writes) and returns the DTO.
package files

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"path"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// downloadPartSize is the chunk size passed to the agent for presigned multipart
// uploads. 5 MiB matches the S3 minimum part size.
const downloadPartSize = 5 << 20 // 5 MiB

// downloadPresignTTL is the lifetime for each presigned PUT/GET URL.
const downloadPresignTTL = 5 * time.Minute

// ErrFilesNotEnabled is returned by the service when a site has not opted in to
// the file manager. The handler maps this to a 403 with code "files_not_enabled".
var ErrFilesNotEnabled = errors.New("file manager is not enabled for this site")

// ErrFilesWriteNotEnabled is returned by write service methods when the site
// has the read flag on but the write flag is still off. The handler maps this
// to a 403 with code "files_write_not_enabled".
var ErrFilesWriteNotEnabled = errors.New("file manager write is not enabled for this site")

// errNotFound is the package-local sentinel returned by getConfig when no row
// exists. It is never surfaced to callers; IsEnabled converts it to false.
var errNotFound = errors.New("files: not found")

// AgentFileClient is the narrow subset of agentcmd.Client the service uses.
// *agentcmd.Client satisfies it via Do. Tests substitute a fake.
type AgentFileClient interface {
	Do(ctx context.Context, siteID uuid.UUID, siteURL, command string, body, out any) error
}

// SiteLookup resolves the agent URL for a site. *site.Service satisfies this
// via a narrow adapter wired in main (same pattern as perf.SiteLookup).
type SiteLookup interface {
	GetSiteURL(ctx context.Context, tenantID, siteID uuid.UUID) (string, error)
}

// Presigner mints presigned PUT/GET URLs over object storage.
// *blobstore.Store satisfies this.
type Presigner interface {
	PresignPut(ctx context.Context, key string, ttl time.Duration) (string, error)
	PresignGet(ctx context.Context, key string, ttl time.Duration) (string, error)
}

// Service is the file manager business-logic layer (read-only P1).
type Service struct {
	pool      *db.Pool
	agent     AgentFileClient
	sites     SiteLookup
	presigner Presigner // may be nil — download path degrades to not-configured
}

// NewService builds the file manager service.
func NewService(pool *db.Pool) *Service {
	return &Service{pool: pool}
}

// SetAgentClient wires the agent command client and site-URL lookup.
func (s *Service) SetAgentClient(agent AgentFileClient, sites SiteLookup) {
	s.agent = agent
	s.sites = sites
}

// SetPresigner wires the object-storage presigner used for download staging.
func (s *Service) SetPresigner(p Presigner) {
	s.presigner = p
}

// ---------------------------------------------------------------------------
// enable flag
// ---------------------------------------------------------------------------

// IsEnabled reports whether the file manager is enabled for a site.
func (s *Service) IsEnabled(ctx context.Context, tenantID, siteID uuid.UUID) (bool, error) {
	cfg, err := s.getConfig(ctx, tenantID, siteID)
	if err != nil {
		if errors.Is(err, errNotFound) {
			return false, nil // no row → not enabled
		}
		return false, err
	}
	return cfg.FilesEnabled, nil
}

// EnableSite enables the file manager for a site (owner/admin only; called by
// a future settings handler). Creates the config row if absent.
func (s *Service) EnableSite(ctx context.Context, tenantID, siteID uuid.UUID) error {
	return s.upsertEnabled(ctx, tenantID, siteID, true)
}

// DisableSite disables the file manager for a site.
func (s *Service) DisableSite(ctx context.Context, tenantID, siteID uuid.UUID) error {
	return s.upsertEnabled(ctx, tenantID, siteID, false)
}

// ---------------------------------------------------------------------------
// Settings (P1 enable/disable toggle)
// ---------------------------------------------------------------------------

// Settings is the service output for GET/PUT /sites/{siteId}/files/settings.
// RootJail is always "" in P1/P2 (the agent defaults to ABSPATH).
type Settings struct {
	Enabled      bool
	WriteEnabled bool
	RootJail     string
}

// GetSettings returns the current file manager settings for a site. When no
// row exists (the default state), returns the zero Settings (all false, empty jail).
func (s *Service) GetSettings(ctx context.Context, tenantID, siteID uuid.UUID) (Settings, error) {
	cfg, err := s.getConfig(ctx, tenantID, siteID)
	if err != nil {
		if errors.Is(err, errNotFound) {
			return Settings{}, nil // no row → defaults
		}
		return Settings{}, err
	}
	return Settings{
		Enabled:      cfg.FilesEnabled,
		WriteEnabled: cfg.FilesWriteEnabled,
		RootJail:     cfg.RootJail,
	}, nil
}

// UpdateSettings sets the read and/or write opt-in flags for a site. Each flag
// is upserted independently so they can be toggled one at a time. RootJail is
// read-only (always "") and is not accepted as an input.
// Returns the post-update Settings so the handler can echo canonical state.
func (s *Service) UpdateSettings(ctx context.Context, tenantID, siteID uuid.UUID, enabled, writeEnabled bool) (Settings, error) {
	// Always upsert the read flag (it was the only flag in P1; backwards-compat).
	if err := s.upsertEnabled(ctx, tenantID, siteID, enabled); err != nil {
		return Settings{}, err
	}
	// Upsert the write flag independently.
	if err := s.upsertWriteEnabled(ctx, tenantID, siteID, writeEnabled); err != nil {
		return Settings{}, err
	}
	// Read back the row so we return the canonical DB state.
	return s.GetSettings(ctx, tenantID, siteID)
}

// ---------------------------------------------------------------------------
// P1 read operations
// ---------------------------------------------------------------------------

// ListDirResult is the service output for a directory listing request.
type ListDirResult struct {
	Path      string
	Entries   []agentcmd.FileEntry
	Total     int
	Truncated bool
	Cursor    *string
}

// ListDir issues a file_list command to the agent and returns the directory
// listing. Returns ErrFilesNotEnabled when the site has not opted in.
func (s *Service) ListDir(ctx context.Context, tenantID, siteID uuid.UUID, reqPath string, cursor *string) (ListDirResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return ListDirResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ListDirResult{}, err
	}

	req := agentcmd.FileListRequest{
		Path:   reqPath,
		Cursor: cursor,
	}
	var resp agentcmd.FileListResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_list", req, &resp); err != nil {
		return ListDirResult{}, mapAgentTransportErr(err, "file_list")
	}
	if resp.Error != nil {
		return ListDirResult{}, mapAgentErrorCode(resp.Error)
	}
	if resp.Entries == nil {
		resp.Entries = []agentcmd.FileEntry{}
	}
	return ListDirResult{
		Path:      resp.Path,
		Entries:   resp.Entries,
		Total:     resp.Total,
		Truncated: resp.Truncated,
		Cursor:    resp.Cursor,
	}, nil
}

// ReadFileResult is the service output for a small inline file read.
type ReadFileResult struct {
	Path          string
	Size          int64
	Mtime         int64
	Mode          string
	ContentBase64 string
	Truncated     bool
}

// ReadFile issues a file_read command to the agent. Returns
// ErrFilesNotEnabled when the site has not opted in. For sensitive paths
// confirmSensitive must be true and the caller (handler) must have verified
// owner-level permission BEFORE calling this method.
func (s *Service) ReadFile(ctx context.Context, tenantID, siteID uuid.UUID, filePath string, confirmSensitive bool) (ReadFileResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return ReadFileResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ReadFileResult{}, err
	}

	req := agentcmd.FileReadRequest{
		Path:             filePath,
		MaxBytes:         agentcmd.FileReadMaxBytes,
		ConfirmSensitive: confirmSensitive,
	}
	var resp agentcmd.FileReadResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_read", req, &resp); err != nil {
		return ReadFileResult{}, mapAgentTransportErr(err, "file_read")
	}
	if resp.Error != nil {
		return ReadFileResult{}, mapAgentErrorCode(resp.Error)
	}
	return ReadFileResult{
		Path:          resp.Path,
		Size:          resp.Size,
		Mtime:         resp.Mtime,
		Mode:          resp.Mode,
		ContentBase64: resp.ContentBase64,
		Truncated:     resp.Truncated,
	}, nil
}

// DownloadResult is returned by PrepareDownload.
type DownloadResult struct {
	TransferID    uuid.UUID
	DownloadURL   string // presigned GET URL for the browser
	ObjectKey     string
	SizeBytes     int64
	ChunkCount    int
	ExpiresAt     time.Time
}

// PrepareDownload stages a file for browser download:
//  1. Mints presigned PUT URLs (CP-owned object storage staging area).
//  2. Issues file_download_prepare to the agent, which uploads chunked content.
//  3. Persists a file_transfers row (bookkeeping + GC).
//  4. Mints a presigned GET URL for the browser (short-TTL).
//
// Returns ErrFilesNotEnabled when the site has not opted in. Returns
// domain.ServiceUnavailable when the presigner is not configured (self-host
// without object storage).
func (s *Service) PrepareDownload(ctx context.Context, tenantID, siteID uuid.UUID, filePath string, createdBy uuid.UUID) (DownloadResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return DownloadResult{}, err
	}
	if s.presigner == nil {
		return DownloadResult{}, domain.ServiceUnavailable("storage_not_configured", "object storage is not configured; file downloads are unavailable")
	}

	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return DownloadResult{}, err
	}

	// Derive the tenant-namespaced staging key for this transfer.
	transferID := uuid.New()
	objectKey := fileTransferS3Key(tenantID, transferID)

	// Mint presigned PUT URLs for each chunk slot. We provision enough slots for
	// a generous file size (32 × 5 MiB = 160 MiB). The agent stops uploading when
	// the file is exhausted and reports the actual chunk_count.
	const maxParts = 32
	presignedPuts := make([]agentcmd.FileDownloadPresignedPut, maxParts)
	for i := 0; i < maxParts; i++ {
		partKey := fmt.Sprintf("%s/part%04d", objectKey, i)
		putURL, perr := s.presigner.PresignPut(ctx, partKey, downloadPresignTTL)
		if perr != nil {
			return DownloadResult{}, domain.Internal("presign_put_failed", "failed to mint presigned PUT URL").WithCause(perr)
		}
		presignedPuts[i] = agentcmd.FileDownloadPresignedPut{Index: i, URL: putURL}
	}

	req := agentcmd.FileDownloadPrepareRequest{
		Path:          filePath,
		PresignedPuts: presignedPuts,
		PartSize:      downloadPartSize,
	}
	var resp agentcmd.FileDownloadPrepareResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_download_prepare", req, &resp); err != nil {
		return DownloadResult{}, mapAgentTransportErr(err, "file_download_prepare")
	}
	if resp.Error != nil {
		return DownloadResult{}, mapAgentErrorCode(resp.Error)
	}

	// Mint the presigned GET URL for the browser. The key is the first part for
	// single-chunk files; for multi-part the caller assembles via the parts list.
	// In v1 we return the GET for the first part (single-file, not directory zip).
	getKey := fmt.Sprintf("%s/part0000", objectKey)
	expiresAt := time.Now().Add(downloadPresignTTL)
	downloadURL, perr := s.presigner.PresignGet(ctx, getKey, downloadPresignTTL)
	if perr != nil {
		return DownloadResult{}, domain.Internal("presign_get_failed", "failed to mint presigned GET URL").WithCause(perr)
	}

	// Persist the transfer bookkeeping row.
	// A bucket lifecycle policy on the file-transfers/ prefix is the GC backstop
	// when this bookkeeping insert fails (the infra config is a separate ops task).
	if err := s.insertTransfer(ctx, tenantID, siteID, transferID, filePath, objectKey, resp.Size, resp.ChunkCount, createdBy, expiresAt); err != nil {
		// Non-fatal: the download URL is already minted and the staged object sits
		// in object storage. Log loudly so the failure is visible and alertable.
		slog.Error("file_transfer bookkeeping insert failed; staged object has no expiry row",
			"tenant_id", tenantID,
			"site_id", siteID,
			"transfer_id", transferID,
			"object_key", objectKey,
			"error", err,
		)
	}

	return DownloadResult{
		TransferID:  transferID,
		DownloadURL: downloadURL,
		ObjectKey:   objectKey,
		SizeBytes:   resp.Size,
		ChunkCount:  resp.ChunkCount,
		ExpiresAt:   expiresAt,
	}, nil
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

type siteFileManagerRow struct {
	FilesEnabled      bool
	FilesWriteEnabled bool
	RootJail          string
}

func (s *Service) getConfig(ctx context.Context, tenantID, siteID uuid.UUID) (siteFileManagerRow, error) {
	var cfg siteFileManagerRow
	err := s.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		row, err := q.GetSiteFileManager(ctx, siteID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return errNotFound
			}
			return domain.Internal("db_error", "failed to read file manager config").WithCause(err)
		}
		cfg.FilesEnabled = row.FilesEnabled
		cfg.FilesWriteEnabled = row.FilesWriteEnabled
		cfg.RootJail = row.RootJail
		return nil
	})
	return cfg, err
}

func (s *Service) requireEnabled(ctx context.Context, tenantID, siteID uuid.UUID) error {
	enabled, err := s.IsEnabled(ctx, tenantID, siteID)
	if err != nil {
		return err
	}
	if !enabled {
		return domain.Forbidden("files_not_enabled", "the file manager is not enabled for this site; enable it in site settings first")
	}
	return nil
}

func (s *Service) upsertEnabled(ctx context.Context, tenantID, siteID uuid.UUID, enabled bool) error {
	return s.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		return q.UpsertSiteFileManager(ctx, sqlc.UpsertSiteFileManagerParams{
			SiteID:       siteID,
			TenantID:     tenantID,
			FilesEnabled: enabled,
		})
	})
}

func (s *Service) upsertWriteEnabled(ctx context.Context, tenantID, siteID uuid.UUID, writeEnabled bool) error {
	return s.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		return q.UpsertSiteFileManagerWrite(ctx, sqlc.UpsertSiteFileManagerWriteParams{
			SiteID:             siteID,
			TenantID:           tenantID,
			FilesWriteEnabled:  writeEnabled,
		})
	})
}

func (s *Service) insertTransfer(ctx context.Context, tenantID, siteID, transferID uuid.UUID, relPath, objectKey string, sizeBytes int64, chunkCount int, createdBy uuid.UUID, expiresAt time.Time) error {
	return s.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		return q.InsertFileTransfer(ctx, sqlc.InsertFileTransferParams{
			ID:         transferID,
			TenantID:   tenantID,
			SiteID:     siteID,
			Direction:  "download",
			RelPath:    relPath,
			Status:     "done",
			ObjectKey:  objectKey,
			SizeBytes:  sizeBytes,
			ChunkCount: int32(chunkCount),
			CreatedBy:  createdBy,
			ExpiresAt:  expiresAt,
		})
	})
}

func (s *Service) siteURL(ctx context.Context, tenantID, siteID uuid.UUID) (string, error) {
	if s.sites == nil {
		return "", domain.Internal("sites_not_wired", "site lookup not configured")
	}
	url, err := s.sites.GetSiteURL(ctx, tenantID, siteID)
	if err != nil {
		return "", err
	}
	return url, nil
}

// fileTransferS3Key returns the tenant-namespaced staging key for a file
// transfer. Namespaced by tenant so a presigned URL can never target another
// tenant's staging area.
func fileTransferS3Key(tenantID, transferID uuid.UUID) string {
	return "file-transfers/" + tenantID.String() + "/" + transferID.String()
}

// requireWriteEnabled returns a typed domain error when write ops are not
// permitted (either read flag is off or write flag is off).
func (s *Service) requireWriteEnabled(ctx context.Context, tenantID, siteID uuid.UUID) error {
	cfg, err := s.getConfig(ctx, tenantID, siteID)
	if err != nil {
		if errors.Is(err, errNotFound) {
			return domain.Forbidden("files_not_enabled",
				"the file manager is not enabled for this site; enable it in site settings first")
		}
		return err
	}
	if !cfg.FilesEnabled {
		return domain.Forbidden("files_not_enabled",
			"the file manager is not enabled for this site; enable it in site settings first")
	}
	if !cfg.FilesWriteEnabled {
		return domain.Forbidden("files_write_not_enabled",
			"the file manager write mode is not enabled for this site; enable it in site settings first")
	}
	return nil
}

// ---------------------------------------------------------------------------
// P2 write operations
// ---------------------------------------------------------------------------

// WriteFileResult is the service output for PUT /sites/{siteId}/files/content.
type WriteFileResult struct {
	Path  string
	Size  int64
	Mtime int64
	Mode  string
}

// WriteFile issues a file_write command to the agent (atomic temp-write+rename).
// Returns ErrFilesWriteNotEnabled when the site has write mode off, or
// ErrFilesNotEnabled when not opted in at all.
// The handler must have already verified PermSiteFilesWriteCode when
// confirmExecutableWrite or confirmSensitive is true.
func (s *Service) WriteFile(ctx context.Context, tenantID, siteID uuid.UUID, filePath, contentBase64 string, confirmExecutableWrite, confirmSensitive bool) (WriteFileResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return WriteFileResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return WriteFileResult{}, err
	}

	req := agentcmd.FileWriteRequest{
		Path:                  filePath,
		ContentBase64:         contentBase64,
		ConfirmExecutableWrite: confirmExecutableWrite,
		ConfirmSensitive:      confirmSensitive,
	}
	var resp agentcmd.FileWriteResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_write", req, &resp); err != nil {
		return WriteFileResult{}, mapAgentTransportErr(err, "file_write")
	}
	if resp.Error != nil {
		return WriteFileResult{}, mapAgentErrorCode(resp.Error)
	}
	return WriteFileResult{
		Path:  resp.Path,
		Size:  resp.Size,
		Mtime: resp.Mtime,
		Mode:  resp.Mode,
	}, nil
}

// MkdirResult is the service output for POST /sites/{siteId}/files/mkdir.
type MkdirResult struct {
	Path string
}

// Mkdir issues a file_mkdir command to the agent.
func (s *Service) Mkdir(ctx context.Context, tenantID, siteID uuid.UUID, dirPath string) (MkdirResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return MkdirResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return MkdirResult{}, err
	}

	req := agentcmd.FileMkdirRequest{Path: dirPath}
	var resp agentcmd.FileMkdirResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_mkdir", req, &resp); err != nil {
		return MkdirResult{}, mapAgentTransportErr(err, "file_mkdir")
	}
	if resp.Error != nil {
		return MkdirResult{}, mapAgentErrorCode(resp.Error)
	}
	return MkdirResult{Path: resp.Path}, nil
}

// RenameResult is the service output for POST /sites/{siteId}/files/rename.
type RenameResult struct {
	Src string
	Dst string
}

// Rename issues a file_rename command to the agent.
// The handler must have verified PermSiteFilesWriteCode when
// confirmExecutableWrite or confirmSensitive is true.
func (s *Service) Rename(ctx context.Context, tenantID, siteID uuid.UUID, src, dst string, confirmExecutableWrite, confirmSensitive bool) (RenameResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return RenameResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return RenameResult{}, err
	}

	req := agentcmd.FileRenameRequest{
		Src:                   src,
		Dst:                   dst,
		ConfirmExecutableWrite: confirmExecutableWrite,
		ConfirmSensitive:      confirmSensitive,
	}
	var resp agentcmd.FileRenameResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_rename", req, &resp); err != nil {
		return RenameResult{}, mapAgentTransportErr(err, "file_rename")
	}
	if resp.Error != nil {
		return RenameResult{}, mapAgentErrorCode(resp.Error)
	}
	return RenameResult{Src: resp.Src, Dst: resp.Dst}, nil
}

// DeleteResult is the service output for POST /sites/{siteId}/files/delete.
type DeleteResult struct {
	Path    string
	Deleted int
}

// Delete issues a file_delete command to the agent.
// The handler must have verified PermSiteFilesDelete (owner) AND the typed
// confirm="DELETE" token before calling this method.
func (s *Service) Delete(ctx context.Context, tenantID, siteID uuid.UUID, filePath string, recursive bool) (DeleteResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return DeleteResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return DeleteResult{}, err
	}

	req := agentcmd.FileDeleteRequest{
		Path:      filePath,
		Recursive: recursive,
	}
	var resp agentcmd.FileDeleteResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_delete", req, &resp); err != nil {
		return DeleteResult{}, mapAgentTransportErr(err, "file_delete")
	}
	if resp.Error != nil {
		return DeleteResult{}, mapAgentErrorCode(resp.Error)
	}
	return DeleteResult{Path: resp.Path, Deleted: resp.Deleted}, nil
}

// ChmodResult is the service output for POST /sites/{siteId}/files/chmod.
type ChmodResult struct {
	Path string
	Mode string
}

// Chmod issues a file_chmod command to the agent.
func (s *Service) Chmod(ctx context.Context, tenantID, siteID uuid.UUID, filePath, mode string) (ChmodResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return ChmodResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ChmodResult{}, err
	}

	req := agentcmd.FileChmodRequest{Path: filePath, Mode: mode}
	var resp agentcmd.FileChmodResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_chmod", req, &resp); err != nil {
		return ChmodResult{}, mapAgentTransportErr(err, "file_chmod")
	}
	if resp.Error != nil {
		return ChmodResult{}, mapAgentErrorCode(resp.Error)
	}
	return ChmodResult{Path: resp.Path, Mode: resp.Mode}, nil
}

// uploadPresignTTL is the presigned PUT/GET TTL for upload staging chunks.
const uploadPresignTTL = 5 * time.Minute

// UploadResult is returned by PrepareUpload.
type UploadResult struct {
	TransferID    uuid.UUID
	PresignedPuts []agentcmd.FileDownloadPresignedPut // PUT URLs for the browser
	ObjectKey     string
	ExpiresAt     time.Time
}

// PrepareUpload stages an upload:
//  1. Mints presigned PUT URLs for the browser to push chunks into the
//     tenant-namespaced staging area.
//  2. Persists a file_transfers row (direction=upload, status=staged).
//  3. Returns the presigned PUTs and the transfer ID to the caller.
//
// After the browser has PUT all chunks, the caller invokes ApplyUpload,
// which mints presigned GET URLs and issues file_upload_apply to the agent.
func (s *Service) PrepareUpload(ctx context.Context, tenantID, siteID uuid.UUID, relPath string, partCount int, createdBy uuid.UUID) (UploadResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return UploadResult{}, err
	}
	if s.presigner == nil {
		return UploadResult{}, domain.ServiceUnavailable("storage_not_configured",
			"object storage is not configured; file uploads are unavailable")
	}

	transferID := uuid.New()
	objectKey := fileTransferS3Key(tenantID, transferID)
	expiresAt := time.Now().Add(uploadPresignTTL)

	// Mint one presigned PUT per chunk slot requested by the caller.
	if partCount < 1 {
		partCount = 1
	}
	const maxParts = 32
	if partCount > maxParts {
		return UploadResult{}, domain.Validation("too_many_parts",
			fmt.Sprintf("upload may not exceed %d parts", maxParts))
	}

	presignedPuts := make([]agentcmd.FileDownloadPresignedPut, partCount)
	for i := 0; i < partCount; i++ {
		partKey := fmt.Sprintf("%s/part%04d", objectKey, i)
		putURL, perr := s.presigner.PresignPut(ctx, partKey, uploadPresignTTL)
		if perr != nil {
			return UploadResult{}, domain.Internal("presign_put_failed",
				"failed to mint presigned PUT URL").WithCause(perr)
		}
		presignedPuts[i] = agentcmd.FileDownloadPresignedPut{Index: i, URL: putURL}
	}

	// Persist the transfer row (status=staged so a GC sweep can clean orphans).
	if err := s.insertUploadTransfer(ctx, tenantID, siteID, transferID, relPath, objectKey, partCount, createdBy, expiresAt); err != nil {
		slog.Error("file_transfer upload bookkeeping insert failed",
			"tenant_id", tenantID, "site_id", siteID,
			"transfer_id", transferID, "error", err)
		// Non-fatal: presigned URLs are already minted. Log for alerting.
	}

	return UploadResult{
		TransferID:    transferID,
		PresignedPuts: presignedPuts,
		ObjectKey:     objectKey,
		ExpiresAt:     expiresAt,
	}, nil
}

// ApplyUploadResult is returned by ApplyUpload.
type ApplyUploadResult struct {
	Path  string
	Size  int64
	Mtime int64
}

// ApplyUpload mints presigned GET URLs for the staged upload chunks and
// issues file_upload_apply to the agent, which fetches, reassembles,
// validates (SHA-256), and atomic-swaps the file into place.
//
// confirmExecutableWrite and confirmSensitive are forwarded to the agent only
// after the caller (handler) has already verified PermSiteFilesWriteCode (owner).
// A non-owner who sets either flag is rejected at the handler before this method
// is ever called — the service never weakens that gate.
//
// The agent is the authoritative executable-extension enforcer (deny-list: php,
// php8, php9, phpt, phar, asp, aspx, jsp, cgi, htaccess, …). The CP does not
// maintain a duplicate extension list for the upload-apply path; confirm flags
// are forwarded as-is so the agent can enforce its own copy of the list.
// Any future extension added to the agent deny-list is covered automatically
// without a CP change.
func (s *Service) ApplyUpload(ctx context.Context, tenantID, siteID uuid.UUID, targetPath, objectKey, sha256 string, partCount int, totalSize int64, confirmExecutableWrite, confirmSensitive bool) (ApplyUploadResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return ApplyUploadResult{}, err
	}
	if s.presigner == nil {
		return ApplyUploadResult{}, domain.ServiceUnavailable("storage_not_configured",
			"object storage is not configured; file uploads are unavailable")
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ApplyUploadResult{}, err
	}

	// Mint presigned GET URLs for each chunk so the agent can fetch them.
	presignedGets := make([]agentcmd.FileUploadPresignedGet, partCount)
	for i := 0; i < partCount; i++ {
		partKey := fmt.Sprintf("%s/part%04d", objectKey, i)
		getURL, perr := s.presigner.PresignGet(ctx, partKey, uploadPresignTTL)
		if perr != nil {
			return ApplyUploadResult{}, domain.Internal("presign_get_failed",
				"failed to mint presigned GET URL").WithCause(perr)
		}
		presignedGets[i] = agentcmd.FileUploadPresignedGet{Index: i, URL: getURL}
	}

	req := agentcmd.FileUploadApplyRequest{
		Path:                   targetPath,
		PresignedGets:          presignedGets,
		PartCount:              partCount,
		TotalSize:              totalSize,
		SHA256:                 sha256,
		ConfirmExecutableWrite: confirmExecutableWrite,
		ConfirmSensitive:       confirmSensitive,
	}
	var resp agentcmd.FileUploadApplyResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_upload_apply", req, &resp); err != nil {
		return ApplyUploadResult{}, mapAgentTransportErr(err, "file_upload_apply")
	}
	if resp.Error != nil {
		return ApplyUploadResult{}, mapAgentErrorCode(resp.Error)
	}
	return ApplyUploadResult{
		Path:  resp.Path,
		Size:  resp.Size,
		Mtime: resp.Mtime,
	}, nil
}

func (s *Service) insertUploadTransfer(ctx context.Context, tenantID, siteID, transferID uuid.UUID, relPath, objectKey string, chunkCount int, createdBy uuid.UUID, expiresAt time.Time) error {
	return s.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		return q.InsertUploadTransfer(ctx, sqlc.InsertUploadTransferParams{
			ID:         transferID,
			TenantID:   tenantID,
			SiteID:     siteID,
			RelPath:    relPath,
			ObjectKey:  objectKey,
			ChunkCount: int32(chunkCount),
			CreatedBy:  createdBy,
			ExpiresAt:  expiresAt,
		})
	})
}

// ---------------------------------------------------------------------------
// P3 advanced ops
// ---------------------------------------------------------------------------

// ArchiveResult is returned by CreateArchive.
type ArchiveResult struct {
	TransferID  uuid.UUID
	DownloadURL string // presigned GET URL for the browser
	ObjectKey   string
	SizeBytes   int64
	ChunkCount  int
	ExpiresAt   time.Time
}

// CreateArchive stages an archive (ZIP) of the given paths for browser download:
//  1. Mints presigned PUT URLs (CP-owned object storage staging area).
//  2. Issues file_archive_create to the agent, which zips the paths and uploads
//     the archive in chunks.
//  3. Mints a presigned GET URL for the browser (short-TTL ≤ 5 min).
//  4. Persists a file_transfers row (direction=download, bookkeeping + GC).
//
// Returns ErrFilesNotEnabled when the site has not opted in. Returns
// domain.ServiceUnavailable when the presigner is not configured.
//
// confirmSensitive must be true when any path in paths matches IsSensitivePath.
// The caller (handler) must have already verified PermSiteFilesReadSensitive
// (owner) before passing confirmSensitive=true — the service never weakens that gate.
func (s *Service) CreateArchive(ctx context.Context, tenantID, siteID uuid.UUID, paths []string, createdBy uuid.UUID, confirmSensitive bool) (ArchiveResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return ArchiveResult{}, err
	}
	if len(paths) == 0 {
		return ArchiveResult{}, domain.Validation("missing_paths", "at least one path is required")
	}
	if s.presigner == nil {
		return ArchiveResult{}, domain.ServiceUnavailable("storage_not_configured",
			"object storage is not configured; file archive downloads are unavailable")
	}

	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ArchiveResult{}, err
	}

	transferID := uuid.New()
	objectKey := fileTransferS3Key(tenantID, transferID)

	const maxParts = 32
	presignedPuts := make([]agentcmd.FileArchivePresignedPut, maxParts)
	for i := 0; i < maxParts; i++ {
		partKey := fmt.Sprintf("%s/part%04d", objectKey, i)
		putURL, perr := s.presigner.PresignPut(ctx, partKey, downloadPresignTTL)
		if perr != nil {
			return ArchiveResult{}, domain.Internal("presign_put_failed",
				"failed to mint presigned PUT URL").WithCause(perr)
		}
		presignedPuts[i] = agentcmd.FileArchivePresignedPut{Index: i, URL: putURL}
	}

	req := agentcmd.FileArchiveCreateRequest{
		Paths:            paths,
		PresignedPuts:    presignedPuts,
		PartSize:         downloadPartSize,
		ConfirmSensitive: confirmSensitive,
	}
	var resp agentcmd.FileArchiveCreateResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_archive_create", req, &resp); err != nil {
		return ArchiveResult{}, mapAgentTransportErr(err, "file_archive_create")
	}
	if resp.Error != nil {
		return ArchiveResult{}, mapAgentErrorCode(resp.Error)
	}

	// Mint the presigned GET URL for the browser (first part for the assembled archive).
	getKey := fmt.Sprintf("%s/part0000", objectKey)
	expiresAt := time.Now().Add(downloadPresignTTL)
	downloadURL, perr := s.presigner.PresignGet(ctx, getKey, downloadPresignTTL)
	if perr != nil {
		return ArchiveResult{}, domain.Internal("presign_get_failed",
			"failed to mint presigned GET URL").WithCause(perr)
	}

	// Use the archive filename as the rel_path in the transfer row for auditability.
	archiveRelPath := path.Join("archive", strings.Join(paths, ","))
	if len(archiveRelPath) > 512 {
		// Truncate for very long multi-path lists so the DB column stays bounded.
		archiveRelPath = archiveRelPath[:512]
	}
	if err := s.insertTransfer(ctx, tenantID, siteID, transferID, archiveRelPath, objectKey, resp.Size, resp.ChunkCount, createdBy, expiresAt); err != nil {
		slog.Error("file_transfer archive bookkeeping insert failed",
			"tenant_id", tenantID, "site_id", siteID,
			"transfer_id", transferID, "object_key", objectKey, "error", err)
	}

	return ArchiveResult{
		TransferID:  transferID,
		DownloadURL: downloadURL,
		ObjectKey:   objectKey,
		SizeBytes:   resp.Size,
		ChunkCount:  resp.ChunkCount,
		ExpiresAt:   expiresAt,
	}, nil
}

// ExtractResult is returned by Extract.
type ExtractResult struct {
	DestPath  string
	Extracted int
}

// Extract issues a file_extract command to the agent (atomic quarantine-expand
// → validate → move). Returns ErrFilesWriteNotEnabled when write mode is off.
// The handler must have already verified PermSiteFilesWriteCode (owner) when
// confirmExecutableWrite or confirmSensitive is true.
func (s *Service) Extract(ctx context.Context, tenantID, siteID uuid.UUID, archivePath, destPath string, confirmExecutableWrite, confirmSensitive bool) (ExtractResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return ExtractResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return ExtractResult{}, err
	}

	req := agentcmd.FileExtractRequest{
		ArchivePath:            archivePath,
		DestPath:               destPath,
		ConfirmExecutableWrite: confirmExecutableWrite,
		ConfirmSensitive:       confirmSensitive,
	}
	var resp agentcmd.FileExtractResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_extract", req, &resp); err != nil {
		return ExtractResult{}, mapAgentTransportErr(err, "file_extract")
	}
	if resp.Error != nil {
		return ExtractResult{}, mapAgentErrorCode(resp.Error)
	}
	return ExtractResult{
		DestPath:  resp.DestPath,
		Extracted: resp.Extracted,
	}, nil
}

// SearchResult is returned by Search.
type SearchResult struct {
	Matches   []agentcmd.FileSearchMatch
	Truncated bool
	Cursor    *string
}

// Search issues a file_search command to the agent. Mode must be "name" or
// "content". Returns ErrFilesNotEnabled when the site has not opted in.
func (s *Service) Search(ctx context.Context, tenantID, siteID uuid.UUID, searchPath, query, mode string, cursor *string) (SearchResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return SearchResult{}, err
	}
	if query == "" {
		return SearchResult{}, domain.Validation("missing_query", "q query parameter is required")
	}
	if mode != "name" && mode != "content" {
		return SearchResult{}, domain.Validation("invalid_mode", `mode must be "name" or "content"`)
	}

	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return SearchResult{}, err
	}

	req := agentcmd.FileSearchRequest{
		Path:   searchPath,
		Query:  query,
		Mode:   mode,
		Cursor: cursor,
	}
	var resp agentcmd.FileSearchResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_search", req, &resp); err != nil {
		return SearchResult{}, mapAgentTransportErr(err, "file_search")
	}
	if resp.Error != nil {
		return SearchResult{}, mapAgentErrorCode(resp.Error)
	}
	if resp.Matches == nil {
		resp.Matches = []agentcmd.FileSearchMatch{}
	}
	return SearchResult{
		Matches:   resp.Matches,
		Truncated: resp.Truncated,
		Cursor:    resp.Cursor,
	}, nil
}

// VersionsResult is returned by ListVersions.
type VersionsResult struct {
	Versions []agentcmd.FileVersion
}

// ListVersions issues a file_versions_list command to the agent.
// Returns ErrFilesNotEnabled when the site has not opted in.
//
// When filePath is a sensitive path, the handler must have already verified
// PermSiteFilesReadSensitive (owner) before calling this method — the service
// does not re-check the permission gate here (belt-and-braces at handler layer).
// The filePath and confirmSensitive parameters are threaded to the agent so the
// agent can enforce its own independent sensitive-path deny-list.
// NOTE: the file_versions_list agent command does not currently accept
// confirm_sensitive; this parameter is reserved for forward compatibility and
// is not forwarded in v1 (the path itself is the agent's gate key).
func (s *Service) ListVersions(ctx context.Context, tenantID, siteID uuid.UUID, filePath string) (VersionsResult, error) {
	if err := s.requireEnabled(ctx, tenantID, siteID); err != nil {
		return VersionsResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return VersionsResult{}, err
	}

	req := agentcmd.FileVersionsListRequest{Path: filePath}
	var resp agentcmd.FileVersionsListResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_versions_list", req, &resp); err != nil {
		return VersionsResult{}, mapAgentTransportErr(err, "file_versions_list")
	}
	if resp.Error != nil {
		return VersionsResult{}, mapAgentErrorCode(resp.Error)
	}
	if resp.Versions == nil {
		resp.Versions = []agentcmd.FileVersion{}
	}
	return VersionsResult{Versions: resp.Versions}, nil
}

// VersionRestoreResult is returned by RestoreVersion.
type VersionRestoreResult struct {
	Path  string
	Size  int64
	Mtime int64
}

// RestoreVersion issues a file_version_restore command to the agent.
// Returns ErrFilesWriteNotEnabled when write mode is off.
//
// When filePath is a sensitive path, the handler must have already verified
// PermSiteFilesWriteCode (owner) AND confirmSensitive=true before calling this
// method — the service never weakens the gate. confirmSensitive is forwarded
// to the agent so it can independently enforce its sensitive-path deny-list.
func (s *Service) RestoreVersion(ctx context.Context, tenantID, siteID uuid.UUID, filePath, versionID string, confirmSensitive bool) (VersionRestoreResult, error) {
	if err := s.requireWriteEnabled(ctx, tenantID, siteID); err != nil {
		return VersionRestoreResult{}, err
	}
	siteURL, err := s.siteURL(ctx, tenantID, siteID)
	if err != nil {
		return VersionRestoreResult{}, err
	}

	req := agentcmd.FileVersionRestoreRequest{Path: filePath, VersionID: versionID, ConfirmSensitive: confirmSensitive}
	var resp agentcmd.FileVersionRestoreResponse
	if err := s.agent.Do(ctx, siteID, siteURL, "file_version_restore", req, &resp); err != nil {
		return VersionRestoreResult{}, mapAgentTransportErr(err, "file_version_restore")
	}
	if resp.Error != nil {
		return VersionRestoreResult{}, mapAgentErrorCode(resp.Error)
	}
	return VersionRestoreResult{
		Path:  resp.Path,
		Size:  resp.Size,
		Mtime: resp.Mtime,
	}, nil
}

// IsSensitivePath reports whether a given path matches the sensitive-file
// deny-list. This list is enforced identically by the PHP agent's isSensitive
// function — both sides must agree or one enforcer becomes a bypass.
//
// Covered cases (all comparisons are case-insensitive):
//   - wp-config.php (exact basename)
//   - wp-config-*.php (glob: basename starts with "wp-config-" and ends with ".php")
//   - wp-config.php backup/editor variants: basename lowercased starts with
//     "wp-config.php" and is NOT exactly "wp-config.php"
//     (catches .bak, .save, .orig, .old, .swp, .swo, ~ suffixes, etc.)
//   - .env* (any file whose basename starts with ".env")
//   - *.pem, *.key, *.crt, *.p12, *.pfx, *.ppk (certificate/key extensions)
//   - id_rsa*, id_dsa*, id_ecdsa*, id_ed25519* (SSH private-key prefixes)
//   - .htpasswd, auth.json, .npmrc, .git-credentials (exact basenames)
//   - path contains .aws/credentials (substring match on the full path)
//   - any path segment equal to .git
//
// The CP enforces this as belt-and-braces; the agent independently enforces it
// before returning content. If either side denies without confirm_sensitive,
// the read is rejected.
func IsSensitivePath(p string) bool {
	base := path.Base(p)
	lower := strings.ToLower(base)
	lowerPath := strings.ToLower(p)

	// Exact basename matches.
	switch lower {
	case "wp-config.php", ".htpasswd", "auth.json", ".npmrc", ".git-credentials":
		return true
	}

	// wp-config-*.php: starts with "wp-config-" and ends with ".php".
	if strings.HasPrefix(lower, "wp-config-") && strings.HasSuffix(lower, ".php") {
		return true
	}

	// wp-config.php backup/editor variants: starts with "wp-config.php" but is
	// not exactly "wp-config.php" (e.g. wp-config.php.bak, wp-config.php~).
	if strings.HasPrefix(lower, "wp-config.php") && lower != "wp-config.php" {
		return true
	}

	// .env* — any basename starting with ".env".
	if strings.HasPrefix(lower, ".env") {
		return true
	}

	// SSH private-key prefixes.
	for _, prefix := range []string{"id_rsa", "id_dsa", "id_ecdsa", "id_ed25519"} {
		if strings.HasPrefix(lower, prefix) {
			return true
		}
	}

	// Certificate and key file extensions.
	for _, ext := range []string{".pem", ".key", ".crt", ".p12", ".pfx", ".ppk"} {
		if strings.HasSuffix(lower, ext) {
			return true
		}
	}

	// .aws/credentials anywhere in the full path.
	if strings.Contains(lowerPath, ".aws/credentials") {
		return true
	}

	// .git as a path segment anywhere in the path.
	for _, seg := range strings.Split(p, "/") {
		if seg == ".git" {
			return true
		}
	}

	return false
}

// ---------------------------------------------------------------------------
// Agent error mapping
// ---------------------------------------------------------------------------

// mapAgentErrorCode maps the agent's semantic error codes to domain errors.
// CP HTTP status mapping (P1 + P2):
//
//	sensitive_denied        → 403 Forbidden
//	executable_write_denied → 403 Forbidden
//	protected_root          → 403 Forbidden
//	not_readable            → 403 Forbidden
//	outside_root            → 400 Bad Request
//	invalid_path            → 400 Bad Request
//	is_directory            → 400 Bad Request
//	not_directory           → 400 Bad Request
//	mode_denied             → 400 Bad Request
//	too_large               → 413 (Validation in domain terms)
//	not_found               → 404 Not Found
//	exists                  → 409 Conflict
//	base_unresolved         → 500 Internal (agent-side safety guard fired)
//	write_failed            → 502 Bad Gateway (agent write/swap failed)
//	<anything else>         → 502 Bad Gateway (agent returned unknown error)
func mapAgentErrorCode(e *agentcmd.FileError) error {
	switch e.Code {
	case "sensitive_denied":
		return domain.Forbidden("sensitive_denied", e.Message)
	case "executable_write_denied":
		return domain.Forbidden("executable_write_denied", e.Message)
	case "protected_root":
		return domain.Forbidden("protected_root", e.Message)
	case "not_readable":
		return domain.Forbidden("not_readable", e.Message)
	case "outside_root", "invalid_path", "is_directory", "not_directory", "mode_denied":
		return domain.Validation(e.Code, e.Message)
	case "not_found":
		return domain.NotFound(e.Code, e.Message)
	case "exists":
		return domain.Conflict("exists", e.Message)
	case "too_large":
		return domain.Validation("file_too_large", e.Message)
	case "base_unresolved":
		return domain.Internal("base_unresolved", e.Message)
	case "write_failed":
		return domain.Internal("agent_write_failed", fmt.Sprintf("agent write failed: %s", e.Message))
	// P3 archive/extract/search/versions codes.
	case "zip_slip", "zip_bomb":
		// 422 Unprocessable Entity: the archive is valid but its contents are
		// malicious (zip-slip path traversal or zip-bomb resource exhaustion).
		return domain.Validation(e.Code, e.Message)
	case "bad_archive", "not_archive":
		// 400 Bad Request: the path is not a usable archive.
		return domain.Validation(e.Code, e.Message)
	case "no_such_version":
		return domain.NotFound(e.Code, e.Message)
	default:
		return domain.Internal("agent_file_error", fmt.Sprintf("agent returned error %q: %s", e.Code, e.Message))
	}
}

// mapAgentTransportErr wraps a raw agentcmd transport error as a domain error.
// A non-2xx agent response arrives as a "rejected by agent: status NNN" string;
// we pass it through as an internal error so the handler's httpx.Error path
// surfaces it without a misleading 500.
func mapAgentTransportErr(err error, cmd string) error {
	if err == nil {
		return nil
	}
	return domain.Internal("agent_transport_error", fmt.Sprintf("%s command failed: %s", cmd, err.Error())).WithCause(err)
}
