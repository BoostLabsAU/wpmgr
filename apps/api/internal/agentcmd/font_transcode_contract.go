package agentcmd

// This file is the AUTHORITATIVE CP→agent contract for the font-transcode
// enqueue path (M54, Phase 1). The agent sends a FontTranscodeRequest when it
// discovers a self-hosted font that needs WOFF2 encoding; the CP enqueues a
// font_transcode River job and returns the current state for that hash.
//
// The agent also polls this endpoint to check whether a previously enqueued
// job has finished so it can serve the WOFF2 on subsequent page builds.
//
// Transport:
//
//	POST {cp_base}/agent/v1/fonts/transcode
//	Auth: Ed25519 signed-request middleware (agent group).
//	Body: application/json — FontTranscodeRequest.
//	Response: 200 — FontTranscodeResponse.
//
// Field names are JSON wire names; do not rename without updating both sides.
//
// AGENT UPLOAD FLOW (mirrors media optimizer):
//
//  1. Agent POSTs FontTranscodeRequest (no storage key — the agent never picks
//     the key). Response for a NEW hash includes state="pending" AND a
//     source_put_url (presigned S3 PUT, 15-min TTL).
//  2. Agent PUTs the raw font bytes to source_put_url immediately. The
//     encoder reads from the server-derived key: no key is sent back or
//     needed by the agent after the upload.
//  3. Agent POSTs the same request again on subsequent builds to poll for
//     completion. When state="ready", woff2_get_url is a short-TTL presigned
//     GET URL the CP minted for the server-derived WOFF2 object. The agent
//     fetches the WOFF2 bytes from this URL (it MUST NOT presign or construct
//     a storage key itself — that would reintroduce the path-traversal risk).
//     woff2_key is also present as an informational field. source_put_url is
//     absent on poll responses (the source is already uploaded).
//  4. When state="negative", transcoding failed permanently; serve original.

// FontTranscodeRequest is the body the agent POSTs when it needs a WOFF2
// transcode for a specific font content hash. The CP looks up or creates a
// font_transcode_results row for (source_hash, tenant_id, site_id) and, if no
// job is in flight or complete, enqueues a font_transcode River job.
//
// source_hash is the hex-encoded BLAKE3 hash of the raw source font bytes.
//
//	Must be exactly 64 lowercase hex characters; any other value is rejected
//	with 400. The agent MUST NOT send a storage key; the CP derives it
//	server-side from the verified tenant identity + source_hash.
//
// source_size is the byte length of the source font (for the 10 MiB cap).
//
//	Must be > 0; requests with source_size == 0 are rejected with 400.
//
// source_ext  is the file extension hint: "ttf" | "otf" | "woff". The CP
//
//	detects the real format from the magic bytes; this is informational only.
type FontTranscodeRequest struct {
	SourceHash string `json:"source_hash"`
	SourceSize int64  `json:"source_size"`
	SourceExt  string `json:"source_ext,omitempty"`
}

// FontTranscodeResponse is the CP's reply to a FontTranscodeRequest.
//
// state is one of:
//
//	"pending"  — a job has been enqueued or is in flight. When a NEW job
//	             was just created, source_put_url is also present: the agent
//	             MUST PUT the raw font bytes there before the encoder runs.
//	             On subsequent polls (state still "pending"), source_put_url
//	             is absent (source already uploaded). Poll again on the next
//	             page build. woff2_key is absent.
//	"ready"    — the WOFF2 is available; woff2_key is the object-storage key
//	             the agent presign-GETs and serves.
//	"negative" — transcoding permanently failed for this hash; the agent must
//	             serve the original font. No retry will ever produce a result.
//
// source_put_url is non-empty only when state=="pending" AND the job was
// just freshly enqueued. The agent must upload the raw source font bytes
// (Content-Type: application/octet-stream or the real MIME) via HTTP PUT to
// this URL before the encoder can run. The URL is a presigned S3 PUT with a
// 15-minute TTL. Do NOT follow redirects; issue a direct PUT.
//
// woff2_key is non-empty only when state=="ready". Informational; the agent
// MUST NOT build or presign this key itself — use woff2_get_url instead.
//
// woff2_get_url is non-empty only when state=="ready". It is a short-TTL
// presigned GET URL minted by the CP for the server-derived WOFF2 object
// (GuardStorageKey-validated + tenant-scoped). The agent fetches the WOFF2
// bytes directly from this URL. The agent MUST NOT presign any key itself.
//
// error_detail is a short diagnostic string for state=="negative".
type FontTranscodeResponse struct {
	State        string `json:"state"`
	SourcePutURL string `json:"source_put_url,omitempty"`
	Woff2Key     string `json:"woff2_key,omitempty"`
	Woff2GetURL  string `json:"woff2_get_url,omitempty"`
	ErrorDetail  string `json:"error_detail,omitempty"`
}
