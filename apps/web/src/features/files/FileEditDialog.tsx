import { useId, useRef, useState } from "react";
import { AlertTriangle, Lock, Save, ShieldAlert } from "lucide-react";

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
  ExecutableWriteError,
  SensitiveWriteError,
  useWriteFileContent,
} from "./hooks/use-file-mutations";

// FileEditDialog — inline code editor for small text files (admin+, write_enabled).
//
// Security posture (from research §5):
//   - Executable paths (.php, .phar, .htaccess, etc.) require a two-step
//     owner-only confirm: the server returns ExecutableWriteError, then the
//     owner clicks an explicit "I understand, write executable code" button
//     which re-sends with confirm_executable_write=true. Non-owners are blocked.
//   - Sensitive paths (wp-config.php, .env*, *.pem, etc.) use the same flow
//     with confirm_sensitive=true.
//   - 413 = file is > 256 KiB; inline editing is refused, user must download
//     and re-upload.
//   - A "Make a backup before editing" advisory is always shown.
//
// No heavy CodeMirror dep — a monospace scrollable textarea is sufficient for
// the file sizes in scope (≤ 256 KiB). The server is the authoritative gate.

export interface FileEditDialogProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  /** Resolved path of the file relative to the jail root. */
  filePath: string;
  /** Decoded UTF-8 content pre-filled into the editor. */
  initialContent: string;
  currentDirPath: string;
  /** Whether the current user is a site owner. */
  isOwner: boolean;
}

