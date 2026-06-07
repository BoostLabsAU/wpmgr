package backup

// restore_chain_overlay_test.go — ADR-051 archive-delta OVERLAY restore tests.
// An archive-delta chain restores by overlaying whole zip PARTS in generation
// order (base first; a later generation's file wins because ZipArchive extractTo
// overwrites in array order) plus a tombstone-delete pass with newest-wins
// un-delete. The model is detected by the gen-0 base carrying a `files-list`
// manifest entry. These tests drive planRestoreChain through the chainFakeRepo.

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
)

// filesListEntry builds the per-snapshot files-list manifest entry that flags a
// snapshot as archive-delta (its chunk content is irrelevant to the planner).
func filesListEntry(hash string) ManifestEntry {
	return ManifestEntry{Path: "files.list", EntryKind: EntryKindFilesList, ChunkHashes: []string{hash}}
}

// partEntry builds a zip-PART manifest entry (one component archive part).
func partEntry(path, kind string, chunkHashes ...string) ManifestEntry {
	return ManifestEntry{Path: path, EntryKind: kind, ChunkHashes: chunkHashes}
}

// tombstoneEntry builds a per-path tombstone manifest entry (mode delta).
func tombstoneEntry(path string, mode int) ManifestEntry {
	return ManifestEntry{Path: path, EntryKind: EntryKindTombstones, Mode: int32(mode)}
}

// orderOf returns the index of the FIRST manifest entry whose LogicalPath equals
// path, or -1 if absent. Used to assert ascending-generation overlay order.
func orderOf(entries []agentcmd.RestoreEntry, path string) int {
	for i, e := range entries {
		if e.LogicalPath == path {
			return i
		}
	}
	return -1
}

// ---------------------------------------------------------------------------
// OV-1: latest-wins overlay — base part first, increment part after, so a later
// generation's file wins on extract; the highest-gen DB dump is appended last.
// ---------------------------------------------------------------------------

func TestPlanRestoreChainOverlay_LatestWins(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false)
	gen1 := mkSnap(tenantID, siteID, chainID, 1, true)
	gen2 := mkSnap(tenantID, siteID, chainID, 2, true)

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.addChainSnap(chainID, gen1)
	repo.addChainSnap(chainID, gen2)

	// gen0: base full — plugins part + a DB dump + a files-list (model flag).
	repo.addManifest(gen0.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "p0"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db0"}},
		filesListEntry("fl0"),
	})
	// gen1: increment — re-packed plugins part (newer) + files-list, no DB.
	repo.addManifest(gen1.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "p1"),
		filesListEntry("fl1"),
	})
	// gen2: increment — a themes part + a fresher DB dump + files-list.
	repo.addManifest(gen2.ID, []ManifestEntry{
		partEntry("themes.part001.zip", EntryKindTheme, "t2"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db2"}},
		filesListEntry("fl2"),
	})

	for _, h := range []string{"p0", "p1", "t2", "db0", "db2", "fl0", "fl1", "fl2"} {
		repo.addChunk(h)
	}

	svc, _ := buildChainSvc(repo, tenantID)

	plan, snap, _, err := svc.PlanRestore(
		context.Background(), tenantID, gen2.ID,
		RestoreSelection{Full: true}, "restore-ov1", "https://cp.test/progress",
	)
	if err != nil {
		t.Fatalf("PlanRestore returned error: %v", err)
	}
	if !plan.IsChainRestore || plan.TargetGeneration != 2 {
		t.Fatalf("expected chain restore to gen 2, got chain=%v gen=%d", plan.IsChainRestore, plan.TargetGeneration)
	}

	// files-list and tombstones must NOT appear as restore parts.
	for _, e := range plan.Manifest.Entries {
		if e.LogicalPath == "files.list" {
			t.Error("files.list must not be sent as a restore part")
		}
	}

	// OVERLAY ORDER: gen0's plugins part must come BEFORE gen1's plugins part
	// (same LogicalPath) — but since both share "plugins.part001.zip", we assert
	// the two part entries appear in ascending generation order by checking the
	// chunk sequence: the first occurrence is gen0 (p0), the second is gen1 (p1).
	var pluginChunks []string
	for _, e := range plan.Manifest.Entries {
		if e.LogicalPath == "plugins.part001.zip" {
			if len(e.Chunks) == 1 {
				pluginChunks = append(pluginChunks, e.Chunks[0].Hash)
			}
		}
	}
	if len(pluginChunks) != 2 || pluginChunks[0] != "p0" || pluginChunks[1] != "p1" {
		t.Errorf("plugins parts must be ascending by generation (p0 then p1), got %v", pluginChunks)
	}

	// The themes part (gen2) must come AFTER both plugins parts (gen0/gen1).
	if to, p1o := orderOf(plan.Manifest.Entries, "themes.part001.zip"), 0; to < 0 {
		t.Error("themes part missing")
	} else {
		// Count plugins entries before the themes entry.
		for i, e := range plan.Manifest.Entries {
			if e.LogicalPath == "plugins.part001.zip" {
				p1o = i
			}
		}
		if to < p1o {
			t.Errorf("themes part (gen2, idx %d) must come after the last plugins part (idx %d)", to, p1o)
		}
	}

	// DB dump: the highest-gen one (gen2, db2) must be selected and appended.
	var dbChunks []string
	for _, e := range plan.Manifest.Entries {
		if e.LogicalPath == "database.sql.gz" {
			for _, c := range e.Chunks {
				dbChunks = append(dbChunks, c.Hash)
			}
		}
	}
	if len(dbChunks) != 1 || dbChunks[0] != "db2" {
		t.Errorf("expected the highest-gen DB dump (db2), got %v", dbChunks)
	}

	// dbSnapGen is stashed in snap.CycleFilesScanned (worker SSE convention).
	if snap.CycleFilesScanned != 2 {
		t.Errorf("expected db_snap_generation=2, got %d", snap.CycleFilesScanned)
	}

	if len(plan.TombstonePaths) != 0 {
		t.Errorf("expected no tombstones, got %v", plan.TombstonePaths)
	}
}

