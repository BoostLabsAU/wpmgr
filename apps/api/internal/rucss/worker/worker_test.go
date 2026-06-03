package worker

import (
	"context"
	"errors"
	"io"
	"sync"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/blobstore"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/repo"
	"github.com/mosamlife/wpmgr/apps/api/internal/rucss/service"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// Compile-time assertions that the production types satisfy the worker's seams.
var (
	_ JobLifecycle      = (*repo.Repo)(nil)
	_ service.BlobStore = (*blobstore.Store)(nil)
	_ EventPublisher    = (site.EventPublisher)(nil)
)

type fakeFetcher struct {
	src Source
	err error
}

func (f *fakeFetcher) Fetch(context.Context, RucssArgs) (Source, error) { return f.src, f.err }

// fakeFetchDeleter is a fetcher that ALSO records DeleteSource calls, so the
// worker test can assert the temp source bundle is reaped after processing.
type fakeFetchDeleter struct {
	src       Source
	err       error
	mu        sync.Mutex
	deleted   []string
	deleteErr error
	deleteCnt int
}

func (f *fakeFetchDeleter) Fetch(context.Context, RucssArgs) (Source, error) { return f.src, f.err }

func (f *fakeFetchDeleter) DeleteSource(_ context.Context, a RucssArgs) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.deleteCnt++
	if f.deleteErr != nil {
		return f.deleteErr
	}
	f.deleted = append(f.deleted, a.SourceKey)
	return nil
}

func (f *fakeFetchDeleter) deletedKeys() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := make([]string, len(f.deleted))
	copy(out, f.deleted)
	return out
}

type fakeJobs struct {
	mu      sync.Mutex
	running int
	done    int
	failed  int
	lastErr string
}

func (j *fakeJobs) MarkRunning(context.Context, uuid.UUID, string) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.running++
	return nil
}
func (j *fakeJobs) MarkDone(context.Context, uuid.UUID, string, uuid.UUID) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.done++
	return nil
}
func (j *fakeJobs) MarkFailed(_ context.Context, _ uuid.UUID, _ string, reason string) error {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.failed++
	j.lastErr = reason
	return nil
}

type fakeEvents struct {
	mu     sync.Mutex
	events []site.ConnectionEvent
}

func (e *fakeEvents) Publish(_ context.Context, ev site.ConnectionEvent) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.events = append(e.events, ev)
	return nil
}

// ---- minimal service-layer fakes so the worker test can build a real Service ----

type svcRepo struct {
	mu     sync.Mutex
	byHash map[string]model.Result
}

func (r *svcRepo) GetByHash(_ context.Context, _ uuid.UUID, siteID uuid.UUID, h string) (model.Result, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if res, ok := r.byHash[siteID.String()+"|"+h]; ok {
		return res, nil
	}
	return model.Result{}, repo.ErrNotFound
}

func (r *svcRepo) Upsert(_ context.Context, in repo.UpsertInput) (model.Result, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	res := model.Result{
		ID:            uuid.New(),
		TenantID:      in.TenantID,
		SiteID:        in.SiteID,
		StructureHash: in.StructureHash,
		UsedCSSS3Key:  in.UsedCSSS3Key,
		ReductionPct:  in.ReductionPct,
		UsedCSSBytes:  in.UsedCSSBytes,
	}
	r.byHash[in.SiteID.String()+"|"+in.StructureHash] = res
	return res, nil
}

func (r *svcRepo) TouchLastUsed(context.Context, uuid.UUID, uuid.UUID, string) error { return nil }

type svcStore struct{}

func (svcStore) Put(_ context.Context, _ string, body io.Reader, _ int64) error {
	_, _ = io.Copy(io.Discard, body)
	return nil
}
func (svcStore) Bucket() string { return "test" }

type clk struct{}

func (clk) Now() time.Time { return time.Unix(0, 0) }

func newSvc(t *testing.T) *service.Service {
	t.Helper()
	return service.NewService(&svcRepo{byHash: map[string]model.Result{}}, svcStore{}, clk{}, nil)
}

func TestWorker_Success(t *testing.T) {
	jobs := &fakeJobs{}
	events := &fakeEvents{}
	fetch := &fakeFetcher{src: Source{
		HTML: []byte(`<div class="used">x</div>`),
		CSS:  []byte(`.used{color:red}.unused{color:blue}`),
	}}
	w := NewWorker(newSvc(t), fetch, jobs, events, nil)

	args := RucssArgs{TenantID: uuid.New(), SiteID: uuid.New(), JobID: "job-1", StructureHash: "h1"}
	err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: args})
	if err != nil {
		t.Fatalf("Work returned err: %v", err)
	}
	if jobs.running != 1 || jobs.done != 1 || jobs.failed != 0 {
		t.Errorf("lifecycle wrong: running=%d done=%d failed=%d", jobs.running, jobs.done, jobs.failed)
	}
	// markRunning publishes rucss.computing, then success publishes rucss.completed.
	if len(events.events) != 2 || events.events[0].Type != site.EventRucssComputing || events.events[1].Type != site.EventRucssCompleted {
		t.Errorf("expected rucss.computing then rucss.completed, got %+v", events.events)
	}
}

