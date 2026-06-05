import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  client,
  getCacheStats,
  purgeCache,
  preloadCache,
  enableCache,
  disableCache,
  cleanDatabase,
  clearRucss,
} from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { CacheStats, PerfActionResult, PurgeBody } from "../types";

// Cache-stats query + the cache action mutations (purge / preload / enable /
// disable / db-clean / rucss-clear). All routes now flow through the generated
// @wpmgr/api SDK fns (canonical paths owned by openapi.yaml) instead of inline
// `/api/v1/...` URL strings, so the frontend can no longer drift on the path.
// Pattern mirrors features/sites/use-login-brand.ts (generated SDK fn +
// `{ data, error }` + `toError`).
//
// The actions DON'T optimistically write — they return an {ok,detail} ack and
// the authoritative state lands via the cache.* / db.clean.completed SSE events
// (usePerfEvents invalidates the stats query). Each mutation toasts on error.

/** Fetch the latest cache gauges (GET /perf/cache/stats). */
export function useCacheStats(
  siteId: string,
): UseQueryResult<CacheStats, Error> {
  return useQuery({
    queryKey: perfKeys.stats(siteId),
    queryFn: async () => {
      const { data, error } = await getCacheStats({ path: { siteId } });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data as CacheStats;
    },
    staleTime: 10_000,
  });
}

/** POST /perf/cache/purge — purge all, a single URL, or delete-everything (admin). */
export function usePurgeCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, PurgeBody> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: PurgeBody) => {
      const { data, error } = await purgeCache({ path: { siteId }, body });
      if (error) throw toError(error);
      return data ?? { ok: false };
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not purge the cache.", { description: err.message }),
  });
}

/** POST /perf/cache/preload — start the cache preload pass. */
export function usePreloadCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await preloadCache({ path: { siteId } });
      if (error) throw toError(error);
      return (data as PerfActionResult) ?? { ok: false };
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not start preload.", { description: err.message }),
  });
}

/** POST /perf/cache/enable | /perf/cache/disable — toggle page caching. */
export function useToggleCache(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, boolean> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (enable: boolean) => {
      const { data, error } = enable
        ? await enableCache({ path: { siteId } })
        : await disableCache({ path: { siteId } });
      if (error) throw toError(error);
      return (data as PerfActionResult) ?? { ok: false };
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: perfKeys.config(siteId) });
      void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    },
    onError: (err) =>
      toast.error("Could not change caching.", { description: err.message }),
  });
}

/** POST /perf/db/clean — run the configured database cleanup now (all tasks). */
export function useDbClean(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, void> {
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await cleanDatabase({ path: { siteId } });
      if (error) throw toError(error);
      return (data as PerfActionResult) ?? { ok: false };
    },
    onError: (err) =>
      toast.error("Could not clean the database.", {
        description: err.message,
      }),
  });
}

/**
 * POST /perf/db/clean with an explicit `tasks` list — runs only the selected
 * categories. Used by the scan->preview->clean flow in DatabaseSection when the
 * operator ticks a subset of categories and clicks "Clean selected".
 *
 * The generated SDK's cleanDatabase() declares `body?: never`, so it cannot
 * carry the tasks array. We call the same URL via the raw `client.post` instead
 * (same pattern as useDbScan / useMediaSettings before SDK regen).
 */
export function useDbCleanSelected(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, string[]> {
  return useMutation({
    mutationFn: async (tasks: string[]) => {
      const { data, error } = await client.post<PerfActionResult, unknown, false>({
        url: `/api/v1/sites/${siteId}/perf/db/clean`,
        body: { tasks },
        credentials: "include",
      });
      if (error) throw toError(error);
      return (data as PerfActionResult) ?? { ok: false };
    },
    onError: (err) =>
      toast.error("Could not clean the database.", {
        description: err.message,
      }),
  });
}

/** POST /perf/rucss/clear — drop all cached Used-CSS results for the site. */
export function useClearRucss(
  siteId: string,
): UseMutationResult<{ ok: boolean; cleared: number }, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await clearRucss({ path: { siteId } });
      if (error) throw toError(error);
      return (
        (data as { ok: boolean; cleared: number }) ?? {
          ok: false,
          cleared: 0,
        }
      );
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
