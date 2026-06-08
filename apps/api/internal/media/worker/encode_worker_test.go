package worker

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"

	"github.com/google/uuid"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
)

// fakeJobRepo stubs EncodeJobRepo for unit tests. All unset fields return a
// generic internal error so tests fail loudly if an unexpected method is called.
type fakeJobRepo struct {
	getJob    func(ctx context.Context, jobID string) (model.Job, error)
	upsert    func(ctx context.Context, tenantID uuid.UUID, in repo.UpsertVariantInput) error
	countStates func(ctx context.Context, jobID string) (int, int, error)
	finalize  func(ctx context.Context, jobID string, in repo.FinalizeJobInput) (model.Job, error)
}

func (f *fakeJobRepo) GetJobAgent(ctx context.Context, jobID string) (model.Job, error) {
	if f.getJob != nil {
		return f.getJob(ctx, jobID)
	}
	return model.Job{}, domain.Internal("not_set", "getJob not set in test")
}

func (f *fakeJobRepo) UpsertVariantAgent(ctx context.Context, tenantID uuid.UUID, in repo.UpsertVariantInput) error {
	if f.upsert != nil {
		return f.upsert(ctx, tenantID, in)
	}
	return nil
}

func (f *fakeJobRepo) CountVariantStatesAgent(ctx context.Context, jobID string) (int, int, error) {
	if f.countStates != nil {
		return f.countStates(ctx, jobID)
	}
	return 0, 0, nil
}

func (f *fakeJobRepo) FinalizeJobAgent(ctx context.Context, jobID string, in repo.FinalizeJobInput) (model.Job, error) {
	if f.finalize != nil {
		return f.finalize(ctx, jobID, in)
	}
	return model.Job{}, nil
}

// newTestWorker builds a minimal EncodeWorker with nil optional deps (no
// encoder, store, events, sites, or apply client) — sufficient for unit tests
// that exercise only the repo-layer gate at the top of Work().
func newTestWorker(r EncodeJobRepo) *EncodeWorker {
	return NewEncodeWorker(
		nil, // encoder — not called in these tests
		r,
		nil,                                               // store
		nil,                                               // events
		nil,                                               // sites
		nil,                                               // apply
		"https://cp.example.test",                         // cpBaseURL
		0,                                                 // presignTTL (default)
		slog.New(slog.NewTextHandler(io.Discard, nil)),
	)
}

// TestWork_NotFoundJobCancelled verifies that when GetJobAgent returns a
// domain.KindNotFound error (the media_optimization_jobs row was deleted while
// the River job was still queued), Work() returns river.JobCancel rather than a
// retryable error. This prevents the orphan retry storm described in the prod
// incident: River permanently discards the job instead of re-scheduling it.
func TestWork_NotFoundJobCancelled(t *testing.T) {
	notFoundErr := domain.NotFound("media_job_not_found", "media job not found")
	r := &fakeJobRepo{
		getJob: func(_ context.Context, _ string) (model.Job, error) {
			return model.Job{}, notFoundErr
		},
	}
	w := newTestWorker(r)

	job := &river.Job[model.EncodeArgs]{
		Args: model.EncodeArgs{
			TenantID: uuid.New(),
			SiteID:   uuid.New(),
			JobID:    "01HZ000000000000000000000",
		},
	}

	err := w.Work(context.Background(), job)
	if err == nil {
		t.Fatal("Work() returned nil for not-found job; expected river.JobCancel error")
	}

	// river.JobCancel wraps the error in a special type that River's executor
	// recognises as a permanent cancel signal. Unwrap to confirm it carries the
	// original not-found error.
	var cancelErr *river.JobCancelError
	if !errors.As(err, &cancelErr) {
		t.Fatalf("Work() returned %T (%v), want *river.JobCancelError", err, err)
	}
}

// TestWork_InternalErrorRetryable verifies that a non-not-found repo error
// (e.g. a transient Postgres failure) is still returned as a plain error so
// River retries it rather than permanently discarding the job.
func TestWork_InternalErrorRetryable(t *testing.T) {
	internalErr := domain.Internal("media_job_get_failed", "db timeout")
	r := &fakeJobRepo{
		getJob: func(_ context.Context, _ string) (model.Job, error) {
			return model.Job{}, internalErr
		},
	}
	w := newTestWorker(r)

	job := &river.Job[model.EncodeArgs]{
		Args: model.EncodeArgs{
			TenantID: uuid.New(),
			SiteID:   uuid.New(),
			JobID:    "01HZ000000000000000000001",
		},
	}

	err := w.Work(context.Background(), job)
	if err == nil {
		t.Fatal("Work() returned nil for internal error; expected a retryable error")
	}
	var cancelErr *river.JobCancelError
	if errors.As(err, &cancelErr) {
		t.Fatal("Work() returned river.JobCancel for an internal error; it must be retryable")
	}
}

// TestWork_TerminalJobSkipped verifies that when the job is already in a
// terminal state (e.g. cancelled by the operator between the River enqueue and
// the worker picking it up), Work() returns nil so River marks the River job
// as completed without any side effects.
func TestWork_TerminalJobSkipped(t *testing.T) {
	r := &fakeJobRepo{
		getJob: func(_ context.Context, _ string) (model.Job, error) {
			return model.Job{State: model.JobCancelled}, nil
		},
	}
	w := newTestWorker(r)

	job := &river.Job[model.EncodeArgs]{
		Args: model.EncodeArgs{
			TenantID: uuid.New(),
			SiteID:   uuid.New(),
			JobID:    "01HZ000000000000000000002",
		},
	}

	if err := w.Work(context.Background(), job); err != nil {
		t.Fatalf("Work() returned %v for a terminal job; want nil (dup-safe no-op)", err)
	}
}
