import {
  useQuery,
  type UseQueryResult,
} from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { RucssResult } from "../types";

// Paginated Used-CSS (RUCSS) results list. Hand-rolled route:
//   GET /api/v1/sites/:siteId/perf/rucss/results?limit=&offset= → { items }
// Same raw client.get + toError pattern as the other perf hooks. The page is
// passed as a 0-based index; the backend caps limit at 500 (we use 25).

const PAGE_SIZE = 25;

export function useRucssResults(
  siteId: string,
  page: number,
): UseQueryResult<RucssResult[], Error> {
  return useQuery({
    queryKey: [...perfKeys.rucss(siteId), page] as const,
    queryFn: async () => {
      const offset = page * PAGE_SIZE;
      const r = await client.get({
        url: `/api/v1/sites/${encodeURIComponent(siteId)}/perf/rucss/results?limit=${PAGE_SIZE}&offset=${offset}`,
      });
      if (r.error) throw toError(r.error);
      return (r.data as { items: RucssResult[] }).items ?? [];
    },
    placeholderData: (prev) => prev,
  });
}

export { PAGE_SIZE as RUCSS_PAGE_SIZE };
