// /performance — fleet-level performance page (Insights > Performance).
//
// P3.7 ships the "Database health across your sites" portfolio panel.
// Phase 3b adds the Core Web Vitals panel powered by Real User Monitoring data.
// CWV data is per-site (no fleet aggregate endpoint exists), so the panel
// includes a site selector that reveals the CWV summary for the chosen site.

import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { listSites, type Site } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { PageHeader } from "@/components/shared/page-header";
import { FleetDbHealthPanel } from "@/features/perf/optimize/FleetDbHealthPanel";
import { FleetRumPanel } from "@/features/perf/optimize/FleetRumPanel";
import { Skeleton } from "@/components/ui/skeleton";

export const Route = createFileRoute("/_authed/performance")({
  component: PerformancePage,
});

// ---------------------------------------------------------------------------
// Site list query (for the site selector)
// ---------------------------------------------------------------------------

function useSiteList(): { sites: Site[]; isPending: boolean; isError: boolean } {
  const q = useQuery({
    queryKey: ["sites", "list-for-rum"],
    queryFn: async () => {
      const { data, error } = await listSites({ query: { limit: 500 } });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    staleTime: 60_000,
  });
  return { sites: q.data ?? [], isPending: q.isPending, isError: q.isError };
}

// ---------------------------------------------------------------------------
// Site selector (minimal native select to avoid new primitives)
// ---------------------------------------------------------------------------

interface SiteSelectorProps {
  sites: Site[];
  selectedId: string | null;
  onSelect: (id: string) => void;
}

function SiteSelector({ sites, selectedId, onSelect }: SiteSelectorProps) {
  return (
    <div className="flex items-center gap-2">
      <label
        htmlFor="cwv-site-select"
        className="text-sm font-medium text-foreground"
      >
        Site
      </label>
      <select
        id="cwv-site-select"
        value={selectedId ?? ""}
        onChange={(e) => { if (e.target.value) onSelect(e.target.value); }}
        className="h-8 rounded-md border border-input bg-background px-2 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
      >
        <option value="">Select a site...</option>
        {sites.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name || s.url}
          </option>
        ))}
      </select>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function PerformancePage() {
  const { sites, isPending: sitesPending } = useSiteList();
  const [selectedSiteId, setSelectedSiteId] = useState<string | null>(null);

  const selectedSite = sites.find((s) => s.id === selectedSiteId);

  return (
    <section aria-labelledby="performance-heading" className="space-y-6">
      <PageHeader
        title="Performance"
        subline="Fleet-wide performance insights across all connected sites"
      />

      {/* P3.7 — fleet DB health rollup */}
      <FleetDbHealthPanel />

      {/* Phase 3b — Core Web Vitals (per-site, with a site selector) */}
      <section
        aria-labelledby="cwv-section-heading"
        className="space-y-4"
      >
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2
              id="cwv-section-heading"
              className="text-sm font-semibold text-foreground"
            >
              Core Web Vitals
            </h2>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Real visitor measurements from the selected site (28-day window).
              Enable Real User Monitoring in the site's Optimize tab to populate data.
            </p>
          </div>
          {sitesPending ? (
            <Skeleton className="h-8 w-48 rounded-md" />
          ) : (
            <SiteSelector
              sites={sites}
              selectedId={selectedSiteId}
              onSelect={setSelectedSiteId}
            />
          )}
        </div>

        {selectedSiteId ? (
          <FleetRumPanel
            siteId={selectedSiteId}
            siteName={selectedSite?.name || selectedSite?.url}
          />
        ) : (
          <div className="rounded-lg border border-border px-5 py-8 text-center">
            <p className="text-sm text-muted-foreground">
              Select a site above to view its Core Web Vitals measurements.
            </p>
          </div>
        )}
      </section>
    </section>
  );
}
