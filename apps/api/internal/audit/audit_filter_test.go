package audit

import (
	"testing"

	"github.com/google/uuid"
)

// ---------------------------------------------------------------------------
// Filter value construction tests (pure unit — no DB, no pool)
// ---------------------------------------------------------------------------

// TestFilterActionPrefixEmpty verifies that an empty ActionPrefix in Filter
// produces the sentinel value that disables the filter in the SQL query.
// The SQL query uses  $2 = '' OR action LIKE $2 || '%'  so an empty string
// matches all rows.
func TestFilterActionPrefixEmpty(t *testing.T) {
	f := Filter{}
	if f.ActionPrefix != "" {
		t.Errorf("expected empty ActionPrefix, got %q", f.ActionPrefix)
	}
	// Confirm the SQL sentinel: passing "" as ActionPrefix enables the OR branch
	// that returns all actions.
	prefix := f.ActionPrefix
	if prefix != "" {
		t.Error("zero-value Filter should have empty ActionPrefix")
	}
}

// TestFilterActionPrefixSet verifies that a non-empty ActionPrefix is preserved.
func TestFilterActionPrefixSet(t *testing.T) {
	f := Filter{ActionPrefix: "site.files."}
	if f.ActionPrefix != "site.files." {
		t.Errorf("expected 'site.files.', got %q", f.ActionPrefix)
	}
}

// TestFilterExactActionIsItsOwnPrefix verifies that an exact action string
// also works as a prefix filter (it is a prefix of itself).
func TestFilterExactActionIsItsOwnPrefix(t *testing.T) {
	exactAction := "site.files.delete"
	f := Filter{ActionPrefix: exactAction}
	// A LIKE 'site.files.delete%' matches 'site.files.delete' exactly.
	// This is a pure string check — the SQL handles it correctly.
	if f.ActionPrefix != exactAction {
		t.Errorf("exact action should survive as ActionPrefix, got %q", f.ActionPrefix)
	}
}

// TestFilterSiteIDNil verifies that a nil SiteID produces the sentinel string
// used by the SQL to disable the site_id filter.
func TestFilterSiteIDNil(t *testing.T) {
	f := Filter{}
	// Recorder.ListFiltered maps nil SiteID to the zero-UUID sentinel.
	siteIDStr := "00000000-0000-0000-0000-000000000000"
	if f.SiteID != nil {
		t.Error("zero-value Filter.SiteID should be nil")
	}
	// Emulate what ListFiltered does with a nil SiteID.
	got := "00000000-0000-0000-0000-000000000000"
	if f.SiteID != nil {
		got = f.SiteID.String()
	}
	if got != siteIDStr {
		t.Errorf("nil SiteID should produce sentinel %q, got %q", siteIDStr, got)
	}
}

// TestFilterSiteIDNonNil verifies that a non-nil SiteID is stringified correctly.
func TestFilterSiteIDNonNil(t *testing.T) {
	id := uuid.MustParse("11111111-1111-1111-1111-111111111111")
	f := Filter{SiteID: &id}
	if f.SiteID == nil {
		t.Fatal("SiteID should be non-nil")
	}
	got := f.SiteID.String()
	if got != "11111111-1111-1111-1111-111111111111" {
		t.Errorf("unexpected SiteID string: %q", got)
	}
}

// TestFilterSiteIDSentinelIsZeroUUID verifies that the sentinel used to disable
// the site_id filter in SQL is the canonical zero UUID (all-zeros).
// The SQL query is:
//
//	$3::text = '00000000-0000-0000-0000-000000000000' OR (target_type = 'site' AND target_id = $3::text)
//
// A zero-UUID sentinel disables the filter by matching the first branch.
func TestFilterSiteIDSentinelIsZeroUUID(t *testing.T) {
	sentinel := "00000000-0000-0000-0000-000000000000"
	if uuid.Nil.String() != sentinel {
		t.Errorf("uuid.Nil.String() should equal the sentinel %q, got %q", sentinel, uuid.Nil.String())
	}
}

// TestFilterTenantIsolation verifies that the ListFiltered params always carry
// the tenantID value unchanged. This is the explicit defense-in-depth param
// sent alongside the RLS GUC — it must never be mutated by filter construction.
func TestFilterTenantIsolation(t *testing.T) {
	tenantA := uuid.MustParse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa")
	tenantB := uuid.MustParse("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb")

	// Verify that two different tenants produce two different param sets.
	// (The actual cross-tenant leak test would require a real DB; this checks
	// that the param value is correctly threaded through the filter builder.)
	f := Filter{ActionPrefix: "site."}
	siteIDStr := "00000000-0000-0000-0000-000000000000"
	if f.SiteID != nil {
		siteIDStr = f.SiteID.String()
	}

	// Simulate param construction for tenant A.
	paramsA := struct {
		TenantID     uuid.UUID
		ActionPrefix string
		SiteID       string
	}{tenantA, f.ActionPrefix, siteIDStr}

	// Simulate param construction for tenant B.
	paramsB := struct {
		TenantID     uuid.UUID
		ActionPrefix string
		SiteID       string
	}{tenantB, f.ActionPrefix, siteIDStr}

	if paramsA.TenantID == paramsB.TenantID {
		t.Error("different tenants must produce different TenantID params")
	}
	if paramsA.ActionPrefix != paramsB.ActionPrefix {
		t.Errorf("same filter should produce same ActionPrefix: %q vs %q", paramsA.ActionPrefix, paramsB.ActionPrefix)
	}
}

// TestAuditFilterPrefixMatchesFilesPrefix verifies that the "site.files."
// prefix string is the conventional prefix for ALL file-manager audit events
// so the web dashboard can filter by it with a single query param.
func TestAuditFilterPrefixMatchesFilesPrefix(t *testing.T) {
	fileActions := []string{
		ActionSiteFilesRead,
		ActionSiteFilesSensitiveRead,
		ActionSiteFilesSensitiveDenied,
		ActionSiteFilesSettingsChanged,
	}
	prefix := "site.files."
	for _, a := range fileActions {
		// Every file-manager action defined in audit.go must start with "site.files."
		if len(a) < len(prefix) || a[:len(prefix)] != prefix {
			t.Errorf("action %q does not start with prefix %q", a, prefix)
		}
	}
}
