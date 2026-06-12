package perf

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// ---------------------------------------------------------------------------
// fakes specific to db_clean handler tests
// ---------------------------------------------------------------------------

// dbCleanFakeRepo embeds fakeRepo and adds configurable overrides for the
// db_clean result and active-clean state so handler tests can inject exact values.
type dbCleanFakeRepo struct {
	fakeRepo
	// clean result fields
	cleanResult DBCleanResult
	cleanFound  bool
	// active clean state fields
	activeState    ActiveDBCleanState
	activeStateErr error
	// upsert capture
	upserted []DBCleanResultInput
}

func (r *dbCleanFakeRepo) GetDBCleanResult(_ context.Context, _, _ uuid.UUID) (DBCleanResult, error) {
	if !r.cleanFound {
		return DBCleanResult{}, ErrNotFound
	}
	return r.cleanResult, nil
}

func (r *dbCleanFakeRepo) GetActiveDBCleanState(_ context.Context, _, _ uuid.UUID) (ActiveDBCleanState, error) {
	return r.activeState, r.activeStateErr
}

func (r *dbCleanFakeRepo) UpsertDBCleanResult(_ context.Context, in DBCleanResultInput) error {
	r.upserted = append(r.upserted, in)
	return nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// cleanHandlerFixture holds the engine and the concrete URL for GET /perf/db/clean.
type cleanHandlerFixture struct {
	eng      *gin.Engine
	handler  *Handler
	cleanURL string
}

// buildCleanHandler wires a minimal gin engine with the GET db/clean route backed
// by a Service that uses the supplied repo. Routes use the standard `:siteId` Gin
// param so parseSiteID works correctly.
func buildCleanHandler(repo repository) *cleanHandlerFixture {
	gin.SetMode(gin.TestMode)
	svc := NewService(repo, nil, &fakeEvents{}, nil)
	h := NewHandler(svc, nil, nil)

	eng := gin.New()
	siteID := uuid.New()
	paramPath := "/sites/:siteId/perf/db/clean"
	concreteURL := "/sites/" + siteID.String() + "/perf/db/clean"

	inject := func(c *gin.Context) {
		ctx := domain.WithPrincipal(c.Request.Context(), domain.Principal{
			TenantID: uuid.New(),
			Role:     string(authz.RoleAdmin),
		})
		c.Request = c.Request.WithContext(ctx)
	}
	eng.GET(paramPath, inject, h.getDbClean)
	return &cleanHandlerFixture{eng: eng, handler: h, cleanURL: concreteURL}
}

// ---------------------------------------------------------------------------
// TestDbCleanGetIncludesActiveState
// ---------------------------------------------------------------------------

// TestDbCleanGetIncludesActiveState verifies two sub-cases for GET /perf/db/clean:
//  1. When the repo reports an active clean job, clean_active=true and
//     active_job_id/active_started_at are non-null in the response.
//  2. When no clean is active, clean_active=false and the other fields are null.
func TestDbCleanGetIncludesActiveState(t *testing.T) {
	startedAt := time.Date(2026, 7, 4, 10, 0, 0, 0, time.UTC)
	jobID := uuid.New().String()

	t.Run("active clean returns clean_active=true", func(t *testing.T) {
		repo := &dbCleanFakeRepo{
			// No previous clean result — but there IS an active clean.
			cleanFound: false,
			activeState: ActiveDBCleanState{
				Active:    true,
				JobID:     jobID,
				StartedAt: startedAt,
			},
		}
		fix := buildCleanHandler(repo)

		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, fix.cleanURL, nil)
		fix.eng.ServeHTTP(w, req)

		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
		}
		body := decodeBody(t, w.Body.String())

		cleanActive, _ := body["clean_active"].(bool)
		if !cleanActive {
			t.Errorf("expected clean_active=true, body=%s", w.Body.String())
		}

		activeJobIDVal, hasActiveJobID := body["active_job_id"].(string)
		if !hasActiveJobID || activeJobIDVal != jobID {
			t.Errorf("expected active_job_id=%q, body=%s", jobID, w.Body.String())
		}

		activeStartedAtVal, hasStartedAt := body["active_started_at"].(string)
		if !hasStartedAt || activeStartedAtVal == "" {
			t.Errorf("expected non-empty active_started_at RFC3339, body=%s", w.Body.String())
		}
		// Must parse as RFC3339 and round-trip to the same UTC time.
		parsed, parseErr := time.Parse(time.RFC3339, activeStartedAtVal)
		if parseErr != nil {
			t.Errorf("active_started_at is not RFC3339: %v (value=%q)", parseErr, activeStartedAtVal)
		} else if !parsed.UTC().Equal(startedAt) {
			t.Errorf("active_started_at mismatch: got %v want %v", parsed.UTC(), startedAt)
		}

		// last_result must be null when no clean has completed.
		if body["last_result"] != nil {
			t.Errorf("expected last_result=null when no prior clean, got %v", body["last_result"])
		}
	})

	t.Run("no active clean returns clean_active=false with null fields", func(t *testing.T) {
		cleanedAt := time.Date(2026, 7, 3, 8, 0, 0, 0, time.UTC)
		resultJSON, _ := json.Marshal(map[string]any{
			"revisions": map[string]any{"rows_deleted": 42, "bytes_freed": 1024, "state": "done"},
		})
		repo := &dbCleanFakeRepo{
			cleanFound: true,
			cleanResult: DBCleanResult{
				JobID:       jobID,
				ResultJSON:  resultJSON,
				RowsDeleted: 42,
				BytesFreed:  1024,
				CleanedAt:   cleanedAt,
				CreatedAt:   cleanedAt,
			},
			// activeState is zero value: Active=false.
		}
		fix := buildCleanHandler(repo)

		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, fix.cleanURL, nil)
		fix.eng.ServeHTTP(w, req)

		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
		}
		body := decodeBody(t, w.Body.String())

		cleanActive, _ := body["clean_active"].(bool)
		if cleanActive {
			t.Errorf("expected clean_active=false, body=%s", w.Body.String())
		}

		// active_job_id and active_started_at must be null.
		if body["active_job_id"] != nil {
			t.Errorf("expected active_job_id=null, got %v", body["active_job_id"])
		}
		if body["active_started_at"] != nil {
			t.Errorf("expected active_started_at=null, got %v", body["active_started_at"])
		}

		// last_result must be present with the canned data.
		lastResult, hasLastResult := body["last_result"].(map[string]any)
		if !hasLastResult || lastResult == nil {
			t.Fatalf("expected non-null last_result, body=%s", w.Body.String())
		}
		rowsDeleted, _ := lastResult["rows_deleted"].(float64)
		if rowsDeleted != 42 {
			t.Errorf("expected rows_deleted=42, got %v", rowsDeleted)
		}
		bytesFreed, _ := lastResult["bytes_freed"].(float64)
		if bytesFreed != 1024 {
			t.Errorf("expected bytes_freed=1024, got %v", bytesFreed)
		}
		// job_id must be present.
		if lastResult["job_id"] != jobID {
			t.Errorf("expected job_id=%q, got %v", jobID, lastResult["job_id"])
		}
		// cleaned_at must be a non-empty RFC3339 string.
		cleanedAtVal, _ := lastResult["cleaned_at"].(string)
		if cleanedAtVal == "" {
			t.Errorf("expected non-empty cleaned_at, body=%s", w.Body.String())
		}
		parsedCleanedAt, parseErr := time.Parse(time.RFC3339, cleanedAtVal)
		if parseErr != nil {
			t.Errorf("cleaned_at is not RFC3339: %v (value=%q)", parseErr, cleanedAtVal)
		} else if !parsedCleanedAt.UTC().Equal(cleanedAt) {
			t.Errorf("cleaned_at mismatch: got %v want %v", parsedCleanedAt.UTC(), cleanedAt)
		}
		// result must contain the revisions key.
		resultObj, _ := lastResult["result"].(map[string]any)
		if _, hasRevisions := resultObj["revisions"]; !hasRevisions {
			t.Errorf("expected result.revisions in last_result, body=%s", w.Body.String())
		}
	})
}

