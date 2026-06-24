import {
  useMutation,
  useQueryClient,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  writeSiteFileContent,
  createSiteDirectory,
  renameSiteFile,
  deleteSiteFile,
  chmodSiteFile,
  type WriteFileResult,
  type FileMkdirResult,
  type FileRenameResult,
  type FileDeleteResult,
  type FileChmodResult,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

import { filesKeys } from "./use-file-manager-settings";

// ── Named error classes for P2 write gates ────────────────────────────────

/** The file path matches the executable-extension deny-list or is inside a
 *  PHP-executable web directory. Owner must confirm with
 *  `confirm_executable_write=true`. Non-owners are blocked outright. */
export class ExecutableWriteError extends Error {
  constructor(message = "Executable write blocked") {
    super(message);
    this.name = "ExecutableWriteError";
  }
}

/** The file path matches the sensitive-file deny-list (wp-config.php, .env*,
 *  *.pem, etc.). Owner must confirm with `confirm_sensitive=true`. */
export class SensitiveWriteError extends Error {
  constructor(message = "Sensitive path write blocked") {
    super(message);
    this.name = "SensitiveWriteError";
  }
}

/** The server refused to delete a protected root (wp-admin, wp-includes, etc.) */
export class ProtectedRootError extends Error {
  constructor(message = "Protected path cannot be deleted") {
    super(message);
    this.name = "ProtectedRootError";
  }
}

// ── Error body classification helper ─────────────────────────────────────

function classifyWriteError(
  error: unknown,
  response: Response | undefined,
): Error {
  if (response?.status === 403) {
    const msg =
      typeof error === "object" && error !== null && "message" in error
        ? String((error as { message: string }).message)
        : "";
    const code =
      typeof error === "object" && error !== null && "code" in error
        ? String((error as { code: string }).code)
        : "";
    if (
      code === "executable_write_denied" ||
      /executable/i.test(msg) ||
      /executable_write_denied/i.test(code)
    ) {
      return new ExecutableWriteError(msg || "Executable write blocked");
    }
    if (
      code === "sensitive_denied" ||
      /sensitive/i.test(msg) ||
      /sensitive_denied/i.test(code)
    ) {
      return new SensitiveWriteError(msg || "Sensitive path write blocked");
    }
  }
  if (response?.status === 400) {
    const code =
      typeof error === "object" && error !== null && "code" in error
        ? String((error as { code: string }).code)
        : "";
    if (code === "protected_root") {
      const msg =
        typeof error === "object" && error !== null && "message" in error
          ? String((error as { message: string }).message)
          : "Protected path cannot be deleted";
      return new ProtectedRootError(msg);
    }
  }
  return toError(error);
}

// ── useWriteFileContent ───────────────────────────────────────────────────

export interface WriteFileArgs {
  path: string;
  /** UTF-8 text to write — the hook encodes it to base64. */
  content: string;
  confirmExecutableWrite?: boolean;
  confirmSensitive?: boolean;
}

export function useWriteFileContent(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<WriteFileResult, Error, WriteFileArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      path,
      content,
      confirmExecutableWrite,
      confirmSensitive,
    }) => {
      // Encode UTF-8 text → base64.
      const contentBase64 = btoa(
        encodeURIComponent(content).replace(
          /%([0-9A-F]{2})/g,
          (_, p1: string) => String.fromCharCode(parseInt(p1, 16)),
        ),
      );
      const { data, error, response } = await writeSiteFileContent({
        path: { siteId },
        body: {
          path,
          content_base64: contentBase64,
          ...(confirmExecutableWrite
            ? { confirm_executable_write: true }
            : {}),
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) throw classifyWriteError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      // Invalidate the content cache for the dir so the modified time refreshes.
      void qc.invalidateQueries({ queryKey: filesKeys.list(siteId, currentDirPath) });
      toast.success("File saved");
    },
    onError: (err) => {
      // ExecutableWriteError / SensitiveWriteError are handled in the dialog UI.
      if (
        !(err instanceof ExecutableWriteError) &&
        !(err instanceof SensitiveWriteError)
      ) {
        toast.error("Save failed", { description: err.message });
      }
    },
  });
}

// ── useCreateDirectory ────────────────────────────────────────────────────

export interface CreateDirArgs {
  path: string;
}

export function useCreateDirectory(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileMkdirResult, Error, CreateDirArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ path }) => {
      const { data, error } = await createSiteDirectory({
        path: { siteId },
        body: { path },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: filesKeys.list(siteId, currentDirPath) });
      toast.success("Folder created");
    },
    onError: (err) => {
      toast.error("Could not create folder", { description: err.message });
    },
  });
}

// ── useRenameFile ─────────────────────────────────────────────────────────

export interface RenameFileArgs {
  src: string;
  dst: string;
  confirmExecutableWrite?: boolean;
  confirmSensitive?: boolean;
}

export function useRenameFile(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileRenameResult, Error, RenameFileArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ src, dst, confirmExecutableWrite, confirmSensitive }) => {
      const { data, error, response } = await renameSiteFile({
        path: { siteId },
        body: {
          src,
          dst,
          ...(confirmExecutableWrite
            ? { confirm_executable_write: true }
            : {}),
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) throw classifyWriteError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: filesKeys.list(siteId, currentDirPath) });
      toast.success("Renamed");
    },
    onError: (err) => {
      if (
        !(err instanceof ExecutableWriteError) &&
        !(err instanceof SensitiveWriteError)
      ) {
        toast.error("Rename failed", { description: err.message });
      }
    },
  });
}

// ── useDeleteFile ─────────────────────────────────────────────────────────

export interface DeleteFileArgs {
  path: string;
  recursive?: boolean;
}

export function useDeleteFile(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileDeleteResult, Error, DeleteFileArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ path, recursive }) => {
      const { data, error, response } = await deleteSiteFile({
        path: { siteId },
        body: {
          path,
          confirm: "DELETE",
          ...(recursive ? { recursive: true } : {}),
        },
      });
      if (error) throw classifyWriteError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({ queryKey: filesKeys.list(siteId, currentDirPath) });
      toast.success(
        result.deleted > 1
          ? `Deleted ${result.deleted} entries`
          : "Deleted",
      );
    },
    onError: (err) => {
      if (err instanceof ProtectedRootError) {
        toast.error("Cannot delete protected path", {
          description: err.message,
        });
      } else {
        toast.error("Delete failed", { description: err.message });
      }
    },
  });
}

// ── useChmodFile ──────────────────────────────────────────────────────────

export interface ChmodFileArgs {
  path: string;
  mode: string;
}

export function useChmodFile(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileChmodResult, Error, ChmodFileArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ path, mode }) => {
      const { data, error } = await chmodSiteFile({
        path: { siteId },
        body: { path, mode },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: filesKeys.list(siteId, currentDirPath) });
      toast.success("Permissions updated");
    },
    onError: (err) => {
      toast.error("chmod failed", { description: err.message });
    },
  });
}
