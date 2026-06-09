import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { getRumSummary } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { RumSummary } from "../types";

// Site-level Core Web Vitals p75 summary (GET /perf/rum/summary).
// Mirrors useFontResults: generated SDK fn, { data, error } + toError pattern,
// perfKeys.rumSummary query key.
//
// Uses a 28-day window (same as CrUX / Search Console) so the operator can
// compare against field data from other tools. The window is held constant;
// the backend default is also 28 days so we omit the query param on first load.
//
// RumSummary contains a flat `metrics` array of RumMetricSummary objects keyed
// by (metric, device, country). Consumers must check suppressed=true and render
// "insufficient samples" rather than the p75 value for those rows.

const WINDOW_DAYS = 28;

export function useRumSummary(
  siteId: string,
): UseQueryResult<RumSummary, Error> {
  return useQuery({
    queryKey: perfKeys.rumSummary(siteId),
    queryFn: async () => {
      const { data, error } = await getRumSummary({
        path: { siteId },
        query: { window_days: WINDOW_DAYS },
      });
      if (error) throw toError(error);
      return data ?? {};
    },
  });
}

export { WINDOW_DAYS as RUM_WINDOW_DAYS };
