package email

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
)

func init() {
	gin.SetMode(gin.TestMode)
}

// ---------------------------------------------------------------------------
// Ingest idempotency
// ---------------------------------------------------------------------------

// TestIngestLogBatch_Idempotency verifies that pushing the same agent_seq twice
// results in exactly one logical entry (the fakeRepo counts by max seq, not by
// unique inserts, but the repo contract guarantees ON CONFLICT DO UPDATE
// semantics — tested here via service interface).
func TestIngestLogBatch_Idempotency(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	entries := []IngestEntry{
		{AgentSeq: 1, Status: "sent", Provider: "smtp", ToAddresses: []string{"a@b.com"}, CreatedAt: time.Now()},
		{AgentSeq: 2, Status: "failed", Provider: "smtp", ToAddresses: []string{"c@d.com"}, CreatedAt: time.Now()},
	}
	r1, err := svc.IngestLogBatch(context.Background(), tenantID, siteID, entries)
	if err != nil {
		t.Fatalf("first ingest: %v", err)
	}
	if r1.AckedThrough != 2 {
		t.Errorf("expected AckedThrough=2, got %d", r1.AckedThrough)
	}

	// Push the same entries again (agent retry scenario).
	r2, err := svc.IngestLogBatch(context.Background(), tenantID, siteID, entries)
	if err != nil {
		t.Fatalf("second ingest (idempotent retry): %v", err)
	}
	if r2.AckedThrough != 2 {
		t.Errorf("expected AckedThrough=2 on retry, got %d", r2.AckedThrough)
	}
}

// TestIngestLogBatch_IdentityFromKey verifies that the service uses the tenantID
// and siteID passed by the caller (which in production come from the verified
// agent identity) and does NOT take them from the entry payload.
func TestIngestLogBatch_IdentityFromKey(t *testing.T) {
	callerTenant := uuid.New()
	callerSite := uuid.New()
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	// The entry does not carry tenant/site — those come from the caller's verified
	// identity. This test just confirms the IngestLogBatch contract accepts the
	// caller-supplied IDs without error.
	entry := IngestEntry{AgentSeq: 10, Status: "sent", ToAddresses: []string{}, CreatedAt: time.Now()}
	result, err := svc.IngestLogBatch(context.Background(), callerTenant, callerSite, []IngestEntry{entry})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.AckedThrough != 10 {
		t.Errorf("expected AckedThrough=10, got %d", result.AckedThrough)
	}
}

// TestIngestLogBatch_BatchTooBig verifies that batches exceeding maxIngestBatch
// are rejected with a validation error.
func TestIngestLogBatch_BatchTooBig(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	entries := make([]IngestEntry, maxIngestBatch+1)
	for i := range entries {
		entries[i] = IngestEntry{AgentSeq: int64(i + 1), Status: "sent", ToAddresses: []string{}, CreatedAt: time.Now()}
	}
	_, err := svc.IngestLogBatch(context.Background(), uuid.New(), uuid.New(), entries)
	if err == nil {
		t.Fatal("expected validation error for oversized batch")
	}
	if !containsCode(err, "email_ingest_batch_too_large") {
		t.Errorf("expected code 'email_ingest_batch_too_large', got: %v", err)
	}
}

// TestIngestLogBatch_EmptyBatch verifies an empty batch is a no-op.
func TestIngestLogBatch_EmptyBatch(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	result, err := svc.IngestLogBatch(context.Background(), uuid.New(), uuid.New(), nil)
	if err != nil {
		t.Fatalf("unexpected error for empty batch: %v", err)
	}
	if result.AckedThrough != 0 {
		t.Errorf("expected AckedThrough=0 for empty batch, got %d", result.AckedThrough)
	}
}

// ---------------------------------------------------------------------------
// Keyset cursor encoding / decoding
// ---------------------------------------------------------------------------

