package perf

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// ---------------------------------------------------------------------------
// fakes specific to db_scan handler tests
// ---------------------------------------------------------------------------

// dbScanFakeRepo embeds fakeRepo and adds configurable overrides for the
// db_scan result and active-scan state so handler tests can inject exact values.
type dbScanFakeRepo struct {
	fakeRepo
	// scan result fields
	scanResult    DBScanResult
	scanFound     bool
	// active scan state fields
	activeState   ActiveDBScanState
	activeStateErr error
}

func (r *dbScanFakeRepo) GetDBScanResult(_ context.Context, _, _ uuid.UUID) (DBScanResult, error) {
	if !r.scanFound {
		return DBScanResult{}, ErrNotFound
	}
	return r.scanResult, nil
}

func (r *dbScanFakeRepo) GetActiveDBScanState(_ context.Context, _, _ uuid.UUID) (ActiveDBScanState, error) {
	return r.activeState, r.activeStateErr
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// scanHandlerFixture holds the engine and the concrete URL to use in requests.
type scanHandlerFixture struct {
	eng     *gin.Engine
	handler *Handler
	// scanURL is the concrete URL with a real UUID in place of :siteId.
	scanURL string
}

// buildScanHandler wires a minimal gin engine with the POST and GET db/scan
// routes backed by a Service that uses the supplied repo + agent. Routes use
// the standard `:siteId` Gin param so parseSiteID works correctly.
func buildScanHandler(repo repository, ag AgentPerfClient) *scanHandlerFixture {
	gin.SetMode(gin.TestMode)
	svc := NewService(repo, nil, &fakeEvents{}, nil)
	if ag != nil {
		svc.SetAgentClient(ag, &fakeSites{url: "https://example.com"})
	}
	h := NewHandler(svc, nil, nil)

	eng := gin.New()
	siteID := uuid.New()
	// Register with :siteId param so Gin populates c.Param("siteId").
	paramPath := "/sites/:siteId/perf/db/scan"
	// The concrete URL used in test requests contains the real UUID.
	concreteURL := "/sites/" + siteID.String() + "/perf/db/scan"

	inject := func(c *gin.Context) {
		ctx := domain.WithPrincipal(c.Request.Context(), domain.Principal{
			TenantID: uuid.New(),
			Role:     string(authz.RoleAdmin),
		})
		c.Request = c.Request.WithContext(ctx)
	}
	eng.POST(paramPath, inject, h.dbScan)
	eng.GET(paramPath, inject, h.getDbScan)
	return &scanHandlerFixture{eng: eng, handler: h, scanURL: concreteURL}
}

// decodeBody unmarshals the response body into a map for key assertions.
func decodeBody(t *testing.T, body string) map[string]any {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal([]byte(body), &m); err != nil {
		t.Fatalf("decodeBody: %v (body=%s)", err, body)
	}
	return m
}

// ---------------------------------------------------------------------------
// TestDbScanAckCarriesResult
// ---------------------------------------------------------------------------

// TestDbScanAckCarriesResult verifies that a successful POST /perf/db/scan
// response body contains the full result payload (categories, tables,
// db_size_bytes) so the browser can render immediately without relying on the
// SSE db.scan.completed frame.
func TestDbScanAckCarriesResult(t *testing.T) {
	repo := &dbScanFakeRepo{}
	// fakeAgent.DBScan returns {ok:true, categories:{revisions:{count:10}}, db_size_bytes:1024, table_count:5}
	ag := &fakeAgent{}
	fix := buildScanHandler(repo, ag)

	w := httptest.NewRecorder()
	// POST with an empty JSON body (all-categories scan).
	req := httptest.NewRequest(http.MethodPost, fix.scanURL, bytes.NewBufferString(`{}`))
	req.Header.Set("Content-Type", "application/json")
	req.ContentLength = 2
	fix.eng.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
	}
	body := decodeBody(t, w.Body.String())

	if ok, _ := body["ok"].(bool); !ok {
		t.Fatalf("expected ok=true, body=%s", w.Body.String())
	}
	if _, hasJobID := body["job_id"].(string); !hasJobID {
		t.Fatalf("expected job_id string in ACK, body=%s", w.Body.String())
	}

	result, hasResult := body["result"].(map[string]any)
	if !hasResult || result == nil {
		t.Fatalf("expected non-null result in ACK, body=%s", w.Body.String())
	}

	// categories must be present (fakeAgent returns {"revisions": {...}}).
	if _, hasCats := result["categories"]; !hasCats {
		t.Errorf("expected categories in result, body=%s", w.Body.String())
	}
	// tables must be present (may be empty array from fakeAgent).
	if _, hasTables := result["tables"]; !hasTables {
		t.Errorf("expected tables in result, body=%s", w.Body.String())
	}
	// db_size_bytes must be present and non-zero (fakeAgent returns 1024).
	dbSizeRaw, hasSize := result["db_size_bytes"]
	if !hasSize {
		t.Fatalf("expected db_size_bytes in result, body=%s", w.Body.String())
	}
	// JSON numbers unmarshal as float64.
	dbSize, _ := dbSizeRaw.(float64)
	if dbSize <= 0 {
		t.Errorf("expected db_size_bytes > 0, got %v", dbSizeRaw)
	}
}