// ---------------------------------------------------------------------------
// OV-2: a tombstone removes a file. A path deleted in gen1 must appear in
// TombstonePaths (the agent deletes it from staging after extraction).
// ---------------------------------------------------------------------------

func TestPlanRestoreChainOverlay_TombstoneRemovesFile(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false)
	gen1 := mkSnap(tenantID, siteID, chainID, 1, true)

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.addChainSnap(chainID, gen1)

	repo.addManifest(gen0.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "p0"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db0"}},
		filesListEntry("fl0"),
	})
	// gen1: deletes a file (tombstone delta) + emits a files-list. No new part.
	repo.addManifest(gen1.ID, []ManifestEntry{
		tombstoneEntry("wp-content/plugins/old/old.php", agentcmd.TombstoneModeDelete),
		filesListEntry("fl1"),
	})

	for _, h := range []string{"p0", "db0", "fl0", "fl1"} {
		repo.addChunk(h)
	}

	svc, _ := buildChainSvc(repo, tenantID)

	plan, _, _, err := svc.PlanRestore(
		context.Background(), tenantID, gen1.ID,
		RestoreSelection{Full: true}, "restore-ov2", "https://cp.test/progress",
	)
	if err != nil {
		t.Fatalf("PlanRestore returned error: %v", err)
	}

	if len(plan.TombstonePaths) != 1 || plan.TombstonePaths[0] != "wp-content/plugins/old/old.php" {
		t.Fatalf("expected one tombstone for old.php, got %v", plan.TombstonePaths)
	}
	// The tombstoned path must not be sent as a part.
	for _, e := range plan.Manifest.Entries {
		if e.LogicalPath == "wp-content/plugins/old/old.php" {
			t.Error("a tombstoned path must not appear as a restore part")
		}
	}
	// The base plugins part (carry-forward) must still be present.
	if orderOf(plan.Manifest.Entries, "plugins.part001.zip") < 0 {
		t.Error("base plugins part must still be in the overlay (carry-forward)")
	}
}

// ---------------------------------------------------------------------------
// OV-3: newest-wins un-delete. A file deleted in gen1 then re-added (Readd
// delta) in gen2 must NOT be tombstoned at gen2.
// ---------------------------------------------------------------------------

