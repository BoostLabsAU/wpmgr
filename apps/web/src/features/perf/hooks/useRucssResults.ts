import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { listRucssResults } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { RucssResult } from "../types";

// Paginated Used-CSS (RUCSS) results list. Now called through the generated
// @wpmgr/api SDK fn (canonical path /api/v1/sites/{siteId}/perf/rucss/results
// owned by openapi.yaml) with `query: { limit, offset }`, instead of an inline
// URL with a hand-built query string. Same `{ data, error }` + `toError`
// pattern as the other perf hooks. The page is passed as a 0-based index; the
// backend caps limit at 500 (we use 25).

const PAGE_SIZE = 25;

export function useRucssResults(
  siteId: string,
  page: number,
): UseQueryResult<RucssResult[], Error> {
  return useQuery({
    queryKey: [...perfKeys.rucss(siteId), page] as const,
    queryFn: async () => {
      const offset = page * PAGE_SIZE;
      const { data, error } = await listRucssResults({
        path: { siteId },
        query: { limit: PAGE_SIZE, offset },
      });
      if (error) throw toError(error);
      return (data?.items as RucssResult[] | undefined) ?? [];
    },
    placeholderData: (prev) => prev,
  });
}

export { PAGE_SIZE as RUCSS_PAGE_SIZE };
