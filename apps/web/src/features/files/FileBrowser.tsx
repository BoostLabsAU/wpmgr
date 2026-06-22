import { useState } from "react";
import { RefreshCw, ToggleLeft } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageError } from "@/components/feedback";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import type { FileEntry } from "@wpmgr/api";

import { useFiles } from "./hooks/use-files";
import { FileBreadcrumb } from "./FileBreadcrumb";
import { FileBrowserTable } from "./FileBrowserTable";
import { FileDetailDrawer } from "./FileDetailDrawer";
import { FilesEmptyState } from "./FilesEmptyState";

// FileBrowser — the enabled state of the file manager.
//
// Manages: current path (breadcrumb nav), selected file (drawer), directory
// listing (useFiles → FileBrowserTable), and the file detail drawer.
//
// The path lives in component state; the parent route controls the enabled gate
// (settings). A "Disable" action is exposed to admins via the toolbar so they
// can turn off the feature without leaving the page.

export interface FileBrowserProps {
  siteId: string;
  /** Whether the current user can disable the file manager (admin+). */
  canManage: boolean;
  /** Whether the current user is an owner (for sensitive file gate). */
  isOwner: boolean;
  onDisable: () => void;
  isDisabling: boolean;
}

export function FileBrowser({
  siteId,
  canManage,
  isOwner,
  onDisable,
  isDisabling,
}: FileBrowserProps) {
  const [currentPath, setCurrentPath] = useState("");
  const [selectedFile, setSelectedFile] = useState<FileEntry | null>(null);

  const { entries, total, isPending, isError, error, isFetching, isFetchingNextPage, hasNextPage, fetchNextPage, refetch } =
    useFiles(siteId, currentPath);

  const handleNavigate = (path: string) => {
    setCurrentPath(path);
    setSelectedFile(null);
  };

  const handleFileClick = (entry: FileEntry) => {
    setSelectedFile(entry);
  };

  return (
    <div className="space-y-3">
      {/* Toolbar: breadcrumb + count + disable */}
      <div className="flex min-h-9 items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          <FileBreadcrumb path={currentPath} onNavigate={handleNavigate} />
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {/* Entry count */}
          {!isPending && !isError ? (
            <span className="text-xs text-[var(--color-muted-foreground)] tabular-nums">
              {total.toLocaleString()} {total === 1 ? "entry" : "entries"}
              {isFetching && !isFetchingNextPage ? (
                <RefreshCw
                  aria-hidden="true"
                  className="ml-1 inline size-3 animate-spin"
                />
              ) : null}
            </span>
          ) : null}

          {/* Disable action — only for admins, unobtrusive outline button */}
          {canManage ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isDisabling}
              onClick={onDisable}
              className="gap-1.5 text-[var(--color-muted-foreground)]"
              title="Disable file manager for this site"
            >
              <ToggleLeft aria-hidden="true" className="size-4" />
              Disable
            </Button>
          ) : null}
        </div>
      </div>

      {/* Table / states */}
      {isPending ? (
        <FileBrowserSkeleton />
      ) : isError ? (
        <PageError
          what="Could not load directory."
          why={friendlyError(error)}
          onRetry={() => void refetch()}
          retryLabel="Reload directory"
        />
      ) : entries.length === 0 ? (
        <FilesEmptyState />
      ) : (
        <FileBrowserTable
          entries={entries}
          currentPath={currentPath}
          onNavigate={handleNavigate}
          onFileClick={handleFileClick}
          onEndReached={
            hasNextPage && !isFetchingNextPage ? fetchNextPage : undefined
          }
          isFetchingNextPage={isFetchingNextPage}
        />
      )}

      {/* File detail drawer */}
      <FileDetailDrawer
        siteId={siteId}
        entry={selectedFile}
        currentPath={currentPath}
        onClose={() => setSelectedFile(null)}
        isOwner={isOwner}
      />
    </div>
  );
}

// ── Loading skeleton ──────────────────────────────────────────────────────

function FileBrowserSkeleton() {
  return (
    <div
      aria-label="Loading directory"
      aria-busy="true"
      className="overflow-hidden rounded-lg border border-[var(--color-border)]"
    >
      {/* Header */}
      <div className="flex h-9 items-center gap-3 border-b border-[var(--color-border)] bg-[var(--color-background)] px-4">
        <Skeleton className="h-3 w-12" />
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-3 w-12" />
        <Skeleton className="ml-auto h-3 w-10" />
      </div>
      {/* Rows */}
      {Array.from({ length: 8 }).map((_, i) => (
        <div
          key={i}
          className={cn(
            "flex h-10 items-center gap-3 border-b border-[var(--color-border)] px-4",
            "bg-[var(--color-card)]",
          )}
        >
          <Skeleton className="size-4 rounded" />
          <Skeleton className="h-3 w-40" />
          <Skeleton className="ml-auto h-3 w-14" />
          <Skeleton className="h-3 w-16" />
          <Skeleton className="h-3 w-20" />
        </div>
      ))}
    </div>
  );
}

// ── Error helpers ─────────────────────────────────────────────────────────

function friendlyError(err: Error | null): string {
  if (!err) return "Unknown error";
  const msg = err.message;
  if (/files_not_enabled/i.test(msg))
    return "The file manager is not enabled for this site.";
  if (/403|forbidden|permission/i.test(msg))
    return "You don't have permission to browse files on this site.";
  if (/404|not found/i.test(msg)) return "The path was not found on the site.";
  if (/500/i.test(msg)) return "The server returned an error.";
  if (/network|fetch/i.test(msg)) return "Network error. Check your connection.";
  return msg || "The request failed.";
}
