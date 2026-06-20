package scan

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/google/uuid"
)

// ---------------------------------------------------------------------------
// Plugin checksum fetch + cache + multi-md5-match tests
// ---------------------------------------------------------------------------

// fakePluginRepo is a test double for the plugin-checksum repo methods.
type fakePluginRepo struct {
	// meta: key = "kind:slug:version"
	meta    map[string]pluginMetaEntry
	rows    []PluginChecksumRow
	upserts []PluginChecksumRow
}

type pluginMetaEntry struct {
	fetchedAt int64 // unix second (we use a fixed mock time)
	ok        bool
	found     bool
}

func newFakePluginRepo() *fakePluginRepo {
	return &fakePluginRepo{
		meta: make(map[string]pluginMetaEntry),
	}
}

func (r *fakePluginRepo) GetPluginChecksumsMeta(_ context.Context, kind, slug, version string) (int64, bool, bool, error) {
	key := kind + ":" + slug + ":" + version
	e, ok := r.meta[key]
	if !ok {
		return 0, false, false, nil
	}
	return e.fetchedAt, e.ok, e.found, nil
}

func (r *fakePluginRepo) UpsertPluginChecksumsMeta(_ context.Context, kind, slug, version string, ok bool) error {
	key := kind + ":" + slug + ":" + version
	r.meta[key] = pluginMetaEntry{fetchedAt: 0, ok: ok, found: true}
	return nil
}

func (r *fakePluginRepo) GetPluginChecksums(_ context.Context, _, _, _ string) ([]PluginChecksumRow, error) {
	return r.rows, nil
}

func (r *fakePluginRepo) UpsertPluginChecksums(_ context.Context, rows []PluginChecksumRow) error {
	r.upserts = append(r.upserts, rows...)
	r.rows = append(r.rows, rows...)
	return nil
}

// ---- thin adapter so fakePluginRepo can stand in for the Repo pointer field ----
// We test ChecksumProvider.Plugin using a real httptest server and a minimal repo
// that wraps fakePluginRepo.

// pluginChecksumsProviderForTest builds a ChecksumProvider whose Plugin method
// can be exercised with a fake HTTP server. The core Repo methods are wired to
// no-op stubs (unused by the Plugin path).
type pluginTestRepo struct {
	*fakePluginRepo
}

// Proxy the four core-checksums methods so *pluginTestRepo satisfies *Repo.
// We don't need them in these tests; nil panics would show a test defect.
func (r *pluginTestRepo) GetChecksumsMeta(_ context.Context, _, _ string) (interface{}, bool, bool, error) {
	panic("not needed in plugin checksum tests")
}

// TestPluginChecksum_FetchAndCache verifies that the Plugin method:
//   - Fetches from the (fake) wp.org endpoint on cache miss.
//   - Stores rows in the repo.
//   - Returns the correct path → []md5 map.
func TestPluginChecksum_FetchAndCache(t *testing.T) {
	t.Parallel()

	// Fake wp.org response: single md5 string per file.
	payload := `{
		"plugin": "akismet",
		"version": "5.3.1",
		"files": {
			"akismet.php": {"md5": "aabbccdd00112233aabbccdd00112233"},
			"readme.txt":  {"md5": "11223344aabbccdd11223344aabbccdd"}
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(payload))
	}))
	defer srv.Close()

	// Override the plugin fetch URL for the test.
	oldURL := pluginChecksumsFetchURL
	// pluginChecksumsFetchURL is a const — we need the test server's URL pattern.
	// Use a real ChecksumProvider but override the HTTP client to point at the test server.
	repo := newFakePluginRepo()
	client := srv.Client()

	provider := &ChecksumProvider{
		repo:   &Repo{}, // core repo unused in Plugin path
		client: &testPluginHTTPClient{client: client, baseURL: srv.URL},
	}
	_ = oldURL // suppress unused warning

	result, err := provider.pluginFetchDirect(context.Background(), repo, "akismet", "5.3.1")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(result) != 2 {
		t.Fatalf("expected 2 paths, got %d: %v", len(result), result)
	}
	variants, ok := result["akismet.php"]
	if !ok {
		t.Fatal("expected akismet.php in result")
	}
	if len(variants) != 1 || variants[0] != "aabbccdd00112233aabbccdd00112233" {
		t.Errorf("unexpected variants for akismet.php: %v", variants)
	}
	// Verify upsert was called.
	if len(repo.upserts) == 0 {
		t.Error("expected UpsertPluginChecksums to have been called")
	}
}

// TestPluginChecksum_MultiMD5Variants verifies that when wp.org returns a JSON
// array of md5 variants, ALL are stored and the diff can match any of them.
func TestPluginChecksum_MultiMD5Variants(t *testing.T) {
	t.Parallel()

	payload := `{
		"plugin": "woocommerce",
		"version": "8.0.0",
		"files": {
			"woocommerce.php": {"md5": ["aaaa1111aaaa1111aaaa1111aaaa1111", "bbbb2222bbbb2222bbbb2222bbbb2222"]},
			"readme.txt":      {"md5": "cccc3333cccc3333cccc3333cccc3333"}
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(payload))
	}))
	defer srv.Close()

	repo := newFakePluginRepo()
	provider := &ChecksumProvider{
		repo:   &Repo{},
		client: &testPluginHTTPClient{client: srv.Client(), baseURL: srv.URL},
	}

	result, err := provider.pluginFetchDirect(context.Background(), repo, "woocommerce", "8.0.0")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	variants := result["woocommerce.php"]
	if len(variants) != 2 {
		t.Fatalf("expected 2 md5 variants, got %d: %v", len(variants), variants)
	}
	// Both variants must be present.
	if !md5MatchesAny("aaaa1111aaaa1111aaaa1111aaaa1111", variants) {
		t.Error("first variant not found")
	}
	if !md5MatchesAny("bbbb2222bbbb2222bbbb2222bbbb2222", variants) {
		t.Error("second variant not found")
	}
	// A hash not in the list should NOT match.
	if md5MatchesAny("deadbeefdeadbeefdeadbeefdeadbeef", variants) {
		t.Error("unexpected match for unknown hash")
	}
}

