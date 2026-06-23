import {
  useInfiniteQuery,
  type UseInfiniteQueryResult,
  type InfiniteData,
} from "@tanstack/react-query";
import { listSiteFiles, type FileListResult, type FileEntry } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { filesKeys } from "./use-file-manager-settings";

// ── useFiles ───────────────────────────────────────────────────────────────
//
// Cursor-paginated listing of a site directory. Mirrors useMediaAssets's
// infinite query pattern. The consumer drives pagination (EndReached or a
// "Load more" button) via `fetchNextPage` / `hasNextPage`.
//
// Entries are sorted: directories first, then files, both groups
// alphabetically. The server sends them in filesystem order; we sort
// client-side so it's stable regardless of agent ordering.

export interface UseFilesResult {
  entries: FileEntry[];
  path: string;
  total: number;

  isPending: boolean;
  isError: boolean;
  error: Error | null;
  isFetching: boolean;
  isFetchingNextPage: boolean;
  hasNextPage: boolean;
  fetchNextPage: () => void;
  refetch: () => void;

  /** Raw query for advanced usage. */
  _raw: UseInfiniteQueryResult<InfiniteData<FileListResult>, Error>;
}

export function useFiles(siteId: string, dirPath: string): UseFilesResult {
  const query = useInfiniteQuery({
    queryKey: filesKeys.list(siteId, dirPath),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage: FileListResult) =>
      lastPage.truncated ? lastPage.cursor : undefined,
    queryFn: async ({ pageParam }) => {
      const { data, error } = await listSiteFiles({
        path: { siteId },
        query: {
          path: dirPath,
          ...(pageParam ? { cursor: pageParam } : {}),
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
  });

  // Flatten + sort: dirs first, then files, both alpha.
  const rawEntries: FileEntry[] =
    query.data?.pages.flatMap((p) => p.entries) ?? [];
  const entries = [...rawEntries].sort((a, b) => {
    if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
    return a.name.localeCompare(b.name);
  });

  const lastPage = query.data?.pages.at(-1);
  const path = lastPage?.path ?? dirPath;
  const total = lastPage?.total ?? 0;

  return {
    entries,
    path,
    total,

    isPending: query.isPending,
    isError: query.isError,
    error: query.error,
    isFetching: query.isFetching,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: query.hasNextPage,
    fetchNextPage: () => void query.fetchNextPage(),
    refetch: () => void query.refetch(),

    _raw: query,
  };
}