export function FileEditDialog({
  open,
  onClose,
  siteId,
  filePath,
  initialContent,
  currentDirPath,
  isOwner,
}: FileEditDialogProps) {
  const titleId = useId();
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [content, setContent] = useState(initialContent);

  // Track elevated confirm states — only sent after explicit owner action.
  const [confirmExecutable, setConfirmExecutable] = useState(false);
  const [confirmSensitive, setConfirmSensitive] = useState(false);

  // Track the last error for the confirm gates.
  const [gateError, setGateError] = useState<
    "executable" | "sensitive" | null
  >(null);

  const write = useWriteFileContent(siteId, currentDirPath);

  // Reset state when the dialog opens for a new file (derived state pattern).
  const [prevOpen, setPrevOpen] = useState(open);
  const [prevInitialContent, setPrevInitialContent] = useState(initialContent);
  if (open !== prevOpen || (open && initialContent !== prevInitialContent)) {
    setPrevOpen(open);
    setPrevInitialContent(initialContent);
    if (open) {
      setContent(initialContent);
      setConfirmExecutable(false);
      setConfirmSensitive(false);
      setGateError(null);
      write.reset();
    }
  }

  const handleSave = () => {
    setGateError(null);
    write.mutate(
      {
        path: filePath,
        content,
        confirmExecutableWrite: confirmExecutable || undefined,
        confirmSensitive: confirmSensitive || undefined,
      },
      {
        onSuccess: () => {
          onClose();
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

  const handleConfirmExecutable = () => {
    setConfirmExecutable(true);
    setGateError(null);
    // Re-submit with the flag set.
    write.mutate(
      {
        path: filePath,
        content,
        confirmExecutableWrite: true,
        confirmSensitive: confirmSensitive || undefined,
      },
      {
        onSuccess: () => {
          onClose();
        },
        onError: (err) => {
          if (err instanceof SensitiveWriteError) {
            setGateError("sensitive");
          }
        },
      },
    );
  };

  const handleConfirmSensitive = () => {
    setConfirmSensitive(true);
    setGateError(null);
    write.mutate(
      {
        path: filePath,
        content,
        confirmExecutableWrite: confirmExecutable || undefined,
        confirmSensitive: true,
      },
      {
        onSuccess: () => {
          onClose();
        },
        onError: (err) => {
          if (err instanceof ExecutableWriteError) {
            setGateError("executable");
          }
        },
      },
    );
  };

  const isBusy = write.isPending;
  const fileName = filePath.split("/").pop() ?? filePath;

  return (
    <Dialog open={open} onClose={isBusy ? () => {} : onClose}>
      <DialogContent
        ariaLabelledBy={titleId}
        className="max-w-[min(860px,calc(100vw-2rem))]"
      >
        <DialogHeader>
          <DialogTitle id={titleId}>
            <span className="font-mono text-sm">{fileName}</span>
          </DialogTitle>
          <p className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
            {filePath}
          </p>
        </DialogHeader>

        <DialogBody className="space-y-3">
          {/* Backup advisory — always shown */}
          <div className="flex items-start gap-2 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-3 py-2.5">
            <AlertTriangle
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
            />
            <p className="text-xs text-[var(--color-foreground)]">
              <strong>Make a backup before editing.</strong> Editing live files
              can break a running site. The file manager does not snapshot before
              writing in this release.
            </p>
          </div>

          {/* Executable-write gate */}
          {gateError === "executable" ? (
            isOwner ? (
              <ExecutableWriteGate
                isPending={isBusy}
                onConfirm={handleConfirmExecutable}
              />
            ) : (
              <NonOwnerBlock kind="executable" />
            )
          ) : null}

          {/* Sensitive-write gate */}
          {gateError === "sensitive" ? (
            isOwner ? (
              <SensitiveWriteGate
                isPending={isBusy}
                onConfirm={handleConfirmSensitive}
              />
            ) : (
              <NonOwnerBlock kind="sensitive" />
            )
          ) : null}

          {/* General error */}
          {write.isError &&
          gateError === null &&
          !(write.error instanceof ExecutableWriteError) &&
          !(write.error instanceof SensitiveWriteError) ? (
            <div
              role="alert"
              className="rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 px-3 py-2 text-xs text-[var(--color-destructive)]"
            >
              {write.error?.message ?? "Save failed"}
            </div>
          ) : null}

          {/* Editor */}
          <div className="relative">
            <textarea
              ref={textareaRef}
              aria-label={`Edit ${fileName}`}
              value={content}
              onChange={(e) => setContent(e.target.value)}
              disabled={isBusy}
              spellCheck={false}
              autoCorrect="off"
              autoCapitalize="off"
              className={cn(
                "block h-[min(60vh,480px)] w-full resize-none rounded-md border border-[var(--color-border)]",
                "bg-[var(--color-muted)] px-3 py-2.5",
                "font-mono text-[12px] leading-relaxed text-[var(--color-foreground)]",
                "placeholder:text-[var(--color-muted-foreground)]",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
                "disabled:opacity-50",
                "scrollbar-thin",
              )}
            />
            <div
              aria-hidden="true"
              className="pointer-events-none absolute bottom-2 right-2 font-mono text-[10px] text-[var(--color-muted-foreground)]/50 tabular-nums"
            >
              {content.length.toLocaleString()} chars
            </div>
          </div>
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="ghost"
            onClick={onClose}
            disabled={isBusy}
          >
            Discard
          </Button>
          <Button
            type="button"
            variant="default"
            disabled={isBusy}
            onClick={handleSave}
          >
            <Save aria-hidden="true" className="size-4" />
            {isBusy ? "Saving..." : "Save file"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Elevated confirm gates ────────────────────────────────────────────────

function ExecutableWriteGate({
  isPending,
  onConfirm,
}: {
  isPending: boolean;
  onConfirm: () => void;
}) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border border-[var(--color-destructive)]/40 bg-[var(--color-destructive)]/10 p-4"
    >
      <div className="flex items-start gap-2">
        <ShieldAlert
          aria-hidden="true"
          className="mt-0.5 size-5 shrink-0 text-[var(--color-destructive)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-semibold text-[var(--color-foreground)]">
            Writing executable code
          </p>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            This file has an executable extension (.php, .phar, .htaccess, etc.)
            or PHP-executable content. Writing it could run arbitrary code on
            the server. This action will be recorded in the audit log. Only
            proceed if you are the site owner and trust this content.
          </p>
        </div>
      </div>
      <Button
        type="button"
        variant="destructive"
        size="sm"
        disabled={isPending}
        onClick={onConfirm}
        className="self-start"
      >
        I understand, write executable code
      </Button>
    </div>
  );
}

function SensitiveWriteGate({
  isPending,
  onConfirm,
}: {
  isPending: boolean;
  onConfirm: () => void;
}) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-4"
    >
      <div className="flex items-start gap-2">
        <ShieldAlert
          aria-hidden="true"
          className="mt-0.5 size-5 shrink-0 text-[var(--color-warning)]"
        />
        <div className="space-y-1">
          <p className="text-sm font-semibold text-[var(--color-foreground)]">
            Writing a sensitive file
          </p>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            This path is classified as sensitive (wp-config.php, .env files,
            keys, etc.). Overwriting it incorrectly can disable the site or
            expose credentials. This action will be recorded in the audit log.
          </p>
        </div>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={isPending}
        onClick={onConfirm}
        className="self-start border-[var(--color-warning)]/60 text-[var(--color-warning)] hover:bg-[var(--color-warning)]/10"
      >
        I understand, write sensitive file
      </Button>
    </div>
  );
}

function NonOwnerBlock({ kind }: { kind: "executable" | "sensitive" }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] p-4"
    >
      <Lock
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
      />
      <div className="space-y-1">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          Owner permission required
        </p>
        <p className="text-xs text-[var(--color-muted-foreground)]">
          {kind === "executable"
            ? "Writing executable code requires site owner permission. Ask the site owner to make this change."
            : "Writing sensitive files requires site owner permission. Ask the site owner to make this change."}
        </p>
      </div>
    </div>
  );
}
