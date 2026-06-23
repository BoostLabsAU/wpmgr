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
// file_write  (P2)
// ---------------------------------------------------------------------------

// FileWriteRequest is the POST body for the `file_write` command.
//
// The agent writes content_base64 atomically (temp-write → rename swap) to
// the resolved path, which must be within the configured root jail.
//
//	path                   — site-relative path to create or overwrite.
//	content_base64         — base64-encoded file content (≤ 256 KiB).
//	confirm_executable_write — must be true when the target path or name
//	                         matches the executable-extension deny-list or is
//	                         within a web-served PHP-executable directory.
//	                         Absent / false → agent returns error_code=
//	                         "executable_write_denied".  The CP must also
//	                         verify the caller holds PermSiteFilesWriteCode.
//	confirm_sensitive      — must be true when the target path matches the
//	                         sensitive-file deny-list (wp-config.php, .env*, …).
//	                         Absent / false → agent returns "sensitive_denied".
//	                         The CP must also verify PermSiteFilesWriteCode.
type FileWriteRequest struct {
	Path                  string `json:"path"`
	ContentBase64         string `json:"content_base64"`
	ConfirmExecutableWrite bool   `json:"confirm_executable_write,omitempty"`
	ConfirmSensitive      bool   `json:"confirm_sensitive,omitempty"`
}

