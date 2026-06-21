package vuln

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
)

// ---------------------------------------------------------------------------
// Feed ingester job
// ---------------------------------------------------------------------------

// FeedRefreshQueue is the River queue for the vulnerability feed refresh job.
const FeedRefreshQueue = "vuln_feed_refresh"

// FeedRefreshArgs is the River job payload for the hourly feed refresh.
type FeedRefreshArgs struct{}

// Kind implements river.JobArgs.
func (FeedRefreshArgs) Kind() string { return "vuln_feed_refresh" }

// ---------------------------------------------------------------------------
// Per-site rescan job
// ---------------------------------------------------------------------------

// RescanSiteQueue is the River queue for per-site rescan jobs.
const RescanSiteQueue = "vuln_rescan_site"

// RescanSiteArgs is the River job payload for a per-site vulnerability rescan.
type RescanSiteArgs struct {
	TenantID uuid.UUID `json:"tenant_id"`
	SiteID   uuid.UUID `json:"site_id"`
}

// Kind implements river.JobArgs.
func (RescanSiteArgs) Kind() string { return "vuln_rescan_site" }

// ---------------------------------------------------------------------------
// Wordfence Intelligence V3 feed URL
// ---------------------------------------------------------------------------

// The Scanner feed carries the minimal detection-critical data (affected
// versions, patched, severity). It is the primary fetch target.
// The Production feed additionally carries CVSS scores, CVE identifiers,
// remediation text, and the copyrights block. It is fetched on the same
// schedule and used to ENRICH existing rows (a separate upsert into the same
// wordfence_vuln_feed table).
const (
	wfScannerURL    = "https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner"
	wfProductionURL = "https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production"
)

// errRateLimited marks a 429 from the Wordfence feed. Wordfence documents no
// Retry-After and no per-window number ("too many requests in a short period"),
// so a 429 must NOT trigger River's aggressive retry — that just re-hits the
// endpoint and keeps the rate-limit window warm. Instead we stamp the status and
// succeed; the hourly periodic refresh is the natural, well-spaced retry.
var errRateLimited = errors.New("wordfence feed rate limited (429)")

// FeedHTTPDoer is the subset of httpclient.Client the feed worker needs.
type FeedHTTPDoer interface {
	Do(req *http.Request) (*http.Response, error)
}

// APIKeyResolver resolves the Wordfence Intelligence API key at job-run time.
// Priority: UI-stored instance setting (encrypted at rest) > WPMGR_WORDFENCE_API_KEY env > "".
// The concrete implementation lives in the admin package (to avoid an import
// cycle: vuln→admin is fine; admin→vuln is already fine).
type APIKeyResolver interface {
	// ResolveAPIKey returns the effective API key and the source ("ui"|"env"|"none").
	// Returns ("", "none") when no key is configured; never returns an error (logs internally).
	ResolveAPIKey(ctx context.Context) (key, source string)
}

// staticKeyResolver satisfies APIKeyResolver with a fixed key (env-only path,
// used when no admin.KeyStore is wired — e.g. unit tests or pre-m80 boot).
type staticKeyResolver struct{ key string }

func (s *staticKeyResolver) ResolveAPIKey(_ context.Context) (string, string) {
	if s.key == "" {
		return "", "none"
	}
	return s.key, "env"
}

// NewStaticKeyResolver wraps a plain API key string in an APIKeyResolver.
// Used in tests and as the fallback path in main before the admin store is wired.
func NewStaticKeyResolver(key string) APIKeyResolver { return &staticKeyResolver{key: key} }

// FeedWorker handles the hourly Wordfence Intelligence feed refresh.
type FeedWorker struct {
	river.WorkerDefaults[FeedRefreshArgs]
	repo     *Repo
	pool     *db.Pool
	svc      *Service
	resolver APIKeyResolver // resolves UI-stored key > env key at runtime
	client   FeedHTTPDoer
	logger   *slog.Logger
}

