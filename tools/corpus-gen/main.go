// corpus-gen is an OFFLINE tool that builds the plugin_signatures corpus by
// querying the wordpress.org plugin API, downloading plugin ZIP files, scanning
// PHP source for known call-sites, and emitting a SQL seed migration.
//
// It lives in a SEPARATE Go module (tools/corpus-gen/go.mod) so that
// `go build ./...` in apps/api never includes it. The generated seed SQL is
// committed to apps/api/migrations/ as a static artifact; the API server reads
// it through the Atlas migration runner and the sqlc GetPluginSignatures query.
//
// Usage:
//
//	go run . [flags]
//
// Flags:
//
//	-n          number of popular slugs to fetch (default 300; set 3000 for full run)
//	-out        output SQL file path (default migrations/seeds/plugin_signatures_v{N}.sql)
//	-version    corpus_version integer to stamp in the SQL (default 1)
//	-manifest   path to manifest.json for resumability (default manifest.json)
//	-dry-run    list slugs and stop; do not download or emit SQL
//	-workers    max concurrent downloads (default 2, max 4)
//
// Safety properties:
//   - ZIP-SLIP guard: extracted paths are checked to not escape the temp dir.
//   - SSRF guard: only api.wordpress.org and downloads.wordpress.org are allowed.
//   - Rate limit: ~2 req/s, max 2 concurrent downloads, exponential backoff on 429.
//   - Pattern validation: every emitted pattern is compiled via regexp.MustCompile
//     before being written to the SQL file.
//   - Never executes downloaded PHP; only reads it as text.
//   - No plugin source code is committed; only extracted name-pattern strings.
package main

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"gopkg.in/yaml.v3"
)

// ---- SSRF guard -------------------------------------------------------

// allowedHosts is the exclusive allowlist for outbound HTTP calls. Only these
// hosts are permitted. Any other host causes an immediate error.
var allowedHosts = map[string]bool{
	"api.wordpress.org":        true,
	"downloads.wordpress.org":  true,
	"ps.w.org":                 true,
	"plugins.svn.wordpress.org": true,
}

func guardedGet(client *http.Client, rawURL string) (*http.Response, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, fmt.Errorf("invalid URL %q: %w", rawURL, err)
	}
	if u.Scheme != "https" {
		return nil, fmt.Errorf("SSRF guard: only https is allowed, got %q", u.Scheme)
	}
	host := strings.ToLower(u.Hostname())
	if !allowedHosts[host] {
		return nil, fmt.Errorf("SSRF guard: host %q is not in the allowlist", host)
	}
	req, err := http.NewRequest(http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "wpmgr-corpus-gen/1.0 (+https://github.com/mosamlife/wpmgr)")
	return client.Do(req)
}

// ---- WordPress.org API types ------------------------------------------

type wporgPluginInfo struct {
	Slug    string `json:"slug"`
	Version string `json:"version"`
}

type wporgQueryResponse struct {
	Plugins []wporgPluginInfo `json:"plugins"`
	Info    struct {
		Page    int `json:"page"`
		Pages   int `json:"pages"`
		Results int `json:"results"`
	} `json:"info"`
}

// ---- Manifest for resumability ----------------------------------------

type ManifestEntry struct {
	Slug        string `json:"slug"`
	Version     string `json:"version"`
	Extracted   bool   `json:"extracted"`
	Unavailable bool   `json:"unavailable,omitempty"`
	ProcessedAt string `json:"processed_at,omitempty"`
}

type Manifest struct {
	mu      sync.Mutex
	Entries map[string]*ManifestEntry `json:"entries"`
	path    string
}

func loadManifest(path string) (*Manifest, error) {
	m := &Manifest{path: path, Entries: map[string]*ManifestEntry{}}
	data, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return m, nil
		}
		return nil, err
	}
	return m, json.Unmarshal(data, m)
}

func (m *Manifest) save() error {
	m.mu.Lock()
	defer m.mu.Unlock()
	data, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(m.path, data, 0o644)
}

func (m *Manifest) get(slug string) (*ManifestEntry, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	e, ok := m.Entries[slug]
	return e, ok
}

