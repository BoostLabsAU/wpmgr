import { useState } from "react";
import {
  Archive,
  Download,
  Edit3,
  FolderOpen,
  FolderUp,
  History,
  Lock,
  MoreHorizontal,
  Pencil,
  Trash2,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { FileEntry } from "@wpmgr/api";

import { useFileDownload } from "./hooks/use-file-download";
import { FileDeleteDialog } from "./FileDeleteDialog";
import { FileRenameDialog } from "./FileRenameDialog";
import { FileChmodDialog } from "./FileChmodDialog";
import { FileArchiveDialog } from "./FileArchiveDialog";
import { FileExtractDialog } from "./FileExtractDialog";
import { FileVersionHistoryDialog } from "./FileVersionHistoryDialog";

// FileActionMenu — per-row dropdown for file actions (P1 + P2 + P3).
//
// P3 additions:
//   - Download as ZIP (archive): all files and dirs, admin+.
//     Sensitive confirm for owner; hard block for non-owner.
//   - Extract here (only for .zip files): admin+, write_enabled.
//     Full security error flow (zip_slip/zip_bomb/exec/sensitive).
//   - Version history: all files, admin+.
//     Restore: admin+, write_enabled. Sensitive confirm for owner.
//
// Actions and their permission gates:
//   - Open (dir) / Edit (file): admin+, write_enabled for Edit
//   - Download (file only): admin+
//   - Download as ZIP: admin+
//   - Extract (zip only): admin+, write_enabled
//   - Rename: admin+, write_enabled
//   - Permissions (chmod): admin+, write_enabled
//   - Version history: admin+, file only
//   - Delete: owner only

export interface FileActionMenuProps {
  siteId: string;
  entry: FileEntry;
  currentDirPath: string;
  entryPath: string;
  writeEnabled: boolean;
  canManage: boolean;
  isOwner: boolean;
  onEdit?: () => void;
  onOpen?: () => void;
  onDeleted?: () => void;
}

/** Check if a filename has a .zip extension (case-insensitive). */
function isZipFile(name: string): boolean {
  return /\.zip$/i.test(name);
}

export function FileActionMenu({
  siteId,
  entry,
  currentDirPath,
  entryPath,
  writeEnabled,
  canManage,
  isOwner,
  onEdit,
  onOpen,
  onDeleted,
}: FileActionMenuProps) {
  const [renameOpen, setRenameOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [chmodOpen, setChmodOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [extractOpen, setExtractOpen] = useState(false);
  const [versionsOpen, setVersionsOpen] = useState(false);

  const download = useFileDownload(siteId);

  const canWrite = canManage && writeEnabled;
  const canDelete = isOwner && writeEnabled;
  const canExtract = canWrite && !entry.is_dir && isZipFile(entry.name);
  const canVersions = canManage && !entry.is_dir;

  const hasAnyAction =
    entry.is_dir ||
    (!entry.is_dir && (canManage || canWrite || canDelete));

  if (!hasAnyAction && !canManage) return null;

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7 w-7 p-0 text-[var(--color-muted-foreground)] focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
            aria-label={`Actions for ${entry.name}`}
            onClick={(e) => e.stopPropagation()}
          >
            <MoreHorizontal aria-hidden="true" className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
          {/* Open directory */}
          {entry.is_dir ? (
            <DropdownMenuItem onSelect={() => onOpen?.()} className="gap-2">
              <FolderOpen aria-hidden="true" className="size-4" />
              Open
            </DropdownMenuItem>
          ) : null}

          {/* Edit file */}
          {!entry.is_dir && canWrite ? (
            <DropdownMenuItem onSelect={() => onEdit?.()} className="gap-2">
              <Edit3 aria-hidden="true" className="size-4" />
              Edit
            </DropdownMenuItem>
          ) : null}

          {/* Download (single file) */}
          {!entry.is_dir && canManage ? (
            <DropdownMenuItem
              onSelect={() =>
                download.mutate({ path: entryPath, filename: entry.name })
              }
              disabled={download.isPending}
              className="gap-2"
            >
              <Download aria-hidden="true" className="size-4" />
              Download
            </DropdownMenuItem>
          ) : null}

          {/* Download as ZIP (P3) */}
          {canManage ? (
            <DropdownMenuItem
              onSelect={() => setArchiveOpen(true)}
              className="gap-2"
            >
              <Archive aria-hidden="true" className="size-4" />
              Download as ZIP
            </DropdownMenuItem>
          ) : null}

          {/* Extract (P3 — .zip files only) */}
          {canExtract ? (
            <DropdownMenuItem
              onSelect={() => setExtractOpen(true)}
              className="gap-2"
            >
              <FolderUp aria-hidden="true" className="size-4" />
              Extract here
            </DropdownMenuItem>
          ) : null}

          {/* Rename */}
          {canWrite ? (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => setRenameOpen(true)}
                className="gap-2"
              >
                <Pencil aria-hidden="true" className="size-4" />
                Rename
              </DropdownMenuItem>
            </>
          ) : null}

          {/* chmod */}
          {canWrite ? (
            <DropdownMenuItem
              onSelect={() => setChmodOpen(true)}
              className="gap-2"
            >
              <Lock aria-hidden="true" className="size-4" />
              Permissions
            </DropdownMenuItem>
          ) : null}

          {/* Version history (P3) */}
          {canVersions ? (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => setVersionsOpen(true)}
                className="gap-2"
              >
                <History aria-hidden="true" className="size-4" />
                Version history
              </DropdownMenuItem>
            </>
          ) : null}

          {/* Delete (owner only) */}
          {canDelete ? (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => setDeleteOpen(true)}
                className="gap-2 text-[var(--color-destructive)] focus:text-[var(--color-destructive)]"
              >
                <Trash2 aria-hidden="true" className="size-4" />
                Delete
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Dialogs */}
      {renameOpen ? (
        <FileRenameDialog
          open={renameOpen}
          onClose={() => setRenameOpen(false)}
          siteId={siteId}
          filePath={entryPath}
          currentDirPath={currentDirPath}
          isOwner={isOwner}
          onRenamed={() => setRenameOpen(false)}
        />
      ) : null}

      {deleteOpen ? (
        <FileDeleteDialog
          open={deleteOpen}
          onClose={() => setDeleteOpen(false)}
          siteId={siteId}
          filePath={entryPath}
          isDir={entry.is_dir}
          currentDirPath={currentDirPath}
          onDeleted={() => {
            setDeleteOpen(false);
            onDeleted?.();
          }}
        />
      ) : null}

      {chmodOpen ? (
        <FileChmodDialog
          open={chmodOpen}
          onClose={() => setChmodOpen(false)}
          siteId={siteId}
          filePath={entryPath}
          currentDirPath={currentDirPath}
          isDir={entry.is_dir}
          currentMode={entry.mode ?? "0644"}
          onChanged={() => setChmodOpen(false)}
        />
      ) : null}

      {/* P3 dialogs */}
      {archiveOpen ? (
        <FileArchiveDialog
          open={archiveOpen}
          onClose={() => setArchiveOpen(false)}
          siteId={siteId}
          paths={[entryPath]}
          isOwner={isOwner}
        />
      ) : null}

      {extractOpen ? (
        <FileExtractDialog
          open={extractOpen}
          onClose={() => setExtractOpen(false)}
          siteId={siteId}
          archivePath={entryPath}
          currentDirPath={currentDirPath}
          isOwner={isOwner}
          onExtracted={() => setExtractOpen(false)}
        />
      ) : null}

      {versionsOpen ? (
        <FileVersionHistoryDialog
          open={versionsOpen}
          onClose={() => setVersionsOpen(false)}
          siteId={siteId}
          filePath={entryPath}
          currentDirPath={currentDirPath}
          isOwner={isOwner}
          writeEnabled={writeEnabled}
        />
      ) : null}
    </>
  );
}