// NewFeedWorker builds a FeedWorker. resolver must not be nil; use
// NewStaticKeyResolver("") for the no-key case. The worker no-ops cleanly when
// resolver returns ("", "none") so self-hosters without a key do not crash.
func NewFeedWorker(repo *Repo, pool *db.Pool, svc *Service, resolver APIKeyResolver, client FeedHTTPDoer, logger *slog.Logger) *FeedWorker {
	if resolver == nil {
		resolver = &staticKeyResolver{}
	}
	return &FeedWorker{
		repo:     repo,
		pool:     pool,
		svc:      svc,
		resolver: resolver,
		client:   client,
		logger:   logger,
	}
}

// SetService wires the vuln service into the worker after construction. Called
// once at boot after startRiver returns (the service needs the River client).
func (w *FeedWorker) SetService(svc *Service) { w.svc = svc }

// Timeout gives the feed refresh job 10 minutes. A full-dump ingest of ~13k
// records via CopyFrom completes in seconds, but the two HTTP fetches + the
// 2s inter-fetch delay + any Postgres latency under load consume real wall
// time. 10 minutes is generous headroom well above the expected 30–60s wall
// time and avoids the context deadline that previously killed the per-record loop.
func (w *FeedWorker) Timeout(*river.Job[FeedRefreshArgs]) time.Duration {
	return 10 * time.Minute
}

// Work performs the feed refresh.
func (w *FeedWorker) Work(ctx context.Context, job *river.Job[FeedRefreshArgs]) error {
	// Resolve the key at run-time so a UI-set key takes effect on the next job
	// without requiring a restart. Priority: UI key > env key > no-op.
	apiKey, source := w.resolver.ResolveAPIKey(ctx)
	if apiKey == "" {
		w.logger.Debug("vuln: no API key configured; feed refresh skipped",
			slog.String("source", source))
		return nil
	}
	_ = source // used only for debug logging above

	w.logger.Info("vuln: starting Wordfence Intelligence feed refresh")

	// Fetch the Scanner feed (required).
	records, defiantNotice, defiantLicense, mitreNotice, err := w.fetchFeed(ctx, wfScannerURL, apiKey)
	if err != nil {
		// A 429 must NOT be retried by River: Wordfence rate-limits "too many
		// requests in a short period" with no Retry-After, so aggressive retries
		// keep the window warm and never recover. Stamp the status and SUCCEED;
		// the hourly periodic refresh is the natural, well-spaced retry.
		if errors.Is(err, errRateLimited) {
			_ = w.repo.StampFeedError(ctx, "rate limited by Wordfence; will retry on the next scheduled refresh")
			w.logger.Warn("vuln: scanner feed rate limited; skipping this cycle (no River retry)")
			return nil
		}
		errMsg := fmt.Sprintf("scanner feed fetch failed: %v", err)
		_ = w.repo.StampFeedError(ctx, errMsg)
		return fmt.Errorf("vuln feed refresh: %w", err) // River will retry real failures
	}

	// Brief spacing between the two fetches reduces the chance of hitting the
	// production endpoint's rate limit immediately after the scanner fetch.
	time.Sleep(2 * time.Second)

	// Optionally fetch the Production feed to enrich CVSS / CVE / copyrights.
	// Errors here are non-fatal: we proceed with whatever the Scanner feed gave us.
	prodRecords, prodDefiantNotice, prodDefiantLicense, prodMitreNotice, prodErr := w.fetchFeed(ctx, wfProductionURL, apiKey)
	if prodErr != nil {
		w.logger.Warn("vuln: production feed fetch failed; proceeding with scanner-only data",
			slog.Any("error", prodErr))
	} else {
		// Merge production enrichment into scanner records: CVSS, CVE, CWE, refs,
		// and copyrights (production feed has a richer copyrights block).
		records = mergeEnrichment(records, prodRecords)
		if prodDefiantNotice != "" {
			defiantNotice = prodDefiantNotice
		}
		if prodDefiantLicense != "" {
			defiantLicense = prodDefiantLicense
		}
		if prodMitreNotice != "" {
			mitreNotice = prodMitreNotice
		}
	}

	if len(records) == 0 {
		_ = w.repo.StampFeedError(ctx, "feed returned zero records; not applying update")
		w.logger.Warn("vuln: feed returned zero records; skipping update")
		return nil
	}

	// Persist in a batch transaction using the pool directly (feed tables have
	// no RLS, so no GUC setup is required; we set app.agent='on' anyway inside
	// ingestRecords for forward-compatibility).
	knownIDs := make([]string, 0, len(records))
	for id := range records {
		knownIDs = append(knownIDs, id)
	}

	pgErr := w.ingestRecords(ctx, records, knownIDs, FeedMetaUpdate{
		FetchedAt:      time.Now().UTC(),
		OK:             true,
		RecordCount:    len(records),
		DefiantNotice:  defiantNotice,
		DefiantLicense: defiantLicense,
		MitreNotice:    mitreNotice,
	})
	if pgErr != nil {
		_ = w.repo.StampFeedError(ctx, pgErr.Error())
		return fmt.Errorf("vuln: ingest records: %w", pgErr)
	}

	w.logger.Info("vuln: feed refresh complete",
		slog.Int("records", len(records)))

	// Trigger a cross-tenant rescan (throttled: enqueues per-site River jobs).
	if err := w.svc.RescanAll(ctx, uuid.Nil); err != nil {
		w.logger.Warn("vuln: post-feed rescan-all enqueue failed", slog.Any("error", err))
	}

	return nil
}

