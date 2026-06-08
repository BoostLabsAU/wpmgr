package service

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/model"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/repo"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// ---------------------------------------------------------------------------
// fakes (pure Go — no Postgres, no encoder; this whole test file builds under
// CGO_ENABLED=0)
// ---------------------------------------------------------------------------

type fakeRepo struct {
	assets   map[uuid.UUID]model.Asset
	jobs     map[string]model.Job
	variants map[string][]model.VariantResult
	pending  []model.Asset

	insertedJobs   []repo.InsertJobInput
	enqueuedStatus map[string]model.JobState
}

func newFakeRepo() *fakeRepo {
	return &fakeRepo{
		assets:         map[uuid.UUID]model.Asset{},
		jobs:           map[string]model.Job{},
		variants:       map[string][]model.VariantResult{},
		enqueuedStatus: map[string]model.JobState{},
	}
}

func (r *fakeRepo) UpsertAssetsAgent(_ context.Context, tenantID, siteID uuid.UUID, syncGen int64, rows []repo.UpsertAssetInput) (int64, error) {
	for _, row := range rows {
		// Check if an asset with this wp_attachment_id already exists.
		found := false
		for id, a := range r.assets {
			if a.WPAttachmentID == row.WPAttachmentID {
				// Refresh library metadata, preserve optimization status.
				a.Title = row.Title
				a.OriginalPath = row.OriginalPath
				a.OriginalURL = row.OriginalURL
				a.OriginalMime = row.OriginalMime
				a.OriginalWidth = row.OriginalWidth
				a.OriginalHeight = row.OriginalHeight
				if row.OriginalSizeBytes > a.OriginalSizeBytes {
					a.OriginalSizeBytes = row.OriginalSizeBytes
				}
				a.SyncGeneration = syncGen
				r.assets[id] = a
				found = true
				break
			}
		}
		if !found {
			// Insert new asset row in pending status.
			id := uuid.New()
			r.assets[id] = model.Asset{
				ID:                id,
				TenantID:          tenantID,
				SiteID:            siteID,
				WPAttachmentID:    row.WPAttachmentID,
				Title:             row.Title,
				OriginalPath:      row.OriginalPath,
				OriginalURL:       row.OriginalURL,
				OriginalMime:      row.OriginalMime,
				OriginalWidth:     row.OriginalWidth,
				OriginalHeight:    row.OriginalHeight,
				OriginalSizeBytes: row.OriginalSizeBytes,
				CurrentFormat:     "original",
				CurrentSizeBytes:  row.OriginalSizeBytes,
				Status:            model.AssetPending,
				SyncGeneration:    syncGen,
			}
		}
	}
	return int64(len(rows)), nil
}
func (r *fakeRepo) DeleteAssetAgent(_ context.Context, _, _ uuid.UUID, wpID int64) (int64, error) {
	var deleted int64
	for id, a := range r.assets {
		if a.WPAttachmentID == wpID {
			delete(r.assets, id)
			deleted++
		}
	}
	return deleted, nil
}
func (r *fakeRepo) SweepStaleAssetsAgent(_ context.Context, _, _ uuid.UUID, gen int64) (int64, error) {
	var swept int64
	for id, a := range r.assets {
		if a.SyncGeneration < gen {
			delete(r.assets, id)
			swept++
		}
	}
	return swept, nil
}
func (r *fakeRepo) ListAssets(_ context.Context, _ repo.ListAssetsInput) ([]model.Asset, string, error) {
	out := make([]model.Asset, 0, len(r.assets))
	for _, a := range r.assets {
		out = append(out, a)
	}
	return out, "", nil
}
func (r *fakeRepo) GetAsset(_ context.Context, _, assetID uuid.UUID) (model.Asset, error) {
	a, ok := r.assets[assetID]
	if !ok {
		return model.Asset{}, domain.NotFound("media_asset_not_found", "not found")
	}
	return a, nil
}
func (r *fakeRepo) ListPendingAssetIDs(_ context.Context, _, _ uuid.UUID, _ int) ([]model.Asset, error) {
	return r.pending, nil
}
func (r *fakeRepo) SetAssetStatus(_ context.Context, _, assetID uuid.UUID, status model.AssetStatus) error {
	a := r.assets[assetID]
	a.Status = status
	r.assets[assetID] = a
	return nil
}
func (r *fakeRepo) ApplyOptimizedAgent(_ context.Context, _, _ uuid.UUID, wpID int64, in repo.ApplyOptimizedInput) (model.Asset, error) {
	for id, a := range r.assets {
		if a.WPAttachmentID == wpID {
			a.Status = in.Status
			a.CurrentFormat = in.CurrentFormat
			a.CurrentSizeBytes = in.CurrentSizeBytes
			a.Generation++
			r.assets[id] = a
			return a, nil
		}
	}
	return model.Asset{}, domain.NotFound("media_asset_not_found", "not found")
}
func (r *fakeRepo) RestoreAssetAgent(_ context.Context, _, _ uuid.UUID, wpID int64) (model.Asset, error) {
	for id, a := range r.assets {
		if a.WPAttachmentID == wpID {
			a.Status = model.AssetRestored
			r.assets[id] = a
			return a, nil
		}
	}
	return model.Asset{}, domain.NotFound("media_asset_not_found", "not found")
}
func (r *fakeRepo) Summary(_ context.Context, _, _ uuid.UUID) (model.AssetSummary, error) {
	return model.AssetSummary{Total: int64(len(r.assets))}, nil
}
func (r *fakeRepo) InsertJob(_ context.Context, tenantID uuid.UUID, in repo.InsertJobInput) (model.Job, error) {
	r.insertedJobs = append(r.insertedJobs, in)
	j := model.Job{
		ID: in.ID, TenantID: tenantID, SiteID: in.SiteID, AssetID: in.AssetID,
		WPAttachmentID: in.WPAttachmentID, Kind: in.Kind, TargetFormat: in.TargetFormat,
		TargetQuality: in.TargetQuality, State: model.JobQueued, CreatedAt: time.Now(),
	}
	r.jobs[in.ID] = j
	return j, nil
}
func (r *fakeRepo) GetJob(_ context.Context, _ uuid.UUID, jobID string) (model.Job, error) {
	j, ok := r.jobs[jobID]
	if !ok {
		return model.Job{}, domain.NotFound("media_job_not_found", "not found")
	}
	return j, nil
}
func (r *fakeRepo) GetJobAgent(_ context.Context, jobID string) (model.Job, error) {
	j, ok := r.jobs[jobID]
	if !ok {
		return model.Job{}, domain.NotFound("media_job_not_found", "not found")
	}
	return j, nil
}
func (r *fakeRepo) ListJobs(_ context.Context, _ repo.ListJobsInput) ([]model.Job, string, error) {
	out := make([]model.Job, 0, len(r.jobs))
	for _, j := range r.jobs {
		out = append(out, j)
	}
	return out, "", nil
}
func (r *fakeRepo) MarkJobInProgressAgent(_ context.Context, jobID string, total int) error {
	j := r.jobs[jobID]
	j.State = model.JobInProgress
	j.VariantsTotal = total
	r.jobs[jobID] = j
	return nil
}
func (r *fakeRepo) FinalizeJobAgent(_ context.Context, jobID string, in repo.FinalizeJobInput) (model.Job, error) {
	j := r.jobs[jobID]
	if !j.State.Terminal() {
		j.State = in.State
		j.VariantsSucceeded = in.VariantsSucceeded
		j.VariantsFailed = in.VariantsFailed
		r.jobs[jobID] = j
	}
	r.enqueuedStatus[jobID] = j.State
	return j, nil
}
func (r *fakeRepo) CancelJobs(_ context.Context, _, _ uuid.UUID) (repo.CancelJobsResult, error) {
	var res repo.CancelJobsResult
	for id, j := range r.jobs {
		if j.State == model.JobQueued || j.State == model.JobInProgress {
			j.State = model.JobCancelled
			r.jobs[id] = j
			res.CancelledCount++
			if j.EncodeRiverJobID != nil {
				res.EncodeRiverIDs = append(res.EncodeRiverIDs, *j.EncodeRiverJobID)
			}
		}
	}
	return res, nil
}