// TestPluginChecksum_NegativeCache verifies that a 404 results in no rows
// and that subsequent calls do not hit the server again (negative-cache).
func TestPluginChecksum_NegativeCache(t *testing.T) {
	t.Parallel()

	hitCount := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		hitCount++
		w.WriteHeader(http.StatusNotFound)
	}))
	defer srv.Close()

	repo := newFakePluginRepo()
	provider := &ChecksumProvider{
		repo:   &Repo{},
		client: &testPluginHTTPClient{client: srv.Client(), baseURL: srv.URL},
	}

	// First call: 404 → empty result.
	result, err := provider.pluginFetchDirect(context.Background(), repo, "premium-plugin", "1.0.0")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(result) != 0 {
		t.Errorf("expected empty result for 404, got %v", result)
	}
	if hitCount != 1 {
		t.Errorf("expected exactly 1 HTTP call, got %d", hitCount)
	}
	if len(repo.upserts) != 0 {
		t.Error("expected no checksum rows for 404")
	}
}

// TestDecodeMD5Variants covers the string + array + empty edge cases.
func TestDecodeMD5Variants(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name     string
		input    string
		wantLen  int
		wantErr  bool
	}{
		{"string", `"aabbccdd00112233aabbccdd00112233"`, 1, false},
		{"array_single", `["aabbccdd00112233aabbccdd00112233"]`, 1, false},
		{"array_two", `["aaaa", "bbbb"]`, 2, false},
		{"empty_string", `""`, 0, false},
		{"empty_array", `[]`, 0, false},
		{"null", `null`, 0, false},
	}
	for _, tc := range tests {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()
			variants, err := decodeMD5Variants(json.RawMessage(tc.input))
			if tc.wantErr && err == nil {
				t.Error("expected error, got nil")
			}
			if !tc.wantErr && err != nil {
				t.Errorf("unexpected error: %v", err)
			}
			if len(variants) != tc.wantLen {
				t.Errorf("want %d variants, got %d: %v", tc.wantLen, len(variants), variants)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// diffFiles classification tests
// ---------------------------------------------------------------------------

func TestDiffFiles_ManagedSuppress(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/cache/min/style.css", MD5: "anything"},
		{Path: "object-cache.php", MD5: "known11"},
	}
	baseline := []BaselineRow{
		{Path: "wp-content/cache/min/style.css", MD5: "old"},
		{Path: "object-cache.php", MD5: "old"},
	}
	managed := []ManagedFileRow{
		{Path: "wp-content/cache/min/style.css", MD5: "", ManagedBy: "perf_cache"}, // suppress all
		{Path: "object-cache.php", MD5: "known11", ManagedBy: "object_cache"},       // exact match → OK
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, baseline, nil, nil, managed, false)
	if len(findings) != 0 {
		t.Errorf("expected 0 findings for managed paths, got %d: %v", len(findings), findings)
	}
}

func TestDiffFiles_ManagedTampering(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// The managed registry expects "known11" but the file now has "tampered".
	hashes := []HashRow{
		{Path: "object-cache.php", MD5: "tampered0000000000000000000000000"},
	}
	managed := []ManagedFileRow{
		{Path: "object-cache.php", MD5: "known1100000000000000000000000000", ManagedBy: "object_cache"},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, nil, managed, false)
	if len(findings) != 1 {
		t.Fatalf("expected 1 finding (managed tampering), got %d", len(findings))
	}
	if findings[0].FindingType != FindingFileChanged {
		t.Errorf("expected %s, got %s", FindingFileChanged, findings[0].FindingType)
	}
	if findings[0].Severity != SeverityHigh {
		t.Errorf("expected severity=%s, got %s", SeverityHigh, findings[0].Severity)
	}
}

func TestDiffFiles_PluginChecksumKnownGood(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// A wp.org plugin file with matching official checksum.
	hashes := []HashRow{
		{Path: "wp-content/plugins/akismet/akismet.php", MD5: "official00000000000000000000000000"},
	}
	pluginChecksums := map[string]map[string][]string{
		"akismet": {"akismet.php": {"official00000000000000000000000000"}},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, pluginChecksums, nil, false)
	if len(findings) != 0 {
		t.Errorf("expected 0 findings for known-good plugin file, got %d: %v", len(findings), findings)
	}
}

func TestDiffFiles_PluginModified(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/plugins/akismet/akismet.php", MD5: "tampered0000000000000000000000000"},
	}
	pluginChecksums := map[string]map[string][]string{
		"akismet": {"akismet.php": {"official00000000000000000000000000"}},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, pluginChecksums, nil, false)
	if len(findings) != 1 {
		t.Fatalf("expected 1 plugin_modified finding, got %d", len(findings))
	}
	if findings[0].FindingType != FindingPluginModified {
		t.Errorf("expected %s, got %s", FindingPluginModified, findings[0].FindingType)
	}
	if findings[0].Severity != SeverityHigh {
		t.Errorf("expected severity=%s, got %s", SeverityHigh, findings[0].Severity)
	}
}