// ingestRecords writes all records and the meta row in one transaction using
// bulk operations. The previous per-record loop (13k × DELETE+INSERT round-trips)
// exceeded the River context deadline; the bulk path replaces that with:
//
//  1. A pgx Batch that sends all feed-row upserts in a single round-trip.
//  2. A set-based DELETE of software rows for every vuln_id in the batch, then
//     a single CopyFrom that streams all new software rows to Postgres.
//  3. PruneMissingVulns (single set-based DELETE of retracted vulns).
//  4. StampFeedMeta (single UPDATE).
//
// The entire operation is one transaction — atomic, with no partial state.
func (w *FeedWorker) ingestRecords(ctx context.Context, records map[string]FeedRecord, knownIDs []string, meta FeedMetaUpdate) error {
	// Flatten the map into a slice for deterministic ordering (maps in Go are
	// unordered; a slice makes the Batch and CopyFrom reproducible for debugging).
	recs := make([]FeedRecord, 0, len(records))
	for _, r := range records {
		recs = append(recs, r)
	}

	tx, err := w.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	// Set agent GUC (global tables have no RLS today; this is forward-compatible).
	if _, err := tx.Exec(ctx, "SELECT set_config('app.agent','on',true)"); err != nil {
		return fmt.Errorf("set agent guc: %w", err)
	}

	if err := w.repo.BulkUpsertFeedRecords(ctx, tx, recs); err != nil {
		return err
	}
	if err := w.repo.BulkReplaceAllSoftware(ctx, tx, recs); err != nil {
		return err
	}
	if err := w.repo.PruneMissingVulns(ctx, tx, knownIDs); err != nil {
		return err
	}
	if err := w.repo.StampFeedMeta(ctx, tx, meta); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

// fetchFeed downloads and parses a Wordfence Intelligence V3 feed URL.
// The V3 feed is a JSON object keyed by vuln UUID: { "<uuid>": { ... }, ... }.
// Returned values: records map, defiant notice, defiant license, mitre notice, error.
func (w *FeedWorker) fetchFeed(ctx context.Context, feedURL, apiKey string) (map[string]FeedRecord, string, string, string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, feedURL, nil)
	if err != nil {
		return nil, "", "", "", err
	}
	req.Header.Set("Authorization", "Bearer "+apiKey)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "WPMgr-VulnScanner/1.0")

	resp, err := w.client.Do(req)
	if err != nil {
		return nil, "", "", "", fmt.Errorf("http get %s: %w", feedURL, err)
	}
	defer func() { _ = resp.Body.Close() }()

	switch resp.StatusCode {
	case http.StatusOK:
		// proceed
	case http.StatusUnauthorized, http.StatusForbidden:
		// Bad or missing API key — surface a clean error without including the key
		// itself in the message (the message is stored in wordfence_vuln_feed_meta
		// and returned to the superadmin via the status endpoint).
		return nil, "", "", "", fmt.Errorf("feed auth failed (HTTP %d): check the Wordfence Intelligence API key in the superadmin settings", resp.StatusCode)
	case http.StatusTooManyRequests:
		// Honor Retry-After if present; one retry then fall back.
		retryAfter := resp.Header.Get("Retry-After")
		delay := 10 * time.Second
		if retryAfter != "" {
			if d, parseErr := time.ParseDuration(retryAfter + "s"); parseErr == nil && d > 0 && d <= 30*time.Second {
				delay = d
			}
		}
		w.logger.Debug("vuln: rate limited; retrying after delay",
			slog.String("url", feedURL), slog.Duration("delay", delay))
		time.Sleep(delay)

		// Single retry.
		req2, rerr := http.NewRequestWithContext(ctx, http.MethodGet, feedURL, nil)
		if rerr != nil {
			return nil, "", "", "", fmt.Errorf("rate limited (429) fetching %s: %w", feedURL, errRateLimited)
		}
		req2.Header.Set("Authorization", "Bearer "+apiKey)
		req2.Header.Set("Accept", "application/json")
		req2.Header.Set("User-Agent", "WPMgr-VulnScanner/1.0")
		resp2, rerr := w.client.Do(req2)
		if rerr != nil || resp2.StatusCode != http.StatusOK {
			if resp2 != nil {
				_ = resp2.Body.Close()
			}
			return nil, "", "", "", fmt.Errorf("rate limited (429) fetching %s: %w", feedURL, errRateLimited)
		}
		// Swap to the retry response for decoding below.
		_ = resp.Body.Close()
		resp = resp2
		defer func() { _ = resp.Body.Close() }()
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, "", "", "", fmt.Errorf("unexpected status %d from %s: %s", resp.StatusCode, feedURL, body)
	}

	// Stream-decode the root JSON object so we don't load multi-MB into memory
	// all at once.
	dec := json.NewDecoder(resp.Body)

	// Read opening "{".
	if tok, err := dec.Token(); err != nil || tok.(json.Delim) != '{' {
		return nil, "", "", "", fmt.Errorf("expected root object from %s", feedURL)
	}

	records := make(map[string]FeedRecord)
	var defiantNotice, defiantLicense, mitreNotice string

	for dec.More() {
		// Read the vuln UUID key.
		keyTok, err := dec.Token()
		if err != nil {
			return nil, "", "", "", fmt.Errorf("read key: %w", err)
		}
		vulnID, ok := keyTok.(string)
		if !ok {
			continue
		}

		// Decode the record object as raw JSON first (so we can preserve it in raw).
		var rawMsg json.RawMessage
		if err := dec.Decode(&rawMsg); err != nil {
			return nil, "", "", "", fmt.Errorf("decode record %s: %w", vulnID, err)
		}

		rec, notice, license, mitre, err := parseFeedRecord(vulnID, rawMsg)
		if err != nil {
			if errors.Is(err, errNoUsableSoftware) {
				// A record with no usable software cannot match any site inventory.
				// Skip at Debug level — this is expected for certain informational entries.
				w.logger.Debug("vuln: skipping record with no usable software",
					slog.String("vuln_id", vulnID))
			} else {
				// Defensive catch-all: parseFeedRecord is designed to never return other
				// errors, but guard here anyway.
				w.logger.Warn("vuln: skipping unparseable record",
					slog.String("vuln_id", vulnID), slog.Any("error", err))
			}
			continue
		}

		records[vulnID] = rec

		// Capture the first non-empty attribution texts seen.
		if defiantNotice == "" && notice != "" {
			defiantNotice = notice
		}
		if defiantLicense == "" && license != "" {
			defiantLicense = license
		}
		if mitreNotice == "" && mitre != "" {
			mitreNotice = mitre
		}
	}

	return records, defiantNotice, defiantLicense, mitreNotice, nil
}

