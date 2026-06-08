// CacheHitRatioTrendCard — surfaces the cache hit-ratio trend chart inside
// CacheTab. Mirrors DBSizeTrendCard conventions: standalone card, owns its own
// loading/error/empty states, calls useCacheHealth directly.
//
// Layout:
//   - Header: title + subtitle + average ratio badge + WindowToggle (7/30/90d)
//   - Body:   isFetching skeleton  |  error message  |  area chart or empty state
//
// The WindowToggle drives the days prop of useCacheHealth. isFetching (not
// isPending) drives the spinner so window switches show a refetch indicator
// while the previous window's data remains visible.
//
// The avg_ratio_pct stat is shown in the card header alongside the window
// selector. It updates as the selected window changes.
//
// Empty state is the normal first-paint: the endpoint returns no points until
// the agent begins reporting hit/miss traffic.

import { useState } from "react";
import { Loader2 } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { CacheHitRatioChart } from "@/components/charts/cache-hit-ratio-chart";
import { useCacheHealth } from "../hooks/useCacheHealth";

/** Selectable lookback window (numeric days, matches the endpoint query param). */
type CacheWindow = 7 | 30 | 90;

const CACHE_WINDOWS: ReadonlyArray<{ value: CacheWindow; label: string }> = [
  { value: 7, label: "7d" },
  { value: 30, label: "30d" },
  { value: 90, label: "90d" },
];

export interface CacheHitRatioTrendCardProps {
  siteId: string;
  /** Chart height passed to CacheHitRatioChart (default 160). */
  chartHeight?: number;
}

export function CacheHitRatioTrendCard({
  siteId,
  chartHeight = 160,
}: CacheHitRatioTrendCardProps) {
  const [days, setDays] = useState<CacheWindow>(7);
  const { data, isLoading, isError, isFetching } = useCacheHealth(siteId, days);

  const hasPoints = (data?.points.length ?? 0) >= 1;

  return (
    <section
      aria-label="Cache hit ratio"
      className="rounded-xl border border-border bg-card text-card-foreground shadow-sm"
    >
      {/* Card header */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">
            Cache hit ratio
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Hit percentage over the selected window — one point per sample
          </p>
        </div>

        <div className="flex items-center gap-3">
          {/* Average ratio badge — only when we have data */}
          {hasPoints && data ? (
            <AvgRatioBadge avgRatioPct={data.avg_ratio_pct} busy={isFetching} />
          ) : null}

          <CacheWindowToggle value={days} onChange={setDays} busy={isFetching} />
        </div>
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
            Could not load hit-ratio history.
          </div>
        ) : (
          <CacheHitRatioChart
            points={data?.points ?? []}
            height={chartHeight}
          />
        )}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Average ratio badge
// ---------------------------------------------------------------------------

interface AvgRatioBadgeProps {
  avgRatioPct: number;
  busy: boolean;
}

function AvgRatioBadge({ avgRatioPct, busy }: AvgRatioBadgeProps) {
  const display = `${avgRatioPct.toFixed(1)}% avg`;

  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium tabular-nums text-muted-foreground ring-1 ring-border">
      {busy ? (
        <Loader2 aria-hidden="true" className="size-3 animate-spin" />
      ) : null}
      {display}
      {busy ? <span className="sr-only">(refreshing)</span> : null}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Window toggle
// ---------------------------------------------------------------------------

interface CacheWindowToggleProps {
  value: CacheWindow;
  onChange: (w: CacheWindow) => void;
  busy: boolean;
}

function CacheWindowToggle({ value, onChange, busy }: CacheWindowToggleProps) {
  return (
    <div
      role="group"
      aria-label="Cache hit-ratio window"
      className="inline-flex rounded-md border border-border"
    >
      {CACHE_WINDOWS.map((w) => {
        const active = w.value === value;
        return (
          <button
            key={w.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(w.value)}
            className={
              "px-3 py-1.5 text-sm font-medium transition-colors first:rounded-l-md last:rounded-r-md focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:outline-none " +
              (active
                ? "bg-[var(--color-primary)] text-[var(--color-primary-foreground)]"
                : "hover:bg-[var(--color-accent)]")
            }
          >
            {w.label}
            {active && busy ? (
              <span className="sr-only"> (refreshing)</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}
