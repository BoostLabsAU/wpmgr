package font

import (
	"fmt"
	"regexp"

	"github.com/google/uuid"
	"github.com/riverqueue/river"
)

// FontTranscodeQueue is the dedicated River queue for font_transcode jobs.
// It runs in the SAME media-encoder worker process (CGO_ENABLED=0 is fine
// because this codec path is pure-Go). MaxWorkers is set by the media-encoder
// cmd; the main API registers it with MaxWorkers=0 (insert-only).
const FontTranscodeQueue = "font_transcode"

// blake3HexRe matches exactly 64 lowercase hex characters — a BLAKE3 hash.
var blake3HexRe = regexp.MustCompile(`^[0-9a-f]{64}$`)

// ValidSourceHash returns true when h is a syntactically valid BLAKE3 hex digest
// (exactly 64 lowercase hex chars). Called both in the HTTP handler (returns 400
// on failure) and in the worker before building presigned URLs (defense in depth).
func ValidSourceHash(h string) bool {
	return blake3HexRe.MatchString(h)
}

// storageKeyPrefixRe validates the leading structure of an object-storage key:
// must start with "media/" or "fonts/" followed by a full UUID (tenant_id) and
// a non-empty sub-path. Combined with blake3HexRe via GuardStorageKey.
var storageKeyPrefixRe = regexp.MustCompile(
	`^(media|fonts)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/\S`,
)

// GuardStorageKey returns an error if key does not satisfy ALL of:
//  1. Starts with "media/<uuid>/" or "fonts/<uuid>/" (tenant-scoped prefix).
//  2. Contains a valid 64-char lowercase hex hash (BLAKE3) somewhere in it.
//
// Call this before every PresignGet or PresignPut. This is defense in depth;
// the worker derives all keys from validated inputs so a failure here indicates
// a programming error, not a user-input error.
func GuardStorageKey(key string) error {
	if !storageKeyPrefixRe.MatchString(key) {
		return fmt.Errorf("font transcode: storage key %q does not match expected tenant-scoped prefix", key)
	}
	if !blake3HexRe.MatchString(extractHex64(key)) {
		return fmt.Errorf("font transcode: storage key %q does not contain a valid 64-char hex hash", key)
	}
	return nil
}

// extractHex64 returns the first run of exactly 64 lowercase hex chars found in s,
// or an empty string when none exists. Used by GuardStorageKey.
func extractHex64(s string) string {
	runStart := -1
	runLen := 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		if (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') {
			if runStart < 0 {
				runStart = i
			}
			runLen++
			if runLen == 64 {
				// Verify the next char (if any) is NOT also a hex digit, so we
				// don't match the middle of a longer hex run.
				if i+1 < len(s) {
					next := s[i+1]
					if (next >= '0' && next <= '9') || (next >= 'a' && next <= 'f') {
						// Run continues — reset and keep scanning.
						runStart = -1
						runLen = 0
						continue
					}
				}
				return s[runStart : runStart+64]
			}
		} else {
			runStart = -1
			runLen = 0
		}
	}
	return ""
}

// TranscodeArgs is the River job payload for one font_transcode job.
// It is a PURE-Go type with no font package import so the main API can insert
// it without pulling in the transcode implementation.
//
// source_hash  — hex-encoded BLAKE3 hash of the raw source font bytes.
//
//	This is the content-address key used in font_transcode_results.
//	MUST be exactly 64 lowercase hex chars (validated before enqueue).
//
// source_key   — SERVER-DERIVED object-storage key for the source font.
//
//	Format: "media/<tenant_id>/font-src/<source_hash>"
//	The agent uploads the source to this key via the presigned PUT URL
//	returned by the /fonts/transcode enqueue response. The worker reads
//	the source from this key. Never agent-supplied.
//
// source_size  — byte length of the source (for the 10 MiB cap check).
// tenant_id    — the owning tenant (used for RLS + result row writes).
// site_id      — the requesting site (stored on the result row).
type TranscodeArgs struct {
	TenantID   uuid.UUID `json:"tenant_id"`
	SiteID     uuid.UUID `json:"site_id"`
	SourceHash string    `json:"source_hash"`
	SourceKey  string    `json:"source_key"`
	SourceSize int64     `json:"source_size"`
}

// Kind implements river.JobArgs. Pinned contract value: "font_transcode".
func (TranscodeArgs) Kind() string { return "font_transcode" }

// InsertOpts pins every font_transcode job to the dedicated queue.
func (TranscodeArgs) InsertOpts() river.InsertOpts {
	return river.InsertOpts{Queue: FontTranscodeQueue}
}

// SourceKey returns the SERVER-DERIVED object-storage key for the raw source
// font bytes. The agent uploads the source to this key (presigned PUT) before
// the encoder reads it.
//
// Format: "media/<tenant_id>/font-src/<source_hash>"
func DeriveSourceKey(tenantID uuid.UUID, sourceHash string) string {
	return "media/" + tenantID.String() + "/font-src/" + sourceHash
}

// DeriveWoff2Key returns the SERVER-DERIVED object-storage key for the WOFF2
// output. Tenant-scoped so one tenant cannot read or overwrite another's output.
//
// Format: "fonts/<tenant_id>/<source_hash>.woff2"
func DeriveWoff2Key(tenantID uuid.UUID, sourceHash string) string {
	return "fonts/" + tenantID.String() + "/" + sourceHash + ".woff2"
}

// Woff2Key returns the deterministic tenant-scoped object-storage key for the
// WOFF2 output derived from this job's TenantID and SourceHash.
func (a TranscodeArgs) Woff2Key() string {
	return DeriveWoff2Key(a.TenantID, a.SourceHash)
}