// ---------------------------------------------------------------------------
// Feed record JSON types
// ---------------------------------------------------------------------------

// errNoUsableSoftware is returned by parseFeedRecord when a record carries no
// software entry with a non-empty allow-listed type and non-empty slug. This is
// the ONE legitimate whole-record skip. The caller treats it as Debug-level.
var errNoUsableSoftware = errors.New("no usable software entry")

// wfTimeLayouts are tried in order. The space-separated layout is the real v3
// feed format; date-only and RFC3339 are tolerated as forward-compatible fallbacks.
var wfTimeLayouts = []string{
	"2006-01-02 15:04:05", // real Wordfence v3 format (UTC, space-separated, no T)
	"2006-01-02",          // date-only fallback
	time.RFC3339,          // RFC3339 fallback (forward-tolerant)
}

// wfTime is a Wordfence-feed timestamp. The v3 feed emits UTC datetimes as
// "YYYY-MM-DD HH:MM:SS" (no T, no zone); it never uses RFC3339. UnmarshalJSON
// is intentionally lenient: a null, empty, or unrecognised value yields a nil
// time and never an error, so one bad timestamp field can never drop a record.
type wfTime struct{ t *time.Time }

func (w *wfTime) UnmarshalJSON(b []byte) error {
	w.t = nil
	s := strings.TrimSpace(string(b))
	if s == "" || s == "null" || s == `""` {
		return nil // not disclosed → nil, no error
	}
	var raw string
	if err := json.Unmarshal(b, &raw); err != nil {
		return nil // not a JSON string → ignore the field, keep the record
	}
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil
	}
	for _, layout := range wfTimeLayouts {
		if parsed, err := time.Parse(layout, raw); err == nil {
			// Pin to UTC: the space-separated and date-only layouts carry no zone;
			// the feed declares all timestamps UTC.
			u := parsed.UTC()
			w.t = &u
			return nil
		}
	}
	return nil // unrecognised format → nil time, record still ingests
}

