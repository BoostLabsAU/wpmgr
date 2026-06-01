package agentcmd

// This file is the AUTHORITATIVE CP->agent command contract for the auto-optimize
// settings push (ADR-044 Phase B). The wp-agent-engineer mirrors these shapes in
// the agent's command handler for `sync_media_config`. Field names are JSON wire
// names; do not rename without updating both sides.
//
// Transport — sync_media_config:
//   POST {site_url}/wp-json/wpmgr/v1/command/sync_media_config
//   Header: Authorization: Bearer <minted EdDSA JWT>
//           cmd="sync_media_config", aud=<siteId>
//   Body:   application/json — MediaConfigRequest below.
//   Response: 200 with MediaConfigResult; ok=false = semantic failure.
//
// The agent writes the values into typed WP options so the upload filter can
// read the enable flag locally (fast path). The actual format/quality for each
// encode is ALWAYS re-read CP-side in HandleAutoOptimize — a stale agent option
// can never select an invalid format (ADR-044 §4).

// MediaConfigRequest is the POST body for the `sync_media_config` command.
//
//	enabled        whether auto-optimize on upload is active for this site.
//	target_format  "avif" | "webp" | "original" — the encode target.
//	target_quality "lossy" | "lossless" — the encode quality mode.
type MediaConfigRequest struct {
	Enabled       bool   `json:"enabled"`
	TargetFormat  string `json:"target_format"`
	TargetQuality string `json:"target_quality"`
}

// MediaConfigResult is the agent's response to the `sync_media_config` command.
//
//	ok      whether the agent successfully stored the new config.
//	detail  short human-readable note ("applied", or an error message when
//	        ok=false). The CP treats ok=false as an application-level error.
type MediaConfigResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail"`
}
