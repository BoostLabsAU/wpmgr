import { useId, useState } from "react";
import { Lock } from "lucide-react";
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
import { cn } from "@/lib/utils";

import { useChmodFile } from "./hooks/use-file-mutations";

// FileChmodDialog — change file or directory permissions.
//
// The server validates against a safe allowlist (no setuid, no world-write).
// We restrict the picker to the common safe modes so users can't accidentally
// set dangerous permissions. The server is the final gate.

export interface FileChmodDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  filePath: string;
  currentDirPath: string;
  isDir: boolean;
  currentMode: string;
  onChanged?: () => void;
}

const FILE_MODES: Array<{ value: string; label: string; description: string }> =
  [
    { value: "0644", label: "0644", description: "Owner read/write, others read-only (default files)" },
    { value: "0640", label: "0640", description: "Owner read/write, group read, others none" },
    { value: "0600", label: "0600", description: "Owner read/write only (private files)" },
  ];

const DIR_MODES: Array<{ value: string; label: string; description: string }> =
  [
    { value: "0755", label: "0755", description: "Owner all, others read+execute (default dirs)" },
    { value: "0750", label: "0750", description: "Owner all, group read+execute, others none" },
    { value: "0700", label: "0700", description: "Owner only (private dirs)" },
  ];

export function FileChmodDialog({
  open,
  onClose,
  siteId,
  filePath,
  currentDirPath,
  isDir,
  currentMode,
  onChanged,
}: FileChmodDialogProps) {
  const titleId = useId();
  const modes = isDir ? DIR_MODES : FILE_MODES;

  // Normalise the current mode for display — strip leading '0' octets if not 4-digit.
  const normalised =
    currentMode.length === 4 ? currentMode : `0${currentMode}`.slice(-4);
  const [selected, setSelected] = useState<string>(
    () => modes.find((m) => m.value === normalised)?.value ?? modes[0]!.value,
  );

  const chmod = useChmodFile(siteId, currentDirPath);

  // Reset on open.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      const found = modes.find((m) => m.value === normalised);
      setSelected(found?.value ?? modes[0]!.value);
      chmod.reset();
    }
  }

  const handleApply = () => {
    chmod.mutate(
      { path: filePath, mode: selected },
      {
        onSuccess: () => {
          onClose();
          onChanged?.();
        },
      },
    );
  };

  const unchanged = selected === normalised;

  return (
    <Dialog open={open} onClose={chmod.isPending ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <Lock
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
            Change permissions
          </DialogTitle>
          <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
            {filePath}
          </p>
        </DialogHeader>

        <DialogBody>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Current permissions:{" "}
            <code className="font-mono text-[var(--color-foreground)]">
              {normalised}
            </code>
          </p>

          <fieldset className="space-y-2">
            <legend className="sr-only">Select permission mode</legend>
            {modes.map((m) => {
              const isSelected = selected === m.value;
              return (
                <label
                  key={m.value}
                  className={cn(
                    "flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors",
                    isSelected
                      ? "border-[var(--color-primary)] bg-[var(--color-primary)]/5"
                      : "border-[var(--color-border)] hover:bg-[var(--color-muted)]",
                    chmod.isPending && "cursor-not-allowed opacity-50",
                  )}
                >
                  <input
                    type="radio"
                    name="chmod-mode"
                    value={m.value}
                    checked={isSelected}
                    onChange={() => setSelected(m.value)}
                    disabled={chmod.isPending}
                    className="mt-0.5 accent-[var(--color-primary)]"
                  />
                  <div className="min-w-0">
                    <p className="font-mono text-sm font-medium text-[var(--color-foreground)]">
                      {m.label}
                    </p>
                    <p className="text-xs text-[var(--color-muted-foreground)]">
                      {m.description}
                    </p>
                  </div>
                </label>
              );
            })}
          </fieldset>

          {chmod.isError ? (
            <p
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-2 text-sm text-[var(--color-destructive)]"
            >
              {chmod.error?.message ?? "chmod failed"}
            </p>
          ) : null}
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="ghost"
            disabled={chmod.isPending}
            onClick={onClose}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="default"
            disabled={chmod.isPending || unchanged}
            onClick={handleApply}
          >
            {chmod.isPending ? (
              <>
                <Loader2 aria-hidden="true" className="animate-spin" />
                <span className="sr-only">Applying...</span>
              </>
            ) : (
              "Apply"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