// Time returns the parsed *time.Time (nil when absent or unparseable).
func (w wfTime) Time() *time.Time { return w.t }

// wfRecord is the JSON shape of one Wordfence V3 vulnerability record.
// Fields that can carry "odd" shapes are typed as json.RawMessage and decoded
// by extractors so a single malformed field can never fail the whole record.
type wfRecord struct {
	ID            string          `json:"id"`
	Title         string          `json:"title"`
	Informational bool            `json:"informational"`
	Published     wfTime          `json:"published"`
	Updated       wfTime          `json:"updated"`
	CVE           json.RawMessage `json:"cve"`      // string or array in some shapes
	CVELink       string          `json:"cve_link"` // may be absent on Scanner feed
	CVSS          json.RawMessage `json:"cvss"`     // object {vector,score,rating} or null
	CWE           json.RawMessage `json:"cwe"`
	References    json.RawMessage `json:"references"`
	Software      json.RawMessage `json:"software"`   // decoded best-effort
	Copyrights    json.RawMessage `json:"copyrights"` // decoded best-effort
}

// wfCVSS is the object shape inside the "cvss" key of the Production feed.
type wfCVSS struct {
	Score  *float64 `json:"score"`
	Rating string   `json:"rating"`
}

// wfCopyrightEntry holds one party's attribution data.
type wfCopyrightEntry struct {
	Notice  string `json:"notice"`
	License string `json:"license"`
}

// wfCopyrightsObj is the typed structure for the copyrights block.
type wfCopyrightsObj struct {
	Defiant *wfCopyrightEntry `json:"defiant"`
	MITRE   *wfCopyrightEntry `json:"mitre"`
}

// wfSoftware is one entry in the software[] array.
type wfSoftware struct {
	Type             string          `json:"type"`    // core|plugin|theme
	Name             string          `json:"name"`
	Slug             string          `json:"slug"`
	AffectedVersions json.RawMessage `json:"affected_versions"`
	Patched          bool            `json:"patched"`
	PatchedVersions  json.RawMessage `json:"patched_versions"` // array OR map
	Informational    *bool           `json:"informational"`    // scanner carries this at software level
}