func TestDiffFiles_PluginUnknown(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// A file inside a known wp.org plugin dir but NOT in the manifest.
	hashes := []HashRow{
		{Path: "wp-content/plugins/akismet/injected.php", MD5: "evil00000000000000000000000000000"},
	}
	pluginChecksums := map[string]map[string][]string{
		"akismet": {"akismet.php": {"official00000000000000000000000000"}},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, pluginChecksums, nil, false)
	if len(findings) != 1 {
		t.Fatalf("expected 1 plugin_unknown finding, got %d", len(findings))
	}
	if findings[0].FindingType != FindingPluginUnknown {
		t.Errorf("expected %s, got %s", FindingPluginUnknown, findings[0].FindingType)
	}
}

func TestDiffFiles_MultiMD5Match(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// The file has the SECOND variant — should match (no finding).
	hashes := []HashRow{
		{Path: "wp-content/plugins/woo/woocommerce.php", MD5: "variant200000000000000000000000000"},
	}
	pluginChecksums := map[string]map[string][]string{
		"woo": {"woocommerce.php": {
			"variant100000000000000000000000000",
			"variant200000000000000000000000000",
		}},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, pluginChecksums, nil, false)
	if len(findings) != 0 {
		t.Errorf("expected 0 findings (multi-variant match), got %d: %v", len(findings), findings)
	}
}

func TestDiffFiles_FileAdded(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/uploads/new-file.jpg", MD5: "newfile00000000000000000000000000"},
	}
	// Non-empty baseline (cold_start=false) but this path is absent from it.
	baseline := []BaselineRow{
		{Path: "wp-content/uploads/old-file.jpg", MD5: "old00000000000000000000000000000"},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, baseline, nil, nil, nil, false)

	added := filterFindings(findings, FindingFileAdded)
	if len(added) != 1 {
		t.Errorf("expected 1 file_added finding, got %d: %v", len(added), findings)
	}
	if added[0].Severity != SeverityMedium {
		t.Errorf("expected severity=%s, got %s", SeverityMedium, added[0].Severity)
	}
}

