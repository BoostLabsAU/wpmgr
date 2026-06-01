import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
  type InfiniteData,
  type UseInfiniteQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import { useCallback, useEffect, useRef, useState } from "react";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import type {
  AssetSelectionBody,
  BatchResponse,
  CancelResponse,
  ListAssetsResponse,
  MediaAsset,
  MediaSummary,
  OptimizeBody,
  SyncResponse,
} from "../types";

// Server-state hooks for the Media Optimizer asset list + the action mutations.
//
// Like use-site-connection.ts, these routes are NOT in the generated @wpmgr/api
// SDK (hand-rolled DTOs — ADR-043 §7), so we call the shared `client` directly
// with explicit `url`s. The response-style generic `<{ 200: T }>` mirrors how
// use-sites.ts unwraps the SDK's responses-map so `data` types as `T`.
//
// Endpoints (handler.go):
//   GET  /api/v1/sites/:siteId/media/assets?cursor&limit&status&format&search
//   POST /api/v1/sites/:siteId/media/sync
//   POST /api/v1/sites/:siteId/media/optimize
//   POST /api/v1/sites/:siteId/media/restore
//   POST /api/v1/sites/:siteId/media/delete-originals
//   POST /api/v1/sites/:siteId/media/cancel
//
// PAGINATION: useMediaAssets is an useInfiniteQuery. Each page fetches up to 200
// assets (server max — fewest round-trips). The caller drives pagination via
// endReached on the virtual scroller; the hook exposes fetchNextPage / hasNextPage
// / isFetchingNextPage. Flattened `items` is the concat of all pages.

export interface AssetFilters {
  status?: string;
  format?: string;
  search?: string;
}

/** The page size we request — server max (200) to minimise round-trips. */
const PAGE_LIMIT = 200;

/** Safety cap: stop prefetching after this many pages to avoid runaway loops. */
const MAX_PREFETCH_PAGES = 200;

export const mediaKeys = {
  all: ["media"] as const,
  assets: (siteId: string, filters: AssetFilters = {}) =>
    [...mediaKeys.all, "assets", siteId, filters] as const,
  jobs: (siteId: string, state?: string) =>
    [...mediaKeys.all, "jobs", siteId, state ?? "all"] as const,
  job: (jobId: string) => [...mediaKeys.all, "job", jobId] as const,
};

function base(siteId: string): string {
  return `/api/v1/sites/${encodeURIComponent(siteId)}/media`;
}

/** Shape exposed by useMediaAssets — mirrors the old UseQueryResult shape that
 *  callers depended on, extended with infinite-scroll helpers. */
export interface UseMediaAssetsResult {
  // Derived from the infinite query —
  items: MediaAsset[];
  /** From the first (authoritative) page; stable while filters hold. */
  totalCount: number;
  summary: MediaSummary;

  // Pass-through infinite-query state —
  isPending: boolean;
  isError: boolean;
  error: Error | null;
  isFetching: boolean;
  isFetchingNextPage: boolean;
  hasNextPage: boolean;
  fetchNextPage: () => void;
  refetch: () => void;

  /**
   * Fetch every remaining page sequentially until hasNextPage is false.
   * Resolves to the ids of ALL assets (across all loaded pages) once done.
   * Guarded against loops: aborts after MAX_PREFETCH_PAGES fetches or when
   * totalCount is already satisfied.
   *
   * // TODO: for libraries > ~10 k assets a server-side "select-all-by-filter"
   * // endpoint would skip these round-trips entirely; implement as a follow-up.
   */
  fetchAllPages: () => Promise<string[]>;

  /** True while fetchAllPages is loading remaining pages. */
  isFetchingAll: boolean;

  /** Raw infinite data — useMediaEvents needs setQueriesData compat. */
  _raw: UseInfiniteQueryResult<InfiniteData<ListAssetsResponse>, Error>;
}

/** List a site's media assets + the summary rollup (handler.listAssets).
 *
 *  Cursor-paginated: fetches pages of PAGE_LIMIT (200) assets until the server
 *  returns no next_cursor. The virtual table's endReached drives fetchNextPage.
 */
