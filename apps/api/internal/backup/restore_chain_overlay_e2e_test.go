package backup

// restore_chain_overlay_e2e_test.go — ADR-051 archive-delta END-TO-END
// round-trip test (agent emit -> CP overlay planner -> reconstructed tree).
//
// WHY THIS EXISTS: the other overlay tests (restore_chain_overlay_test.go)
// hand-craft manifest entries with the CORRECT contract values. They PASS even
// when the agent never emits those values, so they cannot catch agent<->CP
// CONTRACT DRIFT. This test instead builds the manifest entries the way the
// AGENT actually emits them (the exact entry_kind / mode / part-name strings),
// feeds them into the REAL planRestoreChain overlay, then replays the agent's
// restore overlay (extract parts in Manifest.Entries order, newest-wins,
// delete tombstoned paths) and asserts the reconstructed wp-content tree.
//
// It runs the SAME scenario twice:
//   - agentEmitFixed   : the post-fix wire (files-list entry_kind, per-path
//                        tombstones entries w/ Delete mode, generation-namespaced
//                        part names). The round-trip MUST reconstruct the exact
//                        tree.
//   - agentEmitDrifted : the pre-fix wire (files.list tagged "file", a single
//                        "tombstones.list" file artifact, part names that COLLIDE
//                        across generations). The round-trip MUST be wrong (the
//                        whole point: the test fails on the drift, passes on the
//                        fix).
//
// The fixed/drifted emitters mirror the agent's class-files-archiver.php +
// class-encrypt-and-upload.php + class-task-runner.php manifest assembly. The
// canonical strings live in agentcmd/backup_contract.go and backup/model.go;
// the emitter references those constants so a future rename can't desync the
// test from the contract.

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
)

// ---------------------------------------------------------------------------
// Agent-emit model: the manifest entries the agent produces for one generation.
// ---------------------------------------------------------------------------

// agentPart is one component archive part the agent's FilesArchiver produced,
// plus the wp-content-relative files it packed (so the test can replay the
// ZipArchive extract during reconstruction).
type agentPart struct {
	component string            // "plugins" | "themes" | "uploads" | "wp-content"
	chunk     string            // a single content-addressed chunk hash for the part
	files     map[string]string // relpath -> file content packed in this part
}

// agentGen is the agent's emit for one snapshot generation: the parts it packed,
// the paths it tombstoned (deletions vs the parent), and whether it carries a DB
// dump (gen-0 base always does; an increment may).
type agentGen struct {
	generation int
	parts      []agentPart
	tombstoned []string // deleted relpaths (agent only ever emits Delete)
	hasDB      bool
	dbChunk    string
}

// componentKind maps the agent component name to the CP/agent manifest entry_kind
// (mirrors FilesArchiver::COMPONENT_PARTITIONS kind column).
func componentKind(component string) string {
	switch component {
	case "plugins":
		return EntryKindPlugin
	case "themes":
		return EntryKindTheme
	case "uploads":
		return EntryKindUpload
	default:
		return EntryKindWPContent
	}
}

// agentPartNameFixed mirrors FilesArchiver::partName: generation-namespaced
// `<component>.gNNN.partMMM.zip`. THIS is the post-fix part identity.
func agentPartNameFixed(component string, generation, part int) string {
	return component +
		"." + pad3("g", generation) +
		"." + pad3("part", part) +
		".zip"
}

// agentPartNameDrifted mirrors the PRE-FIX FilesArchiver: `<component>.partMMM.zip`
// with NO generation namespace — so gen-0 and gen-1 collide by name.
func agentPartNameDrifted(component string, _ /*generation*/, part int) string {
	return component + "." + pad3("part", part) + ".zip"
}

