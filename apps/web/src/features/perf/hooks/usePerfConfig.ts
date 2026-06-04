import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import { getPerfConfig, putPerfConfig } from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { PerfConfig } from "../types";

// Server-state hooks for the per-site Performance Suite config (Phase 7 / m36).
//
// Routes are now described in packages/openapi/openapi.yaml and called through
// the generated @wpmgr/api SDK fns (getPerfConfig / putPerfConfig) instead of
// inline `/api/v1/...` URL strings. The SDK owns the canonical path
// (/api/v1/sites/{siteId}/perf/config), so the frontend can no longer drift
// from the backend on it. The component-facing PerfConfig type (with required
// fields) stays in ../types; the generated response type marks every field
// optional, so we narrow with a cast at the boundary exactly as before.
//
// Pattern mirrors features/sites/use-login-brand.ts (generated SDK fn +
// `{ data, error }` + `toError`) combined with use-admin.ts's OPTIMISTIC PUT
// with rollback. Every settings change autosaves through useUpdatePerfConfig —
// the panel calls `save(patch)` and the optimistic update makes the toggle
// reflect immediately; a failure reverts and toasts.

/** Fetch the site's performance config (GET /perf/config). */
export function usePerfConfig(
  siteId: string,
): UseQueryResult<PerfConfig, Error> {
  return useQuery({
    queryKey: perfKeys.config(siteId),
    queryFn: async () => {
      const { data, error } = await getPerfConfig({ path: { siteId } });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data as PerfConfig;
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
      const { data, error } = await putPerfConfig({
        path: { siteId },
        body: next,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data as PerfConfig;
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
      // Authoritative: the PUT returns the persisted config, so the read cache is
      // already correct here. We deliberately do NOT invalidate the config query
      // afterwards — a background refetch re-renders all 30+ Optimize toggles on
      // every single save, which reads as a flicker / momentary revert of the
      // switch you just flipped. Server-side reconciliation (e.g. an agent
      // config-ack) still arrives via the perf.config SSE handler in usePerfEvents.
      qc.setQueryData(perfKeys.config(siteId), saved);
    },
  });
}