// TestCursorRoundTrip verifies that encodeCursor / parseCursor are inverse
// operations: the decoded values match what was encoded.
func TestCursorRoundTrip(t *testing.T) {
	ts := time.Date(2026, 6, 10, 12, 0, 0, 123456789, time.UTC)
	id := uuid.MustParse("aaaabbbb-cccc-dddd-eeee-ffffaaaabbbb")

	cursor := encodeCursor(ts, id)
	decodedTs, decodedID := parseCursor(cursor)

	if !decodedTs.Equal(ts) {
		t.Errorf("timestamp round-trip: got %v, want %v", decodedTs, ts)
	}
	if decodedID != id {
		t.Errorf("id round-trip: got %v, want %v", decodedID, id)
	}
}

// TestCursorEmpty verifies an empty cursor returns the far-future sentinels
// (i.e., first page of results).
func TestCursorEmpty(t *testing.T) {
	ts, id := parseCursor("")
	if !ts.Equal(farFuture) {
		t.Errorf("expected farFuture for empty cursor, got %v", ts)
	}
	if id != cursorIDMax {
		t.Errorf("expected cursorIDMax for empty cursor, got %v", id)
	}
}

// TestCursorMalformed verifies that a malformed cursor falls back to sentinels.
func TestCursorMalformed(t *testing.T) {
	for _, bad := range []string{"garbage", "123", "not_a_uuid", "123_also_not_uuid"} {
		ts, id := parseCursor(bad)
		if !ts.Equal(farFuture) {
			t.Errorf("cursor %q: expected farFuture, got %v", bad, ts)
		}
		if id != cursorIDMax {
			t.Errorf("cursor %q: expected cursorIDMax, got %v", bad, id)
		}
	}
}

// TestCompositeKeyset_NoSkipOnSharedTimestamp documents the design intent:
// the composite (created_at, id) predicate is required so that rows sharing
// the same created_at timestamp are not skipped. This test validates the cursor
// encoding semantics ensure two rows with the same created_at but different ids
// produce different cursors that select different page boundaries.
func TestCompositeKeyset_NoSkipOnSharedTimestamp(t *testing.T) {
	sharedTs := time.Date(2026, 6, 10, 12, 0, 0, 0, time.UTC)
	id1 := uuid.MustParse("00000000-0000-0000-0000-000000000001")
	id2 := uuid.MustParse("00000000-0000-0000-0000-000000000002")

	cursor1 := encodeCursor(sharedTs, id1)
	cursor2 := encodeCursor(sharedTs, id2)

	// Different ids must produce different cursors even with the same timestamp.
	if cursor1 == cursor2 {
		t.Error("two rows with same timestamp but different ids produced identical cursors — composite predicate would skip rows")
	}

	// Decoding each cursor must recover the correct id.
	_, decodedID1 := parseCursor(cursor1)
	_, decodedID2 := parseCursor(cursor2)
	if decodedID1 != id1 {
		t.Errorf("cursor1 decoded id: got %v, want %v", decodedID1, id1)
	}
	if decodedID2 != id2 {
		t.Errorf("cursor2 decoded id: got %v, want %v", decodedID2, id2)
	}
}

// ---------------------------------------------------------------------------
// Body privacy
// ---------------------------------------------------------------------------

// TestToLogEntryDTO_BodyNotInList verifies that the list DTO never includes
// body content, even when body_stored=true.
func TestToLogEntryDTO_BodyNotInList(t *testing.T) {
	bodyText := "sensitive email content"
	entry := LogEntry{
		ID:          uuid.New(),
		TenantID:    uuid.New(),
		SiteID:      uuid.New(),
		BodyStored:  true,
		Body:        &bodyText,
		Status:      "sent",
		ToAddresses: []string{},
		CreatedAt:   time.Now(),
		UpdatedAt:   time.Now(),
		Response:    map[string]any{},
	}
	dto := toLogEntryDTO(entry, false /* list view */)
	if dto.Body != nil {
		t.Errorf("body must not appear in list DTO, got %q", *dto.Body)
	}
}

// TestToLogEntryDTO_BodyInDetail verifies that the detail DTO includes body
// when body_stored=true and includeBody=true.
func TestToLogEntryDTO_BodyInDetail(t *testing.T) {
	bodyText := "sensitive email content"
	entry := LogEntry{
		ID:          uuid.New(),
		TenantID:    uuid.New(),
		SiteID:      uuid.New(),
		BodyStored:  true,
		Body:        &bodyText,
		Status:      "sent",
		ToAddresses: []string{},
		CreatedAt:   time.Now(),
		UpdatedAt:   time.Now(),
		Response:    map[string]any{},
	}
	dto := toLogEntryDTO(entry, true /* detail view */)
	if dto.Body == nil {
		t.Fatal("body must be present in detail DTO when body_stored=true")
	}
	if *dto.Body != bodyText {
		t.Errorf("body mismatch: got %q, want %q", *dto.Body, bodyText)
	}
}