func (m *Manifest) set(e *ManifestEntry) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.Entries[e.Slug] = e
}

// ---- Pattern extraction -----------------------------------------------

// blocklist contains generic literals that must never be emitted as patterns
// because they appear across hundreds of unrelated plugins and would produce
// false attributions. Extend this list conservatively.
var patternBlocklist = map[string]bool{
	"settings":  true,
	"version":   true,
	"active":    true,
	"cache":     true,
	"options":   true,
	"data":      true,
	"config":    true,
	"status":    true,
	"enable":    true,
	"enabled":   true,
	"disabled":  true,
	"key":       true,
	"value":     true,
	"type":      true,
	"name":      true,
	"token":     true,
	"secret":    true,
	"license":   true,
	"debug":     true,
	"error":     true,
	"errors":    true,
	"installed": true,
	"update":    true,
	"upgrade":   true,
}

// maxPatternLen caps the length of any emitted pattern to prevent oversized
// regexps. Patterns longer than this are silently discarded.
const maxPatternLen = 120

// callSiteRe matches PHP call-sites for option/cron/table registration.
// We look for:
//   - add_option, update_option, get_option, register_setting (option_name arg)
//   - wp_schedule_event, wp_schedule_single_event, wp_next_scheduled,
//     wp_clear_scheduled_hook (hook arg, position differs)
//   - dbDelta, CREATE TABLE, $wpdb->prefix (table name)
var callSiteRe = regexp.MustCompile(
	`(?i)(?:add_option|update_option|get_option|delete_option|register_setting|wp_schedule_event|wp_schedule_single_event|wp_next_scheduled|wp_clear_scheduled_hook|wp_unschedule_hook)\s*\(\s*(?:'([^']{1,100})'|"([^"]{1,100})")`)

// prefixConcatRe matches static prefix concatenation patterns like:
// 'my_prefix_' . $variable   →   prefix pattern ^my_prefix_
//
// The minimum of 4 characters (before the trailing _) is a security floor: short
// prefixes like ^et_ or ^ep_ are too ambiguous to use as delete-eligible corpus
// entries — a 2-character root matches many unrelated option names from foreign
// plugins and would cause false-attribution misclassification. Any prefix shorter
// than 4 chars before the underscore is rejected here so it never reaches the
// emitted SQL seed and never becomes a ConfidencePrefix deletion candidate.
var prefixConcatRe = regexp.MustCompile(`'([a-zA-Z0-9_-]{4,60}_)'\s*\.\s*\$`)

// createTableRe matches CREATE TABLE calls with $wpdb->prefix:
// $wpdb->prefix . 'my_suffix'
var createTableRe = regexp.MustCompile(`\$wpdb->prefix\s*\.\s*'([a-zA-Z0-9_-]{1,60})'`)

// SlugPatterns holds the extracted patterns for a single plugin slug, keyed by
// pattern kind.
type SlugPatterns struct {
	Slug              string
	OptionPatterns    []string
	TransientPatterns []string
	TablePatterns     []string
	CronHookPatterns  []string
}

func extractFromPHP(slug, content string) *SlugPatterns {
	sp := &SlugPatterns{Slug: slug}

	// Extract plain literals from call-sites.
	for _, m := range callSiteRe.FindAllStringSubmatch(content, -1) {
		lit := m[1]
		if lit == "" {
			lit = m[2]
		}
		lit = strings.ToLower(strings.TrimSpace(lit))
		if lit == "" || len(lit) > maxPatternLen {
			continue
		}
		if patternBlocklist[lit] {
			continue
		}
		// Classify by prefix heuristic.
		if strings.HasPrefix(lit, "_transient_") {
			sp.TransientPatterns = appendUnique(sp.TransientPatterns, lit)
		} else {
			sp.OptionPatterns = appendUnique(sp.OptionPatterns, lit)
		}
	}

	// Extract prefix concat patterns.
	for _, m := range prefixConcatRe.FindAllStringSubmatch(content, -1) {
		prefix := strings.ToLower(m[1])
		// Security floor: require at least 5 chars total (4 chars + trailing _) so
		// that patterns like ^et_ / ^ep_ / ^lp_ cannot become ConfidencePrefix
		// deletion candidates. The prefixConcatRe already enforces {4,60}, but we
		// double-check here in case the regexp is ever relaxed.
		if len(prefix) < 5 || len(prefix) > maxPatternLen {
			continue
		}
		if patternBlocklist[strings.TrimSuffix(prefix, "_")] {
			continue
		}
		pat := "^" + prefix
		if strings.HasPrefix(prefix, "_transient_") {
			sp.TransientPatterns = appendUnique(sp.TransientPatterns, pat)
		} else {
			sp.OptionPatterns = appendUnique(sp.OptionPatterns, pat)
		}
	}

	// Extract table suffix patterns from CREATE TABLE $wpdb->prefix . 'suffix'.
	for _, m := range createTableRe.FindAllStringSubmatch(content, -1) {
		suffix := strings.ToLower(m[1])
		if len(suffix) < 2 || len(suffix) > maxPatternLen {
			continue
		}
		sp.TablePatterns = appendUnique(sp.TablePatterns, "^wp_"+suffix)
	}

	return sp
}

