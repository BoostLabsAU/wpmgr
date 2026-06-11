// Object cache analytics charts — hit ratio, memory, latency, ops/sec.
//
// Each chart is a thin Recharts area chart following the cache-hit-ratio-chart
// and db-size-chart conventions: fixed Y-axis domain, semantic chart color
// tokens (chart-1..chart-4), RFC 3339 / ISO date X-axis, shared ChartTooltip,
// ChartEmpty when fewer than 2 points are present.
//
// The four charts are exported separately so ObjectCachePanel can render them
// in individual cards with their own loading states.

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
import type { ObjectCacheStatsHistoryPoint } from "@wpmgr/api";

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function tickInterval(length: number): number {
  return Math.max(0, Math.floor(length / 6) - 1);
}

// ---------------------------------------------------------------------------
// Hit ratio chart (chart-3, 0..100 %)
// ---------------------------------------------------------------------------

export interface ObjectCacheHitRatioChartProps {
  points: ObjectCacheStatsHistoryPoint[];
  height?: number;
}

export function ObjectCacheHitRatioChart({
  points,
  height = 160,
}: ObjectCacheHitRatioChartProps) {
  if (!points || points.length < 2) {
    return (
      <ChartEmpty message="Not enough data yet — stats accumulate once the object cache is active" />
    );
  }
  const interval = tickInterval(points.length);
  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={points}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id="ocHitRatioFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="var(--color-chart-3)" stopOpacity={0.18} />
              <stop offset="95%" stopColor="var(--color-chart-3)" stopOpacity={0.03} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
          <XAxis
            dataKey="sampled_at"
            tickFormatter={shortDate}
            interval={interval}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            dataKey="ratio_pct"
            domain={[0, 100]}
            tickFormatter={(v: number) => `${v}%`}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={40}
          />
          <Tooltip
            content={
              <ChartTooltip
                formatter={(v) => `${v.toFixed(1)}%`}
                labelFormatter={(l) => shortDate(l)}
              />
            }
            cursor={{ stroke: "var(--color-border)", strokeDasharray: "3 3" }}
          />
          <Area
            type="monotone"
            dataKey="ratio_pct"
            name="Hit ratio"
            stroke="var(--color-chart-3)"
            strokeWidth={1.5}
            fill="url(#ocHitRatioFill)"
            dot={false}
            activeDot={{ r: 3, stroke: "var(--color-chart-3)", strokeWidth: 1, fill: "var(--color-background)" }}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Memory chart (chart-2, bytes, human-readable ticks)
// ---------------------------------------------------------------------------

export interface ObjectCacheMemoryChartProps {
  points: ObjectCacheStatsHistoryPoint[];
  height?: number;
}

function bytesLabel(b: number): string {
  if (b <= 0) return "0";
  const units = ["B", "KB", "MB", "GB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  const v = b / 1024 ** i;
  return `${v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)} ${units[i]}`;
}

export function ObjectCacheMemoryChart({
  points,
  height = 160,
}: ObjectCacheMemoryChartProps) {
  if (!points || points.length < 2) {
    return <ChartEmpty message="Not enough data yet" />;
  }
  const interval = tickInterval(points.length);
  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={points}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id="ocMemFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="var(--color-chart-2)" stopOpacity={0.18} />
              <stop offset="95%" stopColor="var(--color-chart-2)" stopOpacity={0.03} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
          <XAxis
            dataKey="sampled_at"
            tickFormatter={shortDate}
            interval={interval}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            dataKey="used_memory_bytes"
            tickFormatter={bytesLabel}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={56}
          />
          <Tooltip
            content={
              <ChartTooltip
                formatter={(v) => bytesLabel(v)}
                labelFormatter={(l) => shortDate(l)}
              />
            }
            cursor={{ stroke: "var(--color-border)", strokeDasharray: "3 3" }}
          />
          <Area
            type="monotone"
            dataKey="used_memory_bytes"
            name="Used memory"
            stroke="var(--color-chart-2)"
            strokeWidth={1.5}
            fill="url(#ocMemFill)"
            dot={false}
            activeDot={{ r: 3, stroke: "var(--color-chart-2)", strokeWidth: 1, fill: "var(--color-background)" }}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Latency chart (chart-1, ms)
// ---------------------------------------------------------------------------

export interface ObjectCacheLatencyChartProps {
  points: ObjectCacheStatsHistoryPoint[];
  height?: number;
}

export function ObjectCacheLatencyChart({
  points,
  height = 160,
}: ObjectCacheLatencyChartProps) {
  const filtered = (points ?? []).filter((p) => p.avg_wait_ms !== undefined && p.avg_wait_ms !== null);
  if (filtered.length < 2) {
    return <ChartEmpty message="Not enough data yet" />;
  }
  const interval = tickInterval(filtered.length);
  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={filtered}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id="ocLatFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="var(--color-chart-1)" stopOpacity={0.18} />
              <stop offset="95%" stopColor="var(--color-chart-1)" stopOpacity={0.03} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
          <XAxis
            dataKey="sampled_at"
            tickFormatter={shortDate}
            interval={interval}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            dataKey="avg_wait_ms"
            tickFormatter={(v: number) => `${v.toFixed(1)}ms`}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={52}
          />
          <Tooltip
            content={
              <ChartTooltip
                formatter={(v) => `${v.toFixed(2)} ms`}
                labelFormatter={(l) => shortDate(l)}
              />
            }
            cursor={{ stroke: "var(--color-border)", strokeDasharray: "3 3" }}
          />
          <Area
            type="monotone"
            dataKey="avg_wait_ms"
            name="Avg latency"
            stroke="var(--color-chart-1)"
            strokeWidth={1.5}
            fill="url(#ocLatFill)"
            dot={false}
            activeDot={{ r: 3, stroke: "var(--color-chart-1)", strokeWidth: 1, fill: "var(--color-background)" }}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Ops/sec chart (chart-4)
// ---------------------------------------------------------------------------

export interface ObjectCacheOpsChartProps {
  points: ObjectCacheStatsHistoryPoint[];
  height?: number;
}

export function ObjectCacheOpsChart({
  points,
  height = 160,
}: ObjectCacheOpsChartProps) {
  if (!points || points.length < 2) {
    return <ChartEmpty message="Not enough data yet" />;
  }
  const interval = tickInterval(points.length);
  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={points}
          margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
        >
          <defs>
            <linearGradient id="ocOpsFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="var(--color-chart-4)" stopOpacity={0.18} />
              <stop offset="95%" stopColor="var(--color-chart-4)" stopOpacity={0.03} />
            </linearGradient>
          </defs>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
          <XAxis
            dataKey="sampled_at"
            tickFormatter={shortDate}
            interval={interval}
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            dataKey="ops_per_sec"
            tick={{ fill: "var(--color-muted-foreground)", fontSize: 11 }}
            stroke="var(--color-border)"
            tickLine={false}
            axisLine={false}
            width={40}
          />
          <Tooltip
            content={
              <ChartTooltip
                formatter={(v) => `${v.toFixed(0)} ops/s`}
                labelFormatter={(l) => shortDate(l)}
              />
            }
            cursor={{ stroke: "var(--color-border)", strokeDasharray: "3 3" }}
          />
          <Area
            type="monotone"
            dataKey="ops_per_sec"
            name="Ops/sec"
            stroke="var(--color-chart-4)"
            strokeWidth={1.5}
            fill="url(#ocOpsFill)"
            dot={false}
            activeDot={{ r: 3, stroke: "var(--color-chart-4)", strokeWidth: 1, fill: "var(--color-background)" }}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