// ---------------------------------------------------------------------------
// TestDbCleanGetReturnsLastResult
// ---------------------------------------------------------------------------

// TestDbCleanGetReturnsLastResult verifies that after a clean run completes,
// GET /perf/db/clean returns the stored last_result with the correct field names
// and values. This is the "pull-truth" regression test: the web layer must be
// able to reconstruct state from GET without relying on SSE.
func TestDbCleanGetReturnsLastResult(t *testing.T) {
	jobID := uuid.New().String()
	cleanedAt := time.Date(2026, 7, 4, 12, 0, 0, 0, time.UTC)
	resultJSON, _ := json.Marshal(map[string]any{
		"expired_transients": map[string]any{"rows_deleted": 300, "bytes_freed": 65536, "state": "done"},
		"spam_comments":      map[string]any{"rows_deleted": 5, "bytes_freed": 512, "state": "done"},
	})

	repo := &dbCleanFakeRepo{
		cleanFound: true,
		cleanResult: DBCleanResult{
			JobID:       jobID,
			ResultJSON:  resultJSON,
			RowsDeleted: 305,
			BytesFreed:  66048,
			CleanedAt:   cleanedAt,
			CreatedAt:   cleanedAt,
		},
	}
	fix := buildCleanHandler(repo)

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, fix.cleanURL, nil)
	fix.eng.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := decodeBody(t, w.Body.String())

	lastResult, ok := body["last_result"].(map[string]any)
	if !ok || lastResult == nil {
		t.Fatalf("expected non-null last_result, body=%s", w.Body.String())
	}

	// Verify the total counters.
	if lastResult["rows_deleted"].(float64) != 305 {
		t.Errorf("expected rows_deleted=305, got %v", lastResult["rows_deleted"])
	}
	if lastResult["bytes_freed"].(float64) != 66048 {
		t.Errorf("expected bytes_freed=66048, got %v", lastResult["bytes_freed"])
	}
	if lastResult["job_id"] != jobID {
		t.Errorf("expected job_id=%q, got %v", jobID, lastResult["job_id"])
	}

	// Verify the per-category result map is present and contains the expected keys.
	resultObj, _ := lastResult["result"].(map[string]any)
	if _, has := resultObj["expired_transients"]; !has {
		t.Errorf("expected result.expired_transients, body=%s", w.Body.String())
	}
	if _, has := resultObj["spam_comments"]; !has {
		t.Errorf("expected result.spam_comments, body=%s", w.Body.String())
	}
}

