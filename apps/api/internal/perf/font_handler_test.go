package perf

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/media/font"
)

// ---------------------------------------------------------------------------
// fakes
// ---------------------------------------------------------------------------

// fakeFontRepo satisfies fontTranscodeRepo for unit tests.
type fakeFontRepo struct {
	result    FontTranscodeResult
	resultErr error // error returned by GetFontTranscodeResult
	capCount  int   // returned by CountTodayFontTranscodeEnqueues
	capErr    error
	upsertErr error
	upsertOut FontTranscodeResult
}

func (r *fakeFontRepo) GetFontTranscodeResult(_ context.Context, _ uuid.UUID, _ string) (FontTranscodeResult, error) {
	return r.result, r.resultErr
}

func (r *fakeFontRepo) CountTodayFontTranscodeEnqueues(_ context.Context, _ uuid.UUID) (int, error) {
	return r.capCount, r.capErr
}

func (r *fakeFontRepo) UpsertFontTranscodeJob(_ context.Context, tenantID, siteID uuid.UUID, sourceHash string, _ int64) (FontTranscodeResult, error) {
	if r.upsertErr != nil {
		return FontTranscodeResult{}, r.upsertErr
	}
	out := r.upsertOut
	// Populate with the values so the response is sensible when upsertOut is
	// left at zero value.
	if out.TenantID == uuid.Nil {
		out.TenantID = tenantID
		out.SiteID = siteID
		out.SourceHash = sourceHash
		out.State = FontTranscodePending
	}
	return out, nil
}

// fakeFontEnqueuer satisfies FontTranscodeEnqueuer.
type fakeFontEnqueuer struct {
	jobID int64
	err   error
	calls []font.TranscodeArgs
}

func (e *fakeFontEnqueuer) EnqueueTranscode(_ context.Context, args font.TranscodeArgs) (int64, error) {
	e.calls = append(e.calls, args)
	return e.jobID, e.err
}

// fakeFontPresigner satisfies FontSourcePresigner. It records calls and returns
// deterministic URLs so tests can assert against them.
type fakeFontPresigner struct {
	putURL string
	getURL string
	putErr error
	getErr error
	// recorded calls
	putCalls []presignCall
	getCalls []presignCall
}

type presignCall struct {
	key string
	ttl time.Duration
}

func (p *fakeFontPresigner) PresignPut(_ context.Context, key string, ttl time.Duration) (string, error) {
	p.putCalls = append(p.putCalls, presignCall{key: key, ttl: ttl})
	if p.putErr != nil {
		return "", p.putErr
	}
	if p.putURL != "" {
		return p.putURL, nil
	}
	return "https://s3.example.com/put/" + key + "?signed=put", nil
}

func (p *fakeFontPresigner) PresignGet(_ context.Context, key string, ttl time.Duration) (string, error) {
	p.getCalls = append(p.getCalls, presignCall{key: key, ttl: ttl})
	if p.getErr != nil {
		return "", p.getErr
	}
	if p.getURL != "" {
		return p.getURL, nil
	}
	return "https://s3.example.com/get/" + key + "?signed=get", nil
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

// validHash is a syntactically valid 64-char lowercase hex string for tests.
const validHash = "abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890"

func buildFontEngine(t *testing.T, id agent.Identity, h *FontAgentHandler) *gin.Engine {
	t.Helper()
	gin.SetMode(gin.TestMode)
	eng := gin.New()
	eng.POST("/agent/v1/fonts/transcode", withIdentity(id, h.transcodeRequest))
	return eng
}

func postTranscode(t *testing.T, eng *gin.Engine, req agentcmd.FontTranscodeRequest) *httptest.ResponseRecorder {
	t.Helper()
	body, err := json.Marshal(req)
	if err != nil {
		t.Fatalf("marshal request: %v", err)
	}
	httpReq := httptest.NewRequest(http.MethodPost, "/agent/v1/fonts/transcode", bytes.NewReader(body))
	httpReq.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, httpReq)
	return rec
}

func decodeTranscodeResponse(t *testing.T, rec *httptest.ResponseRecorder) agentcmd.FontTranscodeResponse {
	t.Helper()
	var resp agentcmd.FontTranscodeResponse
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v (body: %s)", err, rec.Body.String())
	}
	return resp
}

