import { useState, useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  prepareSiteFileUpload,
  applySiteFileUpload,
  type PrepareUploadResult,
  type ApplyUploadResult,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

import { filesKeys } from "./use-file-manager-settings";
import {
  ExecutableWriteError,
  SensitiveWriteError,
} from "./use-file-mutations";

// ── Upload flow ───────────────────────────────────────────────────────────
//
// Browser file upload is a 3-step process:
//   1. prepareSiteFileUpload   — CP mints presigned S3 PUT URLs for each chunk.
//   2. Browser PUTs each chunk directly to S3 (never through the CP).
//   3. applySiteFileUpload     — agent fetches chunks, validates SHA-256,
//                                atomic-swaps into the target path.
//
// We compute the SHA-256 in the browser before uploading so the apply call
// can pass it for integrity validation.
//
// Chunk size: 5 MiB. Files ≤ 5 MiB = 1 chunk.
// Max upload: 160 MiB (32 × 5 MiB).

const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MiB
const MAX_UPLOAD_BYTES = 32 * CHUNK_SIZE; // 160 MiB

export type UploadStatus =
  | "idle"
  | "preparing"
  | "uploading"
  | "applying"
  | "done"
  | "error";

export interface UploadFileState {
  file: File;
  targetPath: string;
  status: UploadStatus;
  progress: number; // 0–100
  error: Error | null;
  result: ApplyUploadResult | null;
}

export interface UseFileUploadReturn {
  uploads: UploadFileState[];
  upload: (
    files: File[],
    dirPath: string,
    opts?: UploadOpts,
  ) => Promise<void>;
  clearCompleted: () => void;
  isUploading: boolean;
}

export interface UploadOpts {
  confirmExecutableWrite?: boolean;
  confirmSensitive?: boolean;
}

// ── SHA-256 helper ────────────────────────────────────────────────────────

async function sha256Hex(buffer: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", buffer);
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

// ── Chunk a file into ArrayBuffer parts ──────────────────────────────────

function chunkFile(file: File): Blob[] {
  const chunks: Blob[] = [];
  let offset = 0;
  while (offset < file.size) {
    chunks.push(file.slice(offset, offset + CHUNK_SIZE));
    offset += CHUNK_SIZE;
  }
  // Guarantee at least one chunk (empty files).
  if (chunks.length === 0) chunks.push(file.slice(0, 0));
  return chunks;
}

// ── XHR-based presigned PUT with progress ────────────────────────────────

function xhrPut(
  url: string,
  blob: Blob,
  onProgress: (pct: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", url);
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        onProgress(Math.round((e.loaded / e.total) * 100));
      }
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve();
      } else {
        reject(new Error(`S3 PUT failed: HTTP ${xhr.status}`));
      }
    };
    xhr.onerror = () => reject(new Error("Network error during upload"));
    xhr.send(blob);
  });
}

// ── Main hook ─────────────────────────────────────────────────────────────

