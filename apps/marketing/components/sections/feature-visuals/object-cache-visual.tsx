"use client";

import { cn } from "@/lib/utils";
import { motion } from "motion/react";

// Illustrative Redis object cache dashboard for the object-cache feature page.
// Distinct from CacheTrendVisual (which shows full-page cache hit ratio).
// This widget shows hit ratio ring, memory, operations/sec, and per-site stats.
// Sample data only.

const HIT_RATIO = 94.2;
const CIRCUMFERENCE = 2 * Math.PI * 36;
const PROGRESS = (HIT_RATIO / 100) * CIRCUMFERENCE;

const SITES = [
  { name: "shop.acme.com", hits: "94.2%", mem: "48 MB", ops: "1,240/s", status: "ok" },
  { name: "blog.acme.com", hits: "88.7%", mem: "12 MB", ops: "320/s", status: "ok" },
  { name: "store.client.com", hits: "61.3%", mem: "6 MB", ops: "98/s", status: "warn" },
] as const;

const STATUS_DOT: Record<string, string> = {
  ok: "bg-[var(--success)]",
  warn: "bg-[var(--warning-subtle-fg)]",
};

export function ObjectCacheVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Redis object cache</span>
        <span className="rounded-md bg-[var(--success)]/12 px-2 py-0.5 font-mono text-xs font-medium text-[var(--success)]">
          Live
        </span>
      </div>

      {/* Hit ratio ring + stats */}
      <div className="flex items-center gap-6">
        {/* SVG ring */}
        <div className="relative shrink-0">
          <svg width="90" height="90" viewBox="0 0 90 90" aria-hidden>
            {/* Track */}
            <circle
              cx="45"
              cy="45"
              r="36"
              fill="none"
              strokeWidth="8"
              className="stroke-[var(--muted)]"
            />
            {/* Progress */}
            <motion.circle
              cx="45"
              cy="45"
              r="36"
              fill="none"
              strokeWidth="8"
              strokeLinecap="round"
              strokeDasharray={CIRCUMFERENCE}
              strokeDashoffset={CIRCUMFERENCE - PROGRESS}
              className="stroke-[var(--primary)]"
              style={{ rotate: "-90deg", transformOrigin: "45px 45px" }}
              initial={{ strokeDashoffset: CIRCUMFERENCE }}
              whileInView={{ strokeDashoffset: CIRCUMFERENCE - PROGRESS }}
              viewport={{ once: true, margin: "-60px" }}
              transition={{ duration: 1.0, ease: [0.22, 1, 0.36, 1] }}
            />
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-mono text-lg font-semibold text-foreground tabular-nums">
              {HIT_RATIO}%
            </span>
            <span className="text-[10px] text-[var(--muted-foreground)]">hit ratio</span>
          </div>
        </div>

        {/* Side stats */}
        <div className="flex flex-1 flex-col gap-3">
          {[
            { label: "Total memory", value: "66 MB", sub: "of 256 MB" },
            { label: "Ops / sec", value: "1,658", sub: "across 3 sites" },
            { label: "Keys evicted", value: "0", sub: "last 24h" },
          ].map((s) => (
            <div key={s.label} className="flex flex-col gap-0.5">
              <div className="flex items-baseline gap-1.5">
                <span className="font-mono text-base font-semibold text-foreground tabular-nums">
                  {s.value}
                </span>
                <span className="text-[10px] text-[var(--muted-foreground)]">{s.sub}</span>
              </div>
              <span className="text-[10px] text-[var(--muted-foreground)]">{s.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Per-site table */}
      <div className="flex flex-col gap-1.5 border-t border-[var(--border)] pt-3">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Per-site breakdown</span>
        {SITES.map((site) => (
          <div
            key={site.name}
            className="flex items-center gap-2 rounded-lg border border-[var(--border)]/60 bg-[var(--background)] px-3 py-2"
          >
            <span className={cn("h-1.5 w-1.5 shrink-0 rounded-full", STATUS_DOT[site.status])} />
            <span className="min-w-0 flex-1 truncate font-mono text-xs text-foreground">{site.name}</span>
            <span className="shrink-0 font-mono text-[10px] tabular-nums text-[var(--muted-foreground)]">{site.mem}</span>
            <span className="shrink-0 font-mono text-[10px] tabular-nums text-[var(--muted-foreground)]">{site.ops}</span>
            <span
              className={cn(
                "shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] font-medium tabular-nums",
                parseFloat(site.hits) >= 85
                  ? "bg-[var(--success)]/12 text-[var(--success)]"
                  : "bg-[var(--warning-subtle-fg)]/12 text-[var(--warning-subtle-fg)]",
              )}
            >
              {site.hits}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