// ---------------------------------------------------------------------------
// TestDbScanGetIncludesActiveState
// ---------------------------------------------------------------------------

// TestDbScanGetIncludesActiveState verifies two sub-cases for GET /perf/db/scan:
//  1. When the repo reports an active scan job, scan_active=true and
//     active_job_id/active_started_at are non-null in the response.
//  2. When no scan is active, scan_active=false and the other fields are null.
func TestDbScanGetIncludesActiveState(t *testing.T) {
	startedAt := time.Date(2026, 6, 12, 10, 0, 0, 0, time.UTC)
	jobID := uuid.New().String()

	t.Run("active scan returns scan_active=true", func(t *testing.T) {
		repo := &dbScanFakeRepo{
			// No previous scan result — but there IS an active scan.
			scanFound: false,
			activeState: ActiveDBScanState{
				Active:    true,
				JobID:     jobID,
				StartedAt: startedAt,
			},
		}
		fix := buildScanHandler(repo, nil)

		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, fix.scanURL, nil)
		fix.eng.ServeHTTP(w, req)

		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
		}
		body := decodeBody(t, w.Body.String())

		scanActive, _ := body["scan_active"].(bool)
		if !scanActive {
			t.Errorf("expected scan_active=true, body=%s", w.Body.String())
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
	})

	t.Run("no active scan returns scan_active=false with null fields", func(t *testing.T) {
		categoriesJSON, _ := json.Marshal(map[string]any{
			"revisions": map[string]any{"count": 5, "bytes": 0},
		})
		repo := &dbScanFakeRepo{
			scanFound: true,
			scanResult: DBScanResult{
				JobID:          jobID,
				CategoriesJSON: categoriesJSON,
				TablesJSON:     []byte("[]"),
				DBSizeBytes:    2048,
				TableCount:     7,
				ScannedAt:      startedAt,
				CreatedAt:      startedAt,
			},
			// activeState is zero value: Active=false.
		}
		fix := buildScanHandler(repo, nil)

		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, fix.scanURL, nil)
		fix.eng.ServeHTTP(w, req)

		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
		}
		body := decodeBody(t, w.Body.String())

		scanActive, _ := body["scan_active"].(bool)
		if scanActive {
			t.Errorf("expected scan_active=false, body=%s", w.Body.String())
		}

		// active_job_id and active_started_at must be null (JSON null → Go nil interface).
		if body["active_job_id"] != nil {
			t.Errorf("expected active_job_id=null, got %v", body["active_job_id"])
		}
		if body["active_started_at"] != nil {
			t.Errorf("expected active_started_at=null, got %v", body["active_started_at"])
		}

		// The result must be present with the canned data.
		result, hasResult := body["result"].(map[string]any)
		if !hasResult || result == nil {
			t.Fatalf("expected non-null result, body=%s", w.Body.String())
		}
		dbSize, _ := result["db_size_bytes"].(float64)
		if dbSize != 2048 {
			t.Errorf("expected db_size_bytes=2048, got %v", dbSize)
		}
		// categories must contain the revisions key.
		cats, _ := result["categories"].(map[string]any)
		if _, hasRevisions := cats["revisions"]; !hasRevisions {
			t.Errorf("expected categories.revisions in result, body=%s", w.Body.String())
		}
	})

	t.Run("GET response includes orphaned_options and orphaned_cron keys", func(t *testing.T) {
		// Verify the helper exposes these Phase 3.3 fields (the GET path now uses
		// dbScanResultToGinH which always includes them).
		repo := &dbScanFakeRepo{
			scanFound: true,
			scanResult: DBScanResult{
				JobID:                jobID,
				CategoriesJSON:       []byte(`{}`),
				TablesJSON:           []byte(`[]`),
				OrphanedOptionsJSON:  []byte(`[{"name":"orphan_option","autoload":true,"size_bytes":32}]`),
				OrphanedCronJSON:     []byte(`[]`),
				InstalledPluginsJSON: []byte(`[]`),
				DBSizeBytes:          512,
			},
		}
		fix := buildScanHandler(repo, nil)

		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, fix.scanURL, nil)
		fix.eng.ServeHTTP(w, req)

		if w.Code != http.StatusOK {
			t.Fatalf("expected 200, got %d (body: %s)", w.Code, w.Body.String())
		}

		rawBody := w.Body.String()
		if !strings.Contains(rawBody, `"orphaned_options"`) {
			t.Errorf("expected orphaned_options in GET response body, body=%s", rawBody)
		}
		if !strings.Contains(rawBody, `"orphaned_cron"`) {
			t.Errorf("expected orphaned_cron in GET response body, body=%s", rawBody)
		}
		if !strings.Contains(rawBody, `"installed_plugins"`) {
			t.Errorf("expected installed_plugins in GET response body, body=%s", rawBody)
		}

		body := decodeBody(t, rawBody)
		result, _ := body["result"].(map[string]any)
		opts, _ := result["orphaned_options"].([]any)
		if len(opts) != 1 {
			t.Errorf("expected 1 orphaned_option, got %v", opts)
		}
	})
}