// wfSoftwareTypeAllowList is the set of valid software type values.
var wfSoftwareTypeAllowList = map[string]bool{
	"core":   true,
	"plugin": true,
	"theme":  true,
}

// ---------------------------------------------------------------------------
// Field extractors
// ---------------------------------------------------------------------------

// extractCVE returns a CVE string from a raw JSON value that may be a plain
// string, an array of strings, or null. Returns "" on any odd shape.
func extractCVE(raw json.RawMessage) string {
	if len(raw) == 0 || string(raw) == "null" {
		return ""
	}
	// Try plain string first.
	var s string
	if json.Unmarshal(raw, &s) == nil {
		return s
	}
	// Try array; return first element.
	var arr []string
	if json.Unmarshal(raw, &arr) == nil && len(arr) > 0 {
		return arr[0]
	}
	return ""
}

// extractCVSS decodes a raw "cvss" JSON value into a score and rating.
// The real v3 feed sends an object {vector, score, rating}; for forward
// tolerance, a bare number is also accepted as a score-only value.
// Returns (nil, "") on null/absent/unparseable input.
func extractCVSS(raw json.RawMessage) (score *float64, rating string) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, ""
	}
	// Try object {score, rating} first (real v3 format).
	var obj wfCVSS
	if json.Unmarshal(raw, &obj) == nil && obj.Score != nil {
		return obj.Score, obj.Rating
	}
	// Forward-tolerance: bare number.
	var f float64
	if json.Unmarshal(raw, &f) == nil {
		return &f, ""
	}
	return nil, ""
}

// extractCopyrights decodes the raw copyrights block, returning (defiantNotice,
// defiantLicense, mitreNotice). Returns ("","","") on any odd shape so a
// malformed copyrights block cannot drop the record.
func extractCopyrights(raw json.RawMessage) (defiantNotice, defiantLicense, mitreNotice string) {
	if len(raw) == 0 || string(raw) == "null" {
		return "", "", ""
	}
	var cp wfCopyrightsObj
	if err := json.Unmarshal(raw, &cp); err != nil {
		return "", "", ""
	}
	if cp.Defiant != nil {
		defiantNotice = cp.Defiant.Notice
		defiantLicense = cp.Defiant.License
	}
	if cp.MITRE != nil {
		mitreNotice = cp.MITRE.Notice
	}
	return defiantNotice, defiantLicense, mitreNotice
}

// ---------------------------------------------------------------------------
// Core parser
// ---------------------------------------------------------------------------

