// DB-size trend chart — 90-day area chart for the Database Cleaner Health view.
//
// Mirrors uptime-chart.tsx conventions: same axis style (3-3 dashed grid in
// --color-border, muted small ticks, no axis lines), same ChartTooltip, same
// ChartEmpty guard for < 2 points. Area fill uses chart-2 at low opacity to
// distinguish it from the uptime line (chart-1).
//
// Y-axis: bytes formatted via formatBytes (1024-base, "MB"/"KB"/etc.). The
// domain is [dataMin, dataMax] so the chart uses full vertical space rather
// than starting from 0 (a DB that sits at 45 MB and grows to 47 MB should show
// a visible slope, not a flat line near the top of a 0-based axis).
//
// X-axis: scanned_at ISO timestamps formatted to "Mon D" short strings with
// an auto-calculated tick interval targeting ~6 labels.

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
import { formatBytes } from "@/features/perf/format";
import type { DbSizeTrendPoint } from "@/features/perf/types";

export interface DbSizeChartProps {
  /** Ordered oldest-first (as returned by the /perf/db/health endpoint). */
  points: DbSizeTrendPoint[];
  height?: number;
}

function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

export function DbSizeChart({ points, height = 180 }: DbSizeChartProps) {
  // Fewer than 2 points means no trend to display. Show the empty state so the
  // operator understands data is still accumulating rather than seeing a
  // misleading single-point flat line.
  if (!points || points.length < 2) {
    return (
      <ChartEmpty message="Collecting data — run a scan to start the trend" />
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
            <linearGradient id="dbSizeFill" x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="5%"
                stopColor="var(--color-chart-2)"
                stopOpacity={0.18}
              />
              <stop
                offset="95%"
                stopColor="var(--color-chart-2)"
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
            dataKey="scanned_at"
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
            dataKey="db_size_bytes"
            domain={["dataMin", "dataMax"]}
            tickFormatter={(v: number) => formatBytes(v)}
            tick={{
              fill: "var(--color-muted-foreground)",
              fontSize: 11,
            }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={54}
          />

          <Tooltip
            content={
              <ChartTooltip
                formatter={(v) => formatBytes(v)}
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
            dataKey="db_size_bytes"
            name="DB size"
            stroke="var(--color-chart-2)"
            strokeWidth={1.5}
            fill="url(#dbSizeFill)"
            dot={false}
            activeDot={{
              r: 3,
              stroke: "var(--color-chart-2)",
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