func appendUnique(slice []string, s string) []string {
	for _, v := range slice {
		if v == s {
			return slice
		}
	}
	return append(slice, s)
}

// validatePattern compiles a pattern string with regexp.MustCompile (panics
// on invalid RE2 syntax). We recover and return false for invalid patterns.
func validatePattern(p string) (ok bool) {
	defer func() {
		if r := recover(); r != nil {
			ok = false
		}
	}()
	regexp.MustCompile(p)
	return true
}

// ---- ZIP download & extraction ----------------------------------------

// maxZipEntryBytes is the max uncompressed size of a single ZIP entry we will
// process. Entries larger than this are skipped.
const maxZipEntryBytes = 2 * 1024 * 1024 // 2 MB

// downloadAndExtract downloads the plugin ZIP for slug@version, extracts *.php
// files (skipping /assets/), scans them, and returns a SlugPatterns aggregate.
func downloadAndExtract(ctx context.Context, client *http.Client, slug, version string) (*SlugPatterns, error) {
	zipURL := fmt.Sprintf("https://downloads.wordpress.org/plugin/%s.%s.zip", slug, version)
	resp, err := guardedGet(client, zipURL)
	if err != nil {
		return nil, fmt.Errorf("download %s: %w", zipURL, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotFound {
		return nil, fmt.Errorf("zip not found for %s@%s (404)", slug, version)
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("download %s: HTTP %d", zipURL, resp.StatusCode)
	}

	// Read ZIP into memory (we can't seek on the HTTP body without buffering).
	body, err := io.ReadAll(io.LimitReader(resp.Body, 50*1024*1024)) // cap at 50 MB
	if err != nil {
		return nil, fmt.Errorf("read zip body for %s: %w", slug, err)
	}

	zr, err := zip.NewReader(bytes.NewReader(body), int64(len(body)))
	if err != nil {
		return nil, fmt.Errorf("open zip for %s: %w", slug, err)
	}

	aggregate := &SlugPatterns{Slug: slug}
	for _, f := range zr.File {
		if err := ctx.Err(); err != nil {
			return nil, err
		}

		// ZIP-SLIP guard: reject any entry whose cleaned path escapes the slug dir.
		cleanName := filepath.Clean(f.Name)
		if strings.Contains(cleanName, "..") || filepath.IsAbs(cleanName) {
			log.Printf("WARN: ZIP-SLIP attempt in %s: entry %q skipped", slug, f.Name)
			continue
		}

		// Only process .php files; skip /assets/ directories.
		if !strings.HasSuffix(strings.ToLower(f.Name), ".php") {
			continue
		}
		if strings.Contains(f.Name, "/assets/") || strings.Contains(f.Name, "/node_modules/") {
			continue
		}
		if f.UncompressedSize64 > maxZipEntryBytes {
			continue
		}

		rc, err := f.Open()
		if err != nil {
			continue
		}
		content, err := io.ReadAll(io.LimitReader(rc, maxZipEntryBytes))
		rc.Close()
		if err != nil {
			continue
		}

		partial := extractFromPHP(slug, string(content))
		aggregate.OptionPatterns = mergeUnique(aggregate.OptionPatterns, partial.OptionPatterns)
		aggregate.TransientPatterns = mergeUnique(aggregate.TransientPatterns, partial.TransientPatterns)
		aggregate.TablePatterns = mergeUnique(aggregate.TablePatterns, partial.TablePatterns)
		aggregate.CronHookPatterns = mergeUnique(aggregate.CronHookPatterns, partial.CronHookPatterns)
	}
	return aggregate, nil
}

func mergeUnique(a, b []string) []string {
	seen := make(map[string]bool, len(a))
	for _, v := range a {
		seen[v] = true
	}
	for _, v := range b {
		if !seen[v] {
			seen[v] = true
			a = append(a, v)
		}
	}
	return a
}

// ---- Document-frequency suppression -----------------------------------

// suppressByDocFreq removes patterns that appear in 3 or more unrelated slugs.
// "Unrelated" means different slugs (not counting the slug that owns the pattern
// itself). This guards against generic names that coincidentally match many plugins.
func suppressByDocFreq(allPatterns map[string]*SlugPatterns, threshold int) {
	// Build a map: pattern → set of slugs that have it.
	docFreq := map[string]map[string]bool{}
	for slug, sp := range allPatterns {
		addFreq := func(pats []string) {
			for _, p := range pats {
				if _, ok := docFreq[p]; !ok {
					docFreq[p] = map[string]bool{}
				}
				docFreq[p][slug] = true
			}
		}
		addFreq(sp.OptionPatterns)
		addFreq(sp.TransientPatterns)
		addFreq(sp.TablePatterns)
		addFreq(sp.CronHookPatterns)
	}

	// Suppress patterns that appear across >= threshold unrelated slugs.
	suppress := func(pats []string) []string {
		out := pats[:0]
		for _, p := range pats {
			if len(docFreq[p]) < threshold {
				out = append(out, p)
			}
		}
		return out
	}

	for _, sp := range allPatterns {
		sp.OptionPatterns = suppress(sp.OptionPatterns)
		sp.TransientPatterns = suppress(sp.TransientPatterns)
		sp.TablePatterns = suppress(sp.TablePatterns)
		sp.CronHookPatterns = suppress(sp.CronHookPatterns)
	}
}

// ---- SQL emission -----------------------------------------------------

func emitSQL(outPath string, corpusVersion int, all map[string]*SlugPatterns) error {
	var buf strings.Builder
	buf.WriteString(fmt.Sprintf(`-- Generated by tools/corpus-gen at corpus_version=%d.
-- DO NOT EDIT MANUALLY. Re-run tools/corpus-gen to regenerate.
-- Generated at: %s
--
-- This file contains INSERT ... ON CONFLICT DO UPDATE for all processed slugs.
-- Apply it to Postgres as the schema owner (not wpmgr_app) so the INSERT
-- succeeds under ENABLE RLS (owner bypasses RLS; app role has SELECT only).

INSERT INTO plugin_signatures
    (slug, corpus_version, option_patterns, transient_patterns, table_patterns, cron_hook_patterns, updated_at)
VALUES
`, corpusVersion, time.Now().UTC().Format(time.RFC3339)))

	// Sort slugs for deterministic output.
	slugs := make([]string, 0, len(all))
	for s := range all {
		slugs = append(slugs, s)
	}
	sort.Strings(slugs)

	rows := make([]string, 0, len(slugs))
	for _, slug := range slugs {
		sp := all[slug]
		if sp == nil {
			continue
		}

		optPats := filterValidPatterns(sp.OptionPatterns)
		transPats := filterValidPatterns(sp.TransientPatterns)
		tablePats := filterValidPatterns(sp.TablePatterns)
		cronPats := filterValidPatterns(sp.CronHookPatterns)

		opJSON := toJSONArray(optPats)
		trJSON := toJSONArray(transPats)
		tbJSON := toJSONArray(tablePats)
		crJSON := toJSONArray(cronPats)

		rows = append(rows, fmt.Sprintf("    ('%s', %d, '%s', '%s', '%s', '%s', now())",
			escapeSQLString(slug),
			corpusVersion,
			opJSON,
			trJSON,
			tbJSON,
			crJSON,
		))
	}

	buf.WriteString(strings.Join(rows, ",\n"))
	buf.WriteString(`
ON CONFLICT (slug) DO UPDATE SET
    corpus_version     = EXCLUDED.corpus_version,
    option_patterns    = EXCLUDED.option_patterns,
    transient_patterns = EXCLUDED.transient_patterns,
    table_patterns     = EXCLUDED.table_patterns,
    cron_hook_patterns = EXCLUDED.cron_hook_patterns,
    updated_at         = now();
`)

	if err := os.MkdirAll(filepath.Dir(outPath), 0o755); err != nil {
		return err
	}
	return os.WriteFile(outPath, []byte(buf.String()), 0o644)
}

// minPrefixBodyLen is the minimum number of characters that must appear between
// the ^ anchor and the trailing _ in an anchored prefix pattern. Prefixes shorter
// than this (e.g. ^et_, ^ep_, ^lp_) are too ambiguous: a 2-char root matches
// many unrelated option names from other plugins and could cause false-confidence
// misclassification when the item later becomes a ConfidencePrefix deletion candidate.
const minPrefixBodyLen = 4

// isShortAnchoredPrefix returns true for open-ended anchored prefix patterns of
// the form ^X_ (ending with an underscore) where the total body X has fewer than
// minPrefixBodyLen characters (not counting the leading ^ or trailing _). These
// patterns match ANY option/hook name that starts with X_, making them
// collision-prone when X is short (2–3 chars covers many unrelated plugins).
//
// Examples:
//   - "^et_"       → body = "et"   (2 chars) → rejected (2 < 4)
//   - "^wf_"       → body = "wf"   (2 chars) → rejected
//   - "^acf_"      → body = "acf"  (3 chars) → rejected (3 < 4)
//   - "^wpcf7_"    → body = "wpcf7" (5 chars) → allowed
//   - "^et_social_"→ body = "et_social" (8 chars) → allowed (total body >=4)
//
// Patterns that do NOT end with _ (e.g. ^ac_storage, ^wp_super_cache) are
// specific enough to be retained regardless of their body length.
func isShortAnchoredPrefix(p string) bool {
	if !strings.HasPrefix(p, "^") {
		return false
	}
	// Only apply to open-ended prefix patterns (trailing _).
	if !strings.HasSuffix(p, "_") {
		return false
	}
	// The body is everything between ^ and the trailing _.
	body := strings.TrimPrefix(p, "^")
	body = strings.TrimSuffix(body, "_")
	// Reject if the entire body (which may contain underscores) is shorter than
	// the minimum. A body like "et_social" (8 chars) is long enough; "et" (2) is not.
	return len(body) < minPrefixBodyLen
}

func filterValidPatterns(pats []string) []string {
	out := pats[:0]
	for _, p := range pats {
		if len(p) > maxPatternLen {
			continue
		}
		if isShortAnchoredPrefix(p) {
			log.Printf("WARN: anchored prefix %q rejected: body before first _ is shorter than %d chars", p, minPrefixBodyLen)
			continue
		}
		if !validatePattern(p) {
			log.Printf("WARN: invalid regexp pattern discarded: %q", p)
			continue
		}
		out = append(out, p)
	}
	return out
}

func toJSONArray(pats []string) string {
	if len(pats) == 0 {
		return "[]"
	}
	b, _ := json.Marshal(pats)
	// Escape single quotes in the JSON for embedding in SQL string literals.
	return strings.ReplaceAll(string(b), "'", "''")
}

func escapeSQLString(s string) string {
	return strings.ReplaceAll(s, "'", "''")
}

// ---- WordPress.org plugin listing -------------------------------------

// fetchPopularSlugs queries the wordpress.org plugins API for the top-N popular
// slugs, respecting a ~2 req/s rate limit.
func fetchPopularSlugs(ctx context.Context, client *http.Client, n int) ([]wporgPluginInfo, error) {
	var all []wporgPluginInfo
	perPage := 100
	page := 1
	ticker := time.NewTicker(500 * time.Millisecond) // 2 req/s
	defer ticker.Stop()

	for len(all) < n {
		select {
		case <-ctx.Done():
			return all, ctx.Err()
		case <-ticker.C:
		}

		apiURL := fmt.Sprintf(
			"https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[browse]=popular&request[per_page]=%d&request[page]=%d&request[fields][versions]=false&request[fields][downloaded]=false",
			perPage, page,
		)
		resp, err := guardedGet(client, apiURL)
		if err != nil {
			return nil, fmt.Errorf("list page %d: %w", page, err)
		}

		if resp.StatusCode == http.StatusTooManyRequests {
			log.Printf("429 on listing page %d; backing off 5s", page)
			resp.Body.Close()
			time.Sleep(5 * time.Second)
			continue
		}
		if resp.StatusCode != http.StatusOK {
			resp.Body.Close()
			return nil, fmt.Errorf("list page %d: HTTP %d", page, resp.StatusCode)
		}

		var result wporgQueryResponse
		if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
			resp.Body.Close()
			return nil, fmt.Errorf("decode page %d: %w", page, err)
		}
		resp.Body.Close()

		if len(result.Plugins) == 0 {
			break
		}
		all = append(all, result.Plugins...)
		if page >= result.Info.Pages {
			break
		}
		page++
	}

	if len(all) > n {
		all = all[:n]
	}
	return all, nil
}

// ---- Input YAML -------------------------------------------------------

// PluginsYAML is the optional input file at input/plugins.yaml. It lets the
// operator curate a fixed slug list (e.g. add paid plugins not on the public
// browse API) or skip certain slugs.
type PluginsYAML struct {
	// ExtraSlugVersions is a map of slug → version to include beyond the API list.
	ExtraSlugVersions map[string]string `yaml:"extra_slug_versions"`
	// SkipSlugs is a list of slugs to always skip (e.g. known-broken or very large).
	SkipSlugs []string `yaml:"skip_slugs"`
}

func loadPluginsYAML(path string) (*PluginsYAML, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return &PluginsYAML{}, nil
		}
		return nil, err
	}
	var py PluginsYAML
	return &py, yaml.Unmarshal(data, &py)
}

