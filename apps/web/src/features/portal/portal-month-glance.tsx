// PortalMonthGlance — fleet uptime trend + vitals distribution side-by-side.
//
// Left card: UptimeChart (shared component) fed from summary.uptime_daily.
// Right card: 3 distribution rows (Loading speed / Responsiveness / Visual
//   stability) using PortalDistributionRow (site-count-based version of the
//   RumDistributionBar token — NOT the pct-based RUM one; the portal has
//   site counts per rating, not page-view percentages).
//
// When all vitals data is absent, the right card shows ChartEmpty.
// Desktop: md:grid-cols-2. Mobile: stacks.

import { Skeleton } from "@/components/ui/skeleton";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { TrendingUp, Zap } from "lucide-react";
import { UptimeChart, type UptimePoint } from "@/components/charts/uptime-chart";
import { ChartEmpty } from "@/components/charts/chart-empty";
import { cn } from "@/lib/utils";
import type { PortalUptimeDay, PortalVitalsDistribution, PortalVitalsRatingCount } from "./use-portal";

// ---------------------------------------------------------------------------
// Uptime data adapter: PortalUptimeDay[] -> UptimePoint[]
// ---------------------------------------------------------------------------

function toUptimePoints(days: PortalUptimeDay[]): UptimePoint[] {
  return days.map((d) => ({ date: d.day, uptime: d.uptime_pct }));
}

// ---------------------------------------------------------------------------
// PortalDistributionRow — counts-based distribution bar for the portal.
// Uses site COUNTS (not percentages) — converts to widths for display.
// Three segments: good (success) / needs-improvement (warning) / poor (destructive)
// ---------------------------------------------------------------------------

interface PortalDistributionRowProps {
  label: string;
  counts: PortalVitalsRatingCount;
  totalSites: number;
}

function PortalDistributionRow({
  label,
  counts,
  totalSites,
}: PortalDistributionRowProps) {
  const total = counts.good + counts.needs_improvement + counts.poor;
  if (total === 0) return null;

  // Sites with no data are not included in the distribution total
  const legend = `${String(total)} of ${String(totalSites)} site${totalSites === 1 ? "" : "s"}`;

  const goodPct = total > 0 ? (counts.good / total) * 100 : 0;
  const niPct = total > 0 ? (counts.needs_improvement / total) * 100 : 0;
  const poorPct = total > 0 ? (counts.poor / total) * 100 : 0;

  const ariaLabel = [
    `${label} distribution:`,
    `${String(counts.good)} good,`,
    `${String(counts.needs_improvement)} needs improvement,`,
    `${String(counts.poor)} poor.`,
  ].join(" ");

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-[var(--color-foreground)]">
          {label}
        </span>
        <span className="text-xs text-[var(--color-muted-foreground)]">
          {legend}
        </span>
      </div>
      <div
        role="img"
        aria-label={ariaLabel}
        title={ariaLabel}
        className="flex h-3 w-full overflow-hidden rounded-full"
      >
        {goodPct > 0 ? (
          <div
            className="bg-[var(--color-success)]"
            style={{ width: `${String(goodPct)}%`, minWidth: goodPct > 0 ? "4px" : undefined }}
          />
        ) : null}
        {niPct > 0 ? (
          <div
            className="bg-[var(--color-warning)]"
            style={{ width: `${String(niPct)}%`, minWidth: niPct > 0 ? "4px" : undefined }}
          />
        ) : null}
        {poorPct > 0 ? (
          <div
            className="bg-[var(--color-destructive)]"
            style={{ width: `${String(poorPct)}%`, minWidth: poorPct > 0 ? "4px" : undefined }}
          />
        ) : null}
      </div>
      <div
        className="flex items-center gap-3 text-[10px] text-[var(--color-muted-foreground)]"
        aria-hidden="true"
      >
        <span className="inline-flex items-center gap-1">
          <span className="inline-block size-2 rounded-sm bg-[var(--color-success)]" />
          Good: {String(counts.good)}
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="inline-block size-2 rounded-sm bg-[var(--color-warning)]" />
          Fair: {String(counts.needs_improvement)}
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="inline-block size-2 rounded-sm bg-[var(--color-destructive)]" />
          Poor: {String(counts.poor)}
        </span>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalMonthGlanceSkeleton() {
  return (
    <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
      <Skeleton className="h-[240px] w-full rounded-lg" />
      <Skeleton className="h-[240px] w-full rounded-lg" />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalMonthGlanceProps {
  uptimeDaily: PortalUptimeDay[];
  vitalsDistribution: PortalVitalsDistribution | undefined;
  totalSites: number;
  className?: string;
}

export function PortalMonthGlance({
  uptimeDaily,
  vitalsDistribution,
  totalSites,
  className,
}: PortalMonthGlanceProps) {
  const uptimePoints = toUptimePoints(uptimeDaily);

  const hasVitals =
    vitalsDistribution != null &&
    (vitalsDistribution.lcp.good +
      vitalsDistribution.lcp.needs_improvement +
      vitalsDistribution.lcp.poor +
      vitalsDistribution.inp.good +
      vitalsDistribution.inp.needs_improvement +
      vitalsDistribution.inp.poor +
      vitalsDistribution.cls.good +
      vitalsDistribution.cls.needs_improvement +
      vitalsDistribution.cls.poor) > 0;

  return (
    <div className={cn("mb-6 grid grid-cols-1 gap-4 md:grid-cols-2", className)}>
      {/* Uptime trend card */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <TrendingUp
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
            Fleet uptime trend
          </CardTitle>
        </CardHeader>
        <CardContent>
          <UptimeChart data={uptimePoints} height={160} />
        </CardContent>
      </Card>

      {/* Vitals distribution band card */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <Zap
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
            Site speed ratings
          </CardTitle>
        </CardHeader>
        <CardContent>
          {hasVitals && vitalsDistribution ? (
            <div className="space-y-4">
              <PortalDistributionRow
                label="Loading speed"
                counts={vitalsDistribution.lcp}
                totalSites={totalSites}
              />
              <PortalDistributionRow
                label="Responsiveness"
                counts={vitalsDistribution.inp}
                totalSites={totalSites}
              />
              <PortalDistributionRow
                label="Visual stability"
                counts={vitalsDistribution.cls}
                totalSites={totalSites}
              />
            </div>
          ) : (
            <ChartEmpty message="Speed data appears once visitors browse your sites." />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
