// TanStack Query hooks for the fleet-wide backup endpoints.
// Endpoints:
//   GET /api/v1/backups/health?sites=<csv>
//   GET /api/v1/backups/fleet?...

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

import { fleetKeys } from "./fleet-keys";
import type {
  BackupHealthResponse,
  FleetBackupsResponse,
} from "./fleet-types";

/**
 * Fetch backup health status for all sites (or a filtered CSV of site IDs).
 * Returns classified status: protected | stale | failed | unprotected | in_flight.
 */
export function useBackupHealth(
  siteIds?: string[],
): UseQueryResult<BackupHealthResponse, Error> {
  const sitesParam = siteIds?.join(",") ?? "";
  return useQuery({
    queryKey: fleetKeys.backupHealth(sitesParam || undefined),
    queryFn: async (): Promise<BackupHealthResponse> => {
      const url = new URL("/api/v1/backups/health", window.location.origin);
      if (sitesParam) url.searchParams.set("sites", sitesParam);
      const { data, error } = await client.get<BackupHealthResponse, false>({
        url: url.pathname + url.search,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from backups/health");
      return data;
    },
    staleTime: 60_000,
    refetchInterval: 2 * 60 * 1000, // 2 min — backups are long-running
  });
}

export interface FleetBackupBrowserParams {
  sites?: string;
  status?: "completed" | "failed" | "running" | "pending";
  created_after?: string;
  created_before?: string;
  sort?: "created_at" | "size" | "site";
  dir?: "asc" | "desc";
  limit?: number;
  offset?: number;
}

/**
 * Browse fleet-wide backup snapshots (paginated).
 */
export function useFleetBackups(
  params: FleetBackupBrowserParams = {},
): UseQueryResult<FleetBackupsResponse, Error> {
  // Build a stable key from the params object.
  const paramRecord: Record<string, string> = {};
  if (params.sites) paramRecord.sites = params.sites;
  if (params.status) paramRecord.status = params.status;
  if (params.created_after) paramRecord.created_after = params.created_after;
  if (params.created_before) paramRecord.created_before = params.created_before;
  if (params.sort) paramRecord.sort = params.sort;
  if (params.dir) paramRecord.dir = params.dir;
  if (params.limit !== undefined) paramRecord.limit = String(params.limit);
  if (params.offset !== undefined) paramRecord.offset = String(params.offset);

  return useQuery({
    queryKey: fleetKeys.backupBrowser(paramRecord),
    queryFn: async (): Promise<FleetBackupsResponse> => {
      const url = new URL("/api/v1/backups/fleet", window.location.origin);
      Object.entries(paramRecord).forEach(([k, v]) =>
        url.searchParams.set(k, v),
      );
      const { data, error } = await client.get<FleetBackupsResponse, false>({
        url: url.pathname + url.search,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from backups/fleet");
      return data;
    },
    staleTime: 30_000,
  });
}
