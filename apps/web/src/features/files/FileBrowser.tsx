import { useState } from "react";
import {
  Archive,
  FolderPlus,
  History,
  RefreshCw,
  Search,
  ToggleLeft,
  Upload,
  X,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageError } from "@/components/feedback";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { Link } from "@tanstack/react-router";
import type { FileEntry, FileSearchMatch } from "@wpmgr/api";

import { useFiles } from "./hooks/use-files";
import { FileBreadcrumb } from "./FileBreadcrumb";
import { FileBrowserTable } from "./FileBrowserTable";
import { FileDetailDrawer } from "./FileDetailDrawer";
import { FilesEmptyState } from "./FilesEmptyState";
import { FileEditDialog } from "./FileEditDialog";
import { FileMkdirDialog } from "./FileMkdirDialog";
import { FileUploadPane } from "./FileUploadPane";
import { FileSearchBar } from "./FileSearchBar";
import { FileArchiveDialog } from "./FileArchiveDialog";

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
// P3 extensions:
//   - Search bar: toggle shows FileSearchBar above the table. While search is
//     active the normal listing is hidden (replaced by results). Clicking a
//     result navigates or opens the drawer. Clear returns to normal listing.
//   - Bulk archive: selecting entries in the table shows a "Download as ZIP"
//     bar. FileBrowserTable gains selectedPaths / onSelectionChange props.
//     NOTE: bulk selection is managed here; the table renders checkboxes.
//
// Authorization:
//   - Read (browse/preview/download): admin+ (enforced server-side).
//   - Write (edit/upload/mkdir/rename/chmod): admin+ + write_enabled.
//   - Delete: owner only + write_enabled.
//   - Archive (download ZIP): admin+.
//   - Extract: admin+ + write_enabled.
//   - Version history: admin+.
//   - Version restore: admin+ + write_enabled.

export interface FileBrowserProps {
  siteId: string;
  canManage: boolean;
  isOwner: boolean;
  writeEnabled: boolean;
  onDisable: () => void;
  isDisabling: boolean;
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

  // P3: search state
  const [searchOpen, setSearchOpen] = useState(false);
  const [isSearchActive, setIsSearchActive] = useState(false);

