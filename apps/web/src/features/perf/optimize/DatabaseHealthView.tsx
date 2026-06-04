// DatabaseHealthView — Database Health tab.
//
// Surfaces:
//   (a) 90-day DB-size trend chart (reuses DbSizeChart via DBSizeTrendCard) +
//       a stat strip: total DB size, overhead, table count, orphan counts.
//   (b) OrphanReviewSection: three collapsible groups (options / cron / tables)
//       with P3.8 delete affordances for eligible items.

import { Database, HelpCircle, TrendingUp, TrendingDown, Minus } from "lucide-react";

import { PageError } from "@/components/feedback";
import { Skeleton } from "@/components/ui/skeleton";
import { DbSizeChart } from "@/components/charts/db-size-chart";

import { useDbHealth } from "../hooks/useDbHealth";
import { useDbOrphans } from "../hooks/useDbOrphans";
import { formatBytes, formatCount } from "../format";
import { OrphanReviewSection } from "./OrphanReviewSection";

export interface DatabaseHealthViewProps {
  siteId: string;
}

export function DatabaseHealthView({ siteId }: DatabaseHealthViewProps) {
  const health = useDbHealth(siteId);
  const orphans = useDbOrphans(siteId);

  // Derive stat strip values from available data.
  const latestPoint =
    health.data && health.data.points.length > 0
      ? health.data.points[health.data.points.length - 1]
      : null;

  const totalDbSize = latestPoint?.db_size_bytes;
  const tableCount = latestPoint?.table_count;

  const orphanCounts = orphans.data?.counts;

  const totalOrphans =
    orphanCounts !== undefined
      ? orphanCounts.options + orphanCounts.cron + orphanCounts.tables
      : undefined;

  return (
    <div className="space-y-4">
      {/* ── Size trend card ─────────────────────────────────────────────── */}
      <section
        aria-label="Database size trend"
        className="rounded-xl border border-border bg-card text-card-foreground shadow-sm"
      >
        {/* Header */}
        <div className="flex items-center justify-between gap-4 border-b border-border px-5 py-3">
          <div className="min-w-0">
            <h3 className="text-sm font-semibold text-foreground">
              Database size trend
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              90-day history — one point per scan
            </p>
          </div>
          {health.data && health.data.points.length >= 2 && (
            <GrowthBadge
              growthBytes={health.data.growth_bytes}
              growthPct={health.data.growth_pct}
            />
          )}
        </div>

        {/* Chart */}
        <div className="px-2 pt-3 pb-1">
          {health.isPending ? (
            <div className="flex flex-col gap-2 px-3">
              <Skeleton className="h-3 w-24 rounded" />
              <Skeleton className="h-40 w-full rounded-md" />
            </div>
          ) : health.isError ? (
            <div className="flex items-center justify-center py-10 text-xs text-muted-foreground">
              Could not load size history.
            </div>
          ) : (
            <DbSizeChart points={health.data?.points ?? []} height={160} />
          )}
        </div>

        {/* Stat strip */}
        <StatStrip
          dbSize={totalDbSize}
          tableCount={tableCount}
          totalOrphans={totalOrphans}
          orphanCounts={orphanCounts}
          isLoading={health.isPending || orphans.isPending}
        />
      </section>

      {/* ── Orphan review card ───────────────────────────────────────────── */}
      <section
        aria-label="Orphan review"
        className="rounded-xl border border-border bg-card text-card-foreground shadow-sm"
      >
        {/* Header */}
        <div className="flex items-center gap-3 border-b border-border px-5 py-3">
          <Database aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          <div className="min-w-0 flex-1">
            <h3 className="text-sm font-semibold text-foreground">
              Orphaned items
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Options, scheduled tasks, and tables attributed to uninstalled
              plugins — review only
            </p>
          </div>
          {orphans.data != null && (
            <OrphanSummaryPill counts={orphans.data.counts} />
          )}
        </div>

        {/* Body */}
        {orphans.isPending ? (
          <OrphanSkeleton />
        ) : orphans.isError ? (
          <div className="px-5 py-6">
            <PageError
              what="Could not load orphan report."
              why={orphans.error?.message ?? "Unknown error"}
              onRetry={() => void orphans.refetch()}
              retryLabel="Reload orphan report"
            />
          </div>
        ) : (
          <OrphanReviewSection siteId={siteId} report={orphans.data ?? null} />
        )}
      </section>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Stat strip
// ---------------------------------------------------------------------------

interface StatStripProps {
  dbSize: number | undefined;
  tableCount: number | undefined;
  totalOrphans: number | undefined;
  orphanCounts:
    | { options: number; cron: number; tables: number; deletable: number }
    | undefined;
  isLoading: boolean;
}

function StatStrip({
  dbSize,
  tableCount,
  totalOrphans,
  orphanCounts,
  isLoading,
}: StatStripProps) {
  return (
    <div
      aria-label="Database summary stats"
      className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-border px-5 py-3"
    >
      <StatPill
        label="DB size"
        value={dbSize !== undefined ? formatBytes(dbSize) : "–"}
        isLoading={isLoading}
      />
      <StatPill
        label="Tables"
        value={tableCount !== undefined ? formatCount(tableCount) : "–"}
        isLoading={isLoading}
      />
      <StatPill
        label="Orphaned items"
        value={totalOrphans !== undefined ? formatCount(totalOrphans) : "–"}
        isLoading={isLoading}
        hint="Total across options, scheduled tasks, and tables"
      />
      {orphanCounts !== undefined && orphanCounts.deletable > 0 && (
        <StatPill
          label="Eligible to remove"
          value={formatCount(orphanCounts.deletable)}
          isLoading={isLoading}
          highlight="amber"
        />
      )}
    </div>
  );
}

interface StatPillProps {
  label: string;
  value: string;
  isLoading: boolean;
  hint?: string;
  highlight?: "amber";
}

function StatPill({ label, value, isLoading, hint, highlight }: StatPillProps) {
  return (
    <div className="flex items-baseline gap-1.5">
      <span
        className={`tabular-nums text-sm font-semibold ${
          highlight === "amber"
            ? "text-amber-700 dark:text-amber-400"
            : "text-foreground"
        }`}
      >
        {isLoading ? (
          <Skeleton className="inline-block h-3.5 w-12 align-middle" />
        ) : (
          value
        )}
      </span>
      <span className="flex items-center gap-0.5 text-xs text-muted-foreground">
        {label}
        {hint && (
          <HelpCircle
            aria-label={hint}
            className="size-3 cursor-help text-muted-foreground/60"
          />
        )}
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Orphan summary pill (header slot)
// ---------------------------------------------------------------------------

function OrphanSummaryPill({
  counts,
}: {
  counts: { options: number; cron: number; tables: number; deletable: number };
}) {
  const total = counts.options + counts.cron + counts.tables;
  if (total === 0) return null;
  return (
    <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums text-muted-foreground">
      {total.toLocaleString()}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Orphan skeleton
// ---------------------------------------------------------------------------

function OrphanSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading orphan report"
      className="space-y-2 px-5 py-4"
    >
      <span className="sr-only">Loading orphan report</span>
      {Array.from({ length: 3 }).map((_, i) => (
        <Skeleton key={i} className="h-8 w-full rounded-md" />
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Growth badge (mirrors DBSizeTrendCard.tsx logic without importing it)
// ---------------------------------------------------------------------------

interface GrowthBadgeProps {
  growthBytes: number;
  growthPct: number;
}

function GrowthBadge({ growthBytes, growthPct }: GrowthBadgeProps) {
  const sign = growthBytes > 0 ? "+" : "";
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
