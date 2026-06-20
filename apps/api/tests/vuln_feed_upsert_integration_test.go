// Integration test for the vulnerability feed upsert (m79 + m81 fix).
//
// This test reproduces the prod failure: UpsertFeedRecord failing for every
// record with SQLSTATE 42601 ("syntax error at or near 'references'") because
// the column was named after a PostgreSQL reserved keyword.
//
// The test:
//   1. Starts a real Postgres with all migrations applied.
//   2. Calls UpsertFeedRecord with a realistic record (non-empty reference_urls,
//      CVE, CVSS, software row).
//   3. Verifies the row persisted via a direct SELECT.
//   4. Calls UpsertFeedRecord again with a changed title to prove the ON CONFLICT
//      DO UPDATE path also works (this was the second site of the original error).
//   5. Calls LookupSoftware to verify the reference_urls JOIN survives a read.
//
// This test must FAIL against the pre-fix schema (the "references" column name
// causes 42601) and PASS after the m81 rename migration is applied.
package tests

import (
	"context"
	"encoding/json"
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
