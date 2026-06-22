"use client";

import { cn } from "@/lib/utils";

// Illustrative fleet update dashboard widget for the updates feature page.
// Sample data only.

const UPDATES = [
  { plugin: "woocommerce", from: "8.6.1", to: "8.8.0", sites: 14, status: "queued" },
  { plugin: "elementor", from: "3.21.4", to: "3.22.2", sites: 9, status: "running" },
  { plugin: "yoast-seo", from: "22.4", to: "22.7", sites: 22, status: "done" },
  { plugin: "contact-form-7", from: "5.9.1", to: "5.9.5", sites: 7, status: "done" },
  { plugin: "wp-core", from: "6.5.2", to: "6.5.4", sites: 31, status: "queued" },
] as const;

const STATUS: Record<string, { label: string; class: string }> = {
  queued: { label: "Queued", class: "bg-[var(--muted)] text-[var(--muted-foreground)]" },
  running: { label: "Updating", class: "bg-[var(--warning-subtle-fg)]/12 text-[var(--warning-subtle-fg)]" },
  done: { label: "Updated", class: "bg-[var(--success)]/12 text-[var(--success)]" },
  reverted: { label: "Reverted", class: "bg-destructive/10 text-destructive" },
};

const SUMMARY = [
  { label: "Updated", value: "36", tone: "success" },
  { label: "Running", value: "9", tone: "warn" },
  { label: "Queued", value: "45", tone: "muted" },
] as const;

const SUMMARY_TEXT: Record<string, string> = {
  success: "text-[var(--success)]",
  warn: "text-[var(--warning-subtle-fg)]",
  muted: "text-[var(--muted-foreground)]",
};

export function UpdatesVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Fleet update run</span>
        <div className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-[var(--warning-subtle-fg)] animate-pulse" />
          <span className="text-xs font-medium text-[var(--warning-subtle-fg)]">In progress</span>
        </div>
      </div>

      {/* Progress summary */}
      <div className="grid grid-cols-3 gap-2 rounded-lg bg-[var(--muted)]/50 p-3">
        {SUMMARY.map((s) => (
          <div key={s.label} className="flex flex-col items-center gap-0.5">
            <span
              className={cn("font-mono text-xl font-semibold tabular-nums", SUMMARY_TEXT[s.tone])}
            >
              {s.value}
            </span>
            <span className="text-[10px] text-[var(--muted-foreground)]">{s.label}</span>
          </div>
        ))}
      </div>

      {/* Per-plugin rows */}
      <div className="flex flex-col gap-1.5">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Plugin updates</span>
        {UPDATES.map((u) => {
          const s = STATUS[u.status];
          return (
            <div
              key={u.plugin}
              className="flex items-center justify-between gap-2 rounded-lg border border-[var(--border)]/60 bg-[var(--background)] px-3 py-2"
            >
              <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate font-mono text-xs font-medium text-foreground">
                  {u.plugin}
                </span>
                <span className="font-mono text-[10px] text-[var(--muted-foreground)]">
                  {u.from} <span className="text-[var(--primary)]">to</span> {u.to}
                </span>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <span className="text-[10px] text-[var(--muted-foreground)]">{u.sites} sites</span>
                {s && (
                  <span className={cn("rounded px-1.5 py-0.5 text-[10px] font-medium", s.class)}>
                    {s.label}
                  </span>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Auto-revert note */}
      <div className="flex items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--primary-subtle)] px-3 py-2">
        <span className="text-[10px] leading-relaxed text-[var(--primary-pressed)]">
          Snapshot taken before each update. Auto-reverts on failed health check.
        </span>
      </div>
    </div>
  );
}
