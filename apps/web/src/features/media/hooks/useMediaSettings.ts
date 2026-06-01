import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import type { MediaSettings } from "../types";
import { mediaKeys } from "./useMediaAssets";

// Server-state hooks for per-site auto-optimize settings (ADR-044 §4).
//
// These routes are hand-rolled (NOT in the generated @wpmgr/api SDK):
//   GET /api/v1/sites/:siteId/media/settings → MediaSettings
//   PUT /api/v1/sites/:siteId/media/settings ← MediaSettings → 200 MediaSettings
//
// Pattern mirrors useMediaAssets.ts: same `client.get`/`client.put` style,
// same base(siteId) helper, same toError narrowing.

function base(siteId: string): string {
  return `/api/v1/sites/${encodeURIComponent(siteId)}/media`;
}

export const mediaSettingsKeys = {
  settings: (siteId: string) =>
    [...mediaKeys.all, "settings", siteId] as const,
};

/** Fetch the site's auto-optimize settings (GET /media/settings). */
export function useMediaSettings(
  siteId: string,
): UseQueryResult<MediaSettings, Error> {
  return useQuery({
    queryKey: mediaSettingsKeys.settings(siteId),
    queryFn: async () => {
      const { data, error } = await client.get<{ 200: MediaSettings }>({
        url: `${base(siteId)}/settings`,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
  });
}

/** Update the site's auto-optimize settings (PUT /media/settings). */
export function useUpdateMediaSettings(
  siteId: string,
): UseMutationResult<MediaSettings, Error, MediaSettings> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: MediaSettings) => {
      const { data, error } = await client.put<{ 200: MediaSettings }>({
        url: `${base(siteId)}/settings`,
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (updated) => {
      // Optimistically update the cache so the toggle reflects immediately.
      qc.setQueryData(mediaSettingsKeys.settings(siteId), updated);
      void qc.invalidateQueries({
        queryKey: mediaSettingsKeys.settings(siteId),
      });
    },
  });
}
