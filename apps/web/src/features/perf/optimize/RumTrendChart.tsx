// RumTrendChart — daily p75 line/area chart for one CWV metric.
//
// Mirrors cache-hit-ratio-chart.tsx conventions exactly:
//   - ChartEmpty when fewer than 2 non-suppressed points.
//   - ResponsiveContainer + AreaChart.
//   - var(--color-chart-*) stroke/fill.
//   - ChartTooltip for the tooltip.
//   - ~6 X-axis ticks, short-date "Mon D" labels.
//   - isAnimationActive={false}.
//
// Two ReferenceLine overlays at the metric's good and needs-improvement
// thresholds (green / amber) so the operator sees where the series sits
// relative to the CWV pass/fail bands, exactly as Google's PageSpeed Insights.
//
// Suppressed days (p75_ms=0, suppressed=true) are mapped to null for the Y
// value. connectNulls={false} causes Recharts to break the line at those days
// rather than interpolating through them, preserving data honesty.
//
// CLS display: divide p75_ms by 1000 before rendering; show 3 decimal places.
// All other metrics render as ms with one decimal place (or seconds when >= 1s).
//
// Tooltip: shows the day, the formatted p75, and the sample_count.

import type { ReactNode } from "react";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { ChartEmpty } from "@/components/charts/chart-empty";
import type { RumTrendPoint } from "../types";

// ---------------------------------------------------------------------------
// Display constants
// ---------------------------------------------------------------------------

export type MetricName = "lcp" | "inp" | "cls" | "fcp" | "ttfb";

/** Official CWV thresholds in display units (ms for timing, unitless for CLS). */
const THRESHOLDS: Record<MetricName, { good: number; ni: number }> = {
  lcp: { good: 2500, ni: 4000 },
  inp: { good: 200, ni: 500 },
  cls: { good: 0.1, ni: 0.25 },
  fcp: { good: 1800, ni: 3000 },
  ttfb: { good: 800, ni: 1800 },
};

const METRIC_LABELS: Record<MetricName, string> = {
  lcp: "LCP",
  inp: "INP",
  cls: "CLS",
  fcp: "FCP",
  ttfb: "TTFB",
};

const METRIC_UNITS: Record<MetricName, string> = {
  lcp: "ms",
  inp: "ms",
  cls: "",
  fcp: "ms",
  ttfb: "ms",
};

// Chart-token per metric to vary the line color across small multiples.
const CHART_TOKEN: Record<MetricName, string> = {
  lcp: "var(--color-chart-1)",
  inp: "var(--color-chart-4)",
  cls: "var(--color-chart-5)",
  fcp: "var(--color-chart-2)",
  ttfb: "var(--color-chart-3)",
};

