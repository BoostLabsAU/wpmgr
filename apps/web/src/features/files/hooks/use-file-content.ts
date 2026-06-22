import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { readSiteFileContent, type FileReadResult } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { filesKeys } from "./use-file-manager-settings";

// ── Named error classes ────────────────────────────────────────────────────

/** The file is a sensitive path (wp-config.php, .env*, etc.) and
 *  `confirm_sensitive=true` was not passed, OR the caller doesn't have
 *  owner-level permission. */
export class SensitiveFileError extends Error {
  constructor(message = "Sensitive file blocked") {
    super(message);
    this.name = "SensitiveFileError";
  }
}

/** File is too large for inline read (> 256 KiB). Use download instead. */
export class FileTooLargeError extends Error {
  constructor(message = "File too large for inline preview") {
    super(message);
    this.name = "FileTooLargeError";
  }
}

// ── useFileContent ─────────────────────────────────────────────────────────
//
// On-demand file read. The query is DISABLED until `path` is non-null (i.e.
// nothing is selected). Sensitivity gate:
//
//  - First attempt: confirmSensitive = false (the default). If the server
//    returns 403 with a body hinting "sensitive", we surface SensitiveFileError
//    so the drawer can show the confirm gate.
//  - After owner confirms: the caller re-calls with confirmSensitive = true;
//    the query key changes so React Query fires a new fetch.

export interface UseFileContentOptions {
  siteId: string;
  /** Null to skip — hook is disabled when null. */
  path: string | null;
  /** Set to true after the owner confirms they want to read a sensitive file. */
  confirmSensitive?: boolean;
}

export function useFileContent({
  siteId,
  path,
  confirmSensitive = false,
}: UseFileContentOptions): UseQueryResult<FileReadResult, Error> {
  return useQuery({
    // Include confirmSensitive in the key so flipping it triggers a fresh fetch.
    queryKey: [
      ...filesKeys.content(siteId, path ?? ""),
      { confirmSensitive },
    ],
    enabled: path !== null,
    // Don't cache sensitive reads longer than the session needs them.
    staleTime: confirmSensitive ? 0 : 30_000,
    queryFn: async () => {
      if (!path) throw new Error("No path");
      const { data, error, response } = await readSiteFileContent({
        path: { siteId },
        query: {
          path,
          ...(confirmSensitive ? { confirm_sensitive: true } : {}),
        },
      });
      if (error) {
        // 413 → file too large for inline read
        if (response?.status === 413) {
          throw new FileTooLargeError(
            typeof error === "object" && error !== null && "message" in error
              ? String((error as { message: string }).message)
              : "File too large for inline preview",
          );
        }
        // 403 → could be sensitive-path or permission denied
        if (response?.status === 403) {
          throw new SensitiveFileError(
            typeof error === "object" && error !== null && "message" in error
              ? String((error as { message: string }).message)
              : "Sensitive file blocked",
          );
        }
        throw toError(error);
      }
      if (!data) throw new Error("Empty response");
      return data;
    },
  });
}
