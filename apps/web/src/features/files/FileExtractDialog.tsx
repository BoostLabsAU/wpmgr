import { useId, useState } from "react";
import {
  AlertTriangle,
  FolderOpen,
  Loader2,
  Lock,
  ShieldAlert,
} from "lucide-react";

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
import { cn } from "@/lib/utils";

import {
  ArchiveExecutableError,
  ArchiveSensitiveError,
  BadArchiveError,
  NotArchiveError,
  ZipBombError,
  ZipSlipError,
  useExtractFileArchive,
} from "./hooks/use-file-p3";

// FileExtractDialog — extract a ZIP archive on the site.
//
// Security error flows:
//   zip_slip (422)  — "This archive is unsafe to extract" (path traversal).
//   zip_bomb (422)  — "Archive exceeds size limit" (decompression bomb guard).
//   bad_archive     — "The archive is corrupted or unreadable."
//   not_archive     — "This file is not a recognised archive format."
//   executable_write_denied (403) — owner gets a combined explicit confirm; non-owner blocked.
//   sensitive_denied (403)        — owner gets a combined explicit confirm; non-owner blocked.
//
// Combined confirm: when both executable and sensitive gates trigger (either
// sequentially or simultaneously), they are coalesced into a single warning
// block with one confirm button that sends BOTH flags. This eliminates the
// two-round-trip confirm sequence.
//
// The destination path defaults to a sibling folder named after the archive
// (e.g., "archive.zip" -> "archive"). The user can edit it before confirming.

export interface FileExtractDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved site-relative path of the ZIP file. */
  archivePath: string;
  /** Current directory (used to compute the default dest path + invalidation). */
  currentDirPath: string;
  isOwner: boolean;
  onExtracted?: () => void;
}

/** Derive a sensible default destination from the archive's basename. */
function defaultDestPath(archivePath: string): string {
  const base = archivePath.split("/").pop() ?? archivePath;
  // Remove .zip extension if present.
  const withoutExt = base.replace(/\.zip$/i, "");
  const dir = archivePath.includes("/")
    ? archivePath.slice(0, archivePath.lastIndexOf("/"))
    : "";
  return dir ? `${dir}/${withoutExt}` : withoutExt;
}

// Security error states tracked in the dialog.
//
// "combined" represents both executable AND sensitive gates firing together
// (accumulated from two sequential denials). Hard-blocked states (zip_slip,
// zip_bomb, bad_archive, not_archive) never get an override; non-owner
// executable/sensitive blocks are separate.
type BlockReason =
  | "zip_slip"
  | "zip_bomb"
  | "bad_archive"
  | "not_archive"
  | "executable"
  | "sensitive"
  | "combined"      // both executable + sensitive
  | null;

