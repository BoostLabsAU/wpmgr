import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  getPerfConfig,
  putPerfConfig,
  reprovisionRumBeaconKey,
} from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { PerfConfig } from "../types";

/** The keys that were changed in a single optimistic PUT. */
export interface PendingMutation {
  keys: ReadonlySet<string>;
}

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
  { previous: PerfConfig | undefined; patchKeys: Set<string> }
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
      // Infer which keys this mutation changed so onSuccess can do a safe merge.
      const patchKeys = new Set<string>(
        previous
          ? (Object.keys(next) as (keyof PerfConfig)[]).filter(
              (k) => next[k] !== previous[k],
            )
          : Object.keys(next),
      );
      // Never let the write-only credentials object leak into the read cache.
      const { cdn_credentials: _omit, ...optimistic } = next;
      qc.setQueryData<PerfConfig>(perfKeys.config(siteId), {
        ...optimistic,
        cdn_has_credentials:
          previous?.cdn_has_credentials || Boolean(next.cdn_credentials),
      });
      return { previous, patchKeys };
    },
    onError: (err, _next, context) => {
      if (context?.previous) {
        qc.setQueryData(perfKeys.config(siteId), context.previous);
      }
      toast.error("Could not save setting.", { description: err.message });
    },
    onSuccess: (saved, _next, context) => {
      // Merge: use the server's authoritative response as the base, but for keys
      // that were NOT part of this mutation, preserve the current cache value.
      // This prevents a concurrent later optimistic write from being reverted when
      // an earlier mutation's PUT response arrives with stale values for those keys.
      qc.setQueryData<PerfConfig>(perfKeys.config(siteId), (current) => {
        if (!current) return saved;
        const merged: PerfConfig = { ...saved };
        for (const key of Object.keys(current) as (keyof PerfConfig)[]) {
          if (!context.patchKeys.has(key)) {
            // Key was not changed by this mutation -- keep the current cache
            // value, which may reflect a later concurrent optimistic write.
            (merged as unknown as Record<string, unknown>)[key] = current[key];
          }
        }
        return merged;
      });
    },
  });
}

/** Rotate and push a fresh RUM beacon key, then refresh config state. */
export function useReprovisionRumBeaconKey(
  siteId: string,
): UseMutationResult<PerfConfig, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await reprovisionRumBeaconKey({
        path: { siteId },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data as PerfConfig;
    },
    onSuccess: (saved) => {
      qc.setQueryData<PerfConfig>(perfKeys.config(siteId), saved);
      void qc.invalidateQueries({ queryKey: perfKeys.config(siteId) });
      if (saved.rum_agent_beacon_key_set === true) {
        toast.success("RUM key reprovisioned.");
      } else {
        toast.info("RUM key reprovisioned.", {
          description: "Waiting for the agent to confirm the local key.",
        });
      }
    },
    onError: (err) => {
      toast.error("Could not reprovision RUM key.", {
        description: err.message,
      });
    },
  });
}
