import { useCallback, useRef, useState } from "react";
import { useDropzone } from "react-dropzone";
import {
  CheckCircle2,
  Lock,
  ShieldAlert,
  Upload,
  XCircle,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

import {
  ExecutableWriteError,
  SensitiveWriteError,
} from "./hooks/use-file-mutations";
import {
  useFileUpload,
  type UploadFileState,
  type UploadOpts,
} from "./hooks/use-file-upload";

// FileUploadPane — drag-and-drop upload for admin+ users with write_enabled.
//
// Flow (prepare→PUT→apply — NOT a standard S3 multipart protocol):
//   1. prepareSiteFileUpload  — CP mints presigned S3 PUT URLs per chunk.
//   2. Browser XHR PUTs each chunk to S3 (never through CP), with progress.
//   3. applySiteFileUpload    — agent fetches from S3, validates SHA-256,
//                               atomic-swaps into the jail.
//
// Client-side UX guards (server is the authoritative gate):
//   - Files with executable extensions (.php, .phar, .htaccess, etc.) get a
//     friendly warning before upload, but are NOT rejected client-side (the
//     server decides). If the server returns ExecutableWriteError, the owner
//     can confirm; non-owners see a block.
//   - Files over 160 MiB are rejected client-side.
//
// Storage-unavailable (503 from prepare): show a clear explanation that
// object storage is not configured on this instance.

// Extensions that are likely executable — used for UX hinting only.
const LIKELY_EXEC_EXTENSIONS = new Set([
  ".php", ".phtml", ".phar", ".php3", ".php4", ".php5", ".php7", ".phps",
  ".pht", ".cgi", ".pl", ".py", ".sh", ".asp", ".aspx", ".jsp",
  ".htaccess", ".htpasswd",
]);

function isLikelyExecutable(name: string): boolean {
  const lower = name.toLowerCase();
  return LIKELY_EXEC_EXTENSIONS.has(`.${lower.split(".").pop() ?? ""}`);
}

export interface FileUploadPaneProps {
  siteId: string;
  currentDirPath: string;
  /** Whether the current user is a site owner. */
  isOwner: boolean;
}

export function FileUploadPane({
  siteId,
  currentDirPath,
  isOwner,
}: FileUploadPaneProps) {
  const { uploads, upload, clearCompleted, isUploading } =
    useFileUpload(siteId);

  // Per-file exec/sensitive confirm states (keyed by file name for UX).
  const [pendingExecFiles, setPendingExecFiles] = useState<File[]>([]);
  const [execConfirmed, setExecConfirmed] = useState(false);
  const [storageError, setStorageError] = useState<string | null>(null);

  const pendingFilesRef = useRef<File[]>([]);

  const doUpload = useCallback(
    (files: File[], opts: UploadOpts = {}) => {
      setStorageError(null);
      void upload(files, currentDirPath, opts).catch((err: Error) => {
        if (/storage_not_configured|503/i.test(err.message)) {
          setStorageError(
            "Object storage is not configured on this instance. File uploads require S3-compatible storage.",
          );
        }
      });
    },
    [upload, currentDirPath],
  );

  const onDrop = useCallback(
    (acceptedFiles: File[]) => {
      if (acceptedFiles.length === 0) return;
      // Check for likely-executable files — show a warning for UX but let
      // the server be authoritative (the confirm gate fires on 403 error).
      const execFiles = acceptedFiles.filter((f) => isLikelyExecutable(f.name));
      if (execFiles.length > 0 && !execConfirmed) {
        setPendingExecFiles(execFiles);
        pendingFilesRef.current = acceptedFiles;
        return;
      }
      doUpload(acceptedFiles, {
        confirmExecutableWrite: execConfirmed || execFiles.length > 0,
      });
      setPendingExecFiles([]);
    },
    [doUpload, execConfirmed],
  );

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    noClick: false,
    multiple: true,
  });

  const hasUploads = uploads.length > 0;
  const hasCompleted = uploads.some(
    (u) => u.status === "done" || u.status === "error",
  );

  return (
    <div className="space-y-3">
      {/* Storage error */}
      {storageError ? (
        <div
          role="alert"
          className="flex items-start gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-2.5"
        >
          <XCircle
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
          <p className="text-xs text-[var(--color-foreground)]">{storageError}</p>
        </div>
      ) : null}

      {/* Executable-file warning (UX only; server is authoritative) */}
      {pendingExecFiles.length > 0 ? (
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
                Executable file detected
              </p>
              <p className="text-xs text-[var(--color-muted-foreground)]">
                The following file(s) have executable extensions. Uploading
                executable code can allow arbitrary code execution on the server.
                Only proceed if you trust this content and are the site owner.
              </p>
              <ul className="mt-1 space-y-0.5">
                {pendingExecFiles.map((f) => (
                  <li
                    key={f.name}
                    className="font-mono text-[11px] text-[var(--color-foreground)]"
                  >
                    {f.name}
                  </li>
                ))}
              </ul>
            </div>
          </div>
          {isOwner ? (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={() => {
                  setExecConfirmed(true);
                  doUpload(pendingFilesRef.current, {
                    confirmExecutableWrite: true,
                  });
                  setPendingExecFiles([]);
                }}
              >
                I understand, upload executable file
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setPendingExecFiles([])}
              >
                Cancel
              </Button>
            </div>
          ) : (
            <div className="flex items-start gap-2">
              <Lock
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
              />
              <p className="text-xs text-[var(--color-muted-foreground)]">
                Uploading executable files requires site owner permission.
              </p>
            </div>
          )}
        </div>
      ) : null}

      {/* Drop zone */}
      <div
        {...getRootProps()}
        className={cn(
          "relative flex cursor-pointer flex-col items-center gap-3 rounded-lg border-2 border-dashed px-6 py-8 transition-colors",
          isDragActive
            ? "border-[var(--color-primary)] bg-[var(--color-primary)]/5"
            : "border-[var(--color-border)] hover:border-[var(--color-muted-foreground)]/40 hover:bg-[var(--color-muted)]",
          isUploading && "pointer-events-none opacity-60",
        )}
        aria-label={
          isDragActive ? "Drop files here to upload" : "Drag and drop files here, or click to browse"
        }
      >
        <input {...getInputProps()} aria-hidden="true" />
        <Upload
          aria-hidden="true"
          strokeWidth={1.5}
          className={cn(
            "size-8 transition-colors",
            isDragActive
              ? "text-[var(--color-primary)]"
              : "text-[var(--color-muted-foreground)]/50",
          )}
        />
        <div className="space-y-1 text-center">
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            {isDragActive ? "Drop files here" : "Drag files here or click to browse"}
          </p>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Maximum 160 MiB per file. Uploads to:{" "}
            <span className="font-mono">{currentDirPath || "root"}</span>
          </p>
        </div>
      </div>

      {/* Upload progress list */}
      {hasUploads ? (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <p className="text-xs font-medium text-[var(--color-muted-foreground)]">
              Uploads
            </p>
            {hasCompleted && !isUploading ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={clearCompleted}
                className="h-6 px-2 text-xs text-[var(--color-muted-foreground)]"
              >
                Clear completed
              </Button>
            ) : null}
          </div>
          <div className="space-y-1.5">
            {uploads.map((up) => (
              <UploadRow key={up.file.name + up.targetPath} upload={up} isOwner={isOwner} />
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

// ── Upload row ──────────────────────────────────────────────────────────

function UploadRow({
  upload,
  isOwner,
}: {
  upload: UploadFileState;
  isOwner: boolean;
}) {
  const isExecError = upload.error instanceof ExecutableWriteError;
  const isSensitiveError = upload.error instanceof SensitiveWriteError;

  return (
    <div className="rounded-md border border-[var(--color-border)] bg-[var(--color-card)] px-3 py-2.5">
      <div className="flex items-center gap-2">
        {/* Status icon */}
        <div className="shrink-0">
          {upload.status === "done" ? (
            <CheckCircle2
              aria-hidden="true"
              className="size-4 text-[var(--color-success,green)]"
            />
          ) : upload.status === "error" ? (
            <XCircle
              aria-hidden="true"
              className="size-4 text-[var(--color-destructive)]"
            />
          ) : (
            <div
              aria-hidden="true"
              className="size-4 animate-pulse rounded-full bg-[var(--color-muted-foreground)]/30"
            />
          )}
        </div>

        {/* File name + path */}
        <div className="min-w-0 flex-1">
          <p className="truncate font-mono text-xs font-medium text-[var(--color-foreground)]">
            {upload.file.name}
          </p>
          {upload.status === "uploading" || upload.status === "applying" ? (
            <StatusLabel status={upload.status} />
          ) : null}
        </div>

        {/* Progress % */}
        {(upload.status === "uploading" || upload.status === "applying") ? (
          <span className="shrink-0 font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
            {upload.progress}%
          </span>
        ) : null}
      </div>

      {/* Progress bar */}
      {(upload.status === "uploading" || upload.status === "applying") ? (
        <div
          role="progressbar"
          aria-valuenow={upload.progress}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={`Uploading ${upload.file.name}`}
          className="mt-2 h-1 w-full overflow-hidden rounded-full bg-[var(--color-muted)]"
        >
          <div
            className="h-full rounded-full bg-[var(--color-primary)] transition-all duration-200"
            style={{ width: `${upload.progress}%` }}
          />
        </div>
      ) : null}

      {/* Exec/sensitive error with confirm */}
      {upload.status === "error" && isExecError ? (
        isOwner ? (
          <div className="mt-2 flex items-center gap-2">
            <p className="text-xs text-[var(--color-destructive)]">
              Executable file blocked.
            </p>
            <span className="text-xs text-[var(--color-muted-foreground)]">
              (Use the "I understand, upload executable file" flow above)
            </span>
          </div>
        ) : (
          <div className="mt-2 flex items-center gap-1.5">
            <Lock
              aria-hidden="true"
              className="size-3 text-[var(--color-muted-foreground)]"
            />
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Executable upload requires owner permission.
            </p>
          </div>
        )
      ) : null}

      {upload.status === "error" && isSensitiveError ? (
        isOwner ? (
          <p className="mt-2 text-xs text-[var(--color-warning)]">
            Sensitive path blocked. Ask the site owner to re-upload with explicit
            confirmation.
          </p>
        ) : (
          <div className="mt-2 flex items-center gap-1.5">
            <Lock
              aria-hidden="true"
              className="size-3 text-[var(--color-muted-foreground)]"
            />
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Sensitive path upload requires owner permission.
            </p>
          </div>
        )
      ) : null}

      {upload.status === "error" &&
      !isExecError &&
      !isSensitiveError &&
      upload.error ? (
        <p className="mt-1.5 text-xs text-[var(--color-destructive)]">
          {upload.error.message}
        </p>
      ) : null}
    </div>
  );
}

function StatusLabel({ status }: { status: UploadFileState["status"] }) {
  if (status === "preparing") return <p className="text-[10px] text-[var(--color-muted-foreground)]">Preparing...</p>;
  if (status === "uploading") return <p className="text-[10px] text-[var(--color-muted-foreground)]">Uploading to staging...</p>;
  if (status === "applying") return <p className="text-[10px] text-[var(--color-muted-foreground)]">Applying to site...</p>;
  return null;
}