func (r *fakeRepo) SetEncodeRiverJobID(_ context.Context, jobID string, riverJobID int64) error {
	j := r.jobs[jobID]
	j.EncodeRiverJobID = &riverJobID
	r.jobs[jobID] = j
	return nil
}
func (r *fakeRepo) UpsertVariantAgent(_ context.Context, _ uuid.UUID, in repo.UpsertVariantInput) error {
	r.variants[in.JobID] = append(r.variants[in.JobID], model.VariantResult{
		JobID: in.JobID, VariantName: in.VariantName, State: in.State,
	})
	return nil
}
func (r *fakeRepo) ListVariantsForJob(_ context.Context, _ uuid.UUID, jobID string) ([]model.VariantResult, error) {
	return r.variants[jobID], nil
}
func (r *fakeRepo) CountVariantStatesAgent(_ context.Context, jobID string) (int, int, error) {
	var s, f int
	for _, v := range r.variants[jobID] {
		switch v.State {
		case model.VariantSucceeded:
			s++
		case model.VariantFailed:
			f++
		}
	}
	return s, f, nil
}
func (r *fakeRepo) GetAssetByWPIDAgent(_ context.Context, _, _ uuid.UUID, wpID int64) (model.Asset, bool, error) {
	for _, a := range r.assets {
		if a.WPAttachmentID == wpID {
			return a, true, nil
		}
	}
	return model.Asset{}, false, nil
}
func (r *fakeRepo) HasInFlightOptimizeJobAgent(_ context.Context, _, _ uuid.UUID, _ int64) (bool, error) {
	return false, nil
}
func (r *fakeRepo) GetMediaSettings(_ context.Context, _, _ uuid.UUID) (model.MediaSettings, bool, error) {
	return model.MediaSettings{}, false, nil
}
func (r *fakeRepo) GetMediaSettingsAgent(_ context.Context, _, _ uuid.UUID) (model.MediaSettings, bool, error) {
	return model.MediaSettings{AutoOptimizeEnabled: true, AutoTargetFormat: "webp", AutoTargetQuality: "lossy"}, true, nil
}
func (r *fakeRepo) UpsertMediaSettings(_ context.Context, tenantID, siteID uuid.UUID, in repo.UpsertMediaSettingsInput) (model.MediaSettings, error) {
	return model.MediaSettings{
		TenantID:            tenantID,
		SiteID:              siteID,
		AutoOptimizeEnabled: in.AutoOptimizeEnabled,
		AutoTargetFormat:    in.AutoTargetFormat,
		AutoTargetQuality:   in.AutoTargetQuality,
	}, nil
}

