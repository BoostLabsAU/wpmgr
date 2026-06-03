import { useEffect } from "react";
import { CheckCircle2, Loader2, XCircle } from "lucide-react";

import { selectRucss, useRucssStore } from "../rucss-store";

// Small inline live-phase indicator for the RUCSS operator trigger. Driven by
// the rucss-store (updated by usePerfEvents ← SSE frames). Self-hides when
// idle. The "done" state shows the reduction percentage from the completed event
// and auto-clears after 8 seconds.

const AUTO_CLEAR_DONE_MS = 8_000;
// Stale-timeout: if no SSE frame arrives for 120s while queued/computing, auto-
// reset (guards against a dropped rucss.completed / rucss.failed frame).
const STALE_TIMEOUT_MS = 120_000;

export interface RucssLiveIndicatorProps {
  siteId: string;
}

export function RucssLiveIndicator({ siteId }: RucssLiveIndicatorProps) {
  const live = useRucssStore((s) => selectRucss(s, siteId));
  const reset = useRucssStore((s) => s.reset);

  // Auto-clear "done" after a short pause.
  useEffect(() => {
    if (live.phase !== "done") return;
    const id = window.setTimeout(() => reset(siteId), AUTO_CLEAR_DONE_MS);
    return () => window.clearTimeout(id);
  }, [siteId, live.phase, reset]);

  // Stale backstop for active phases.
  useEffect(() => {
    if (live.phase !== "queued" && live.phase !== "computing") return;
    const id = window.setTimeout(() => reset(siteId), STALE_TIMEOUT_MS);
    return () => window.clearTimeout(id);
  }, [siteId, live.phase, live.updatedAt, reset]);

  if (live.phase === null) return null;

  return (
    <span
      role="status"
      aria-live="polite"
      className="inline-flex items-center gap-1.5 text-xs"
    >
      {live.phase === "queued" && (
        <>
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin text-muted-foreground" />
          <span className="text-muted-foreground">Queued…</span>
        </>
      )}
      {live.phase === "computing" && (
        <>
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin text-blue-500 dark:text-blue-400" />
          <span className="text-foreground">Computing…</span>
        </>
      )}
      {live.phase === "done" && (
        <>
          <CheckCircle2 aria-hidden="true" className="size-3.5 text-green-600 dark:text-green-400" />
          <span className="text-foreground">
            {live.reduction_pct !== null
              ? `Reduced ${live.reduction_pct.toFixed(0)}%`
              : "Done"}
          </span>
        </>
      )}
      {live.phase === "failed" && (
        <>
          <XCircle aria-hidden="true" className="size-3.5 text-red-600 dark:text-red-400" />
          <span className="text-red-700 dark:text-red-400">Failed</span>
        </>
      )}
    </span>
  );
}
