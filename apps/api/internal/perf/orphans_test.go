package perf

import (
	"context"
	"encoding/json"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/dbclean"
)

// ---------------------------------------------------------------------------
// fake corpus for orphan tests (no DB required)
// ---------------------------------------------------------------------------

// fakeCorpus is an in-memory CorpusSource for testing the orphan pipeline.
type fakeCorpus struct {
	sigs []dbclean.Signature
}

func (f *fakeCorpus) GetPluginSignatures(_ context.Context, slug string) (dbclean.Signature, error) {
	for _, s := range f.sigs {
		if s.Slug == slug {
			return s, nil
		}
	}
	return dbclean.Signature{}, dbclean.ErrNotFound
}

func (f *fakeCorpus) AllSignatures(_ context.Context) ([]dbclean.Signature, error) {
	return f.sigs, nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// mustMarshal is a convenience wrapper that panics on marshal error (tests only).
func mustMarshal(v any) []byte {
	b, err := json.Marshal(v)
	if err != nil {
		panic("mustMarshal: " + err.Error())
	}
	return b
}

// buildScanResult constructs a DBScanResult with the supplied JSON blobs. Empty
// slice arguments default to "[]".
func buildScanResult(opts []agentcmd.OrphanedOptionItem, cron []agentcmd.OrphanedCronItem,
	tables []agentcmd.DBScanTableInventoryRow, plugins []agentcmd.InstalledPluginItem) DBScanResult {
	optJSON := mustMarshal(opts)
	cronJSON := mustMarshal(cron)
	tabJSON := mustMarshal(tables)
	plugJSON := mustMarshal(plugins)
	return DBScanResult{
		OrphanedOptionsJSON:  optJSON,
		OrphanedCronJSON:     cronJSON,
		TablesJSON:           tabJSON,
		InstalledPluginsJSON: plugJSON,
	}
}

// scanRepoReturning is a fakeRepo override for GetDBScanResult so we can inject
// a prepared scan result per test.
type scanRepoWith struct {
	fakeRepo
	result    DBScanResult
	scanFound bool
}

func (r *scanRepoWith) GetDBScanResult(_ context.Context, _, _ uuid.UUID) (DBScanResult, error) {
	if !r.scanFound {
		return DBScanResult{}, ErrNotFound
	}
	return r.result, nil
}

// ---------------------------------------------------------------------------
// unit tests
// ---------------------------------------------------------------------------

// TestGetOrphansReport_NoScan verifies a domain NotFound error is returned when
// no scan result exists in the repo.
func TestGetOrphansReport_NoScan(t *testing.T) {
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	corpus := &fakeCorpus{}
	_, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err == nil {
		t.Fatal("expected error, got nil")
	}
}

// TestGetOrphansReport_EmptySlices ensures a scan result with no orphans produces
// a valid empty report with zero counts.
func TestGetOrphansReport_EmptySlices(t *testing.T) {
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(nil, nil, nil, nil)}
	svc := NewService(repo, nil, nil, nil)
	corpus := &fakeCorpus{}
	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.Counts.Options != 0 || report.Counts.Cron != 0 || report.Counts.Tables != 0 {
		t.Fatalf("expected zero counts, got %+v", report.Counts)
	}
	if len(report.Options) != 0 || len(report.Cron) != 0 || len(report.Tables) != 0 {
		t.Fatal("expected empty slices in report")
	}
}

// TestGetOrphansReport_ExactClassification verifies that an orphaned option with
// a matching exact corpus pattern is classified at ConfidenceExact and is
// DeletableEligible when the owning plugin is not installed.
func TestGetOrphansReport_ExactClassification(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		{Slug: "my-plugin", CorpusVersion: 1, OptionPatterns: []string{"my_plugin_option"}},
	}}

	opts := []agentcmd.OrphanedOptionItem{
		{Name: "my_plugin_option", Autoload: true, SizeBytes: 128},
	}
	// A real installed snapshot that does NOT contain the owner "my-plugin", so
	// the cross-check is available (snapshot_available=true) and the item is
	// genuinely uninstalled.
	installed := []agentcmd.InstalledPluginItem{{Slug: "some-other-plugin", Name: "Other", Active: true, Source: "plugin"}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, installed)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !report.SnapshotAvailable {
		t.Error("expected snapshot_available=true with a non-empty installed set")
	}
	if len(report.Options) != 1 {
		t.Fatalf("expected 1 option item, got %d", len(report.Options))
	}
	item := report.Options[0]
	if item.Confidence != "exact" {
		t.Errorf("expected confidence=exact, got %q", item.Confidence)
	}
	if item.OwnerSlug != "my-plugin" {
		t.Errorf("expected owner_slug=my-plugin, got %q", item.OwnerSlug)
	}
	if !item.DeletableEligible {
		t.Error("expected deletable_eligible=true for exact single-candidate uninstalled item")
	}
	if item.Installed {
		t.Error("expected installed=false")
	}
}

