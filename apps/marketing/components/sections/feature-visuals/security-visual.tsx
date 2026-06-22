"use client";

import { cn } from "@/lib/utils";

// Illustrative security dashboard widget for the security feature page.
// Sample data only.

const HARDENING_ITEMS = [
  { label: "File editor disabled", state: "on" },
  { label: "XML-RPC restricted", state: "on" },
  { label: "PHP in uploads blocked", state: "on" },
  { label: "SSL / HSTS enforced", state: "on" },
  { label: "REST API public access", state: "off" },
] as const;

const VULN_ITEMS = [
  { name: "my-seo-plugin", severity: "medium", fixed: "2.4.1" },
  { name: "wp-core", severity: "low", fixed: "6.5.4" },
] as const;

const SEVERITY_STYLE: Record<string, { dot: string; text: string; bg: string }> = {
  critical: { dot: "bg-destructive", text: "text-destructive", bg: "bg-destructive/10" },
  high: { dot: "bg-destructive", text: "text-destructive", bg: "bg-destructive/10" },
  medium: { dot: "bg-[var(--warning-subtle-fg)]", text: "text-[var(--warning-subtle-fg)]", bg: "bg-[var(--warning-subtle-fg)]/12" },
  low: { dot: "bg-[var(--info-subtle-fg,var(--primary))]", text: "text-[var(--primary)]", bg: "bg-[var(--primary-subtle)]" },
};

export function SecurityVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Security overview</span>
        <span className="rounded-md bg-[var(--success)]/12 px-2 py-0.5 text-xs font-medium text-[var(--success)]">
          2 findings
        </span>
      </div>

      {/* Hardening */}
      <div className="flex flex-col gap-2">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Hardening controls</span>
        <div className="flex flex-col gap-1.5">
          {HARDENING_ITEMS.map((item) => (
            <div key={item.label} className="flex items-center justify-between gap-2">
              <span className="text-xs text-foreground">{item.label}</span>
              <span
                className={cn(
                  "rounded px-1.5 py-0.5 font-mono text-[10px] font-medium",
                  item.state === "on"
                    ? "bg-[var(--success)]/12 text-[var(--success)]"
                    : "bg-[var(--muted)] text-[var(--muted-foreground)]",
                )}
              >
                {item.state === "on" ? "ON" : "OFF"}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Vulnerability findings */}
      <div className="flex flex-col gap-2 border-t border-[var(--border)] pt-3">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Vulnerability findings</span>
        <div className="flex flex-col gap-2">
          {VULN_ITEMS.map((v) => {
            const s = SEVERITY_STYLE[v.severity] ?? SEVERITY_STYLE.low!;
            return (
              <div
                key={v.name}
                className={cn("flex items-center justify-between gap-2 rounded-lg p-2.5", s.bg)}
              >
                <div className="flex items-center gap-2">
                  <span className={cn("h-2 w-2 rounded-full", s.dot)} />
                  <span className="font-mono text-xs font-medium text-foreground">{v.name}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className={cn("text-xs font-medium capitalize", s.text)}>{v.severity}</span>
                  <span className="rounded bg-card px-1.5 py-0.5 font-mono text-[10px] text-[var(--muted-foreground)]">
                    Fix: {v.fixed}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* File integrity summary */}
      <div className="flex items-center justify-between rounded-lg border border-[var(--border)] bg-[var(--muted)]/40 p-3">
        <span className="text-xs text-foreground">File integrity scan</span>
        <div className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-[var(--success)]" />
          <span className="text-xs font-medium text-[var(--success)]">No changes detected</span>
        </div>
      </div>
    </div>
  );
}