// Gradient IDs must be unique per metric to avoid cross-contamination.
const GRADIENT_ID: Record<MetricName, string> = {
  lcp: "rumTrendFill_lcp",
  inp: "rumTrendFill_inp",
  cls: "rumTrendFill_cls",
  fcp: "rumTrendFill_fcp",
  ttfb: "rumTrendFill_ttfb",
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function shortDate(day: string): string {
  // day is "YYYY-MM-DD" — parse as UTC noon to avoid timezone off-by-one.
  const d = new Date(`${day}T12:00:00Z`);
  if (Number.isNaN(d.getTime())) return day;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

/** Convert raw p75_ms from the API to the display value for a given metric. */
function toDisplayValue(metric: MetricName, p75_ms: number): number {
  return metric === "cls" ? p75_ms / 1000 : p75_ms;
}

/** Format a display value (already converted) for tooltip / axis labels. */
function formatDisplay(metric: MetricName, value: number): string {
  if (metric === "cls") {
    return value.toFixed(3);
  }
  if (value >= 1000) {
    return `${(value / 1000).toFixed(1)} s`;
  }
  return `${value.toFixed(1)} ms`;
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** Internal chart datum. Y is null for suppressed days so the line breaks. */
interface ChartDatum {
  day: string;
  y: number | null;
  sample_count: number;
  suppressed: boolean;
}

// ---------------------------------------------------------------------------
// Custom tooltip
// ---------------------------------------------------------------------------

interface TrendTooltipProps {
  active?: boolean;
  payload?: Array<{
    value?: number | null;
    payload?: ChartDatum;
    color?: string;
    stroke?: string;
  }>;
  label?: string;
  metric: MetricName;
}

function TrendTooltip({ active, payload, label, metric }: TrendTooltipProps) {
  if (!active || !payload || payload.length === 0) return null;
  const entry = payload[0];
  const datum = entry?.payload;
  const dayLabel = label ? shortDate(label) : "";
  const value = entry?.value;
  const swatch = entry?.color ?? entry?.stroke ?? CHART_TOKEN[metric];

  return (
    <div
      role="tooltip"
      className="rounded-md border border-[var(--color-border)] bg-[var(--color-popover)] px-3 py-2 text-sm shadow-md"
    >
      <div className="mb-1 text-xs text-[var(--color-muted-foreground)]">
        {dayLabel}
      </div>
      <dl className="flex flex-col gap-1">
        <div className="flex items-center gap-2">
          <span
            aria-hidden="true"
            className="inline-block h-2 w-2 rounded-full"
            style={{ backgroundColor: swatch }}
          />
          <dt className="text-[var(--color-muted-foreground)]">
            {METRIC_LABELS[metric]} p75
          </dt>
          <dd className="ml-auto font-mono tabular-nums text-[var(--color-foreground)]">
            {value !== null && value !== undefined
              ? formatDisplay(metric, value)
              : "Insufficient samples"}
          </dd>
        </div>
        {datum && (
          <div className="flex items-center gap-2 text-xs">
            <span className="text-[var(--color-muted-foreground)]">
              Samples
            </span>
            <span className="ml-auto font-mono tabular-nums text-[var(--color-foreground)]">
              {datum.sample_count.toLocaleString()}
            </span>
          </div>
        )}
      </dl>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Y-axis tick formatter
// ---------------------------------------------------------------------------

function makeYFormatter(metric: MetricName): (v: number) => string {
  if (metric === "cls") {
    return (v: number) => v.toFixed(2);
  }
  return (v: number) => {
    if (v >= 1000) return `${(v / 1000).toFixed(0)}s`;
    return `${Math.round(v)}`;
  };
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface RumTrendChartProps {
  /** Metric this chart visualises. */
  metric: MetricName;
  /** Daily trend series from the trend endpoint. */
  points: RumTrendPoint[];
  height?: number;
  /** Optional title rendered above the chart (e.g. "LCP - 28d p75 trend"). */
  title?: ReactNode;
}

export function RumTrendChart({
  metric,
  points,
  height = 160,
  title,
}: RumTrendChartProps) {
  const thresholds = THRESHOLDS[metric];
  const chartToken = CHART_TOKEN[metric];
  const gradientId = GRADIENT_ID[metric];
  const unit = METRIC_UNITS[metric];

  // Map API points to chart datums. Suppressed days become y=null so the line
  // breaks (connectNulls={false}). Non-suppressed days are converted to display
  // units.
  const chartData: ChartDatum[] = points.map((pt) => ({
    day: pt.day,
    y: pt.suppressed ? null : toDisplayValue(metric, pt.p75_ms),
    sample_count: pt.sample_count,
    suppressed: pt.suppressed,
  }));

  // Count non-suppressed points. Show ChartEmpty when fewer than 2.
  const unsuppressedCount = chartData.filter((d) => d.y !== null).length;

  const content =
    unsuppressedCount < 2 ? (
      <ChartEmpty message="Not enough data yet to show a trend" />
    ) : (
      renderChart(chartData, metric, thresholds, chartToken, gradientId, unit, height)
    );

  if (!title) return <>{content}</>;

  return (
    <div>
      <p className="mb-1 text-xs font-medium text-muted-foreground">{title}</p>
      {content}
    </div>
  );
}

function renderChart(
  chartData: ChartDatum[],
  metric: MetricName,
  thresholds: { good: number; ni: number },
  chartToken: string,
  gradientId: string,
  unit: string,
  height: number,
): ReactNode {
  const interval = Math.max(0, Math.floor(chartData.length / 6) - 1);
  const yFormatter = makeYFormatter(metric);

  const yDomain: [number | string, number | string] = ["auto", "auto"];

  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={chartData}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="5%"
                stopColor={chartToken}
                stopOpacity={0.18}
              />
              <stop
                offset="95%"
                stopColor={chartToken}
                stopOpacity={0.03}
              />
            </linearGradient>
          </defs>

          <CartesianGrid
            strokeDasharray="3 3"
            stroke="var(--color-border)"
            vertical={false}
          />

          <XAxis
            dataKey="day"
            tickFormatter={shortDate}
            interval={interval}
            tick={{
              fill: "var(--color-muted-foreground)",
              fontSize: 11,
            }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
          />

          <YAxis
            dataKey="y"
            domain={yDomain}
            tickFormatter={yFormatter}
            tick={{
              fill: "var(--color-muted-foreground)",
              fontSize: 11,
            }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={metric === "cls" ? 38 : 44}
            unit={unit}
          />

          <Tooltip
            content={(props) => (
              <TrendTooltip
                active={props.active}
                payload={props.payload as unknown as TrendTooltipProps["payload"]}
                label={typeof props.label === "string" ? props.label : undefined}
                metric={metric}
              />
            )}
            cursor={{
              stroke: "var(--color-border)",
              strokeDasharray: "3 3",
            }}
          />

          {/* Good threshold — green */}
          <ReferenceLine
            y={thresholds.good}
            stroke="var(--color-chart-1)"
            strokeDasharray="4 3"
            strokeWidth={1}
            label={{
              value: `Good ${yFormatter(thresholds.good)}${unit}`,
              position: "insideTopRight",
              fontSize: 9,
              fill: "var(--color-chart-1)",
            }}
          />

          {/* NI threshold — amber */}
          <ReferenceLine
            y={thresholds.ni}
            stroke="var(--color-chart-4)"
            strokeDasharray="4 3"
            strokeWidth={1}
            label={{
              value: `NI ${yFormatter(thresholds.ni)}${unit}`,
              position: "insideTopRight",
              fontSize: 9,
              fill: "var(--color-chart-4)",
            }}
          />

          <Area
            type="monotone"
            dataKey="y"
            name={`${METRIC_LABELS[metric]} p75`}
            stroke={chartToken}
            strokeWidth={1.5}
            fill={`url(#${gradientId})`}
            dot={false}
            activeDot={{
              r: 3,
              stroke: chartToken,
              strokeWidth: 1,
              fill: "var(--color-background)",
            }}
            isAnimationActive={false}
            connectNulls={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
