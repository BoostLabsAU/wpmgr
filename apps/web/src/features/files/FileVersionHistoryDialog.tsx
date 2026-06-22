import { useId, useState } from "react";
import {
  Clock,
  History,
  Loader2,
  Lock,
  RotateCcw,
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
import { Skeleton } from "@/components/ui/skeleton";
import { cn, formatBytes, relativeTime } from "@/lib/utils";
import type { FileVersion } from "@wpmgr/api";

import {
  ArchiveSensitiveError,
  NoSuchVersionError,
  useFileVersions,
  useRestoreFileVersion,
} from "./hooks/use-file-p3";

// FileVersionHistoryDialog — shows the version history for a file and lets
// an admin restore a prior version.
//
// Flow:
//  1. Load via listSiteFileVersions (useFileVersions).
//  2. Empty state: "No prior versions" with explanation.
//  3. Select a version row -> show restore confirm inline.
//  4. Confirm restore -> POST /files/versions/restore.
//  5. Sensitive-path gate: owner must confirm; non-owner blocked.
//  6. NoSuchVersionError: surfaced inline (version was garbage-collected).

export interface FileVersionHistoryDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved site-relative file path. */
  filePath: string;
  currentDirPath: string;
  isOwner: boolean;
  writeEnabled: boolean;
}

