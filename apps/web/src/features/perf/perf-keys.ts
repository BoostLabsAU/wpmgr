// Query-key factory for the Performance Suite. Co-located + namespaced under a
// single parent so usePerfEvents can invalidate the whole tree or a single
// query, mirroring features/media/hooks/useMediaAssets.ts (mediaKeys).

export const perfKeys = {
  all: ["perf"] as const,
  config: (siteId: string) => [...perfKeys.all, "config", siteId] as const,
  stats: (siteId: string) => [...perfKeys.all, "stats", siteId] as const,
  rucss: (siteId: string) => [...perfKeys.all, "rucss", siteId] as const,
  dbHealth: (siteId: string) => [...perfKeys.all, "dbHealth", siteId] as const,
  cacheHealth: (siteId: string) => [...perfKeys.all, "cacheHealth", siteId] as const,
  dbOrphans: (siteId: string) => [...perfKeys.all, "dbOrphans", siteId] as const,
  // Tenant-level fleet aggregate — no siteId (cross-site).
  fleetDbHealth: () => [...perfKeys.all, "fleetDbHealth"] as const,
  // Font results catalog (ADR-052 Phase 2 / m55).
  fonts: (siteId: string) => [...perfKeys.all, "fonts", siteId] as const,
  // RUM / Core Web Vitals (Phase 3b).
  rum: (siteId: string) => [...perfKeys.all, "rum", siteId] as const,
  rumSummary: (siteId: string) => [...perfKeys.all, "rumSummary", siteId] as const,
} as const;