func TestWorker_SourceFetcherUnwired_TerminalFail(t *testing.T) {
	jobs := &fakeJobs{}
	events := &fakeEvents{}
	w := NewWorker(newSvc(t), nil /* no fetcher */, jobs, events, nil)
	err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: RucssArgs{JobID: "j", TenantID: uuid.New(), SiteID: uuid.New(), StructureHash: "h"}})
	if err != nil {
		t.Fatalf("unwired fetcher must be terminal (nil err), got %v", err)
	}
	if jobs.failed != 1 {
		t.Errorf("expected a recorded failure, got failed=%d", jobs.failed)
	}
	// markRunning publishes rucss.computing first, then the terminal rucss.failed.
	if len(events.events) == 0 || events.events[len(events.events)-1].Type != site.EventRucssFailed {
		t.Errorf("expected rucss.failed as the terminal event, got %+v", events.events)
	}
}

func TestWorker_FetchError_Retryable(t *testing.T) {
	jobs := &fakeJobs{}
	w := NewWorker(newSvc(t), &fakeFetcher{err: errors.New("s3 blip")}, jobs, &fakeEvents{}, nil)
	err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: RucssArgs{JobID: "j", TenantID: uuid.New(), SiteID: uuid.New(), StructureHash: "h"}})
	if err == nil {
		t.Fatal("a transient fetch error must be returned so River retries")
	}
	// The job stayed running (not terminal-failed) so a retry can complete it.
	if jobs.failed != 0 {
		t.Errorf("transient fetch error must NOT record a terminal failure")
	}
}

// FIX 1: the temp HTML+CSS source bundle MUST be deleted after a successful
// computation (the page HTML must not linger in object storage).
func TestWorker_DeletesSourceBundle_OnSuccess(t *testing.T) {
	jobs := &fakeJobs{}
	fd := &fakeFetchDeleter{src: Source{
		HTML: []byte(`<div class="used">x</div>`),
		CSS:  []byte(`.used{color:red}.unused{color:blue}`),
	}}
	w := NewWorker(newSvc(t), fd, jobs, &fakeEvents{}, nil)

	args := RucssArgs{TenantID: uuid.New(), SiteID: uuid.New(), JobID: "job-1", StructureHash: "h1", SourceKey: "rucss-src/t/s/job-1.bin"}
	if err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: args}); err != nil {
		t.Fatalf("Work returned err: %v", err)
	}
	got := fd.deletedKeys()
	if len(got) != 1 || got[0] != args.SourceKey {
		t.Fatalf("expected source bundle %q deleted exactly once, got %v", args.SourceKey, got)
	}
}

// FIX 1: a transient (retryable) fetch error must NOT delete the bundle — River
// will retry and must be able to re-fetch it.
func TestWorker_NoDeleteOnTransientFetchError(t *testing.T) {
	jobs := &fakeJobs{}
	fd := &fakeFetchDeleter{err: errors.New("s3 blip")}
	w := NewWorker(newSvc(t), fd, jobs, &fakeEvents{}, nil)

	args := RucssArgs{TenantID: uuid.New(), SiteID: uuid.New(), JobID: "j", StructureHash: "h", SourceKey: "rucss-src/t/s/j.bin"}
	err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: args})
	if err == nil {
		t.Fatal("a transient fetch error must be returned so River retries")
	}
	// The bundle MUST NOT be deleted: a retry needs to re-fetch it.
	if got := fd.deletedKeys(); len(got) != 0 {
		t.Fatalf("transient fetch error must NOT delete the source bundle, deleted=%v", got)
	}
}

// FIX 1: a delete failure must not fail the job (best-effort; backstop sweeper
// is the net).
func TestWorker_DeleteFailure_NonFatal(t *testing.T) {
	jobs := &fakeJobs{}
	fd := &fakeFetchDeleter{
		src:       Source{HTML: []byte(`<a class="x"/>`), CSS: []byte(`.x{color:red}`)},
		deleteErr: errors.New("delete blew up"),
	}
	w := NewWorker(newSvc(t), fd, jobs, &fakeEvents{}, nil)
	args := RucssArgs{TenantID: uuid.New(), SiteID: uuid.New(), JobID: "j", StructureHash: "h", SourceKey: "rucss-src/t/s/j.bin"}
	if err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: args}); err != nil {
		t.Fatalf("a delete failure must not fail the job, got %v", err)
	}
	if jobs.done != 1 {
		t.Fatalf("expected the job to still complete, done=%d", jobs.done)
	}
	if fd.deleteCnt != 1 {
		t.Fatalf("expected exactly one delete attempt, got %d", fd.deleteCnt)
	}
}

