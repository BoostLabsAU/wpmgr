import { useState } from "react";
import { Activity, AlertCircle, CheckCircle2, XCircle } from "lucide-react";
import { Link } from "@tanstack/react-router";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";

import { useRumSummary } from "../hooks/useRumSummary";
import { useRumTrend } from "../hooks/useRumTrend";
import type { RumSummary, RumResult, RumDistribution } from "../types";
import { RumDistributionBar } from "./RumDistributionBar";
import { RumTrendChart } from "./RumTrendChart";
import type { MetricName as TrendMetricName } from "./RumTrendChart";

// FleetRumPanel — Core Web Vitals dashboard for the /performance page.
//
// Shows a site-scoped CWV panel: three big p75 readouts for the three core
// metrics (LCP, INP, CLS) with good/needs-improvement/poor coloring, a
// pass/fail badge (passes only when all three core p75 values are "good"), and
// the percentage of pageviews in each rating band.
//
// Standard CWV thresholds (official web-vitals constants):
//   LCP:  good <= 2500 ms  /  needs-improvement <= 4000 ms  /  poor > 4000 ms
//   INP:  good <= 200 ms   /  needs-improvement <= 500 ms   /  poor > 500 ms
//   CLS:  good <= 100 milli-units / NI <= 250 milli-units   /  poor > 250 milli-units
//   FCP:  good <= 1800 ms  /  needs-improvement <= 3000 ms  /  poor > 3000 ms
//   TTFB: good <= 800 ms   /  needs-improvement <= 1800 ms  /  poor > 1800 ms
//
// Data honesty rule: suppressed rows MUST NOT show a p75 value. The API sets
// suppressed=true when sample_count < min_sample_count (the CrUX-style floor).
// Render "Insufficient samples" for those rows rather than any number.
//
// This panel is READ-ONLY and siteId-scoped. The fleet page passes a siteId
// from context (or a site-picker). When used without RUM data it shows a clear
// empty state with a pointer to the per-site Optimize tab.

export interface FleetRumPanelProps {
  siteId: string;
  siteName?: string;
}

// ---------------------------------------------------------------------------
// Rating helpers
// ---------------------------------------------------------------------------

type Rating = NonNullable<RumResult["rating"]>;

const RATING_COLOR_CLASS: Record<Rating, string> = {
  good: "text-green-700 dark:text-green-400",
  needs_improvement: "text-amber-700 dark:text-amber-400",
  poor: "text-red-700 dark:text-red-400",
};

const RATING_BG_CLASS: Record<Rating, string> = {
  good: "bg-green-50 border-green-200 dark:bg-green-950/40 dark:border-green-800",
  needs_improvement: "bg-amber-50 border-amber-200 dark:bg-amber-950/40 dark:border-amber-800",
  poor: "bg-red-50 border-red-200 dark:bg-red-950/40 dark:border-red-800",
};

const RATING_LABELS: Record<Rating, string> = {
  good: "Good",
  needs_improvement: "Needs work",
  poor: "Poor",
};

function formatP75(metric: NonNullable<RumResult["metric"]>, p75_ms: number): string {
  if (metric === "cls") {
    return (p75_ms / 1000).toFixed(3);
  }
  if (p75_ms >= 1000) {
    return `${(p75_ms / 1000).toFixed(2)} s`;
  }
  return `${Math.round(p75_ms)} ms`;
}

// ---------------------------------------------------------------------------
// Core metric card
// ---------------------------------------------------------------------------

type MetricName = "lcp" | "inp" | "cls" | "fcp" | "ttfb";

const METRIC_LABELS: Record<MetricName, string> = {
  lcp: "LCP",
  inp: "INP",
  cls: "CLS",
  fcp: "FCP",
  ttfb: "TTFB",
};

const METRIC_DESCRIPTIONS: Record<MetricName, string> = {
  lcp: "Largest Contentful Paint",
  inp: "Interaction to Next Paint",
  cls: "Cumulative Layout Shift",
  fcp: "First Contentful Paint",
  ttfb: "Time to First Byte",
};

interface MetricSlice {
  p75_ms: number;
  sample_count: number;
  rating: Rating | undefined;
  suppressed: boolean;
  min_sample_count: number;
  /** Distribution across the three rating bands. Absent when suppressed. */
  distribution?: RumDistribution;
}

interface CoreMetricCardProps {
  metric: MetricName;
  slice: MetricSlice | undefined;
}

