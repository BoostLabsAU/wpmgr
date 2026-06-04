// useDbHealth — fetches the 90-day DB-size trend from
// GET /api/v1/sites/{siteId}/perf/db/health.
//
// The endpoint is not yet in the generated @wpmgr/api SDK, so we call it via
// the raw `client.get` (same pattern as useDbScan / usePerfConfig). staleTime
// is 5 minutes — the history is append-only and only grows after a scan, so
// there is no value in aggressive re-fetching.

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { DBHealthResponse } from "../types";

const STALE_MS = 5 * 60 * 1000; // 5 minutes

/**
 * Fetch the DB-size trend + growth summary for the given site.
 *
 * @param siteId  - UUID of the site. The query is disabled when falsy so the
 *                  hook can be called unconditionally before a site is selected.
 * @param days    - Lookback window in days (default 90, clamped server-side to
 *                  [7, 365]).
 */
export function useDbHealth(
  siteId: string,
  days = 90,
): UseQueryResult<DBHealthResponse, Error> {
  return useQuery({
    queryKey: perfKeys.dbHealth(siteId),
    queryFn: async (): Promise<DBHealthResponse> => {
      const { data, error } = await client.get<DBHealthResponse, false>({
        url: `/api/v1/sites/${siteId}/perf/db/health?days=${days}`,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from db/health");
      return data;
    },
    staleTime: STALE_MS,
    enabled: Boolean(siteId),
  });
}
