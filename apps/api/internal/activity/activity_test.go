package activity

import (
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"
)

// buildChain assembles a valid 3-event hash chain the way the agent ships it:
// each event's prev_hash is the prior event's this_hash (genesis for the
// first), and this_hash is the canonical sha256 over its own fields. The stored
// Event mirror is what VerifyChain folds over.
func buildChain(t *testing.T) []Event {
	t.Helper()
	tenant := uuid.New()
	siteID := uuid.New()
	base, _ := time.Parse(time.RFC3339, "2026-05-29T10:00:00Z")

	// Raw meta bytes exactly as the agent emits them (insertion order, compact).
	specs := []IngestEvent{
		{Seq: 1, EventType: "plugin.activated", ObjectType: "plugin", ObjectID: "akismet/akismet.php", ActorUserID: 1, Meta: json.RawMessage(`{"version":"5.3","severity":"medium"}`)},
		{Seq: 2, EventType: "user.login", ObjectType: "user", ObjectID: "admin", ActorUserID: 1, Meta: json.RawMessage(`{"ip":"203.0.113.4","severity":"low"}`)},
		{Seq: 3, EventType: "core.updated", ObjectType: "core", ObjectID: "", ActorUserID: 1, Meta: json.RawMessage(`{"from":"6.4","to":"6.5","severity":"high"}`)},
	}

	rows := make([]Event, 0, len(specs))
	prev := GenesisPrevHash
	for i := range specs {
		specs[i].OccurredAt = base.Add(time.Duration(i) * time.Minute)
		specs[i].PrevHash = prev
		specs[i].ThisHash = ComputeHash(prev, specs[i])
		rows = append(rows, Event{
			TenantID:    tenant,
			SiteID:      siteID,
			Seq:         specs[i].Seq,
			EventType:   specs[i].EventType,
			ObjectType:  specs[i].ObjectType,
			ObjectID:    specs[i].ObjectID,
			ActorUserID: specs[i].ActorUserID,
			Meta:        parseMeta(specs[i].Meta),
			MetaRaw:     canonicalMetaRaw(specs[i].Meta),
			Severity:    severityFromMeta(specs[i].Meta),
			PrevHash:    specs[i].PrevHash,
			ThisHash:    specs[i].ThisHash,
			ChainValid:  true,
			OccurredAt:  specs[i].OccurredAt,
		})
		prev = specs[i].ThisHash
	}
	return rows
}

// TestVerifyIntactChain confirms a well-formed chain verifies clean.
func TestVerifyIntactChain(t *testing.T) {
	rows := buildChain(t)
	res := VerifyChain(rows)
	if !res.Valid {
		t.Fatalf("expected valid chain, got break at %v", res.BreakAtSeq)
	}
	if res.Total != 3 {
		t.Fatalf("expected total 3, got %d", res.Total)
	}
	if res.BreakAtSeq != nil {
		t.Fatalf("expected no break, got seq %d", *res.BreakAtSeq)
	}
}

// TestVerifyDetectsMiddleTamper is the headline tamper-evidence test: build a
// valid 3-event chain, mutate the MIDDLE event's meta in place (leaving its
// stored this_hash untouched, exactly as a row-level DB mutation would), and
// assert Verify reports a break at the tampered seq (2). The recomputed hash of
// the mutated row no longer matches its stored this_hash, so the fold trips at
// seq 2 — before reaching seq 3.
func TestVerifyDetectsMiddleTamper(t *testing.T) {
	rows := buildChain(t)

	// Tamper: change seq 2's authoritative meta bytes (MetaRaw — the hash
	// preimage source, what a real DB-row attacker would have to edit to forge
	// the record). The stored this_hash is now stale relative to the mutated
	// MetaRaw, so the fold trips at seq 2.
	rows[1].MetaRaw = `{"ip":"10.0.0.1","severity":"low"}`

	res := VerifyChain(rows)
	if res.Valid {
		t.Fatal("expected tampered chain to be reported invalid")
	}
	if res.BreakAtSeq == nil {
		t.Fatal("expected a break seq, got nil")
	}
	if *res.BreakAtSeq != 2 {
		t.Fatalf("expected break at seq 2 (the tampered row), got %d", *res.BreakAtSeq)
	}
}

// TestVerifyDetectsBrokenPrevHash covers the other break condition: a row whose
// prev_hash does not match the prior link (e.g. a deleted/reordered row).
func TestVerifyDetectsBrokenPrevHash(t *testing.T) {
	rows := buildChain(t)
	// Snip the first event so seq 2's prev_hash no longer matches genesis.
	res := VerifyChain(rows[1:])
	if res.Valid {
		t.Fatal("expected chain with a missing genesis link to be invalid")
	}
	if res.BreakAtSeq == nil || *res.BreakAtSeq != 2 {
		t.Fatalf("expected break at seq 2, got %v", res.BreakAtSeq)
	}
}

