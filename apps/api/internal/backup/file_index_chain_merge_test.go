package backup

// file_index_chain_merge_test.go — proves the CHAIN-MERGED /file-index fix
// (task #181). The agent's previous-index must be the chain-MERGED effective
// tree (the same view PlanRestore reconstructs), not a single increment's delta
// rows. These tests drive StreamChainEffectiveFileIndex — the exact repo method
// the fileIndex endpoint streams for a chained increment — through the faithful
// chainFakeRepo (which mirrors the pgRepo merge: ListChainSnapshots per
// generation + latest-version-wins winMap + tombstone delete + sort by path).

import (
	"context"
	"testing"

	"github.com/google/uuid"
)

// collectEffective drains StreamChainEffectiveFileIndex into a path->entry map
// (and an ordered path slice to assert sort order) for one chain up to maxGen.
func collectEffective(t *testing.T, repo *chainFakeRepo, tenantID, chainID uuid.UUID, maxGen int) (map[string]FileIndexEntry, []string) {
	t.Helper()
	byPath := map[string]FileIndexEntry{}
	var order []string
	err := repo.StreamChainEffectiveFileIndex(context.Background(), tenantID, chainID, maxGen, func(e FileIndexEntry) error {
		if e.IsTombstone {
			t.Errorf("merged effective index must never emit a tombstone, got one for %q", e.FilePath)
		}
		if _, dup := byPath[e.FilePath]; dup {
			t.Errorf("merged effective index emitted %q more than once", e.FilePath)
		}
		byPath[e.FilePath] = e
		order = append(order, e.FilePath)
		return nil
	})
	if err != nil {
		t.Fatalf("StreamChainEffectiveFileIndex error: %v", err)
	}
	return byPath, order
}

// TestFileIndexChainMerge_Gen0BaseServesFullIndex — a gen-0 base, merged up to
// its own generation, serves exactly its full file index. This is the view the
// endpoint relies on for the base case (the merged tree over generations 0..0 ==
// the base's own rows), so a gen-1 increment computed against it is correct.
func TestFileIndexChainMerge_Gen0BaseServesFullIndex(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false) // base, is_incremental=false

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.fileIndexRows[gen0.ID] = []FileIndexEntry{
		{FilePath: "wp-content/plugins/foo/foo.php", FileSize: 100, FileBlake3: "b3-foo-0", ChunkHashes: []string{"aaa"}},
		{FilePath: "wp-content/themes/bar/style.css", FileSize: 200, FileBlake3: "b3-bar-0", ChunkHashes: []string{"bbb"}},
		{FilePath: "wp-includes/version.php", FileSize: 50, FileBlake3: "b3-ver-0", ChunkHashes: []string{"ccc"}},
	}

	byPath, order := collectEffective(t, repo, tenantID, chainID, gen0.Generation)

	if len(byPath) != 3 {
		t.Fatalf("expected 3 entries in the base index, got %d (%v)", len(byPath), order)
	}
	for _, want := range []struct {
		path   string
		size   int64
		blake3 string
	}{
		{"wp-content/plugins/foo/foo.php", 100, "b3-foo-0"},
		{"wp-content/themes/bar/style.css", 200, "b3-bar-0"},
		{"wp-includes/version.php", 50, "b3-ver-0"},
	} {
		got, ok := byPath[want.path]
		if !ok {
			t.Errorf("base index missing %q", want.path)
			continue
		}
		if got.FileSize != want.size || got.FileBlake3 != want.blake3 {
			t.Errorf("%q: got size=%d blake3=%q, want size=%d blake3=%q",
				want.path, got.FileSize, got.FileBlake3, want.size, want.blake3)
		}
	}

	// Output must be sorted by file_path ASC (the endpoint's stream contract).
	if !sortedAsc(order) {
		t.Errorf("base index not sorted by file_path: %v", order)
	}
}

