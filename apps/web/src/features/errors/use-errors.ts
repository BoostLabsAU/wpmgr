import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
  type InfiniteData,
  type UseInfiniteQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  listSitePhpErrors,
  silenceSitePhpError,
  type PhpError,
  type PhpErrorList,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// ADR-037 Sprint 2 — TanStack Query hooks for the PHP error monitor.
//
// The list endpoint supports `silenced` and `since` filters; the table shows
// unsilenced by default with a toggle to reveal silenced. The mutation flips
// the silenced flag on a (site, md5) row; the agent keeps counting locally
// regardless.
//
// ADR-037 Sprint 6 — cursor-based infinite pagination. usePHPErrors is now
// an infinite query with per-page limit=100. Live-refresh tradeoff: polling
// fires only when the user is on the first page (pages.length <= 1).

export const errorsKeys = {
  all: ["php-errors"] as const,
  forSite: (siteId: string, silenced: "true" | "false" | "all") =>
    [...errorsKeys.all, "site", siteId, silenced] as const,
};

export interface UsePHPErrorsOptions {
  showSilenced: boolean;
  limit?: number;
}

export interface UsePHPErrorsResult {
  items: PhpError[];
  fetchNextPage: UseInfiniteQueryResult<InfiniteData<PhpErrorList>, Error>["fetchNextPage"];
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  isLoading: boolean;
  isPending: boolean;
  isError: boolean;
  error: Error | null;
  refetch: UseInfiniteQueryResult<InfiniteData<PhpErrorList>, Error>["refetch"];
}

export function usePHPErrors(
  siteId: string,
  options: UsePHPErrorsOptions,
): UsePHPErrorsResult {
  const silenced = options.showSilenced ? "all" : "false";
  const result = useInfiniteQuery<PhpErrorList, Error, InfiniteData<PhpErrorList>, ReturnType<typeof errorsKeys.forSite>, string | undefined>({
    queryKey: errorsKeys.forSite(siteId, silenced),
    initialPageParam: undefined,
    getNextPageParam: (lastPage) => lastPage.next_cursor || undefined,
    queryFn: async ({ pageParam }) => {
      const { data, error } = await listSitePhpErrors({
        path: { siteId },
        query: {
          ...(options.showSilenced ? {} : { silenced: "false" }),
          limit: options.limit ?? 100,
          ...(pageParam !== undefined ? { cursor: pageParam } : {}),
        },
      });
      if (error) throw toError(error);
      return data ?? { items: [], next_cursor: undefined };
    },
    // Only poll while the user is still on the first page.
    refetchInterval: (query) => {
      const pages = query.state.data?.pages;
      return pages && pages.length <= 1 ? 15_000 : false;
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

export function useSilenceError(
  siteId: string,
): UseMutationResult<
  void,
  Error,
  { md5: string; silenced: boolean }
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ md5, silenced }) => {
      const { error } = await silenceSitePhpError({
        path: { siteId, md5 },
        body: { silenced },
      });
      if (error) throw toError(error);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: errorsKeys.all,
      });
    },
  });
}
