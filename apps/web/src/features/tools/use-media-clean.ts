import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  scanUnusedMedia,
  isolateUnusedMedia,
  restoreIsolatedMedia,
  deleteIsolatedMedia,
  listQuarantinedMedia,
  type MediaCleanCandidate,
  type MediaCleanScanResult,
  type MediaCleanIsolateResult,
  type MediaCleanRestoreResult,
  type MediaCleanDeleteResult,
  type MediaCleanQuarantineManifest,
  type MediaCleanQuarantineList,
} from "@wpmgr/api";
import { toError } from "@/features/auth/use-auth";

// use-media-clean.ts — Media Cleaner data hooks (#190).
//
// Flow:
//   1. SCAN       — GET  /api/v1/sites/{siteId}/media/clean/scan
//                   Fetches ALL unused candidates in a single request (offset=0,
//                   limit=SCAN_MAX). The agent collects the full unused list and
//                   returns it capped at SCAN_MAX. The web panel then paginates
//                   client-side — no refetch on page changes.
//   2. QUARANTINE — GET  /api/v1/sites/{siteId}/media/clean/quarantine
//                   Lists all active quarantine manifests for the site.
//                   Enables the Quarantine tab to survive page refreshes —
//                   previously isolated items are no longer lost.
//   3. ISOLATE    — POST /api/v1/sites/{siteId}/media/clean/isolate
//                   Moves selected files to quarantine (reversible).
//   4. RESTORE    — POST /api/v1/sites/{siteId}/media/clean/restore
//                   Reverses an isolate using quarantine manifest IDs.
//   5. DELETE     — POST /api/v1/sites/{siteId}/media/clean/delete
//                   Permanently removes quarantined files + attachment posts.

// ── Wire-contract extension types (#190 v2) ───────────────────────────────────
//
// The CP scan response now carries additional summary fields and an in-use
// attachment list. These fields are not yet present in the generated OpenAPI
// types (the spec lags behind the agent); we define them locally here and
// expose a richer result type that extends the generated one.
//
// Field names are kept snake_case to match the wire exactly.

/** One surface where a referenced attachment is used. */
export interface MediaCleanUsage {
  /** Surface identifier; see SURFACE_LABELS for the friendly label map. */
  surface: string;
  /** Source post/option/widget ID when applicable; null otherwise. */
  source_id: number | null;
  /** Human-readable label for the source (e.g. post title); null when unavailable. */
  source_label: string | null;
  /** WP admin edit URL for the source; null when not applicable. */
  edit_url: string | null;
  /** Extra detail (e.g. the matched URL path or a CSS class); null when absent. */
  detail: string | null;
}

/** An attachment that is actively referenced somewhere in the site. */
export interface MediaCleanReferenced {
  id: number;
  title: string;
  url: string;
  thumb: string | null;
  usages: MediaCleanUsage[];
}

/**
 * Extended scan result that includes the in-use attachment data introduced
 * alongside the v2 agent scanner. All new fields are optional so the panel
 * degrades gracefully against older agents that do not send them.
 */
export type MediaCleanScanResultV2 = MediaCleanScanResult & {
  /** Total attachments visited by the scanner (unused + in-use). */
  total_attachments?: number;
  /** Count of attachments actively referenced; equals referenced.length. */
  referenced_count?: number;
  /** Count of unused attachments; equals total. */
  unused_count?: number;
  /** In-use attachments with their usage surfaces. */
  referenced?: MediaCleanReferenced[];
};

export type { MediaCleanCandidate };
export type { MediaCleanScanResult };
export type { MediaCleanQuarantineManifest };
export type { MediaCleanQuarantineList };

/** Maximum candidates the agent returns in a single scan. Matches SCAN_MAX on the agent. */
export const SCAN_MAX = 500;

/** Candidates shown per client-side page in the results grid. */
export const CLIENT_PAGE_SIZE = 50;

// ============================================================
// UUID helper — crypto.randomUUID is available in all modern browsers
// and Node.js 14.17+. No external dep needed.
// ============================================================
function newJobId(): string {
  return crypto.randomUUID();
}

// ============================================================
// Query keys
// ============================================================

// The key is fixed (no offset) because we always fetch offset=0&limit=SCAN_MAX.
export const mediaCleanScanKey = (siteId: string) =>
  ["sites", siteId, "media-clean", "scan"] as const;

export const mediaCleanQuarantineKey = (siteId: string) =>
  ["sites", siteId, "media-clean", "quarantine"] as const;

// ============================================================
// Scan
// ============================================================