  // P3: bulk selection + archive
  const [selectedPaths, setSelectedPaths] = useState<string[]>([]);
  const [bulkArchiveOpen, setBulkArchiveOpen] = useState(false);

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
    setSelectedPaths([]);
    // Clear search when navigating
    if (isSearchActive) {
      setSearchOpen(false);
      setIsSearchActive(false);
    }
  };

  const handleFileClick = (entry: FileEntry) => {
    setSelectedFile(entry);
  };

  // P3: when a search result is clicked for a file, we need to open the drawer.
  // We construct a minimal FileEntry from the search match.
  const handleSearchFileClick = (match: FileSearchMatch) => {
    // Navigate to the file's parent directory and open the drawer.
    const parts = match.path.split("/");
    const parentDir = parts.slice(0, -1).join("/");
    const name = parts[parts.length - 1] ?? match.path;
    if (parentDir !== currentPath) {
      setCurrentPath(parentDir);
    }
    // Build a minimal FileEntry to open the drawer.
    const syntheticEntry: FileEntry = {
      name,
      size: match.size,
      mtime: match.mtime,
      is_dir: match.is_dir,
      is_link: false,
      is_writable: false,
      mode: "",
    };
    setSelectedFile(syntheticEntry);
    setSearchOpen(false);
    setIsSearchActive(false);
  };

  const handleSearchClear = () => {
    setSearchOpen(false);
    setIsSearchActive(false);
  };

  const handleSearchNavigate = (path: string) => {
    handleNavigate(path);
    setSearchOpen(false);
    setIsSearchActive(false);
  };

  const handleToggleSearch = () => {
    if (searchOpen) {
      setSearchOpen(false);
      setIsSearchActive(false);
    } else {
      setSearchOpen(true);
    }
  };

  const canWrite = canManage && writeEnabled;

  return (
    <div className="space-y-3">
      {/* Write-mode hint strip */}
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

      {/* Write-mode active strip */}
      {canManage && writeEnabled ? (
        <div className="flex items-center justify-between gap-3 rounded-md border border-[var(--color-warning)]/30 bg-[var(--color-warning)]/8 px-3 py-2">
          <p className="text-xs text-[var(--color-foreground)]">
            Write mode is on. Edits, uploads, and deletions are live on the
            site and audited.
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
          {!isSearchActive ? (
            <FileBreadcrumb path={currentPath} onNavigate={handleNavigate} />
          ) : (
            <span className="text-xs font-medium text-[var(--color-foreground)]">
              Search results
            </span>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {/* Entry count + refresh indicator */}
          {!isSearchActive && !isPending && !isError ? (
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

          {/* View activity deep-link: jumps to the audit log pre-filtered to
              file-manager events for this site. */}
          {canManage ? (
            <Link
              to="/audit"
              search={{ action: "site.files.", site_id: siteId }}
              className={cn(
                "inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium",
                "text-[var(--color-muted-foreground)] transition-colors",
                "hover:bg-[var(--color-muted)] hover:text-[var(--color-foreground)]",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-1",
              )}
              title="View file manager audit log for this site"
            >
              <History aria-hidden="true" className="size-3.5" />
              View activity
            </Link>
          ) : null}

          {/* Search toggle (P3, admin+) */}
          {canManage ? (
            <Button
              type="button"
              variant={searchOpen ? "default" : "outline"}
              size="sm"
              onClick={handleToggleSearch}
              className="gap-1.5"
              title="Search files in this directory"
              aria-expanded={searchOpen}
              aria-label={searchOpen ? "Close search" : "Search files"}
            >
              {searchOpen ? (
                <X aria-hidden="true" className="size-4" />
              ) : (
                <Search aria-hidden="true" className="size-4" />
              )}
              {searchOpen ? "Close" : "Search"}
            </Button>
          ) : null}

          {/* New folder (admin+, write_enabled) */}
          {canWrite && !isSearchActive ? (
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
          {canWrite && !isSearchActive ? (
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

          {/* Disable action */}
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

      {/* P3: Search bar — shown when searchOpen=true */}
      {searchOpen && canManage ? (
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4">
          <FileSearchBar
            siteId={siteId}
            currentPath={currentPath}
            isVisible={searchOpen}
            onNavigate={handleSearchNavigate}
            onFileClick={handleSearchFileClick}
            onClear={handleSearchClear}
          />
        </div>
      ) : null}

      {/* Upload pane */}
      {uploadOpen && canWrite && !isSearchActive ? (
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4">
          <FileUploadPane
            siteId={siteId}
            currentDirPath={currentPath}
            isOwner={isOwner}
          />
        </div>
      ) : null}

      {/* Bulk selection bar (P3) — shown when items are selected */}
      {selectedPaths.length > 0 && !isSearchActive ? (
        <BulkSelectionBar
          count={selectedPaths.length}
          canManage={canManage}
          onArchive={() => setBulkArchiveOpen(true)}
          onClearSelection={() => setSelectedPaths([])}
        />
      ) : null}

      {/* Table / states — hidden while search is active (search bar owns results) */}
      {!isSearchActive ? (
        isPending ? (
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
            selectedPaths={selectedPaths}
            onSelectionChange={setSelectedPaths}
          />
        )
      ) : null}

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

      {/* P3: Bulk archive dialog */}
      {bulkArchiveOpen ? (
        <FileArchiveDialog
          open={bulkArchiveOpen}
          onClose={() => {
            setBulkArchiveOpen(false);
            setSelectedPaths([]);
          }}
          siteId={siteId}
          paths={selectedPaths}
          isOwner={isOwner}
        />
      ) : null}
    </div>
  );
}

// ── Bulk selection bar ────────────────────────────────────────────────────────

function BulkSelectionBar({
  count,
  canManage,
  onArchive,
  onClearSelection,
}: {
  count: number;
  canManage: boolean;
  onArchive: () => void;
  onClearSelection: () => void;
}) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-center justify-between gap-3 rounded-md border border-[var(--color-primary)]/30 bg-[var(--color-primary)]/8 px-3 py-2"
    >
      <span className="text-sm text-[var(--color-foreground)]">
        <span className="font-medium tabular-nums">{count}</span>{" "}
        {count === 1 ? "item" : "items"} selected
      </span>
      <div className="flex items-center gap-2">
        {canManage ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onArchive}
            className="gap-1.5 text-xs"
          >
            <Archive aria-hidden="true" className="size-3.5" />
            Download as ZIP
          </Button>
        ) : null}
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onClearSelection}
          className="gap-1.5 text-xs text-[var(--color-muted-foreground)]"
          aria-label="Clear selection"
        >
          <X aria-hidden="true" className="size-3.5" />
          Clear
        </Button>
      </div>
    </div>
  );
}

// ── Loading skeleton ──────────────────────────────────────────────────────────

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

// ── Error helpers ─────────────────────────────────────────────────────────────

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