// ---------------------------------------------------------------------------
// tests
// ---------------------------------------------------------------------------

// TestFontTranscode_ReadyResponseCarriesWoff2GetURL is the primary gate for this
// change: when the DB row is in the ready state the CP must return a non-empty
// woff2_get_url presigned by the CP and must NOT require the agent to build or
// presign any key itself.
func TestFontTranscode_ReadyResponseCarriesWoff2GetURL(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: siteID}

	// Build the expected server-derived WOFF2 key.
	woff2Key := font.DeriveWoff2Key(tenantID, validHash)

	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash: validHash,
			TenantID:   tenantID,
			SiteID:     siteID,
			State:      FontTranscodeReady,
			Woff2Key:   woff2Key,
		},
		resultErr: nil, // row found
	}
	h := NewFontAgentHandler(nil, nil, presigner, 15*time.Minute)
	h.repo = repo // override the concrete *Repo with the fake

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{
		SourceHash: validHash,
		SourceSize: 1024,
	})

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	resp := decodeTranscodeResponse(t, rec)

	// state must be "ready"
	if resp.State != "ready" {
		t.Fatalf("expected state=ready, got %q", resp.State)
	}

	// woff2_get_url must be non-empty
	if resp.Woff2GetURL == "" {
		t.Fatal("expected non-empty woff2_get_url on ready response")
	}

	// woff2_get_url must be presigned for the tenant-scoped, server-derived key
	expectedKeyFragment := woff2Key
	if !strings.Contains(resp.Woff2GetURL, expectedKeyFragment) {
		t.Fatalf("woff2_get_url %q does not contain the expected tenant-scoped key %q", resp.Woff2GetURL, expectedKeyFragment)
	}

	// woff2_key (informational) must also be present
	if resp.Woff2Key == "" {
		t.Fatal("expected non-empty woff2_key (informational) on ready response")
	}

	// Verify the presigner was called for a GET, not a PUT.
	if len(presigner.getCalls) != 1 {
		t.Fatalf("expected exactly 1 PresignGet call, got %d", len(presigner.getCalls))
	}
	if presigner.getCalls[0].key != woff2Key {
		t.Fatalf("PresignGet called with key %q, want %q", presigner.getCalls[0].key, woff2Key)
	}
	if len(presigner.putCalls) != 0 {
		t.Fatalf("expected 0 PresignPut calls on ready poll, got %d", len(presigner.putCalls))
	}

	// source_put_url must be absent on a poll (source already uploaded)
	if resp.SourcePutURL != "" {
		t.Fatalf("source_put_url must be absent on ready-state poll, got %q", resp.SourcePutURL)
	}
}

// TestFontTranscode_ReadyPresignUsesGuardedTenantKey verifies that the key
// handed to PresignGet passes GuardStorageKey (tenant-scoped + BLAKE3 hash).
func TestFontTranscode_ReadyPresignUsesGuardedTenantKey(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: siteID}

	woff2Key := font.DeriveWoff2Key(tenantID, validHash)

	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash: validHash,
			TenantID:   tenantID,
			SiteID:     siteID,
			State:      FontTranscodeReady,
			Woff2Key:   woff2Key,
		},
	}
	h := NewFontAgentHandler(nil, nil, presigner, 5*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	_ = postTranscode(t, eng, agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 512})

	if len(presigner.getCalls) != 1 {
		t.Fatalf("expected 1 PresignGet call, got %d", len(presigner.getCalls))
	}
	gotKey := presigner.getCalls[0].key
	if err := font.GuardStorageKey(gotKey); err != nil {
		t.Fatalf("key %q passed to PresignGet failed GuardStorageKey: %v", gotKey, err)
	}
	// Key must be tenant-scoped: must start with "fonts/<tenantID>/"
	wantPrefix := "fonts/" + tenantID.String() + "/"
	if !strings.HasPrefix(gotKey, wantPrefix) {
		t.Fatalf("key %q does not start with tenant prefix %q", gotKey, wantPrefix)
	}
}