type fakeEnqueuer struct {
	enqueued    []model.EncodeArgs
	cancelled   []int64
	nextRiverID int64 // incremented on each EnqueueEncode; starts at 1
}

func (e *fakeEnqueuer) EnqueueEncode(_ context.Context, args model.EncodeArgs) (int64, error) {
	e.nextRiverID++
	e.enqueued = append(e.enqueued, args)
	return e.nextRiverID, nil
}

func (e *fakeEnqueuer) CancelEncodeJob(_ context.Context, riverJobID int64) error {
	e.cancelled = append(e.cancelled, riverJobID)
	return nil
}

type fakeAgent struct {
	optimizeCalls int
	restoreCalls  int
	deleteCalls   int
	syncCalls     int
	// optimized is signalled once per MediaOptimize dispatch. StartOptimize fires
	// the dispatch in a DETACHED goroutine, so tests MUST await this signal before
	// reading optimizeCalls — reading it directly after StartOptimize returns both
	// races the goroutine AND data-races the counter write.
	optimized chan struct{}
}

func (a *fakeAgent) MediaOptimize(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaOptimizeRequest) (agentcmd.MediaOptimizeResponse, error) {
	a.optimizeCalls++
	if a.optimized != nil {
		a.optimized <- struct{}{}
	}
	return agentcmd.MediaOptimizeResponse{OK: true}, nil
}

// waitOptimize blocks until the detached MediaOptimize dispatch has run (or the
// test deadline elapses), so the caller can read optimizeCalls race-free.
func (a *fakeAgent) waitOptimize(t *testing.T) {
	t.Helper()
	select {
	case <-a.optimized:
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for detached media_optimize dispatch")
	}
}
func (a *fakeAgent) MediaSync(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaSyncRequest) (agentcmd.MediaSyncResponse, error) {
	a.syncCalls++
	return agentcmd.MediaSyncResponse{OK: true}, nil
}
func (a *fakeAgent) MediaRestore(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaRestoreRequest) (agentcmd.MediaRestoreResponse, error) {
	a.restoreCalls++
	return agentcmd.MediaRestoreResponse{OK: true}, nil
}
func (a *fakeAgent) MediaDeleteOriginals(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaDeleteOriginalsRequest) (agentcmd.MediaDeleteOriginalsResponse, error) {
	a.deleteCalls++
	return agentcmd.MediaDeleteOriginalsResponse{OK: true}, nil
}
func (a *fakeAgent) SyncMediaConfig(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaConfigRequest) (agentcmd.MediaConfigResult, error) {
	return agentcmd.MediaConfigResult{OK: true, Detail: "applied"}, nil
}

type fakeSites struct{ enrolled bool }

func (s fakeSites) GetMediaSiteInfo(_ context.Context, _, _ uuid.UUID) (MediaSiteInfo, error) {
	return MediaSiteInfo{URL: "https://site.example", Enrolled: s.enrolled}, nil
}

type fakeStore struct{ deleted, listed int }

