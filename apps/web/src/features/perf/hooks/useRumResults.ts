import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { listRumResults } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { RumResult } from "../types";

// Per-URL / per-device / per-country Core Web Vitals breakdown rows
// (GET /perf/rum). Mirrors useFontResults: generated SDK fn, { data, error } +
// toError, perfKeys.rum query key.
//
// No pagination: the API returns all rows for the site bounded by the
// cardinality caps (max_distinct_urls x metrics x devices x countries), so the
// list is finite and bounded. Consumers sort/filter client-side.
//
// Rows with suppressed=true carry a 0 p75_ms and must render
// "insufficient samples (N of M needed)" — the server withheld the estimate
// because sample_count < min_sample_count. NEVER display a p75 number for a
// suppressed row; doing so would report noise as a metric.

const WINDOW_DAYS = 28;

export function useRumResults(
  siteId: string,
): UseQueryResult<RumResult[], Error> {
  return useQuery({
    queryKey: perfKeys.rum(siteId),
    queryFn: async () => {
      const { data, error } = await listRumResults({
        path: { siteId },
        query: { window_days: WINDOW_DAYS },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
  });
}