func pad3(prefix string, n int) string {
	s := prefix
	digits := ""
	switch {
	case n >= 100:
		digits = itoa(n)
	case n >= 10:
		digits = "0" + itoa(n)
	default:
		digits = "00" + itoa(n)
	}
	return s + digits
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var b []byte
	for n > 0 {
		b = append([]byte{byte('0' + n%10)}, b...)
		n /= 10
	}
	return string(b)
}

// emitManifest builds the per-generation CP ManifestEntry rows the agent submits.
// `fixed` selects the post-fix contract (true) vs the pre-fix drift (false):
//
//	fixed=true:  files-list entry_kind = EntryKindFilesList,
//	             per-path tombstones entries (EntryKindTombstones, mode=Delete,
//	             EMPTY chunks), generation-namespaced part names.
//	fixed=false: files.list tagged EntryKindFile, a SINGLE "tombstones.list"
//	             file artifact (EntryKindFile with a chunk), colliding part names.
func emitManifest(g agentGen, fixed bool) []ManifestEntry {
	var entries []ManifestEntry

	// DB dump (gen carries one).
	if g.hasDB {
		entries = append(entries, ManifestEntry{
			Path:        "database.sql.gz",
			EntryKind:   EntryKindDB,
			ChunkHashes: []string{g.dbChunk},
		})
	}

	// Component archive parts.
	for _, p := range g.parts {
		var name string
		if fixed {
			name = agentPartNameFixed(p.component, g.generation, 1)
		} else {
			name = agentPartNameDrifted(p.component, g.generation, 1)
		}
		entries = append(entries, ManifestEntry{
			Path:        name,
			EntryKind:   componentKind(p.component),
			ChunkHashes: []string{p.chunk},
		})
	}

	// files.list artifact.
	flChunk := "fl-g" + itoa(g.generation)
	if fixed {
		// CRITICAL #1: the files.list artifact MUST be tagged "files-list".
		entries = append(entries, ManifestEntry{
			Path:        "files.list",
			EntryKind:   EntryKindFilesList,
			ChunkHashes: []string{flChunk},
		})
	} else {
		// PRE-FIX DRIFT: entryKind("files.list") fell through to "file".
		entries = append(entries, ManifestEntry{
			Path:        "files.list",
			EntryKind:   EntryKindFile,
			ChunkHashes: []string{flChunk},
		})
	}

	// Tombstones.
	if len(g.tombstoned) > 0 {
		if fixed {
			// CRITICAL #2: ONE per-path entry, entry_kind="tombstones",
			// mode=Delete, EMPTY chunk list.
			for _, path := range g.tombstoned {
				entries = append(entries, ManifestEntry{
					Path:        path,
					EntryKind:   EntryKindTombstones,
					Mode:        int32(agentcmd.TombstoneModeDelete),
					ChunkHashes: nil,
				})
			}
		} else {
			// PRE-FIX DRIFT: a single chunked "tombstones.list" file artifact
			// (entry_kind="file", carrying a chunk) — the CP overlay never reads
			// it as a tombstone, so the deletion is silently dropped.
			entries = append(entries, ManifestEntry{
				Path:        "tombstones.list",
				EntryKind:   EntryKindFile,
				ChunkHashes: []string{"tb-g" + itoa(g.generation)},
			})
		}
	}

	return entries
}

// ---------------------------------------------------------------------------
// Restore-overlay replay (mirrors class-restore-runner.php stage_files):
//   - download each Manifest.Entry to <scratch>/<logical_path> (a flat map
//     keyed by logical_path — so colliding part names overwrite each other,
//     exactly like the agent),
//   - extract parts in Manifest.Entries order (later overwrites earlier),
//   - delete every TombstonePaths entry from the staged tree.
// Returns the reconstructed relpath -> content tree.
// ---------------------------------------------------------------------------

