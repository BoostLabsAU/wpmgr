// Compact "Database size trend" card — surfaces the 90-day history chart
// inside DatabaseSection so operators can see growth at a glance while the
// scan/clean flow sits below it.
//
// The card is intentionally standalone so P3.6 (Health tab) can reuse it
// without pulling in the full DatabaseSection context. It calls useDbHealth
// directly and owns its own loading/error/empty states.
//
// Growth delta badges:
//   - Green / muted when DB shrank or held steady (growth_pct <= 0).
//   - Amber when growth_pct > 0 to nudge the operator to consider cleaning.
// The badge only renders when at least 2 points exist (growth_pct is 0 by
// contract when fewer than 2 points are present).

import { TrendingUp, TrendingDown, Minus } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { DbSizeChart } from "@/components/charts/db-size-chart";
import { useDbHealth } from "../hooks/useDbHealth";
import { formatBytes } from "../format";

export interface DBSizeTrendCardProps {
  siteId: string;
  /** Chart height passed to DbSizeChart (default 160). */
  chartHeight?: number;
}

export function DBSizeTrendCard({
  siteId,
  chartHeight = 160,
}: DBSizeTrendCardProps) {
  const { data, isLoading, isError } = useDbHealth(siteId);

  const hasGrowthData = (data?.points.length ?? 0) >= 2;

  return (
    <section
      aria-label="Database size trend"
      className="rounded-xl border border-border bg-card text-card-foreground shadow-sm"
    >
      {/* Card header */}
      <div className="flex items-center justify-between gap-4 border-b border-border px-5 py-3">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">
            Database size trend
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            90-day history — one point per scan
          </p>
        </div>

        {/* Growth badge — only when we have at least 2 points */}
        {hasGrowthData && data ? (
          <GrowthBadge
            growthBytes={data.growth_bytes}
            growthPct={data.growth_pct}
          />
        ) : null}
      </div>

      {/* Chart body */}
      <div className="px-2 py-3">
        {isLoading ? (
          <div className="flex flex-col gap-2 px-3">
            <Skeleton className="h-3 w-24 rounded" />
            <Skeleton style={{ height: chartHeight }} className="w-full rounded-md" />
          </div>
        ) : isError ? (
          <div className="flex items-center justify-center py-10 text-xs text-muted-foreground">
            Could not load size history.
          </div>
        ) : (
          <DbSizeChart
            points={data?.points ?? []}
            height={chartHeight}
          />
        )}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Growth badge
// ---------------------------------------------------------------------------

interface GrowthBadgeProps {
  growthBytes: number;
  growthPct: number;
}

function GrowthBadge({ growthBytes, growthPct }: GrowthBadgeProps) {
  const sign = growthBytes > 0 ? "+" : growthBytes < 0 ? "" : "";
  const label = `${sign}${formatBytes(Math.abs(growthBytes))} (${sign}${growthPct.toFixed(1)}%)`;

  if (growthBytes > 0) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:ring-amber-800">
        <TrendingUp aria-hidden="true" className="size-3" />
        {label}
      </span>
    );
  }

  if (growthBytes < 0) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-green-200 dark:bg-green-950/30 dark:text-green-400 dark:ring-green-800">
        <TrendingDown aria-hidden="true" className="size-3" />
        {label}
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground ring-1 ring-border">
      <Minus aria-hidden="true" className="size-3" />
      No change
    </span>
  );
}
