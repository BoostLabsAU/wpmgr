package perf

import (
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
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
