import { useState } from "react";
import {
  Download,
  Edit3,
  FolderOpen,
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

// FileActionMenu — per-row dropdown for file actions.
//
// Actions and their permission gates:
//   - Open (dir) / Edit (file): admin+, write_enabled for Edit
//   - Download (file only): admin+
//   - Rename: admin+, write_enabled
//   - chmod: admin+, write_enabled
//   - Delete: owner only
//
// The Edit action (open FileEditDialog) is handled by the parent (the drawer
// already does it); we emit `onEdit` so FileBrowser can open the edit dialog.

export interface FileActionMenuProps {
  siteId: string;
  entry: FileEntry;
  currentDirPath: string;
  entryPath: string;
  /** Whether write mode is on (write_enabled=true in settings). */
  writeEnabled: boolean;
  /** Whether the user is admin+ (can manage). */
  canManage: boolean;
  /** Whether the user is owner. */
  isOwner: boolean;
  /** Called when the user clicks "Edit" for a file. */
  onEdit?: () => void;
  /** Called when the user clicks "Open" for a directory. */
  onOpen?: () => void;
  /** Called after a successful destructive action (delete). */
  onDeleted?: () => void;
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

  const download = useFileDownload(siteId);

  const canWrite = canManage && writeEnabled;
  const canDelete = isOwner && writeEnabled;

  const hasAnyAction = entry.is_dir || (!entry.is_dir && (canManage || canWrite || canDelete));

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
            <DropdownMenuItem
              onSelect={() => onOpen?.()}
              className="gap-2"
            >
              <FolderOpen aria-hidden="true" className="size-4" />
              Open
            </DropdownMenuItem>
          ) : null}

          {/* Edit file */}
          {!entry.is_dir && canWrite ? (
            <DropdownMenuItem
              onSelect={() => onEdit?.()}
              className="gap-2"
            >
              <Edit3 aria-hidden="true" className="size-4" />
              Edit
            </DropdownMenuItem>
          ) : null}

          {/* Download (files only) */}
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

      {/* Dialogs — rendered outside the menu to avoid portal conflicts */}
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
    </>
  );
}