export function useFileUpload(siteId: string): UseFileUploadReturn {
  const qc = useQueryClient();
  const [uploads, setUploads] = useState<UploadFileState[]>([]);

  const setStatus = useCallback(
    (
      file: File,
      patch: Partial<
        Pick<UploadFileState, "status" | "progress" | "error" | "result">
      >,
    ) => {
      setUploads((prev) =>
        prev.map((u) => (u.file === file ? { ...u, ...patch } : u)),
      );
    },
    [],
  );

  const upload = useCallback(
    async (
      files: File[],
      dirPath: string,
      opts: UploadOpts = {},
    ): Promise<void> => {
      // Validate sizes client-side first.
      const valid: File[] = [];
      for (const file of files) {
        if (file.size > MAX_UPLOAD_BYTES) {
          toast.error(`${file.name} is too large`, {
            description: `Maximum upload size is 160 MiB per file.`,
          });
          continue;
        }
        valid.push(file);
      }
      if (valid.length === 0) return;

      // Initialise upload state for all files.
      const initial: UploadFileState[] = valid.map((file) => ({
        file,
        targetPath: dirPath ? `${dirPath}/${file.name}` : file.name,
        status: "idle",
        progress: 0,
        error: null,
        result: null,
      }));
      setUploads((prev) => [...prev, ...initial]);

      // Process each file sequentially (avoids S3 concurrency limits).
      for (const init of initial) {
        const { file, targetPath } = init;

        try {
          // Step 0: read the whole file + compute SHA-256 in parallel.
          setStatus(file, { status: "preparing", progress: 0 });
          const buffer = await file.arrayBuffer();
          const sha256 = await sha256Hex(buffer);
          const chunks = chunkFile(file);
          const partCount = chunks.length;

          // Step 1: prepare — get presigned PUT URLs.
          const { data: prepData, error: prepError, response: prepResp } =
            await prepareSiteFileUpload({
              path: { siteId },
              body: {
                path: targetPath,
                part_count: partCount,
                ...(opts.confirmExecutableWrite
                  ? { confirm_executable_write: true }
                  : {}),
                ...(opts.confirmSensitive
                  ? { confirm_sensitive: true }
                  : {}),
              },
            });

          if (prepError) {
            // Classify exec/sensitive errors so the upload pane can surface them.
            if (prepResp?.status === 403) {
              const code =
                typeof prepError === "object" &&
                prepError !== null &&
                "code" in prepError
                  ? String((prepError as { code: string }).code)
                  : "";
              const msg =
                typeof prepError === "object" &&
                prepError !== null &&
                "message" in prepError
                  ? String((prepError as { message: string }).message)
                  : "";
              if (
                code === "executable_write_denied" ||
                /executable/i.test(msg)
              ) {
                throw new ExecutableWriteError(
                  msg || "Executable write blocked",
                );
              }
              if (code === "sensitive_denied" || /sensitive/i.test(msg)) {
                throw new SensitiveWriteError(
                  msg || "Sensitive path write blocked",
                );
              }
            }
            throw toError(prepError);
          }
          if (!prepData) throw new Error("Empty prepare response");
          const prepare: PrepareUploadResult = prepData;

          // Step 2: PUT each chunk to its presigned URL, tracking progress.
          setStatus(file, { status: "uploading", progress: 0 });
          const chunkProgresses: number[] = Array.from({ length: partCount }, () => 0);

          await Promise.all(
            chunks.map(async (chunk, i) => {
              const slot = prepare.presigned_puts[i];
              if (!slot) throw new Error(`Missing presigned PUT slot ${i}`);
              await xhrPut(slot.url, chunk, (pct) => {
                chunkProgresses[i] = pct;
                const avg = Math.round(
                  chunkProgresses.reduce((a, b) => a + b, 0) / partCount,
                );
                setStatus(file, { status: "uploading", progress: avg });
              });
            }),
          );

          // Step 3: apply — agent fetches from S3, validates, atomic-swaps.
          setStatus(file, { status: "applying", progress: 100 });
          const {
            data: applyData,
            error: applyError,
            response: applyResp,
          } = await applySiteFileUpload({
            path: { siteId },
            body: {
              path: targetPath,
              object_key: prepare.object_key,
              part_count: partCount,
              total_size: file.size,
              sha256,
              ...(opts.confirmExecutableWrite
                ? { confirm_executable_write: true }
                : {}),
              ...(opts.confirmSensitive ? { confirm_sensitive: true } : {}),
            },
          });

          if (applyError) {
            if (applyResp?.status === 403) {
              const code =
                typeof applyError === "object" &&
                applyError !== null &&
                "code" in applyError
                  ? String((applyError as { code: string }).code)
                  : "";
              const msg =
                typeof applyError === "object" &&
                applyError !== null &&
                "message" in applyError
                  ? String((applyError as { message: string }).message)
                  : "";
              if (
                code === "executable_write_denied" ||
                /executable/i.test(msg)
              ) {
                throw new ExecutableWriteError(
                  msg || "Executable write blocked",
                );
              }
              if (code === "sensitive_denied" || /sensitive/i.test(msg)) {
                throw new SensitiveWriteError(
                  msg || "Sensitive path write blocked",
                );
              }
            }
            throw toError(applyError);
          }
          if (!applyData) throw new Error("Empty apply response");

          setStatus(file, {
            status: "done",
            progress: 100,
            result: applyData,
          });
          void qc.invalidateQueries({
            queryKey: filesKeys.list(siteId, dirPath),
          });
          toast.success(`Uploaded ${file.name}`);
        } catch (err) {
          const e = err instanceof Error ? err : new Error(String(err));
          setStatus(file, { status: "error", error: e });
          if (
            !(e instanceof ExecutableWriteError) &&
            !(e instanceof SensitiveWriteError)
          ) {
            toast.error(`Upload failed: ${file.name}`, {
              description: e.message,
            });
          }
        }
      }
    },
    [siteId, qc, setStatus],
  );

  const clearCompleted = useCallback(() => {
    setUploads((prev) =>
      prev.filter((u) => u.status !== "done" && u.status !== "error"),
    );
  }, []);

  const isUploading = uploads.some(
    (u) =>
      u.status === "preparing" ||
      u.status === "uploading" ||
      u.status === "applying",
  );

  return { uploads, upload, clearCompleted, isUploading };
}