function CoreMetricCard({ metric, slice }: CoreMetricCardProps) {
  const label = METRIC_LABELS[metric];
  const desc = METRIC_DESCRIPTIONS[metric];

  if (!slice) {
    return (
      <div className="flex flex-col gap-1 rounded-lg border border-border bg-background px-4 py-3">
        <span className="text-xs font-semibold uppercase tracking-[0.04em] text-muted-foreground">
          {label}
        </span>
        <span className="text-sm text-muted-foreground">{desc}</span>
        <span className="mt-1 text-xs text-muted-foreground">No data</span>
      </div>
    );
  }

  if (slice.suppressed) {
    return (
      <div className="flex flex-col gap-1 rounded-lg border border-border bg-background px-4 py-3">
        <span className="text-xs font-semibold uppercase tracking-[0.04em] text-muted-foreground">
          {label}
        </span>
        <span className="text-sm text-muted-foreground">{desc}</span>
        <span
          className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground"
          title={`Insufficient samples: ${String(slice.sample_count)} of ${String(slice.min_sample_count)} needed`}
        >
          <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
          Insufficient samples ({slice.sample_count} of {slice.min_sample_count})
        </span>
      </div>
    );
  }

  const rating = slice.rating;
  const colorClass = rating ? RATING_COLOR_CLASS[rating] : "text-foreground";
  const bgClass = rating ? RATING_BG_CLASS[rating] : "border-border";

  return (
    <div
      className={`flex flex-col gap-1 rounded-lg border px-4 py-3 ${bgClass}`}
    >
      <span
        className={`text-xs font-semibold uppercase tracking-[0.04em] ${colorClass}`}
      >
        {label}
      </span>
      <span className={`text-2xl font-bold tabular-nums leading-none ${colorClass}`}>
        {formatP75(metric, slice.p75_ms)}
      </span>
      <span className="mt-0.5 text-xs text-muted-foreground">{desc}</span>
      {rating ? (
        <span
          className={`mt-1 text-xs font-medium ${colorClass}`}
        >
          {RATING_LABELS[rating]}
        </span>
      ) : null}
      <span className="text-xs text-muted-foreground tabular-nums">
        {slice.sample_count.toLocaleString()} samples
      </span>
      <RumDistributionBar
        metricLabel={label}
        distribution={slice.distribution}
        suppressed={false}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Pass/fail overall badge
// ---------------------------------------------------------------------------

interface OverallBadgeProps {
  allGood: boolean | null; // null = insufficient data
}

function OverallBadge({ allGood }: OverallBadgeProps) {
  if (allGood === null) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded border border-border bg-background px-2 py-1 text-xs text-muted-foreground">
        <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
        Insufficient data
      </span>
    );
  }
  if (allGood) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded border border-green-200 bg-green-50 px-2 py-1 text-xs font-medium text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-400">
        <CheckCircle2 aria-hidden="true" className="size-3.5 shrink-0" />
        Passes Core Web Vitals
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1.5 rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-400">
      <XCircle aria-hidden="true" className="size-3.5 shrink-0" />
      Does not pass
    </span>
  );
}

// ---------------------------------------------------------------------------
// Device segment selector
// ---------------------------------------------------------------------------

type DeviceFilter = "all" | "desktop" | "mobile" | "tablet";

interface DeviceTabsProps {
  value: DeviceFilter;
  onChange: (v: DeviceFilter) => void;
}