// TestFontTranscode_PendingResponseHasNoPutURLOnPoll verifies that a second
// POST for the same hash (state=pending, row already exists) does NOT include
// a source_put_url and does NOT include a woff2_get_url.
func TestFontTranscode_PendingPollHasNeitherURL(t *testing.T) {
	tenantID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: uuid.New()}

	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash: validHash,
			TenantID:   tenantID,
			State:      FontTranscodePending,
		},
	}
	h := NewFontAgentHandler(nil, nil, presigner, 15*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 2048})

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	resp := decodeTranscodeResponse(t, rec)
	if resp.State != "pending" {
		t.Fatalf("expected state=pending, got %q", resp.State)
	}
	if resp.SourcePutURL != "" {
		t.Fatalf("source_put_url must be absent on pending poll, got %q", resp.SourcePutURL)
	}
	if resp.Woff2GetURL != "" {
		t.Fatalf("woff2_get_url must be absent on pending state, got %q", resp.Woff2GetURL)
	}
	if len(presigner.getCalls) != 0 {
		t.Fatalf("expected 0 PresignGet calls for pending state, got %d", len(presigner.getCalls))
	}
}

// TestFontTranscode_FirstEnqueueReturnsPendingWithSourcePutURL verifies the
// first-enqueue response: state=pending + source_put_url + no woff2_get_url.
func TestFontTranscode_FirstEnqueueReturnsPendingWithSourcePutURL(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: siteID}

	presigner := &fakeFontPresigner{}
	enqueuer := &fakeFontEnqueuer{jobID: 42}
	repo := &fakeFontRepo{
		resultErr: ErrNotFound, // no existing row
		capCount:  0,
	}
	h := NewFontAgentHandler(nil, enqueuer, presigner, 15*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{
		SourceHash: validHash,
		SourceSize: 1024,
		SourceExt:  "ttf",
	})

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	resp := decodeTranscodeResponse(t, rec)
	if resp.State != "pending" {
		t.Fatalf("expected state=pending, got %q", resp.State)
	}
	if resp.SourcePutURL == "" {
		t.Fatal("expected non-empty source_put_url on first enqueue")
	}
	if resp.Woff2GetURL != "" {
		t.Fatalf("woff2_get_url must be absent on first enqueue (pending), got %q", resp.Woff2GetURL)
	}

	// PUT presign must have been called for the server-derived source key.
	if len(presigner.putCalls) != 1 {
		t.Fatalf("expected 1 PresignPut call, got %d", len(presigner.putCalls))
	}
	sourceKey := font.DeriveSourceKey(tenantID, validHash)
	if presigner.putCalls[0].key != sourceKey {
		t.Fatalf("PresignPut key %q, want %q", presigner.putCalls[0].key, sourceKey)
	}

	// GET presign must NOT have been called.
	if len(presigner.getCalls) != 0 {
		t.Fatalf("expected 0 PresignGet calls on first enqueue, got %d", len(presigner.getCalls))
	}
}

// TestFontTranscode_NegativeResponseHasNoURLs verifies that state=negative
// responses have neither source_put_url nor woff2_get_url.
func TestFontTranscode_NegativeResponseHasNoURLs(t *testing.T) {
	tenantID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: uuid.New()}

	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash:  validHash,
			TenantID:    tenantID,
			State:       FontTranscodeNegative,
			ErrorDetail: "unsupported format",
		},
	}
	h := NewFontAgentHandler(nil, nil, presigner, 15*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 512})

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rec.Code, rec.Body.String())
	}
	resp := decodeTranscodeResponse(t, rec)
	if resp.State != "negative" {
		t.Fatalf("expected state=negative, got %q", resp.State)
	}
	if resp.SourcePutURL != "" {
		t.Fatalf("source_put_url must be absent on negative, got %q", resp.SourcePutURL)
	}
	if resp.Woff2GetURL != "" {
		t.Fatalf("woff2_get_url must be absent on negative, got %q", resp.Woff2GetURL)
	}
	if resp.ErrorDetail == "" {
		t.Fatal("expected non-empty error_detail on negative response")
	}
	if len(presigner.getCalls) != 0 {
		t.Fatalf("expected 0 PresignGet calls for negative state, got %d", len(presigner.getCalls))
	}
}