func replayRestore(plan agentcmd.RestoreRequest, partFiles map[string]map[string]string) map[string]string {
	// 1. Download artifacts to a flat map keyed by logical_path. A collision
	//    (two entries with the same logical_path) overwrites — this is the
	//    agent's artifact_paths[$logical] = $outPath behaviour.
	staged := map[string]string{} // logical_path -> "ownership token" (which part's bytes landed)
	order := []string{}           // logical_path in Manifest.Entries order (download order)
	seen := map[string]bool{}
	for _, e := range plan.Manifest.Entries {
		if _, ok := staged[e.LogicalPath]; !ok && !seen[e.LogicalPath] {
			order = append(order, e.LogicalPath)
			seen[e.LogicalPath] = true
		}
		// The chunk hash is the part's identity here; last write wins (collision).
		if len(e.Chunks) > 0 {
			staged[e.LogicalPath] = e.Chunks[0].Hash
		}
	}

	// 2. Extract parts in download order into the tree. A later generation's
	//    part overwrites an earlier file (newest-wins). The bytes for a part
	//    are looked up by its logical_path -> chunk -> partFiles.
	tree := map[string]string{}
	for _, logical := range order {
		chunk, ok := staged[logical]
		if !ok {
			continue // db / files-list / tombstones-as-file: not a real extractable part here
		}
		files, ok := partFiles[chunk]
		if !ok {
			continue // not a component part (db dump, files.list, etc.)
		}
		for rel, content := range files {
			tree[rel] = content
		}
	}

	// 3. Apply tombstones (delete from the staged tree).
	for _, path := range plan.TombstonePaths {
		delete(tree, path)
	}

	return tree
}

// ---------------------------------------------------------------------------
// THE END-TO-END TEST.
// base (gen0) -> increment (gen1: change 2, add 1, delete 1) -> restore(gen1).
// Asserts: changed updated, added present, deleted GONE, unchanged carried.
// ---------------------------------------------------------------------------

func TestArchiveDeltaRoundTrip_AgentEmitToOverlayRestore(t *testing.T) {
	// The expected wp-content tree AFTER restoring the increment (gen-1):
	//   - plugins/keep.php       : UNCHANGED, carried forward from the base part.
	//   - plugins/changed.php    : CHANGED in gen-1 (new content wins).
	//   - themes/changed.css     : CHANGED in gen-1 (new content wins).
	//   - uploads/added.png      : ADDED in gen-1.
	//   - plugins/deleted.php     : DELETED in gen-1 (must be GONE).
	wantTree := map[string]string{
		"plugins/keep.php":    "KEEP-v0",
		"plugins/changed.php": "CHANGED-v1",
		"themes/changed.css":  "CSS-v1",
		"uploads/added.png":   "PNG-v1",
	}

	// gen-0 base: a full backup. The plugins part holds keep.php +
	// changed.php@v0 + deleted.php; the themes part holds changed.css@v0.
	gen0 := agentGen{
		generation: 0,
		hasDB:      true,
		dbChunk:    "db0",
		parts: []agentPart{
			{component: "plugins", chunk: "g0-plugins", files: map[string]string{
				"plugins/keep.php":    "KEEP-v0",
				"plugins/changed.php": "CHANGED-v0",
				"plugins/deleted.php": "DELETED-v0",
			}},
			{component: "themes", chunk: "g0-themes", files: map[string]string{
				"themes/changed.css": "CSS-v0",
			}},
		},
	}

	// gen-1 increment: re-packs ONLY the changed/added files (archive-delta), and
	// tombstones the deleted path. keep.php is NOT re-packed (carry-forward).
	gen1 := agentGen{
		generation: 1,
		hasDB:      false,
		parts: []agentPart{
			// Re-packed plugins part: changed.php@v1 (keep.php carried forward,
			// deleted.php gone).
			{component: "plugins", chunk: "g1-plugins", files: map[string]string{
				"plugins/changed.php": "CHANGED-v1",
			}},
			// Re-packed themes part: changed.css@v1.
			{component: "themes", chunk: "g1-themes", files: map[string]string{
				"themes/changed.css": "CSS-v1",
			}},
			// New uploads part: added.png.
			{component: "uploads", chunk: "g1-uploads", files: map[string]string{
				"uploads/added.png": "PNG-v1",
			}},
		},
		tombstoned: []string{"plugins/deleted.php"},
	}

	// partFiles maps each part's chunk hash to the files it carries — the test's
	// stand-in for "extract this zip part".
	partFiles := map[string]map[string]string{}
	for _, g := range []agentGen{gen0, gen1} {
		for _, p := range g.parts {
			partFiles[p.chunk] = p.files
		}
	}

	t.Run("fixed_contract_reconstructs_exact_tree", func(t *testing.T) {
		got := runOverlayRoundTrip(t, gen0, gen1, partFiles, true /*fixed*/)
		assertTreeEqual(t, wantTree, got)
	})

	t.Run("drifted_contract_breaks_reconstruction", func(t *testing.T) {
		got := runOverlayRoundTrip(t, gen0, gen1, partFiles, false /*drifted*/)
		// On the PRE-FIX drift the reconstruction MUST be wrong. Specifically:
		//   - the CP never detects archive-delta (files.list tagged "file"), so
		//     the overlay path doesn't run the way the fixed path does, AND
		//   - even if parts overlay, the deleted path is NOT tombstoned (the
		//     "tombstones.list" file artifact is never read as a tombstone), AND
		//   - the colliding gen-0/gen-1 part names clobber carry-forward.
		// Any one of these makes the tree != wantTree. Assert it differs.
		if treeEqual(wantTree, got) {
			t.Fatalf("drifted contract unexpectedly reconstructed the exact tree — the test no longer catches the drift")
		}
	})
}

