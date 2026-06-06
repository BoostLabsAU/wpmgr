package media

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

func TestEncoderWaker_DisabledWhenURLEmpty(t *testing.T) {
	w := NewEncoderWaker(nil, "", nil)
	if w.Enabled() {
		t.Fatal("waker with empty URL must be disabled")
	}
	// Kick + Run must be safe no-ops when disabled (self-host path).
	w.Kick()
	if len(w.kick) != 0 {
		t.Fatalf("disabled Kick must not enqueue, got %d", len(w.kick))
	}
	w.Run(context.Background()) // returns immediately
}

func TestEncoderWaker_DerivesDrainURLAndAudience(t *testing.T) {
	// Trailing slash + whitespace must be trimmed; drainURL gets the path.
	w := NewEncoderWaker(nil, "  https://enc.example.run.app/ ", nil)
	if !w.Enabled() {
		t.Fatal("waker with a URL must be enabled")
	}
	if got, want := w.audience, "https://enc.example.run.app"; got != want {
		t.Fatalf("audience = %q, want %q", got, want)
	}
	if got, want := w.drainURL, "https://enc.example.run.app/internal/drain"; got != want {
		t.Fatalf("drainURL = %q, want %q", got, want)
	}
}

func TestEncoderWaker_KickIsDedupedAndNonBlocking(t *testing.T) {
	w := NewEncoderWaker(nil, "https://enc.example.run.app", nil)
	// Many Kicks must collapse to a single buffered signal and never block.
	for i := 0; i < 10; i++ {
		w.Kick()
	}
	if len(w.kick) != 1 {
		t.Fatalf("Kick must coalesce to one pending signal, got %d", len(w.kick))
	}
}

func TestEncoderWaker_HoldDrainSendsBearerAndPosts(t *testing.T) {
	var gotAuth atomic.Value
	var gotMethod atomic.Value
	var gotPath atomic.Value
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth.Store(r.Header.Get("Authorization"))
		gotMethod.Store(r.Method)
		gotPath.Store(r.URL.Path)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"drained":true,"reason":"empty"}`))
	}))
	defer srv.Close()

	w := NewEncoderWaker(nil, srv.URL, nil)
	w.mintToken = func(context.Context) (string, error) { return "test-id-token", nil }

	w.holdDrain(context.Background(), 3)

	if got := gotMethod.Load(); got != http.MethodPost {
		t.Fatalf("method = %v, want POST", got)
	}
	if got := gotPath.Load(); got != "/internal/drain" {
		t.Fatalf("path = %v, want /internal/drain", got)
	}
	if got := gotAuth.Load(); got != "Bearer test-id-token" {
		t.Fatalf("authorization = %v, want Bearer test-id-token", got)
	}
}

func TestEncoderWaker_HoldDrainProceedsWithoutTokenOnMintError(t *testing.T) {
	var gotAuth atomic.Value
	gotAuth.Store("unset")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth.Store(r.Header.Get("Authorization"))
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	w := NewEncoderWaker(nil, srv.URL, nil)
	w.mintToken = func(context.Context) (string, error) { return "", context.DeadlineExceeded }

	// Must not panic and must still attempt the POST (tokenless).
	w.holdDrain(context.Background(), 1)

	if got := gotAuth.Load(); got != "" {
		t.Fatalf("authorization = %q, want empty (no token minted)", got)
	}
}
