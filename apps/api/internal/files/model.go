package files

// Audit action constants for the File Manager.
//
// These mirror the naming convention from internal/audit/audit.go.
const (
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
	// (PermSiteFilesManage, admin+). Metadata: enabled (bool).
	ActionSiteFilesSettingsChanged = "site.files.settings.changed"
)
