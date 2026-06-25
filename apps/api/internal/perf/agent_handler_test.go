package perf

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/objectcache"
	rucssmodel "github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
	rucssworker "github.com/mosamlife/wpmgr/apps/api/internal/rucss/worker"
)

// fakeRucssRepo satisfies perf.RucssRepo for the ingest service.
type fakeRucssRepo struct {
	hit    rucssmodel.Result
	hasHit bool
	jobs   []rucssmodel.Job
	jobErr error
}

func (r *fakeRucssRepo) GetByHash(_ context.Context, _, _ uuid.UUID, _ string) (rucssmodel.Result, error) {
	if r.hasHit {
		return r.hit, nil
	}
	return rucssmodel.Result{}, errNotFound
}

func (r *fakeRucssRepo) InsertJob(_ context.Context, j rucssmodel.Job) (rucssmodel.Job, error) {
	r.jobs = append(r.jobs, j)
	return j, r.jobErr
}

var errNotFound = io.EOF // any non-nil error ⇒ treated as a miss by Ingest

type memStore struct {
	mu   map[string][]byte
	puts int
}

func newMemStore() *memStore { return &memStore{mu: map[string][]byte{}} }

func (s *memStore) Put(_ context.Context, key string, body io.Reader, _ int64) error {
	b, _ := io.ReadAll(body)
	s.mu[key] = b
	s.puts++
	return nil
}
func (s *memStore) Get(_ context.Context, key string) (io.ReadCloser, error) {
	b, ok := s.mu[key]
	if !ok {
		return nil, io.EOF
	}
	return io.NopCloser(bytes.NewReader(b)), nil
}

func (s *memStore) Delete(_ context.Context, key string) error {
	delete(s.mu, key)
	return nil
}

// seed pre-loads an object (used to stage a cache-hit used-CSS object).
func (s *memStore) seed(key string, b []byte) { s.mu[key] = b }

type fakeEnqueuer struct{ args []rucssworker.RucssArgs }

func (e *fakeEnqueuer) EnqueueRucss(_ context.Context, a rucssworker.RucssArgs) error {
	e.args = append(e.args, a)
	return nil
}

// withIdentity wraps a handler so the request context carries a verified agent
// identity, mimicking the Ed25519 middleware.
func withIdentity(id agent.Identity, h gin.HandlerFunc) gin.HandlerFunc {
	return func(c *gin.Context) {
		c.Request = c.Request.WithContext(agent.WithIdentity(c.Request.Context(), id))
		h(c)
	}
}

func buildAgentEngine(t *testing.T, id agent.Identity, ingest *RucssIngestService) *gin.Engine {
	t.Helper()
	gin.SetMode(gin.TestMode)
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	h := NewAgentHandler(svc, ingest, nil)
	eng := gin.New()
	eng.POST("/agent/v1/rucss", withIdentity(id, h.rucssIngest))
	return eng
}

func buildConfigAckEngine(t *testing.T, id agent.Identity, repo *fakeRepo) *gin.Engine {
	t.Helper()
	gin.SetMode(gin.TestMode)
	svc := NewService(repo, nil, nil, nil)
	h := NewAgentHandler(svc, nil, nil)
	eng := gin.New()
	eng.POST("/agent/v1/perf/config-ack", withIdentity(id, h.configAck))
	return eng
}

func multipartRUCSS(t *testing.T, meta string, html, css []byte) (*bytes.Buffer, string) {
	t.Helper()
	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	if meta != "" {
		mw, _ := w.CreateFormField("meta")
		_, _ = mw.Write([]byte(meta))
	}
	if html != nil {
		hw, _ := w.CreateFormField("html")
		_, _ = hw.Write(html)
	}
	if css != nil {
		cw, _ := w.CreateFormField("css")
		_, _ = cw.Write(css)
	}
	_ = w.Close()
	return &buf, w.FormDataContentType()
}

// gzipFor gzip-compresses b the same way the rucss service stores used-CSS.
func gzipFor(t *testing.T, b []byte) []byte {
	t.Helper()
	var buf bytes.Buffer
	w := gzip.NewWriter(&buf)
	if _, err := w.Write(b); err != nil {
		t.Fatalf("gzip write: %v", err)
	}
	if err := w.Close(); err != nil {
		t.Fatalf("gzip close: %v", err)
	}
	return buf.Bytes()
}

