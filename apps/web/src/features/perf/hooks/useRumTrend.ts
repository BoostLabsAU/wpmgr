import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { RumTrendResponse } from "../types";

// useRumTrend — fetches the daily p75 trend for all five CWV metrics from
// GET /api/v1/sites/{siteId}/perf/rum/trend?window_days=28&device=<device>.
//
// The endpoint is NOT in the generated @wpmgr/api SDK, so we call it via the
// raw `client.get` (same pattern as useCacheHealth / useDbHealth). The device
// and windowDays params are included in the query key so each combination is
// cached independently; switching the device tab in FleetRumPanel re-fetches
// via a new cache slot without invalidating the other slots.
//
// usePerfEvents invalidates perfKeys.rumTrend(siteId, ...) on
// rum.rollup_updated by using a prefix-match invalidation (the siteId prefix)
// so all device variants for the site refresh together.

const STALE_MS = 5 * 60 * 1000; // 5 minutes

export interface UseRumTrendOptions {
  /** Device class to filter by. "all" sends an empty string to the API. */
  device: "all" | "desktop" | "mobile" | "tablet";
  /** Lookback window in days. Default 28. */
  windowDays?: number;
}

export function useRumTrend(
  siteId: string,
  { device, windowDays = 28 }: UseRumTrendOptions,
): UseQueryResult<RumTrendResponse, Error> {
  // Map "all" to an empty string for the API query param (same convention used
  // by the summary endpoint — omitting the device filter returns the aggregate).
  const deviceParam = device === "all" ? "" : device;

  return useQuery({
    queryKey: perfKeys.rumTrend(siteId, deviceParam, windowDays),
    queryFn: async (): Promise<RumTrendResponse> => {
      const params = new URLSearchParams({ window_days: String(windowDays) });
      if (deviceParam) params.set("device", deviceParam);
      const { data, error } = await client.get<RumTrendResponse, false>({
        url: `/api/v1/sites/${siteId}/perf/rum/trend?${params.toString()}`,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from rum/trend");
      return data;
    },
    staleTime: STALE_MS,
    enabled: Boolean(siteId),
  });
}