func TestWorker_NilDeps_NoPanic(t *testing.T) {
	w := NewWorker(newSvc(t), &fakeFetcher{src: Source{HTML: []byte(`<a class="x"/>`), CSS: []byte(`.x{color:red}`)}}, nil, nil, nil)
	// jobs and events nil — must not panic.
	if err := w.Work(context.Background(), &river.Job[RucssArgs]{Args: RucssArgs{JobID: "j", TenantID: uuid.New(), SiteID: uuid.New(), StructureHash: "h"}}); err != nil {
		t.Fatalf("nil deps should still succeed: %v", err)
	}
}

func TestArgs_KindAndQueue(t *testing.T) {
	if (RucssArgs{}).Kind() != "rucss_process" {
		t.Errorf("kind mismatch")
	}
	if (RucssArgs{}).InsertOpts().Queue != RucssQueue {
		t.Errorf("queue mismatch")
	}
	if _, ok := Queues(0)[RucssQueue]; !ok {
		t.Errorf("Queues must include the rucss queue")
	}
	if Queues(0)[RucssQueue].MaxWorkers <= 0 {
		t.Errorf("MaxWorkers must be >= 1 (River rejects 0)")
	}
}

func TestRegisterWorker_NilSafe(t *testing.T) {
	workers := river.NewWorkers()
	RegisterWorker(workers, nil) // must not panic
	RegisterWorker(workers, NewWorker(newSvc(t), &fakeFetcher{}, nil, nil, nil))
}

func TestWorkerTimeout(t *testing.T) {
	w := NewWorker(newSvc(t), &fakeFetcher{}, nil, nil, nil)
	if w.Timeout(nil) != RucssTimeout {
		t.Errorf("timeout should default to RucssTimeout")
	}
	_ = time.Second
}

// ---- FIX 1 backstop sweeper ----

type fakeReaper struct {
	objs    []blobstore.ObjectInfo
	deleted []string
}

func (r *fakeReaper) ListWithModified(_ context.Context, prefix string) ([]blobstore.ObjectInfo, error) {
	if prefix != RucssSourcePrefix {
		return nil, errors.New("unexpected prefix " + prefix)
	}
	return r.objs, nil
}
func (r *fakeReaper) Delete(_ context.Context, key string) error {
	r.deleted = append(r.deleted, key)
	return nil
}

func TestRucssSweepWorker_ReapsOnlyStale(t *testing.T) {
	now := time.Unix(1_000_000, 0)
	reaper := &fakeReaper{objs: []blobstore.ObjectInfo{
		{Key: "rucss-src/t/s/old.bin", LastModified: now.Add(-90 * time.Second)},  // stale → delete
		{Key: "rucss-src/t/s/fresh.bin", LastModified: now.Add(-5 * time.Second)}, // fresh → keep
		{Key: "rucss-src/t/s/zero.bin"},                                           // zero time → delete (never retain)
	}}
	w := NewRucssSweepWorker(reaper, RucssSweepMaxAge, nil)
	w.now = func() time.Time { return now }

	if err := w.Work(context.Background(), &river.Job[RucssSweepArgs]{}); err != nil {
		t.Fatalf("sweep Work returned err: %v", err)
	}
	want := map[string]bool{"rucss-src/t/s/old.bin": true, "rucss-src/t/s/zero.bin": true}
	if len(reaper.deleted) != len(want) {
		t.Fatalf("expected %d deletes, got %v", len(want), reaper.deleted)
	}
	for _, k := range reaper.deleted {
		if !want[k] {
			t.Fatalf("deleted a key that should have been kept: %q (deleted=%v)", k, reaper.deleted)
		}
	}
}

func TestRucssSweepWorker_NilReaper_NoOp(t *testing.T) {
	w := NewRucssSweepWorker(nil, 0, nil)
	if err := w.Work(context.Background(), &river.Job[RucssSweepArgs]{}); err != nil {
		t.Fatalf("nil reaper must be a no-op, got %v", err)
	}
	if (RucssSweepArgs{}).Kind() != "rucss_source_sweep" {
		t.Errorf("sweep kind mismatch")
	}
}

// Compile-time assertion that *blobstore.Store satisfies the reaper surface.
var _ BundleReaper = (*blobstore.Store)(nil)
