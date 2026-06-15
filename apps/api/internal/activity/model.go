// Package activity implements the CP side of the ADR-037 Sprint 3 WordPress
// activity log: ingest of the agent-shipped, hash-chained event stream, a
// server-side re-verification of that chain (tamper-evidence — a capability
// not shipped by leading site-management plugins), tenant-scoped listing with filters, and a wiring
// hook into the existing uptime alert Dispatcher for high-severity security
// events.
//
// The hash chain is the load-bearing piece. The agent ships each event with a
// prev_hash + this_hash; the CP recomputes this_hash from the event fields and
// the PRIOR stored row's this_hash and marks a per-row chain_valid boolean. A
// mutated, inserted, or deleted historical row breaks the recomputation and is
// surfaced by Verify. The canonicalization MUST match the agent byte-for-byte
// (see Canonical / ComputeHash below and the SHARED WIRE CONTRACT in ADR-037).
package activity

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"time"

	"github.com/google/uuid"
)

// GenesisPrevHash is the prev_hash of the first event in a chain: 64 zero chars.
const GenesisPrevHash = "0000000000000000000000000000000000000000000000000000000000000000"

// Severity values. meta.severity drives the alert decision; "high" is the only
// level that can fire a security alert.
const (
	SeverityHigh   = "high"
	SeverityMedium = "medium"
	SeverityLow    = "low"
)

// IngestEvent is one event as shipped by the agent in the POST /agent/v1/activity
// body. Field names and JSON tags match the SHARED WIRE CONTRACT exactly.
type IngestEvent struct {
	Seq         int64  `json:"seq"`
	EventType   string `json:"event_type"`
	ObjectType  string `json:"object_type"`
	ObjectID    string `json:"object_id"`
	ObjectLabel string `json:"object_label"`
	ActorUserID int64  `json:"actor_user_id"`
	ActorLogin  string `json:"actor_login"`
	ActorIP     string `json:"actor_ip"`
	Summary     string `json:"summary"`
	// Meta is captured as RAW JSON bytes — NOT a map — so the hash preimage
	// uses the EXACT bytes the agent serialized. The agent computes this_hash
	// over wp_json_encode($meta), which (a) preserves insertion order and
	// (b) escapes slashes + unicode per PHP's defaults. Go's json.Marshal of a
	// map sorts keys and HTML-escapes <>& — so re-marshalling a parsed map
	// would diverge byte-for-byte for any multi-key meta (e.g. the universal
	// {"version":...,"severity":...}) and falsely flag every event as a chain
	// break. Hashing the verbatim wire bytes sidesteps all cross-language
	// JSON-encoder differences.
	Meta       json.RawMessage `json:"meta"`
	PrevHash   string          `json:"prev_hash"`
	ThisHash   string          `json:"this_hash"`
	OccurredAt time.Time       `json:"occurred_at"`
}

// IngestRequest is the POST /agent/v1/activity body.
type IngestRequest struct {
	Events        []IngestEvent `json:"events"`
	ChainStartSeq int64         `json:"chain_start_seq"`
	AgentVersion  string        `json:"agent_version"`
}

// Event is a stored activity row (operator-facing projection).
type Event struct {
	ID          int64
	TenantID    uuid.UUID
	SiteID      uuid.UUID
	Seq         int64
	EventType   string
	ObjectType  string
	ObjectID    string
	ObjectLabel string
	ActorUserID int64
	ActorLogin  string
	ActorIP     string
	Summary     string
	// Meta is the parsed map for operator-facing display + querying (stored in
	// the meta JSONB column). MetaRaw is the verbatim agent-serialized bytes
	// used for hash re-verification (stored in the meta_raw TEXT column). The
	// two are distinct ON PURPOSE: Meta is convenient, MetaRaw is correct.
	Meta       map[string]any
	MetaRaw    string
	Severity   string
	PrevHash   string
	ThisHash   string
	ChainValid bool
	OccurredAt time.Time
	ReceivedAt time.Time
}

// ListFilter narrows ListActivity. Zero-value fields are ignored.
type ListFilter struct {
	EventType  string
	ObjectType string
	ActorLogin string
	Severity   string
	Since      time.Time
	Until      time.Time
	Limit      int
	Offset     int
	// Cursor is the seq of the last row returned on the previous page.
	// When non-zero the query adds "seq < Cursor" to page forward (DESC order).
	Cursor int64
}

// ChainBreakKind classifies the first broken link found by VerifyChain.
type ChainBreakKind string