function DeviceTabs({ value, onChange }: DeviceTabsProps) {
  const options: Array<{ value: DeviceFilter; label: string }> = [
    { value: "all", label: "All" },
    { value: "desktop", label: "Desktop" },
    { value: "mobile", label: "Mobile" },
    { value: "tablet", label: "Tablet" },
  ];
  return (
    <div
      role="tablist"
      aria-label="Filter by device"
      className="inline-flex items-center rounded-md border border-border text-xs"
    >
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          role="tab"
          aria-selected={value === opt.value}
          onClick={() => onChange(opt.value)}
          className={`px-2.5 py-1 first:rounded-l-md last:rounded-r-md transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 ${
            value === opt.value
              ? "bg-foreground text-background"
              : "bg-transparent text-muted-foreground hover:text-foreground"
          }`}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Additional metrics row (FCP + TTFB)
// ---------------------------------------------------------------------------

interface AdditionalMetricRowProps {
  metric: MetricName;
  slice: MetricSlice | undefined;
}

function AdditionalMetricRow({ metric, slice }: AdditionalMetricRowProps) {
  const label = METRIC_LABELS[metric];

  if (!slice || slice.suppressed) {
    return (
      <div className="flex items-center gap-3 py-2">
        <span className="w-12 shrink-0 font-mono text-xs font-semibold text-muted-foreground">
          {label}
        </span>
        <span className="text-xs text-muted-foreground">
          {!slice ? "No data" : `Insufficient samples (${String(slice.sample_count)} of ${String(slice.min_sample_count)})`}
        </span>
      </div>
    );
  }

  const rating = slice.rating;
  const colorClass = rating ? RATING_COLOR_CLASS[rating] : "text-foreground";

  return (
    <div className="flex items-center gap-3 py-2">
      <span className="w-12 shrink-0 font-mono text-xs font-semibold text-muted-foreground">
        {label}
      </span>
      <span className={`tabular-nums text-sm font-semibold ${colorClass}`}>
        {formatP75(metric, slice.p75_ms)}
      </span>
      {rating ? (
        <span className={`text-xs ${colorClass}`}>{RATING_LABELS[rating]}</span>
      ) : null}
      <span className="ml-auto text-xs text-muted-foreground tabular-nums">
        {slice.sample_count.toLocaleString()} samples
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Data extraction helpers
// ---------------------------------------------------------------------------

// The `distribution` field is present in the live API response (CP side is
// DONE) but is not yet in the generated SDK types. We access it via a typed
// cast of the raw row object rather than modifying the generated code.
interface RawMetricRow {
  metric?: string;
  device?: string;
  p75_ms?: number;
  sample_count?: number;
  rating?: "good" | "needs_improvement" | "poor";
  suppressed?: boolean;
  distribution?: RumDistribution;
}

function extractSlice(
  summary: RumSummary,
  metric: MetricName,
  device: DeviceFilter,
  minSampleCount: number,
): MetricSlice | undefined {
  const metrics = summary.metrics ?? [];
  // For "all" devices, look for rows without a device filter (the aggregate
  // row the API emits when device=""). If none, fall back to the
  // device-agnostic aggregate by summing -- but the API should always supply
  // an aggregate row; return undefined if not present.
  const candidates = metrics.filter(
    (m) =>
      m.metric === metric &&
      (device === "all"
        ? !m.device || m.device === ("" as string)
        : m.device === device),
  );
  if (candidates.length === 0) return undefined;
  const row = candidates[0];
  if (!row) return undefined;
  // Cast to access the `distribution` field that the CP now includes but that
  // is not yet reflected in the generated SDK type definition.
  const raw = row as RawMetricRow;
  return {
    p75_ms: raw.p75_ms ?? 0,
    sample_count: raw.sample_count ?? 0,
    rating: raw.rating,
    suppressed: raw.suppressed ?? false,
    min_sample_count: minSampleCount,
    distribution: raw.suppressed ? undefined : raw.distribution,
  };
}

// ---------------------------------------------------------------------------
// Main panel
// ---------------------------------------------------------------------------

// All five metrics rendered as trend charts, core three first.
const TREND_METRICS: TrendMetricName[] = ["lcp", "inp", "cls", "fcp", "ttfb"];

export function FleetRumPanel({ siteId, siteName }: FleetRumPanelProps) {
  const { data, isPending, isError, error, refetch } = useRumSummary(siteId);
  const [device, setDevice] = useState<DeviceFilter>("all");

  const minSampleCount = data?.min_sample_count ?? 100;
  const windowDays = data?.window_days ?? 28;
  const hasMetrics = (data?.metrics ?? []).length > 0;

  // Extract core metric slices for the selected device.
  const lcpSlice = data ? extractSlice(data, "lcp", device, minSampleCount) : undefined;
  const inpSlice = data ? extractSlice(data, "inp", device, minSampleCount) : undefined;
  const clsSlice = data ? extractSlice(data, "cls", device, minSampleCount) : undefined;
  const fcpSlice = data ? extractSlice(data, "fcp", device, minSampleCount) : undefined;
  const ttfbSlice = data ? extractSlice(data, "ttfb", device, minSampleCount) : undefined;

  // Trend data — fetched from the separate /rum/trend endpoint. The device tab
  // is threaded in so switching device triggers a fresh fetch for that segment.
  const { data: trendData } = useRumTrend(siteId, { device, windowDays });

  // Pass/fail: all three core metrics must have a "good" rating (not suppressed).
  // Derive allGood from the individual slices without non-null assertions.
  const lcpRating = lcpSlice && !lcpSlice.suppressed ? lcpSlice.rating : undefined;
  const inpRating = inpSlice && !inpSlice.suppressed ? inpSlice.rating : undefined;
  const clsRating = clsSlice && !clsSlice.suppressed ? clsSlice.rating : undefined;
  const coreRated = lcpRating && inpRating && clsRating;
  const allGood: boolean | null = coreRated
    ? lcpRating === "good" && inpRating === "good" && clsRating === "good"
    : hasMetrics
      ? false
      : null;

  return (
    <section
      aria-labelledby="fleet-rum-heading"
      className="rounded-xl border border-border bg-card text-card-foreground shadow-sm"
    >
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
        <div className="flex items-center gap-3">
          <Activity
            aria-hidden="true"
            className="size-4 shrink-0 text-muted-foreground"
          />
          <div className="min-w-0">
            <h2
              id="fleet-rum-heading"
              className="text-sm font-semibold text-foreground"
            >
              Core Web Vitals{siteName ? ` – ${siteName}` : ""}
            </h2>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Real visitor measurements over {String(windowDays)} days (p75)
            </p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {!isPending && !isError && (
            <OverallBadge allGood={allGood} />
          )}
          <DeviceTabs value={device} onChange={setDevice} />
        </div>
      </div>

      {/* Body */}
      {isPending ? (
        <FleetRumSkeleton />
      ) : isError ? (
        <div className="px-5 py-6">
          <PageError
            what="Could not load Core Web Vitals data."
            why={error?.message ?? "Unknown error"}
            onRetry={() => void refetch()}
            retryLabel="Reload CWV data"
          />
        </div>
      ) : !hasMetrics ? (
        <FleetRumEmpty siteId={siteId} />
      ) : (
        <div className="space-y-5 px-5 py-5">
          {/* Core CWV cards — p75 headline + distribution bar per card */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <CoreMetricCard metric="lcp" slice={lcpSlice} />
            <CoreMetricCard metric="inp" slice={inpSlice} />
            <CoreMetricCard metric="cls" slice={clsSlice} />
          </div>

          {/* Additional metrics */}
          <div className="rounded-lg border border-border divide-y divide-border">
            <p className="px-4 py-2 text-xs font-medium uppercase tracking-[0.02em] text-muted-foreground">
              Additional metrics
            </p>
            <div className="px-4">
              <AdditionalMetricRow metric="fcp" slice={fcpSlice} />
            </div>
            {fcpSlice && !fcpSlice.suppressed && fcpSlice.distribution ? (
              <div className="px-4 pb-3">
                <RumDistributionBar
                  metricLabel="FCP"
                  distribution={fcpSlice.distribution}
                />
              </div>
            ) : null}
            <div className="px-4">
              <AdditionalMetricRow metric="ttfb" slice={ttfbSlice} />
            </div>
            {ttfbSlice && !ttfbSlice.suppressed && ttfbSlice.distribution ? (
              <div className="px-4 pb-3">
                <RumDistributionBar
                  metricLabel="TTFB"
                  distribution={ttfbSlice.distribution}
                />
              </div>
            ) : null}
          </div>

          {/* 28-day p75 trend charts — one per metric, small multiples */}
          {trendData ? (
            <div className="space-y-4">
              <p className="text-xs font-medium uppercase tracking-[0.02em] text-muted-foreground">
                {String(windowDays)}-day p75 trend
              </p>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {TREND_METRICS.map((m) => (
                  <div
                    key={m}
                    className="rounded-lg border border-border bg-background px-3 pt-3 pb-2"
                  >
                    <p className="mb-1 text-xs font-semibold uppercase tracking-[0.04em] text-muted-foreground">
                      {METRIC_LABELS[m]}
                    </p>
                    <RumTrendChart
                      metric={m}
                      points={trendData.metrics[m]}
                      height={140}
                    />
                  </div>
                ))}
              </div>
            </div>
          ) : null}

          <p className="text-xs text-muted-foreground">
            All Core Web Vitals breakdowns by page and device are available in the{" "}
            <Link
              to="/sites/$siteId/optimize"
              params={{ siteId }}
              className="font-medium text-foreground underline underline-offset-4 hover:text-foreground/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            >
              Optimize tab
            </Link>{" "}
            for this site.
          </p>
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function FleetRumSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading Core Web Vitals"
      className="space-y-4 px-5 py-5"
    >
      <span className="sr-only">Loading Core Web Vitals</span>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="flex flex-col gap-2 rounded-lg border border-border px-4 py-3">
            <Skeleton className="h-3 w-10 rounded" />
            <Skeleton className="h-8 w-24 rounded" />
            <Skeleton className="h-3 w-32 rounded" />
          </div>
        ))}
      </div>
      <div className="rounded-lg border border-border px-4 py-4 space-y-3">
        {Array.from({ length: 2 }).map((_, i) => (
          <Skeleton key={i} className="h-5 w-full rounded" />
        ))}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Empty state
// ---------------------------------------------------------------------------

function FleetRumEmpty({ siteId }: { siteId: string }) {
  return (
    <div className="flex flex-col items-center gap-3 px-5 py-10 text-center">
      <Activity
        aria-hidden="true"
        strokeWidth={1.5}
        className="size-8 text-muted-foreground/40"
      />
      <p className="max-w-xs text-sm text-muted-foreground">
        No Real User Monitoring data yet. Enable RUM in the{" "}
        <Link
          to="/sites/$siteId/optimize"
          params={{ siteId }}
          className="font-medium text-foreground underline underline-offset-4 hover:text-foreground/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          Optimize tab
        </Link>{" "}
        and wait for enough visitor pageviews to accumulate.
      </p>
    </div>
  );
}
