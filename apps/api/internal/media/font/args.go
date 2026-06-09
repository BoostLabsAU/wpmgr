package font

import (
	"errors"
	"fmt"
	"regexp"
	"strings"

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

// SubsetMode enumerates the supported subsetting modes.
//
//   - ""      — full WOFF2 only; Phase-1 behavior (no subsetting). This is the
//     zero value, so existing enqueued jobs deserialize correctly.
//   - "range" — subset to a fixed unicode range (e.g. "latin-ext").
const (
	SubsetModeNone  = ""      // full WOFF2 only; no subsetting
	SubsetModeRange = "range" // subset to a fixed unicode range
)

// SubsetSpec describes the optional subsetting pass that runs AFTER the full
// WOFF2 is produced. It is a pure-Go value type with no font-package import
// so the main API can embed it in TranscodeArgs without pulling in the
// transcoding implementation.
//
// Mode == "" (SubsetModeNone) is the zero value; existing Phase-1 jobs that
// do not carry a SubsetSpec field deserialize with Mode=="" and receive
// Phase-1 (full-WOFF2-only) behavior unchanged.
type SubsetSpec struct {
	// Mode is "" (full WOFF2 only) or "range" (subset to a fixed unicode range).
	Mode string `json:"mode,omitempty"`
	// Range is the unicode range name. Required when Mode == "range".
	// Supported value: "latin-ext".
	Range string `json:"range,omitempty"`
}

// ErrInvalidSubsetSpec is the sentinel for validation failures on a SubsetSpec.
var ErrInvalidSubsetSpec = errors.New("font transcode: invalid subset spec")

// ValidSubsetSpec validates a SubsetSpec at enqueue time (returns a 400-able error)
// and in the worker (defense in depth). It must be called before the spec is
// used to derive an asset key or passed to the transcode path.
func ValidSubsetSpec(s SubsetSpec) error {
	switch s.Mode {
	case SubsetModeNone:
		// range field is ignored when mode is none
		return nil
	case SubsetModeRange:
		switch strings.ToLower(s.Range) {
		case "latin-ext":
			return nil
		default:
			return fmt.Errorf("%w: range %q not supported (use \"latin-ext\")", ErrInvalidSubsetSpec, s.Range)
		}
	default:
		return fmt.Errorf("%w: mode %q not supported (use \"\" or \"range\")", ErrInvalidSubsetSpec, s.Mode)
	}
}

// CanonicalSubsetSpec returns a canonicalized copy of s (lowercased range).
// Always call this before using the spec to derive a key so that differently-
// cased inputs produce the same content address.
func CanonicalSubsetSpec(s SubsetSpec) SubsetSpec {
	return SubsetSpec{Mode: s.Mode, Range: strings.ToLower(s.Range)}
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
// subset       — optional subsetting spec. Zero value = full WOFF2 only (Phase-1).
type TranscodeArgs struct {
	TenantID   uuid.UUID  `json:"tenant_id"`
	SiteID     uuid.UUID  `json:"site_id"`
	SourceHash string     `json:"source_hash"`
	SourceKey  string     `json:"source_key"`
	SourceSize int64      `json:"source_size"`
	Subset     SubsetSpec `json:"subset,omitempty"`
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

// DeriveWoff2Key returns the SERVER-DERIVED object-storage key for the full
// WOFF2 output. Tenant-scoped so one tenant cannot read or overwrite another's
// output.
//
// Format: "fonts/<tenant_id>/<source_hash>.woff2"
//
// This key is UNCHANGED from Phase 1 (SubsetModeNone). Existing assets remain
// valid when subsetting is added.
func DeriveWoff2Key(tenantID uuid.UUID, sourceHash string) string {
	return "fonts/" + tenantID.String() + "/" + sourceHash + ".woff2"
}

// DeriveSubsetWoff2Key returns the SERVER-DERIVED object-storage key for a
// SUBSET WOFF2 output. The key encodes the source_hash + subset spec so that:
//
//   - Full WOFF2 key (mode=""):  "fonts/<tenant_id>/<source_hash>.woff2"  (DeriveWoff2Key — UNCHANGED)
//   - Range-subset key (mode="range", range="latin-ext"):
//     "fonts/<tenant_id>/<source_hash>.latin-ext.woff2"
//
// Tenant-scoped and content-addressed on (source_hash + subset spec). Never
// collides with the full WOFF2 key because the suffix includes the range label
// (which is non-hex, so it cannot match the 64-hex extractHex64 invariant —
// GuardStorageKey still passes because the 64-hex hash is in the base name).
func DeriveSubsetWoff2Key(tenantID uuid.UUID, sourceHash string, spec SubsetSpec) string {
	spec = CanonicalSubsetSpec(spec)
	if spec.Mode == SubsetModeNone {
		return DeriveWoff2Key(tenantID, sourceHash)
	}
	// mode == "range": suffix is the range label.
	return "fonts/" + tenantID.String() + "/" + sourceHash + "." + spec.Range + ".woff2"
}

// Woff2Key returns the deterministic tenant-scoped object-storage key for the
// full WOFF2 output derived from this job's TenantID and SourceHash.
func (a TranscodeArgs) Woff2Key() string {
	return DeriveWoff2Key(a.TenantID, a.SourceHash)
}

// SubsetWoff2Key returns the deterministic tenant-scoped object-storage key for
// the subset WOFF2 output (if the job carries a SubsetSpec with Mode != "").
// Returns the same value as Woff2Key() when the job has no subset spec.
func (a TranscodeArgs) SubsetWoff2Key() string {
	return DeriveSubsetWoff2Key(a.TenantID, a.SourceHash, a.Subset)
}