// parseFeedRecord decodes one raw Wordfence V3 vulnerability record.
//
// Design: the ONLY non-nil error returned is errNoUsableSoftware (when the
// record has no software entry with a non-empty allow-listed type and non-empty
// slug). Every other field is decoded best-effort: a null/malformed/unexpected
// value defaults to zero/nil and the record still ingests. This means a single
// bad timestamp, cvss object, or copyrights block can never silence the record.
func parseFeedRecord(vulnID string, raw json.RawMessage) (FeedRecord, string, string, string, error) {
	var rec wfRecord
	// The struct uses only safe field types (wfTime, json.RawMessage, string,
	// bool) so this unmarshal cannot fail due to field-level type mismatches.
	if err := json.Unmarshal(raw, &rec); err != nil {
		// Structural failure (not a JSON object at all). Very unlikely on a well-formed
		// feed but guard anyway; return errNoUsableSoftware so the caller skips quietly.
		return FeedRecord{}, "", "", "", errNoUsableSoftware
	}

	// --- cvss: real key is "cvss", shape is {vector,score,rating} or null ---
	cvssScore, cvssRating := extractCVSS(rec.CVSS)

	// --- cve: string or array (decode best-effort) ---
	cve := extractCVE(rec.CVE)

	// --- cve_link: keep only safe http(s) URLs ---
	cveLink := rec.CVELink
	if cveLink != "" && !isSafeURL(cveLink) {
		cveLink = ""
	}

	// --- references: filter to safe URLs ---
	refs := filterReferences(rec.References)
	if len(refs) == 0 {
		refs = []byte("[]")
	}

	// --- cwe: keep raw, nil-safe ---
	cwe := rec.CWE
	if len(cwe) == 0 {
		cwe = nil
	}

	// --- copyrights: best-effort decode ---
	defiantNotice, defiantLicense, mitreNotice := extractCopyrights(rec.Copyrights)

	// --- informational: record-level OR-ed with any software-level true ---
	informational := rec.Informational

	// --- software: decode best-effort; odd shape → nil slice → skip record ---
	var rawSoftware []wfSoftware
	if len(rec.Software) > 0 && string(rec.Software) != "null" {
		// Ignore the error: an odd shape (not an array) leaves rawSoftware nil,
		// which will trigger the errNoUsableSoftware skip below.
		_ = json.Unmarshal(rec.Software, &rawSoftware)
	}

	var software []SoftwareRow
	for _, sw := range rawSoftware {
		// OR-up software-level informational into the record-level flag.
		if sw.Informational != nil && *sw.Informational {
			informational = true
		}

		kind := sw.Type
		if kind == "" || !wfSoftwareTypeAllowList[kind] {
			// Unknown or empty type: skip this software ROW, not the record.
			continue
		}
		slug := normSlug(sw.Slug)
		if slug == "" {
			// A software row with no slug can never match any inventory item.
			continue
		}

		avRaw := sw.AffectedVersions
		if len(avRaw) == 0 || string(avRaw) == "null" {
			// Default to empty object (matching the real v3 object shape) rather
			// than "[]" (an array), so the matcher's object-parser sees the right type.
			avRaw = []byte("{}")
		}

		pvRaw := sw.PatchedVersions
		if len(pvRaw) == 0 || string(pvRaw) == "null" {
			pvRaw = []byte("[]")
		}
		// PatchedVersions may be an array ["1.2","1.3"] or a map {"1.2":true} —
		// normalise to an array.
		pvRaw = normalisePatchedVersions(pvRaw)

		software = append(software, SoftwareRow{
			Kind:             kind,
			Slug:             slug,
			AffectedVersions: avRaw,
			Patched:          sw.Patched,
			PatchedVersions:  pvRaw,
		})
	}

	// Essential-field rule: a record is ingestable iff it has at least one usable
	// software entry (non-empty allow-listed type + non-empty slug).
	if len(software) == 0 {
		return FeedRecord{}, "", "", "", errNoUsableSoftware
	}

	return FeedRecord{
		VulnID:        vulnID,
		Title:         rec.Title,
		CVE:           cve,
		CVELink:       cveLink,
		CVSSScore:     cvssScore,
		CVSSRating:    cvssRating,
		CWE:           cwe,
		Informational: informational,
		References:    refs,
		Published:     rec.Published.Time(),
		Updated:       rec.Updated.Time(),
		Raw:           raw,
		Software:      software,
	}, defiantNotice, defiantLicense, mitreNotice, nil
}

// isSafeURL reports whether a URL has an http:// or https:// scheme.
// F2: used to drop feed-supplied javascript:/data:/etc. references before
// they reach the database, so a malicious feed entry cannot inject a
// non-HTTP URL that would later be rendered as a clickable link in the UI.
func isSafeURL(u string) bool {
	lower := strings.ToLower(strings.TrimSpace(u))
	return strings.HasPrefix(lower, "http://") || strings.HasPrefix(lower, "https://")
}

// filterReferences removes any entry from a Wordfence references JSON array
// whose URL is not an http(s) URL. The feed supports two shapes:
//
//   - array of strings: ["https://example.com", ...]
//   - array of objects: [{"url":"https://example.com"}, ...]
//
// Returns a JSON array of safe URLs in string form, or nil when the input is
// empty/unparseable (callers default to "[]").
func filterReferences(raw json.RawMessage) json.RawMessage {
	if len(raw) == 0 || string(raw) == "null" {
		return nil
	}
	// Try array-of-strings first.
	var strs []string
	if json.Unmarshal(raw, &strs) == nil {
		safe := strs[:0]
		for _, u := range strs {
			if isSafeURL(u) {
				safe = append(safe, u)
			}
		}
		b, _ := json.Marshal(safe)
		return b
	}
	// Try array-of-objects with a "url" field.
	var objs []struct {
		URL string `json:"url"`
	}
	if json.Unmarshal(raw, &objs) == nil {
		safe := make([]string, 0, len(objs))
		for _, o := range objs {
			if isSafeURL(o.URL) {
				safe = append(safe, o.URL)
			}
		}
		b, _ := json.Marshal(safe)
		return b
	}
	// Unparseable: return nil so the caller substitutes "[]".
	return nil
}