func (f *fakeStore) PresignPut(_ context.Context, key string, _ time.Duration) (string, error) {
	return "https://put/" + key, nil
}
func (f *fakeStore) PresignGet(_ context.Context, key string, _ time.Duration) (string, error) {
	return "https://get/" + key, nil
}
func (f *fakeStore) Delete(_ context.Context, _ string) error { f.deleted++; return nil }
func (f *fakeStore) List(_ context.Context, _ string) ([]string, error) {
	f.listed++
	return []string{"media/k1", "media/k2"}, nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

func newTestService(r Repo, store Presigner, enq EncodeEnqueuer, cmd AgentMediaClient, sites SiteLookup) *Service {
	s := NewService(r, store, nil, nil, domain.SystemClock{}, Config{CPBaseURL: "https://cp.example"}, nil)
	s.SetEnqueuer(enq)
	s.SetAgentClient(cmd, sites)
	return s
}

// fakeEvents records every published envelope so tests can assert which SSE
// events fired (and which were suppressed — e.g. media.job.failed on a job the
// agent already finalized as succeeded).
type fakeEvents struct{ events []site.ConnectionEvent }

func (e *fakeEvents) Publish(_ context.Context, ev site.ConnectionEvent) error {
	e.events = append(e.events, ev)
	return nil
}

func (e *fakeEvents) count(eventType string) int {
	n := 0
	for _, ev := range e.events {
		if ev.Type == eventType {
			n++
		}
	}
	return n
}

func newTestServiceWithEvents(r Repo, ev EventPublisher) *Service {
	s := NewService(r, &fakeStore{}, ev, nil, domain.SystemClock{}, Config{CPBaseURL: "https://cp.example"}, nil)
	s.SetEnqueuer(&fakeEnqueuer{})
	s.SetAgentClient(&fakeAgent{}, fakeSites{enrolled: true})
	return s
}

func userPrincipal(tenantID uuid.UUID) domain.Principal {
	return domain.Principal{Type: domain.PrincipalUser, UserID: uuid.New(), TenantID: tenantID, Role: "admin"}
}

// ---------------------------------------------------------------------------
// tests
// ---------------------------------------------------------------------------

func TestStartOptimize_FansOutOneJobPerAsset(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	a1 := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 1, OriginalMime: "image/jpeg", Status: model.AssetPending}
	a2 := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 2, OriginalMime: "image/jpeg", Status: model.AssetPending}
	r.assets[a1.ID] = a1
	r.assets[a2.ID] = a2

	agent := &fakeAgent{optimized: make(chan struct{}, 1)}
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, agent, fakeSites{enrolled: true})

	res, err := svc.StartOptimize(context.Background(), tenantID, siteID, []uuid.UUID{a1.ID, a2.ID}, false, "avif", "lossy", userPrincipal(tenantID))
	if err != nil {
		t.Fatalf("StartOptimize: %v", err)
	}
	if res.QueuedCount != 2 {
		t.Errorf("queued_count = %d, want 2", res.QueuedCount)
	}
	if len(r.insertedJobs) != 2 {
		t.Errorf("inserted %d jobs, want 2 (one per asset — ADR-043 §3 fan-out)", len(r.insertedJobs))
	}
	// StartOptimize dispatches media_optimize in a DETACHED goroutine; await it
	// before reading optimizeCalls (race-free) instead of relying on timing.
	agent.waitOptimize(t)
	if agent.optimizeCalls != 1 {
		t.Errorf("agent optimize dispatched %d times, want 1", agent.optimizeCalls)
	}
	// Assets flipped to optimizing.
	if r.assets[a1.ID].Status != model.AssetOptimizing {
		t.Errorf("asset 1 status = %q, want optimizing", r.assets[a1.ID].Status)
	}
}

func TestStartOptimize_RejectsBadFormat(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	svc := newTestService(newFakeRepo(), &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})
	_, err := svc.StartOptimize(context.Background(), tenantID, siteID, nil, true, "gif", "", userPrincipal(tenantID))
	if err == nil {
		t.Fatal("expected validation error for target_format=gif")
	}
	de, ok := domain.AsDomain(err)
	if !ok || de.Kind != domain.KindValidation {
		t.Errorf("err = %v, want validation", err)
	}
}

func TestHandleEncodeReady_EnqueuesAndMarksInProgress(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	jobID := "JOB1"
	r.jobs[jobID] = model.Job{ID: jobID, TenantID: tenantID, SiteID: siteID, Kind: model.JobOptimize, TargetFormat: "webp", TargetQuality: "lossy", State: model.JobQueued}
	enq := &fakeEnqueuer{}
	svc := newTestService(r, &fakeStore{}, enq, &fakeAgent{}, fakeSites{enrolled: true})

	err := svc.HandleEncodeReady(context.Background(), tenantID, siteID, jobID, []EncodeReadyVariant{
		{Name: "full", SourceSize: 1000, SourceMime: "image/jpeg"},
		{Name: "thumbnail", SourceSize: 200, SourceMime: "image/jpeg"},
	})
	if err != nil {
		t.Fatalf("HandleEncodeReady: %v", err)
	}
	if len(enq.enqueued) != 1 {
		t.Fatalf("enqueued %d encode jobs, want 1 (one EncodeArgs carrying both variants)", len(enq.enqueued))
	}
	if got := len(enq.enqueued[0].Variants); got != 2 {
		t.Errorf("EncodeArgs carried %d variants, want 2", got)
	}
	if r.jobs[jobID].State != model.JobInProgress {
		t.Errorf("job state = %q, want in_progress", r.jobs[jobID].State)
	}
}

func TestHandleEncodeReady_SiteMismatchRejected(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	otherSite := uuid.New()
	r := newFakeRepo()
	r.jobs["JOB1"] = model.Job{ID: "JOB1", TenantID: tenantID, SiteID: otherSite, State: model.JobQueued}
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	err := svc.HandleEncodeReady(context.Background(), tenantID, siteID, "JOB1", []EncodeReadyVariant{{Name: "full"}})
	if err == nil {
		t.Fatal("expected forbidden for a job belonging to another site")
	}
	de, ok := domain.AsDomain(err)
	if !ok || de.Kind != domain.KindForbidden {
		t.Errorf("err = %v, want forbidden (job/site mismatch)", err)
	}
}

