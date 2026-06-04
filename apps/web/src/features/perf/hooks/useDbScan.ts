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
// The web layer drives the preview UI via the Zustand db-scan store, which is
// updated by usePerfEvents when those SSE frames arrive. The mutation here is
// fire-and-forget: SSE frames are the authoritative UI signal, not the mutation
// result. (The mutation's onError is a fallback for a complete transport failure
// before any SSE frame could arrive.)
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
      }
      // ok=true: the SSE db.scan.completed frame carries the result and drives
      // the store transition to "completed". No action needed here.
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