// normalisePatchedVersions converts either a JSON string array or a JSON
// object (map[version]bool/null) to a uniform JSON string array.
func normalisePatchedVersions(raw []byte) []byte {
	if len(raw) == 0 || string(raw) == "null" {
		return []byte("[]")
	}
	trimmed := []byte{}
	for _, b := range raw {
		if b != ' ' && b != '\t' && b != '\n' {
			trimmed = append(trimmed, b)
			break
		}
	}
	if len(trimmed) == 0 {
		return []byte("[]")
	}
	if raw[0] == '[' {
		return raw // already an array
	}
	if raw[0] == '{' {
		// It's a map — extract the keys.
		var m map[string]json.RawMessage
		if err := json.Unmarshal(raw, &m); err != nil {
			return []byte("[]")
		}
		keys := make([]string, 0, len(m))
		for k := range m {
			keys = append(keys, k)
		}
		b, _ := json.Marshal(keys)
		return b
	}
	return []byte("[]")
}

// mergeEnrichment copies CVSS, CVE, CWE, references, and copyrights from
// the production records into the scanner records.  The scanner feed is the
// canonical detection authority; production enriches display fields only.
func mergeEnrichment(scanner, production map[string]FeedRecord) map[string]FeedRecord {
	for id, prod := range production {
		sc, ok := scanner[id]
		if !ok {
			// Production has records not in Scanner (older/retracted); skip.
			continue
		}
		if sc.CVSSScore == nil && prod.CVSSScore != nil {
			sc.CVSSScore = prod.CVSSScore
		}
		if sc.CVSSRating == "" && prod.CVSSRating != "" {
			sc.CVSSRating = prod.CVSSRating
		}
		if sc.CVE == "" && prod.CVE != "" {
			sc.CVE = prod.CVE
		}
		if sc.CVELink == "" && prod.CVELink != "" {
			sc.CVELink = prod.CVELink
		}
		if len(sc.CWE) == 0 && len(prod.CWE) > 0 {
			sc.CWE = prod.CWE
		}
		if string(sc.References) == "[]" && string(prod.References) != "[]" && len(prod.References) > 0 {
			sc.References = prod.References
		}
		// Use the production raw for the stored snapshot (richer data).
		sc.Raw = prod.Raw
		scanner[id] = sc
	}
	return scanner
}

// ---------------------------------------------------------------------------
// Per-site rescan worker
// ---------------------------------------------------------------------------

// RescanSiteWorker handles per-site rescan jobs enqueued after a feed refresh
// or after an inventory change.
type RescanSiteWorker struct {
	river.WorkerDefaults[RescanSiteArgs]
	svc    *Service
	logger *slog.Logger
}

// NewRescanSiteWorker builds a RescanSiteWorker.
func NewRescanSiteWorker(svc *Service, logger *slog.Logger) *RescanSiteWorker {
	return &RescanSiteWorker{svc: svc, logger: logger}
}

// SetService wires the vuln service into the worker after construction. Called
// once at boot after startRiver returns (the service needs the River client).
func (w *RescanSiteWorker) SetService(svc *Service) { w.svc = svc }

// Work performs the per-site vulnerability rescan.
func (w *RescanSiteWorker) Work(ctx context.Context, job *river.Job[RescanSiteArgs]) error {
	args := job.Args
	if err := w.svc.RescanSite(ctx, args.TenantID, args.SiteID); err != nil {
		w.logger.Warn("vuln: site rescan failed",
			slog.String("tenant_id", args.TenantID.String()),
			slog.String("site_id", args.SiteID.String()),
			slog.Any("error", err))
		return err
	}
	return nil
}
