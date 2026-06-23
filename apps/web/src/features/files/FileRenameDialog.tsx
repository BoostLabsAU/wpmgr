import { useId, useState } from "react";
import { Lock, ShieldAlert } from "lucide-react";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
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

import {
  ExecutableWriteError,
  SensitiveWriteError,
  useRenameFile,
} from "./hooks/use-file-mutations";

// FileRenameDialog — rename a file or directory within the jail.
//
// The new name is combined with the current directory path to form the full
// destination. The server checks both src and dst against the jail + the
// exec/sensitive deny-lists, so we surface those gates here too (same flow as
// the editor: server returns 403, owner gets an "I understand" confirm, non-owners
// are blocked).

export interface FileRenameDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved current path. */
  filePath: string;
  /** Parent directory path (used to build the destination). */
  currentDirPath: string;
  isOwner: boolean;
  onRenamed?: () => void;
}

export function FileRenameDialog({
  open,
  onClose,
  siteId,
  filePath,
  currentDirPath,
  isOwner,
  onRenamed,
}: FileRenameDialogProps) {
  const titleId = useId();
  const inputId = useId();

  const currentName = filePath.split("/").pop() ?? filePath;
  const [newName, setNewName] = useState(currentName);
  const [gateError, setGateError] = useState<
    "executable" | "sensitive" | null
  >(null);
  const [confirmExecutable, setConfirmExecutable] = useState(false);
  const [confirmSensitive, setConfirmSensitive] = useState(false);

  const rename = useRenameFile(siteId, currentDirPath);

  // Reset when opening.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setNewName(currentName);
      setGateError(null);
      setConfirmExecutable(false);
      setConfirmSensitive(false);
      rename.reset();
    }
  }

  function dstPath(name: string): string {
    return currentDirPath ? `${currentDirPath}/${name}` : name;
  }

  const doRename = (
    confirmExec: boolean,
    confirmSens: boolean,
  ) => {
    setGateError(null);
    rename.mutate(
      {
        src: filePath,
        dst: dstPath(newName.trim()),
        confirmExecutableWrite: confirmExec || undefined,
        confirmSensitive: confirmSens || undefined,
      },
      {
        onSuccess: () => {
          onClose();
          onRenamed?.();
        },
        onError: (err) => {
          if (err instanceof ExecutableWriteError) {
            setGateError("executable");
          } else if (err instanceof SensitiveWriteError) {
            setGateError("sensitive");
          }
        },
      },
    );
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newName.trim() || newName.trim() === currentName) return;
    doRename(confirmExecutable, confirmSensitive);
  };

  const isBusy = rename.isPending;
  const isUnchanged = newName.trim() === currentName || !newName.trim();

  return (
    <Dialog open={open} onClose={isBusy ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId}>Rename</DialogTitle>
          <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
            {filePath}
          </p>
        </DialogHeader>

        <form onSubmit={handleSubmit} noValidate>
          <DialogBody>
            <div className="space-y-2">
              <Label htmlFor={inputId}>New name</Label>
              <Input
                id={inputId}
                value={newName}
                onChange={(e) => {
                  setNewName(e.target.value);
                  setGateError(null);
                }}
                autoComplete="off"
                autoCorrect="off"
                spellCheck={false}
                disabled={isBusy}
                data-autofocus
                aria-invalid={newName.trim() === "" ? true : undefined}
                aria-describedby={gateError ? "rename-gate-desc" : undefined}
              />
            </div>

            {/* Exec gate */}
            {gateError === "executable" ? (
              isOwner ? (
                <div
                  role="alert"
                  id="rename-gate-desc"
                  className="flex flex-col gap-3 rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-4"
                >
                  <div className="flex items-start gap-2">
                    <ShieldAlert
                      aria-hidden="true"
                      className="mt-0.5 size-5 shrink-0 text-[var(--color-destructive)]"
                    />
                    <div className="space-y-1">
                      <p className="text-sm font-semibold text-[var(--color-foreground)]">
                        Renaming to an executable extension
                      </p>
                      <p className="text-xs text-[var(--color-muted-foreground)]">
                        The new name has an executable extension (.php, .phar,
                        .htaccess, etc.). This allows the file to be executed as
                        server code. Only proceed if you trust this file. This
                        will be audited.
                      </p>
                    </div>
                  </div>
                  <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    disabled={isBusy}
                    onClick={() => {
                      setConfirmExecutable(true);
                      doRename(true, confirmSensitive);
                    }}
                    className="self-start"
                  >
                    I understand, rename to executable
                  </Button>
                </div>
              ) : (
                <NonOwnerBlock kind="executable" id="rename-gate-desc" />
              )
            ) : null}

            {/* Sensitive gate */}
            {gateError === "sensitive" ? (
              isOwner ? (
                <div
                  role="alert"
                  id="rename-gate-desc"
                  className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-4"
                >
                  <div className="flex items-start gap-2">
                    <ShieldAlert
                      aria-hidden="true"
                      className="mt-0.5 size-5 shrink-0 text-[var(--color-warning)]"
                    />
                    <div className="space-y-1">
                      <p className="text-sm font-semibold text-[var(--color-foreground)]">
                        Renaming a sensitive file
                      </p>
                      <p className="text-xs text-[var(--color-muted-foreground)]">
                        The source or destination path is classified as sensitive
                        (wp-config.php, .env files, keys, etc.). This will be
                        audited.
                      </p>
                    </div>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isBusy}
                    onClick={() => {
                      setConfirmSensitive(true);
                      doRename(confirmExecutable, true);
                    }}
                    className="self-start border-[var(--color-warning)]/60 text-[var(--color-warning)] hover:bg-[var(--color-warning)]/10"
                  >
                    I understand, rename sensitive file
                  </Button>
                </div>
              ) : (
                <NonOwnerBlock kind="sensitive" id="rename-gate-desc" />
              )
            ) : null}

            {/* General error */}
            {rename.isError &&
            gateError === null &&
            !(rename.error instanceof ExecutableWriteError) &&
            !(rename.error instanceof SensitiveWriteError) ? (
              <p
                role="alert"
                className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-2 text-sm text-[var(--color-destructive)]"
              >
                {rename.error?.message ?? "Rename failed"}
              </p>
            ) : null}
          </DialogBody>

          <DialogFooter className="mt-4">
            <Button
              type="button"
              variant="ghost"
              disabled={isBusy}
              onClick={onClose}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="default"
              disabled={isBusy || isUnchanged}
            >
              {isBusy ? (
                <>
                  <Loader2 aria-hidden="true" className="animate-spin" />
                  <span className="sr-only">Renaming...</span>
                </>
              ) : (
                "Rename"
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function NonOwnerBlock({
  kind,
  id,
}: {
  kind: "executable" | "sensitive";
  id?: string;
}) {
  return (
    <div
      role="alert"
      id={id}
      className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-4"
    >
      <Lock
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
      />
      <p className="text-xs text-[var(--color-muted-foreground)]">
        {kind === "executable"
          ? "Renaming to an executable extension requires site owner permission."
          : "Renaming a sensitive file requires site owner permission."}
      </p>
    </div>
  );
}