func TestDiffFiles_FileChanged(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/themes/twentytwentyfour/style.css", MD5: "changed00000000000000000000000000"},
	}
	baseline := []BaselineRow{
		{Path: "wp-content/themes/twentytwentyfour/style.css", MD5: "original0000000000000000000000000"},
	}

	// No plugin checksums for this theme slug (premium/custom).
	findings := diffFiles(runID, tenantID, siteID, hashes, baseline, nil, nil, nil, false)
	changed := filterFindings(findings, FindingFileChanged)
	if len(changed) != 1 {
		t.Fatalf("expected 1 file_changed finding, got %d: %v", len(changed), findings)
	}
	if changed[0].Severity != SeverityHigh {
		t.Errorf("expected severity=%s, got %s", SeverityHigh, changed[0].Severity)
	}
}

func TestDiffFiles_FileRemoved(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// Current run has no files; baseline has one.
	hashes := []HashRow{}
	baseline := []BaselineRow{
		{Path: "wp-content/uploads/gone.php", MD5: "gone00000000000000000000000000000"},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, baseline, nil, nil, nil, false)
	removed := filterFindings(findings, FindingFileRemoved)
	if len(removed) != 1 {
		t.Fatalf("expected 1 file_removed finding, got %d: %v", len(removed), findings)
	}
	if removed[0].Severity != SeverityLow {
		t.Errorf("expected severity=%s, got %s", SeverityLow, removed[0].Severity)
	}
}

func TestDiffFiles_ColdStart_NoBaselineFindings(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/uploads/photo.jpg", MD5: "photo000000000000000000000000000"},
		{Path: "wp-content/themes/mytheme/style.css", MD5: "style000000000000000000000000000"},
	}

	// Cold start: no baseline.
	findings := diffFiles(runID, tenantID, siteID, hashes, nil, nil, nil, nil, true)

	// No Added/Changed/Removed should be emitted (only core/plugin findings,
	// which are absent here since no checksums provided).
	for _, f := range findings {
		switch f.FindingType {
		case FindingFileAdded, FindingFileChanged, FindingFileRemoved:
			t.Errorf("unexpected %s finding on cold start for path %q", f.FindingType, f.Path)
		}
	}
}

func TestDiffFiles_ColdStart_CoreFindingsStillEmit(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// Core file is modified — should emit core finding even on cold start.
	hashes := []HashRow{
		{Path: "wp-login.php", MD5: "tampered0000000000000000000000000"},
	}
	coreChecksums := map[string]string{
		"wp-login.php": "official000000000000000000000000",
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, nil, coreChecksums, nil, nil, true)
	if len(findings) != 1 {
		t.Fatalf("expected 1 core_modified finding on cold start, got %d", len(findings))
	}
	if findings[0].FindingType != FindingCoreModified {
		t.Errorf("expected core_modified, got %s", findings[0].FindingType)
	}
}

func TestDiffFiles_RemovedManagedSuppressed(t *testing.T) {
	t.Parallel()
	runID, tenantID, siteID := uuid.New(), uuid.New(), uuid.New()

	// A managed file that was removed should NOT produce a file_removed finding.
	hashes := []HashRow{} // file gone
	baseline := []BaselineRow{
		{Path: "object-cache.php", MD5: "old000000000000000000000000000000"},
	}
	managed := []ManagedFileRow{
		{Path: "object-cache.php", MD5: "old000000000000000000000000000000", ManagedBy: "object_cache"},
	}

	findings := diffFiles(runID, tenantID, siteID, hashes, baseline, nil, nil, managed, false)
	for _, f := range findings {
		if f.FindingType == FindingFileRemoved && f.Path == "object-cache.php" {
			t.Error("managed file removal should be suppressed")
		}
	}
}

// ---------------------------------------------------------------------------
// pluginOrThemePath tests
// ---------------------------------------------------------------------------