func TestHandleApplyStatus_FinalizesAndCleansUp(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	asset := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 7, Status: model.AssetOptimizing}
	r.assets[asset.ID] = asset
	aid := asset.ID
	jobID := "JOB7"
	r.jobs[jobID] = model.Job{ID: jobID, TenantID: tenantID, SiteID: siteID, AssetID: &aid, WPAttachmentID: 7, Kind: model.JobOptimize, State: model.JobInProgress, TargetFormat: "avif"}
	r.variants[jobID] = []model.VariantResult{{JobID: jobID, State: model.VariantSucceeded}}
	store := &fakeStore{}
	svc := newTestService(r, store, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	err := svc.HandleApplyStatus(context.Background(), tenantID, siteID, jobID, ApplyStatusInput{
		AppliedVariants:  []string{"full"},
		CurrentFormat:    "avif",
		CurrentSizeBytes: 500,
	})
	if err != nil {
		t.Fatalf("HandleApplyStatus: %v", err)
	}
	if r.jobs[jobID].State != model.JobSucceeded {
		t.Errorf("job state = %q, want succeeded", r.jobs[jobID].State)
	}
	if r.assets[asset.ID].Status != model.AssetOptimized {
		t.Errorf("asset status = %q, want optimized", r.assets[asset.ID].Status)
	}
	if store.deleted == 0 {
		t.Error("expected temp objects to be deleted after apply (ADR-043 §2)")
	}
}

func TestHandleApplyStatus_DeleteOriginals(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	asset := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 9, Status: model.AssetOptimized}
	r.assets[asset.ID] = asset
	aid := asset.ID
	jobID := "JOB9"
	r.jobs[jobID] = model.Job{ID: jobID, TenantID: tenantID, SiteID: siteID, AssetID: &aid, WPAttachmentID: 9, Kind: model.JobDeleteOriginals, State: model.JobInProgress}
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	if err := svc.HandleApplyStatus(context.Background(), tenantID, siteID, jobID, ApplyStatusInput{OriginalsDeleted: true}); err != nil {
		t.Fatalf("HandleApplyStatus(delete): %v", err)
	}
	if r.assets[asset.ID].Status != model.AssetOriginalsDeleted {
		t.Errorf("asset status = %q, want originals_deleted", r.assets[asset.ID].Status)
	}
	if r.jobs[jobID].State != model.JobSucceeded {
		t.Errorf("job state = %q, want succeeded", r.jobs[jobID].State)
	}
}

func TestStartDeleteOriginals_RequiresOptimized(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	a := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 3, OriginalMime: "image/jpeg", Status: model.AssetPending}
	r.assets[a.ID] = a
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	_, err := svc.StartDeleteOriginals(context.Background(), tenantID, siteID, []uuid.UUID{a.ID}, userPrincipal(tenantID))
	if err == nil {
		t.Fatal("expected conflict: delete-originals requires an optimized asset")
	}
	de, ok := domain.AsDomain(err)
	if !ok || de.Kind != domain.KindConflict {
		t.Errorf("err = %v, want conflict", err)
	}
}

func TestStartRestore_RefusesOriginalsDeleted(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	a := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 4, OriginalMime: "image/jpeg", Status: model.AssetOriginalsDeleted}
	r.assets[a.ID] = a
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	_, err := svc.StartRestore(context.Background(), tenantID, siteID, []uuid.UUID{a.ID}, userPrincipal(tenantID))
	if err == nil {
		t.Fatal("expected conflict: cannot restore when originals are deleted")
	}
}

func TestCancel_CancelsNonTerminalJobs(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	r.jobs["A"] = model.Job{ID: "A", SiteID: siteID, State: model.JobQueued}
	r.jobs["B"] = model.Job{ID: "B", SiteID: siteID, State: model.JobInProgress}
	r.jobs["C"] = model.Job{ID: "C", SiteID: siteID, State: model.JobSucceeded}
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	res, err := svc.Cancel(context.Background(), tenantID, siteID, userPrincipal(tenantID))
	if err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if res.CancelledCount != 2 {
		t.Errorf("cancelled %d, want 2 (queued + in_progress; succeeded untouched)", res.CancelledCount)
	}
}