const (
	// BreakChainStartMissing means the very first stored event does not chain
	// from genesis — the oldest events are gone or the chain was reset.
	BreakChainStartMissing ChainBreakKind = "chain_start_missing"
	// BreakMissingEvents means one or more seq numbers are absent between the
	// last good row and the failing row (gap ≥ 1) — usually log cleanup /
	// retention or direct deletion. Not by itself proof of tampering.
	BreakMissingEvents ChainBreakKind = "missing_events"
	// BreakLinkMismatch means the rows are contiguous but this row's
	// prev_hash does not equal the prior row's this_hash — an event was
	// inserted, removed, or reordered, or the prior event was altered.
	BreakLinkMismatch ChainBreakKind = "link_mismatch"
	// BreakContentModified means the prev link is intact but the recomputed
	// this_hash no longer matches the stored this_hash — this event's content
	// was edited after recording, or there is a hashing disagreement.
	BreakContentModified ChainBreakKind = "content_modified"
)

// ChainBreakEvent is the offending event sub-struct carried in ChainBreak for
// operator context. The web layer truncates hashes for display; the CP sends
// the full 64-char hex.
type ChainBreakEvent struct {
	Summary    string `json:"summary"`
	EventType  string `json:"event_type"`
	ActorLogin string `json:"actor_login"`
	OccurredAt string `json:"occurred_at"` // RFC3339
}

// ChainBreak describes the first broken link in a chain re-verification. It is
// nil when the chain is intact.
type ChainBreak struct {
	// Seq is the seq of the event where verification failed.
	Seq int64 `json:"seq"`
	// Kind classifies the break (see BreakChainStartMissing etc.).
	Kind ChainBreakKind `json:"kind"`
	// PriorSeq is the seq of the last successfully-verified row, or nil when
	// the break is at the first/genesis row (no prior was verified).
	PriorSeq *int64 `json:"prior_seq"`
	// SeqGap is the number of missing sequence numbers between PriorSeq and
	// Seq. 0 when contiguous or when there is no prior row.
	SeqGap int64 `json:"seq_gap"`
	// ExpectedPrevHash is the verified chain head before this row (prior
	// row's this_hash, or GenesisPrevHash if this is the first row).
	ExpectedPrevHash string `json:"expected_prev_hash"`
	// StoredPrevHash is the row's own prev_hash as stored.
	StoredPrevHash string `json:"stored_prev_hash"`
	// RecomputedThisHash is ComputeHashFromStored(ExpectedPrevHash, row).
	RecomputedThisHash string `json:"recomputed_this_hash"`
	// StoredThisHash is the row's own this_hash as stored.
	StoredThisHash string `json:"stored_this_hash"`
	// Event carries the offending event fields for operator context.
	Event ChainBreakEvent `json:"event"`
}

// VerifyResult is the outcome of a full server-side chain re-verification.
type VerifyResult struct {
	Valid      bool        `json:"valid"`
	BreakAtSeq *int64      `json:"break_at_seq"`
	Total      int         `json:"total"`
	Break      *ChainBreak `json:"break"`
}

// occurredAtCanonical formats the occurred_at exactly as the agent does in the
// hash preimage. The agent ships RFC3339 in UTC ("2026-05-29T10:00:00Z"); we
// must reproduce the SAME string the agent hashed, which is the verbatim wire
// value. ComputeHashFromWire uses the raw wire string; this helper is the
// fallback for the re-verify path where we only have the parsed time.
func occurredAtCanonical(t time.Time) string {
	return t.UTC().Format("2006-01-02T15:04:05Z07:00")
}

// canonicalMetaRaw returns the meta JSON exactly as the agent hashed it. The
// agent's hash preimage uses wp_json_encode($meta) verbatim; the same bytes
// arrive in the request body and are captured as json.RawMessage, so we hash
// them as-is. Empty / absent / JSON-null meta canonicalizes to "{}" — matching
// the agent, which encodes an empty meta as the object literal "{}" (it casts
// the empty array to stdClass before encoding). We do NOT re-marshal: that
// would re-sort keys + change escaping and break the cross-language chain.
func canonicalMetaRaw(raw []byte) string {
	s := string(raw)
	if s == "" || s == "null" {
		return "{}"
	}
	return s
}

// Canonical builds the deterministic hash preimage for an event, given the
// prior link's hash. The field order + separator MUST match the agent
// canonicalization byte-for-byte:
//
//	sha256( prev_hash + "\n" + seq + "\n" + event_type + "\n" + object_type +
//	        "\n" + object_id + "\n" + actor_user_id + "\n" + occurred_at +
//	        "\n" + json_meta )
//
// occurredAt is the canonical occurred_at string (RFC3339 UTC, as shipped) and
// metaJSON is the compact JSON of the meta object ("{}" when empty).
func Canonical(prevHash string, seq int64, eventType, objectType, objectID string, actorUserID int64, occurredAt, metaJSON string) []byte {
	s := prevHash + "\n" +
		strconv.FormatInt(seq, 10) + "\n" +
		eventType + "\n" +
		objectType + "\n" +
		objectID + "\n" +
		strconv.FormatInt(actorUserID, 10) + "\n" +
		occurredAt + "\n" +
		metaJSON
	return []byte(s)
}

