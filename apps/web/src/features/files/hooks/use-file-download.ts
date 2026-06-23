import { useMutation, type UseMutationResult } from "@tanstack/react-query";
import { prepareSiteFileDownload, type FileDownloadResult } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

// ── useFileDownload ────────────────────────────────────────────────────────
//
// Two-step download flow:
//  1. POST /files/download → agent uploads the file to S3 staging and returns
//     a presigned GET URL (≤ 5 min TTL).
//  2. Browser navigates to the presigned URL via a hidden <a download> to
//     trigger a native download (never through the CP).
//
// Returns a mutation; call `.mutate({ path })` from the download button.

export interface FileDownloadArgs {
  path: string;
  /** Filename hint for the browser download dialog. */
  filename?: string;
}

export function useFileDownload(
  siteId: string,
): UseMutationResult<FileDownloadResult, Error, FileDownloadArgs> {
  return useMutation({
    mutationFn: async ({ path }) => {
      const { data, error, response } = await prepareSiteFileDownload({
        path: { siteId },
        body: { path },
      });
      if (error) {
        if (response?.status === 503) {
          throw new Error(
            "Object storage is not configured on this instance. Downloads are unavailable.",
          );
        }
        if (response?.status === 403) {
          throw new Error(
            "You don't have permission to download this file, or the file manager is not enabled for this site.",
          );
        }
        throw toError(error);
      }
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result, { filename }) => {
      // Trigger the download via a transient anchor — never logs the presigned
      // URL (it goes into the anchor href only, not the console/toast).
      const a = document.createElement("a");
      a.href = result.download_url;
      if (filename) a.download = filename;
      a.style.display = "none";
      document.body.appendChild(a);
      a.click();
      // Clean up after the browser has had time to start the download.
      window.setTimeout(() => {
        document.body.removeChild(a);
      }, 100);
      toast.success("Download started");
    },
    onError: (err) => {
      toast.error("Download failed", { description: err.message });
    },
  });
}
