package service

import (
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/repo"
)

// fakeClock is a minimal domain.Clock.
type fakeClock struct{ t time.Time }

func (c *fakeClock) Now() time.Time { c.t = c.t.Add(time.Millisecond); return c.t }

// fakeStore records Put calls and the bytes written.
type fakeStore struct {
	mu      sync.Mutex
	puts    int32
	objects map[string][]byte
}

func newFakeStore() *fakeStore { return &fakeStore{objects: map[string][]byte{}} }

func (s *fakeStore) Put(_ context.Context, key string, body io.Reader, _ int64) error {
	b, _ := io.ReadAll(body)
	s.mu.Lock()
	defer s.mu.Unlock()
	atomic.AddInt32(&s.puts, 1)
	s.objects[key] = b
	return nil
}
func (s *fakeStore) Bucket() string { return "test" }

// fakeRepo is an in-memory Repository.
type fakeRepo struct {
	mu        sync.Mutex
	byHash    map[string]model.Result
	upserts   int32
	touches   int32
	slowCheck func() // optional: called inside GetByHash to widen the race window
}

func newFakeRepo() *fakeRepo { return &fakeRepo{byHash: map[string]model.Result{}} }

func key(siteID uuid.UUID, h string) string { return siteID.String() + "|" + h }

func (r *fakeRepo) GetByHash(_ context.Context, _ uuid.UUID, siteID uuid.UUID, h string) (model.Result, error) {
	if r.slowCheck != nil {
		r.slowCheck()
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if res, ok := r.byHash[key(siteID, h)]; ok {
		return res, nil
	}
	return model.Result{}, repo.ErrNotFound
}

func (r *fakeRepo) Upsert(_ context.Context, in repo.UpsertInput) (model.Result, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	atomic.AddInt32(&r.upserts, 1)
	res := model.Result{
		ID:               uuid.New(),
		TenantID:         in.TenantID,
		SiteID:           in.SiteID,
		StructureHash:    in.StructureHash,
		URL:              in.URL,
		OriginalCSSBytes: in.OriginalCSSBytes,
		UsedCSSBytes:     in.UsedCSSBytes,
		ReductionPct:     in.ReductionPct,
		UsedCSSS3Key:     in.UsedCSSS3Key,
		SelectorsTotal:   in.SelectorsTotal,
		SelectorsKept:    in.SelectorsKept,
		SelectorsDropped: in.SelectorsDropped,
		ComputeMs:        in.ComputeMs,
	}
	r.byHash[key(in.SiteID, in.StructureHash)] = res
	return res, nil
}

func (r *fakeRepo) TouchLastUsed(_ context.Context, _ uuid.UUID, _ uuid.UUID, _ string) error {
	atomic.AddInt32(&r.touches, 1)
	return nil
}

const sampleHTML = `<!DOCTYPE html><html><body><div class="used">x</div></body></html>`
const sampleCSS = `.used{color:red}.unused{color:blue}`

func newTestService(r Repository, s BlobStore) *Service {
	return NewService(r, s, &fakeClock{t: time.Unix(0, 0)}, nil)
}

func TestComputeOrGetCached_Miss_ComputesStoresUpserts(t *testing.T) {
	r := newFakeRepo()
	s := newFakeStore()
	svc := newTestService(r, s)

	tenant, site := uuid.New(), uuid.New()
	out, err := svc.ComputeOrGetCached(context.Background(), ComputeInput{
		TenantID:      tenant,
		SiteID:        site,
		StructureHash: "h1",
		URL:           "https://example.com/",
		HTML:          []byte(sampleHTML),
		CSS:           []byte(sampleCSS),
	})
	if err != nil {
		t.Fatal(err)
	}
	if out.CacheHit {
		t.Errorf("first call must be a miss")
	}
	if out.Result.UsedCSSS3Key == "" {
		t.Errorf("expected an S3 key")
	}
	if atomic.LoadInt32(&s.puts) != 1 {
		t.Errorf("expected exactly 1 store Put, got %d", s.puts)
	}
	if atomic.LoadInt32(&r.upserts) != 1 {
		t.Errorf("expected exactly 1 upsert, got %d", r.upserts)
	}
	// The stored object must be valid gzip containing the purged (used) CSS.
	raw, ok := s.objects[out.Result.UsedCSSS3Key]
	if !ok {
		t.Fatalf("stored object missing for key %q", out.Result.UsedCSSS3Key)
	}
	gr, err := gzip.NewReader(bytes.NewReader(raw))
	if err != nil {
		t.Fatalf("stored object not gzip: %v", err)
	}
	dec, _ := io.ReadAll(gr)
	if !bytes.Contains(dec, []byte(".used")) {
		t.Errorf("used css missing .used: %q", dec)
	}
	if bytes.Contains(dec, []byte("unused")) {
		t.Errorf("used css should have dropped .unused: %q", dec)
	}
}

func TestComputeOrGetCached_Hit_TouchesAndSkipsCompute(t *testing.T) {
	r := newFakeRepo()
	s := newFakeStore()
	svc := newTestService(r, s)
	tenant, site := uuid.New(), uuid.New()
	in := ComputeInput{TenantID: tenant, SiteID: site, StructureHash: "h1", HTML: []byte(sampleHTML), CSS: []byte(sampleCSS)}

	if _, err := svc.ComputeOrGetCached(context.Background(), in); err != nil {
		t.Fatal(err)
	}
	// Second call: cache hit.
	out, err := svc.ComputeOrGetCached(context.Background(), in)
	if err != nil {
		t.Fatal(err)
	}
	if !out.CacheHit {
		t.Errorf("second call must be a cache hit")
	}
	if atomic.LoadInt32(&s.puts) != 1 {
		t.Errorf("hit must NOT re-store; puts=%d", s.puts)
	}
	if atomic.LoadInt32(&r.upserts) != 1 {
		t.Errorf("hit must NOT re-upsert; upserts=%d", r.upserts)
	}
	if atomic.LoadInt32(&r.touches) < 1 {
		t.Errorf("hit must touch last_used")
	}
}

// Concurrent identical requests must collapse to ONE computation.
func TestComputeOrGetCached_DedupsConcurrent(t *testing.T) {
	r := newFakeRepo()
	// Widen the miss->upsert window so all goroutines reach singleflight together.
	var gate sync.WaitGroup
	gate.Add(1)
	once := sync.Once{}
	r.slowCheck = func() {
		once.Do(func() { time.Sleep(20 * time.Millisecond) })
	}
	s := newFakeStore()
	svc := newTestService(r, s)
	tenant, site := uuid.New(), uuid.New()
	in := ComputeInput{TenantID: tenant, SiteID: site, StructureHash: "h1", HTML: []byte(sampleHTML), CSS: []byte(sampleCSS)}

	const n = 16
	var wg sync.WaitGroup
	wg.Add(n)
	gate.Done()
	for i := 0; i < n; i++ {
		go func() {
			defer wg.Done()
			if _, err := svc.ComputeOrGetCached(context.Background(), in); err != nil {
				t.Errorf("concurrent compute err: %v", err)
			}
		}()
	}
	wg.Wait()

	// Singleflight + the in-critical-section re-check must keep upserts/puts at 1.
	if got := atomic.LoadInt32(&r.upserts); got != 1 {
		t.Errorf("expected exactly 1 upsert under concurrency, got %d", got)
	}
	if got := atomic.LoadInt32(&s.puts); got != 1 {
		t.Errorf("expected exactly 1 store Put under concurrency, got %d", got)
	}
}

func TestComputeOrGetCached_StoreUnwired(t *testing.T) {
	svc := NewService(newFakeRepo(), nil, &fakeClock{t: time.Unix(0, 0)}, nil)
	_, err := svc.ComputeOrGetCached(context.Background(), ComputeInput{
		TenantID: uuid.New(), SiteID: uuid.New(), StructureHash: "h1",
		HTML: []byte(sampleHTML), CSS: []byte(sampleCSS),
	})
	if err == nil {
		t.Fatal("expected error when store is unwired")
	}
}

func TestComputeOrGetCached_MissingHash(t *testing.T) {
	svc := newTestService(newFakeRepo(), newFakeStore())
	_, err := svc.ComputeOrGetCached(context.Background(), ComputeInput{
		TenantID: uuid.New(), SiteID: uuid.New(), StructureHash: "",
	})
	if err == nil {
		t.Fatal("expected validation error for missing structure_hash")
	}
}