// TestCancel_CancelsRiverEncodeJobs is the regression guard for the orphaned
// River job bug: when an operator cancels a media optimization job, the service
// must also cancel any stored River media_encode job IDs so the encoder is
// never woken for discarded work. River cancels are best-effort — a failure
// does not fail the operator's cancel request.
func TestCancel_CancelsRiverEncodeJobs(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()

	// riverID1 simulates a job that has already been through encode-ready
	// (HandleEncodeReady stored its River job ID). riverID2 is a second job.
	// riverID values must be non-zero — 0 is the zero value of int64.
	riverID1, riverID2 := int64(101), int64(202)
	r.jobs["A"] = model.Job{ID: "A", SiteID: siteID, State: model.JobInProgress, EncodeRiverJobID: &riverID1}
	r.jobs["B"] = model.Job{ID: "B", SiteID: siteID, State: model.JobQueued, EncodeRiverJobID: &riverID2}
	// Job C has no River ID (non-optimize or encode-ready not yet called).
	r.jobs["C"] = model.Job{ID: "C", SiteID: siteID, State: model.JobQueued}
	// Job D is already terminal — must not be cancelled or cause a River call.
	r.jobs["D"] = model.Job{ID: "D", SiteID: siteID, State: model.JobSucceeded}

	enq := &fakeEnqueuer{}
	svc := newTestService(r, &fakeStore{}, enq, &fakeAgent{}, fakeSites{enrolled: true})

	res, err := svc.Cancel(context.Background(), tenantID, siteID, userPrincipal(tenantID))
	if err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if res.CancelledCount != 3 {
		t.Errorf("cancelled %d, want 3 (A+B+C; D terminal untouched)", res.CancelledCount)
	}

	// Verify the River cancel was called for the two jobs that had stored IDs.
	// Job C had no River ID — it must NOT appear in the cancelled list.
	if len(enq.cancelled) != 2 {
		t.Fatalf("River CancelEncodeJob called %d times, want 2 (only jobs with stored River IDs)", len(enq.cancelled))
	}
	// Order is non-deterministic (map iteration); use a set.
	cancelledSet := make(map[int64]bool, len(enq.cancelled))
	for _, id := range enq.cancelled {
		cancelledSet[id] = true
	}
	if !cancelledSet[riverID1] {
		t.Errorf("River job %d not cancelled (expected for job A)", riverID1)
	}
	if !cancelledSet[riverID2] {
		t.Errorf("River job %d not cancelled (expected for job B)", riverID2)
	}
}

// TestHandleEncodeReady_StoresRiverJobID verifies that the River job ID
// returned by EnqueueEncode is persisted on the media_optimization_jobs row via
// SetEncodeRiverJobID. This is the other half of the cancel fix — the store
// path that makes the cancel path possible.
func TestHandleEncodeReady_StoresRiverJobID(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	jobID := "JOB_RIVER_ID"
	r.jobs[jobID] = model.Job{
		ID: jobID, TenantID: tenantID, SiteID: siteID,
		Kind: model.JobOptimize, TargetFormat: "webp", TargetQuality: "lossy",
		State: model.JobQueued,
	}
	enq := &fakeEnqueuer{nextRiverID: 500} // will increment to 501 on first Enqueue
	svc := newTestService(r, &fakeStore{}, enq, &fakeAgent{}, fakeSites{enrolled: true})

	err := svc.HandleEncodeReady(context.Background(), tenantID, siteID, jobID, []EncodeReadyVariant{
		{Name: "full", SourceSize: 1000, SourceMime: "image/jpeg"},
	})
	if err != nil {
		t.Fatalf("HandleEncodeReady: %v", err)
	}

	// The River job ID (501) must now be stored on the job row.
	got := r.jobs[jobID]
	if got.EncodeRiverJobID == nil {
		t.Fatal("EncodeRiverJobID is nil after HandleEncodeReady; expected it to be stored (m51)")
	}
	if *got.EncodeRiverJobID != 501 {
		t.Errorf("EncodeRiverJobID = %d, want 501 (the ID returned by EnqueueEncode)", *got.EncodeRiverJobID)
	}
}

func TestRateLimit_BlocksRunawayOptimize(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	// Each pending asset must have an optimizable MIME so resolveAssets includes it.
	pending := make([]model.Asset, 0, 3)
	for i := 0; i < 3; i++ {
		a := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: int64(i + 1), OriginalMime: "image/jpeg", Status: model.AssetPending}
		r.assets[a.ID] = a
		pending = append(pending, a)
	}
	r.pending = pending

	// The rate limiter now consumes 1 unit per optimize REQUEST (not per image).
	// With a per-site cap of 2, the 3rd call should be rate-limited.
	s := NewService(r, &fakeStore{}, nil, nil, domain.SystemClock{}, Config{
		CPBaseURL: "https://cp.example", RatePerSite: 2, RatePerTenant: 10, RateWindow: time.Minute,
	}, nil)
	s.SetEnqueuer(&fakeEnqueuer{})
	// Use an agent with an async channel so the detached dispatch goroutines don't
	// race with test assertions; drain the channel to avoid goroutine leaks.
	ag := &fakeAgent{optimized: make(chan struct{}, 10)}
	s.SetAgentClient(ag, fakeSites{enrolled: true})

	ctx := context.Background()
	p := userPrincipal(tenantID)

	// First two calls should succeed (each consuming 1 of the 2 allowed slots).
	if _, err := s.StartOptimize(ctx, tenantID, siteID, nil, true, "avif", "lossy", p); err != nil {
		t.Fatalf("call 1: unexpected error: %v", err)
	}
	ag.waitOptimize(t)
	if _, err := s.StartOptimize(ctx, tenantID, siteID, nil, true, "avif", "lossy", p); err != nil {
		t.Fatalf("call 2: unexpected error: %v", err)
	}
	ag.waitOptimize(t)

	// Third call must be rate-limited.
	_, err := s.StartOptimize(ctx, tenantID, siteID, nil, true, "avif", "lossy", p)
	if err == nil {
		t.Fatal("expected rate-limit error on 3rd call against a per-site cap of 2")
	}
	de, ok := domain.AsDomain(err)
	if !ok || de.Kind != domain.KindRateLimited {
		t.Errorf("err = %v, want rate-limited", err)
	}
}

