package agentcmd

// This file is the AUTHORITATIVE CP->agent command contract for the File Manager
// feature (P1 read-only). The wp-agent-engineer mirrors these shapes exactly in
// the PHP agent's file_list / file_read / file_download_prepare command handlers.
// Field names are JSON wire names (snake_case); do not rename without updating
// both sides.
//
// Transport — file_list:
//   POST {site_url}/wp-json/wpmgr/v1/command/file_list
//   Header: Authorization: Bearer <minted EdDSA JWT>
//           cmd="file_list", aud=<siteId>
//   Body:   application/json — FileListRequest below.
//   Response: 200 with FileListResponse.
//
// Transport — file_read:
//   POST {site_url}/wp-json/wpmgr/v1/command/file_read
//   Header: Authorization: Bearer <minted EdDSA JWT>
//           cmd="file_read", aud=<siteId>
//   Body:   application/json — FileReadRequest below.
//   Response: 200 with FileReadResponse; ok=false = semantic failure.
//   Error codes: invalid_path | outside_root | not_found | not_readable |
//                is_directory | too_large | sensitive_denied
//
// Transport — file_download_prepare:
//   POST {site_url}/wp-json/wpmgr/v1/command/file_download_prepare
//   Header: Authorization: Bearer <minted EdDSA JWT>
//           cmd="file_download_prepare", aud=<siteId>
//   Body:   application/json — FileDownloadPrepareRequest below.
//   Response: 200 with FileDownloadPrepareResponse.
//
// Security invariants (binding for the agent implementation):
//   1. Every path is run through the realpath+strncmp containment guard before
//      any FS operation — reject ../ segments, NUL bytes, symlinks and
//      absolute paths that resolve outside the configured root jail.
//   2. Sensitive paths (wp-config.php, .env*, *.pem, *.key, id_rsa*, .git/,
//      .htpasswd, auth.json) require confirm_sensitive=true; absent that the
//      agent MUST return error_code="sensitive_denied".
//   3. JWT jti is single-use; each file_* command is a distinct cmd claim so
//      a captured file_read token cannot perform file_list or file_download_prepare.

// ---------------------------------------------------------------------------
// file_list
// ---------------------------------------------------------------------------

// FileListRequest is the POST body for the `file_list` command.
//
//	path   — site-relative forward-slash path to the directory to list.
//	         Must be within the configured root jail; the agent rejects escapes.
//	cursor — optional opaque resume cursor returned by a prior FileListResponse
//	         whose truncated=true. Nil / absent means start from the beginning.
type FileListRequest struct {
	Path   string  `json:"path"`
	Cursor *string `json:"cursor,omitempty"`
}

// FileEntry is one entry in a directory listing returned by `file_list`.
//
//	name        — filename (basename only, no directory component).
//	size        — file size in bytes. 0 for directories.
//	mtime       — last-modified time as Unix epoch seconds.
//	mode        — 4-digit octal permission string, e.g. "0644" or "0755".
//	is_dir      — true when the entry is a directory.
//	is_link     — true when the entry is a symlink. The agent never follows symlinks;
//	              is_link=true means the link itself is reported, not its target.
//	is_writable — true when the agent process can write to this entry.
type FileEntry struct {
	Name       string `json:"name"`
	Size       int64  `json:"size"`
	Mtime      int64  `json:"mtime"`
	Mode       string `json:"mode"`
	IsDir      bool   `json:"is_dir"`
	IsLink     bool   `json:"is_link"`
	IsWritable bool   `json:"is_writable"`
}

