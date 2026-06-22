import { useId, useState } from "react";
import {
  Archive,
  Download,
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
import { cn } from "@/lib/utils";

import {
  ArchiveSensitiveError,
  useCreateFileArchive,
} from "./hooks/use-file-p3";

// FileArchiveDialog — "Download as ZIP" for one or more paths.
//
// Flow:
//  1. Show the list of paths to be archived.
//  2. Call createSiteFileArchive. On success the browser navigates to the
//     presigned URL (handled in the hook's onSuccess).
//  3. If the server returns a sensitive-gate error (403 sensitive_denied):
//       - Owner: show an explicit "These include sensitive files" confirm that
//         re-sends with confirm_sensitive=true.
//       - Non-owner: show a hard block ("owner only").
//  4. Other errors surface inline.

export interface FileArchiveDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved paths to include in the archive. */
  paths: string[];
  isOwner: boolean;
}

export function FileArchiveDialog({
  open,
  onClose,
  siteId,
  paths,
  isOwner,
}: FileArchiveDialogProps) {
  const titleId = useId();
  const [sensitiveBlocked, setSensitiveBlocked] = useState(false);
  const [confirmedSensitive, setConfirmedSensitive] = useState(false);

  const archive = useCreateFileArchive(siteId);

  // Reset state when the dialog opens.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setSensitiveBlocked(false);
      setConfirmedSensitive(false);
      archive.reset();
    }
  }

  const handleDownload = (withSensitive = false) => {
    archive.mutate(
      { paths, confirmSensitive: withSensitive || confirmedSensitive || undefined },
      {
        onSuccess: () => {
          onClose();
        },
        onError: (err) => {
          if (err instanceof ArchiveSensitiveError) {
            setSensitiveBlocked(true);
          }
        },
      },
    );
  };

  const handleConfirmSensitive = () => {
    setConfirmedSensitive(true);
    setSensitiveBlocked(false);
    handleDownload(true);
  };

  const isBusy = archive.isPending;
  const hasError =
    archive.isError && !(archive.error instanceof ArchiveSensitiveError);
  const label =
    paths.length === 1
      ? `1 item`
      : `${paths.length} items`;

  return (
    <Dialog open={open} onClose={isBusy ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <Archive aria-hidden="true" className="size-4" />
            Download as ZIP
          </DialogTitle>
        </DialogHeader>

        <DialogBody>
          {/* Path list */}
          <div className="rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-3">
            <p className="mb-2 text-xs font-medium text-[var(--color-muted-foreground)] uppercase tracking-[0.02em]">
              {label}
            </p>
            <ul className="max-h-32 overflow-y-auto space-y-0.5">
              {paths.map((p) => (
                <li
                  key={p}
                  className="font-mono text-xs text-[var(--color-foreground)] truncate"
                >
                  {p}
                </li>
              ))}
            </ul>
          </div>

          <p className="text-sm text-[var(--color-foreground)]">
            The selected files will be compressed into a ZIP archive and
            downloaded directly from the site's storage. The download link
            expires after 5 minutes.
          </p>

          {/* General error */}
          {hasError ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
            >
              {archive.error?.message ?? "Archive failed"}
            </div>
          ) : null}

          {/* Sensitive-file gate */}
          {sensitiveBlocked ? (
            <SensitiveGate isOwner={isOwner} onConfirm={handleConfirmSensitive} isBusy={isBusy} />
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
          {/* Hide the primary button while sensitive confirm is showing */}
          {!sensitiveBlocked ? (
            <Button
              type="button"
              disabled={isBusy}
              onClick={() => handleDownload()}
              className="gap-1.5"
            >
              {isBusy ? (
                <>
                  <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                  Preparing...
                </>
              ) : (
                <>
                  <Download aria-hidden="true" className="size-4" />
                  Download ZIP
                </>
              )}
            </Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Sensitive-file gate ───────────────────────────────────────────────────────

function SensitiveGate({
  isOwner,
  onConfirm,
  isBusy,
}: {
  isOwner: boolean;
  onConfirm: () => void;
  isBusy: boolean;
}) {
  if (!isOwner) {
    return (
      <div
        role="alert"
        className={cn(
          "flex items-start gap-2 rounded-md border border-[var(--color-border)]",
          "bg-[var(--color-muted)] p-3",
        )}
      >
        <Lock
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            Sensitive files included
          </p>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            One or more selected paths include sensitive files
            (wp-config.php, .env, private keys). Only a site owner may archive
            sensitive files.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40",
        "bg-[var(--color-warning)]/10 p-3",
      )}
    >
      <div className="flex items-start gap-2">
        <ShieldAlert
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            Archive includes sensitive files
          </p>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            One or more paths contain sensitive files such as
            wp-config.php, .env, or private keys. Including them in the
            archive exposes database credentials and secret keys. This
            action will be recorded in the audit log.
          </p>
        </div>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={isBusy}
        onClick={onConfirm}
        className="self-start gap-1.5"
      >
        <ShieldAlert aria-hidden="true" className="size-4" />
        Include sensitive files and download
      </Button>
    </div>
  );
}
