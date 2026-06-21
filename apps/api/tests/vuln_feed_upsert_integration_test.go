// Integration test for the vulnerability feed upsert (m79 + m81 fix) and the
// bulk ingest path (timeout fix).
//
// TestUpsertFeedRecord_RoundTrip reproduces the original prod failure: UpsertFeedRecord
// failing with SQLSTATE 42601 ("syntax error at or near 'references'") because the
// column was named after a PostgreSQL reserved keyword.
//
// TestBulkIngest_Scale proves the bulk ingest path (BulkUpsertFeedRecords +
// BulkReplaceAllSoftware) that replaced the per-record loop scales to thousands
// of records without timing out and upserts idempotently on a second run.
package tests

import (
	"context"
	"encoding/json"
	"fmt"
	"testing"
	"time"

	"github.com/mosamlife/wpmgr/apps/api/internal/vuln"
)

func TestUpsertFeedRecord_RoundTrip(t *testing.T) {
	pool := startPostgres(t) // skips if Docker unavailable
	ctx := context.Background()

	score := 6.5
	pub := time.Date(1998, 1, 9, 0, 0, 0, 0, time.UTC)
	upd := time.Date(2022, 8, 5, 20, 14, 5, 0, time.UTC)

	affectedVersions, _ := json.Marshal(map[string]any{
		"1.0.0 - 1.2.3": map[string]any{
			"from_version":  "1.0.0",
			"from_inclusive": true,
			"to_version":    "1.2.3",
			"to_inclusive":  true,
		},
	})
	patchedVersions, _ := json.Marshal([]string{"1.2.4"})
	refURLs, _ := json.Marshal([]string{"https://www.wordfence.com/threat-intel/vulnerabilities/example"})
	cwe, _ := json.Marshal(map[string]any{"id": 80, "name": "Basic XSS"})
	raw, _ := json.Marshal(map[string]any{"_test": true})

	rec := vuln.FeedRecord{
		VulnID:        "848ccbdc-c6f1-480f-a272-cd459e706713",
		Title:         "Example Plugin <= 1.2.3 - Stored XSS",
		CVE:           "CVE-1998-1000",
		CVELink:       "https://www.cve.org/CVERecord?id=CVE-1998-1000",
		CVSSScore:     &score,
		CVSSRating:    "Medium",
		CWE:           cwe,
		Informational: false,
		References:    refURLs, // stored in reference_urls column after m81 rename
		Published:     &pub,
		Updated:       &upd,
		Raw:           raw,
		Software: []vuln.SoftwareRow{
			{
				Kind:             "plugin",
				Slug:             "example",
				AffectedVersions: affectedVersions,
				Patched:          true,
				PatchedVersions:  patchedVersions,
			},
		},
	}

	repo := vuln.NewRepo(pool)

	// Step 1: INSERT path — this is the statement that was failing with SQLSTATE
	// 42601 ("syntax error at or near 'references'") before the m81 rename.
	t.Run("insert_succeeds", func(t *testing.T) {
		tx, err := pool.Begin(ctx)
		if err != nil {
			t.Fatalf("begin tx: %v", err)
		}
		defer func() { _ = tx.Rollback(ctx) }()
		if _, err := tx.Exec(ctx, "SELECT set_config('app.agent','on',true)"); err != nil {
			t.Fatalf("set agent guc: %v", err)
		}
		if err := repo.UpsertFeedRecord(ctx, tx, rec); err != nil {
			t.Fatalf("UpsertFeedRecord (INSERT path) failed: %v", err)
		}
		if err := tx.Commit(ctx); err != nil {
			t.Fatalf("commit: %v", err)
		}
	})

	// Step 2: Verify the row persisted and reference_urls survived.
	t.Run("row_persisted_with_reference_urls", func(t *testing.T) {
		var title string
		var refURLsOut []byte
		err := pool.QueryRow(ctx,
			`SELECT title, reference_urls FROM wordfence_vuln_feed WHERE vuln_id = $1`,
			rec.VulnID,
		).Scan(&title, &refURLsOut)
		if err != nil {
			t.Fatalf("SELECT after upsert: %v", err)
		}
		if title != rec.Title {
			t.Errorf("title = %q; want %q", title, rec.Title)
		}
		var urls []string
		if err := json.Unmarshal(refURLsOut, &urls); err != nil {
			t.Fatalf("unmarshal reference_urls: %v", err)
		}
		if len(urls) != 1 || urls[0] != "https://www.wordfence.com/threat-intel/vulnerabilities/example" {
			t.Errorf("reference_urls = %v; want [https://www.wordfence.com/...]", urls)
		}
	})

	// Step 3: ON CONFLICT DO UPDATE path — the second query site of the original
	// error was in the ON CONFLICT SET clause which also referenced the
	// (unquoted) reserved keyword.
	t.Run("on_conflict_update_succeeds", func(t *testing.T) {
		updated := rec
		updated.Title = "Example Plugin <= 1.2.3 - Stored XSS (updated)"

		tx, err := pool.Begin(ctx)
		if err != nil {
			t.Fatalf("begin tx: %v", err)
		}
		defer func() { _ = tx.Rollback(ctx) }()
		if _, err := tx.Exec(ctx, "SELECT set_config('app.agent','on',true)"); err != nil {
			t.Fatalf("set agent guc: %v", err)
		}
		if err := repo.UpsertFeedRecord(ctx, tx, updated); err != nil {
			t.Fatalf("UpsertFeedRecord (ON CONFLICT path) failed: %v", err)
		}
		if err := tx.Commit(ctx); err != nil {
			t.Fatalf("commit: %v", err)
		}

		// Verify the title was updated.
		var gotTitle string
		if err := pool.QueryRow(ctx,
			`SELECT title FROM wordfence_vuln_feed WHERE vuln_id = $1`, rec.VulnID,
		).Scan(&gotTitle); err != nil {
			t.Fatalf("SELECT after ON CONFLICT update: %v", err)
		}
		if gotTitle != updated.Title {
			t.Errorf("title after update = %q; want %q", gotTitle, updated.Title)
		}
	})

	// Step 4: LookupSoftware — exercises the JOIN SELECT that uses reference_urls.
	t.Run("lookup_software_returns_reference_urls", func(t *testing.T) {
		rows, err := repo.LookupSoftware(ctx, "plugin", "example")
		if err != nil {
			t.Fatalf("LookupSoftware: %v", err)
		}
		if len(rows) != 1 {
			t.Fatalf("LookupSoftware returned %d rows; want 1", len(rows))
		}
		r := rows[0]
		if r.VulnID != rec.VulnID {
			t.Errorf("VulnID = %q; want %q", r.VulnID, rec.VulnID)
		}
		if r.CVE != rec.CVE {
			t.Errorf("CVE = %q; want %q", r.CVE, rec.CVE)
		}
		var urls []string
		if err := json.Unmarshal(r.References, &urls); err != nil {
			t.Fatalf("unmarshal References from LookupSoftware: %v", err)
		}
		if len(urls) != 1 {
			t.Errorf("References len = %d; want 1", len(urls))
		}
	})
}