export function useMediaAssets(
  siteId: string,
  filters: AssetFilters = {},
): UseMediaAssetsResult {
  const [isFetchingAll, setIsFetchingAll] = useState(false);
  // Guard against concurrent fetchAllPages calls.
  const fetchingAllRef = useRef(false);

  const query = useInfiniteQuery({
    queryKey: mediaKeys.assets(siteId, filters),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage: ListAssetsResponse) =>
      lastPage.next_cursor || undefined,
    queryFn: async ({ pageParam }) => {
      const qs: Record<string, string> = { limit: String(PAGE_LIMIT) };
      if (filters.status) qs.status = filters.status;
      if (filters.format) qs.format = filters.format;
      if (filters.search) qs.search = filters.search;
      if (pageParam) qs.cursor = pageParam;

      const { data, error } = await client.get<{ 200: ListAssetsResponse }>({
        url: `${base(siteId)}/assets`,
        query: qs,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
  });

  // Flatten all pages into a single items array.
  const items: MediaAsset[] =
    query.data?.pages.flatMap((p) => p.items) ?? [];

  // Summary + total_count: prefer the latest page (most up-to-date rollup after
  // an SSE-triggered invalidation), fall back to the first page on initial load.
  const lastPage = query.data?.pages[query.data.pages.length - 1];
  const firstPage = query.data?.pages[0];
  const activePage = lastPage ?? firstPage;

  const EMPTY_SUMMARY: MediaSummary = {
    total: 0,
    optimized: 0,
    pending: 0,
    failed: 0,
    unsupported: 0,
    bytes_saved: 0,
    total_images: 0,
    optimized_images: 0,
  };

  // Keep a ref to the latest query result so fetchAllPages never has a stale
  // closure (query changes reference every render but we need the latest). The
  // ref is updated in an effect (NOT during render) — fetchAllPages is only ever
  // invoked from user-triggered async callbacks, well after commit, so it always
  // observes the latest value.
  const queryRef = useRef(query);
  useEffect(() => {
    queryRef.current = query;
  });

  // fetchAllPages: fetch every remaining page sequentially, then return all ids.
  // Bounded by MAX_PREFETCH_PAGES and totalCount so a broken hasNextPage can't
  // loop forever. Callers should gate on !isFetchingAll before invoking.
  const fetchAllPages = useCallback(async (): Promise<string[]> => {
    if (fetchingAllRef.current) {
      // Already in progress — return current ids (caller will re-call after done).
      return (queryRef.current.data?.pages.flatMap((p) => p.items) ?? []).map(
        (a) => a.id,
      );
    }
    fetchingAllRef.current = true;
    setIsFetchingAll(true);
    try {
      let pagesFetched = 0;
      const tc = queryRef.current.data?.pages[0]?.total_count ?? 0;
      // Keep fetching while there are more pages, within the safety cap, and
      // while loaded count is below totalCount.
      while (
        queryRef.current.hasNextPage &&
        pagesFetched < MAX_PREFETCH_PAGES &&
        (tc === 0 ||
          (queryRef.current.data?.pages.flatMap((p) => p.items).length ?? 0) <
            tc)
      ) {
        await queryRef.current.fetchNextPage();
        pagesFetched += 1;
      }
      return (queryRef.current.data?.pages.flatMap((p) => p.items) ?? []).map(
        (a) => a.id,
      );
    } finally {
      fetchingAllRef.current = false;
      setIsFetchingAll(false);
    }
  }, []);

  return {
    items,
    totalCount: activePage?.total_count ?? 0,
    summary: activePage?.summary ?? EMPTY_SUMMARY,

    isPending: query.isPending,
    isError: query.isError,
    error: query.error,
    isFetching: query.isFetching,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: query.hasNextPage,
    fetchNextPage: () => void query.fetchNextPage(),
    refetch: () => void query.refetch(),
    fetchAllPages,
    isFetchingAll,

    _raw: query,
  };
}

/** Start a library sync (operator+) — enumerates WP media into the CP. */
export function useSyncMedia(
  siteId: string,
): UseMutationResult<SyncResponse, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await client.post<{ 202: SyncResponse }>({
        url: `${base(siteId)}/sync`,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "assets", siteId] });
    },
  });
}

/** Start an optimize batch (operator+). */
export function useOptimizeMedia(
  siteId: string,
): UseMutationResult<BatchResponse, Error, OptimizeBody> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: OptimizeBody) => {
      const { data, error } = await client.post<{ 202: BatchResponse }>({
        url: `${base(siteId)}/optimize`,
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "assets", siteId] });
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "jobs", siteId] });
    },
  });
}

/** Start a restore batch (operator+). */
export function useRestoreMedia(
  siteId: string,
): UseMutationResult<BatchResponse, Error, AssetSelectionBody> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: AssetSelectionBody) => {
      const { data, error } = await client.post<{ 202: BatchResponse }>({
        url: `${base(siteId)}/restore`,
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "assets", siteId] });
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "jobs", siteId] });
    },
  });
}

/** Delete originals (admin+, IRREVERSIBLE). */
export function useDeleteOriginals(
  siteId: string,
): UseMutationResult<BatchResponse, Error, AssetSelectionBody> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: AssetSelectionBody) => {
      const { data, error } = await client.post<{ 202: BatchResponse }>({
        url: `${base(siteId)}/delete-originals`,
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "assets", siteId] });
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "jobs", siteId] });
    },
  });
}

/** Cancel all non-terminal jobs for a site (operator+). */
export function useCancelMedia(
  siteId: string,
): UseMutationResult<CancelResponse, Error, void> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data, error } = await client.post<{ 200: CancelResponse }>({
        url: `${base(siteId)}/cancel`,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "jobs", siteId] });
      void qc.invalidateQueries({ queryKey: [...mediaKeys.all, "assets", siteId] });
    },
  });
}