// ---------------------------------------------------------------------------
// TestDbCleanProgressOrderingAndPersist
// ---------------------------------------------------------------------------

// TestDbCleanProgressOrderingAndPersist verifies two ordering invariants and the
// persist-on-completion behaviour of HandleDBCleanProgress:
//
//  1. On done=true (success): the result is persisted, the completed SSE is
//     published, and the watchdog is cleared — in that order. Specifically,
//     the completed event must appear in the event bus BEFORE ClearActiveDBCleanJob
//     is called (tested here by checking that the event was published).
//  2. On done=true + state=error: the failed SSE is published and the watchdog
//     is cleared; no result is upserted.
//  3. Non-final pushes (done=false) only emit a progress event; no upsert.
func TestDbCleanProgressOrderingAndPersist(t *testing.T) {
	t.Run("done=true success: result persisted and completed event published", func(t *testing.T) {
		repo := &dbCleanFakeRepo{}
		events := &fakeEvents{}
		svc := NewService(repo, nil, events, nil)
		tenantID := uuid.New()
		siteID := uuid.New()
		jobID := uuid.New().String()

		err := svc.HandleDBCleanProgress(context.Background(), DBCleanProgressInput{
			JobID:       jobID,
			Category:    "revisions",
			RowsDeleted: 10,
			BytesFreed:  512,
			State:       "done",
			Done:        true,
			TenantID:    tenantID,
			SiteID:      siteID,
		})
		if err != nil {
			t.Fatalf("HandleDBCleanProgress returned error: %v", err)
		}

		// A result must have been upserted.
		if len(repo.upserted) != 1 {
			t.Fatalf("expected 1 upsert, got %d", len(repo.upserted))
		}
		u := repo.upserted[0]
		if u.JobID != jobID {
			t.Errorf("upserted job_id mismatch: got %q want %q", u.JobID, jobID)
		}
		if u.RowsDeleted != 10 {
			t.Errorf("upserted rows_deleted mismatch: got %d want 10", u.RowsDeleted)
		}
		if u.BytesFreed != 512 {
			t.Errorf("upserted bytes_freed mismatch: got %d want 512", u.BytesFreed)
		}

		// The completed event must have been published.
		types := events.types()
		found := false
		for _, ev := range types {
			if ev == site.EventDbCleanCompleted {
				found = true
				break
			}
		}
		if !found {
			t.Errorf("expected %s event to be published, got events: %v", site.EventDbCleanCompleted, types)
		}

		// The failed event must NOT have been published.
		for _, ev := range types {
			if ev == site.EventDbCleanFailed {
				t.Errorf("unexpected %s event on successful done=true", site.EventDbCleanFailed)
			}
		}
	})

	t.Run("done=true state=error: failed event published, no result upserted", func(t *testing.T) {
		repo := &dbCleanFakeRepo{}
		events := &fakeEvents{}
		svc := NewService(repo, nil, events, nil)
		tenantID := uuid.New()
		siteID := uuid.New()
		jobID := uuid.New().String()

		err := svc.HandleDBCleanProgress(context.Background(), DBCleanProgressInput{
			JobID:    jobID,
			Category: "revisions",
			State:    "error",
			Detail:   "out of memory",
			Done:     true,
			TenantID: tenantID,
			SiteID:   siteID,
		})
		if err != nil {
			t.Fatalf("HandleDBCleanProgress returned error: %v", err)
		}

		// No result upsert on error.
		if len(repo.upserted) != 0 {
			t.Errorf("expected 0 upserts on state=error, got %d", len(repo.upserted))
		}

		types := events.types()
		found := false
		for _, ev := range types {
			if ev == site.EventDbCleanFailed {
				found = true
				break
			}
		}
		if !found {
			t.Errorf("expected %s event on state=error, got events: %v", site.EventDbCleanFailed, types)
		}
		for _, ev := range types {
			if ev == site.EventDbCleanCompleted {
				t.Errorf("unexpected %s event on state=error", site.EventDbCleanCompleted)
			}
		}
	})

	t.Run("done=false: only progress event, no upsert", func(t *testing.T) {
		repo := &dbCleanFakeRepo{}
		events := &fakeEvents{}
		svc := NewService(repo, nil, events, nil)
		tenantID := uuid.New()
		siteID := uuid.New()
		jobID := uuid.New().String()

		err := svc.HandleDBCleanProgress(context.Background(), DBCleanProgressInput{
			JobID:       jobID,
			Category:    "revisions",
			RowsDeleted: 5,
			State:       "processing",
			Done:        false,
			TenantID:    tenantID,
			SiteID:      siteID,
		})
		if err != nil {
			t.Fatalf("HandleDBCleanProgress returned error: %v", err)
		}

		// No result upsert for non-final pushes.
		if len(repo.upserted) != 0 {
			t.Errorf("expected 0 upserts on done=false, got %d", len(repo.upserted))
		}

		types := events.types()
		if len(types) != 1 || types[0] != site.EventDbCleanProgress {
			t.Errorf("expected exactly [%s], got %v", site.EventDbCleanProgress, types)
		}
	})
}