// TestBulkIngest_Scale exercises the bulk ingest path that fixed the prod timeout.
//
// The per-record loop (UpsertFeedRecord called 13k times in one tx) blew the
// River job context deadline. BulkUpsertFeedRecords + BulkReplaceAllSoftware
// replaced it with a single pgx Batch round-trip + one DELETE + one CopyFrom.
//
// This test:
//   (a) generates 2,000 synthetic records with 2 software rows each (4,000 total),
//   (b) runs the full bulk path (the same operations ingestRecords calls),
//   (c) asserts row counts in both tables,
//   (d) asserts timing is well under 10 seconds,
//   (e) runs a second ingest with an overlapping set (1,800 of the original +
//       200 new) and asserts the ON CONFLICT upsert is idempotent (no dup-key),
//       the 200 new records landed, and the 200 removed ones were pruned by
//       PruneMissingVulns.
func TestBulkIngest_Scale(t *testing.T) {
	pool := startPostgres(t) // skips if Docker unavailable
	ctx := context.Background()
	repo := vuln.NewRepo(pool)

	const N = 2000 // number of synthetic records per run
	const softwarePerRec = 2

	// buildRecords generates n synthetic feed records. Each has two software rows
	// (one plugin, one theme) so we exercise the CopyFrom path non-trivially.
	buildRecords := func(n int, titlePrefix string) []vuln.FeedRecord {
		avJSON, _ := json.Marshal(map[string]any{
			"* - 1.0.0": map[string]any{
				"from_version": "*", "from_inclusive": true,
				"to_version": "1.0.0", "to_inclusive": true,
			},
		})
		pvJSON, _ := json.Marshal([]string{"1.0.1"})
		refJSON, _ := json.Marshal([]string{"https://example.com/vuln"})
		rawJSON, _ := json.Marshal(map[string]any{"_test": true})
		score := 7.5

		recs := make([]vuln.FeedRecord, n)
		for i := 0; i < n; i++ {
			vulnID := fmt.Sprintf("00000000-0000-0000-0000-%012d", i)
			recs[i] = vuln.FeedRecord{
				VulnID:        vulnID,
				Title:         fmt.Sprintf("%s record %d", titlePrefix, i),
				CVE:           fmt.Sprintf("CVE-2026-%04d", i),
				CVSSScore:     &score,
				CVSSRating:    "High",
				Informational: false,
				References:    refJSON,
				Raw:           rawJSON,
				Software: []vuln.SoftwareRow{
					{
						Kind:             "plugin",
						Slug:             fmt.Sprintf("plugin-%d", i),
						AffectedVersions: avJSON,
						Patched:          true,
						PatchedVersions:  pvJSON,
					},
					{
						Kind:             "theme",
						Slug:             fmt.Sprintf("theme-%d", i),
						AffectedVersions: avJSON,
						Patched:          false,
						PatchedVersions:  pvJSON,
					},
				},
			}
		}
		return recs
	}

	// bulkIngest runs the same three operations that ingestRecords uses.
	bulkIngest := func(t *testing.T, recs []vuln.FeedRecord) {
		t.Helper()
		tx, err := pool.Begin(ctx)
		if err != nil {
			t.Fatalf("begin tx: %v", err)
		}
		defer func() { _ = tx.Rollback(ctx) }()
		if _, err := tx.Exec(ctx, "SELECT set_config('app.agent','on',true)"); err != nil {
			t.Fatalf("set agent guc: %v", err)
		}
		if err := repo.BulkUpsertFeedRecords(ctx, tx, recs); err != nil {
			t.Fatalf("BulkUpsertFeedRecords: %v", err)
		}
		if err := repo.BulkReplaceAllSoftware(ctx, tx, recs); err != nil {
			t.Fatalf("BulkReplaceAllSoftware: %v", err)
		}
		knownIDs := make([]string, len(recs))
		for i, r := range recs {
			knownIDs[i] = r.VulnID
		}
		if err := repo.PruneMissingVulns(ctx, tx, knownIDs); err != nil {
			t.Fatalf("PruneMissingVulns: %v", err)
		}
		if err := tx.Commit(ctx); err != nil {
			t.Fatalf("commit: %v", err)
		}
	}

	countRows := func(t *testing.T, table string) int {
		t.Helper()
		var n int
		if err := pool.QueryRow(ctx, "SELECT count(*) FROM "+table).Scan(&n); err != nil {
			t.Fatalf("count %s: %v", table, err)
		}
		return n
	}

	// --- First ingest: 2,000 records ---
	t.Run("first_ingest_2000_records", func(t *testing.T) {
		recs := buildRecords(N, "first")
		start := time.Now()
		bulkIngest(t, recs)
		elapsed := time.Since(start)

		feedCount := countRows(t, "wordfence_vuln_feed")
		// The round-trip test may have left one extra row; allow for it.
		if feedCount < N {
			t.Errorf("wordfence_vuln_feed: got %d rows, want >= %d", feedCount, N)
		}

		swCount := countRows(t, "wordfence_vuln_software")
		if swCount < N*softwarePerRec {
			t.Errorf("wordfence_vuln_software: got %d rows, want >= %d", swCount, N*softwarePerRec)
		}

		// The bulk path should comfortably finish in under 10 seconds even on a
		// container with limited I/O. The per-record path took 13k+ round-trips;
		// the bulk path is 3 SQL operations.
		if elapsed > 10*time.Second {
			t.Errorf("bulk ingest of %d records took %v; want < 10s (bulk path should be fast)", N, elapsed)
		}
		t.Logf("first ingest: %d feed rows, %d software rows in %v", feedCount, swCount, elapsed)
	})

	// --- Second ingest: 1,800 overlapping + 200 new; 200 original removed ---
	t.Run("second_ingest_overlap_and_prune", func(t *testing.T) {
		const keep = 1800
		const newCount = 200
		// Overlap: first 1,800 records from the first batch (updated titles).
		overlap := buildRecords(keep, "second-update")
		// New: 200 brand-new records with IDs in the range [N, N+newCount).
		newRecs := make([]vuln.FeedRecord, newCount)
		avJSON, _ := json.Marshal(map[string]any{})
		pvJSON, _ := json.Marshal([]string{})
		refJSON, _ := json.Marshal([]string{"https://example.com/new"})
		rawJSON, _ := json.Marshal(map[string]any{"_test": true})
		score := 5.0
		for i := 0; i < newCount; i++ {
			idx := N + i
			vulnID := fmt.Sprintf("00000000-0000-0000-0000-%012d", idx)
			newRecs[i] = vuln.FeedRecord{
				VulnID:        vulnID,
				Title:         fmt.Sprintf("new record %d", idx),
				CVSSScore:     &score,
				CVSSRating:    "Medium",
				References:    refJSON,
				Raw:           rawJSON,
				Software: []vuln.SoftwareRow{
					{
						Kind: "plugin", Slug: fmt.Sprintf("new-plugin-%d", idx),
						AffectedVersions: avJSON, Patched: true, PatchedVersions: pvJSON,
					},
				},
			}
		}
		recs := append(overlap, newRecs...)

		bulkIngest(t, recs)

		feedCount := countRows(t, "wordfence_vuln_feed")
		wantFeed := keep + newCount
		if feedCount != wantFeed {
			t.Errorf("after second ingest: wordfence_vuln_feed = %d rows, want %d (keep=%d new=%d)",
				feedCount, wantFeed, keep, newCount)
		}

		// Verify updated title for one of the overlap records.
		var gotTitle string
		if err := pool.QueryRow(ctx,
			`SELECT title FROM wordfence_vuln_feed WHERE vuln_id = $1`,
			fmt.Sprintf("00000000-0000-0000-0000-%012d", 0),
		).Scan(&gotTitle); err != nil {
			t.Fatalf("SELECT overlap title: %v", err)
		}
		if gotTitle != "second-update record 0" {
			t.Errorf("overlap title = %q; want %q", gotTitle, "second-update record 0")
		}

		// Verify one of the new records landed.
		var newTitle string
		if err := pool.QueryRow(ctx,
			`SELECT title FROM wordfence_vuln_feed WHERE vuln_id = $1`,
			fmt.Sprintf("00000000-0000-0000-0000-%012d", N),
		).Scan(&newTitle); err != nil {
			t.Fatalf("SELECT new record title: %v", err)
		}
		if newTitle != fmt.Sprintf("new record %d", N) {
			t.Errorf("new record title = %q; want %q", newTitle, fmt.Sprintf("new record %d", N))
		}

		// Verify one of the pruned records is gone (IDs [keep..N-1]).
		var prunedCount int
		if err := pool.QueryRow(ctx,
			`SELECT count(*) FROM wordfence_vuln_feed WHERE vuln_id = $1`,
			fmt.Sprintf("00000000-0000-0000-0000-%012d", keep),
		).Scan(&prunedCount); err != nil {
			t.Fatalf("SELECT pruned check: %v", err)
		}
		if prunedCount != 0 {
			t.Errorf("pruned record still present after second ingest")
		}

		t.Logf("second ingest: %d feed rows (keep=%d new=%d pruned=%d)",
			feedCount, keep, newCount, N-keep)
	})
}