// TestGetOrphansReport_InstalledSetCrossCheck verifies that an item whose owning
// plugin appears in the installed-set is excluded from the returned slices and
// counted in HiddenInstalled. Installed items are not real orphans and must not
// appear in the report.
func TestGetOrphansReport_InstalledSetCrossCheck(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		{Slug: "my-plugin", CorpusVersion: 1, OptionPatterns: []string{"my_plugin_option"}},
	}}

	opts := []agentcmd.OrphanedOptionItem{
		{Name: "my_plugin_option", Autoload: false, SizeBytes: 64},
	}
	// "my-plugin" IS installed.
	plugins := []agentcmd.InstalledPluginItem{
		{Slug: "my-plugin", Name: "My Plugin", Active: true, Source: "plugin"},
	}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, plugins)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	// The installed item must be excluded from the returned slice.
	if len(report.Options) != 0 {
		t.Fatalf("expected 0 option items (installed owner suppressed), got %d", len(report.Options))
	}
	// It must be counted in HiddenInstalled instead.
	if report.HiddenInstalled != 1 {
		t.Errorf("expected hidden_installed=1, got %d", report.HiddenInstalled)
	}
	if report.Counts.Options != 0 {
		t.Errorf("expected counts.options=0, got %d", report.Counts.Options)
	}
	if report.Counts.Deletable != 0 {
		t.Errorf("expected 0 deletable, got %d", report.Counts.Deletable)
	}
}

// TestGetOrphansReport_MultiCandidateNotDeletable verifies that an item matched
// by two corpus slugs (multi-candidate) is never DeletableEligible even when
// Confidence is prefix and the item is not installed.
func TestGetOrphansReport_MultiCandidateNotDeletable(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		{Slug: "plugin-a", CorpusVersion: 1, OptionPatterns: []string{"^shared_"}},
		{Slug: "plugin-b", CorpusVersion: 1, OptionPatterns: []string{"^shared_"}},
	}}

	opts := []agentcmd.OrphanedOptionItem{{Name: "shared_data", SizeBytes: 32}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, nil)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(report.Options) != 1 {
		t.Fatalf("expected 1 option item, got %d", len(report.Options))
	}
	item := report.Options[0]
	if item.DeletableEligible {
		t.Error("expected deletable_eligible=false for multi-candidate item")
	}
	if len(item.KnownPlugins) != 2 {
		t.Errorf("expected 2 known_plugins, got %d", len(item.KnownPlugins))
	}
}

// TestGetOrphansReport_HeuristicNotDeletable verifies that a heuristic-matched
// item is never DeletableEligible.
func TestGetOrphansReport_HeuristicNotDeletable(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		// No exact or prefix patterns; rely on heuristic slug-normalisation.
		{Slug: "contact-form-7", CorpusVersion: 1},
	}}

	opts := []agentcmd.OrphanedOptionItem{{Name: "contactform7_recaptcha_key", SizeBytes: 16}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, nil)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(report.Options) != 1 {
		t.Fatalf("expected 1 option item, got %d", len(report.Options))
	}
	item := report.Options[0]
	if item.Confidence != "heuristic" {
		t.Errorf("expected confidence=heuristic, got %q", item.Confidence)
	}
	if item.DeletableEligible {
		t.Error("expected deletable_eligible=false for heuristic match")
	}
}

// TestGetOrphansReport_UnknownNotDeletable verifies that an unattributed item
// (no corpus match) is never DeletableEligible and has empty OwnerSlug.
func TestGetOrphansReport_UnknownNotDeletable(t *testing.T) {
	corpus := &fakeCorpus{} // empty corpus — no matches possible

	opts := []agentcmd.OrphanedOptionItem{{Name: "totally_unknown_option", SizeBytes: 8}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, nil)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	item := report.Options[0]
	if item.Confidence != "unknown" {
		t.Errorf("expected confidence=unknown, got %q", item.Confidence)
	}
	if item.OwnerSlug != "" {
		t.Errorf("expected empty owner_slug, got %q", item.OwnerSlug)
	}
	if item.DeletableEligible {
		t.Error("expected deletable_eligible=false for unknown confidence")
	}
}

