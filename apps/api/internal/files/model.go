package files

// Audit action constants for the File Manager.
//
// These mirror the naming convention from internal/audit/audit.go.
//
// P1 (read) actions: Read, SensitiveRead, SensitiveDenied, SettingsChanged.
// P2 (write) actions: Write, Mkdir, Rename, Delete, Chmod, Upload + the
// elevated WriteCode/WriteCodeDenied for executable/sensitive writes.
const (
	// ---------------------------------------------------------------------------
	// P1 actions
	// ---------------------------------------------------------------------------

	// ActionSiteFilesRead is the standard audit action for any successful
	// file read, list, or download that does not involve a sensitive path.
	// Metadata always carries: op ("list"|"read"|"download"), path (for
	// read/download), size (for read/download).
	ActionSiteFilesRead = "site.files.read"

	// ActionSiteFilesSensitiveRead is recorded when a SENSITIVE path (e.g.
	// wp-config.php, .env, *.pem) is successfully read or downloaded. The full
	// path is always included in metadata — this is the T6 elevated-severity
	// entry the security design requires.
	ActionSiteFilesSensitiveRead = "site.files.sensitive.read"

	// ActionSiteFilesSensitiveDenied is recorded on every DENIED attempt to
	// read or download a sensitive path, whether due to missing
	// confirm_sensitive or insufficient permission (T9: log denials).
	ActionSiteFilesSensitiveDenied = "site.files.sensitive.denied"

	// ActionSiteFilesSettingsChanged is recorded when the file manager is
	// enabled or disabled for a site via PUT /sites/{siteId}/files/settings
	// (PermSiteFilesManage, admin+). Metadata: enabled (bool), write_enabled (bool).
	ActionSiteFilesSettingsChanged = "site.files.settings.changed"

	// ---------------------------------------------------------------------------
	// P2 write actions
	// ---------------------------------------------------------------------------

	// ActionSiteFilesWrite is recorded on a successful small-file write
	// (file_write command). Metadata: path, size, mtime, mode.
	ActionSiteFilesWrite = "site.files.write"

	// ActionSiteFilesMkdir is recorded on a successful directory creation
	// (file_mkdir command). Metadata: path.
	ActionSiteFilesMkdir = "site.files.mkdir"

	// ActionSiteFilesRename is recorded on a successful rename/move
	// (file_rename command). Metadata: src, dst.
	ActionSiteFilesRename = "site.files.rename"

	// ActionSiteFilesDelete is recorded on a successful file/directory deletion
	// (file_delete command). Metadata: path, recursive, deleted (count).
	// This is the highest-risk write action; always recorded even on denial.
	ActionSiteFilesDelete = "site.files.delete"

	// ActionSiteFilesDeleteDenied is recorded when a delete is rejected (missing
	// confirm token, insufficient perm, or agent refusal). Metadata: path, reason.
	ActionSiteFilesDeleteDenied = "site.files.delete.denied"

	// ActionSiteFilesChmod is recorded on a successful mode change
	// (file_chmod command). Metadata: path, mode.
	ActionSiteFilesChmod = "site.files.chmod"

	// ActionSiteFilesUpload is recorded on a successful upload-and-apply
	// (file_upload_apply command). Metadata: path, size_bytes, chunk_count,
	// transfer_id.
	ActionSiteFilesUpload = "site.files.upload"

	// ActionSiteFilesWriteCode is recorded when a successful write was gated on
	// PermSiteFilesWriteCode (owner) via confirm_executable_write or
	// confirm_sensitive on a write/rename/upload. Metadata: path, op, reason
	// ("executable"|"sensitive").  This is an elevated-severity entry (T1/T6).
	ActionSiteFilesWriteCode = "site.files.write_code"

	// ActionSiteFilesWriteCodeDenied is recorded when a caller passed
	// confirm_executable_write or confirm_sensitive without holding
	// PermSiteFilesWriteCode (owner). Metadata: path, op, reason. T9: always log
	// denials, especially for elevated-risk operations.
	ActionSiteFilesWriteCodeDenied = "site.files.write_code.denied"

	// ---------------------------------------------------------------------------
	// P3 advanced ops actions
	// ---------------------------------------------------------------------------

	// ActionSiteFilesArchive is recorded on a successful archive creation
	// (file_archive_create command). Metadata: paths ([]string), size_bytes,
	// chunk_count, transfer_id, object_key.
	ActionSiteFilesArchive = "site.files.archive"

	// ActionSiteFilesArchiveSensitiveRead is recorded when a successful archive
	// creation includes at least one sensitive path (wp-config.php, .env*, …).
	// Elevated-severity entry — mirrors ActionSiteFilesSensitiveRead (T6).
	// Metadata: paths ([]string), size_bytes, chunk_count, transfer_id.
	ActionSiteFilesArchiveSensitiveRead = "site.files.archive.sensitive.read"

	// ActionSiteFilesArchiveSensitiveDenied is recorded on every denied attempt
	// to archive a sensitive path (missing confirm_sensitive or insufficient
	// permission). Metadata: paths ([]string), reason. T9: log denials.
	ActionSiteFilesArchiveSensitiveDenied = "site.files.archive.sensitive.denied"

	// ActionSiteFilesExtract is recorded on a successful archive extraction
	// (file_extract command). Metadata: archive_path, dest_path, extracted (count).
	ActionSiteFilesExtract = "site.files.extract"

	// ActionSiteFilesExtractDenied is recorded when an extract is rejected at the
	// CP gate (missing WriteCode owner permission for confirm_executable_write or
	// confirm_sensitive). Metadata: archive_path, reason. T9: log denials at
	// elevated severity for this high-risk surface.
	ActionSiteFilesExtractDenied = "site.files.extract.denied"

	// ActionSiteFilesSearch is recorded on a successful search
	// (file_search command). Metadata: path, query, mode, match_count, truncated.
	// Search is a read op — audited so bulk content-search of the FS is traceable.
	ActionSiteFilesSearch = "site.files.search"

	// ActionSiteFilesVersionsList is recorded on a successful version history
	// listing (file_versions_list command). Metadata: path, version_count.
	ActionSiteFilesVersionsList = "site.files.versions.list"

	// ActionSiteFilesVersionsListDenied is recorded when a non-owner caller
	// attempts to list versions for a sensitive path without holding
	// PermSiteFilesReadSensitive. Metadata: path, reason. T9: log denials.
	ActionSiteFilesVersionsListDenied = "site.files.versions.list.denied"

	// ActionSiteFilesVersionRestore is recorded on a successful version restore
	// (file_version_restore command). Metadata: path, version_id, size, mtime.
	// This is a write operation and is always audited with the full version_id.
	ActionSiteFilesVersionRestore = "site.files.version.restore"

	// ActionSiteFilesVersionRestoreSensitive is recorded when a successful version
	// restore targets a sensitive path. Elevated-severity entry (T6).
	// Metadata: path, version_id, size, mtime.
	ActionSiteFilesVersionRestoreSensitive = "site.files.version.restore.sensitive"

	// ActionSiteFilesVersionRestoreDenied is recorded when a version restore is
	// denied because the path is sensitive and the caller lacks PermSiteFilesWriteCode
	// or the confirm_sensitive flag was not set. Metadata: path, reason. T9.
	ActionSiteFilesVersionRestoreDenied = "site.files.version.restore.denied"
)
