import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  getSiteFilesSettings,
  updateSiteFilesSettings,
  type FileManagerSettings,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

// ── Query key factory ──────────────────────────────────────────────────────

export const filesKeys = {
  all: ["files"] as const,
  settings: (siteId: string) =>
    [...filesKeys.all, "settings", siteId] as const,
  list: (siteId: string, path: string, cursor?: string) =>
    [...filesKeys.all, "list", siteId, path, cursor ?? ""] as const,
  content: (siteId: string, path: string) =>
    [...filesKeys.all, "content", siteId, path] as const,
};

// ── useFileManagerSettings ─────────────────────────────────────────────────

/** Fetch the per-site file manager enabled/disabled state. */
export function useFileManagerSettings(
  siteId: string,
): UseQueryResult<FileManagerSettings, Error> {
  return useQuery({
    queryKey: filesKeys.settings(siteId),
    queryFn: async () => {
      const { data, error } = await getSiteFilesSettings({
        path: { siteId },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
  });
}

// ── useUpdateFileManagerSettings ──────────────────────────────────────────

/** Enable or disable the file manager for a site (admin+ only). */
export function useUpdateFileManagerSettings(
  siteId: string,
): UseMutationResult<FileManagerSettings, Error, { enabled: boolean }> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ enabled }) => {
      const { data, error } = await updateSiteFilesSettings({
        path: { siteId },
        body: { enabled },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (result) => {
      qc.setQueryData(filesKeys.settings(siteId), result);
      if (result.enabled) {
        toast.success("File manager enabled", {
          description:
            "You can now browse and download files on this site. All access is audited.",
        });
      } else {
        toast.success("File manager disabled");
      }
    },
    onError: (err) => {
      toast.error("Could not update file manager settings", {
        description: err.message,
      });
    },
  });
}