// ---- main -------------------------------------------------------------

func main() {
	nFlag := flag.Int("n", 300, "number of popular slugs to fetch from wordpress.org API")
	outFlag := flag.String("out", "", "output SQL file (default: migrations/seeds/plugin_signatures_v{version}.sql)")
	versionFlag := flag.Int("version", 1, "corpus_version integer to stamp in the SQL")
	manifestFlag := flag.String("manifest", "manifest.json", "path to manifest.json for resumability")
	dryRunFlag := flag.Bool("dry-run", false, "list slugs only; do not download or emit SQL")
	workersFlag := flag.Int("workers", 2, "max concurrent downloads (1-4)")
	inputFlag := flag.String("input", "input/plugins.yaml", "path to input/plugins.yaml")
	flag.Parse()

	if *workersFlag < 1 {
		*workersFlag = 1
	}
	if *workersFlag > 4 {
		*workersFlag = 4
	}

	outPath := *outFlag
	if outPath == "" {
		outPath = fmt.Sprintf("migrations/seeds/plugin_signatures_v%d.sql", *versionFlag)
	}

	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Printf("corpus-gen start: n=%d version=%d out=%s workers=%d", *nFlag, *versionFlag, outPath, *workersFlag)

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
	defer cancel()

	client := &http.Client{Timeout: 30 * time.Second}

	// Load optional input YAML.
	pluginsYAML, err := loadPluginsYAML(*inputFlag)
	if err != nil {
		log.Fatalf("load plugins yaml: %v", err)
	}

	skipSet := map[string]bool{}
	for _, s := range pluginsYAML.SkipSlugs {
		skipSet[s] = true
	}

	// Load manifest for resumability.
	manifest, err := loadManifest(*manifestFlag)
	if err != nil {
		log.Fatalf("load manifest: %v", err)
	}

	// Fetch popular slug list from wordpress.org API.
	log.Printf("fetching top %d popular slugs from wordpress.org...", *nFlag)
	plugins, err := fetchPopularSlugs(ctx, client, *nFlag)
	if err != nil {
		log.Printf("WARN: failed to fetch popular slugs: %v", err)
		log.Printf("falling back to manifest-only mode (use previously fetched slugs)")
	}

	// Merge extra slugs from YAML.
	slugSet := map[string]string{} // slug → version
	for _, p := range plugins {
		slugSet[p.Slug] = p.Version
	}
	for slug, ver := range pluginsYAML.ExtraSlugVersions {
		slugSet[slug] = ver
	}

	// Apply skip list.
	for s := range skipSet {
		delete(slugSet, s)
	}

	slugList := make([]string, 0, len(slugSet))
	for s := range slugSet {
		slugList = append(slugList, s)
	}
	sort.Strings(slugList)

	log.Printf("total slugs to process: %d", len(slugList))

	if *dryRunFlag {
		for _, s := range slugList {
			fmt.Printf("%s@%s\n", s, slugSet[s])
		}
		return
	}

	// Process slugs concurrently with a worker pool.
	type result struct {
		sp  *SlugPatterns
		err error
	}

	jobs := make(chan string, len(slugList))
	results := make(chan result, len(slugList))

	// Rate limiter: 2 req/s shared across all workers.
	rateTicker := time.NewTicker(500 * time.Millisecond)
	defer rateTicker.Stop()
	rateMu := &sync.Mutex{}

	waitForRate := func() {
		rateMu.Lock()
		defer rateMu.Unlock()
		<-rateTicker.C
	}

	var wg sync.WaitGroup
	for i := 0; i < *workersFlag; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for slug := range jobs {
				version := slugSet[slug]

				// Check manifest for already-processed entries.
				if entry, ok := manifest.get(slug); ok && entry.Extracted {
					log.Printf("skip %s (already extracted per manifest)", slug)
					results <- result{sp: nil}
					continue
				}
				if entry, ok := manifest.get(slug); ok && entry.Unavailable {
					log.Printf("skip %s (marked unavailable in manifest)", slug)
					results <- result{sp: nil}
					continue
				}

				waitForRate()

				var sp *SlugPatterns
				var fetchErr error
				for attempt := 0; attempt < 3; attempt++ {
					sp, fetchErr = downloadAndExtract(ctx, client, slug, version)
					if fetchErr == nil {
						break
					}
					// 429: back off.
					if strings.Contains(fetchErr.Error(), "429") {
						backoff := time.Duration(1<<uint(attempt)) * 2 * time.Second
						log.Printf("429 for %s; backoff %s", slug, backoff)
						time.Sleep(backoff)
						continue
					}
					// 404: mark unavailable; do not retry.
					if strings.Contains(fetchErr.Error(), "404") {
						log.Printf("WARN: %s unavailable (404); skipping", slug)
						manifest.set(&ManifestEntry{Slug: slug, Version: version, Unavailable: true})
						_ = manifest.save()
						break
					}
					break
				}

				if fetchErr != nil {
					log.Printf("WARN: %s fetch error: %v", slug, fetchErr)
					results <- result{sp: nil}
					continue
				}

				manifest.set(&ManifestEntry{
					Slug:        slug,
					Version:     version,
					Extracted:   true,
					ProcessedAt: time.Now().UTC().Format(time.RFC3339),
				})
				_ = manifest.save()

				results <- result{sp: sp}
			}
		}()
	}

	for _, slug := range slugList {
		jobs <- slug
	}
	close(jobs)

	go func() {
		wg.Wait()
		close(results)
	}()

	allPatterns := map[string]*SlugPatterns{}
	for r := range results {
		if r.sp == nil {
			continue
		}
		allPatterns[r.sp.Slug] = r.sp
	}

	log.Printf("extracted patterns for %d slugs", len(allPatterns))

	// Apply document-frequency suppression (drop patterns in >= 3 unrelated slugs).
	suppressByDocFreq(allPatterns, 3)

	// Emit SQL.
	if err := emitSQL(outPath, *versionFlag, allPatterns); err != nil {
		log.Fatalf("emit SQL: %v", err)
	}

	log.Printf("done: wrote %s with %d slug rows", outPath, len(allPatterns))
}
