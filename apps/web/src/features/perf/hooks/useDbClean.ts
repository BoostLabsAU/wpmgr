import { useCallback, useEffect } from "react";
import { client } from "@wpmgr/api";

import { useDbCleanStore } from "../db-clean-store";

// useDbCleanHydration — reconciles the dbCleanStore with the server's last
// recorded clean result on mount and on SSE reconnect, closing the gap where a
// db.clean.completed or db.clean.failed frame was missed while the stream was
// down (LB 900 s cut, API deploy, visibility-change reconnect).
//
// The new GET /api/v1/sites/{siteId}/perf/db/clean endpoint returns:
//   {
//     clean_active:      bool,
//     active_job_id:     string | null,
//     active_started_at: RFC3339 | null,
//     last_result: {
//       job_id, rows_deleted, bytes_freed,
//       result: Record<category, {rows_deleted, bytes_freed, state}>,
//       cleaned_at
//     } | null
//   }
//
// Three scenarios this resolves:
//   1. Page refresh: last clean result is immediately visible without re-cleaning.
//   2. Clean completed while the stream was down: on mount the result is already
//      in the store and the operator sees the summary without waiting for SSE.
//   3. Stale active job: the CP watchdog killed the job but the failed frame was
//      lost. We pre-emptively fail so the operator can retry.
//
// The endpoint is NOT yet in the generated @wpmgr/api SDK; called via raw
// client.get exactly as useDbScanHydration does for db/scan.

/** Response shape from GET /api/v1/sites/{siteId}/perf/db/clean */
export interface DbCleanGetResponse {
  /** True when the CP has an active clean job in flight for this site. */
  clean_active?: boolean;
  /** job_id of the active job (present when clean_active=true). */
  active_job_id?: string | null;
  /**
   * RFC 3339 timestamp the active job started (present when clean_active=true).
   * The endpoint returns a string, not a unix timestamp, matching the openapi
   * convention for RFC 3339 fields. We parse it via Date.parse.
   */
  active_started_at?: string | null;
  /** Last completed/failed clean result. null when no clean has run yet. */
  last_result?: DbCleanLastResult | null;
}

export interface DbCleanLastResult {
  job_id: string;
  rows_deleted: number;
  bytes_freed: number;
  result: Record<string, { rows_deleted: number; bytes_freed: number; state: string }>;
  cleaned_at: string;
}

/**
 * Hydration threshold: if a clean was started more than 3 minutes ago and the
 * stream hasn't delivered a completed/failed frame yet, the CP watchdog will
 * time it out at ~3 minutes. We match that so we never show an infinite spinner
 * for a watchdog-killed job whose failed SSE frame was missed.
 */
const ACTIVE_JOB_STALE_MS = 3 * 60 * 1000;

/**
 * Fetch the server's last clean result and populate the dbCleanStore. Returns a
 * stable `hydrate` callback the caller can invoke on SSE reconnect.
 *
 * Never clobbers live SSE state: the store is only written when it is currently
 * idle (phase === null) or when an active-but-stale job is detected.
 */
export function useDbCleanHydration(siteId: string): () => void {
  const hydrate = useCallback(async () => {
    let resp: DbCleanGetResponse | null = null;
    try {
      const { data, error } = await client.get<DbCleanGetResponse, unknown, false>({
        url: `/api/v1/sites/${siteId}/perf/db/clean`,
        credentials: "include",
      });
      if (error || !data) return;
      resp = data;
    } catch {
      // Network failure during hydration — silently ignore; the store keeps
      // whatever state it has (idle or a previously-hydrated result).
      return;
    }

    const s = useDbCleanStore.getState();
    const current = s.bySite[siteId];

    if (resp.clean_active && resp.active_job_id) {
      const startedAt =
        typeof resp.active_started_at === "string" && resp.active_started_at
          ? Date.parse(resp.active_started_at)
          : 0;
      const ageMs = startedAt > 0 ? Date.now() - startedAt : Infinity;

      if (ageMs < ACTIVE_JOB_STALE_MS) {
        // Job is genuinely in flight — show the running state if the store is
        // idle (don't overwrite a just-started optimistic job).
        if (!current || current.phase === null) {
          s.startJob(siteId, resp.active_job_id, [], "manual");
        }
      } else {
        // Job started too long ago; the CP watchdog should have (or will soon)
        // emit db.clean.failed. Pre-emptively fail so the UI isn't stuck.
        if (!current || current.phase === "running") {
          s.failJob(
            siteId,
            resp.active_job_id,
            "Cleanup timed out — the agent did not respond within the expected window.",
          );
        }
      }
      return;
    }

    // No active job. Restore the last stored result into the store, but only
    // when the store is currently idle so we never overwrite live SSE state.
    if (!current || current.phase === null) {
      const lr = resp.last_result;
      if (lr && typeof lr.job_id === "string" && lr.job_id) {
        s.completeJob(
          siteId,
          // Pass "" so the sentinel guard in completeJob accepts it freely for a
          // truly idle store, consistent with useDbScanHydration's convention.
          "",
          lr.rows_deleted,
          lr.bytes_freed,
          lr.result,
        );
      }
    }
  }, [siteId]);

  // Hydrate on mount.
  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  // Return a void wrapper so callers (e.g. useSiteReconnect) receive a
  // () => void compatible function rather than () => Promise<void>.
  return useCallback(() => {
    void hydrate();
  }, [hydrate]);
}