// TestGetOrphansReport_CronAndTables verifies that cron hooks and orphan tables
// are classified separately and the counts are correct.
func TestGetOrphansReport_CronAndTables(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		{Slug: "bookings-plugin", CorpusVersion: 2,
			CronHookPatterns: []string{"^bookings_cleanup_"},
			TablePatterns:    []string{"^wp_bookings"}},
	}}

	cron := []agentcmd.OrphanedCronItem{
		{Hook: "bookings_cleanup_expired", NextRunAt: 1700000000, Recurrence: "hourly"},
		{Hook: "mystery_hook", NextRunAt: 1700000001},
	}
	tables := []agentcmd.DBScanTableInventoryRow{
		// Only owner_type="orphan" rows are picked up.
		{Name: "wp_bookings", OwnerType: "orphan", Rows: 50, SizeBytes: 4096},
		{Name: "wp_posts", OwnerType: "core", Rows: 100, SizeBytes: 8192},
	}

	// Installed snapshot present but does NOT contain "bookings-plugin", so the
	// cross-check is available and the bookings artefacts stay deletable-eligible.
	installed := []agentcmd.InstalledPluginItem{{Slug: "some-other-plugin", Name: "Other", Active: true, Source: "plugin"}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(nil, cron, tables, installed)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// 2 cron items, 1 orphan table (wp_posts is core, ignored).
	if report.Counts.Cron != 2 {
		t.Errorf("expected 2 cron, got %d", report.Counts.Cron)
	}
	if report.Counts.Tables != 1 {
		t.Errorf("expected 1 table, got %d", report.Counts.Tables)
	}

	// First cron item (prefix match).
	c0 := report.Cron[0]
	if c0.Confidence != "prefix" {
		t.Errorf("cron[0] confidence: want prefix, got %q", c0.Confidence)
	}
	if c0.OwnerSlug != "bookings-plugin" {
		t.Errorf("cron[0] owner_slug: want bookings-plugin, got %q", c0.OwnerSlug)
	}
	if !c0.DeletableEligible {
		t.Error("cron[0]: expected deletable_eligible=true for prefix single-candidate uninstalled item")
	}

	// Table classification.
	tab0 := report.Tables[0]
	if tab0.Confidence != "prefix" {
		t.Errorf("table[0] confidence: want prefix, got %q", tab0.Confidence)
	}
	if tab0.DeletableEligible {
		// Table names include the full prefix (e.g. "wp_bookings") — the pattern
		// "^wp_bookings" is a left-anchored prefix match on the full table name.
		// DeletableEligible depends on installed=false + single candidate.
	}
}

// TestGetOrphansReport_CorpusVersion verifies that CorpusVersion is propagated
// from the first corpus signature.
func TestGetOrphansReport_CorpusVersion(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		{Slug: "some-plugin", CorpusVersion: 42},
	}}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(nil, nil, nil, nil)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.CorpusVersion != 42 {
		t.Errorf("expected corpus_version=42, got %d", report.CorpusVersion)
	}
}

// TestGetOrphansReport_InstalledSetNormalisedSlug verifies that the installed-set
// cross-check matches slugs where hyphens and underscores differ between the
// installed snapshot and the corpus slug (normalised comparison), and that the
// matched item is excluded from the report (counted in HiddenInstalled).
func TestGetOrphansReport_InstalledSetNormalisedSlug(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		// Corpus slug uses hyphens.
		{Slug: "my-awesome-plugin", CorpusVersion: 1, OptionPatterns: []string{"my_awesome_plugin_key"}},
	}}
	opts := []agentcmd.OrphanedOptionItem{{Name: "my_awesome_plugin_key", SizeBytes: 16}}
	// Installed snapshot uses underscores instead of hyphens.
	plugins := []agentcmd.InstalledPluginItem{
		{Slug: "my_awesome_plugin", Name: "My Awesome Plugin", Active: true, Source: "plugin"},
	}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, plugins)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	// The item whose normalised slug matches an installed plugin must be excluded.
	if len(report.Options) != 0 {
		t.Fatalf("expected 0 option items (normalised-slug installed match suppressed), got %d", len(report.Options))
	}
	if report.HiddenInstalled != 1 {
		t.Errorf("expected hidden_installed=1, got %d", report.HiddenInstalled)
	}
	if report.Counts.Deletable != 0 {
		t.Errorf("expected 0 deletable, got %d", report.Counts.Deletable)
	}
}

