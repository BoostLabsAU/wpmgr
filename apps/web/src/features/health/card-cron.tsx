import { useState } from "react";
import { X } from "lucide-react";
import type { SiteDiagnosticsCard } from "@wpmgr/api";

import { CopyableMono } from "@/components/shared/copyable-mono";
import { DefinitionList } from "@/components/shared/definition-list";
import { cn } from "@/lib/utils";

import { DiagnosticCard } from "./diagnostic-card";
import { pickBool, pickNumber } from "./diagnostic-pick";

// Cron card — DISABLE_WP_CRON state, registered event count, and the leapfrog
// `overdue_max_seconds` field (the longest-overdue WP-Cron event). A growing
// overdue_max is the canonical "wp-cron is silently broken" signal.
//
// Starvation callout: shown when DISABLE_WP_CRON is true and overdue_max_seconds
// is above the 600s threshold, OR when DISABLE_WP_CRON is true with no recent
// runs (overdue_max > 0 acts as that proxy). The callout is dismissible and the
// dismiss state is persisted to localStorage keyed by siteId so it does not
// reappear across sessions for the same site.

const STARVATION_THRESHOLD_S = 600;
const DISMISS_KEY_PREFIX = "wpmgr.cron.starvation.dismissed";

function dismissKey(siteId: string): string {
  return `${DISMISS_KEY_PREFIX}.${siteId}`;
}

function isDismissed(siteId: string): boolean {
  try {
    return localStorage.getItem(dismissKey(siteId)) === "1";
  } catch {
    return false;
  }
}

function persist(siteId: string): void {
  try {
    localStorage.setItem(dismissKey(siteId), "1");
  } catch {
    // localStorage blocked (e.g. private mode with storage blocked) — ignore;
    // the callout will simply reappear next load, which is acceptable.
  }
}

/**
 * Whether to surface the cron-starvation guidance callout.
 *
 * Gating condition (OR):
 *   - DISABLE_WP_CRON is true AND overdue_max_seconds > STARVATION_THRESHOLD_S
 *   - DISABLE_WP_CRON is true AND overdue_max_seconds > 0 (any overdue event)
 *
 * Both branches collapse to: disabled === true && overdue > 0 (since any
 * positive overdue satisfies the second branch, and the first is a subset).
 * We gate on the 600s threshold to keep the signal meaningful: a site that
 * just missed one short-interval event is not "starved".
 */
function isStarved(disabled: boolean, overdue: number): boolean {
  return disabled && overdue > STARVATION_THRESHOLD_S;
}

export interface CardCronProps {
  card: SiteDiagnosticsCard | undefined;
  /** Site URL — substituted into the example cron command. */
  siteUrl?: string;
  /** Site ID — used to key the per-site localStorage dismiss flag. */
  siteId?: string;
}

export function CardCron({ card, siteUrl, siteId }: CardCronProps) {
  const payload = card?.payload as Record<string, unknown> | null | undefined;
  const overdue = pickNumber(payload, "overdue_max_seconds");
  const disabled = pickBool(payload, "disabled");

  const showCallout = isStarved(disabled, overdue);
  const [dismissed, setDismissed] = useState<boolean>(
    () => (siteId ? isDismissed(siteId) : false),
  );

  const handleDismiss = () => {
    setDismissed(true);
    if (siteId) persist(siteId);
  };

  return (
    <DiagnosticCard title="Cron" card={card}>
      <DefinitionList
        rows={[
          {
            label: "WP-Cron disabled",
            value: pickBool(payload, "disabled") ? "Yes" : "No",
          },
          {
            label: "Alternate WP-Cron",
            value: pickBool(payload, "alternate") ? "Yes" : "No",
          },
          {
            label: "Event count",
            value: pickNumber(payload, "event_count"),
            tabular: true,
          },
          {
            label: "Longest overdue",
            value: overdue > 0 ? formatDuration(overdue) : "No overdue events",
            mono: overdue > 0,
          },
        ]}
      />
      {showCallout && !dismissed ? (
        <CronStarvationCallout
          siteUrl={siteUrl}
          onDismiss={handleDismiss}
        />
      ) : null}
    </DiagnosticCard>
  );
}

// ---------------------------------------------------------------------------
// Starvation guidance callout
// ---------------------------------------------------------------------------

function CronStarvationCallout({
  siteUrl,
  onDismiss,
}: {
  siteUrl?: string;
  onDismiss: () => void;
}) {
  const exampleUrl = siteUrl ?? "https://example.com";
  // Ensure no trailing slash so the path reads cleanly.
  const cronUrl = exampleUrl.replace(/\/$/, "") + "/wp-cron.php";

  const configLine = "define('DISABLE_WP_CRON', true);";
  const cronLine = `* * * * * curl -s ${cronUrl} >/dev/null`;

  return (
    <div
      role="note"
      className={cn(
        "relative rounded-md border border-border bg-muted/50 px-3 py-2.5 text-xs",
      )}
    >
      <button
        type="button"
        aria-label="Dismiss cron guidance"
        onClick={onDismiss}
        className="absolute right-2 top-2 inline-flex size-5 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
      >
        <X aria-hidden="true" className="size-3" />
      </button>

      <p className="pr-6 font-medium text-foreground">
        Keep schedules running on cached sites
      </p>
      <p className="mt-1 pr-6 text-muted-foreground">
        WordPress runs scheduled tasks only when a page actually executes PHP.
        On a heavily cached or low-traffic site schedules can stall. A real
        server cron job calling wp-cron.php every minute keeps schedules
        reliable regardless of traffic. Set{" "}
        <code className="font-mono">DISABLE_WP_CRON</code> in wp-config.php
        and add a system cron entry:
      </p>

      <div className="mt-2 flex flex-col gap-1">
        <CopyableMono
          value={configLine}
          label="Copy wp-config.php constant"
          className="text-[11px]"
        />
        <CopyableMono
          value={cronLine}
          label="Copy cron entry"
          className="text-[11px]"
        />
      </div>
    </div>
  );
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
  return `${Math.floor(seconds / 86400)}d`;
}
