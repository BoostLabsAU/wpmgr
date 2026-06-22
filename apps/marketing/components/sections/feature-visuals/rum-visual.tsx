"use client";

import { motion } from "motion/react";
import { cn } from "@/lib/utils";

// Illustrative RUM preview widget for the Real User Monitoring feature page.
// Sample data only.

type Rating = "good" | "needs-improvement" | "poor";

const RATING_COLOR: Record<Rating, { bar: string; text: string; bg: string }> = {
  "good": { bar: "bg-[var(--success)]", text: "text-[var(--success)]", bg: "bg-[var(--success)]/12" },
  "needs-improvement": { bar: "bg-[var(--warning-subtle-fg)]", text: "text-[var(--warning-subtle-fg)]", bg: "bg-[var(--warning-subtle-fg)]/12" },
  "poor": { bar: "bg-destructive", text: "text-destructive", bg: "bg-destructive/12" },
};

const METRICS = [
  { name: "LCP", p75: "2.1s", rating: "good" as Rating },
  { name: "INP", p75: "148ms", rating: "good" as Rating },
  { name: "CLS", p75: "0.05", rating: "good" as Rating },
  { name: "FCP", p75: "1.4s", rating: "good" as Rating },
  { name: "TTFB", p75: "310ms", rating: "needs-improvement" as Rating },
];

const DISTRIBUTION = [
  { label: "Good", pct: 68, rating: "good" as Rating },
  { label: "Needs improvement", pct: 22, rating: "needs-improvement" as Rating },
  { label: "Poor", pct: 10, rating: "poor" as Rating },
];

const TREND = [2.6, 2.4, 2.3, 2.5, 2.2, 2.0, 2.1, 2.3, 2.0, 1.9, 2.1, 2.0, 2.1];
const THRESHOLD = 2.5;

export function RumVisual() {
  const maxT = Math.max(...TREND, THRESHOLD);
  const minT = Math.min(...TREND);
  const H = 48;
  const W = 200;

  const pts = TREND.map((v, i) => ({
    x: (i / (TREND.length - 1)) * W,
    y: H - ((v - minT) / (maxT - minT)) * H,
  }));
  const polyline = pts.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(" ");
  const thresholdY = H - ((THRESHOLD - minT) / (maxT - minT)) * H;

  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      {/* Header */}
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Core Web Vitals</span>
        <span className="text-xs text-[var(--muted-foreground)]">p75 from real visitors</span>
      </div>

      {/* LCP spotlight */}
      <div className="flex items-center gap-3 rounded-lg bg-[var(--muted)]/50 p-3">
        <div className="flex flex-col gap-0.5 flex-1">
          <span className="text-xs font-medium text-[var(--muted-foreground)]">LCP (Largest Contentful Paint)</span>
          <div className="flex items-baseline gap-2">
            <span className="font-mono text-2xl font-semibold text-foreground tabular-nums">2.1s</span>
            <span className={cn("rounded px-1.5 py-0.5 text-xs font-medium", RATING_COLOR.good.bg, RATING_COLOR.good.text)}>
              Good
            </span>
          </div>
        </div>
        {/* Mini trend */}
        <svg viewBox={`0 0 ${W} ${H + 4}`} className="h-12 w-24 shrink-0" aria-hidden>
          <line
            x1={0} y1={thresholdY} x2={W} y2={thresholdY}
            strokeDasharray="3 2"
            strokeWidth={1}
            className="stroke-[var(--muted-foreground)]/40"
          />
          <motion.polyline
            points={polyline}
            fill="none"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="stroke-[var(--primary)]"
            initial={{ pathLength: 0 }}
            whileInView={{ pathLength: 1 }}
            viewport={{ once: true }}
            transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
          />
        </svg>
      </div>

      {/* Distribution bar */}
      <div className="flex flex-col gap-2">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Distribution</span>
        <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-[var(--muted)]">
          {DISTRIBUTION.map((seg) => (
            <motion.div
              key={seg.label}
              className={cn("h-full", RATING_COLOR[seg.rating].bar)}
              initial={{ width: 0 }}
              whileInView={{ width: `${seg.pct}%` }}
              viewport={{ once: true }}
              transition={{ duration: 0.6, ease: "easeOut" }}
            />
          ))}
        </div>
        <div className="flex gap-4">
          {DISTRIBUTION.map((seg) => (
            <span key={seg.label} className="inline-flex items-center gap-1.5 text-xs text-[var(--muted-foreground)]">
              <span className={cn("h-2 w-2 rounded-full", RATING_COLOR[seg.rating].bar)} />
              {seg.pct}% {seg.label}
            </span>
          ))}
        </div>
      </div>

      {/* Metrics table */}
      <div className="grid grid-cols-5 gap-1.5 border-t border-[var(--border)] pt-3">
        {METRICS.map((m) => (
          <div key={m.name} className="flex flex-col items-center gap-0.5">
            <span className={cn("rounded px-1 py-0.5 font-mono text-xs font-semibold", RATING_COLOR[m.rating].bg, RATING_COLOR[m.rating].text)}>
              {m.p75}
            </span>
            <span className="text-[10px] font-medium text-[var(--muted-foreground)]">{m.name}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
