"use client";

import { cn } from "@/lib/utils";

// Illustrative backup chain widget for the backups feature page.
// Sample data only.

const BACKUPS = [
  { label: "Base", date: "Jun 15", size: "218 MB", type: "full", status: "ok" },
  { label: "+1", date: "Jun 16", size: "4.2 MB", type: "inc", status: "ok" },
  { label: "+2", date: "Jun 17", size: "6.8 MB", type: "inc", status: "ok" },
  { label: "+3", date: "Jun 18", size: "2.1 MB", type: "inc", status: "ok" },
  { label: "+4", date: "Jun 19", size: "8.4 MB", type: "inc", status: "ok" },
  { label: "+5", date: "Jun 20", size: "5.7 MB", type: "inc", status: "ok" },
  { label: "+6", date: "Jun 21", size: "3.2 MB", type: "inc", status: "running" },
] as const;

const FLEET = [
  { name: "shop.example.com", status: "protected", last: "2h ago" },
  { name: "blog.example.com", status: "protected", last: "1h ago" },
  { name: "staging.example.com", status: "stale", last: "3d ago" },
  { name: "client-a.example.com", status: "protected", last: "4h ago" },
] as const;

const STATUS_STYLE: Record<string, string> = {
  protected: "bg-[var(--success)]/12 text-[var(--success)]",
  stale: "bg-[var(--warning-subtle-fg)]/12 text-[var(--warning-subtle-fg)]",
  unprotected: "bg-destructive/10 text-destructive",
};

export function BackupVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Backup chain</span>
        <span className="rounded-md bg-[var(--primary-subtle)] px-2 py-0.5 font-mono text-xs font-medium text-[var(--primary-pressed)]">
          7 snapshots
        </span>
      </div>

      {/* Chain visualisation */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
        {BACKUPS.map((b, i) => (
          <div key={i} className="flex shrink-0 items-center gap-1.5">
            <div
              className={cn(
                "flex flex-col items-center gap-1 rounded-lg border p-2.5",
                b.type === "full"
                  ? "border-[var(--primary)]/40 bg-[var(--primary-subtle)]"
                  : "border-[var(--border)] bg-[var(--muted)]/50",
                b.status === "running" && "border-[var(--warning-subtle-fg)]/40 bg-[var(--warning-subtle-fg)]/10",
              )}
            >
              <span
                className={cn(
                  "font-mono text-[10px] font-semibold",
                  b.type === "full" ? "text-[var(--primary-pressed)]" : "text-[var(--muted-foreground)]",
                  b.status === "running" && "text-[var(--warning-subtle-fg)]",
                )}
              >
                {b.label}
              </span>
              <span className="text-[9px] text-[var(--muted-foreground)]">{b.date}</span>
              <span className="font-mono text-[9px] text-[var(--muted-foreground)]">{b.size}</span>
              {b.status === "running" && (
                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-[var(--warning-subtle-fg)]" />
              )}
            </div>
            {i < BACKUPS.length - 1 && (
              <span className="text-[var(--muted-foreground)]/50 text-xs">-</span>
            )}
          </div>
        ))}
      </div>

      {/* Fleet view */}
      <div className="flex flex-col gap-1.5 border-t border-[var(--border)] pt-3">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Fleet backup health</span>
        {FLEET.map((site) => (
          <div key={site.name} className="flex items-center justify-between gap-2">
            <span className="truncate font-mono text-xs text-foreground">{site.name}</span>
            <div className="flex items-center gap-2 shrink-0">
              <span className="text-[10px] text-[var(--muted-foreground)]">{site.last}</span>
              <span className={cn("rounded px-1.5 py-0.5 text-[10px] font-medium capitalize", STATUS_STYLE[site.status])}>
                {site.status}
              </span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
