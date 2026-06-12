import { useCallback, useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useFontsStore, selectFonts } from "../fonts-store";
import { perfKeys } from "../perf-keys";
import type { FontResult } from "../types";

// useFontsBannerHydration — reconciles the fontsStore live banner with the
// TanStack Query font-results cache on mount and on SSE reconnect.
//
// The fonts banner (queued/converting phase in fontsStore) has NO authoritative
// server endpoint to hydrate from: the server does not expose a "is a font
// batch currently running?" field. The banner is a pure SSE accelerator — it
// shows progress during a conversion pass and self-clears on font.completed or
// font.failed. If a font.completed frame is missed (stream drop), the 120 s
// stale backstop in FontsLiveIndicator eventually clears it.
//
// This hook adds a second defensive line: on mount and on reconnect, if the
// banner is in an active phase (queued or converting) but the font-results
// query data shows NO fonts in a terminal in-flight state (i.e. no rows are
// still "pending" — the only DB state that means a job is outstanding), we
// clear the banner immediately and invalidate the fonts query so the table
// refreshes from the server, reflecting the true post-conversion state.
//
// What "in-flight" means in the DB state vocab (from types.ts):
//   pending  — job enqueued, not yet complete (server has an outstanding task)
//   ready    — full WOFF2 produced (terminal success)
//   subset   — subset WOFF2 produced (terminal success)
//   negative — permanent failure (terminal failure)
//
// If any row is still "pending", a conversion batch may be genuinely running,
// so we do NOT clear the banner — we let the SSE event (or the 120 s backstop)
// do it. If all rows are terminal (or the table is empty), the banner is stale
// and we clear it.
//
// We intentionally do NOT synthesise banner state from query data — the banner
// is a pure SSE accelerator and the authoritative display is the table.

/** DB states that indicate a font row is still waiting for processing. */
const IN_FLIGHT_DB_STATES: Array<FontResult["state"]> = ["pending"];

/**
 * Returns true when the fonts query data contains at least one row that is
 * still in a pending DB state, meaning a conversion batch may be running.
 */
function hasPendingFonts(items: FontResult[]): boolean {
  return items.some((r) => IN_FLIGHT_DB_STATES.includes(r.state));
}

/**
 * Reconcile the fontsStore banner against the current fonts query cache.
 * Call on mount and on SSE reconnect to clear a stale banner left by a missed
 * font.completed or font.failed frame.
 *
 * @param siteId  - The site to check.
 * @param qc      - The TanStack QueryClient (for invalidation + cache access).
 */
export function useFontsBannerHydration(siteId: string): () => void {
  const qc = useQueryClient();

  const reconcile = useCallback(() => {
    const live = selectFonts(useFontsStore.getState(), siteId);

    // Only act when the banner is in an active phase.
    if (live.phase !== "queued" && live.phase !== "converting") return;

    // Read the current fonts query cache synchronously. If there is no cached
    // data yet (first mount before the query resolves) we cannot conclude
    // anything — leave the banner alone and let the stale backstop handle it.
    const cached = qc.getQueryData<FontResult[]>(
      [...perfKeys.fonts(siteId), 0] as const,
    );
    if (!cached) return;

    // If any row is still pending, a batch may genuinely be running.
    if (hasPendingFonts(cached)) return;

    // All rows are terminal (or the table is empty) — the banner is stale.
    // Clear it and invalidate the query so the table reflects server truth.
    useFontsStore.getState().reset(siteId);
    void qc.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
  }, [siteId, qc]);

  // Run on mount.
  useEffect(() => {
    reconcile();
  }, [reconcile]);

  // Return a stable void wrapper for useSiteReconnect.
  return useCallback(() => {
    reconcile();
  }, [reconcile]);
}