func hashHex(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// ComputeHash recomputes this_hash for a stored/ingest event given the prior
// link's hash. Used at ingest (against the prior stored row) and at Verify
// (folding the chain). occurredAt is canonicalized from the parsed timestamp.
func ComputeHash(prevHash string, e IngestEvent) string {
	return hashHex(Canonical(
		prevHash,
		e.Seq,
		e.EventType,
		e.ObjectType,
		e.ObjectID,
		e.ActorUserID,
		occurredAtCanonical(e.OccurredAt),
		canonicalMetaRaw(e.Meta),
	))
}

// ComputeHashFromStored recomputes this_hash for a stored Event row, hashing
// the verbatim MetaRaw bytes the agent shipped (NOT the parsed Meta map).
func ComputeHashFromStored(prevHash string, e Event) string {
	return hashHex(Canonical(
		prevHash,
		e.Seq,
		e.EventType,
		e.ObjectType,
		e.ObjectID,
		e.ActorUserID,
		occurredAtCanonical(e.OccurredAt),
		canonicalMetaRaw([]byte(e.MetaRaw)),
	))
}

// VerifyChain folds a seq-ASC ordered slice of stored events from genesis,
// recomputing each this_hash against the prior link, and reports the first
// broken link with a classified ChainBreak. A row is a break when its
// prev_hash != the prior hash OR its recomputed hash != its stored this_hash.
// This is the pure core of Service.Verify (DB-free, so it is directly
// unit-testable).
func VerifyChain(rows []Event) VerifyResult {
	res := VerifyResult{Valid: true, Total: len(rows)}
	prev := GenesisPrevHash
	var priorSeq *int64 // nil until at least one row has been verified OK
	for _, row := range rows {
		recomputed := ComputeHashFromStored(prev, row)
		linkOK := row.PrevHash == prev
		hashOK := recomputed == row.ThisHash
		if !linkOK || !hashOK {
			seq := row.Seq
			res.Valid = false
			res.BreakAtSeq = &seq

			// Compute seq gap: how many sequence numbers are missing between
			// the last good row and this row.
			var seqGap int64
			if priorSeq != nil {
				gap := row.Seq - *priorSeq - 1
				if gap > 0 {
					seqGap = gap
				}
			}

			// Classify the break kind.
			var kind ChainBreakKind
			switch {
			case priorSeq == nil && row.PrevHash != GenesisPrevHash:
				// First row doesn't anchor to genesis — the chain root is gone.
				kind = BreakChainStartMissing
			case seqGap > 0:
				// Gap in seq numbers — rows have been removed.
				kind = BreakMissingEvents
			case !linkOK:
				// Contiguous seq but prev_hash is broken — insertion/removal/reorder.
				kind = BreakLinkMismatch
			default:
				// prev link is intact but content hash diverges — content was edited.
				kind = BreakContentModified
			}

			res.Break = &ChainBreak{
				Seq:                seq,
				Kind:               kind,
				PriorSeq:           priorSeq,
				SeqGap:             seqGap,
				ExpectedPrevHash:   prev,
				StoredPrevHash:     row.PrevHash,
				RecomputedThisHash: recomputed,
				StoredThisHash:     row.ThisHash,
				Event: ChainBreakEvent{
					Summary:    row.Summary,
					EventType:  row.EventType,
					ActorLogin: row.ActorLogin,
					OccurredAt: row.OccurredAt.UTC().Format("2006-01-02T15:04:05Z07:00"),
				},
			}
			return res
		}
		// Row verified OK — advance the chain head and record the last good seq.
		prev = row.ThisHash
		s := row.Seq
		priorSeq = &s
	}
	return res
}

// severityFromMeta extracts meta.severity from the raw meta bytes, defaulting
// to "low" and clamping to the three known levels. Parses the raw (rather than
// taking the typed map) so the ingest path has a single source of truth for
// meta — the bytes the agent shipped.
func severityFromMeta(raw []byte) string {
	if len(raw) == 0 {
		return SeverityLow
	}
	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil {
		return SeverityLow
	}
	v, ok := m["severity"].(string)
	if !ok {
		return SeverityLow
	}
	switch v {
	case SeverityHigh, SeverityMedium, SeverityLow:
		return v
	default:
		return SeverityLow
	}
}

// parseMeta unmarshals the raw meta into a map for the display/query JSONB
// column. Returns an empty map on null/empty/invalid so the stored row always
// has a well-formed meta object.
func parseMeta(raw []byte) map[string]any {
	m := map[string]any{}
	if len(raw) == 0 {
		return m
	}
	_ = json.Unmarshal(raw, &m)
	return m
}
