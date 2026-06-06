package main

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func testDrainCfg(count func(ctx context.Context) (int, error)) drainConfig {
	return drainConfig{
		poll:    1 * time.Millisecond,
		quiet:   10 * time.Millisecond,
		maxHold: 2 * time.Second,
		count:   count,
		logger:  slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
}

func TestHoldUntilDrained_EmptyQueueDrains(t *testing.T) {
	drained, reason := holdUntilDrained(context.Background(),
		testDrainCfg(func(context.Context) (int, error) { return 0, nil }))
	if !drained || reason != "empty" {
		t.Fatalf("got (%v,%q), want (true,\"empty\")", drained, reason)
	}
}

func TestHoldUntilDrained_HoldsUntilWorkClears(t *testing.T) {
	var calls atomic.Int32
	// Report live work for the first few polls, then empty.
	count := func(context.Context) (int, error) {
		if calls.Add(1) <= 5 {
			return 2, nil
		}
		return 0, nil
	}
	drained, reason := holdUntilDrained(context.Background(), testDrainCfg(count))
	if !drained || reason != "empty" {
		t.Fatalf("got (%v,%q), want (true,\"empty\")", drained, reason)
	}
	if calls.Load() < 6 {
		t.Fatalf("expected to keep polling through live work, calls=%d", calls.Load())
	}
}

func TestHoldUntilDrained_ClientGoneReturnsPromptly(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel() // client already disconnected
	drained, reason := holdUntilDrained(ctx,
		testDrainCfg(func(context.Context) (int, error) { return 5, nil }))
	if drained || reason != "client-gone" {
		t.Fatalf("got (%v,%q), want (false,\"client-gone\")", drained, reason)
	}
}

func TestHoldUntilDrained_MaxHoldCeiling(t *testing.T) {
	cfg := testDrainCfg(func(context.Context) (int, error) { return 1, nil }) // never empties
	cfg.maxHold = 20 * time.Millisecond
	drained, reason := holdUntilDrained(context.Background(), cfg)
	if drained || reason != "max-hold" {
		t.Fatalf("got (%v,%q), want (false,\"max-hold\")", drained, reason)
	}
}

func TestHoldUntilDrained_CountErrorDoesNotPrematurelyDrain(t *testing.T) {
	var calls atomic.Int32
	// Persistent errors must NOT be treated as "empty" — the hold should run to
	// the max-hold ceiling rather than releasing the instance.
	count := func(context.Context) (int, error) {
		calls.Add(1)
		return 0, context.DeadlineExceeded
	}
	cfg := testDrainCfg(count)
	cfg.maxHold = 20 * time.Millisecond
	drained, reason := holdUntilDrained(context.Background(), cfg)
	if drained || reason != "max-hold" {
		t.Fatalf("got (%v,%q), want (false,\"max-hold\") — errors must not drain", drained, reason)
	}
}

func TestDrainHandler_PostReturns200JSON(t *testing.T) {
	h := drainHandler(testDrainCfg(func(context.Context) (int, error) { return 0, nil }))
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/internal/drain", nil)
	h(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), `"drained":true`) {
		t.Fatalf("body = %q, want drained:true", rec.Body.String())
	}
}

func TestDrainHandler_RejectsNonPost(t *testing.T) {
	h := drainHandler(testDrainCfg(func(context.Context) (int, error) { return 0, nil }))
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/internal/drain", nil)
	h(rec, req)
	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rec.Code)
	}
}

func TestDrainHandler_ConcurrencyCapReturns429(t *testing.T) {
	cfg := testDrainCfg(func(context.Context) (int, error) { return 0, nil })
	cfg.sem = make(chan struct{}, 1)
	cfg.sem <- struct{}{} // pre-fill to capacity: the handler cannot acquire a slot
	h := drainHandler(cfg)
	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodPost, "/internal/drain", nil)
	h(rec, req)
	if rec.Code != http.StatusTooManyRequests {
		t.Fatalf("status = %d, want 429 when at capacity", rec.Code)
	}
}
