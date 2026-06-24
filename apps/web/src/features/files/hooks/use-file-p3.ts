import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  createSiteFileArchive,
  extractSiteFileArchive,
  searchSiteFiles,
  listSiteFileVersions,
  restoreSiteFileVersion,
  type FileArchiveCreateResult,
  type FileExtractResult,
  type FileSearchResult,
  type FileVersionsResult,
  type FileVersionRestoreResult,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

import { filesKeys } from "./use-file-manager-settings";

// ── Named error classes for P3 gates ─────────────────────────────────────────

/** Any archive entry would escape the destination via path traversal. */
export class ZipSlipError extends Error {
  constructor(message = "Unsafe archive: path traversal detected") {
    super(message);
    this.name = "ZipSlipError";
  }
}

/** The archive exceeds the uncompressed-size or entry-count guard. */
export class ZipBombError extends Error {
  constructor(message = "Unsafe archive: size or entry-count limit exceeded") {
    super(message);
    this.name = "ZipBombError";
  }
}

/** The archive file is corrupted or could not be opened. */
export class BadArchiveError extends Error {
  constructor(message = "The archive is corrupted or unreadable") {
    super(message);
    this.name = "BadArchiveError";
  }
}

/** The path is not a recognised archive format. */
export class NotArchiveError extends Error {
  constructor(message = "The file is not a recognised archive") {
    super(message);
    this.name = "NotArchiveError";
  }
}

/** The archive contains executable-extension entries; owner must confirm. */
export class ArchiveExecutableError extends Error {
  constructor(message = "Archive contains executable files") {
    super(message);
    this.name = "ArchiveExecutableError";
  }
}

/** The archive or path touches the sensitive-file deny-list; owner must confirm. */
export class ArchiveSensitiveError extends Error {
  constructor(message = "Archive involves sensitive files") {
    super(message);
    this.name = "ArchiveSensitiveError";
  }
}

/** No such version ID exists for the given path. */
export class NoSuchVersionError extends Error {
  constructor(message = "This version no longer exists") {
    super(message);
    this.name = "NoSuchVersionError";
  }
}

// ── Error body classification helpers ────────────────────────────────────────

function extractCode(error: unknown): string {
  if (typeof error === "object" && error !== null && "code" in error) {
    return String((error as { code: string }).code);
  }
  return "";
}

function extractMessage(error: unknown): string {
  if (typeof error === "object" && error !== null && "message" in error) {
    return String((error as { message: string }).message);
  }
  return "";
}

function classifyExtractError(
  error: unknown,
  response: Response | undefined,
): Error {
  const code = extractCode(error);
  const msg = extractMessage(error);

  if (response?.status === 422) {
    if (code === "zip_slip" || /zip_slip/i.test(code)) {
      return new ZipSlipError(msg || undefined);
    }
    if (code === "zip_bomb" || /zip_bomb/i.test(code)) {
      return new ZipBombError(msg || undefined);
    }
    return new ZipSlipError(msg || "Archive contains unsafe entries");
  }
  if (response?.status === 400) {
    if (code === "bad_archive") return new BadArchiveError(msg || undefined);
    if (code === "not_archive") return new NotArchiveError(msg || undefined);
  }
  if (response?.status === 403) {
    if (
      code === "executable_write_denied" ||
      /executable/i.test(code)
    ) {
      return new ArchiveExecutableError(msg || undefined);
    }
    if (code === "sensitive_denied" || /sensitive/i.test(code)) {
      return new ArchiveSensitiveError(msg || undefined);
    }
  }
  return toError(error);
}

function classifyArchiveError(
  error: unknown,
  response: Response | undefined,
): Error {
  const code = extractCode(error);
  const msg = extractMessage(error);

  if (response?.status === 403) {
    if (code === "sensitive_denied" || /sensitive/i.test(code)) {
      return new ArchiveSensitiveError(msg || undefined);
    }
  }
  return toError(error);
}

function classifyRestoreError(
  error: unknown,
  response: Response | undefined,
): Error {
  const code = extractCode(error);
  const msg = extractMessage(error);

  if (response?.status === 404) {
    if (code === "no_such_version") {
      return new NoSuchVersionError(msg || undefined);
    }
  }
  if (response?.status === 403) {
    if (code === "sensitive_denied" || /sensitive/i.test(code)) {
      return new ArchiveSensitiveError(msg || undefined);
    }
  }
  return toError(error);
}

// ── useCreateFileArchive ──────────────────────────────────────────────────────
//
// Downloads one or more paths as a ZIP. The presigned URL is consumed
// immediately in onSuccess via a transient <a download> (same as useFileDownload).

export interface CreateArchiveArgs {
  paths: string[];
  confirmSensitive?: boolean;
}

