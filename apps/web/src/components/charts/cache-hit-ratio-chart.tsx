// Cache hit-ratio area chart — mirrors db-size-chart.tsx conventions exactly.
//
// Y-axis: fixed 0..100 domain (ratio_pct is a natural bounded percentage).
//   Tick formatter appends "%". dataMin/dataMax is intentionally NOT used —
//   a ratio of 95% should read as "near the top", not "filling the chart".
//
// X-axis: sampled_at RFC 3339 timestamps formatted to "Mon D" short strings
//   with an auto-calculated tick interval targeting ~6 labels.
//
// Area fill uses chart-3 to distinguish it from the uptime (chart-1) and
// db-size (chart-2) series.
//
// Empty state: shown when fewer than 2 points are present. The endpoint
// returns empty until the agent begins reporting hit/miss traffic, so the
// empty state is the normal first-paint for new sites.

import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { ChartTooltip } from "./chart-tooltip";
import { ChartEmpty } from "./chart-empty";
import type { CacheHitRatioPoint } from "@/features/perf/types";

export interface CacheHitRatioChartProps {
  /** Ordered oldest-first (as returned by the /perf/cache/health endpoint). */
  points: CacheHitRatioPoint[];
  height?: number;
}

function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function pctFormatter(v: number): string {
  return `${v.toFixed(1)}%`;
}

export function CacheHitRatioChart({ points, height = 180 }: CacheHitRatioChartProps) {
  // Fewer than 2 points means no trend to display. Show the empty state so the
  // operator understands data is still accumulating rather than a flat line.
  if (!points || points.length < 2) {
    return (
      <ChartEmpty message="Not enough data yet — caching has not produced traffic yet" />
    );
  }

  // Target ~6 X-axis ticks regardless of series length.
  const interval = Math.max(0, Math.floor(points.length / 6) - 1);

  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={points}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id="cacheHitRatioFill" x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="5%"
                stopColor="var(--color-chart-3)"
                stopOpacity={0.18}
              />
              <stop
                offset="95%"
                stopColor="var(--color-chart-3)"
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
            dataKey="sampled_at"
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
            dataKey="ratio_pct"
            domain={[0, 100]}
            tickFormatter={(v: number) => `${v}%`}
            tick={{
              fill: "var(--color-muted-foreground)",
              fontSize: 11,
            }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={40}
          />

          <Tooltip
            content={
              <ChartTooltip
                formatter={pctFormatter}
                labelFormatter={(l) => shortDate(l)}
              />
            }
            cursor={{
              stroke: "var(--color-border)",
              strokeDasharray: "3 3",
            }}
          />

          <Area
            type="monotone"
            dataKey="ratio_pct"
            name="Hit ratio"
            stroke="var(--color-chart-3)"
            strokeWidth={1.5}
            fill="url(#cacheHitRatioFill)"
            dot={false}
            activeDot={{
              r: 3,
              stroke: "var(--color-chart-3)",
              strokeWidth: 1,
              fill: "var(--color-background)",
            }}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
