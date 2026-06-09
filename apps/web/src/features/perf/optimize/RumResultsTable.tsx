import { useState } from "react";
import { AlertCircle } from "lucide-react";

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";

import { useRumResults } from "../hooks/useRumResults";
import type { RumResult } from "../types";

// RUM breakdown table: one row per (url_pattern, metric, device, country)
// combination returned by GET /perf/rum. Mirrors FontResultsTable in structure.
//
// CRITICAL: rows with suppressed=true MUST NOT show a p75 number. The server
// withheld the estimate because sample_count < min_sample_count (the
// CrUX-style floor configured per deployment). Rendering "0 ms" or any
// interpolated value for a suppressed row would report noise as a real metric.
// Instead, render an explicit "Insufficient samples" notice.
//
// Standard CWV thresholds (official web-vitals constants, same as the collector):
//   LCP:  good <= 2500 ms  /  needs-improvement <= 4000 ms  / poor > 4000 ms
//   INP:  good <= 200 ms   /  needs-improvement <= 500 ms   / poor > 500 ms
//   CLS:  good <= 100 milli-units / NI <= 250 milli-units   / poor > 250 milli-units
//   FCP:  good <= 1800 ms  /  needs-improvement <= 3000 ms  / poor > 3000 ms
//   TTFB: good <= 800 ms   /  needs-improvement <= 1800 ms  / poor > 1800 ms

export interface RumResultsTableProps {
  siteId: string;
  /** When true, the table renders inside the Optimize tab (per-site context). */
  perSite?: boolean;
}

// ---------------------------------------------------------------------------
// Metric labels and units
// ---------------------------------------------------------------------------

const METRIC_LABELS: Record<NonNullable<RumResult["metric"]>, string> = {
  lcp: "LCP",
  inp: "INP",
  cls: "CLS",
  fcp: "FCP",
  ttfb: "TTFB",
};

const DEVICE_LABELS: Record<NonNullable<RumResult["device"]>, string> = {
  desktop: "Desktop",
  mobile: "Mobile",
  tablet: "Tablet",
};

/** Format a p75 value for display. CLS is stored as milli-units (x1000). */
function formatP75(metric: RumResult["metric"], p75_ms: number): string {
  if (metric === "cls") {
    // CLS stored as milli-units: 100 milli-units = 0.100 CLS score.
    return (p75_ms / 1000).toFixed(3);
  }
  if (p75_ms >= 1000) {
    return `${(p75_ms / 1000).toFixed(2)} s`;
  }
  return `${Math.round(p75_ms)} ms`;
}

// ---------------------------------------------------------------------------
// Rating badge
// ---------------------------------------------------------------------------

type Rating = NonNullable<RumResult["rating"]>;

const RATING_COLORS: Record<Rating, string> = {
  good: "text-green-700 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-950/40 dark:border-green-800",
  needs_improvement: "text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-950/40 dark:border-amber-800",
  poor: "text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-950/40 dark:border-red-800",
};

const RATING_LABELS: Record<Rating, string> = {
  good: "Good",
  needs_improvement: "Needs work",
  poor: "Poor",
};

interface RatingBadgeProps {
  rating: Rating | undefined;
  suppressed: boolean | undefined;
  sampleCount: number | undefined;
  minSampleCount?: number;
}

