package main

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"time"

	"github.com/mosamlife/wpmgr/apps/api/internal/config"
)

// serveDegraded starts a minimal net/http server (no Gin, no DB, no River) on
// cfg.HTTPAddr and parks until ctx is cancelled. It is called when
// config.Validate reports one or more config issues so the container does NOT
// crash-loop and an operator can curl /readyz to see which env vars are wrong.
//
// Endpoints:
//
//	GET /healthz → 200 {"status":"ok"}     — process is alive.
//	GET /readyz  → 503 {"status":"degraded","checks":{<name>:<reason>,...}}
//
// SECRET-LEAK INVARIANT: the /readyz checks map and all slog output contain
// ONLY the curated Issue.Name + Issue.Reason strings from config.Validate;
// raw errors from crypto / DB paths are never surfaced here.
func serveDegraded(ctx context.Context, addr string, issues []config.Issue) error {
	// Build the checks map once — names + reasons only, no secret values.
	checks := make(map[string]string, len(issues))
	names := make([]string, 0, len(issues))
	for _, iss := range issues {
		checks[iss.Name] = iss.Reason
		names = append(names, iss.Name)
	}

	slog.Error("degraded boot: config issues detected — /readyz will return 503 until fixed",
		slog.Any("issues", names))

	mux := http.NewServeMux()

	// /healthz — process is alive.
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	})

	// /readyz — degraded, lists config problems (names + safe reasons only).
	readyzBody, _ := json.Marshal(map[string]any{
		"status": "degraded",
		"checks": checks,
	})
	mux.HandleFunc("/readyz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = w.Write(readyzBody)
	})

	srv := &http.Server{
		Addr:         addr,
		Handler:      mux,
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
		IdleTimeout:  30 * time.Second,
	}

	errCh := make(chan error, 1)
	go func() {
		slog.Info("degraded http server listening", slog.String("addr", addr))
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case err := <-errCh:
		return err
	case <-ctx.Done():
		slog.Info("degraded: shutdown signal received")
		shutCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		if err := srv.Shutdown(shutCtx); err != nil {
			slog.Warn("degraded: graceful shutdown error", slog.Any("error", err))
		}
		return nil
	}
}
