import { useState } from "react";
import {
  File,
  Folder,
  Loader2,
  Search,
  X,
} from "lucide-react";
import { useInfiniteQuery } from "@tanstack/react-query";
import { searchSiteFiles } from "@wpmgr/api";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn, formatBytes, relativeTime } from "@/lib/utils";
import type { FileSearchMatch, FileSearchResult } from "@wpmgr/api";
import { toError } from "@/features/auth/use-auth";

// FileSearchBar — file search toolbar + results view.
//
// Two modes: "name" (filename match) and "content" (grep with snippet).
// Debounces 350ms before querying (via a controlled state field updated on
// a timer). Results are accumulated via useInfiniteQuery so TanStack Query
// owns pagination — no ref-in-render or setState-in-effect patterns needed.
//
// The results view replaces the normal table while active. Clicking a result
// navigates (dir) or opens the detail drawer (file). Pressing Escape clears.

export interface FileSearchBarProps {
  siteId: string;
  currentPath: string;
  onNavigate: (path: string) => void;
  onFileClick: (match: FileSearchMatch) => void;
  onClear: () => void;
  isVisible: boolean;
}

export function FileSearchBar({
  siteId,
  currentPath,
  onNavigate,
  onFileClick,
  onClear,
  isVisible: _isVisible,
}: FileSearchBarProps) {
  const [rawQuery, setRawQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [debounceId, setDebounceId] = useState<number | null>(null);
  const [mode, setMode] = useState<"name" | "content">("name");

  // useInfiniteQuery accumulates pages — no manual state needed.
  const search = useInfiniteQuery({
    queryKey: [
      "files",
      "search",
      siteId,
      currentPath,
      debouncedQuery,
      mode,
    ] as const,
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage: FileSearchResult) =>
      lastPage.truncated ? lastPage.cursor : undefined,
    queryFn: async ({ pageParam }) => {
      const { data, error } = await searchSiteFiles({
        path: { siteId },
        query: {
          q: debouncedQuery,
          ...(currentPath ? { path: currentPath } : {}),
          mode,
          ...(pageParam ? { cursor: pageParam } : {}),
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: debouncedQuery.trim().length >= 2,
    staleTime: 10_000,
  });

  const allMatches: FileSearchMatch[] =
    search.data?.pages.flatMap((p) => p.matches) ?? [];
  const hasQuery = debouncedQuery.length >= 2;
  const isEmpty =
    hasQuery && !search.isPending && allMatches.length === 0 && !search.isError;
  const isTruncated = search.data?.pages.at(-1)?.truncated ?? false;

  const handleQueryChange = (q: string) => {
    setRawQuery(q);
    if (debounceId != null) window.clearTimeout(debounceId);
    const id: number = window.setTimeout(() => {
      setDebouncedQuery(q.trim());
      setDebounceId(null);
    }, 350);
    setDebounceId(id);
  };

  const handleModeChange = (m: "name" | "content") => {
    setMode(m);
    // Apply immediately on mode toggle.
    setDebouncedQuery(rawQuery.trim());
  };

  const handleClear = () => {
    if (debounceId != null) window.clearTimeout(debounceId);
    setDebounceId(null);
    setRawQuery("");
    setDebouncedQuery("");
    onClear();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") {
      e.preventDefault();
      handleClear();
    }
  };

  return (
    <div className="space-y-3">
      {/* Toolbar row */}
      <div className="flex items-center gap-2">
        <div
          role="group"
          aria-label="Search mode"
          className="flex shrink-0 overflow-hidden rounded-md border border-[var(--color-border)] text-xs"
        >
          <ModeButton
            active={mode === "name"}
            onClick={() => handleModeChange("name")}
            label="Name"
          />
          <ModeButton
            active={mode === "content"}
            onClick={() => handleModeChange("content")}
            label="Content"
          />
        </div>

        <div className="relative flex-1">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-[var(--color-muted-foreground)]"
          />
          <Input
            type="search"
            value={rawQuery}
            onChange={(e) => handleQueryChange(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={
              mode === "content"
                ? "Search file contents..."
                : "Search by filename..."
            }
            aria-label={
              mode === "content"
                ? "Search file contents"
                : "Search by filename"
            }
            className="pl-8 pr-8 text-sm"
          />
          {rawQuery ? (
            <button
              type="button"
              aria-label="Clear search"
              onClick={handleClear}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
            >
              <X aria-hidden="true" className="size-3.5" />
            </button>
          ) : null}
        </div>

        {search.isFetching && hasQuery ? (
          <Loader2
            aria-label="Searching..."
            className="size-4 shrink-0 animate-spin text-[var(--color-muted-foreground)]"
          />
        ) : null}
      </div>

      {/* Results area */}
      {hasQuery ? (
        <div
          role="region"
          aria-label="Search results"
          aria-live="polite"
          className="space-y-1"
        >
          {!search.isFetching && allMatches.length > 0 ? (
            <p className="px-1 text-xs text-[var(--color-muted-foreground)]">
              {allMatches.length}{" "}
              {allMatches.length === 1 ? "result" : "results"}
              {isTruncated ? " (more available)" : ""}
              {currentPath ? (
                <>
                  {" "}
                  in <span className="font-mono">{currentPath}</span>
                </>
              ) : null}
            </p>
          ) : null}

          {search.isError ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
            >
              {search.error?.message ?? "Search failed"}
            </div>
          ) : null}

          {isEmpty ? (
            <div className="flex flex-col items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] py-10 text-center">
              <Search
                aria-hidden="true"
                className="size-8 text-[var(--color-muted-foreground)]/40"
              />
              <p className="text-sm font-medium text-[var(--color-foreground)]">
                No results for &ldquo;{debouncedQuery}&rdquo;
              </p>
              <p className="text-xs text-[var(--color-muted-foreground)]">
                {mode === "content"
                  ? "No files contain that text in this directory."
                  : "No files or folders match that name."}
              </p>
            </div>
          ) : null}

          {allMatches.length > 0 ? (
            <ul
              aria-label="File search results"
              className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]"
            >
              {allMatches.map((match, i) => (
                <SearchResultRow
                  key={`${match.path}-${i}`}
                  match={match}
                  mode={mode}
                  onNavigate={onNavigate}
                  onFileClick={onFileClick}
                />
              ))}
            </ul>
          ) : null}

          {isTruncated && search.hasNextPage ? (
            <div className="flex justify-center pt-1">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => void search.fetchNextPage()}
                disabled={search.isFetchingNextPage}
                className="text-xs"
              >
                {search.isFetchingNextPage ? (
                  <>
                    <Loader2
                      aria-hidden="true"
                      className="size-3.5 animate-spin"
                    />
                    Loading more...
                  </>
                ) : (
                  "Load more results"
                )}
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

// ── Mode toggle button ────────────────────────────────────────────────────────

function ModeButton({
  active,
  onClick,
  label,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-ring)]",
        active
          ? "bg-[var(--color-primary)] text-[var(--color-primary-foreground)]"
          : "bg-[var(--color-card)] text-[var(--color-muted-foreground)] hover:bg-[var(--color-muted)] hover:text-[var(--color-foreground)]",
      )}
    >
      {label}
    </button>
  );
}

// ── Search result row ─────────────────────────────────────────────────────────

function SearchResultRow({
  match,
  mode,
  onNavigate,
  onFileClick,
}: {
  match: FileSearchMatch;
  mode: "name" | "content";
  onNavigate: (path: string) => void;
  onFileClick: (match: FileSearchMatch) => void;
}) {
  const handleClick = () => {
    if (match.is_dir) {
      onNavigate(match.path);
    } else {
      onFileClick(match);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      handleClick();
    }
  };

  const mtimeIso = match.mtime
    ? new Date(match.mtime * 1000).toISOString()
    : null;

  return (
    <li className="border-b border-[var(--color-border)] last:border-0">
      <button
        type="button"
        onClick={handleClick}
        onKeyDown={handleKeyDown}
        className={cn(
          "flex w-full items-start gap-3 px-4 py-2.5 text-left transition-colors",
          "hover:bg-[var(--color-muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-ring)]",
        )}
        aria-label={
          match.is_dir
            ? `Open directory: ${match.path}`
            : `Open file: ${match.path}`
        }
      >
        <span className="mt-0.5 shrink-0">
          {match.is_dir ? (
            <Folder
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
          ) : (
            <File
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]/70"
            />
          )}
        </span>

        <span className="min-w-0 flex-1 space-y-0.5">
          <span className="block min-w-0 truncate font-mono text-sm text-[var(--color-foreground)]">
            {match.path}
          </span>
          <span className="flex items-center gap-3 text-xs text-[var(--color-muted-foreground)]">
            {!match.is_dir ? (
              <span className="tabular-nums">{formatBytes(match.size)}</span>
            ) : null}
            {mtimeIso ? (
              <span
                title={new Date(match.mtime * 1000).toLocaleString()}
                className="tabular-nums"
              >
                {relativeTime(mtimeIso)}
              </span>
            ) : null}
            {mode === "content" && match.line != null ? (
              <span className="tabular-nums">line {match.line}</span>
            ) : null}
          </span>
          {mode === "content" && match.snippet ? (
            <span
              aria-label="Matching content"
              className="block max-h-10 overflow-hidden text-ellipsis whitespace-pre font-mono text-[11px] text-[var(--color-muted-foreground)]"
            >
              {match.snippet}
            </span>
          ) : null}
        </span>
      </button>
    </li>
  );
}