// TestFontTranscode_ReadyPresignGetFailDegradesGracefully verifies that when
// PresignGet returns an error the handler still returns state=ready with a
// non-error HTTP status, but woff2_get_url is empty (agent retries next build).
func TestFontTranscode_ReadyPresignGetFailDegradesGracefully(t *testing.T) {
	tenantID := uuid.New()
	siteID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: siteID}

	woff2Key := font.DeriveWoff2Key(tenantID, validHash)
	presigner := &fakeFontPresigner{getErr: fmt.Errorf("storage unavailable")}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash: validHash,
			TenantID:   tenantID,
			SiteID:     siteID,
			State:      FontTranscodeReady,
			Woff2Key:   woff2Key,
		},
	}
	h := NewFontAgentHandler(nil, nil, presigner, 15*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 512})

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 even when presign fails, got %d body=%s", rec.Code, rec.Body.String())
	}
	resp := decodeTranscodeResponse(t, rec)
	if resp.State != "ready" {
		t.Fatalf("expected state=ready, got %q", resp.State)
	}
	// Degraded: woff2_get_url is empty but woff2_key is still present.
	if resp.Woff2GetURL != "" {
		t.Fatalf("expected empty woff2_get_url on presign failure, got %q", resp.Woff2GetURL)
	}
	if resp.Woff2Key == "" {
		t.Fatal("woff2_key must still be present even when presign fails")
	}
}

// TestFontTranscode_InvalidHashRejected400 verifies that a malformed
// source_hash is rejected with 400 before any presign call.
func TestFontTranscode_InvalidHashRejected400(t *testing.T) {
	tenantID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: uuid.New()}
	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{resultErr: ErrNotFound}
	h := NewFontAgentHandler(nil, &fakeFontEnqueuer{}, presigner, 15*time.Minute)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	rec := postTranscode(t, eng, agentcmd.FontTranscodeRequest{
		SourceHash: "not-a-valid-hash",
		SourceSize: 512,
	})

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 on invalid hash, got %d body=%s", rec.Code, rec.Body.String())
	}
	if len(presigner.putCalls)+len(presigner.getCalls) > 0 {
		t.Fatal("presigner must not be called when hash is invalid")
	}
}

// TestFontTranscode_NoIdentity401 verifies that the endpoint requires the
// Ed25519 agent identity (the middleware normally injects it; here we don't).
func TestFontTranscode_NoIdentity401(t *testing.T) {
	gin.SetMode(gin.TestMode)
	h := NewFontAgentHandler(nil, nil, &fakeFontPresigner{}, 15*time.Minute)
	h.repo = &fakeFontRepo{}

	eng := gin.New()
	// No withIdentity wrapper — identity is absent from context.
	eng.POST("/agent/v1/fonts/transcode", h.transcodeRequest)

	body, _ := json.Marshal(agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 512})
	req := httptest.NewRequest(http.MethodPost, "/agent/v1/fonts/transcode", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	eng.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 without identity, got %d", rec.Code)
	}
}

// TestFontTranscode_PresignTTLForwardedToGet verifies that the same presignTTL
// used for the source PUT is also used for the WOFF2 GET.
func TestFontTranscode_PresignTTLForwardedToGet(t *testing.T) {
	tenantID := uuid.New()
	id := agent.Identity{TenantID: tenantID, SiteID: uuid.New()}

	const customTTL = 7 * time.Minute
	presigner := &fakeFontPresigner{}
	repo := &fakeFontRepo{
		result: FontTranscodeResult{
			SourceHash: validHash,
			TenantID:   tenantID,
			State:      FontTranscodeReady,
			Woff2Key:   font.DeriveWoff2Key(tenantID, validHash),
		},
	}
	h := NewFontAgentHandler(nil, nil, presigner, customTTL)
	h.repo = repo

	eng := buildFontEngine(t, id, h)
	_ = postTranscode(t, eng, agentcmd.FontTranscodeRequest{SourceHash: validHash, SourceSize: 512})

	if len(presigner.getCalls) != 1 {
		t.Fatalf("expected 1 PresignGet call, got %d", len(presigner.getCalls))
	}
	if presigner.getCalls[0].ttl != customTTL {
		t.Fatalf("PresignGet TTL %v, want %v", presigner.getCalls[0].ttl, customTTL)
	}
}