export interface ScanParams {
  // When false (default) the query stays dormant until the caller explicitly
  // calls refetch(). The scan is potentially expensive — never trigger it
  // automatically on mount.
  enabled?: boolean;
}

export function useMediaCleanScan(
  siteId: string,
  params: ScanParams = {},
): UseQueryResult<MediaCleanScanResultV2, Error> {
  const enabled = params.enabled ?? false;

  return useQuery({
    queryKey: mediaCleanScanKey(siteId),
    queryFn: async (): Promise<MediaCleanScanResultV2> => {
      // Fetch all candidates in one request. The agent collects the full unused
      // list (up to SCAN_MAX) and applies offset/limit to the unused results —
      // so offset=0 + limit=SCAN_MAX returns every unused candidate at once.
      const { data, error } = await scanUnusedMedia({
        path: { siteId },
        query: { offset: 0, limit: SCAN_MAX },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from media scan");
      // Cast to the extended type — the extra fields are optional so old
      // agents that do not send them degrade cleanly to undefined.
      return data as MediaCleanScanResultV2;
    },
    enabled,
    // The reference scan is read-only but potentially expensive — don't
    // refetch in the background; the operator triggers scans manually.
    staleTime: 5 * 60_000,
    gcTime: 10 * 60_000,
  });
}

// ============================================================
// Quarantine list
// ============================================================

/**
 * Fetches the server-side list of active quarantine manifests for the site.
 *
 * Enabled only when the caller opts in via `enabled` (the Quarantine tab
 * activates this). Spinner gated on `isFetching`, NOT `isPending`, so the
 * query never gets stuck in a pending state when results are already cached.
 */
export function useMediaCleanQuarantineList(
  siteId: string,
  opts: { enabled?: boolean } = {},
): UseQueryResult<MediaCleanQuarantineList, Error> {
  const enabled = opts.enabled ?? true;

  return useQuery({
    queryKey: mediaCleanQuarantineKey(siteId),
    queryFn: async (): Promise<MediaCleanQuarantineList> => {
      const { data, error } = await listQuarantinedMedia({
        path: { siteId },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from quarantine list");
      return data;
    },
    enabled,
    // Refresh when the window regains focus so stale items don't persist.
    staleTime: 30_000,
    gcTime: 5 * 60_000,
  });
}

// ============================================================
// Isolate
// ============================================================

export interface IsolateInput {
  attachmentIds: number[];
}

export function useMediaCleanIsolate(
  siteId: string,
): UseMutationResult<MediaCleanIsolateResult, Error, IsolateInput> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (
      input: IsolateInput,
    ): Promise<MediaCleanIsolateResult> => {
      const { data, error } = await isolateUnusedMedia({
        path: { siteId },
        body: {
          job_id: newJobId(),
          attachment_ids: input.attachmentIds,
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from media isolate");
      if (!data.ok) throw new Error(data.detail ?? "Isolate operation failed");
      return data;
    },
    onSuccess: () => {
      // Invalidate the scan list (isolated items should disappear from results)
      // and the quarantine list (new manifest is now live on the server).
      void qc.invalidateQueries({
        queryKey: ["sites", siteId, "media-clean"],
      });
    },
  });
}

// ============================================================
// Restore
// ============================================================

export interface RestoreInput {
  quarantineIds: string[];
}

export function useMediaCleanRestore(
  siteId: string,
): UseMutationResult<MediaCleanRestoreResult, Error, RestoreInput> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (
      input: RestoreInput,
    ): Promise<MediaCleanRestoreResult> => {
      const { data, error } = await restoreIsolatedMedia({
        path: { siteId },
        body: {
          job_id: newJobId(),
          quarantine_ids: input.quarantineIds,
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from media restore");
      if (!data.ok) throw new Error(data.detail ?? "Restore operation failed");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: ["sites", siteId, "media-clean"],
      });
    },
  });
}

// ============================================================
// Delete (permanent)
// ============================================================

export interface DeleteInput {
  quarantineIds: string[];
  // confirm MUST be the exact string "DELETE" — checked server-side and agent-side.
  confirm: "DELETE";
}

export function useMediaCleanDelete(
  siteId: string,
): UseMutationResult<MediaCleanDeleteResult, Error, DeleteInput> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (
      input: DeleteInput,
    ): Promise<MediaCleanDeleteResult> => {
      const { data, error } = await deleteIsolatedMedia({
        path: { siteId },
        body: {
          job_id: newJobId(),
          quarantine_ids: input.quarantineIds,
          confirm: input.confirm,
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from media delete");
      if (!data.ok) throw new Error(data.detail ?? "Delete operation failed");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: ["sites", siteId, "media-clean"],
      });
    },
  });
}
