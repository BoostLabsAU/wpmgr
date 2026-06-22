"use client";

import { cn } from "@/lib/utils";

// Illustrative fleet status matrix widget for the uptime-monitoring feature page.
// Sample data only.

const SITES = [
  { name: "shop.acme.com", status: "up", latency: "142ms", ssl: "89d" },
  { name: "blog.acme.com", status: "up", latency: "98ms", ssl: "45d" },
  { name: "staging.client-b.com", status: "degraded", latency: "1.2s", ssl: "12d" },
  { name: "store.client-c.com", status: "up", latency: "204ms", ssl: "67d" },
  { name: "old.client-d.com", status: "down", latency: "--", ssl: "expired" },
] as const;

const STATUS_STYLE: Record<string, { dot: string; text: string; label: string }> = {
  up: { dot: "bg-[var(--success)]", text: "text-[var(--success)]", label: "Up" },
  degraded: { dot: "bg-[var(--warning-subtle-fg)] animate-pulse", text: "text-[var(--warning-subtle-fg)]", label: "Degraded" },
  down: { dot: "bg-destructive animate-pulse", text: "text-destructive", label: "Down" },
};

// Mini sparkline per site (rough static bars)
const BARS: Record<string, number[]> = {
  "shop.acme.com": [95, 98, 97, 100, 99, 100, 100],
  "blog.acme.com": [100, 100, 98, 100, 100, 100, 100],
  "staging.client-b.com": [100, 92, 85, 88, 78, 82, 79],
  "store.client-c.com": [100, 100, 100, 99, 100, 100, 100],
  "old.client-d.com": [100, 95, 80, 40, 0, 0, 0],
};

const BAR_COLOR: Record<string, string> = {
  up: "bg-[var(--success)]",
  degraded: "bg-[var(--warning-subtle-fg)]",
  down: "bg-destructive",
};

export function UptimeVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Fleet status matrix</span>
        <div className="flex items-center gap-3 text-[10px] text-[var(--muted-foreground)]">
          <span className="flex items-center gap-1">
            <span className="h-1.5 w-1.5 rounded-full bg-[var(--success)]" />Up
          </span>
          <span className="flex items-center gap-1">
            <span className="h-1.5 w-1.5 rounded-full bg-[var(--warning-subtle-fg)]" />Degraded
          </span>
          <span className="flex items-center gap-1">
            <span className="h-1.5 w-1.5 rounded-full bg-destructive" />Down
          </span>
        </div>
      </div>

      {/* Site rows */}
      <div className="flex flex-col gap-1.5">
        {SITES.map((site) => {
          const s = STATUS_STYLE[site.status];
          const bars = BARS[site.name] ?? [];
          return (
            <div
              key={site.name}
              className="flex items-center gap-3 rounded-lg border border-[var(--border)]/50 bg-[var(--background)] px-3 py-2.5"
            >
              {/* Status dot */}
              {s && <span className={cn("h-2 w-2 shrink-0 rounded-full", s.dot)} />}

              {/* Name */}
              <span className="min-w-0 flex-1 truncate font-mono text-xs text-foreground">
                {site.name}
              </span>

              {/* Mini bar chart */}
              <div className="flex shrink-0 items-end gap-0.5" aria-hidden>
                {bars.map((v, i) => (
                  <div
                    key={i}
                    className={cn(
                      "w-1 rounded-sm",
                      site.status === "up" ? BAR_COLOR.up :
                        i >= 4 && site.status === "down" ? BAR_COLOR.down :
                          BAR_COLOR.degraded,
                    )}
                    style={{ height: `${Math.max(4, (v / 100) * 20)}px` }}
                  />
                ))}
              </div>

              {/* Latency */}
              <span className="shrink-0 font-mono text-[10px] text-[var(--muted-foreground)]">
                {site.latency}
              </span>

              {/* SSL */}
              <span
                className={cn(
                  "shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] font-medium",
                  site.ssl === "expired"
                    ? "bg-destructive/10 text-destructive"
                    : parseInt(site.ssl) < 30
                      ? "bg-[var(--warning-subtle-fg)]/12 text-[var(--warning-subtle-fg)]"
                      : "bg-[var(--muted)] text-[var(--muted-foreground)]",
                )}
              >
                {site.ssl === "expired" ? "SSL expired" : `SSL ${site.ssl}`}
              </span>
            </div>
          );
        })}
      </div>

      {/* Summary */}
      <div className="flex items-center justify-between border-t border-[var(--border)] pt-3 text-xs text-[var(--muted-foreground)]">
        <span>Last probed 23 seconds ago</span>
        <span className="font-mono">3 up · 1 degraded · 1 down</span>
      </div>
    </div>
  );
}
