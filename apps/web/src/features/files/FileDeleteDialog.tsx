import { useId, useState } from "react";
import { AlertTriangle, ShieldAlert, Trash2 } from "lucide-react";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

import { ProtectedRootError, useDeleteFile } from "./hooks/use-file-mutations";

// FileDeleteDialog — owner-only delete with type-to-confirm "DELETE" token.
//
// Gates:
//   - Only rendered when isOwner=true (callers are responsible for this).
//   - The user must type "DELETE" exactly before the button enables.
//   - Non-empty directories require checking the "recursive" checkbox which
//     shows an extra warning about deleting all contents.
//   - Protected roots (wp-admin, wp-includes) return ProtectedRootError from
//     the server — surfaced clearly without re-triggering delete.

export interface FileDeleteDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved path of the file or directory to delete. */
  filePath: string;
  /** If true, the path is a directory (shows recursive option). */
  isDir: boolean;
  currentDirPath: string;
  onDeleted?: () => void;
}

export function FileDeleteDialog({
  open,
  onClose,
  siteId,
  filePath,
  isDir,
  currentDirPath,
  onDeleted,
}: FileDeleteDialogProps) {
  const titleId = useId();
  const inputId = useId();
  const [typed, setTyped] = useState("");
  const [recursive, setRecursive] = useState(false);
  const [protectedError, setProtectedError] = useState<string | null>(null);

  const deleteMutation = useDeleteFile(siteId, currentDirPath);

  // Reset when dialog opens.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setTyped("");
      setRecursive(false);
      setProtectedError(null);
      deleteMutation.reset();
    }
  }

  const matches = typed === "DELETE";
  const canConfirm = matches && !deleteMutation.isPending;
  const fileName = filePath.split("/").pop() ?? filePath;

  const handleDelete = () => {
    if (!canConfirm) return;
    setProtectedError(null);
    deleteMutation.mutate(
      { path: filePath, recursive: recursive || undefined },
      {
        onSuccess: () => {
          onClose();
          onDeleted?.();
        },
        onError: (err) => {
          if (err instanceof ProtectedRootError) {
            setProtectedError(err.message);
          }
        },
      },
    );
  };

  return (
    <Dialog
      open={open}
      onClose={deleteMutation.isPending ? () => {} : onClose}
    >
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <Trash2
              aria-hidden="true"
              className="size-4 text-[var(--color-destructive)]"
            />
            Delete {isDir ? "directory" : "file"}
          </DialogTitle>
        </DialogHeader>

        <DialogBody>
          {/* Path display */}
          <div className="rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-2">
            <p className="font-mono text-xs text-[var(--color-foreground)]">
              {filePath}
            </p>
          </div>

          {/* Consequences */}
          <div className="text-sm text-[var(--color-foreground)]">
            <p>
              {isDir
                ? "This will permanently delete the directory and all its contents. This cannot be undone."
                : "This will permanently delete the file. This cannot be undone."}
            </p>
          </div>

          {/* Recursive checkbox for directories */}
          {isDir ? (
            <div
              className={`flex flex-col gap-2 rounded-md border p-3 ${
                recursive
                  ? "border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/5"
                  : "border-[var(--color-border)] bg-[var(--color-muted)]"
              }`}
            >
              <div className="flex items-start gap-2">
                <Checkbox
                  id="recursive"
                  checked={recursive}
                  onChange={(e) => setRecursive(e.target.checked)}
                  disabled={deleteMutation.isPending}
                  aria-describedby="recursive-desc"
                />
                <div className="space-y-0.5">
                  <Label htmlFor="recursive" className="cursor-pointer text-sm">
                    Delete all contents recursively
                  </Label>
                  <p
                    id="recursive-desc"
                    className="text-xs text-[var(--color-muted-foreground)]"
                  >
                    Required if the directory is not empty. All files and
                    subdirectories will be removed.
                  </p>
                </div>
              </div>
              {recursive ? (
                <div className="flex items-start gap-1.5">
                  <AlertTriangle
                    aria-hidden="true"
                    className="mt-0.5 size-3.5 shrink-0 text-[var(--color-destructive)]"
                  />
                  <p className="text-xs text-[var(--color-destructive)]">
                    All files inside <strong>{fileName}</strong> will be
                    permanently deleted.
                  </p>
                </div>
              ) : null}
            </div>
          ) : null}

          {/* Protected-root error */}
          {protectedError ? (
            <div
              role="alert"
              className="flex items-start gap-2 rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-3"
            >
              <ShieldAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
              />
              <div className="space-y-1">
                <p className="text-sm font-medium text-[var(--color-destructive)]">
                  Protected path
                </p>
                <p className="text-xs text-[var(--color-foreground)]">
                  {protectedError}. The agent refuses to delete protected
                  WordPress directories (wp-admin, wp-includes) and their
                  contents.
                </p>
              </div>
            </div>
          ) : null}

          {/* General mutation error */}
          {deleteMutation.isError &&
          !(deleteMutation.error instanceof ProtectedRootError) ? (
            <p
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-2 text-sm text-[var(--color-destructive)]"
            >
              {deleteMutation.error?.message ?? "Delete failed"}
            </p>
          ) : null}

          {/* Type-to-confirm */}
          <div className="space-y-2">
            <Label htmlFor={inputId}>
              Type{" "}
              <code className="rounded-sm bg-[var(--color-muted)] px-1 font-mono text-xs">
                DELETE
              </code>{" "}
              to confirm
            </Label>
            <Input
              id={inputId}
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              autoComplete="off"
              autoCorrect="off"
              spellCheck={false}
              disabled={deleteMutation.isPending}
              aria-invalid={typed.length > 0 && !matches ? true : undefined}
              data-autofocus
            />
          </div>
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            disabled={deleteMutation.isPending}
            onClick={onClose}
          >
            Keep file
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={!canConfirm}
            onClick={handleDelete}
          >
            {deleteMutation.isPending ? (
              <>
                <Loader2 aria-hidden="true" className="animate-spin" />
                <span className="sr-only">Deleting...</span>
              </>
            ) : (
              <>
                <Trash2 aria-hidden="true" className="size-4" />
                Delete {isDir ? "directory" : "file"}
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