// runOverlayRoundTrip wires a chainFakeRepo from the agent emit, drives the REAL
// planRestoreChain overlay, then replays the restore and returns the tree.
func runOverlayRoundTrip(t *testing.T, gen0, gen1 agentGen, partFiles map[string]map[string]string, fixed bool) map[string]string {
	t.Helper()

	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	snap0 := mkSnap(tenantID, siteID, chainID, 0, false)
	snap1 := mkSnap(tenantID, siteID, chainID, 1, true)

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, snap0)
	repo.addChainSnap(chainID, snap1)

	entries0 := emitManifest(gen0, fixed)
	entries1 := emitManifest(gen1, fixed)
	repo.addManifest(snap0.ID, entries0)
	repo.addManifest(snap1.ID, entries1)

	// Register every chunk hash referenced by either generation as "stored".
	for _, es := range [][]ManifestEntry{entries0, entries1} {
		for _, e := range es {
			for _, h := range e.ChunkHashes {
				repo.addChunk(h)
			}
		}
	}

	svc, _ := buildChainSvc(repo, tenantID)

	plan, _, _, err := svc.PlanRestore(
		context.Background(), tenantID, snap1.ID,
		RestoreSelection{Full: true}, "restore-e2e", "https://cp.test/progress",
	)
	if err != nil {
		t.Fatalf("PlanRestore returned error (fixed=%v): %v", fixed, err)
	}

	return replayRestore(plan, partFiles)
}

func assertTreeEqual(t *testing.T, want, got map[string]string) {
	t.Helper()
	if !treeEqual(want, got) {
		t.Errorf("reconstructed tree mismatch:\n  want: %v\n  got:  %v", want, got)
		for rel, w := range want {
			if g, ok := got[rel]; !ok {
				t.Errorf("  MISSING %q (want %q)", rel, w)
			} else if g != w {
				t.Errorf("  WRONG   %q: want %q got %q", rel, w, g)
			}
		}
		for rel := range got {
			if _, ok := want[rel]; !ok {
				t.Errorf("  EXTRA   %q (should be gone) = %q", rel, got[rel])
			}
		}
	}
}

func treeEqual(a, b map[string]string) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if b[k] != v {
			return false
		}
	}
	return true
}
