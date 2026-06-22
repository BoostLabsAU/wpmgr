import { useState } from "react";
import {
  FolderPlus,
  RefreshCw,
  ToggleLeft,
  Upload,
  X,
} from "lucide-react";

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
import { FileEditDialog } from "./FileEditDialog";
import { FileMkdirDialog } from "./FileMkdirDialog";
import { FileUploadPane } from "./FileUploadPane";

// FileBrowser — the enabled state of the file manager.
//
// P2 extensions:
//   - Toolbar: "New folder" + "Upload" (admin+, write_enabled). "Disable" moved last.
//   - Write toggle (write_enabled) displayed as a contextual info strip; the
//     actual toggle lives in the route (FilesDisabledGate area) handled by
//     the parent because it needs the updateSettings mutation.
//   - Edit action surfaces from the FileDetailDrawer via onEdit callback.
//   - Upload pane shown/hidden by a toggle button in the toolbar.
//   - New-folder dialog.
//
// Authorization:
//   - Read (browse/preview/download): admin+ (enforced server-side).
//   - Write (edit/upload/mkdir/rename/chmod): admin+ + write_enabled.
//   - Delete: owner only + write_enabled.

export interface FileBrowserProps {
  siteId: string;
  /** Whether the current user can disable/manage the file manager (admin+). */
  canManage: boolean;
  /** Whether the current user is an owner (for exec/sensitive confirms + delete). */
  isOwner: boolean;
  /** Whether write mode is enabled for this site. */
  writeEnabled: boolean;
  onDisable: () => void;
  isDisabling: boolean;
  /** Called when admin wants to toggle write mode (admin+). */
  onToggleWrite: () => void;
  isTogglingWrite: boolean;
}

export function FileBrowser({
  siteId,
  canManage,
  isOwner,
  writeEnabled,
  onDisable,
  isDisabling,
  onToggleWrite,
  isTogglingWrite,
}: FileBrowserProps) {
  const [currentPath, setCurrentPath] = useState("");
  const [selectedFile, setSelectedFile] = useState<FileEntry | null>(null);
  const [editEntry, setEditEntry] = useState<{
    entry: FileEntry;
    content: string;
  } | null>(null);
  const [mkdirOpen, setMkdirOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);

  const {
    entries,
    total,
    isPending,
    isError,
    error,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
    refetch,
  } = useFiles(siteId, currentPath);

  const handleNavigate = (path: string) => {
    setCurrentPath(path);
    setSelectedFile(null);
  };

  const handleFileClick = (entry: FileEntry) => {
    setSelectedFile(entry);
  };

  const canWrite = canManage && writeEnabled;

  return (
    <div className="space-y-3">
      {/* Write-mode hint strip — shown to admins when write is off */}
      {canManage && !writeEnabled ? (
        <div className="flex items-center justify-between gap-3 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-2">
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Write mode is off. Enable it to edit, upload, and delete files.
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={isTogglingWrite}
            onClick={onToggleWrite}
            className="shrink-0 text-xs"
          >
            Enable writes
          </Button>
        </div>
      ) : null}

      {/* Write-mode active strip — shown to admins when write is on */}
      {canManage && writeEnabled ? (
        <div className="flex items-center justify-between gap-3 rounded-md border border-[var(--color-warning)]/30 bg-[var(--color-warning)]/8 px-3 py-2">
          <p className="text-xs text-[var(--color-foreground)]">
            Write mode is on. Edits, uploads, and deletions are live on the site
            and audited.
          </p>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={isTogglingWrite}
            onClick={onToggleWrite}
            className="shrink-0 text-xs text-[var(--color-muted-foreground)]"
          >
            Disable writes
          </Button>
        </div>
      ) : null}

      {/* Toolbar: breadcrumb + count + actions */}
      <div className="flex min-h-9 items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          <FileBreadcrumb path={currentPath} onNavigate={handleNavigate} />
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {/* Entry count + refresh indicator */}
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

          {/* New folder (admin+, write_enabled) */}
          {canWrite ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setMkdirOpen(true)}
              className="gap-1.5"
              title="Create a new folder in this directory"
            >
              <FolderPlus aria-hidden="true" className="size-4" />
              New folder
            </Button>
          ) : null}

          {/* Upload toggle (admin+, write_enabled) */}
          {canWrite ? (
            <Button
              type="button"
              variant={uploadOpen ? "default" : "outline"}
              size="sm"
              onClick={() => setUploadOpen((v) => !v)}
              className="gap-1.5"
              title="Upload files to this directory"
              aria-expanded={uploadOpen}
            >
              {uploadOpen ? (
                <X aria-hidden="true" className="size-4" />
              ) : (
                <Upload aria-hidden="true" className="size-4" />
              )}
              {uploadOpen ? "Close upload" : "Upload"}
            </Button>
          ) : null}

          {/* Disable action — only for admins, unobtrusive */}
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

      {/* Upload pane */}
      {uploadOpen && canWrite ? (
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4">
          <FileUploadPane
            siteId={siteId}
            currentDirPath={currentPath}
            isOwner={isOwner}
          />
        </div>
      ) : null}

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
          siteId={siteId}
          writeEnabled={writeEnabled}
          canManage={canManage}
          isOwner={isOwner}
        />
      )}

      {/* File detail drawer */}
      <FileDetailDrawer
        siteId={siteId}
        entry={selectedFile}
        currentPath={currentPath}
        onClose={() => setSelectedFile(null)}
        isOwner={isOwner}
        writeEnabled={writeEnabled}
        canManage={canManage}
        onEdit={(content) => {
          if (selectedFile) {
            setEditEntry({ entry: selectedFile, content });
          }
        }}
      />

      {/* Edit dialog */}
      {editEntry ? (
        <FileEditDialog
          open={true}
          onClose={() => setEditEntry(null)}
          siteId={siteId}
          filePath={
            currentPath
              ? `${currentPath}/${editEntry.entry.name}`
              : editEntry.entry.name
          }
          initialContent={editEntry.content}
          currentDirPath={currentPath}
          isOwner={isOwner}
        />
      ) : null}

      {/* New-folder dialog */}
      {mkdirOpen ? (
        <FileMkdirDialog
          open={mkdirOpen}
          onClose={() => setMkdirOpen(false)}
          siteId={siteId}
          currentDirPath={currentPath}
          onCreated={() => setMkdirOpen(false)}
        />
      ) : null}
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