// TestBuildInstalledSet_NullJSON verifies that null/empty JSON input produces an
// empty set without error (backward-compat with agents < 0.16.0).
func TestBuildInstalledSet_NullJSON(t *testing.T) {
	for _, raw := range [][]byte{nil, []byte("null"), []byte("[]")} {
		set, err := buildInstalledSet(raw)
		if err != nil {
			t.Errorf("buildInstalledSet(%q): unexpected error: %v", raw, err)
		}
		if len(set) != 0 {
			t.Errorf("buildInstalledSet(%q): expected empty set, got len=%d", raw, len(set))
		}
	}
}

// TestGetOrphansReport_InstalledItemsExcluded verifies that items whose owner
// plugin is installed are removed from the returned slices and counted in
// HiddenInstalled, while non-installed items survive and counts reflect only
// the remaining genuine-orphan items.
func TestGetOrphansReport_InstalledItemsExcluded(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		// owner-a is installed; its option should be hidden.
		{Slug: "owner-a", CorpusVersion: 1, OptionPatterns: []string{"owner_a_setting"}},
		// owner-b is NOT installed; its option should survive.
		{Slug: "owner-b", CorpusVersion: 1, OptionPatterns: []string{"owner_b_setting"}},
	}}

	opts := []agentcmd.OrphanedOptionItem{
		{Name: "owner_a_setting", Autoload: false, SizeBytes: 32},
		{Name: "owner_b_setting", Autoload: true, SizeBytes: 64},
	}
	// Only owner-a is installed.
	plugins := []agentcmd.InstalledPluginItem{
		{Slug: "owner-a", Name: "Owner A Plugin", Active: true, Source: "plugin"},
	}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, nil, nil, plugins)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// owner_a_setting must be excluded; owner_b_setting must survive.
	if len(report.Options) != 1 {
		t.Fatalf("expected 1 option item after filtering, got %d", len(report.Options))
	}
	if report.Options[0].Name != "owner_b_setting" {
		t.Errorf("expected surviving item to be owner_b_setting, got %q", report.Options[0].Name)
	}

	// HiddenInstalled must account for the excluded item.
	if report.HiddenInstalled != 1 {
		t.Errorf("expected hidden_installed=1, got %d", report.HiddenInstalled)
	}

	// Counts must reflect only the remaining item.
	if report.Counts.Options != 1 {
		t.Errorf("expected counts.options=1, got %d", report.Counts.Options)
	}

	// The surviving item (owner-b not installed, exact match, single candidate)
	// must be deletable eligible.
	if !report.Options[0].DeletableEligible {
		t.Error("expected deletable_eligible=true for the non-installed surviving item")
	}
	if report.Counts.Deletable != 1 {
		t.Errorf("expected counts.deletable=1, got %d", report.Counts.Deletable)
	}
}

// TestGetOrphansReport_InstalledItemsExcluded_AllKinds verifies the
// HiddenInstalled count aggregates suppressions across options, cron, and
// tables, and that no eligible item is ever excluded by the filter.
func TestGetOrphansReport_InstalledItemsExcluded_AllKinds(t *testing.T) {
	corpus := &fakeCorpus{sigs: []dbclean.Signature{
		// Installed plugin — its artefacts across all three kinds must be hidden.
		{Slug: "installed-plugin", CorpusVersion: 1,
			OptionPatterns:   []string{"inst_plugin_option"},
			CronHookPatterns: []string{"^inst_plugin_cron"},
			TablePatterns:    []string{"^wp_inst_plugin"}},
		// Non-installed plugin — its artefacts must survive.
		{Slug: "gone-plugin", CorpusVersion: 1,
			OptionPatterns: []string{"gone_plugin_option"}},
	}}

	opts := []agentcmd.OrphanedOptionItem{
		{Name: "inst_plugin_option", SizeBytes: 10},
		{Name: "gone_plugin_option", SizeBytes: 20},
	}
	cron := []agentcmd.OrphanedCronItem{
		{Hook: "inst_plugin_cron_cleanup", NextRunAt: 1700000000, Recurrence: "daily"},
	}
	tables := []agentcmd.DBScanTableInventoryRow{
		{Name: "wp_inst_plugin_log", OwnerType: "orphan", Rows: 5, SizeBytes: 512},
	}
	plugins := []agentcmd.InstalledPluginItem{
		{Slug: "installed-plugin", Name: "Installed Plugin", Active: true, Source: "plugin"},
	}
	repo := &scanRepoWith{scanFound: true, result: buildScanResult(opts, cron, tables, plugins)}
	svc := NewService(repo, nil, nil, nil)

	report, err := svc.GetOrphansReport(context.Background(), corpus, uuid.New(), uuid.New())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// One installed-owner item hidden per kind (option, cron, table) = 3 total.
	if report.HiddenInstalled != 3 {
		t.Errorf("expected hidden_installed=3 (1 per kind), got %d", report.HiddenInstalled)
	}

	// Only the gone-plugin option survives in Options.
	if len(report.Options) != 1 {
		t.Fatalf("expected 1 surviving option, got %d", len(report.Options))
	}
	if report.Options[0].Name != "gone_plugin_option" {
		t.Errorf("surviving option: want gone_plugin_option, got %q", report.Options[0].Name)
	}

	// No cron or table items survive.
	if len(report.Cron) != 0 {
		t.Errorf("expected 0 cron items, got %d", len(report.Cron))
	}
	if len(report.Tables) != 0 {
		t.Errorf("expected 0 table items, got %d", len(report.Tables))
	}

	// Counts reflect remaining items only.
	if report.Counts.Options != 1 {
		t.Errorf("expected counts.options=1, got %d", report.Counts.Options)
	}
	if report.Counts.Cron != 0 {
		t.Errorf("expected counts.cron=0, got %d", report.Counts.Cron)
	}
	if report.Counts.Tables != 0 {
		t.Errorf("expected counts.tables=0, got %d", report.Counts.Tables)
	}

	// The surviving gone-plugin option must be deletable (exact, single, not installed).
	if report.Counts.Deletable != 1 {
		t.Errorf("expected counts.deletable=1, got %d", report.Counts.Deletable)
	}
}