export function FileVersionHistoryDialog({
  open,
  onClose,
  siteId,
  filePath,
  currentDirPath,
  isOwner,
  writeEnabled,
}: FileVersionHistoryDialogProps) {
  const titleId = useId();
  const [selectedVersion, setSelectedVersion] = useState<FileVersion | null>(
    null,
  );
  const [sensitiveBlocked, setSensitiveBlocked] = useState(false);
  const [noSuchVersion, setNoSuchVersion] = useState(false);

  const versions = useFileVersions(siteId, open ? filePath : null, open);
  const restore = useRestoreFileVersion(siteId, currentDirPath);

  const fileName = filePath.split("/").pop() ?? filePath;

  // Reset selection when dialog opens.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setSelectedVersion(null);
      setSensitiveBlocked(false);
      setNoSuchVersion(false);
      restore.reset();
    }
  }

  const handleSelectVersion = (v: FileVersion) => {
    setSelectedVersion(v);
    setSensitiveBlocked(false);
    setNoSuchVersion(false);
    restore.reset();
  };

  const handleRestore = (confirmSensitive = false) => {
    if (!selectedVersion) return;
    setSensitiveBlocked(false);
    setNoSuchVersion(false);
    restore.mutate(
      {
        path: filePath,
        versionId: selectedVersion.version_id,
        confirmSensitive: confirmSensitive || undefined,
      },
      {
        onSuccess: () => {
          onClose();
        },
        onError: (err) => {
          if (err instanceof ArchiveSensitiveError) {
            setSensitiveBlocked(true);
          } else if (err instanceof NoSuchVersionError) {
            setNoSuchVersion(true);
          }
        },
      },
    );
  };

  const isBusy = restore.isPending;
  const canRestore = writeEnabled && Boolean(selectedVersion) && !isBusy;

  return (
    <Dialog open={open} onClose={isBusy ? () => {} : onClose}>
      <DialogContent
        ariaLabelledBy={titleId}
        className="max-w-[min(640px,calc(100vw-2rem))]"
      >
        <DialogHeader>
          <DialogTitle id={titleId} className="flex items-center gap-2">
            <History aria-hidden="true" className="size-4" />
            Version history
          </DialogTitle>
          <p className="font-mono text-[11px] text-[var(--color-muted-foreground)] truncate">
            {filePath}
          </p>
        </DialogHeader>

        <DialogBody>
          {/* Version list */}
          {versions.isPending ? (
            <VersionListSkeleton />
          ) : versions.isError ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
            >
              {versions.error?.message ?? "Could not load version history"}
            </div>
          ) : versions.data?.versions.length === 0 ? (
            <VersionEmptyState fileName={fileName} />
          ) : (
            <VersionList
              versions={versions.data?.versions ?? []}
              selectedVersion={selectedVersion}
              onSelect={handleSelectVersion}
              isBusy={isBusy}
            />
          )}

          {/* Restore confirm section */}
          {selectedVersion && writeEnabled ? (
            <RestoreConfirmSection
              version={selectedVersion}
              filePath={filePath}
              isOwner={isOwner}
              isBusy={isBusy}
              sensitiveBlocked={sensitiveBlocked}
              noSuchVersion={noSuchVersion}
              restoreError={
                restore.isError &&
                !sensitiveBlocked &&
                !noSuchVersion
                  ? (restore.error?.message ?? null)
                  : null
              }
              onConfirmSensitive={() => handleRestore(true)}
            />
          ) : selectedVersion && !writeEnabled ? (
            <div className="rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-2">
              <p className="text-xs text-[var(--color-muted-foreground)]">
                Write mode is off. Enable write mode to restore this version.
              </p>
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
            Close
          </Button>
          {selectedVersion && writeEnabled && !sensitiveBlocked ? (
            <Button
              type="button"
              disabled={!canRestore}
              onClick={() => handleRestore()}
              className="gap-1.5"
            >
              {isBusy ? (
                <>
                  <Loader2
                    aria-hidden="true"
                    className="size-4 animate-spin"
                  />
                  Restoring...
                </>
              ) : (
                <>
                  <RotateCcw aria-hidden="true" className="size-4" />
                  Restore this version
                </>
              )}
            </Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Version list ──────────────────────────────────────────────────────────────

function VersionList({
  versions,
  selectedVersion,
  onSelect,
  isBusy,
}: {
  versions: FileVersion[];
  selectedVersion: FileVersion | null;
  onSelect: (v: FileVersion) => void;
  isBusy: boolean;
}) {
  return (
    <ul
      role="listbox"
      aria-label="File versions"
      className="max-h-64 overflow-y-auto rounded-md border border-[var(--color-border)] bg-[var(--color-card)]"
    >
      {versions.map((v, i) => {
        const isSelected = selectedVersion?.version_id === v.version_id;
        const createdIso = new Date(v.created_at * 1000).toISOString();
        const isLatest = i === 0;

        return (
          <li key={v.version_id} role="option" aria-selected={isSelected}>
            <button
              type="button"
              disabled={isBusy}
              onClick={() => onSelect(v)}
              className={cn(
                "flex w-full items-center gap-3 border-b border-[var(--color-border)] px-4 py-2.5 text-left last:border-0",
                "transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-ring)]",
                isSelected
                  ? "bg-[var(--color-primary)]/10"
                  : "hover:bg-[var(--color-muted)]",
                isBusy && "cursor-not-allowed opacity-60",
              )}
            >
              <Clock
                aria-hidden="true"
                className={cn(
                  "size-4 shrink-0",
                  isSelected
                    ? "text-[var(--color-primary)]"
                    : "text-[var(--color-muted-foreground)]/60",
                )}
              />
              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                  <span
                    className="block text-sm font-medium text-[var(--color-foreground)] tabular-nums"
                    title={new Date(v.created_at * 1000).toLocaleString()}
                  >
                    {relativeTime(createdIso) ?? new Date(v.created_at * 1000).toLocaleString()}
                  </span>
                  {isLatest ? (
                    <span className="rounded-sm bg-[var(--color-muted)] px-1 py-px font-mono text-[10px] text-[var(--color-muted-foreground)]">
                      latest
                    </span>
                  ) : null}
                </span>
                <span className="text-xs tabular-nums text-[var(--color-muted-foreground)]">
                  {formatBytes(v.size)}
                </span>
              </span>
              {isSelected ? (
                <span
                  aria-hidden="true"
                  className="size-2 rounded-full bg-[var(--color-primary)] shrink-0"
                />
              ) : null}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

// ── Restore confirm section ───────────────────────────────────────────────────

function RestoreConfirmSection({
  version,
  filePath,
  isOwner,
  isBusy,
  sensitiveBlocked,
  noSuchVersion,
  restoreError,
  onConfirmSensitive,
}: {
  version: FileVersion;
  filePath: string;
  isOwner: boolean;
  isBusy: boolean;
  sensitiveBlocked: boolean;
  noSuchVersion: boolean;
  restoreError: string | null;
  onConfirmSensitive: () => void;
}) {
  const createdIso = new Date(version.created_at * 1000).toISOString();

  return (
    <div className="space-y-2">
      {/* Restore info */}
      <div className="rounded-md border border-[var(--color-warning)]/30 bg-[var(--color-warning)]/8 px-3 py-2">
        <p className="text-xs text-[var(--color-foreground)]">
          Restoring will overwrite{" "}
          <span className="font-mono">{filePath}</span> with the version from{" "}
          <span className="font-medium">
            {relativeTime(createdIso) ??
              new Date(version.created_at * 1000).toLocaleString()}
          </span>{" "}
          ({formatBytes(version.size)}). The current version is saved
          automatically before the restore.
        </p>
      </div>

      {/* No-such-version error */}
      {noSuchVersion ? (
        <div
          role="alert"
          className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
        >
          This version no longer exists. It may have been garbage-collected.
          Reload the version list to see current versions.
        </div>
      ) : null}

      {/* Sensitive gate */}
      {sensitiveBlocked ? (
        isOwner ? (
          <div
            role="alert"
            className="flex flex-col gap-2 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-3"
          >
            <div className="flex items-start gap-2">
              <ShieldAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
              />
              <div className="space-y-1">
                <p className="text-sm font-medium text-[var(--color-foreground)]">
                  Restoring a sensitive file
                </p>
                <p className="text-xs text-[var(--color-muted-foreground)]">
                  This file is on the sensitive-path list (wp-config.php, .env,
                  private keys). Restoring it will update credentials or
                  configuration on the live site. This action will be audited.
                </p>
              </div>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isBusy}
              onClick={onConfirmSensitive}
              className="self-start gap-1.5"
            >
              <ShieldAlert aria-hidden="true" className="size-4" />
              Restore sensitive file
            </Button>
          </div>
        ) : (
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
                Sensitive file
              </p>
              <p className="text-xs text-[var(--color-muted-foreground)]">
                Only a site owner may restore sensitive files such as
                wp-config.php or private keys.
              </p>
            </div>
          </div>
        )
      ) : null}

      {/* Generic error */}
      {restoreError ? (
        <div
          role="alert"
          className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
        >
          {restoreError}
        </div>
      ) : null}
    </div>
  );
}

// ── Empty state ───────────────────────────────────────────────────────────────

function VersionEmptyState({ fileName }: { fileName: string }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] py-10 text-center">
      <History
        aria-hidden="true"
        className="size-8 text-[var(--color-muted-foreground)]/40"
      />
      <div className="space-y-1">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          No prior versions
        </p>
        <p className="max-w-xs text-xs text-[var(--color-muted-foreground)]">
          Versions of{" "}
          <span className="font-mono">{fileName}</span> are saved
          automatically when you edit or overwrite the file. No saves have
          been recorded yet.
        </p>
      </div>
    </div>
  );
}

// ── Loading skeleton ──────────────────────────────────────────────────────────

function VersionListSkeleton() {
  return (
    <div
      aria-label="Loading version history"
      aria-busy="true"
      className="overflow-hidden rounded-md border border-[var(--color-border)] bg-[var(--color-card)]"
    >
      {Array.from({ length: 4 }).map((_, i) => (
        <div
          key={i}
          className="flex h-12 items-center gap-3 border-b border-[var(--color-border)] px-4 last:border-0"
        >
          <Skeleton className="size-4 rounded" />
          <div className="space-y-1.5 flex-1">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-2.5 w-16" />
          </div>
        </div>
      ))}
    </div>
  );
}
