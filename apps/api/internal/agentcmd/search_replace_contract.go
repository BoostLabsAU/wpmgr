package agentcmd

// This file defines the CP->agent contract for the search_replace command
// (#188). The agent mirrors these shapes in
// apps/agent/includes/commands/class-search-replace-command.php.
// Field names are JSON wire names; do not rename without updating both sides.
//
// Transport: POST {site_url}/wp-json/wpmgr/v1/command/search_replace
//   Header:  Authorization: Bearer <minted EdDSA JWT, cmd="search_replace">
//   Body:    SearchReplaceRequest (application/json)
//   Response: SearchReplaceResult

// SearchReplaceRequest is the POST body for the `search_replace` command.
//
//	job_id   UUID v4 minted by the CP for idempotency correlation. Required.
//	search   The exact string to search for. Must be at least 3 bytes; the
//	         agent enforces this independently. The string is treated as a
//	         literal (no regex, no wildcards).
//	replace  The replacement string. May be empty (replaces with nothing).
//	dry_run  When true the agent scans and counts matching rows but does NOT
//	         write any changes. rows_changed in the response will be 0.
//	tables   Optional allowlist of full table names (including prefix, e.g.
//	         "wp_options"). When absent or empty the agent scans every table
//	         that is not in the denylist.
type SearchReplaceRequest struct {
	JobID   string   `json:"job_id"`
	Search  string   `json:"search"`
	Replace string   `json:"replace"`
	DryRun  bool     `json:"dry_run"`
	Tables  []string `json:"tables,omitempty"`
}

// SearchReplaceResult is the agent's synchronous ACK+result for
// `search_replace`. The command is synchronous: the full counts are returned
// in the ACK body (no async progress). ok=false with detail means the agent
// refused the command (missing job_id, search too short, etc.).
//
//	tables_scanned  number of tables the agent walked (after denylist filter).
//	rows_matched    number of rows where at least one column value changed after
//	                the serialization-safe rewrite (dry_run counts these too).
//	rows_changed    number of rows actually written (always 0 on dry_run=true).
type SearchReplaceResult struct {
	OK            bool   `json:"ok"`
	JobID         string `json:"job_id,omitempty"`
	TablesScanned int    `json:"tables_scanned"`
	RowsMatched   int    `json:"rows_matched"`
	RowsChanged   int    `json:"rows_changed"`
	Detail        string `json:"detail,omitempty"`
}