// TestFilterOutInstalled is a direct unit test of the helper to confirm it
// never touches eligible (non-installed) items and correctly counts suppressed ones.
func TestFilterOutInstalled(t *testing.T) {
	items := []OrphanItem{
		{Name: "keep-1", Installed: false, DeletableEligible: true},
		{Name: "hide-1", Installed: true, DeletableEligible: false},
		{Name: "keep-2", Installed: false, DeletableEligible: false},
		{Name: "hide-2", Installed: true, DeletableEligible: false},
	}

	kept, suppressed := filterOutInstalled(items)

	if suppressed != 2 {
		t.Errorf("expected suppressed=2, got %d", suppressed)
	}
	if len(kept) != 2 {
		t.Fatalf("expected 2 kept items, got %d", len(kept))
	}
	if kept[0].Name != "keep-1" || kept[1].Name != "keep-2" {
		t.Errorf("kept items mismatch: %v", kept)
	}
	// Eligible items must be in the kept slice, never suppressed.
	for _, item := range kept {
		if item.Installed {
			t.Errorf("filterOutInstalled kept an installed item: %q", item.Name)
		}
	}
}

// TestIsDeletableEligible is a table-driven unit test for the gate function.
func TestIsDeletableEligible(t *testing.T) {
	cases := []struct {
		name      string
		conf      dbclean.ConfidenceLevel
		known     []string
		installed bool
		snapshot  bool
		want      bool
	}{
		{"exact single uninstalled", dbclean.ConfidenceExact, []string{"slug-a"}, false, true, true},
		{"prefix single uninstalled", dbclean.ConfidencePrefix, []string{"slug-a"}, false, true, true},
		{"exact single installed", dbclean.ConfidenceExact, []string{"slug-a"}, true, true, false},
		{"exact multi uninstalled", dbclean.ConfidenceExact, []string{"slug-a", "slug-b"}, false, true, false},
		{"heuristic single uninstalled", dbclean.ConfidenceHeuristic, []string{"slug-a"}, false, true, false},
		{"unknown no slugs", dbclean.ConfidenceUnknown, nil, false, true, false},
		{"prefix zero known", dbclean.ConfidencePrefix, []string{}, false, true, false},
		// Fail-safe: no installed snapshot => never eligible, even for an
		// otherwise-perfect exact single-candidate uninstalled match.
		{"exact single uninstalled but NO snapshot", dbclean.ConfidenceExact, []string{"slug-a"}, false, false, false},
		{"prefix single uninstalled but NO snapshot", dbclean.ConfidencePrefix, []string{"slug-a"}, false, false, false},
	}

	for _, tc := range cases {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			cl := dbclean.Classification{
				Confidence:   tc.conf,
				KnownPlugins: tc.known,
			}
			got := isDeletableEligible(cl, tc.installed, tc.snapshot)
			if got != tc.want {
				t.Errorf("isDeletableEligible(%q, known=%v, installed=%v, snapshot=%v) = %v, want %v",
					tc.conf, tc.known, tc.installed, tc.snapshot, got, tc.want)
			}
		})
	}
}