// FileListResponse is the agent's response to the `file_list` command.
//
//	path      — absolute resolved path (echoed back so the caller can confirm).
//	entries   — the current page of directory entries (never nil; empty slice when dir is empty).
//	total     — total count of entries in the directory (before cursor truncation).
//	truncated — true when more entries remain beyond this page.
//	cursor    — opaque resume cursor; present only when truncated=true.
//	error     — agent error struct present when ok=false.
type FileListResponse struct {
	Path      string      `json:"path"`
	Entries   []FileEntry `json:"entries"`
	Total     int         `json:"total"`
	Truncated bool        `json:"truncated"`
	Cursor    *string     `json:"cursor,omitempty"`
	Error     *FileError  `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_read
// ---------------------------------------------------------------------------

// FileReadMaxBytes is the default and maximum inline read cap (256 KiB). The
// agent MUST enforce this cap even when the caller omits max_bytes; the CP
// always sends the cap explicitly. Larger files must be served via
// file_download_prepare.
const FileReadMaxBytes = 262144

// FileReadRequest is the POST body for the `file_read` command.
//
//	path              — site-relative path to the file to read.
//	max_bytes         — byte cap on returned content. Defaults to FileReadMaxBytes
//	                    when 0 or absent; agent MUST cap at FileReadMaxBytes regardless.
//	confirm_sensitive — must be true when path matches the sensitive-file deny-list.
//	                    Absent / false = agent returns error_code="sensitive_denied".
type FileReadRequest struct {
	Path             string `json:"path"`
	MaxBytes         int    `json:"max_bytes,omitempty"`
	ConfirmSensitive bool   `json:"confirm_sensitive,omitempty"`
}

// FileReadResponse is the agent's response to the `file_read` command.
//
//	path           — echoed back.
//	size           — full file size in bytes (before any byte-cap truncation).
//	mtime          — last-modified time as Unix epoch seconds.
//	mode           — human-readable permission string.
//	encoding       — always "base64" in v1.
//	content_base64 — base64-encoded file content up to max_bytes. Empty when ok=false.
//	truncated      — true when the file was larger than max_bytes; the client
//	                 should offer a download path for the full file.
//	error          — agent error struct present when ok=false.
type FileReadResponse struct {
	Path          string     `json:"path"`
	Size          int64      `json:"size"`
	Mtime         int64      `json:"mtime"`
	Mode          string     `json:"mode"`
	Encoding      string     `json:"encoding"`
	ContentBase64 string     `json:"content_base64,omitempty"`
	Truncated     bool       `json:"truncated"`
	Error         *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_download_prepare
// ---------------------------------------------------------------------------

// FileDownloadPresignedPut is one presigned PUT slot the CP has minted for the
// agent to upload a chunk of the staged download object.
//
//	index — zero-based part index matching the agent's chunk ordering.
//	url   — S3 presigned PUT URL. Short-lived (≤5 min TTL). Single-use.
type FileDownloadPresignedPut struct {
	Index int    `json:"index"`
	URL   string `json:"url"`
}

// FileDownloadPrepareRequest is the POST body for the `file_download_prepare`
// command.
//
//	path           — site-relative path to the file (or directory) to stage.
//	presigned_puts — the CP-minted presigned PUT slots for each chunk.
//	               The CP resolves the exact count from the agent's response
//	               in a two-phase flow (not yet implemented; v1 sends a fixed
//	               overcount and the agent stops early when done).
//	part_size      — expected chunk size in bytes (matches the CP's S3 multipart config).
type FileDownloadPrepareRequest struct {
	Path          string                    `json:"path"`
	PresignedPuts []FileDownloadPresignedPut `json:"presigned_puts"`
	PartSize      int                       `json:"part_size"`
}

// FileDownloadPart is the completion record for one uploaded chunk.
//
//	index — echoes the presigned_puts slot index.
//	etag  — S3 ETag returned by the presigned PUT.
//	size  — bytes uploaded in this part.
type FileDownloadPart struct {
	Index int    `json:"index"`
	ETag  string `json:"etag"`
	Size  int64  `json:"size"`
}

// FileDownloadPrepareResponse is the agent's response to the
// `file_download_prepare` command.
//
//	object_key  — the S3 key prefix the agent wrote to (derived from the CP-
//	              minted presigned URLs; echoed for cross-check).
//	size        — total staged bytes.
//	chunk_count — number of parts uploaded.
//	parts       — per-part completion records for S3 multipart completion.
//	error       — agent error struct present when ok=false.
type FileDownloadPrepareResponse struct {
	ObjectKey  string              `json:"object_key"`
	Size       int64               `json:"size"`
	ChunkCount int                 `json:"chunk_count"`
	Parts      []FileDownloadPart  `json:"parts"`
	Error      *FileError          `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// Agent error envelope
// ---------------------------------------------------------------------------

// FileError is the agent's error envelope returned when a file_* command fails
// at the semantic level (as opposed to a non-2xx HTTP response, which is a
// transport error).
//
// Error codes:
//
//	invalid_path    — the path is malformed (empty, NUL byte, etc.).
//	outside_root    — the resolved path escapes the configured root jail.
//	not_found       — the path does not exist on the filesystem.
//	not_readable    — the agent process cannot read the path.
//	is_directory    — the path is a directory (unexpected for file_read).
//	too_large       — the file exceeds the per-command byte cap.
//	sensitive_denied — the path matches the sensitive-file deny-list and
//	                   confirm_sensitive was absent / false.
type FileError struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}