export function FileExtractDialog({
  open,
  onClose,
  siteId,
  archivePath,
  currentDirPath,
  isOwner,
  onExtracted,
}: FileExtractDialogProps) {
  const titleId = useId();
  const destId = useId();

  const [destPath, setDestPath] = useState(() => defaultDestPath(archivePath));
  const [blockReason, setBlockReason] = useState<BlockReason>(null);

  // Track which confirm flags have been accumulated from sequential denials.
  // These are ONLY set when the user deliberately chooses to override.
  const [pendingExec, setPendingExec] = useState(false);
  const [pendingSens, setPendingSens] = useState(false);

  const extract = useExtractFileArchive(siteId, currentDirPath);

  // Reset when dialog opens.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setDestPath(defaultDestPath(archivePath));
      setBlockReason(null);
      setPendingExec(false);
      setPendingSens(false);
      extract.reset();
    }
  }

  const doExtract = (opts: {
    confirmExecutableWrite: boolean;
    confirmSensitive: boolean;
  }) => {
    setBlockReason(null);
    extract.mutate(
      {
        archivePath,
        destPath: destPath.trim() || defaultDestPath(archivePath),
        confirmExecutableWrite: opts.confirmExecutableWrite,
        confirmSensitive: opts.confirmSensitive,
      },
      {
        onSuccess: () => {
          onExtracted?.();
          onClose();
        },
        onError: (err) => {
          if (err instanceof ZipSlipError) {
            setBlockReason("zip_slip");
          } else if (err instanceof ZipBombError) {
            setBlockReason("zip_bomb");
          } else if (err instanceof BadArchiveError) {
            setBlockReason("bad_archive");
          } else if (err instanceof NotArchiveError) {
            setBlockReason("not_archive");
          } else if (err instanceof ArchiveExecutableError) {
            // If we already had a sensitive block pending, coalesce into combined.
            if (pendingSens) {
              setBlockReason("combined");
            } else {
              setBlockReason("executable");
            }
          } else if (err instanceof ArchiveSensitiveError) {
            // If we already had an executable block pending, coalesce into combined.
            if (pendingExec) {
              setBlockReason("combined");
            } else {
              setBlockReason("sensitive");
            }
          }
        },
      },
    );
  };

  const handleInitialExtract = () => {
    doExtract({ confirmExecutableWrite: false, confirmSensitive: false });
  };

  // Owner confirms the executable gate alone; immediately re-tries with that
  // flag set. If the server then returns a sensitive denial too, the next
  // onError coalesces into "combined".
  const handleConfirmExec = () => {
    setPendingExec(true);
    setBlockReason(null);
    doExtract({ confirmExecutableWrite: true, confirmSensitive: pendingSens });
  };

  // Owner confirms the sensitive gate alone.
  const handleConfirmSens = () => {
    setPendingSens(true);
    setBlockReason(null);
    doExtract({ confirmExecutableWrite: pendingExec, confirmSensitive: true });
  };

  // Owner confirms BOTH gates at once (combined block, or the user just hits
  // the combined confirm button after seeing either block first).
  const handleConfirmBoth = () => {
    setPendingExec(true);
    setPendingSens(true);
    setBlockReason(null);
    doExtract({ confirmExecutableWrite: true, confirmSensitive: true });
  };

  const isBusy = extract.isPending;

  const isHardBlocked =
    blockReason === "zip_slip" ||
    blockReason === "zip_bomb" ||
    blockReason === "bad_archive" ||
    blockReason === "not_archive" ||
    ((blockReason === "executable" ||
      blockReason === "sensitive" ||
      blockReason === "combined") &&
      !isOwner);

  const hasGenericError =
    extract.isError && blockReason === null;

  return (
    <Dialog open={open} onClose={isBusy ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <FolderOpen aria-hidden="true" className="size-4" />
            Extract archive
          </DialogTitle>
        </DialogHeader>

        <DialogBody>
          {/* Archive path */}
          <div className="rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-2">
            <p className="font-mono text-xs text-[var(--color-foreground)] truncate">
              {archivePath}
            </p>
          </div>

          {/* Destination path input */}
          <div className="space-y-1.5">
            <Label htmlFor={destId}>Extract to</Label>
            <Input
              id={destId}
              value={destPath}
              onChange={(e) => setDestPath(e.target.value)}
              disabled={isBusy}
              placeholder="Destination directory path"
              className="font-mono text-xs"
            />
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Site-relative path. The directory will be created if it does not
              exist.
            </p>
          </div>

          {/* Security / format error blocks */}
          {blockReason ? (
            <ExtractErrorBlock
              reason={blockReason}
              isOwner={isOwner}
              isBusy={isBusy}
              onConfirmExec={handleConfirmExec}
              onConfirmSens={handleConfirmSens}
              onConfirmBoth={handleConfirmBoth}
              archivePath={archivePath}
            />
          ) : null}

          {/* Generic error */}
          {hasGenericError ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
            >
              {extract.error?.message ?? "Extract failed"}
            </div>
          ) : null}
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            disabled={isBusy}
            onClick={onClose}
          >
            Cancel
          </Button>
          <Button
            type="button"
            disabled={isBusy || isHardBlocked}
            onClick={handleInitialExtract}
            className="gap-1.5"
          >
            {isBusy ? (
              <>
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                Extracting...
              </>
            ) : (
              <>
                <FolderOpen aria-hidden="true" className="size-4" />
                Extract
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Security / format error block ─────────────────────────────────────────────

function ExtractErrorBlock({
  reason,
  isOwner,
  isBusy,
  onConfirmExec,
  onConfirmSens,
  onConfirmBoth,
  archivePath,
}: {
  reason: BlockReason;
  isOwner: boolean;
  isBusy: boolean;
  onConfirmExec: () => void;
  onConfirmSens: () => void;
  onConfirmBoth: () => void;
  archivePath: string;
}) {
  // Hard blocks — no action possible.
  if (reason === "zip_slip") {
    return (
      <div
        role="alert"
        className={cn(
          "flex items-start gap-2 rounded-md border border-[var(--color-destructive)]/40",
          "bg-[var(--color-destructive)]/10 p-3",
        )}
      >
        <AlertTriangle
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-destructive)]">
            Unsafe archive
          </p>
          <p className="text-xs text-[var(--color-foreground)]">
            This archive contains entries that would extract outside the
            destination directory (path traversal). It cannot be extracted for
            security reasons.
          </p>
        </div>
      </div>
    );
  }

  if (reason === "zip_bomb") {
    return (
      <div
        role="alert"
        className={cn(
          "flex items-start gap-2 rounded-md border border-[var(--color-destructive)]/40",
          "bg-[var(--color-destructive)]/10 p-3",
        )}
      >
        <AlertTriangle
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-destructive)]">
            Archive too large
          </p>
          <p className="text-xs text-[var(--color-foreground)]">
            This archive exceeds the maximum allowed uncompressed size or entry
            count. It cannot be extracted to protect the server from resource
            exhaustion.
          </p>
        </div>
      </div>
    );
  }

  if (reason === "bad_archive") {
    return (
      <div
        role="alert"
        className={cn(
          "flex items-start gap-2 rounded-md border border-[var(--color-destructive)]/40",
          "bg-[var(--color-destructive)]/10 p-3",
        )}
      >
        <AlertTriangle
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-destructive)]">
            Corrupted archive
          </p>
          <p className="text-xs text-[var(--color-foreground)]">
            The file at{" "}
            <span className="font-mono">{archivePath}</span> is corrupted or
            could not be opened. Try re-uploading a fresh copy.
          </p>
        </div>
      </div>
    );
  }

  if (reason === "not_archive") {
    return (
      <div
        role="alert"
        className={cn(
          "flex items-start gap-2 rounded-md border border-[var(--color-destructive)]/40",
          "bg-[var(--color-destructive)]/10 p-3",
        )}
      >
        <AlertTriangle
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-destructive)]">
            Not an archive
          </p>
          <p className="text-xs text-[var(--color-foreground)]">
            The file at{" "}
            <span className="font-mono">{archivePath}</span> is not a
            recognised archive format. Only ZIP files can be extracted.
          </p>
        </div>
      </div>
    );
  }

  // ── Combined block (both executable + sensitive) ───────────────────────────
  //
  // Shown when both gates fired during this session (either together or
  // sequentially). A single confirm sends both flags in one call.
  if (reason === "combined") {
    if (!isOwner) {
      return (
        <NonOwnerBlock label="Executable and sensitive files require owner permission" />
      );
    }
    return (
      <div
        role="alert"
        className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-3"
      >
        <div className="flex items-start gap-2">
          <ShieldAlert
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
          />
          <div className="space-y-1.5">
            <p className="text-sm font-medium text-[var(--color-foreground)]">
              Archive contains executable and sensitive files
            </p>
            <ul className="space-y-1 text-xs text-[var(--color-muted-foreground)]">
              <li>
                Executable files with extensions such as PHP scripts or server
                configuration files are present. Extracting them can affect site
                security.
              </li>
              <li>
                Sensitive files such as wp-config.php, .env, or private keys
                will be overwritten. This can alter database credentials and
                site configuration.
              </li>
            </ul>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              This action will be audited.
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isBusy}
          onClick={onConfirmBoth}
          className="self-start gap-1.5"
        >
          <ShieldAlert aria-hidden="true" className="size-4" />
          I understand, extract anyway
        </Button>
      </div>
    );
  }

  // ── Single executable block ────────────────────────────────────────────────
  if (reason === "executable") {
    if (!isOwner) {
      return (
        <NonOwnerBlock label="Executable files require owner permission" />
      );
    }
    return (
      <div
        role="alert"
        className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-3"
      >
        <div className="flex items-start gap-2">
          <ShieldAlert
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-[var(--color-foreground)]">
              Archive contains executable files
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              This archive includes files with executable extensions such as
              PHP scripts or server configuration files. Extracting executable
              content can affect site security. This action will be audited.
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isBusy}
          onClick={onConfirmExec}
          className="self-start gap-1.5"
        >
          <ShieldAlert aria-hidden="true" className="size-4" />
          Extract including executable files
        </Button>
      </div>
    );
  }

  // ── Single sensitive block ─────────────────────────────────────────────────
  if (reason === "sensitive") {
    if (!isOwner) {
      return (
        <NonOwnerBlock label="Sensitive files require owner permission" />
      );
    }
    return (
      <div
        role="alert"
        className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-3"
      >
        <div className="flex items-start gap-2">
          <ShieldAlert
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-[var(--color-foreground)]">
              Archive overwrites sensitive files
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              This archive would overwrite sensitive files such as
              wp-config.php, .env, or private keys. This can alter database
              credentials and site configuration. This action will be audited.
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isBusy}
          onClick={onConfirmSens}
          className="self-start gap-1.5"
        >
          <ShieldAlert aria-hidden="true" className="size-4" />
          Extract including sensitive files
        </Button>
      </div>
    );
  }

  return null;
}

// ── Shared non-owner block ─────────────────────────────────────────────────────

function NonOwnerBlock({ label }: { label: string }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-3"
    >
      <Lock
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
      />
      <div className="space-y-1">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          {label}
        </p>
        <p className="text-xs text-[var(--color-muted-foreground)]">
          Only a site owner may perform this extraction.
        </p>
      </div>
    </div>
  );
}