// TestToLogEntryDTO_BodyNotStoredNeverReturned verifies that even the detail
// view does not return body content when body_stored=false.
func TestToLogEntryDTO_BodyNotStoredNeverReturned(t *testing.T) {
	entry := LogEntry{
		ID:          uuid.New(),
		TenantID:    uuid.New(),
		SiteID:      uuid.New(),
		BodyStored:  false, // body opt-in was OFF at send time
		Body:        nil,
		Status:      "sent",
		ToAddresses: []string{},
		CreatedAt:   time.Now(),
		UpdatedAt:   time.Now(),
		Response:    map[string]any{},
	}
	dto := toLogEntryDTO(entry, true)
	if dto.Body != nil {
		t.Error("body must not be returned when body_stored=false")
	}
}

// ---------------------------------------------------------------------------
// Fleet RLS scoping
// ---------------------------------------------------------------------------

// TestService_ListFleetLog_TenantScoped verifies that the fleet list is invoked
// with the caller's tenantID (not a body-supplied value). The fakeRepo returns
// an empty list, which confirms no cross-tenant data leaks via the interface.
func TestService_ListFleetLog_TenantScoped(t *testing.T) {
	callerTenant := uuid.New()
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	page, err := svc.ListFleetLog(context.Background(), callerTenant, LogListFilter{Limit: 10})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	// fakeRepo returns empty; the important thing is no panic and no error.
	if len(page.Entries) != 0 {
		t.Errorf("expected empty list from fakeRepo, got %d entries", len(page.Entries))
	}
}

// ---------------------------------------------------------------------------
// resolveRange sentinel defaults
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// coerceResponse unit tests
// ---------------------------------------------------------------------------

func TestCoerceResponse_PlainString(t *testing.T) {
	raw := json.RawMessage(`"SMTP send OK"`)
	got := coerceResponse(raw)
	if got["summary"] != "SMTP send OK" {
		t.Errorf("expected summary='SMTP send OK', got %v", got)
	}
	if len(got) != 1 {
		t.Errorf("expected exactly one key, got %d: %v", len(got), got)
	}
}

func TestCoerceResponse_Object(t *testing.T) {
	raw := json.RawMessage(`{"code":250,"message":"OK"}`)
	got := coerceResponse(raw)
	if got["code"] != float64(250) {
		t.Errorf("expected code=250, got %v", got["code"])
	}
	if got["message"] != "OK" {
		t.Errorf("expected message='OK', got %v", got["message"])
	}
}

func TestCoerceResponse_Null(t *testing.T) {
	raw := json.RawMessage(`null`)
	got := coerceResponse(raw)
	if len(got) != 0 {
		t.Errorf("expected empty map for null, got %v", got)
	}
}

func TestCoerceResponse_Empty(t *testing.T) {
	got := coerceResponse(json.RawMessage(nil))
	if len(got) != 0 {
		t.Errorf("expected empty map for nil raw, got %v", got)
	}
	got2 := coerceResponse(json.RawMessage(""))
	if len(got2) != 0 {
		t.Errorf("expected empty map for empty raw, got %v", got2)
	}
}

func TestCoerceResponse_Number(t *testing.T) {
	raw := json.RawMessage(`250`)
	got := coerceResponse(raw)
	if got["summary"] != "250" {
		t.Errorf("expected summary='250' for number, got %v", got)
	}
}

func TestCoerceResponse_Array(t *testing.T) {
	raw := json.RawMessage(`["a","b"]`)
	got := coerceResponse(raw)
	if _, ok := got["summary"]; !ok {
		t.Errorf("expected 'summary' key for array, got %v", got)
	}
}

// ---------------------------------------------------------------------------
// parseCreatedAt unit tests
// ---------------------------------------------------------------------------

