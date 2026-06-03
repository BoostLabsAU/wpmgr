// Query-key factory for the Performance Suite. Co-located + namespaced under a
// single parent so usePerfEvents can invalidate the whole tree or a single
// query, mirroring features/media/hooks/useMediaAssets.ts (mediaKeys).

export const perfKeys = {
  all: ["perf"] as const,
  config: (siteId: string) => [...perfKeys.all, "config", siteId] as const,
  stats: (siteId: string) => [...perfKeys.all, "stats", siteId] as const,
  rucss: (siteId: string) => [...perfKeys.all, "rucss", siteId] as const,
} as const;