// FileWriteResponse is the agent's response to `file_write`.
//
//	path  — echoed resolved path.
//	size  — bytes written.
//	mtime — last-modified time (Unix epoch seconds) after the write.
//	mode  — 4-digit octal string, e.g. "0644".
//	error — agent error struct present on failure.
type FileWriteResponse struct {
	Path  string     `json:"path"`
	Size  int64      `json:"size"`
	Mtime int64      `json:"mtime"`
	Mode  string     `json:"mode"`
	Error *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_mkdir  (P2)
// ---------------------------------------------------------------------------

// FileMkdirRequest is the POST body for the `file_mkdir` command.
//
//	path — site-relative path for the new directory.  The agent applies the
//	       same containment guard as all other file_* commands.
type FileMkdirRequest struct {
	Path string `json:"path"`
}

// FileMkdirResponse is the agent's response to `file_mkdir`.
//
//	path  — resolved path of the created directory (echoed).
//	error — agent error struct present on failure.
type FileMkdirResponse struct {
	Path  string     `json:"path"`
	Error *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_rename  (P2)
// ---------------------------------------------------------------------------

// FileRenameRequest is the POST body for the `file_rename` command.
//
// Both src and dst are checked against the containment guard — the agent
// must reject any operation where either path resolves outside the jail.
//
//	src                    — site-relative source path (must exist).
//	dst                    — site-relative destination path (must not exist
//	                         unless the agent supports atomic overwrite, which
//	                         is implementation-defined for the PHP side).
//	confirm_executable_write — required when dst matches the executable-
//	                           extension deny-list or a PHP-executable dir.
//	confirm_sensitive      — required when either src or dst is a sensitive path.
type FileRenameRequest struct {
	Src                   string `json:"src"`
	Dst                   string `json:"dst"`
	ConfirmExecutableWrite bool   `json:"confirm_executable_write,omitempty"`
	ConfirmSensitive      bool   `json:"confirm_sensitive,omitempty"`
}

// FileRenameResponse is the agent's response to `file_rename`.
//
//	src   — echoed source path.
//	dst   — echoed destination path.
//	error — agent error struct present on failure.
type FileRenameResponse struct {
	Src   string     `json:"src"`
	Dst   string     `json:"dst"`
	Error *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_delete  (P2)
// ---------------------------------------------------------------------------

// FileDeleteRequest is the POST body for the `file_delete` command.
//
// The CP checks the typed confirm token AND PermSiteFilesDelete (owner) before
// issuing this command. The agent independently enforces its own path-jail and
// protected-root guards.
//
//	path      — site-relative path to delete.
//	recursive — when true, delete a non-empty directory and all its contents.
//	            When false, the agent refuses to delete a non-empty directory
//	            (returns error_code="not_directory" / "exists").
type FileDeleteRequest struct {
	Path      string `json:"path"`
	Recursive bool   `json:"recursive,omitempty"`
}

// FileDeleteResponse is the agent's response to `file_delete`.
//
//	path    — echoed path.
//	deleted — number of filesystem entries removed (1 for a file; ≥1 for
//	          a recursive directory removal).
//	error   — agent error struct present on failure.
type FileDeleteResponse struct {
	Path    string     `json:"path"`
	Deleted int        `json:"deleted"`
	Error   *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_chmod  (P2)
// ---------------------------------------------------------------------------

// FileChmodRequest is the POST body for the `file_chmod` command.
//
// The agent MUST validate mode against a safe allowlist — no setuid (4xxx),
// no setgid (2xxx), no sticky-on-file (1xxx for files), no world-write (?x2).
// Refuse modes that would make the target world-writable.
//
//	path — site-relative path.
//	mode — 4-digit octal string, e.g. "0644" or "0755".
type FileChmodRequest struct {
	Path string `json:"path"`
	Mode string `json:"mode"`
}

// FileChmodResponse is the agent's response to `file_chmod`.
//
//	path  — echoed path.
//	mode  — effective mode after the chmod (echoed back).
//	error — agent error struct present on failure.
type FileChmodResponse struct {
	Path  string     `json:"path"`
	Mode  string     `json:"mode"`
	Error *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_upload_apply  (P2)
// ---------------------------------------------------------------------------

// FileUploadPresignedGet is one presigned GET slot the CP has minted for the
// agent to fetch a staged upload chunk from object storage.
//
//	index — zero-based part index.
//	url   — S3 presigned GET URL. Short-lived (≤5 min TTL). Single-use.
type FileUploadPresignedGet struct {
	Index int    `json:"index"`
	URL   string `json:"url"`
}

// FileUploadApplyRequest is the POST body for the `file_upload_apply` command.
//
// The agent fetches each chunk from the presigned GET URLs, reassembles them
// in order into a temp file, validates the SHA-256 digest, and atomic-swaps
// the temp file into the target path (same FilesRestorer swap primitive used
// by restore).
//
//	path                     — site-relative target path (containment-guarded).
//	presigned_gets           — the CP-minted presigned GET slots for each chunk.
//	part_count               — total number of chunks (must match len(presigned_gets)).
//	total_size               — expected assembled size in bytes (agent verifies before swap).
//	sha256                   — hex-encoded SHA-256 of the assembled content (agent verifies
//	                           before swap; mismatch → "write_failed").
//	confirm_executable_write — must be true when the target path matches the
//	                           executable-extension deny-list or is inside a
//	                           PHP-executable directory.  Absent / false → agent
//	                           returns error_code="executable_write_denied".  The CP
//	                           must also verify PermSiteFilesWriteCode (owner) before
//	                           setting this field; a non-owner caller is rejected at the
//	                           CP before any agent call is issued.
//	confirm_sensitive        — must be true when the target path matches the
//	                           sensitive-file deny-list (wp-config.php, .env*, …).
//	                           Absent / false → agent returns "sensitive_denied".
//	                           The CP must also verify PermSiteFilesWriteCode (owner).
type FileUploadApplyRequest struct {
	Path                   string                   `json:"path"`
	PresignedGets          []FileUploadPresignedGet  `json:"presigned_gets"`
	PartCount              int                      `json:"part_count"`
	TotalSize              int64                    `json:"total_size"`
	SHA256                 string                   `json:"sha256"`
	ConfirmExecutableWrite bool                     `json:"confirm_executable_write,omitempty"`
	ConfirmSensitive       bool                     `json:"confirm_sensitive,omitempty"`
}

// FileUploadApplyResponse is the agent's response to `file_upload_apply`.
//
//	path  — echoed resolved path.
//	size  — bytes written (should match total_size).
//	mtime — last-modified time after the swap (Unix epoch seconds).
//	error — agent error struct present on failure.
type FileUploadApplyResponse struct {
	Path  string     `json:"path"`
	Size  int64      `json:"size"`
	Mtime int64      `json:"mtime"`
	Error *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_archive_create  (P3)
// ---------------------------------------------------------------------------

// FileArchivePresignedPut is one presigned PUT slot the CP has minted for the
// agent to upload a chunk of the archive it creates.
//
// Identical shape to FileDownloadPresignedPut — reused so the agent can use the
// same multipart-upload helper for both commands.
type FileArchivePresignedPut struct {
	Index int    `json:"index"`
	URL   string `json:"url"`
}

// FileArchiveCreateRequest is the POST body for the `file_archive_create` command.
//
// The agent zips the listed paths into a temporary archive, uploads it in
// chunks to the CP-minted presigned PUT URLs, then removes the temp archive.
// The CP assembles the parts and mints a presigned GET for the browser.
//
//	paths             — slice of site-relative paths to include in the archive.
//	                    The agent runs each through the containment guard; paths
//	                    that escape the jail are skipped (or cause an error, per
//	                    the agent's mode).
//	presigned_puts    — CP-minted presigned PUT slots for each chunk (same shape as
//	                    FileDownloadPrepareRequest.presigned_puts).
//	part_size         — expected chunk size in bytes (matches the CP's S3 multipart config).
//	confirm_sensitive — must be true when any path in `paths` matches the sensitive-file
//	                    deny-list (wp-config.php, .env*, *.pem, …). The CP verifies
//	                    PermSiteFilesReadSensitive (owner) AND the request flag before
//	                    issuing this command; the agent independently re-checks and
//	                    returns "sensitive_denied" when absent / false.
type FileArchiveCreateRequest struct {
	Paths            []string                 `json:"paths"`
	PresignedPuts    []FileArchivePresignedPut `json:"presigned_puts"`
	PartSize         int                      `json:"part_size"`
	ConfirmSensitive bool                     `json:"confirm_sensitive,omitempty"`
}

// FileArchivePart is the completion record for one uploaded archive chunk.
type FileArchivePart struct {
	Index int    `json:"index"`
	ETag  string `json:"etag"`
	Size  int64  `json:"size"`
}

// FileArchiveCreateResponse is the agent's response to `file_archive_create`.
//
//	object_key  — the S3 key prefix the agent wrote to (derived from the CP-minted
//	              presigned URLs; echoed for cross-check).
//	size        — total archive bytes staged.
//	chunk_count — number of parts uploaded.
//	parts       — per-part completion records for S3 multipart completion.
//	error       — agent error struct present when an error occurred.
type FileArchiveCreateResponse struct {
	ObjectKey  string            `json:"object_key"`
	Size       int64             `json:"size"`
	ChunkCount int               `json:"chunk_count"`
	Parts      []FileArchivePart `json:"parts"`
	Error      *FileError        `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_extract  (P3)
// ---------------------------------------------------------------------------

// FileExtractRequest is the POST body for the `file_extract` command.
//
// The agent opens the archive at archive_path (within the jail), validates each
// entry (reject zip-slip, zip-bomb, absolute paths, symlinks, device files),
// extracts into dest_path, and removes the archive on success.
//
//	archive_path            — site-relative path to the ZIP archive to extract.
//	dest_path               — site-relative destination directory. Created if absent.
//	confirm_executable_write — must be true if any archive entry resolves to an
//	                           executable-extension path. Absent / false → the
//	                           agent returns "executable_write_denied". The CP
//	                           must also verify PermSiteFilesWriteCode (owner).
//	confirm_sensitive       — must be true if any archive entry resolves to a
//	                           sensitive path. Absent / false → "sensitive_denied".
//	                           The CP must also verify PermSiteFilesWriteCode.
type FileExtractRequest struct {
	ArchivePath            string `json:"archive_path"`
	DestPath               string `json:"dest_path"`
	ConfirmExecutableWrite bool   `json:"confirm_executable_write,omitempty"`
	ConfirmSensitive       bool   `json:"confirm_sensitive,omitempty"`
}

// FileExtractResponse is the agent's response to `file_extract`.
//
//	dest_path  — echoed (or resolved) destination directory.
//	extracted  — count of entries extracted.
//	error      — agent error struct on failure.
type FileExtractResponse struct {
	DestPath  string     `json:"dest_path"`
	Extracted int        `json:"extracted"`
	Error     *FileError `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_search  (P3)
// ---------------------------------------------------------------------------

// FileSearchRequest is the POST body for the `file_search` command.
//
//	path   — site-relative directory to search under (recursive).
//	query  — search term.
//	mode   — "name" (match filenames) or "content" (grep file contents).
//	cursor — opaque resume cursor from a prior truncated response.
type FileSearchRequest struct {
	Path   string  `json:"path"`
	Query  string  `json:"query"`
	Mode   string  `json:"mode"`
	Cursor *string `json:"cursor,omitempty"`
}

// FileSearchMatch is one result in a file_search response.
//
//	path    — site-relative path of the matching file or directory.
//	name    — basename of the entry.
//	size    — file size in bytes (0 for directories).
//	mtime   — last-modified time as Unix epoch seconds.
//	is_dir  — true when the entry is a directory.
//	line    — line number of the match (content mode only; 0 for name mode).
//	snippet — surrounding text context (content mode only; empty for name mode).
type FileSearchMatch struct {
	Path    string `json:"path"`
	Name    string `json:"name"`
	Size    int64  `json:"size"`
	Mtime   int64  `json:"mtime"`
	IsDir   bool   `json:"is_dir"`
	Line    int    `json:"line,omitempty"`
	Snippet string `json:"snippet,omitempty"`
}

// FileSearchResponse is the agent's response to `file_search`.
//
//	matches   — the current page of search results.
//	truncated — true when more results remain beyond this page.
//	cursor    — opaque resume cursor; present only when truncated=true.
//	error     — agent error struct on failure.
type FileSearchResponse struct {
	Matches   []FileSearchMatch `json:"matches"`
	Truncated bool              `json:"truncated"`
	Cursor    *string           `json:"cursor,omitempty"`
	Error     *FileError        `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_versions_list  (P3)
// ---------------------------------------------------------------------------

// FileVersionsListRequest is the POST body for the `file_versions_list` command.
//
//	path — site-relative path to the file whose version history to retrieve.
type FileVersionsListRequest struct {
	Path string `json:"path"`
}

// FileVersion is one version entry in the version history of a file.
//
//	version_id  — opaque identifier for this version (e.g. a timestamp string or hash).
//	             Pass to file_version_restore to restore this version.
//	size        — file size in bytes at this version.
//	mtime       — last-modified time of this version (Unix epoch seconds).
//	created_at  — when this version was created (Unix epoch seconds; may equal mtime).
type FileVersion struct {
	VersionID string `json:"version_id"`
	Size      int64  `json:"size"`
	Mtime     int64  `json:"mtime"`
	CreatedAt int64  `json:"created_at"`
}

// FileVersionsListResponse is the agent's response to `file_versions_list`.
//
//	versions — list of versions ordered newest-first.
//	error    — agent error struct on failure.
type FileVersionsListResponse struct {
	Versions []FileVersion `json:"versions"`
	Error    *FileError    `json:"error,omitempty"`
}

// ---------------------------------------------------------------------------
// file_version_restore  (P3)
// ---------------------------------------------------------------------------

// FileVersionRestoreRequest is the POST body for the `file_version_restore` command.
//
//	path              — site-relative path of the file to restore.
//	version_id        — the version identifier returned by file_versions_list.
//	confirm_sensitive — must be true when `path` matches the sensitive-file deny-list
//	                    (wp-config.php, .env*, *.pem, …). The CP verifies
//	                    PermSiteFilesWriteCode (owner) AND the request flag before
//	                    issuing this command; the agent independently re-checks and
//	                    returns "sensitive_denied" when absent / false.
type FileVersionRestoreRequest struct {
	Path             string `json:"path"`
	VersionID        string `json:"version_id"`
	ConfirmSensitive bool   `json:"confirm_sensitive,omitempty"`
}

// FileVersionRestoreResponse is the agent's response to `file_version_restore`.
//
//	path  — echoed resolved path.
//	size  — size of the restored file in bytes.
//	mtime — last-modified time after restore (Unix epoch seconds).
//	error — agent error struct on failure.
type FileVersionRestoreResponse struct {
	Path  string     `json:"path"`
	Size  int64      `json:"size"`
	Mtime int64      `json:"mtime"`
	Error *FileError `json:"error,omitempty"`
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
// P1 (read) codes:
//
//	invalid_path     — the path is malformed (empty, NUL byte, etc.).
//	outside_root     — the resolved path escapes the configured root jail.
//	not_found        — the path does not exist on the filesystem.
//	not_readable     — the agent process cannot read the path.
//	is_directory     — the path is a directory (unexpected for file_read).
//	too_large        — the file exceeds the per-command byte cap.
//	sensitive_denied — the path matches the sensitive-file deny-list and
//	                   confirm_sensitive was absent / false.
//
// P2 (write) codes:
//
//	executable_write_denied — the target path or destination name matches the
//	                          executable-extension deny-list (php, phar, asp,
//	                          jsp, cgi, htaccess, …) or is inside a web-served
//	                          PHP-executable directory, and confirm_executable_write
//	                          was absent / false.  CP maps to 403.
//	protected_root          — the path targets a protected directory (wp-admin,
//	                          wp-includes) without an explicit override.  CP maps
//	                          to 403.
//	mode_denied             — the requested mode is unsafe (setuid/world-write).
//	                          CP maps to 400.
//	exists                  — the destination already exists (file_mkdir or
//	                          file_rename when overwrite not permitted).  CP
//	                          maps to 409.
//	not_directory           — the path is not a directory (file_delete
//	                          non-recursive on a non-empty dir).  CP maps to 400.
//	base_unresolved         — the write base could not be resolved to a real
//	                          path (empty-base guard, T3).  CP maps to 500.
//	write_failed            — the atomic-write / swap failed (temp-write error,
//	                          SHA-256 mismatch, disk full).  CP maps to 502.
//
// P3 (archive/extract/search/versions) codes:
//
//	zip_slip        — a zip entry resolves outside the extraction destination
//	                  (directory traversal via archive).  CP maps to 422.
//	zip_bomb        — the archive exceeds the uncompressed-size or entry-count
//	                  guard (DoS protection).  CP maps to 422.
//	bad_archive     — the file exists but cannot be opened as a valid archive
//	                  (corrupted or unsupported format).  CP maps to 400.
//	not_archive     — the path does not point to a file with a recognised
//	                  archive extension or magic bytes.  CP maps to 400.
//	no_such_version — the version_id passed to file_version_restore does not
//	                  exist for the given path.  CP maps to 404.
type FileError struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}
