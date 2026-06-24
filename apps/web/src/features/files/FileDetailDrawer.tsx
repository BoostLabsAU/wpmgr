import { useState } from "react";
import {
  AlertTriangle,
  Binary,
  ChevronRight,
  Download,
  Edit3,
  FileX,
  History,
  Lock,
  ShieldAlert,
  X,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { cn, formatBytes, relativeTime } from "@/lib/utils";
import type { FileEntry } from "@wpmgr/api";

import {
  useFileContent,
  SensitiveFileError,
  FileTooLargeError,
} from "./hooks/use-file-content";
import { useFileDownload } from "./hooks/use-file-download";
import { FileVersionHistoryDialog } from "./FileVersionHistoryDialog";

// FileDetailDrawer — opens on a file row click. Shows:
//  - File metadata (path, size, mtime, mode)
//  - Inline preview: base64-decoded text OR binary-detect banner OR truncation banner
//  - Sensitive-path gate (confirm for owners, hard block for others)
//  - Download action via prepareSiteFileDownload → presigned GET
//  - Edit action (P2): only shown when writeEnabled=true, admin+, file is not binary
//
// Widen from the default 480px to 720px, matching AssetDetailDrawer.

export interface FileDetailDrawerProps {
  siteId: string;
  entry: FileEntry | null;
  currentPath: string;
  onClose: () => void;
  /** Whether the current user is an owner (allowed to confirm sensitive reads). */
  isOwner: boolean;
  /** P2: whether write mode is enabled. */
  writeEnabled?: boolean;
  /** P2: whether the current user can manage (admin+). */
  canManage?: boolean;
  /** P2: called when the user wants to edit. Receives decoded text content. */
  onEdit?: (content: string) => void;
}

/** Try to detect binary content by looking for NUL bytes or very high density
 *  of non-printable characters in the first 512 bytes decoded. */
function isBinaryContent(base64: string): boolean {
  try {
    const raw = atob(base64.slice(0, Math.ceil((512 * 4) / 3)));
    let nonPrintable = 0;
    for (let i = 0; i < raw.length; i++) {
      const code = raw.charCodeAt(i);
      // NUL byte is an unambiguous binary signal.
      if (code === 0) return true;
      // Control chars (except tab/LF/CR/FF) are strong binary indicators.
      if (code < 8 || (code >= 14 && code <= 31)) nonPrintable++;
    }
    return nonPrintable / raw.length > 0.1;
  } catch {
    return false;
  }
}

/** Decode base64 to a UTF-8 string. Returns null if decoding fails. */
function decodeBase64Text(base64: string): string | null {
  try {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    return null;
  }
}

export function FileDetailDrawer({
  siteId,
  entry,
  currentPath,
  onClose,
  isOwner,
  writeEnabled = false,
  canManage = false,
  onEdit,
}: FileDetailDrawerProps) {
  // Track whether the owner has confirmed reading a sensitive file.
  const [confirmedSensitive, setConfirmedSensitive] = useState(false);
  // P3: version history dialog
  const [versionsOpen, setVersionsOpen] = useState(false);
  // Reset confirmation when the selected file changes.
  const filePath = entry
    ? currentPath
      ? `${currentPath}/${entry.name}`
      : entry.name
    : null;

  const content = useFileContent({
    siteId,
    path: filePath,
    confirmSensitive: confirmedSensitive,
  });

  const download = useFileDownload(siteId);

  // Reset sensitive confirmation when drawer opens a new file.
  const handleClose = () => {
    setConfirmedSensitive(false);
    setVersionsOpen(false);
    onClose();
  };

  if (!entry) return null;

  const isSensitiveError = content.error instanceof SensitiveFileError;
  const isTooLargeError = content.error instanceof FileTooLargeError;
  const isOtherError =
    content.isError && !isSensitiveError && !isTooLargeError;
  const sensitiveMessage = isSensitiveError ? content.error!.message : "";

  // Determine preview content once we have data.
  let previewContent: React.ReactNode = null;
  let decodedText: string | null = null;
  let isBinary = false;

  if (content.data) {
    isBinary = isBinaryContent(content.data.content_base64);
    if (isBinary) {
      previewContent = (
        <div className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-4 py-3">
          <Binary
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-[var(--color-foreground)]">
              Binary file
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              This file contains binary data and cannot be previewed as text.
              Download it to view the full contents.
            </p>
          </div>
        </div>
      );
    } else {
      decodedText = decodeBase64Text(content.data.content_base64);
      if (decodedText === null) {
        previewContent = (
          <div className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-4 py-3">
            <FileX
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
            />
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Could not decode file as UTF-8 text. Download to view the full
              file.
            </p>
          </div>
        );
      } else {
        previewContent = (
          <div className="space-y-2">
            {content.data.truncated ? (
              <div className="flex items-center gap-1.5 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-3 py-2 text-xs text-[var(--color-warning)]">
                <AlertTriangle aria-hidden="true" className="size-3.5 shrink-0" />
                Showing the first 256 KiB. Download for the full file.
              </div>
            ) : null}
            <pre
              aria-label="File content preview"
              className="max-h-96 overflow-auto rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-3 font-mono text-[11px] leading-relaxed text-[var(--color-foreground)] scrollbar-thin"
            >
              {decodedText}
            </pre>
          </div>
        );
      }
    }
  }

  const mtime = entry.mtime
    ? new Date(entry.mtime * 1000).toLocaleString()
    : null;
  const mtimeRelative = entry.mtime
    ? relativeTime(new Date(entry.mtime * 1000).toISOString())
    : null;

  // Edit is shown when: admin+, write_enabled, file is text, not too large.
  const canEdit =
    canManage &&
    writeEnabled &&
    !entry.is_dir &&
    decodedText !== null &&
    !isBinary &&
    !isTooLargeError &&
    !isSensitiveError;

  return (
    <>
    <Dialog open={true} onClose={handleClose}>
      <DialogContent
        ariaLabelledBy="file-detail-title"
        className="max-w-[min(720px,calc(100vw-2rem))]"
      >
        <DialogHeader>
          <div className="flex items-start justify-between gap-3">
            <DialogTitle id="file-detail-title">
              <span className="block min-w-0 truncate font-mono text-sm font-medium text-[var(--color-foreground)]">
                {entry.name}
              </span>
            </DialogTitle>
            <button
              type="button"
              aria-label="Close file preview"
              onClick={handleClose}
              className="shrink-0 rounded p-1 text-[var(--color-muted-foreground)] hover:bg-[var(--color-accent)] hover:text-[var(--color-accent-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
            >
              <X aria-hidden="true" className="size-4" />
            </button>
          </div>

          {/* Breadcrumb path */}
          <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
            {currentPath ? (
              <>
                <span className="opacity-60">{currentPath}</span>
                <ChevronRight
                  aria-hidden="true"
                  className="inline size-3 align-middle"
                />
              </>
            ) : null}
            {entry.name}
          </p>
        </DialogHeader>

        <DialogBody className="space-y-4">
          {/* Metadata strip */}
          <div className="grid grid-cols-3 gap-px overflow-hidden rounded-md border border-[var(--color-border)] bg-[var(--color-border)]">
            <MetaTile label="Size" value={formatBytes(entry.size)} />
            <MetaTile
              label="Modified"
              value={mtimeRelative ?? "—"}
              title={mtime ?? undefined}
            />
            <MetaTile label="Mode" value={entry.mode || "—"} mono />
          </div>

          {/* Preview body */}
          {content.isPending ? (
            <div className="space-y-2" aria-label="Loading file content">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-5/6" />
              <Skeleton className="h-4 w-4/6" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-3/6" />
            </div>
          ) : isSensitiveError ? (
            <SensitiveGate
              isOwner={isOwner}
              isPending={content.isFetching}
              onConfirm={() => setConfirmedSensitive(true)}
              message={sensitiveMessage}
            />
          ) : isTooLargeError ? (
            <div className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-4 py-3">
              <AlertTriangle
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
              />
              <div className="space-y-1">
                <p className="text-sm font-medium text-[var(--color-foreground)]">
                  File too large for inline preview
                </p>
                <p className="text-xs text-[var(--color-muted-foreground)]">
                  This file exceeds the 256 KiB inline limit. Download it to
                  view the full contents.
                </p>
              </div>
            </div>
          ) : isOtherError ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-4 py-3 text-xs text-[var(--color-destructive)]"
            >
              {(content.error instanceof Error ? content.error.message : null) ?? "Could not read file."}
            </div>
          ) : (
            previewContent
          )}
        </DialogBody>

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={handleClose}>
            Close
          </Button>
          {/* Version history (P3): admin+, files only */}
          {canManage && !entry.is_dir ? (
            <Button
              type="button"
              variant="outline"
              onClick={() => setVersionsOpen(true)}
              className="gap-1.5"
            >
              <History aria-hidden="true" className="size-4" />
              History
            </Button>
          ) : null}
          {/* Edit button: P2, admin+, write_enabled, text file only */}
          {canEdit ? (
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                if (decodedText !== null) onEdit?.(decodedText);
                handleClose();
              }}
              className="gap-1.5"
            >
              <Edit3 aria-hidden="true" className="size-4" />
              Edit
            </Button>
          ) : null}
          <Button
            type="button"
            variant="outline"
            disabled={download.isPending || entry.is_dir}
            onClick={() => {
              if (!entry.is_dir) {
                download.mutate({
                  path: filePath ?? entry.name,
                  filename: entry.name,
                });
              }
            }}
          >
            <Download aria-hidden="true" className="size-4" />
            {download.isPending ? "Preparing..." : "Download"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    {/* P3: Version history dialog — rendered outside the drawer dialog to avoid
        portal stacking conflicts. It is only mounted when versionsOpen=true. */}
    {versionsOpen && filePath ? (
      <FileVersionHistoryDialog
        open={versionsOpen}
        onClose={() => setVersionsOpen(false)}
        siteId={siteId}
        filePath={filePath}
        currentDirPath={currentPath}
        isOwner={isOwner}
        writeEnabled={writeEnabled}
      />
    ) : null}
    </>
  );
}

