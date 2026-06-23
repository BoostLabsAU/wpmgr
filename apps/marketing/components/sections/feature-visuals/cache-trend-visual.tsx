"use client";

import { motion } from "motion/react";
import { cn } from "@/lib/utils";

// Illustrative cache-trend widget for the performance feature page.
// Static sample data only.

const TREND_POINTS = [42, 55, 48, 61, 58, 72, 68, 75, 80, 78, 85, 88];
const LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

const STATS = [
  { label: "Cache hit ratio", value: "88%", tone: "success" },
  { label: "Pages from disk", value: "12k/hr", tone: "teal" },
  { label: "Avg PHP time", value: "0 ms", tone: "muted" },
] as const;

const TONE_TEXT: Record<string, string> = {
  success: "text-[var(--success)]",
  teal: "text-[var(--primary)]",
  muted: "text-[var(--muted-foreground)]",
};

export function CacheTrendVisual() {
  const max = Math.max(...TREND_POINTS);
  const min = Math.min(...TREND_POINTS);
  const range = max - min || 1;
  const H = 80;

  const pts = TREND_POINTS.map((v, i) => {
    const x = (i / (TREND_POINTS.length - 1)) * 260;
    const y = H - ((v - min) / range) * H;
    return { x, y, v };
  });

  const polyline = pts.map((p) => `${p.x},${p.y}`).join(" ");
  const area = `${pts[0]!.x},${H} ` + pts.map((p) => `${p.x},${p.y}`).join(" ") + ` ${pts[pts.length - 1]!.x},${H}`;

  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Page cache hit ratio</span>
        <span className="rounded-md bg-[var(--success)]/12 px-2 py-0.5 font-mono text-xs font-medium text-[var(--success)]">
          88% today
        </span>
      </div>

      {/* Mini chart */}
      <div className="relative overflow-hidden rounded-lg bg-[var(--muted)]/50 p-3">
        <svg
          viewBox={`0 0 260 ${H + 8}`}
          className="w-full"
          aria-hidden
          style={{ height: 80 }}
        >
          {/* Area fill */}
          <motion.polygon
            points={area}
            className="fill-[var(--primary)]/10"
            initial={{ opacity: 0 }}
            whileInView={{ opacity: 1 }}
            viewport={{ once: true }}
            transition={{ duration: 0.5, ease: "easeOut" }}
          />
          {/* Trend line */}
          <motion.polyline
            points={polyline}
            fill="none"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="stroke-[var(--primary)]"
            initial={{ pathLength: 0, opacity: 0 }}
            whileInView={{ pathLength: 1, opacity: 1 }}
            viewport={{ once: true }}
            transition={{ duration: 1.0, ease: [0.22, 1, 0.36, 1] }}
          />
          {/* Dots */}
          {pts.map((p, i) => (
            <circle
              key={i}
              cx={p.x}
              cy={p.y}
              r={i === pts.length - 1 ? 4 : 2.5}
              className={cn(
                "fill-[var(--primary)]",
                i === pts.length - 1 && "fill-[var(--primary)] stroke-card stroke-2",
              )}
            />
          ))}
        </svg>
        <div className="mt-1 flex justify-between px-0.5">
          {LABELS.map((l) => (
            <span key={l} className="font-mono text-[10px] text-[var(--muted-foreground)]">
              {l}
            </span>
          ))}
        </div>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-3 gap-2 border-t border-[var(--border)] pt-3">
        {STATS.map((s) => (
          <div key={s.label} className="flex flex-col gap-0.5">
            <span className={cn("font-mono text-lg font-semibold tabular-nums", TONE_TEXT[s.tone])}>
              {s.value}
            </span>
            <span className="text-[10px] leading-snug text-[var(--muted-foreground)]">{s.label}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