// TestVerifyChain_ValidChain confirms a clean chain returns Valid true, Break nil.
func TestVerifyChain_ValidChain(t *testing.T) {
	rows := buildChain(t)
	res := VerifyChain(rows)
	if !res.Valid {
		t.Fatalf("expected valid chain, got break at %v", res.BreakAtSeq)
	}
	if res.Break != nil {
		t.Fatalf("expected Break nil on valid chain, got %+v", res.Break)
	}
	if res.Total != 3 {
		t.Fatalf("expected total 3, got %d", res.Total)
	}
}

// TestVerifyChain_ContentModified tampers a middle row's content so the
// recomputed hash diverges from the stored this_hash while the prev link remains
// intact. Expects kind=content_modified at seq 2, seq_gap=0, prior_seq=1.
func TestVerifyChain_ContentModified(t *testing.T) {
	rows := buildChain(t)
	// Mutate seq 2's MetaRaw — the hash preimage source — without touching
	// PrevHash or ThisHash, exactly as a direct DB row edit would look.
	rows[1].MetaRaw = `{"ip":"192.168.0.1","severity":"low"}`

	res := VerifyChain(rows)
	if res.Valid {
		t.Fatal("expected invalid chain")
	}
	if res.Break == nil {
		t.Fatal("expected non-nil Break")
	}
	b := res.Break
	if b.Kind != BreakContentModified {
		t.Errorf("kind: got %q, want %q", b.Kind, BreakContentModified)
	}
	if b.Seq != 2 {
		t.Errorf("seq: got %d, want 2", b.Seq)
	}
	if b.PriorSeq == nil || *b.PriorSeq != 1 {
		t.Errorf("prior_seq: got %v, want 1", b.PriorSeq)
	}
	if b.SeqGap != 0 {
		t.Errorf("seq_gap: got %d, want 0", b.SeqGap)
	}
	if res.BreakAtSeq == nil || *res.BreakAtSeq != 2 {
		t.Errorf("BreakAtSeq: got %v, want 2", res.BreakAtSeq)
	}
	// ExpectedPrevHash must equal row[0].ThisHash (the verified chain head).
	if b.ExpectedPrevHash != rows[0].ThisHash {
		t.Errorf("expected_prev_hash mismatch")
	}
	// StoredPrevHash must equal row[1].PrevHash as stored (unchanged).
	if b.StoredPrevHash != rows[1].PrevHash {
		t.Errorf("stored_prev_hash mismatch")
	}
	// StoredThisHash must equal row[1].ThisHash as stored.
	if b.StoredThisHash != rows[1].ThisHash {
		t.Errorf("stored_this_hash mismatch")
	}
	// RecomputedThisHash must differ from StoredThisHash (that's the whole point).
	if b.RecomputedThisHash == b.StoredThisHash {
		t.Errorf("recomputed_this_hash should differ from stored when content was modified")
	}
}

// TestVerifyChain_LinkMismatch sets a row's prev_hash to a garbage value
// while keeping seqs contiguous. Expects kind=link_mismatch.
func TestVerifyChain_LinkMismatch(t *testing.T) {
	rows := buildChain(t)
	// Break row[1]'s prev_hash while keeping the seq contiguous (seq 1, 2, 3).
	rows[1].PrevHash = strings.Repeat("a", 64)

	res := VerifyChain(rows)
	if res.Valid {
		t.Fatal("expected invalid chain")
	}
	if res.Break == nil {
		t.Fatal("expected non-nil Break")
	}
	b := res.Break
	if b.Kind != BreakLinkMismatch {
		t.Errorf("kind: got %q, want %q", b.Kind, BreakLinkMismatch)
	}
	if b.Seq != 2 {
		t.Errorf("seq: got %d, want 2", b.Seq)
	}
	if b.PriorSeq == nil || *b.PriorSeq != 1 {
		t.Errorf("prior_seq: got %v, want 1", b.PriorSeq)
	}
	if b.SeqGap != 0 {
		t.Errorf("seq_gap: got %d, want 0", b.SeqGap)
	}
	if b.StoredPrevHash != rows[1].PrevHash {
		t.Errorf("stored_prev_hash mismatch")
	}
}

