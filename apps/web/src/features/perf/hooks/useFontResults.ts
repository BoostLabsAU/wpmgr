import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { listFontResults } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { FontResult } from "../types";

// Paginated font results catalog. Calls the generated listFontResults SDK fn
// (canonical path /api/v1/sites/{siteId}/perf/fonts, owned by openapi.yaml)
// with `query: { limit, offset }`. Same { data, error } + toError pattern as
// useRucssResults. The page is passed as a 0-based index; the backend caps
// limit at 500 (we use 50 — font counts per site are small).
//
// FontResult in types.ts is a re-export of the generated API type (single
// source of truth), so no cast is needed at the query boundary.

const PAGE_SIZE = 50;

export function useFontResults(
  siteId: string,
  page: number,
): UseQueryResult<FontResult[], Error> {
  return useQuery({
    queryKey: [...perfKeys.fonts(siteId), page] as const,
    queryFn: async () => {
      const offset = page * PAGE_SIZE;
      const { data, error } = await listFontResults({
        path: { siteId },
        query: { limit: PAGE_SIZE, offset },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    placeholderData: (prev) => prev,
  });
}

export { PAGE_SIZE as FONT_PAGE_SIZE };
