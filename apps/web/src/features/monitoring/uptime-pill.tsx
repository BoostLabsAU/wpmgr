// uptime-pill.tsx — a compact inline pill showing a site's current reachability.
//
// Used next to the ConnectionStateBadge in the site-detail header and (optionally)
// the sites-table row. It answers a different question than the connection badge:
//
//   ConnectionStateBadge → "Is the WPMgr agent talking to the CP?"
//   UptimePill           → "Is the site itself reachable from the outside?"
//
// The uptime monitor probes the public URL independently of the agent heartbeat,
// so a site can be "Degraded" (agent quiet) yet "Up" (site serving HTTP 200s).
// Showing both lets operators distinguish "agent missed a heartbeat" from
// "site is genuinely down" at a glance.
//
// Data source: `useUptimeSummary()` (features/monitoring/use-uptime.ts) — a
// tenant-wide list refetched on 60s cadence. We index it by `site_id`. The
// component renders nothing when no summary item exists for the site (the site
// has no uptime monitor configured), so it is safe to add to any surface without
// needing a per-site guard up the tree.

import { useUptimeSummary } from "@/features/monitoring/use-uptime";
import { statusFromItem, type UpDown } from "@/features/monitoring/uptime-badges-helpers";
import { cn } from "@/lib/utils";

export interface UptimePillProps {
  siteId: string;
  className?: string;
}

const PILL_STYLES: Record<UpDown, string> = {
  up: [
    "bg-[var(--color-success-subtle,oklch(95%_0.05_145))]",
    "text-[var(--color-success,oklch(50%_0.15_145))]",
  ].join(" "),
  down: [
    "bg-[var(--color-destructive-subtle,oklch(95%_0.05_25))]",
    "text-[var(--color-destructive,oklch(50%_0.18_25))]",
  ].join(" "),
  unknown: [
    "bg-[var(--color-muted)]",
    "text-[var(--color-muted-foreground)]",
  ].join(" "),
};

const PILL_LABELS: Record<UpDown, string> = {
  up: "Up",
  down: "Down",
  unknown: "Unknown",
};

/**
 * Compact reachability pill sourced from the uptime monitor summary.
 * Renders nothing when the site has no monitor entry (no uptime probe
 * configured). Never throws — a query error is silently suppressed so a
 * monitoring outage doesn't break the site-detail header.
 */
export function UptimePill({ siteId, className }: UptimePillProps) {
  const { data: summary } = useUptimeSummary();

  // If the query is loading or errored, render nothing — the pill is
  // supplemental context, not a critical path element.
  if (!summary) return null;

  const item = summary.items.find((i) => i.site_id === siteId);
  // No monitor configured for this site — render nothing rather than "Unknown"
  // so operators don't infer "we checked and don't know" when the reality is
  // "no probe is set up".
  if (!item) return null;

  const status = statusFromItem(item);

  return (
    <span
      aria-label={`Site reachability: ${PILL_LABELS[status]}`}
      title={`HTTP monitor: ${PILL_LABELS[status]}`}
      className={cn(
        "inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none",
        PILL_STYLES[status],
        className,
      )}
    >
      {/* Decorative dot matches the color-coded tone */}
      <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full bg-current" />
      <span aria-hidden="true">{PILL_LABELS[status]}</span>
    </span>
  );
}