export function useCreateFileArchive(
  siteId: string,
): UseMutationResult<FileArchiveCreateResult, Error, CreateArchiveArgs> {
  return useMutation({
    mutationFn: async ({ paths, confirmSensitive }) => {
      const { data, error, response } = await createSiteFileArchive({
        path: { siteId },
        body: {
          paths,
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) throw classifyArchiveError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result) => {
      const a = document.createElement("a");
      a.href = result.download_url;
      a.download = "archive.zip";
      a.style.display = "none";
      document.body.appendChild(a);
      a.click();
      window.setTimeout(() => {
        document.body.removeChild(a);
      }, 100);
      toast.success("Download started");
    },
    onError: (err) => {
      // ArchiveSensitiveError is handled by the calling dialog.
      if (!(err instanceof ArchiveSensitiveError)) {
        toast.error("Archive failed", { description: err.message });
      }
    },
  });
}

// ── useExtractFileArchive ─────────────────────────────────────────────────────

export interface ExtractArchiveArgs {
  archivePath: string;
  destPath: string;
  confirmExecutableWrite?: boolean;
  confirmSensitive?: boolean;
}

export function useExtractFileArchive(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileExtractResult, Error, ExtractArchiveArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      archivePath,
      destPath,
      confirmExecutableWrite,
      confirmSensitive,
    }) => {
      const { data, error, response } = await extractSiteFileArchive({
        path: { siteId },
        body: {
          archive_path: archivePath,
          dest_path: destPath,
          ...(confirmExecutableWrite
            ? { confirm_executable_write: true }
            : {}),
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) throw classifyExtractError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({
        queryKey: filesKeys.list(siteId, currentDirPath),
      });
      toast.success(
        result.extracted === 1
          ? "Extracted 1 file"
          : `Extracted ${result.extracted} files`,
      );
    },
    onError: (err) => {
      // Security errors are handled by the dialog (owner confirm flow).
      if (
        !(err instanceof ArchiveExecutableError) &&
        !(err instanceof ArchiveSensitiveError) &&
        !(err instanceof ZipSlipError) &&
        !(err instanceof ZipBombError) &&
        !(err instanceof BadArchiveError) &&
        !(err instanceof NotArchiveError)
      ) {
        toast.error("Extract failed", { description: err.message });
      }
    },
  });
}

// ── useSearchFiles ────────────────────────────────────────────────────────────
//
// NOTE: search results live in a plain query, not infinite scroll, so the
// consumer can swap pages by passing a cursor explicitly.

export interface SearchFilesArgs {
  siteId: string;
  path?: string;
  q: string;
  mode: "name" | "content";
  cursor?: string;
}

export function useSearchFiles(
  args: SearchFilesArgs,
): UseQueryResult<FileSearchResult, Error> {
  const { siteId, path, q, mode, cursor } = args;
  return useQuery({
    queryKey: [
      "files",
      "search",
      siteId,
      path ?? "",
      q,
      mode,
      cursor ?? "",
    ] as const,
    queryFn: async () => {
      const { data, error } = await searchSiteFiles({
        path: { siteId },
        query: {
          q,
          ...(path ? { path } : {}),
          mode,
          ...(cursor ? { cursor } : {}),
        },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: q.trim().length >= 2,
    staleTime: 10_000,
  });
}

// ── useFileVersions ───────────────────────────────────────────────────────────

export function useFileVersions(
  siteId: string,
  filePath: string | null,
  enabled = true,
): UseQueryResult<FileVersionsResult, Error> {
  return useQuery({
    queryKey: ["files", "versions", siteId, filePath ?? ""] as const,
    queryFn: async () => {
      if (!filePath) throw new Error("No file path");
      const { data, error } = await listSiteFileVersions({
        path: { siteId },
        query: { path: filePath },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: enabled && Boolean(filePath),
    staleTime: 15_000,
  });
}

// ── useRestoreFileVersion ─────────────────────────────────────────────────────

export interface RestoreVersionArgs {
  path: string;
  versionId: string;
  confirmSensitive?: boolean;
}

export function useRestoreFileVersion(
  siteId: string,
  currentDirPath: string,
): UseMutationResult<FileVersionRestoreResult, Error, RestoreVersionArgs> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ path, versionId, confirmSensitive }) => {
      const { data, error, response } = await restoreSiteFileVersion({
        path: { siteId },
        body: {
          path,
          version_id: versionId,
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) throw classifyRestoreError(error, response);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({
        queryKey: filesKeys.list(siteId, currentDirPath),
      });
      void qc.invalidateQueries({
        queryKey: filesKeys.content(siteId, currentDirPath),
      });
      toast.success("File restored to selected version");
    },
    onError: (err) => {
      // ArchiveSensitiveError and NoSuchVersionError are handled by the dialog.
      if (
        !(err instanceof ArchiveSensitiveError) &&
        !(err instanceof NoSuchVersionError)
      ) {
        toast.error("Restore failed", { description: err.message });
      }
    },
  });
}
