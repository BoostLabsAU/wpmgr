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
import type { CacheStats, PerfActionResult, PurgeBody } from "../types";

// Cache-stats query + the cache action mutations (purge / preload / enable /
// disable / db-clean / rucss-clear). All hand-rolled routes (NOT in the
// generated SDK); raw client.* + toError, mirroring features/admin/use-admin.ts.
//
// The actions DON'T optimistically write — they return an {ok,detail} ack and
// the authoritative state lands via the cache.* / db.clean.completed SSE events
// (usePerfEvents invalidates the stats query). Each mutation toasts on error.

function base(siteId: string): string {
  return `/api/v1/sites/${encodeURIComponent(siteId)}/perf`;
}

/** Fetch the latest cache gauges (GET /cache/stats). */
export function useCacheStats(
  siteId: string,
): UseQueryResult<CacheStats, Error> {
  return useQuery({
    queryKey: perfKeys.stats(siteId),
    queryFn: async () => {
      const r = await client.get({ url: `${base(siteId)}/cache/stats` });
      if (r.error) throw toError(r.error);
      if (!r.data) throw new Error("Empty response");
      return r.data as CacheStats;
    },
    staleTime: 10_000,
  });
}

/** POST /cache/purge — purge all, a single URL, or delete-everything (admin). */
export function usePurgeCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, PurgeBody> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: PurgeBody) => {
      const r = await client.post({
        url: `${base(siteId)}/cache/purge`,
        body,
      });
      if (r.error) throw toError(r.error);
      return (r.data as PerfActionResult) ?? { ok: false };
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not purge the cache.", { description: err.message }),
  });
}

/** POST /cache/preload — start the cache preload pass. */
export function usePreloadCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const r = await client.post({ url: `${base(siteId)}/cache/preload` });
      if (r.error) throw toError(r.error);
      return (r.data as PerfActionResult) ?? { ok: false };
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not start preload.", { description: err.message }),
  });
}

/** POST /cache/enable | /cache/disable — toggle page caching at the server. */
export function useToggleCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, boolean> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (enable: boolean) => {
      const r = await client.post({
        url: `${base(siteId)}/cache/${enable ? "enable" : "disable"}`,
      });
      if (r.error) throw toError(r.error);
      return (r.data as PerfActionResult) ?? { ok: false };
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.config(siteId) });
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not change caching.", { description: err.message }),
  });
}

/** POST /db/clean — run the configured database cleanup now. */
export function useDbClean(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, void> {
  return useMutation({
    mutationFn: async () => {
      const r = await client.post({ url: `${base(siteId)}/db/clean` });
      if (r.error) throw toError(r.error);
      return (r.data as PerfActionResult) ?? { ok: false };
    },
    onError: (err) =>
      toast.error("Could not clean the database.", {
        description: err.message,
      }),
  });
}

/** POST /rucss/clear — drop all cached Used-CSS results for the site. */
export function useClearRucss(
  siteId: string,
): UseMutationResult<{ ok: boolean; cleared: number }, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const r = await client.post({ url: `${base(siteId)}/rucss/clear` });
      if (r.error) throw toError(r.error);
      return (r.data as { ok: boolean; cleared: number }) ?? {
        ok: false,
        cleared: 0,
      };
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
    },
    onError: (err) =>
      toast.error("Could not clear Used-CSS results.", {
        description: err.message,
      }),
  });
}