// TestHandleAutoOptimize_FreshUploadIsUpsertedThenOptimized is the regression
// guard for ADR-044: a fresh upload that is NOT yet in site_media_assets (no
// prior media_sync) must be upserted by HandleAutoOptimize before gating, so
// Accepted > 0 rather than Skipped = len(rows).
func TestHandleAutoOptimize_FreshUploadIsUpsertedThenOptimized(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	// r.assets is empty — simulates an agent that uploaded before any sync ran.

	agent := &fakeAgent{optimized: make(chan struct{}, 1)}
	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, agent, fakeSites{enrolled: true})

	rows := []repo.UpsertAssetInput{
		{
			WPAttachmentID:    42,
			Title:             "fresh.jpg",
			OriginalPath:      "/wp-content/uploads/2026/01/fresh.jpg",
			OriginalURL:       "https://site.example/wp-content/uploads/2026/01/fresh.jpg",
			OriginalMime:      "image/jpeg",
			OriginalSizeBytes: 120000,
		},
	}
	res, err := svc.HandleAutoOptimize(context.Background(), tenantID, siteID, rows)
	if err != nil {
		t.Fatalf("HandleAutoOptimize: %v", err)
	}
	if res.Accepted != 1 {
		t.Errorf("accepted = %d, want 1 (fresh upload must be upserted then optimized)", res.Accepted)
	}
	if res.Skipped != 0 {
		t.Errorf("skipped = %d, want 0", res.Skipped)
	}
	// The asset must now exist in the repo (created by the upsert).
	var found bool
	for _, a := range r.assets {
		if a.WPAttachmentID == 42 {
			found = true
			break
		}
	}
	if !found {
		t.Error("asset wp_attachment_id=42 not found in repo after HandleAutoOptimize upsert")
	}
	// Await the detached StartOptimize dispatch.
	agent.waitOptimize(t)
	if agent.optimizeCalls != 1 {
		t.Errorf("agent optimize dispatched %d times, want 1", agent.optimizeCalls)
	}
}

// TestHandleAutoOptimize_AlreadyOptimizedIsSkipped verifies that an attachment
// already in the CP with status=optimized is not re-enqueued even though the
// upsert refreshes its metadata.
func TestHandleAutoOptimize_AlreadyOptimizedIsSkipped(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	existing := model.Asset{
		ID:             uuid.New(),
		TenantID:       tenantID,
		SiteID:         siteID,
		WPAttachmentID: 7,
		OriginalMime:   "image/jpeg",
		Status:         model.AssetOptimized,
	}
	r.assets[existing.ID] = existing

	svc := newTestService(r, &fakeStore{}, &fakeEnqueuer{}, &fakeAgent{}, fakeSites{enrolled: true})

	rows := []repo.UpsertAssetInput{
		{WPAttachmentID: 7, OriginalMime: "image/jpeg", OriginalSizeBytes: 80000},
	}
	res, err := svc.HandleAutoOptimize(context.Background(), tenantID, siteID, rows)
	if err != nil {
		t.Fatalf("HandleAutoOptimize: %v", err)
	}
	if res.Skipped != 1 {
		t.Errorf("skipped = %d, want 1 (already-optimized must be skipped)", res.Skipped)
	}
	if res.Accepted != 0 {
		t.Errorf("accepted = %d, want 0", res.Accepted)
	}
}

// TestFailJob_DoesNotClobberTerminalJob is the regression guard for the bulk
// optimize success/fail race: a dispatch-timeout-driven failJob must NEVER
// overwrite a job the agent already finalized, and must NOT emit media.job.failed
// for it. The DB-layer guard lives in FinalizeJobAgent (only non-terminal rows
// transition); the SSE-layer guard lives in failJob (suppress the failed event
// when the surviving state is not failed). The fakeRepo mirrors the real repo's
// guard (it only mutates state when !Terminal()).
func TestFailJob_DoesNotClobberTerminalJob(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	for _, tc := range []struct {
		name       string
		startState model.JobState
		wantState  model.JobState
		wantFailed int // expected media.job.failed events
	}{
		{"succeeded job survives", model.JobSucceeded, model.JobSucceeded, 0},
		{"partially_succeeded job survives", model.JobPartiallySucceeded, model.JobPartiallySucceeded, 0},
		{"cancelled job survives", model.JobCancelled, model.JobCancelled, 0},
		{"in_progress job is failed + emits", model.JobInProgress, model.JobFailed, 1},
		{"queued job is failed + emits", model.JobQueued, model.JobFailed, 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			r := newFakeRepo()
			jobID := "JOBX"
			r.jobs[jobID] = model.Job{ID: jobID, TenantID: tenantID, SiteID: siteID, State: tc.startState}
			ev := &fakeEvents{}
			s := newTestServiceWithEvents(r, ev)

			s.failJob(context.Background(), tenantID, siteID, jobID, "optimize dispatch failed: Client.Timeout exceeded")

			if got := r.jobs[jobID].State; got != tc.wantState {
				t.Errorf("job state = %q, want %q (terminal jobs must not be clobbered to failed)", got, tc.wantState)
			}
			if got := ev.count(site.EventMediaJobFailed); got != tc.wantFailed {
				t.Errorf("media.job.failed emitted %d times, want %d", got, tc.wantFailed)
			}
		})
	}
}

