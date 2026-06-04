// /performance — fleet-level performance page (Insights > Performance).
//
// P3.7 ships the "Database health across your sites" portfolio panel here.
// The Core Web Vitals / TTFB / load-trend fleet view is still planned.

import type { CSSProperties } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { Construction } from "lucide-react";

import { PageHeader } from "@/components/shared/page-header";
import { FleetDbHealthPanel } from "@/features/perf/optimize/FleetDbHealthPanel";

export const Route = createFileRoute("/_authed/performance")({
  component: PerformancePage,
});

function PerformancePage() {
  return (
    <section aria-labelledby="performance-heading" className="space-y-6">
      <PageHeader
        title="Performance"
        subline="Fleet-wide performance insights across all connected sites"
      />

      {/* P3.7 — fleet DB health rollup */}
      <FleetDbHealthPanel />

      {/* Planned: Core Web Vitals / TTFB fleet view */}
      <div className="rounded-lg border border-border p-6">
        <div className="flex items-start gap-4">
          <Construction
            aria-hidden="true"
            strokeWidth={1.5}
            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
          />
          <div className="min-w-0 space-y-3">
            <p className="text-sm text-muted-foreground" style={{ textWrap: "pretty" } satisfies CSSProperties}>
              Fleet-wide Core Web Vitals, time-to-first-byte, and load trends
              are planned and not yet available.
            </p>
            <p className="text-sm text-muted-foreground">
              <span className="font-medium text-foreground">Available today:</span>{" "}
              per-site optimization and cache settings under any{" "}
              <Link
                to="/sites"
                className="underline underline-offset-4 hover:text-foreground transition-colors"
              >
                site's Optimize tab
              </Link>
              .
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
