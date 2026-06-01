import {
  useInfiniteQuery,
  useQuery,
  type InfiniteData,
  type UseInfiniteQueryResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  listSiteActivity,
  verifySiteActivity,
  type SiteActivityEvent,
  type ActivityVerifyResult,
  type SiteActivityList,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// ADR-037 Sprint 3 — TanStack Query hooks for the WordPress activity log.
//
// useActivity drives the table (filtered, newest first). useActivityVerify
// feeds the top integrity badge: a server-side recomputation of the whole hash
// chain, returning either "verified" or the seq of the first tamper point.
//
// ADR-037 Sprint 6 — cursor-based infinite pagination. useActivity is now
// an infinite query with per-page limit=100 (server clamps at 500 max).
// Live-refresh tradeoff: refetchInterval is kept but only fires when the user
// has not yet paginated beyond the first page (pages.length <= 1). This means
// new entries appear automatically when viewing the default first page, while
// deep pagination is not churned by background refetches.

export type SeverityFilter = "all" | "high" | "medium" | "low";

export const activityKeys = {
  all: ["activity"] as const,
  forSite: (
    siteId: string,
    filters: ActivityFilters,
  ) => [...activityKeys.all, "site", siteId, filters] as const,
  verify: (siteId: string) =>
    [...activityKeys.all, "verify", siteId] as const,
};

export interface ActivityFilters {
  severity: SeverityFilter;
  objectType: string;
  actorLogin: string;
}

export interface UseActivityResult {
  items: SiteActivityEvent[];
  fetchNextPage: UseInfiniteQueryResult<InfiniteData<SiteActivityList>, Error>["fetchNextPage"];
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  isLoading: boolean;
  isPending: boolean;
  isError: boolean;
  error: Error | null;
  refetch: UseInfiniteQueryResult<InfiniteData<SiteActivityList>, Error>["refetch"];
}

export function useActivity(
  siteId: string,
  filters: ActivityFilters,
): UseActivityResult {
  const result = useInfiniteQuery<SiteActivityList, Error, InfiniteData<SiteActivityList>, ReturnType<typeof activityKeys.forSite>, string | undefined>({
    queryKey: activityKeys.forSite(siteId, filters),
    initialPageParam: undefined,
    getNextPageParam: (lastPage) => lastPage.next_cursor || undefined,
    queryFn: async ({ pageParam }) => {
      const { data, error } = await listSiteActivity({
        path: { siteId },
        query: {
          ...(filters.severity !== "all"
            ? { severity: filters.severity }
            : {}),
          ...(filters.objectType ? { object_type: filters.objectType } : {}),
          ...(filters.actorLogin ? { actor_login: filters.actorLogin } : {}),
          limit: 100,
          ...(pageParam !== undefined ? { cursor: pageParam } : {}),
        },
      });
      if (error) throw toError(error);
      return data ?? { items: [], next_cursor: undefined };
    },
    // Only poll while the user is still on the first page — deeper pages would
    // force a refetch of every loaded page on each interval tick, which is
    // wasteful and can disrupt scroll position.
    refetchInterval: (query) => {
      const pages = query.state.data?.pages;
      return pages && pages.length <= 1 ? 30_000 : false;
    },
  });

  const items = result.data?.pages.flatMap((p) => p.items) ?? [];

  return {
    items,
    fetchNextPage: result.fetchNextPage,
    hasNextPage: result.hasNextPage,
    isFetchingNextPage: result.isFetchingNextPage,
    isLoading: result.isLoading,
    isPending: result.isPending,
    isError: result.isError,
    error: result.error,
    refetch: result.refetch,
  };
}

export function useActivityVerify(
  siteId: string,
): UseQueryResult<ActivityVerifyResult, Error> {
  return useQuery({
    queryKey: activityKeys.verify(siteId),
    queryFn: async () => {
      const { data, error } = await verifySiteActivity({ path: { siteId } });
      if (error) throw toError(error);
      return data ?? { valid: true, total: 0 };
    },
    refetchInterval: 60_000,
  });
}