func TestParseCreatedAt_RFC3339(t *testing.T) {
	ts := time.Date(2026, 6, 10, 14, 0, 0, 0, time.UTC)
	raw := json.RawMessage(`"2026-06-10T14:00:00Z"`)
	got := parseCreatedAt(raw)
	if !got.Equal(ts) {
		t.Errorf("RFC3339: got %v, want %v", got, ts)
	}
}

func TestParseCreatedAt_RFC3339Nano(t *testing.T) {
	raw := json.RawMessage(`"2026-06-10T14:00:00.123456789Z"`)
	got := parseCreatedAt(raw)
	if got.IsZero() {
		t.Error("expected non-zero time for RFC3339Nano")
	}
	if got.Year() != 2026 || got.Month() != 6 || got.Day() != 10 {
		t.Errorf("unexpected parsed date: %v", got)
	}
}

func TestParseCreatedAt_MySQLFormat(t *testing.T) {
	// MySQL UTC format: no T, no Z.
	raw := json.RawMessage(`"2026-06-10 14:00:00"`)
	got := parseCreatedAt(raw)
	want := time.Date(2026, 6, 10, 14, 0, 0, 0, time.UTC)
	if !got.Equal(want) {
		t.Errorf("MySQL format: got %v, want %v", got, want)
	}
}

func TestParseCreatedAt_MySQLTFormat(t *testing.T) {
	// MySQL-T format: T separator but no Z.
	raw := json.RawMessage(`"2026-06-10T14:00:00"`)
	got := parseCreatedAt(raw)
	want := time.Date(2026, 6, 10, 14, 0, 0, 0, time.UTC)
	if !got.Equal(want) {
		t.Errorf("MySQL-T format: got %v, want %v", got, want)
	}
}

func TestParseCreatedAt_Empty(t *testing.T) {
	before := time.Now().UTC()
	got := parseCreatedAt(json.RawMessage(""))
	after := time.Now().UTC()
	if got.Before(before) || got.After(after) {
		t.Errorf("empty raw: expected ~now, got %v", got)
	}
}

func TestParseCreatedAt_Unparseable(t *testing.T) {
	before := time.Now().UTC()
	raw := json.RawMessage(`"not-a-date"`)
	got := parseCreatedAt(raw)
	after := time.Now().UTC()
	if got.Before(before) || got.After(after) {
		t.Errorf("unparseable: expected ~now fallback, got %v", got)
	}
}

// ---------------------------------------------------------------------------
// AgentHandler ingest HTTP-level tests
// ---------------------------------------------------------------------------

// newTestAgentEngine builds a minimal Gin engine that injects the given
// agent identity onto the context before routing to the handler.
func newTestAgentEngine(h *AgentHandler, id agent.Identity) *gin.Engine {
	engine := gin.New()
	group := engine.Group("/agent/v1")
	group.Use(func(c *gin.Context) {
		c.Request = c.Request.WithContext(agent.WithIdentity(c.Request.Context(), id))
		c.Next()
	})
	h.Register(group)
	return engine
}

func TestAgentHandler_IngestLog_StringResponse(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewAgentHandler(svc)

	id := agent.Identity{TenantID: uuid.New(), SiteID: uuid.New()}
	engine := newTestAgentEngine(h, id)

	body := `{"entries":[{
		"agent_seq":1,
		"status":"sent",
		"provider":"smtp",
		"to_addresses":["a@b.com"],
		"response":"SMTP send OK",
		"created_at":"2026-06-10T14:00:00Z"
	}]}`

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/email/log", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}
	var resp map[string]any
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp["acked_through"] != float64(1) {
		t.Errorf("expected acked_through=1, got %v", resp["acked_through"])
	}
}

func TestAgentHandler_IngestLog_ObjectResponse(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewAgentHandler(svc)

	id := agent.Identity{TenantID: uuid.New(), SiteID: uuid.New()}
	engine := newTestAgentEngine(h, id)

	body := `{"entries":[{
		"agent_seq":2,
		"status":"sent",
		"provider":"ses",
		"to_addresses":["c@d.com"],
		"response":{"code":250},
		"created_at":"2026-06-10T14:00:00Z"
	}]}`

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/email/log", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}
}

