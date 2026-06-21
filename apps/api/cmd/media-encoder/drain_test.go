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

func TestLiveEncodeJobsQuery_DefaultSchema(t *testing.T) {
	q, err := liveEncodeJobsQuery("")
	if err != nil {
		t.Fatalf("liveEncodeJobsQuery: %v", err)
	}
	want := `SELECT count(*) FROM river_job WHERE queue = ANY($1) AND state IN ('available','running','retryable')`
	if q != want {
		t.Fatalf("query = %q, want %q", q, want)
	}
}

func TestLiveEncodeJobsQuery_MediaSchema(t *testing.T) {
	q, err := liveEncodeJobsQuery("media_encoder")
	if err != nil {
		t.Fatalf("liveEncodeJobsQuery: %v", err)
	}
	want := `SELECT count(*) FROM "media_encoder"."river_job" WHERE queue = ANY($1) AND state IN ('available','running','retryable')`
	if q != want {
		t.Fatalf("query = %q, want %q", q, want)
	}
}

// TestHoldUntilDrained_RunningJobBlocksDrain verifies that the drain "empty"
// condition requires count == 0, which includes running jobs. This is the
// invariant that prevents the drain-vs-scale-down race: if the count function
// reports n > 0 (because state='running' jobs exist), the quiet timer is
// reset and the hold continues, keeping the instance alive while Work() is
// still executing a variant loop.
func TestHoldUntilDrained_RunningJobBlocksDrain(t *testing.T) {
	var calls atomic.Int32
	// Simulate: 1 running job for the first several polls (the job is mid-Work),
	// then drains to 0.
	count := func(context.Context) (int, error) {
		n := calls.Add(1)
		if n <= 8 {
			return 1, nil // 1 running job — quiet timer must NOT advance
		}
		return 0, nil
	}
	cfg := testDrainCfg(count)
	// quiet period is 10ms in testDrainCfg; we need to observe that the drain
	// does NOT complete while count > 0, even briefly.
	drained, reason := holdUntilDrained(context.Background(), cfg)
	if !drained || reason != "empty" {
		t.Fatalf("got (%v,%q), want (true,\"empty\") after running job clears", drained, reason)
	}
	// Must have polled past the 8-call threshold where count dropped to 0.
	if calls.Load() <= 8 {
		t.Fatalf("drain completed after only %d polls; expected to hold through the running phase", calls.Load())
	}
}

// TestHoldUntilDrained_QuietTimerResetsOnRunningJob verifies that a
// momentary dip to 0 followed by a re-appearance of running jobs (e.g. a
// new job picked up just as the previous one completed) resets the quiet
// timer. The drain must NOT release on the first zero-count sample alone.
func TestHoldUntilDrained_QuietTimerResetsOnRunningJob(t *testing.T) {
	var calls atomic.Int32
	// Pattern: 0, 0, 1 (new job), 0, 0, 0 ... → drain must wait for the quiet
	// period after the LAST zero, not after the first two zeros.
	count := func(context.Context) (int, error) {
		switch calls.Add(1) {
		case 1, 2:
			return 0, nil // briefly empty
		case 3:
			return 1, nil // new running job appears — quiet timer must reset
		default:
			return 0, nil // truly empty; quiet period now counts from here
		}
	}
	cfg := testDrainCfg(count)
	drained, reason := holdUntilDrained(context.Background(), cfg)
	if !drained || reason != "empty" {
		t.Fatalf("got (%v,%q), want (true,\"empty\")", drained, reason)
	}
	// Must have polled at least past call 3 (the re-appearance).
	if calls.Load() < 4 {
		t.Fatalf("drain completed after %d polls; should have reset quiet timer on call 3", calls.Load())
	}
}
