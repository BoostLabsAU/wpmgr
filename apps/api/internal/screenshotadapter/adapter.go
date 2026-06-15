// Package screenshotadapter bridges the site and screenshot packages without
// creating a circular import. It implements site.ScreenshotEnricher using
// screenshot.Repo and screenshot.Presigner, and is wired in cmd/wpmgr at boot.
package screenshotadapter

import (
	"context"
	"log/slog"
	"time"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/screenshot"
	"github.com/mosamlife/wpmgr/apps/api/internal/site"
)

// Enricher implements site.ScreenshotEnricher. It fetches the screenshot rows
// for all listed sites in a single batched query and mints presigned GET URLs.
type Enricher struct {
	repo  screenshot.Repo
	store screenshot.Presigner
	ttl   time.Duration
}

// New builds an Enricher.
func New(repo screenshot.Repo, store screenshot.Presigner) *Enricher {
	return &Enricher{repo: repo, store: store, ttl: screenshot.DefaultPresignTTL}
}

// EnrichSites fetches screenshot rows for all sites in a single batched query
// and populates ScreenshotURL, ScreenshotURL2x, ScreenshotStatus,
// ScreenshotCapturedAt, and ScreenshotFailedReason on each site.
//
// Satisfies site.ScreenshotEnricher.
func (e *Enricher) EnrichSites(ctx context.Context, tenantID uuid.UUID, sites []site.Site) error {
	if len(sites) == 0 {
		return nil
	}
	ids := make([]uuid.UUID, len(sites))
	for i := range sites {
		ids[i] = sites[i].ID
	}
	rows, err := e.repo.ListForSites(ctx, tenantID, ids)
	if err != nil {
		slog.Warn("screenshotadapter: list for sites failed", slog.Any("error", err))
		return nil // non-fatal: screenshot enrichment is best-effort
	}
	byID := make(map[uuid.UUID]screenshot.Screenshot, len(rows))
	for _, row := range rows {
		byID[row.SiteID] = row
	}
	for i := range sites {
		row, ok := byID[sites[i].ID]
		if !ok {
			continue // no screenshot row yet → leave fields nil
		}
		// Always surface the status (even when pending or failed).
		s := row.Status
		sites[i].ScreenshotStatus = &s
		sites[i].ScreenshotCapturedAt = row.CapturedAt
		sites[i].ScreenshotFailedReason = row.FailedReason

		// Only presign GET URLs when the screenshot is ready (keys are non-empty).
		if row.Status == "ready" && row.ScreenshotKey != "" {
			url1x, err := e.store.PresignGet(ctx, row.ScreenshotKey, e.ttl)
			if err != nil {
				slog.Warn("screenshotadapter: presign 1x failed",
					slog.String("site_id", row.SiteID.String()),
					slog.Any("error", err))
				continue
			}
			sites[i].ScreenshotURL = &url1x

			if row.ScreenshotKey2x != "" {
				url2x, err2 := e.store.PresignGet(ctx, row.ScreenshotKey2x, e.ttl)
				if err2 == nil {
					sites[i].ScreenshotURL2x = &url2x
				}
			}
		}
	}
	return nil
}