function RatingCell({ rating, suppressed, sampleCount, minSampleCount }: RatingBadgeProps) {
  if (suppressed) {
    const needed = minSampleCount ?? "?";
    const have = sampleCount ?? 0;
    return (
      <span
        className="inline-flex items-center gap-1 text-xs text-muted-foreground"
        title={`Insufficient samples: ${String(have)} of ${String(needed)} needed`}
        aria-label={`Insufficient samples: ${String(have)} of ${String(needed)} needed`}
      >
        <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
        <span>
          Insufficient samples ({String(have)} of {String(needed)})
        </span>
      </span>
    );
  }
  if (!rating) return <span className="text-muted-foreground">–</span>;
  return (
    <span
      className={`inline-flex items-center rounded border px-1.5 py-0.5 text-xs font-medium ${RATING_COLORS[rating]}`}
    >
      {RATING_LABELS[rating]}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Device segment toggle
// ---------------------------------------------------------------------------

type DeviceFilter = "all" | "desktop" | "mobile" | "tablet";

interface DeviceToggleProps {
  value: DeviceFilter;
  onChange: (v: DeviceFilter) => void;
}

function DeviceToggle({ value, onChange }: DeviceToggleProps) {
  const options: Array<{ value: DeviceFilter; label: string }> = [
    { value: "all", label: "All" },
    { value: "desktop", label: "Desktop" },
    { value: "mobile", label: "Mobile" },
    { value: "tablet", label: "Tablet" },
  ];
  return (
    <div
      role="group"
      aria-label="Filter by device"
      className="inline-flex items-center rounded-md border border-border text-xs"
    >
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          onClick={() => onChange(opt.value)}
          aria-pressed={value === opt.value}
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
// Main table
// ---------------------------------------------------------------------------

export function RumResultsTable({ siteId, perSite = false }: RumResultsTableProps) {
  const results = useRumResults(siteId);
  const [deviceFilter, setDeviceFilter] = useState<DeviceFilter>("all");
  // Show only the three core CWV metrics by default; allow toggling all five.
  const [showAll, setShowAll] = useState(false);

  const items = results.data ?? [];

  // Apply device filter.
  const filtered = items.filter((r) =>
    deviceFilter === "all" ? true : r.device === deviceFilter,
  );

  // Optionally restrict to core CWV (LCP, INP, CLS).
  const coreMetrics: Array<RumResult["metric"]> = ["lcp", "inp", "cls"];
  const visible = showAll
    ? filtered
    : filtered.filter((r) => coreMetrics.includes(r.metric));

  const tableTitle = perSite ? "Core Web Vitals breakdown" : "Per-URL Core Web Vitals";

  return (
    <section className="space-y-3 rounded-xl border border-border bg-card text-card-foreground shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4 border-b border-border px-5 py-4">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">{tableTitle}</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            p75 values from real visitor measurements over 28 days.{" "}
            <span className="font-medium">
              Rows marked "Insufficient samples" are withheld -- the slice has
              fewer measurements than the configured minimum and showing an
              estimate would report noise.
            </span>
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <DeviceToggle value={deviceFilter} onChange={setDeviceFilter} />
        </div>
      </div>

      {results.isPending ? (
        <div
          role="status"
          aria-busy="true"
          aria-label="Loading Core Web Vitals results"
          className="space-y-2 p-5"
        >
          <span className="sr-only">Loading Core Web Vitals results</span>
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      ) : results.isError ? (
        <p role="alert" className="px-5 py-8 text-center text-sm text-muted-foreground">
          Could not load Core Web Vitals data. {results.error.message}
        </p>
      ) : visible.length === 0 ? (
        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
          {items.length === 0
            ? "No measurements yet. Enable Real User Monitoring and wait for visitors to accumulate enough pageviews."
            : "No results match the current filter."}
        </p>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="min-w-[180px]">URL pattern</TableHead>
                <TableHead>Metric</TableHead>
                <TableHead>Device</TableHead>
                <TableHead className="text-right">p75</TableHead>
                <TableHead className="text-right">Samples</TableHead>
                <TableHead>Rating</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {visible.map((r, idx) => {
                const key = `${r.url_pattern ?? ""}-${r.metric ?? ""}-${r.device ?? ""}-${r.country ?? ""}-${String(idx)}`;
                const metric = r.metric;
                const label = metric ? (METRIC_LABELS[metric] ?? metric.toUpperCase()) : "–";
                const device = r.device ? (DEVICE_LABELS[r.device] ?? r.device) : "–";
                const p75Display =
                  r.suppressed || !metric || r.p75_ms === undefined || r.p75_ms === null
                    ? null
                    : formatP75(metric, r.p75_ms);
                const country = r.country && r.country !== "__other__"
                  ? r.country.toUpperCase()
                  : r.country === "__other__" ? "Other" : null;

                return (
                  <TableRow key={key}>
                    {/* URL pattern */}
                    <TableCell className="max-w-[240px]">
                      <span
                        className="block truncate font-mono text-xs text-foreground"
                        title={r.url_pattern ?? "–"}
                      >
                        {r.url_pattern ?? "–"}
                      </span>
                      {country ? (
                        <Badge variant="secondary" className="mt-0.5 text-xs">
                          {country}
                        </Badge>
                      ) : null}
                    </TableCell>

                    {/* Metric */}
                    <TableCell className="whitespace-nowrap font-mono text-xs text-foreground">
                      {label}
                    </TableCell>

                    {/* Device */}
                    <TableCell className="text-xs text-muted-foreground">
                      {device}
                    </TableCell>

                    {/* p75 value */}
                    <TableCell className="text-right tabular-nums font-medium text-foreground">
                      {p75Display ?? <span className="text-muted-foreground">–</span>}
                    </TableCell>

                    {/* Sample count */}
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {r.sample_count?.toLocaleString() ?? "–"}
                    </TableCell>

                    {/* Rating / suppressed state */}
                    <TableCell>
                      <RatingCell
                        rating={r.rating}
                        suppressed={r.suppressed}
                        sampleCount={r.sample_count}
                      />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>

          {/* Show all / show core toggle */}
          <div className="flex justify-center px-5 pb-4 pt-1">
            <button
              type="button"
              onClick={() => setShowAll((v) => !v)}
              className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            >
              {showAll ? "Show core metrics only (LCP, INP, CLS)" : "Show all metrics (FCP, TTFB too)"}
            </button>
          </div>
        </>
      )}

      <div className="h-1" aria-hidden="true" />
    </section>
  );
}
