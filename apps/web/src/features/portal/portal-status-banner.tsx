// PortalStatusBanner — "all systems operating normally" / "N sites need
// attention" derived client-side from summary.sites[].status + generated_at.
//
// Status mapping (locked decision, contract §2.9):
//   connected  -> "operating normally"
//   anything else -> "needs attention"
//
// Banner uses success-subtle (green) for all-OK and warning-subtle (amber) when
// any site is non-connected. Brand tokens only, never raw palette classes.

import { CheckCircle2, AlertTriangle, Clock } from "lucide-react";
import { relativeTime } from "@/lib/utils";
import { cn } from "@/lib/utils";
import type { PortalSummarySite } from "./use-portal";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function isConnected(status: string): boolean {
  return status === "connected";
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface PortalStatusBannerProps {
  sites: PortalSummarySite[];
  generatedAt: string;
}

export function PortalStatusBanner({
  sites,
  generatedAt,
}: PortalStatusBannerProps) {
  const total = sites.length;
  const attentionSites = sites.filter((s) => !isConnected(s.status));
  const attentionCount = attentionSites.length;
  const allOk = attentionCount === 0;
  const checkedLabel = relativeTime(generatedAt) ?? "just now";

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        "mb-6 flex items-start gap-3 rounded-lg px-4 py-3 text-sm",
        allOk
          ? "bg-[var(--color-success-subtle)] text-[var(--color-success-subtle-fg)]"
          : "bg-[var(--color-warning-subtle)] text-[var(--color-warning-subtle-fg)]",
      )}
    >
      {allOk ? (
        <CheckCircle2
          aria-hidden="true"
          className="mt-px size-4 shrink-0"
        />
      ) : (
        <AlertTriangle
          aria-hidden="true"
          className="mt-px size-4 shrink-0"
        />
      )}

      <div className="min-w-0 flex-1">
        {allOk ? (
          <span className="font-medium">
            All {total} {total === 1 ? "site" : "sites"} operating normally.
          </span>
        ) : (
          <span className="font-medium">
            {attentionCount}{" "}
            {attentionCount === 1 ? "site needs" : "sites need"} attention
            {attentionCount <= 2 ? (
              <>
                {" "}
                (
                {attentionSites
                  .map((s) => s.name)
                  .join(", ")}
                )
              </>
            ) : null}{" "}
            — we&apos;re on it.
          </span>
        )}
      </div>

      <span className="flex shrink-0 items-center gap-1 text-xs opacity-70">
        <Clock aria-hidden="true" className="size-3" />
        Checked {checkedLabel}
      </span>
    </div>
  );
}
