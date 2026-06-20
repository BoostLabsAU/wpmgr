package agentcmd

// This file is the AUTHORITATIVE CP->agent command contract for the Security
// Suite Phase 1 (ADR-057): per-site hardening toggles + durable ban list.
// The wp-agent-engineer mirrors these shapes in the agent's command handler.
// Field names are JSON wire names; do not rename without updating both sides.
//
// Transport — sync_security_hardening:
//   POST {site_url}/wp-json/wpmgr/v1/command/sync_security_hardening
//   Header: Authorization: Bearer <minted EdDSA JWT>
//           cmd="sync_security_hardening", aud=<siteId>
//   Body:   application/json — HardeningRequest below.
//   Response: 200 with HardeningResult; ok=false = semantic failure (HTTP
//             transport error OR ok=false at 200 is treated as an error by
//             the CP, same as sync_security_config / sync_error_config).
//
// The agent applies the FULL config + ban list atomically on every push.
// It does not diff against a previous state — the CP sends the canonical
// current snapshot and the agent replaces its local copy.

// HardeningConfig is the per-site hardening toggle block the CP sends to the
// agent. Each field maps to one WordPress hardening technique that the agent
// applies via its config-writer and mu-plugin.
//
// All toggles default false (off) so enabling is opt-in. The agent treats a
// missing field as false (JSON zero value) to remain backward-compatible when
// new toggles are added in future phases without a simultaneous agent update.
//
//	disable_file_editor          — write DISALLOW_FILE_EDIT to wp-config.
//	xmlrpc_mode                  — "on" (leave as-is) | "off" (block) |
//	                               "limited" (block system.multicall only).
//	restrict_rest_api            — "default" | "restricted" (block anon access
//	                               to sensitive routes: /users, /comments).
//	restrict_login_identifier    — "username" | "email" | "both".
//	force_unique_nickname        — enforce display_name != user_login.
//	disable_author_archive_enum  — 404 for author archives with 0 posts.
//	force_ssl                    — write FORCE_SSL_ADMIN to wp-config.
//	disable_directory_browsing   — emit Options -Indexes server-config rule.
//	disable_php_in_uploads       — block direct PHP execution in uploads/
//	                               plugins/themes via server-config rules.
//	protect_system_files         — deny readme.html / wp-config.php /
//	                               install.php / .git/ via server-config.
type HardeningConfig struct {
	DisableFileEditor         bool   `json:"disable_file_editor"`
	XMLRPCMode                string `json:"xmlrpc_mode"`
	RestrictRESTAPI           string `json:"restrict_rest_api"`
	RestrictLoginIdentifier   string `json:"restrict_login_identifier"`
	ForceUniqueNickname       bool   `json:"force_unique_nickname"`
	DisableAuthorArchiveEnum  bool   `json:"disable_author_archive_enum"`
	ForceSSL                  bool   `json:"force_ssl"`
	DisableDirectoryBrowsing  bool   `json:"disable_directory_browsing"`
	DisablePHPInUploads       bool   `json:"disable_php_in_uploads"`
	ProtectSystemFiles        bool   `json:"protect_system_files"`
}

// BanEntry is one durable ban entry in the ban list.
//
//	id          CP-assigned UUID (the agent uses it to detect list drift).
//	type        "ip" | "range" | "user_agent".
//	value       the banned value (IP, CIDR, or UA string).
//	comment     optional operator note (informational; the agent may log it).
type BanEntry struct {
	ID      string `json:"id"`
	Type    string `json:"type"`
	Value   string `json:"value"`
	Comment string `json:"comment,omitempty"`
}

// HardeningRequest is the POST body for the `sync_security_hardening` command.
//
//	config  the full hardening toggle snapshot.
//	bans    the full current ban list for this site (IP/range/user-agent).
//	        The agent REPLACES its local ban list with this snapshot on every
//	        push; an empty slice means "no bans" (clear any local list).
type HardeningRequest struct {
	Config HardeningConfig `json:"config"`
	Bans   []BanEntry      `json:"bans"`
}

// HardeningResult is the agent's response to the `sync_security_hardening`
// command.
//
//	ok      whether the agent successfully applied the config + ban list.
//	detail  short human-readable note ("applied", or an error description when
//	        ok=false). The CP treats ok=false as an application-level error with
//	        this message, even though the HTTP status is 200.
type HardeningResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail"`
}
