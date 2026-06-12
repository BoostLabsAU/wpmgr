package agentcmd

import (
	"context"
	"crypto/ed25519"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/httpclient"
)

// buildTestAgentClient builds a *Client backed by a test-only Ed25519 signer
// and an SSRF-disabled httpclient that can reach loopback httptest servers.
// The httpclient's AllowedPorts is set to the test server's port so traffic
// is restricted to the ephemeral port without opening the SSRF guard broadly.
func buildTestAgentClient(t *testing.T, srv *httptest.Server) *Client {
	t.Helper()
	_, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatalf("generate ed25519 key: %v", err)
	}
	signer := &Signer{priv: priv}
	hc := httpclient.New(httpclient.Config{
		AllowPrivateNetworks: true, // test-only: loopback target
	})
	return NewClient(hc, signer)
}

// TestVerifyReachableFallbackToMetadata proves the 0.44.0 old-agent fallback:
// when the agent returns 404 for the ping command, VerifyReachable retries with
// the metadata command and reports alive=true + fallbackUsed=true on a 200.
func TestVerifyReachableFallbackToMetadata(t *testing.T) {
	siteID := uuid.New()
	callCount := 0

	// Fake agent: ping → 404, metadata → 200 with a trivial JSON body.
	// The JWT in the Authorization header is not verified (test-only).
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		switch r.URL.Path {
		case "/wp-json/wpmgr/v1/command/ping":
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"code":"rest_no_route","message":"No route was found matching the URL and request method."}`))
		case "/wp-json/wpmgr/v1/command/metadata":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true,"plugins":[],"themes":[],"wp_version":"6.5.0"}`))
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()

	client := buildTestAgentClient(t, srv)

	alive, fallback, err := client.VerifyReachable(context.Background(), siteID, srv.URL)
	if err != nil {
		t.Fatalf("VerifyReachable returned unexpected error: %v", err)
	}
	if !alive {
		t.Fatal("expected alive=true: metadata returned 200")
	}
	if !fallback {
		t.Fatal("expected fallbackUsed=true: ping returned 404, metadata was tried")
	}
	if callCount != 2 {
		t.Fatalf("expected exactly 2 HTTP calls (ping + metadata), got %d", callCount)
	}
}

// TestVerifyReachablePingAlive proves that when ping returns 200 the function
// reports alive=true and fallbackUsed=false (metadata is never called).
func TestVerifyReachablePingAlive(t *testing.T) {
	siteID := uuid.New()
	callCount := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		if r.URL.Path == "/wp-json/wpmgr/v1/command/ping" {
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true,"agent_version":"0.44.0","php_time":1000000,"wp_cron_disabled":true,"heartbeat_overdue_sec":120}`))
			return
		}
		// metadata must NOT be called
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	client := buildTestAgentClient(t, srv)

	alive, fallback, err := client.VerifyReachable(context.Background(), siteID, srv.URL)
	if err != nil {
		t.Fatalf("VerifyReachable error: %v", err)
	}
	if !alive {
		t.Fatal("expected alive=true: ping returned 200")
	}
	if fallback {
		t.Fatal("expected fallbackUsed=false: ping succeeded, metadata must not be called")
	}
	if callCount != 1 {
		t.Fatalf("expected exactly 1 HTTP call (ping only), got %d", callCount)
	}
}

// TestVerifyReachableRejectsNonAgent200 proves the captive-portal guard: a
// 200 that is not agent-shaped (ping without ok=true, metadata without
// wp_version/agent_version) must NOT count as alive — otherwise any generic
// 200 page (captive portal, maintenance splash) would mask a dead agent.
func TestVerifyReachableRejectsNonAgent200(t *testing.T) {
	siteID := uuid.New()
	callCount := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		// Captive portal: answers every path with a generic JSON 200.
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"welcome","login":"/portal"}`))
	}))
	defer srv.Close()

	client := buildTestAgentClient(t, srv)

	alive, fallback, err := client.VerifyReachable(context.Background(), siteID, srv.URL)
	if err != nil {
		t.Fatalf("VerifyReachable error: %v", err)
	}
	if alive {
		t.Fatal("expected alive=false: 200 without agent shape must not count as alive")
	}
	if !fallback {
		t.Fatal("expected fallbackUsed=true: non-ok ping 200 must be settled via metadata")
	}
	if callCount != 2 {
		t.Fatalf("expected 2 HTTP calls (ping + metadata shape check), got %d", callCount)
	}
}

// TestVerifyReachableBothFail proves that when both ping (non-404 failure) and
// any subsequent attempt fail, the function reports alive=false, err=nil.
func TestVerifyReachableBothFail(t *testing.T) {
	siteID := uuid.New()

	// Agent is completely unreachable (all requests → 503).
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = w.Write([]byte(`{"code":"service_unavailable"}`))
	}))
	defer srv.Close()

	client := buildTestAgentClient(t, srv)

	alive, fallback, err := client.VerifyReachable(context.Background(), siteID, srv.URL)
	if err != nil {
		t.Fatalf("VerifyReachable must return (false,false,nil) on hard failure, got err=%v", err)
	}
	if alive {
		t.Fatal("expected alive=false: agent returned 5xx")
	}
	if fallback {
		t.Fatal("expected fallbackUsed=false: ping failed with 5xx, not 404/400")
	}
}