func TestConfigAckOmittedRumBeaconKeyPresenceLeavesUnknown(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	repo := &fakeRepo{config: Config{}, configFound: true}
	eng := buildConfigAckEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, repo)
	body, _ := json.Marshal(map[string]any{
		"config_version":        7,
		"server_software":       "nginx",
		"dropin_installed":      true,
		"wp_cache_constant_set": true,
		"htaccess_managed":      false,
	})
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/perf/config-ack", bytes.NewReader(body))
	rec := httptest.NewRecorder()

	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	if len(repo.installUpdates) != 1 {
		t.Fatalf("expected one install-state update, got %d", len(repo.installUpdates))
	}
	if repo.installUpdates[0].RumBeaconKeyPresent != nil {
		t.Fatalf("omitted rum_beacon_key_present must stay nil, got %#v", repo.installUpdates[0].RumBeaconKeyPresent)
	}
	if repo.config.RumAgentBeaconKeySet != nil {
		t.Fatalf("omitted rum_beacon_key_present must leave state unknown, got %#v", repo.config.RumAgentBeaconKeySet)
	}
}

func TestConfigAckPersistsRumBeaconKeyPresence(t *testing.T) {
	tests := []struct {
		name  string
		value bool
	}{
		{name: "present", value: true},
		{name: "missing", value: false},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			siteID, tenantID := uuid.New(), uuid.New()
			repo := &fakeRepo{config: Config{}, configFound: true}
			eng := buildConfigAckEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, repo)
			body, _ := json.Marshal(map[string]any{
				"config_version":         8,
				"server_software":        "nginx",
				"dropin_installed":       true,
				"wp_cache_constant_set":  true,
				"htaccess_managed":       false,
				"rum_beacon_key_present": tc.value,
			})
			req := httptest.NewRequest(http.MethodPost, "/agent/v1/perf/config-ack", bytes.NewReader(body))
			rec := httptest.NewRecorder()

			eng.ServeHTTP(rec, req)

			if rec.Code != http.StatusOK {
				t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
			}
			if repo.config.RumAgentBeaconKeySet == nil || *repo.config.RumAgentBeaconKeySet != tc.value {
				t.Fatalf("rum_agent_beacon_key_set = %#v, want %v", repo.config.RumAgentBeaconKeySet, tc.value)
			}
			if repo.config.RumAgentBeaconKeyReportedAt == nil {
				t.Fatal("expected rum_agent_beacon_key_reported_at to be stamped")
			}
		})
	}
}