// TestFileIndexChainMerge_Gen1MergesLatestWins — a gen-1 increment off a gen-0
// base, merged up to gen 1, serves base ⊕ inc1 with latest-version-wins:
//   - a file CHANGED in inc1 shows inc1's version (newer size/blake3),
//   - a file TOMBSTONED in inc1 is ABSENT,
//   - an UNCHANGED file (only present in the base) shows the base version,
//   - a file ADDED in inc1 is present.
func TestFileIndexChainMerge_Gen1MergesLatestWins(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	chainID := uuid.New()

	gen0 := mkSnap(tenantID, siteID, chainID, 0, false) // base
	gen1 := mkSnap(tenantID, siteID, chainID, 1, true)  // increment

	repo := newChainFakeRepo()
	repo.addChainSnap(chainID, gen0)
	repo.addChainSnap(chainID, gen1)

	// Base: three files.
	repo.fileIndexRows[gen0.ID] = []FileIndexEntry{
		{FilePath: "wp-content/plugins/foo/foo.php", FileSize: 100, FileBlake3: "b3-foo-base", ChunkHashes: []string{"aaa"}},
		{FilePath: "wp-content/themes/bar/style.css", FileSize: 200, FileBlake3: "b3-bar-base", ChunkHashes: []string{"bbb"}},
		{FilePath: "wp-content/uploads/old.jpg", FileSize: 9000, FileBlake3: "b3-old-base", ChunkHashes: []string{"ddd"}},
	}
	// Increment: foo.php CHANGED, old.jpg TOMBSTONED, new.php ADDED.
	repo.fileIndexRows[gen1.ID] = []FileIndexEntry{
		{FilePath: "wp-content/plugins/foo/foo.php", FileSize: 175, FileBlake3: "b3-foo-inc1", ChunkHashes: []string{"eee"}},
		{FilePath: "wp-content/uploads/old.jpg", IsTombstone: true},
		{FilePath: "wp-content/plugins/new/new.php", FileSize: 42, FileBlake3: "b3-new-inc1", ChunkHashes: []string{"fff"}},
	}

	byPath, order := collectEffective(t, repo, tenantID, chainID, gen1.Generation)

	// Tombstoned file must be ABSENT.
	if _, ok := byPath["wp-content/uploads/old.jpg"]; ok {
		t.Error("tombstoned file wp-content/uploads/old.jpg must be absent from the merged index")
	}

	// Changed file must show inc1's version.
	foo, ok := byPath["wp-content/plugins/foo/foo.php"]
	if !ok {
		t.Fatal("changed file wp-content/plugins/foo/foo.php missing from merged index")
	}
	if foo.FileSize != 175 || foo.FileBlake3 != "b3-foo-inc1" || len(foo.ChunkHashes) != 1 || foo.ChunkHashes[0] != "eee" {
		t.Errorf("changed file did not win: got size=%d blake3=%q chunks=%v; want inc1 (175/b3-foo-inc1/[eee])",
			foo.FileSize, foo.FileBlake3, foo.ChunkHashes)
	}

	// Unchanged file must show the BASE version.
	bar, ok := byPath["wp-content/themes/bar/style.css"]
	if !ok {
		t.Fatal("unchanged file wp-content/themes/bar/style.css missing from merged index")
	}
	if bar.FileSize != 200 || bar.FileBlake3 != "b3-bar-base" || len(bar.ChunkHashes) != 1 || bar.ChunkHashes[0] != "bbb" {
		t.Errorf("unchanged file should show base version: got size=%d blake3=%q chunks=%v; want base (200/b3-bar-base/[bbb])",
			bar.FileSize, bar.FileBlake3, bar.ChunkHashes)
	}

	// Added file must be present with inc1's version.
	newf, ok := byPath["wp-content/plugins/new/new.php"]
	if !ok {
		t.Fatal("added file wp-content/plugins/new/new.php missing from merged index")
	}
	if newf.FileSize != 42 || newf.FileBlake3 != "b3-new-inc1" {
		t.Errorf("added file wrong version: got size=%d blake3=%q; want 42/b3-new-inc1", newf.FileSize, newf.FileBlake3)
	}

	// Effective tree = {foo (inc1), bar (base), new (inc1)} — exactly 3 entries.
	if len(byPath) != 3 {
		t.Errorf("expected 3 surviving entries, got %d (%v)", len(byPath), order)
	}

	// Output must be sorted by file_path ASC.
	if !sortedAsc(order) {
		t.Errorf("merged index not sorted by file_path: %v", order)
	}
}

func sortedAsc(s []string) bool {
	for i := 1; i < len(s); i++ {
		if s[i-1] > s[i] {
			return false
		}
	}
	return true
}
