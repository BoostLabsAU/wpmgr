// Portal domain hooks (m66 Phase 3 — read-only client portal).
//
// All hooks here wrap the generated portal SDK functions. Zero mutations:
// the portal is read-only by contract (role=client holds no permissions).
// Logout lives in PortalShell via the existing useLogout() from use-auth.ts.
//
// Query-key factory follows the house convention from use-backups.ts / use-clients.ts.

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import {
  getPortalOverview,
  listPortalSites,
  getPortalSiteUptime,
  listPortalSiteBackups,
  listPortalSiteUpdates,
  getPortalSiteVitals,
  listPortalReports,
  downloadPortalReport,
  type PortalOverview,
  type PortalSite,
  type PortalUptimeSummary,
  type PortalIncident,
  type PortalBackupItem,
  type PortalUpdateItem,
  type PortalVitalsSummary,
  type PortalVitalMetric,
  type PortalReportItem,
  type PortalReportDownload,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// ---------------------------------------------------------------------------
// Re-export types so portal components don't need to import from @wpmgr/api
// directly (mirrors the clients pattern).
// ---------------------------------------------------------------------------

export type {
  PortalOverview,
  PortalSite,
  PortalUptimeSummary,
  PortalIncident,
  PortalBackupItem,
  PortalUpdateItem,
  PortalVitalsSummary,
  PortalVitalMetric,
  PortalReportItem,
  PortalReportDownload,
};

// ---------------------------------------------------------------------------
// Query key factory (house convention: nested arrays).
// ---------------------------------------------------------------------------

export const portalKeys = {
  all: ["portal"] as const,
  overview: () => [...portalKeys.all, "overview"] as const,
  sites: () => [...portalKeys.all, "sites"] as const,
  site: (id: string) => [...portalKeys.all, "site", id] as const,
  uptime: (id: string, range: string) =>
    [...portalKeys.site(id), "uptime", range] as const,
  backups: (id: string) => [...portalKeys.site(id), "backups"] as const,
  updates: (id: string) => [...portalKeys.site(id), "updates"] as const,
  vitals: (id: string) => [...portalKeys.site(id), "vitals"] as const,
  reports: () => [...portalKeys.all, "reports"] as const,
  reportDownload: (id: string, format: string) =>
    [...portalKeys.all, "reports", id, "download", format] as const,
};

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

export function usePortalOverview(): UseQueryResult<PortalOverview, Error> {
  return useQuery({
    queryKey: portalKeys.overview(),
    queryFn: async () => {
      const { data, error } = await getPortalOverview();
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
  });
}

export function usePortalSites(): UseQueryResult<PortalSite[], Error> {
  return useQuery({
    queryKey: portalKeys.sites(),
    queryFn: async () => {
      const { data, error } = await listPortalSites();
      if (error) throw toError(error);
      return data?.items ?? [];
    },
  });
}

export function usePortalSiteUptime(
  siteId: string,
  range: "24h" | "7d" | "30d" | "90d" = "30d",
): UseQueryResult<PortalUptimeSummary, Error> {
  return useQuery({
    queryKey: portalKeys.uptime(siteId, range),
    queryFn: async () => {
      const { data, error } = await getPortalSiteUptime({
        path: { siteId },
        query: { range },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!siteId,
  });
}

export function usePortalSiteBackups(
  siteId: string,
): UseQueryResult<PortalBackupItem[], Error> {
  return useQuery({
    queryKey: portalKeys.backups(siteId),
    queryFn: async () => {
      const { data, error } = await listPortalSiteBackups({
        path: { siteId },
        query: { limit: 20 },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    enabled: !!siteId,
  });
}

export function usePortalSiteUpdates(
  siteId: string,
): UseQueryResult<PortalUpdateItem[], Error> {
  return useQuery({
    queryKey: portalKeys.updates(siteId),
    queryFn: async () => {
      const { data, error } = await listPortalSiteUpdates({
        path: { siteId },
        query: { limit: 50 },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    enabled: !!siteId,
  });
}

export function usePortalSiteVitals(
  siteId: string,
): UseQueryResult<PortalVitalsSummary, Error> {
  return useQuery({
    queryKey: portalKeys.vitals(siteId),
    queryFn: async () => {
      const { data, error } = await getPortalSiteVitals({
        path: { siteId },
        query: { range: "28d" },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!siteId,
  });
}

export function usePortalReports(): UseQueryResult<PortalReportItem[], Error> {
  return useQuery({
    queryKey: portalKeys.reports(),
    queryFn: async () => {
      const { data, error } = await listPortalReports();
      if (error) throw toError(error);
      return data?.items ?? [];
    },
  });
}

// Not a hook-based download — the component fetches and opens the URL imperatively.
// Exported as an async helper that callers await in a click handler.
export async function fetchPortalReportDownload(
  reportId: string,
  format: "html" | "pdf",
): Promise<PortalReportDownload> {
  const { data, error } = await downloadPortalReport({
    path: { reportId },
    query: { format },
  });
  if (error) throw toError(error);
  if (!data) throw new Error("Empty response");
  return data;
}
