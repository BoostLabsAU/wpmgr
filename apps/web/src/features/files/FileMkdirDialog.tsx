import { useId, useState } from "react";
import { FolderPlus } from "lucide-react";
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

import { useCreateDirectory } from "./hooks/use-file-mutations";

// FileMkdirDialog — create a new directory under the current path.

export interface FileMkdirDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  currentDirPath: string;
  onCreated?: () => void;
}

export function FileMkdirDialog({
  open,
  onClose,
  siteId,
  currentDirPath,
  onCreated,
}: FileMkdirDialogProps) {
  const titleId = useId();
  const inputId = useId();
  const [name, setName] = useState("");

  const mkdir = useCreateDirectory(siteId, currentDirPath);

  // Reset on open.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setName("");
      mkdir.reset();
    }
  }

  const targetPath = currentDirPath
    ? `${currentDirPath}/${name.trim()}`
    : name.trim();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    mkdir.mutate(
      { path: targetPath },
      {
        onSuccess: () => {
          onClose();
          onCreated?.();
        },
      },
    );
  };

  return (
    <Dialog open={open} onClose={mkdir.isPending ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <FolderPlus
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
            New folder
          </DialogTitle>
          {currentDirPath ? (
            <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
              Inside: {currentDirPath}
            </p>
          ) : (
            <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
              Inside: root
            </p>
          )}
        </DialogHeader>

        <form onSubmit={handleSubmit} noValidate>
          <DialogBody>
            <div className="space-y-2">
              <Label htmlFor={inputId}>Folder name</Label>
              <Input
                id={inputId}
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoComplete="off"
                autoCorrect="off"
                spellCheck={false}
                placeholder="my-folder"
                disabled={mkdir.isPending}
                data-autofocus
                aria-invalid={name.length > 0 && !name.trim() ? true : undefined}
              />
              {name.trim() ? (
                <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
                  Path: {targetPath}
                </p>
              ) : null}
            </div>

            {mkdir.isError ? (
              <p
                role="alert"
                className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-2 text-sm text-[var(--color-destructive)]"
              >
                {mkdir.error?.message ?? "Could not create folder"}
              </p>
            ) : null}
          </DialogBody>

          <DialogFooter className="mt-4">
            <Button
              type="button"
              variant="ghost"
              disabled={mkdir.isPending}
              onClick={onClose}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="default"
              disabled={mkdir.isPending || !name.trim()}
            >
              {mkdir.isPending ? (
                <>
                  <Loader2 aria-hidden="true" className="animate-spin" />
                  <span className="sr-only">Creating...</span>
                </>
              ) : (
                <>
                  <FolderPlus aria-hidden="true" className="size-4" />
                  Create folder
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
