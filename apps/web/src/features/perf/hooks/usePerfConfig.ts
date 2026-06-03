import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { PerfConfig } from "../types";

// Server-state hooks for the per-site Performance Suite config (Phase 7 / m36).
//
// These routes are hand-rolled (NOT in the generated @wpmgr/api SDK):
//   GET /api/v1/sites/:siteId/perf/config → PerfConfig
//   PUT /api/v1/sites/:siteId/perf/config ← Partial-ish PerfConfig → 200 PerfConfig
//
// Pattern mirrors features/admin/use-admin.ts EXACTLY: raw client.get/put with
// `toError` narrowing, TanStack Query, an OPTIMISTIC PUT mutation with rollback,
// and a toast.error on failure. Every settings change autosaves through
// useUpdatePerfConfig — the panel calls `save(patch)` and the optimistic update
// makes the toggle reflect immediately; a failure reverts and toasts.

function base(siteId: string): string {
  return `/api/v1/sites/${encodeURIComponent(siteId)}/perf`;
}

/** Fetch the site's performance config (GET /perf/config). */
export function usePerfConfig(
  siteId: string,
): UseQueryResult<PerfConfig, Error> {
  return useQuery({
    queryKey: perfKeys.config(siteId),
    queryFn: async () => {
      const r = await client.get({ url: `${base(siteId)}/config` });
      if (r.error) throw toError(r.error);
      if (!r.data) throw new Error("Empty response");
      return r.data as PerfConfig;
    },
  });
}

/**
 * Update the site's performance config (PUT /perf/config) with an optimistic
 * patch + rollback. The mutation variable is the FULL next config (the panel
 * spreads `{ ...data, ...patch }`). `cdn_credentials`, when present, is sent
 * verbatim and never echoed back; `cdn_has_credentials` is server-derived.
 *
 * Optimistic flow (the use-admin.ts + use-sites.ts pattern combined):
 *   onMutate  → cancel in-flight, snapshot, write the next config to the cache.
 *   onError   → restore the snapshot + toast.error (autosave failed → reverted).
 *   onSuccess → seed the cache with the server's authoritative config.
 *   onSettled → invalidate so the next read is fresh (and re-derives the
 *               agent-acked install-state fields).
 */
export function useUpdatePerfConfig(
  siteId: string,
): UseMutationResult<
  PerfConfig,
  Error,
  PerfConfig,
  { previous: PerfConfig | undefined }
> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (next: PerfConfig) => {
      const r = await client.put({ url: `${base(siteId)}/config`, body: next });
      if (r.error) throw toError(r.error);
      if (!r.data) throw new Error("Empty response");
      return r.data as PerfConfig;
    },
    onMutate: async (next) => {
      await qc.cancelQueries({ queryKey: perfKeys.config(siteId) });
      const previous = qc.getQueryData<PerfConfig>(perfKeys.config(siteId));
      // Never let the write-only credentials object leak into the read cache.
      const { cdn_credentials: _omit, ...optimistic } = next;
      qc.setQueryData<PerfConfig>(perfKeys.config(siteId), {
        ...optimistic,
        cdn_has_credentials:
          previous?.cdn_has_credentials || Boolean(next.cdn_credentials),
      });
      return { previous };
    },
    onError: (err, _next, context) => {
      if (context?.previous) {
        qc.setQueryData(perfKeys.config(siteId), context.previous);
      }
      toast.error("Could not save setting.", { description: err.message });
    },
    onSuccess: (saved) => {
      qc.setQueryData(perfKeys.config(siteId), saved);
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.config(siteId) });
    },
  });
}