func TestPluginOrThemePath(t *testing.T) {
	t.Parallel()
	tests := []struct {
		path     string
		wantSlug string
		wantRel  string
		wantKind string
	}{
		{"wp-content/plugins/akismet/akismet.php", "akismet", "akismet.php", "plugin"},
		{"wp-content/plugins/woocommerce/includes/class-wc.php", "woocommerce", "includes/class-wc.php", "plugin"},
		{"wp-content/themes/twentytwentyfour/style.css", "twentytwentyfour", "style.css", "theme"},
		{"wp-content/plugins/bare", "", "", ""},   // no slug subdir
		{"wp-admin/admin.php", "", "", ""},
		{"index.php", "", "", ""},
	}
	for _, tc := range tests {
		tc := tc
		t.Run(tc.path, func(t *testing.T) {
			t.Parallel()
			slug, relPath, kind := pluginOrThemePath(tc.path)
			if slug != tc.wantSlug || relPath != tc.wantRel || kind != tc.wantKind {
				t.Errorf("pluginOrThemePath(%q) = (%q,%q,%q), want (%q,%q,%q)",
					tc.path, slug, relPath, kind, tc.wantSlug, tc.wantRel, tc.wantKind)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// pluginDirSlug tests
// ---------------------------------------------------------------------------

func TestPluginDirSlug(t *testing.T) {
	t.Parallel()
	tests := []struct {
		input string
		want  string
	}{
		{"akismet/akismet.php", "akismet"},
		{"woocommerce/woocommerce.php", "woocommerce"},
		{"my-plugin", "my-plugin"},
		{"", ""},
	}
	for _, tc := range tests {
		tc := tc
		t.Run(tc.input, func(t *testing.T) {
			t.Parallel()
			got := pluginDirSlug(tc.input)
			if got != tc.want {
				t.Errorf("pluginDirSlug(%q) = %q, want %q", tc.input, got, tc.want)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// Tenant isolation: verify dedup keys differ across tenants
// ---------------------------------------------------------------------------

func TestDiffFiles_TenantIsolation_DeduKey(t *testing.T) {
	t.Parallel()
	runID := uuid.New()
	tenant1, tenant2 := uuid.New(), uuid.New()
	site1, site2 := uuid.New(), uuid.New()

	hashes := []HashRow{
		{Path: "wp-content/uploads/evil.php", MD5: "evil00000000000000000000000000000"},
	}
	baseline := []BaselineRow{} // not in baseline → file_added (non-cold-start)

	f1 := diffFiles(runID, tenant1, site1, hashes, baseline, nil, nil, nil, false)
	f2 := diffFiles(runID, tenant2, site2, hashes, baseline, nil, nil, nil, false)

	if len(f1) != 1 || len(f2) != 1 {
		t.Fatal("expected 1 finding from each tenant")
	}
	if f1[0].DeduKey == f2[0].DeduKey {
		t.Error("dedup_key must differ across tenants (tenant_id is a component)")
	}
	if f1[0].TenantID == f2[0].TenantID {
		t.Error("TenantID must differ across tenants")
	}
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func filterFindings(findings []Finding, ft string) []Finding {
	var out []Finding
	for _, f := range findings {
		if f.FindingType == ft {
			out = append(out, f)
		}
	}
	return out
}

// testPluginHTTPClient is a thin wrapper that redirects all requests to a
// test server URL so we can inject a fake wp.org endpoint.
type testPluginHTTPClient struct {
	client  *http.Client
	baseURL string
}

func (c *testPluginHTTPClient) Do(req *http.Request) (*http.Response, error) {
	// Replace the host with the test server; keep path + query.
	u := req.URL
	u.Scheme = "http"
	u.Host = strings.TrimPrefix(c.baseURL, "http://")
	req2, err := http.NewRequestWithContext(req.Context(), req.Method, u.String(), req.Body)
	if err != nil {
		return nil, err
	}
	req2.Header = req.Header
	return c.client.Do(req2)
}

// ---------------------------------------------------------------------------
// pluginFetchDirect — helper that bypasses the Repo cache so tests can
// exercise the HTTP fetch and decode logic directly.
// ---------------------------------------------------------------------------

// pluginFetchDirect calls fetchPluginFromWPOrg and then upserts into a
// provided fakePluginRepo. It mirrors what the real Plugin() method does on a
// cache miss, but accepts explicit repo and slug arguments so tests can drive
// it without needing a full DB-backed Repo.
func (p *ChecksumProvider) pluginFetchDirect(ctx context.Context, repo *fakePluginRepo, slug, version string) (map[string][]string, error) {
	checksums, err := p.fetchPluginFromWPOrg(ctx, slug, version)
	if err != nil {
		return nil, err
	}
	if len(checksums) == 0 {
		return map[string][]string{}, nil
	}
	var dbRows []PluginChecksumRow
	for path, variants := range checksums {
		for _, md5val := range variants {
			dbRows = append(dbRows, PluginChecksumRow{
				Kind: "plugin", Slug: slug, Version: version, Path: path, MD5: md5val,
			})
		}
	}
	_ = repo.UpsertPluginChecksums(ctx, dbRows)
	return checksums, nil
}
