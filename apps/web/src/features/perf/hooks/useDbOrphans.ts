// useDbOrphans — fetches the orphan attribution report from
// GET /api/v1/sites/{siteId}/perf/db/orphans.
//
// The endpoint is not in the generated @wpmgr/api SDK so we call it via the
// raw `client.get` (same pattern as useDbHealth / usePerfConfig). staleTime is
// 2 minutes — the report only changes after a new scan, so aggressive
// re-fetching has no value.

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import type { OrphansReport } from "../types";

const STALE_MS = 2 * 60 * 1000; // 2 minutes

/**
 * Fetch the orphan attribution report for the given site.
 *
 * Returns null when no scan has been run yet (the backend responds with HTTP
 * 404 / error code "no_scan"). Callers should render a "run a scan" prompt for
 * the null case and reserve the error state for genuine failures.
 *
 * @param siteId - UUID of the site. The query is disabled when falsy so the
 *                 hook can be called unconditionally before a site is selected.
 */
export function useDbOrphans(
  siteId: string,
): UseQueryResult<OrphansReport | null, Error> {
  return useQuery({
    queryKey: perfKeys.dbOrphans(siteId),
    queryFn: async (): Promise<OrphansReport | null> => {
      const { data, error, response } = await client.get<OrphansReport, false>({
        url: `/api/v1/sites/${siteId}/perf/db/orphans`,
        credentials: "include",
      });
      // A 404 with code "no_scan" means the site has never been scanned.
      // Resolve to null so the UI can show guidance rather than an error card.
      if (response?.status === 404) return null;
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from db/orphans");
      return data;
    },
    staleTime: STALE_MS,
    enabled: Boolean(siteId),
  });
}