func TestPlanRestoreChainOverlay_ReaddCancelsTombstone(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false)
	gen1 := mkSnap(tenantID, siteID, chainID, 1, true)
	gen2 := mkSnap(tenantID, siteID, chainID, 2, true)

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.addChainSnap(chainID, gen1)
	repo.addChainSnap(chainID, gen2)

	repo.addManifest(gen0.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "p0"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db0"}},
		filesListEntry("fl0"),
	})
	// gen1 deletes the file.
	repo.addManifest(gen1.ID, []ManifestEntry{
		tombstoneEntry("wp-content/plugins/x/x.php", agentcmd.TombstoneModeDelete),
		filesListEntry("fl1"),
	})
	// gen2 re-adds the file (repacks the plugins part + emits a Readd delta).
	repo.addManifest(gen2.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "p2"),
		tombstoneEntry("wp-content/plugins/x/x.php", agentcmd.TombstoneModeReadd),
		filesListEntry("fl2"),
	})

	for _, h := range []string{"p0", "p2", "db0", "fl0", "fl1", "fl2"} {
		repo.addChunk(h)
	}

	svc, _ := buildChainSvc(repo, tenantID)

	// Restore to gen2: x.php was re-added → NOT in tombstones.
	plan2, _, _, err := svc.PlanRestore(
		context.Background(), tenantID, gen2.ID,
		RestoreSelection{Full: true}, "restore-ov3-g2", "https://cp.test/progress",
	)
	if err != nil {
		t.Fatalf("gen2 PlanRestore error: %v", err)
	}
	for _, p := range plan2.TombstonePaths {
		if p == "wp-content/plugins/x/x.php" {
			t.Error("re-added file must NOT be tombstoned at gen2 (newest-wins un-delete)")
		}
	}

	// Restore to gen1: x.php is still deleted → IS in tombstones (sanity).
	plan1, _, _, err := svc.PlanRestore(
		context.Background(), tenantID, gen1.ID,
		RestoreSelection{Full: true}, "restore-ov3-g1", "https://cp.test/progress",
	)
	if err != nil {
		t.Fatalf("gen1 PlanRestore error: %v", err)
	}
	found := false
	for _, p := range plan1.TombstonePaths {
		if p == "wp-content/plugins/x/x.php" {
			found = true
		}
	}
	if !found {
		t.Errorf("at gen1 the file is still deleted; expected it in tombstones, got %v", plan1.TombstonePaths)
	}
}

// ---------------------------------------------------------------------------
// OV-4: reachableChunks (archive-delta case-b) unions every retained gen's
// zip-part + files-list + tombstones chunks + the highest-gen DB dump, and a
// carry-forward part chunk in gen0 stays reachable when only gen2 is the tip.
// ---------------------------------------------------------------------------

func TestReachableChunks_ArchiveDelta_CarryForward(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false)
	gen1 := mkSnap(tenantID, siteID, chainID, 1, true)
	gen2 := mkSnap(tenantID, siteID, chainID, 2, true)

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.addChainSnap(chainID, gen1)
	repo.addChainSnap(chainID, gen2)

	// gen0: a carry-forward plugins part (never re-packed) + DB dump + files-list.
	repo.addManifest(gen0.ID, []ManifestEntry{
		partEntry("plugins.part001.zip", EntryKindPlugin, "carry"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db0"}},
		filesListEntry("fl0"),
	})
	// gen1: a new uploads part + files-list.
	repo.addManifest(gen1.ID, []ManifestEntry{
		partEntry("uploads.part001.zip", EntryKindUpload, "g1"),
		filesListEntry("fl1"),
	})
	// gen2: a themes part + a fresher DB + a tombstone + files-list.
	repo.addManifest(gen2.ID, []ManifestEntry{
		partEntry("themes.part001.zip", EntryKindTheme, "g2"),
		{Path: "database.sql.gz", EntryKind: EntryKindDB, ChunkHashes: []string{"db2"}},
		tombstoneEntry("wp-content/gone.php", agentcmd.TombstoneModeDelete),
		filesListEntry("fl2"),
	})

	svc, _ := buildChainSvc(repo, tenantID)

	reach, err := svc.reachableChunks(context.Background(), tenantID, gen2, 2)
	if err != nil {
		t.Fatalf("reachableChunks error: %v", err)
	}

	// Carry-forward gen0 part chunk + each gen's part + every files-list +
	// the highest-gen DB dump must all be reachable.
	for _, want := range []string{"carry", "g1", "g2", "fl0", "fl1", "fl2", "db2"} {
		if _, ok := reach[want]; !ok {
			t.Errorf("reachableChunks missing %q", want)
		}
	}
	// The OLDER DB dump (db0) is NOT reachable — only the highest-gen DB is kept.
	if _, ok := reach["db0"]; ok {
		t.Error("reachableChunks must not keep the superseded older DB dump (db0)")
	}
}
