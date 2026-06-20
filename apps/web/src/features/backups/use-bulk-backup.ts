import { useQueryClient } from "@tanstack/react-query";
import { createBackup, type BackupSnapshot } from "@wpmgr/api";
import { toError } from "@/features/auth/use-auth";
import { backupsKeys } from "./use-backups";

// Bounded concurrency for fan-out backup calls.
// Large fleets must not fire hundreds of parallel POSTs in one shot.
const CHUNK_SIZE = 6;

export interface BulkBackupResult {
  /** Site IDs for which a backup job was successfully enqueued. */
  enqueued: string[];
  /** Site IDs that already had a backup in flight (422 backup_already_in_flight). */
  skipped: string[];
  /** Site IDs where the call failed with a non-in-flight error. */
  failed: string[];
}

/**
 * isInFlight returns true when the API rejected the backup with the known
 * "backup_already_in_flight" code (HTTP 422 with that error body). We treat
 * this as "skipped (already running)", NOT a failure.
 */
export function isInFlightError(err: unknown): boolean {
  if (!(err instanceof Error)) return false;
  // The toError helper serialises the error body into the message string; the
  // backend sets code = "backup_already_in_flight" which becomes part of the
  // message. Match on it without relying on the exact body shape.
  return err.message.includes("backup_already_in_flight");
}

/**
 * Minimal dependency surface for runBulkBackup so the function can be tested
 * by injecting a mock createBackup. The default (useBulkBackup hook) wires the
 * live SDK call and QueryClient.
 */
export interface BulkBackupDeps {
  /**
   * Per-site backup creator. Must resolve with a BackupSnapshot on 202 and
   * reject with an Error on any failure. Should throw an error whose message
   * includes "backup_already_in_flight" for 422 responses with that code so
   * isInFlightError can classify them correctly.
   */
  createBackupFn: (siteId: string) => Promise<BackupSnapshot>;
  /** Query client used only to invalidate per-site backup list caches. */
  invalidateBackupList: (siteId: string) => void;
}

/**
 * Fan out a per-site backup create call over a list of site IDs with bounded
 * concurrency (CHUNK_SIZE = 6 at a time). Classifies each outcome:
 *
 *   enqueued — POST 202: a backup_snapshot River job is now in flight.
 *   skipped  — POST 422 backup_already_in_flight: treated as satisfying the
 *              "take a backup" intent rather than an error.
 *   failed   — any other error.
 *
 * Dependencies are injected so the function is pure-async-testable without
 * module mocking. The hook useBulkBackup() wires the live SDK and QueryClient.
 */
export async function runBulkBackup(
  siteIds: string[],
  deps: BulkBackupDeps,
): Promise<BulkBackupResult> {
  const enqueued: string[] = [];
  const skipped: string[] = [];
  const failed: string[] = [];

  // Process in chunks to bound parallelism.
  for (let offset = 0; offset < siteIds.length; offset += CHUNK_SIZE) {
    const chunk = siteIds.slice(offset, offset + CHUNK_SIZE);
    const results = await Promise.allSettled(
      chunk.map((siteId) => deps.createBackupFn(siteId)),
    );

    results.forEach((result, i) => {
      // chunk and results are always the same length (chunk.map → allSettled).
      const siteId = chunk[i] ?? "";
      if (result.status === "fulfilled") {
        enqueued.push(siteId);
        // Invalidate the site's backup list so new snapshots surface immediately.
        deps.invalidateBackupList(siteId);
      } else {
        if (isInFlightError(result.reason)) {
          skipped.push(siteId);
        } else {
          failed.push(siteId);
        }
      }
    });
  }

  return { enqueued, skipped, failed };
}

/**
 * Build the live BulkBackupDeps from the SDK and a QueryClient. Extracted so
 * callers (useBulkBackup, tests) can choose their own wiring.
 */
export function makeLiveDeps(
  queryClient: ReturnType<typeof useQueryClient>,
): BulkBackupDeps {
  return {
    createBackupFn: async (siteId: string): Promise<BackupSnapshot> => {
      const { data, error, response } = await createBackup({
        path: { siteId },
        body: { kind: "full" },
      });
      if (response?.status === 422) {
        throw toError(error ?? { message: "backup_already_in_flight" });
      }
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    invalidateBackupList: (siteId: string) => {
      void queryClient.invalidateQueries({
        queryKey: backupsKeys.listFor(siteId),
      });
    },
  };
}

/**
 * Hook wrapper so React components can call runBulkBackup with the live SDK
 * and QueryClient without needing to manage them directly.
 */
export function useBulkBackup() {
  const queryClient = useQueryClient();
  const deps = makeLiveDeps(queryClient);
  return (siteIds: string[]) => runBulkBackup(siteIds, deps);
}
