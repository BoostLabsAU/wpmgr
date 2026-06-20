package vuln

import (
	"context"
	"encoding/json"
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
		errMsg := fmt.Sprintf("scanner feed fetch failed: %v", err)
		_ = w.repo.StampFeedError(ctx, errMsg)
		return fmt.Errorf("vuln feed refresh: %w", err) // River will retry
	}

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

// ingestRecords writes all records and the meta row in one InAgentTx.
func (w *FeedWorker) ingestRecords(ctx context.Context, records map[string]FeedRecord, knownIDs []string, meta FeedMetaUpdate) error {
	// Use raw pool Begin/Commit (global tables, no RLS; InAgentTx sets the GUC
	// but the global tables don't need it — we use a direct pool transaction to
	// avoid the GUC overhead and keep the import fast).
	tx, err := w.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	// Set agent GUC anyway (in case a policy is added later).
	if _, err := tx.Exec(ctx, "SELECT set_config('app.agent','on',true)"); err != nil {
		return fmt.Errorf("set agent guc: %w", err)
	}

	for _, rec := range records {
		if err := w.repo.UpsertFeedRecord(ctx, tx, rec); err != nil {
			return err
		}
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
		return nil, "", "", "", fmt.Errorf("rate limited (429) fetching %s; will retry next cycle", feedURL)
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
			w.logger.Warn("vuln: skipping unparseable record", slog.String("vuln_id", vulnID), slog.Any("error", err))
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

// wfRecord is the JSON shape of one Wordfence V3 vulnerability record.
// Only the fields we need are decoded; the rest are preserved in rawMsg.
type wfRecord struct {
	ID            string          `json:"id"`
	Title         string          `json:"title"`
	Informational bool            `json:"informational"`
	Published     *time.Time      `json:"published"`
	Updated       *time.Time      `json:"updated"`
	CVE           string          `json:"cve"`        // Scanner may omit; Production includes
	CVELink       string          `json:"cve_link"`   // ibid
	CVSSScore     *float64        `json:"cvss"`       // some feeds nest this; handled below
	CVSSRating    string          `json:"cvss_rating"` // ibid
	CVSS          *wfCVSS         `json:"cvss_obj"`   // nested block (alias key)
	CWE           json.RawMessage `json:"cwe"`
	References    json.RawMessage `json:"references"`
	Software      []wfSoftware    `json:"software"`
	Copyrights    *wfCopyrights   `json:"copyrights"`
}

type wfCVSS struct {
	Score  *float64 `json:"score"`
	Rating string   `json:"rating"`
}

type wfCopyrights struct {
	Defiant *wfCopyrightEntry `json:"defiant"`
	MITRE   *wfCopyrightEntry `json:"mitre"`
}

type wfCopyrightEntry struct {
	Notice  string `json:"notice"`
	License string `json:"license"`
}

type wfSoftware struct {
	Type             string          `json:"type"`  // core|plugin|theme
	Name             string          `json:"name"`
	Slug             string          `json:"slug"`
	AffectedVersions json.RawMessage `json:"affected_versions"`
	Patched          bool            `json:"patched"`
	PatchedVersions  json.RawMessage `json:"patched_versions"` // array OR map
}

func parseFeedRecord(vulnID string, raw json.RawMessage) (FeedRecord, string, string, string, error) {
	var rec wfRecord
	if err := json.Unmarshal(raw, &rec); err != nil {
		return FeedRecord{}, "", "", "", err
	}

	// Normalise CVSS fields — the feed shape varies between Scanner and
	// Production endpoints.
	cvssScore := rec.CVSSScore
	cvssRating := rec.CVSSRating
	if rec.CVSS != nil {
		if rec.CVSS.Score != nil {
			cvssScore = rec.CVSS.Score
		}
		if rec.CVSS.Rating != "" {
			cvssRating = rec.CVSS.Rating
		}
	}

	var defiantNotice, defiantLicense, mitreNotice string
	if rec.Copyrights != nil {
		if rec.Copyrights.Defiant != nil {
			defiantNotice = rec.Copyrights.Defiant.Notice
			defiantLicense = rec.Copyrights.Defiant.License
		}
		if rec.Copyrights.MITRE != nil {
			mitreNotice = rec.Copyrights.MITRE.Notice
		}
	}

	// F2: drop cve_link if it is not a safe http(s) URL.
	cveLink := rec.CVELink
	if cveLink != "" && !isSafeURL(cveLink) {
		cveLink = ""
	}

	// F2: filter the references array so only http(s) URLs reach the DB.
	// The feed can supply either an array of strings or an array of objects
	// with a "url" key. Both shapes are normalised here.
	refs := filterReferences(rec.References)
	if len(refs) == 0 {
		refs = []byte("[]")
	}
	cwe := rec.CWE
	if len(cwe) == 0 {
		cwe = nil
	}

	var software []SoftwareRow
	for _, sw := range rec.Software {
		kind := sw.Type
		if kind == "" {
			continue
		}
		avRaw := sw.AffectedVersions
		if len(avRaw) == 0 {
			avRaw = []byte("[]")
		}
		pvRaw := sw.PatchedVersions
		if len(pvRaw) == 0 {
			pvRaw = []byte("[]")
		}
		// PatchedVersions may be an array ["1.2","1.3"] or a map {"1.2":true} —
		// normalise to an array.
		pvRaw = normalisePatchedVersions(pvRaw)

		software = append(software, SoftwareRow{
			Kind:             kind,
			Slug:             sw.Slug,
			AffectedVersions: avRaw,
			Patched:          sw.Patched,
			PatchedVersions:  pvRaw,
		})
	}

	return FeedRecord{
		VulnID:        vulnID,
		Title:         rec.Title,
		CVE:           rec.CVE,
		CVELink:       cveLink,
		CVSSScore:     cvssScore,
		CVSSRating:    cvssRating,
		CWE:           cwe,
		Informational: rec.Informational,
		References:    refs,
		Published:     rec.Published,
		Updated:       rec.Updated,
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