// TestVerifyChain_MissingEvents removes a middle row so seq jumps from 1 to 3.
// Expects kind=missing_events, seq_gap=1, prior_seq=1.
func TestVerifyChain_MissingEvents(t *testing.T) {
	rows := buildChain(t)
	// Drop seq 2; remaining rows are seq 1 and seq 3. Seq 3's prev_hash was
	// built against seq 2's this_hash so it will also fail the link check, but
	// the gap is detected first.
	trimmed := []Event{rows[0], rows[2]}

	res := VerifyChain(trimmed)
	if res.Valid {
		t.Fatal("expected invalid chain")
	}
	if res.Break == nil {
		t.Fatal("expected non-nil Break")
	}
	b := res.Break
	if b.Kind != BreakMissingEvents {
		t.Errorf("kind: got %q, want %q", b.Kind, BreakMissingEvents)
	}
	if b.Seq != 3 {
		t.Errorf("seq: got %d, want 3", b.Seq)
	}
	if b.PriorSeq == nil || *b.PriorSeq != 1 {
		t.Errorf("prior_seq: got %v, want 1", b.PriorSeq)
	}
	if b.SeqGap != 1 {
		t.Errorf("seq_gap: got %d, want 1", b.SeqGap)
	}
}

// TestVerifyChain_ChainStartMissing sets the first row's prev_hash to something
// other than GenesisPrevHash. Expects kind=chain_start_missing, prior_seq nil.
func TestVerifyChain_ChainStartMissing(t *testing.T) {
	rows := buildChain(t)
	// The first row's prev_hash should be genesis; override it so it looks like
	// the oldest events have been deleted and the chain was rebased.
	rows[0].PrevHash = strings.Repeat("f", 64)

	res := VerifyChain(rows)
	if res.Valid {
		t.Fatal("expected invalid chain")
	}
	if res.Break == nil {
		t.Fatal("expected non-nil Break")
	}
	b := res.Break
	if b.Kind != BreakChainStartMissing {
		t.Errorf("kind: got %q, want %q", b.Kind, BreakChainStartMissing)
	}
	if b.Seq != 1 {
		t.Errorf("seq: got %d, want 1", b.Seq)
	}
	if b.PriorSeq != nil {
		t.Errorf("prior_seq: got %v, want nil (no prior verified row)", b.PriorSeq)
	}
	if b.SeqGap != 0 {
		t.Errorf("seq_gap: got %d, want 0", b.SeqGap)
	}
	if b.ExpectedPrevHash != GenesisPrevHash {
		t.Errorf("expected_prev_hash: got %q, want GenesisPrevHash", b.ExpectedPrevHash)
	}
}

// TestVerifyChain_JSONShape is the contract drift guard: marshal an
// activityVerifyDTO with a fully-populated break and assert every required JSON
// key is present by name. This catches field renames or omitempty accidentally
// swallowing zero-value fields before the web layer sees them.
func TestVerifyChain_JSONShape(t *testing.T) {
	priorSeq := int64(1)
	breakSeq := int64(2)
	dto := activityVerifyDTO{
		Valid:      false,
		Total:      3,
		BreakAtSeq: &breakSeq,
		Break: &activityVerifyBreakDTO{
			Seq:                2,
			Kind:               string(BreakContentModified),
			PriorSeq:           &priorSeq,
			SeqGap:             0,
			ExpectedPrevHash:   strings.Repeat("0", 64),
			StoredPrevHash:     strings.Repeat("0", 64),
			RecomputedThisHash: strings.Repeat("a", 64),
			StoredThisHash:     strings.Repeat("b", 64),
			Event: activityVerifyBreakEventDTO{
				Summary:    "Plugin activated",
				EventType:  "plugin.activated",
				ActorLogin: "admin",
				OccurredAt: "2026-05-29T10:00:00Z",
			},
		},
	}

	b, err := json.Marshal(dto)
	if err != nil {
		t.Fatalf("json.Marshal failed: %v", err)
	}

	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("json.Unmarshal failed: %v", err)
	}

	// Top-level required keys.
	for _, key := range []string{"valid", "total", "break_at_seq", "break"} {
		if _, ok := m[key]; !ok {
			t.Errorf("missing top-level key %q in JSON: %s", key, b)
		}
	}

	breakObj, ok := m["break"].(map[string]any)
	if !ok {
		t.Fatalf("break is not an object: %s", b)
	}

	// break sub-object required keys.
	for _, key := range []string{
		"seq", "kind", "prior_seq", "seq_gap",
		"expected_prev_hash", "stored_prev_hash",
		"recomputed_this_hash", "stored_this_hash", "event",
	} {
		if _, ok := breakObj[key]; !ok {
			t.Errorf("missing break key %q in JSON: %s", key, b)
		}
	}

	eventObj, ok := breakObj["event"].(map[string]any)
	if !ok {
		t.Fatalf("break.event is not an object: %s", b)
	}

	// event sub-object required keys.
	for _, key := range []string{"summary", "event_type", "actor_login", "occurred_at"} {
		if _, ok := eventObj[key]; !ok {
			t.Errorf("missing break.event key %q in JSON: %s", key, b)
		}
	}

	// kind value must be exact.
	if got, _ := breakObj["kind"].(string); got != string(BreakContentModified) {
		t.Errorf("break.kind: got %q, want %q", got, BreakContentModified)
	}
}