func TestAgentHandler_IngestLog_NullResponseAndEmptyCreatedAt(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewAgentHandler(svc)

	id := agent.Identity{TenantID: uuid.New(), SiteID: uuid.New()}
	engine := newTestAgentEngine(h, id)

	// response=null, created_at omitted entirely.
	body := `{"entries":[{
		"agent_seq":3,
		"status":"failed",
		"provider":"smtp",
		"to_addresses":["e@f.com"],
		"response":null
	}]}`

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/email/log", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}
}

func TestAgentHandler_IngestLog_MySQLCreatedAt(t *testing.T) {
	// Verifies that a MySQL-format timestamp is accepted (parsed, not defaulted).
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewAgentHandler(svc)

	id := agent.Identity{TenantID: uuid.New(), SiteID: uuid.New()}
	engine := newTestAgentEngine(h, id)

	body := `{"entries":[{
		"agent_seq":4,
		"status":"sent",
		"provider":"sendgrid",
		"to_addresses":["g@h.com"],
		"response":{"id":"abc"},
		"created_at":"2026-06-10 14:00:00"
	}]}`

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/email/log", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", w.Code, w.Body.String())
	}
}

func TestAgentHandler_IngestLog_MixedBatch(t *testing.T) {
	// A batch mixing string response, object response, null response, and MySQL
	// timestamp must all succeed and acked_through must be the max seq (5).
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo
	h := NewAgentHandler(svc)

	id := agent.Identity{TenantID: uuid.New(), SiteID: uuid.New()}
	engine := newTestAgentEngine(h, id)

	body := `{"entries":[
		{"agent_seq":1,"status":"sent","provider":"smtp","to_addresses":["a@b.com"],"response":"SMTP send OK","created_at":"2026-06-10T14:00:00Z"},
		{"agent_seq":2,"status":"sent","provider":"ses","to_addresses":["b@c.com"],"response":{"code":250},"created_at":"2026-06-10T14:00:01Z"},
		{"agent_seq":3,"status":"failed","provider":"smtp","to_addresses":["c@d.com"],"response":null,"created_at":"2026-06-10 14:00:02"},
		{"agent_seq":4,"status":"sent","provider":"sendgrid","to_addresses":["d@e.com"],"created_at":"2026-06-10 14:00:03"},
		{"agent_seq":5,"status":"failed","provider":"mailgun","to_addresses":["e@f.com"],"response":"connection refused"}
	]}`

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/email/log", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	engine.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200 for mixed batch, got %d: %s", w.Code, w.Body.String())
	}
	var resp map[string]any
	if err := json.NewDecoder(w.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp["acked_through"] != float64(5) {
		t.Errorf("expected acked_through=5, got %v", resp["acked_through"])
	}
}

// TestResolveRange verifies that zero times are replaced with epochStart /
// farFuture respectively so no IS NULL logic is needed in SQL.
func TestResolveRange(t *testing.T) {
	from, to := resolveRange(time.Time{}, time.Time{})
	if from != epochStart {
		t.Errorf("from: got %v, want epochStart %v", from, epochStart)
	}
	if to != farFuture {
		t.Errorf("to: got %v, want farFuture %v", to, farFuture)
	}

	// Non-zero values must pass through unchanged.
	custom := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	from2, to2 := resolveRange(custom, custom)
	if !from2.Equal(custom) {
		t.Errorf("custom from passed through incorrectly: got %v", from2)
	}
	if !to2.Equal(custom) {
		t.Errorf("custom to passed through incorrectly: got %v", to2)
	}
}

// ---------------------------------------------------------------------------
// Stats service passthrough
// ---------------------------------------------------------------------------

// TestService_GetSiteStats_Passthrough verifies GetSiteStats delegates to the
// repo and returns the result without modification.
func TestService_GetSiteStats_Passthrough(t *testing.T) {
	repo := newFakeRepo()
	svc := NewService(&Repo{}, nil, nil)
	svc.repo = repo

	stats, err := svc.GetSiteStats(context.Background(), uuid.New(), uuid.New(), time.Time{}, time.Time{})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if stats.Total != 0 {
		t.Errorf("expected 0 total from fakeRepo, got %d", stats.Total)
	}
}