// FIX 2: on a cache hit the handler must return the used-CSS CONTENT (text/css,
// gzip), NOT an S3 key the agent cannot fetch.
func TestRucssIngestCacheHitReturnsUsedCSSContent(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	const usedCSS = ".a{color:red}"
	key := "rucss/" + tenantID.String() + "/" + siteID.String() + "/h1.css.gz"
	store := newMemStore()
	store.seed(key, gzipFor(t, []byte(usedCSS))) // stage the stored (gzip) object

	repo := &fakeRucssRepo{hasHit: true, hit: rucssmodel.Result{UsedCSSS3Key: key, ReductionPct: 60, UsedCSSBytes: len(usedCSS)}}
	ingest := NewRucssIngestService(repo, store, &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	body, ct := multipartRUCSS(t, `{"site_id":"`+siteID.String()+`","structure_hash":"h1"}`, []byte("<html>"), []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 on cache hit, got %d body=%s", rec.Code, rec.Body.String())
	}
	// Content negotiation: text/css + gzip, and the body must NOT be the S3 key.
	if got := rec.Header().Get("Content-Type"); !strings.HasPrefix(got, "text/css") {
		t.Fatalf("expected Content-Type text/css, got %q", got)
	}
	if got := rec.Header().Get("Content-Encoding"); got != "gzip" {
		t.Fatalf("expected Content-Encoding gzip, got %q", got)
	}
	if strings.Contains(rec.Body.String(), key) || strings.Contains(rec.Body.String(), "used_css_s3_key") {
		t.Fatalf("response must NOT leak the S3 key; got %s", rec.Body.String())
	}
	// The gzip body must inflate back to the exact used CSS.
	gr, err := gzip.NewReader(bytes.NewReader(rec.Body.Bytes()))
	if err != nil {
		t.Fatalf("response body is not gzip: %v", err)
	}
	plain, err := io.ReadAll(gr)
	if err != nil {
		t.Fatalf("inflate body: %v", err)
	}
	if string(plain) != usedCSS {
		t.Fatalf("used-CSS content mismatch: got %q want %q", plain, usedCSS)
	}
}

// When the stored used-CSS object cannot be read on a hit, the handler must
// degrade to a 202 miss (so the agent keeps serving full CSS) rather than error.
func TestRucssIngestCacheHitObjectMissingDegradesTo202(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	repo := &fakeRucssRepo{hasHit: true, hit: rucssmodel.Result{UsedCSSS3Key: "rucss/gone.css.gz", ReductionPct: 60, UsedCSSBytes: 100}}
	store := newMemStore() // object NOT seeded → Get fails
	ingest := NewRucssIngestService(repo, store, &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	body, ct := multipartRUCSS(t, `{"site_id":"`+siteID.String()+`","structure_hash":"h1"}`, []byte("<html>"), []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusAccepted {
		t.Fatalf("expected 202 when used-CSS object is unreadable, got %d body=%s", rec.Code, rec.Body.String())
	}
}

func TestRucssIngestMissEnqueues(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	repo := &fakeRucssRepo{hasHit: false}
	store := newMemStore()
	enq := &fakeEnqueuer{}
	ingest := NewRucssIngestService(repo, store, enq, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	body, ct := multipartRUCSS(t, `{"site_id":"`+siteID.String()+`","structure_hash":"h2","url":"/p"}`, []byte("<html>x</html>"), []byte(".a{color:red}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusAccepted {
		t.Fatalf("expected 202 processing on miss, got %d body=%s", rec.Code, rec.Body.String())
	}
	if store.puts != 1 {
		t.Fatalf("expected source bundle stashed once, got %d puts", store.puts)
	}
	if len(enq.args) != 1 || enq.args[0].StructureHash != "h2" || enq.args[0].SiteID != siteID {
		t.Fatalf("expected one enqueued job for h2/site, got %+v", enq.args)
	}
	// The bundle must round-trip back to the original html/css.
	src, err := NewRucssSourceFetcher(store).Fetch(context.Background(), enq.args[0])
	if err != nil {
		t.Fatalf("fetch source: %v", err)
	}
	if string(src.HTML) != "<html>x</html>" || string(src.CSS) != ".a{color:red}" {
		t.Fatalf("bundle round-trip mismatch: html=%q css=%q", src.HTML, src.CSS)
	}
}

func TestRucssIngestSiteBindingMismatch(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	otherSite := uuid.New()
	ingest := NewRucssIngestService(&fakeRucssRepo{}, newMemStore(), &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	// meta.site_id is a DIFFERENT site than the JWT-bound identity.
	body, ct := multipartRUCSS(t, `{"site_id":"`+otherSite.String()+`","structure_hash":"h3"}`, []byte("<html>"), []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	// The cross-site attempt must be rejected with 403 (the body shape is the
	// generated OpenAPI Error schema; the status is the security-relevant signal).
	if rec.Code != http.StatusForbidden {
		t.Fatalf("expected 403 on cross-site, got %d body=%s", rec.Code, rec.Body.String())
	}
}

func TestRucssIngestHTMLOverLimit413(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	ingest := NewRucssIngestService(&fakeRucssRepo{}, newMemStore(), &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	// HTML one byte over the 10MB cap.
	big := bytes.Repeat([]byte("a"), maxRucssHTML+1)
	body, ct := multipartRUCSS(t, `{"site_id":"`+siteID.String()+`","structure_hash":"h4"}`, big, []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusRequestEntityTooLarge {
		t.Fatalf("expected 413 on oversize html, got %d body=%s", rec.Code, rec.Body.String())
	}
}

func TestRucssIngestCSSOverLimit413(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	ingest := NewRucssIngestService(&fakeRucssRepo{}, newMemStore(), &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	bigCSS := bytes.Repeat([]byte("b"), maxRucssCSS+1)
	body, ct := multipartRUCSS(t, `{"site_id":"`+siteID.String()+`","structure_hash":"h5"}`, []byte("<html>"), bigCSS)
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusRequestEntityTooLarge {
		t.Fatalf("expected 413 on oversize css, got %d body=%s", rec.Code, rec.Body.String())
	}
}

func TestRucssIngestMissingMetaRejected(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	ingest := NewRucssIngestService(&fakeRucssRepo{}, newMemStore(), &fakeEnqueuer{}, domain.FixedClock{}, nil)
	eng := buildAgentEngine(t, agent.Identity{SiteID: siteID, TenantID: tenantID}, ingest)

	body, ct := multipartRUCSS(t, "", []byte("<html>"), []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 on missing meta, got %d body=%s", rec.Code, rec.Body.String())
	}
}

func TestRucssIngestNoIdentity401(t *testing.T) {
	gin.SetMode(gin.TestMode)
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	h := NewAgentHandler(svc, NewRucssIngestService(&fakeRucssRepo{}, newMemStore(), &fakeEnqueuer{}, domain.FixedClock{}, nil), nil)
	eng := gin.New()
	eng.POST("/agent/v1/rucss", h.rucssIngest) // NO identity injected

	body, ct := multipartRUCSS(t, `{"structure_hash":"h"}`, []byte("<html>"), []byte(".a{}"))
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/rucss", body)
	req.Header.Set("Content-Type", ct)
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 without identity, got %d", rec.Code)
	}
}

// ---------------------------------------------------------------------------
// Fakes for object-cache stats-report tests
// ---------------------------------------------------------------------------

// fakeOCSvc is a test double for the objectCacheSvc interface used by
// AgentHandler. It records calls so tests can assert ingest happened.
type fakeOCSvc struct {
	mu           sync.Mutex
	statsInputs  []objectcache.IngestStatsInput
	statsErr     error
	heartbeats   []*objectcache.HeartbeatBlock
	heartbeatErr error
}

func (f *fakeOCSvc) IngestStats(_ context.Context, input objectcache.IngestStatsInput) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.statsInputs = append(f.statsInputs, input)
	return f.statsErr
}

func (f *fakeOCSvc) IngestHeartbeat(_ context.Context, _, _ uuid.UUID, block *objectcache.HeartbeatBlock) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.heartbeats = append(f.heartbeats, block)
	return f.heartbeatErr
}

// buildStatsReportEngine builds a Gin engine that wires statsReport with an
// identity and the given objectCacheSvc stub (may be nil to disable the block).
func buildStatsReportEngine(t *testing.T, id agent.Identity, oc objectCacheSvc) *gin.Engine {
	t.Helper()
	gin.SetMode(gin.TestMode)
	svc := NewService(&fakeRepo{}, nil, nil, nil)
	h := &AgentHandler{svc: svc, rucss: nil, ocSvc: oc}
	eng := gin.New()
	eng.POST("/agent/v1/cache/stats-report", withIdentity(id, h.statsReport))
	return eng
}

// ---------------------------------------------------------------------------
// TestStatsReportAcceptsFloatOpsPerSec — fix for the live prod 422
//
// Root cause: agentObjectCacheBlock.OpsPerSec was typed int, but the PHP agent
// emits round($ops/$elapsed, 2) which produces a fractional JSON number
// (e.g. 35.25). encoding/json rejects a fractional number for an int target
// and fails the whole Unmarshal, 422-ing the entire stats-report.
//
// After the fix:
//   - OpsPerSec is float64 in agentObjectCacheBlock (accepts 35.25).
//   - ObjectCache is json.RawMessage (sub-block decoded separately, best-effort).
//   - The response is 200 and IngestStats is called with the value.
//
// ---------------------------------------------------------------------------
func TestStatsReportAcceptsFloatOpsPerSec(t *testing.T) {
	siteID, tenantID := uuid.New(), uuid.New()
	id := agent.Identity{SiteID: siteID, TenantID: tenantID}
	oc := &fakeOCSvc{}
	eng := buildStatsReportEngine(t, id, oc)

	// Build a stats-report body with a fractional ops_per_sec inside object_cache.
	// The PHP agent emits this when $elapsed is non-zero and ops/elapsed is fractional.
	body, _ := json.Marshal(map[string]any{
		"cached_pages_count": 42,
		"cache_size_bytes":   1024,
		"cache_hit_count":    10,
		"cache_miss_count":   2,
		"object_cache": map[string]any{
			"state":       "connected",
			"latency_ms":  1.5,
			"hit_count":   800,
			"miss_count":  200,
			"ops_per_sec": 35.25, // fractional — was fatal before this fix
			"avg_wait_ms": 0.8,
		},
	})
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/cache/stats-report", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 for float ops_per_sec, got %d body=%s", rec.Code, rec.Body.String())
	}

	oc.mu.Lock()
	defer oc.mu.Unlock()

	// IngestStats must have been called (hit_count=800, miss_count=200 are non-zero).
	if len(oc.statsInputs) == 0 {
		t.Fatal("expected IngestStats to be called; it was not")
	}
	inp := oc.statsInputs[0]
	// ops_per_sec=35.25 should arrive at IngestStats as 35.25 (float64).
	// The repo will round to 35 before writing to the integer DB column.
	if inp.OpsPerSec < 35.0 || inp.OpsPerSec > 36.0 {
		t.Errorf("OpsPerSec not in expected range [35,36]: got %v", inp.OpsPerSec)
	}
	if inp.HitCount != 800 || inp.MissCount != 200 {
		t.Errorf("hit/miss counts wrong: hit=%d miss=%d", inp.HitCount, inp.MissCount)
	}
}

// ---------------------------------------------------------------------------
// TestStatsReportMalformedObjectCacheBlockStill200
//
// Structural fix: even when the object_cache sub-block cannot be decoded
// (wrong field types, garbage value, any JSON schema mismatch), the handler
// must:
//   - Log a WARNING (not return an error).
//   - Ingest the page-cache stats portion normally.
//   - Return 200 to the agent so it keeps reporting.
//
// This is the same class of bug as the email-log 422 incident (v0.35.3) and
// is explicitly promised by the M68 handler comment.
// ---------------------------------------------------------------------------
func TestStatsReportMalformedObjectCacheBlockStill200(t *testing.T) {
	cases := []struct {
		name        string
		objectCache any
	}{
		{
			name:        "ops_per_sec is a non-numeric string",
			objectCache: map[string]any{"state": "connected", "ops_per_sec": "not-a-number"},
		},
		{
			name:        "object_cache is a bare string not an object",
			objectCache: "garbage",
		},
		{
			name:        "hit_count is a nested object",
			objectCache: map[string]any{"state": "connected", "hit_count": map[string]any{"nested": true}},
		},
	}

	for _, tc := range cases {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			siteID, tenantID := uuid.New(), uuid.New()
			id := agent.Identity{SiteID: siteID, TenantID: tenantID}
			oc := &fakeOCSvc{}
			eng := buildStatsReportEngine(t, id, oc)

			body, _ := json.Marshal(map[string]any{
				"cached_pages_count": 10,
				"cache_size_bytes":   512,
				// Page-cache hit/miss counts — must still be ingested.
				"cache_hit_count":  50,
				"cache_miss_count": 5,
				"object_cache":     tc.objectCache,
			})
			req := httptest.NewRequest(http.MethodPost, "/agent/v1/cache/stats-report", bytes.NewReader(body))
			req.Header.Set("Content-Type", "application/json")
			rec := httptest.NewRecorder()
			eng.ServeHTTP(rec, req)

			if rec.Code != http.StatusOK {
				t.Errorf("%s: expected 200 with malformed object_cache block, got %d body=%s",
					tc.name, rec.Code, rec.Body.String())
			}
			// The page-cache stats path (ReportCacheStats) must have completed.
			// We confirm this indirectly: a 200 response means the handler did not
			// short-circuit on the malformed block. IngestStats must NOT have been
			// called for a block that failed decode.
			oc.mu.Lock()
			defer oc.mu.Unlock()
			if len(oc.statsInputs) > 0 {
				// A failed decode must skip IngestStats; a successful decode with
				// zero hit/miss (the "hit_count is an object" case) also skips it
				// (the service's zero-delta guard). Either way: zero calls expected
				// for malformed blocks.
				//
				// For "ops_per_sec is a non-numeric string" and "object_cache is a
				// bare string", json.Unmarshal fails entirely and the block is skipped.
				// For "hit_count is a nested object", Unmarshal fails on the int64
				// field. All three cases must produce len(statsInputs)==0.
				t.Errorf("%s: IngestStats must not be called for a malformed block; called %d time(s)",
					tc.name, len(oc.statsInputs))
			}
		})
	}
}