// ── Sensitive gate ────────────────────────────────────────────────────────

function SensitiveGate({
  isOwner,
  isPending,
  onConfirm,
  message,
}: {
  isOwner: boolean;
  isPending: boolean;
  onConfirm: () => void;
  message: string;
}) {
  if (isOwner) {
    return (
      <div className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-4">
        <div className="flex items-start gap-2">
          <ShieldAlert
            aria-hidden="true"
            className="mt-0.5 size-5 shrink-0 text-[var(--color-warning)]"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-[var(--color-foreground)]">
              Sensitive file
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              {message}. This access will be recorded in the audit log with the
              full file path.
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={isPending}
          onClick={onConfirm}
          className="self-start"
        >
          <ShieldAlert aria-hidden="true" className="size-4" />
          Read sensitive file
        </Button>
      </div>
    );
  }

  return (
    <div className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-4">
      <Lock
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
      />
      <div className="space-y-1">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          Sensitive file
        </p>
        <p className="text-xs text-[var(--color-muted-foreground)]">
          {message}. Only a site owner may preview or download this file.
        </p>
      </div>
    </div>
  );
}

// ── Metadata tile ─────────────────────────────────────────────────────────

function MetaTile({
  label,
  value,
  mono = false,
  title,
}: {
  label: string;
  value: string;
  mono?: boolean;
  title?: string;
}) {
  return (
    <div className="flex flex-col gap-0.5 bg-[var(--color-card)] p-3">
      <span className="text-[11px] uppercase tracking-[0.02em] text-[var(--color-muted-foreground)]">
        {label}
      </span>
      <span
        title={title}
        className={cn(
          "text-sm font-medium tabular-nums text-[var(--color-foreground)]",
          mono && "font-mono",
        )}
      >
        {value}
      </span>
    </div>
  );
}
