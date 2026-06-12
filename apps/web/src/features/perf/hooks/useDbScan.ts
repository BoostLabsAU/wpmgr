import { useCallback, useEffect } from "react";
import { useMutation, type UseMutationResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { useDbScanStore } from "../stores/dbScanStore";
import type { DbScanCategoryResult, DbScanTableInventoryRow } from "../stores/dbScanStore";

// useDbScan — triggers a read-only database scan (POST /perf/db/scan).
//
// The db_scan endpoint is synchronous: the full per-category COUNT + bytes
// result is returned in the HTTP ACK body. The CP emits three SSE events:
//   db.scan.started   — before sending the command to the agent
//   db.scan.completed — after the ACK returns ok=true (with full payload)
//   db.scan.failed    — on ok=false, transport error, or watchdog timeout
//
// Push-is-a-hint / pull-is-the-truth: onSuccess tries to drive the store
// directly from the ACK body (CP Phase 2.2+). This means the UI transitions
// to "completed" without waiting for the SSE db.scan.completed frame, which
// is critical for correctness when the stream is reconnecting (LB 900 s cut,
// API deploy, visibility-change reconnect). When the SSE frame arrives later
// it is a no-op because completeScan ignores duplicate job_ids.
//
// The CP route is POST /api/v1/sites/{siteId}/perf/db/scan — not yet in the
// generated @wpmgr/api SDK, so we call it via the raw `client.post` (same
// pattern as useMediaSettings.ts / usePerfConfig.ts before SDK regeneration).

/** The shape the CP returns in the db_scan ACK. */
export interface DbScanAck {
  ok: boolean;
  job_id: string;
  detail?: string;
  categories?: Record<string, DbScanCategoryResult>;
  /** Full per-table inventory added in Phase 2.1. May be absent on older CP versions. */
  tables?: DbScanTableInventoryRow[];
  db_size_bytes?: number;
  table_count?: number;
  scanned_at?: number;
}

/**
 * GET /api/v1/sites/{siteId}/perf/db/scan — the last stored scan result plus
 * live-job fields added by the CP (scan_active, active_job_id, active_started_at).
 * Not in the generated SDK yet; called via raw client.get.
 */
export interface DbScanGetResponse {
  /** True when the CP has an active scan job in flight for this site. */
  scan_active?: boolean;
  /** job_id of the active job (present when scan_active=true). */
  active_job_id?: string;
  /** Unix timestamp the active job started (present when scan_active=true). */
  active_started_at?: number;
  /** Last stored scan result fields (absent when no scan has run). */
  categories?: Record<string, DbScanCategoryResult>;
  tables?: DbScanTableInventoryRow[];
  db_size_bytes?: number;
  table_count?: number;
  scanned_at?: number;
}

/**
 * Hydration threshold: if a scan was started more than 3 minutes ago and the
 * stream hasn't delivered a completed/failed frame yet, the CP watchdog will
 * time it out at ~3 minutes — we match that so we never show an infinite spinner
 * for a watchdog-killed job whose failed SSE frame was missed.
 */
const ACTIVE_JOB_STALE_MS = 3 * 60 * 1000;

/**
 * Fetch the stored scan result from the server and populate the dbScanStore.
 * Returns a stable `hydrate` function the caller can invoke on reconnect.
 *
 * The GET endpoint returns the last stored result plus live-job fields:
 *   scan_active, active_job_id, active_started_at
 *
 * Three scenarios this resolves:
 *   1. Page refresh: last scan result is immediately visible without re-scanning.
 *   2. Scan completed while the stream was down: on mount the result is already
 *      in the store and tabs render normally.
 *   3. Stale active job: the CP watchdog killed the job but the failed frame was
 *      lost. We pre-emptively fail so the operator can retry.
 */
export function useDbScanHydration(siteId: string): () => void {
  const hydrate = useCallback(async () => {
    let resp: DbScanGetResponse | null = null;
    try {
      const { data, error } = await client.get<DbScanGetResponse, unknown, false>({
        url: `/api/v1/sites/${siteId}/perf/db/scan`,
        credentials: "include",
      });
      if (error || !data) return;
      resp = data;
    } catch {
      // Network failure during hydration — silently ignore; the store keeps
      // whatever state it has (idle, or a previously-hydrated result).
      return;
    }

    const s = useDbScanStore.getState();
    const current = s.bySite[siteId];

    // If the CP says a scan is active, reflect that in the store — but only
    // when the store is idle (don't overwrite a just-started optimistic scan).
    if (resp.scan_active && resp.active_job_id) {
      const startedAt =
        typeof resp.active_started_at === "number"
          ? resp.active_started_at * 1000 // unix seconds to ms
          : 0;
      const ageMs = Date.now() - startedAt;
      if (ageMs < ACTIVE_JOB_STALE_MS) {
        // Scan is genuinely in flight; show the spinner if not already tracking.
        if (!current || current.phase === null) {
          s.startScan(siteId, resp.active_job_id, []);
        }
      } else {
        // Scan started too long ago; the CP watchdog should have (or will soon)
        // emit db.scan.failed. Pre-emptively fail so the UI isn't stuck.
        if (!current || current.phase === "scanning") {
          s.failScan(
            siteId,
            resp.active_job_id,
            "Scan timed out — the agent did not respond within the expected window.",
          );
        }
      }
      return;
    }

    // No active job. Restore the last stored result into the store, but only
    // when the store is currently idle so we never overwrite live SSE state.
    if (!current || current.phase === null) {
      if (
        resp.categories &&
        typeof resp.db_size_bytes === "number" &&
        typeof resp.table_count === "number" &&
        typeof resp.scanned_at === "number"
      ) {
        s.completeScan(
          siteId,
          // No job_id for historical stored results. completeScan guards on
          // job_id only when prev.job_id !== null, so "" is accepted freely
          // for a truly idle store.
          "",
          resp.categories,
          resp.db_size_bytes,
          resp.table_count,
          resp.scanned_at,
          Array.isArray(resp.tables) ? resp.tables : [],
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

/** Trigger a database scan for the given site. */
export function useDbScan(
  siteId: string,
): UseMutationResult<DbScanAck, Error, void> {
  const startScan = useDbScanStore((s) => s.startScan);
  const failScan = useDbScanStore((s) => s.failScan);

  return useMutation({
    mutationFn: async (): Promise<DbScanAck> => {
      const { data, error } = await client.post<DbScanAck, unknown, false>({
        url: `/api/v1/sites/${siteId}/perf/db/scan`,
        body: {},
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from db_scan");
      return data;
    },
    onMutate: () => {
      // Optimistic: show "scanning" immediately. The real db.scan.started SSE
      // frame will arrive shortly and confirm (or the mutation's onError resets).
      // We do NOT know the job_id yet, so we pass an empty string — the store
      // accepts job_id='' as a sentinel and the SSE startScan call will overwrite.
      startScan(siteId, "", []);
    },
    onSuccess: (ack) => {
      if (!ack.ok) {
        // Agent refused the scan request: the CP did not emit db.scan.started, so
        // the store is stuck in "scanning". Transition to failed now.
        failScan(siteId, ack.job_id ?? "", ack.detail ?? "Scan was refused.");
        toast.error("Database scan failed.", {
          description: ack.detail ?? "The agent refused the request.",
        });
        return;
      }
      // Push-is-a-hint / pull-is-the-truth: if the ACK body already carries the
      // full result (backend agent Phase 2.2+), populate the store immediately so
      // the UI does NOT depend on the db.scan.completed SSE frame arriving. The
      // frame is still emitted by the CP and will be a no-op (job_id matches,
      // completeScan is idempotent). Older CP versions return ok=true with no
      // category payload, so we guard all fields before calling completeScan.
      if (
        ack.ok &&
        ack.categories &&
        typeof ack.db_size_bytes === "number" &&
        typeof ack.table_count === "number" &&
        typeof ack.scanned_at === "number"
      ) {
        const rawTables = Array.isArray(ack.tables) ? ack.tables : [];
        useDbScanStore.getState().completeScan(
          siteId,
          ack.job_id ?? "",
          ack.categories,
          ack.db_size_bytes,
          ack.table_count,
          ack.scanned_at,
          rawTables,
        );
      }
      // If the fields are absent (older CP), the SSE db.scan.completed frame
      // remains the authoritative signal. No action needed.
    },
    onError: (err) => {
      // Complete transport failure before any SSE frame: reset to idle so the
      // UI can try again.
      useDbScanStore.getState().reset(siteId);
      toast.error("Could not scan the database.", {
        description: err.message,
      });
    },
  });
}
