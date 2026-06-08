// useCacheHealth — fetches the cache hit-ratio trend from
// GET /api/v1/sites/{siteId}/perf/cache/health?days=<7|30|90>.
//
// The endpoint is not in the generated @wpmgr/api SDK, so we call it via
// the raw `client.get` (same pattern as useDbHealth). staleTime is 5 minutes
// — the history only grows when the agent reports new hit/miss samples.
//
// Note: `days` is included in the query key so each window (7/30/90) is cached
// independently and the WindowToggle drives a separate fetch per selection.
// usePerfEvents invalidates perfKeys.cacheHealth(siteId) on cache.* events,
// which clears all window variants simultaneously (prefix-match).

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { CacheHealthResponse } from "../types";

const STALE_MS = 5 * 60 * 1000; // 5 minutes

/**
 * Fetch the cache hit-ratio trend for the given site.
 *
 * @param siteId  - UUID of the site. The query is disabled when falsy.
 * @param days    - Lookback window in days (7, 30, or 90; default 7).
 */
export function useCacheHealth(
  siteId: string,
  days: 7 | 30 | 90 = 7,
): UseQueryResult<CacheHealthResponse, Error> {
  return useQuery({
    // Include days so each window is cached separately.
    queryKey: [...perfKeys.cacheHealth(siteId), days],
    queryFn: async (): Promise<CacheHealthResponse> => {
      const { data, error } = await client.get<CacheHealthResponse, false>({
        url: `/api/v1/sites/${siteId}/perf/cache/health?days=${days}`,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from cache/health");
      return data;
    },
    staleTime: STALE_MS,
    enabled: Boolean(siteId),
  });
}