// TestStartRestore_DispatchFailureDoesNotClobberSucceeded exercises the
// StartRestore dispatch-failure path (a SYNCHRONOUS failJob loop — no detached
// goroutine, so it is fully deterministic). One restore job in the batch has
// already been finalized succeeded by the agent's restore-status callback before
// the dispatch error surfaces; the failJob loop must leave it succeeded and only
// fail the still-running one.
func TestStartRestore_DispatchFailureDoesNotClobberSucceeded(t *testing.T) {
	tenantID, siteID := uuid.New(), uuid.New()
	r := newFakeRepo()
	a1 := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 1, OriginalMime: "image/jpeg", Status: model.AssetOptimized}
	a2 := model.Asset{ID: uuid.New(), SiteID: siteID, WPAttachmentID: 2, OriginalMime: "image/jpeg", Status: model.AssetOptimized}
	r.assets[a1.ID] = a1
	r.assets[a2.ID] = a2
	ev := &fakeEvents{}
	s := NewService(r, &fakeStore{}, ev, nil, domain.SystemClock{}, Config{CPBaseURL: "https://cp.example"}, nil)
	s.SetEnqueuer(&fakeEnqueuer{})
	// failingRestoreAgent: as soon as the CP inserts the batch's jobs it marks the
	// first one succeeded (the agent's restore-status callback already won the
	// race), then returns a transport error so the CP runs its failJob loop.
	agent := &failingRestoreAgent{repo: r}
	s.SetAgentClient(agent, fakeSites{enrolled: true})

	_, err := s.StartRestore(context.Background(), tenantID, siteID, []uuid.UUID{a1.ID, a2.ID}, userPrincipal(tenantID))
	if err == nil {
		t.Fatal("expected dispatch error to surface from StartRestore")
	}

	var succeeded, failed int
	for _, j := range r.jobs {
		switch j.State {
		case model.JobSucceeded:
			succeeded++
		case model.JobFailed:
			failed++
		}
	}
	if succeeded != 1 {
		t.Errorf("succeeded jobs = %d, want 1 (the agent-finalized job must survive the dispatch failure)", succeeded)
	}
	if failed != 1 {
		t.Errorf("failed jobs = %d, want 1 (only the still-running job is failed)", failed)
	}
	if got := ev.count(site.EventMediaJobFailed); got != 1 {
		t.Errorf("media.job.failed emitted %d times, want 1 (never for the succeeded job)", got)
	}
}

// failingRestoreAgent marks the first job of the batch succeeded (simulating the
// agent's restore-status callback winning the race) and then returns a transport
// error so the CP's failJob loop runs against an already-terminal job.
type failingRestoreAgent struct{ repo *fakeRepo }

func (a *failingRestoreAgent) MediaOptimize(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaOptimizeRequest) (agentcmd.MediaOptimizeResponse, error) {
	return agentcmd.MediaOptimizeResponse{OK: true}, nil
}
func (a *failingRestoreAgent) MediaSync(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaSyncRequest) (agentcmd.MediaSyncResponse, error) {
	return agentcmd.MediaSyncResponse{OK: true}, nil
}
func (a *failingRestoreAgent) MediaRestore(_ context.Context, _ uuid.UUID, _ string, req agentcmd.MediaRestoreRequest) (agentcmd.MediaRestoreResponse, error) {
	if len(req.Jobs) > 0 {
		jid := req.Jobs[0].JobID
		j := a.repo.jobs[jid]
		j.State = model.JobSucceeded
		a.repo.jobs[jid] = j
	}
	return agentcmd.MediaRestoreResponse{}, context.DeadlineExceeded
}
func (a *failingRestoreAgent) MediaDeleteOriginals(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaDeleteOriginalsRequest) (agentcmd.MediaDeleteOriginalsResponse, error) {
	return agentcmd.MediaDeleteOriginalsResponse{OK: true}, nil
}
func (a *failingRestoreAgent) SyncMediaConfig(_ context.Context, _ uuid.UUID, _ string, _ agentcmd.MediaConfigRequest) (agentcmd.MediaConfigResult, error) {
	return agentcmd.MediaConfigResult{OK: true, Detail: "applied"}, nil
}
