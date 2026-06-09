import { useEffect } from "react";
import { CheckCircle2, Loader2, XCircle } from "lucide-react";

import { selectFonts, useFontsStore } from "../fonts-store";

// Small inline live-phase indicator for the font-processing pipeline. Driven by
// the fonts-store (updated by usePerfEvents via font.* SSE frames). Self-hides
// when idle. Shows aggregate progress while converting and a savings summary
// when done. Auto-clears "done" after 8 seconds.

const AUTO_CLEAR_DONE_MS = 8_000;
// Stale backstop: if no SSE frame arrives for 120s while queued/converting,
// auto-reset to guard against a dropped font.completed / font.failed frame.
const STALE_TIMEOUT_MS = 120_000;

export interface FontsLiveIndicatorProps {
  siteId: string;
}

export function FontsLiveIndicator({ siteId }: FontsLiveIndicatorProps) {
  const live = useFontsStore((s) => selectFonts(s, siteId));
  const reset = useFontsStore((s) => s.reset);

  // Auto-clear "done" after a short pause so the indicator fades out.
  useEffect(() => {
    if (live.phase !== "done") return;
    const id = window.setTimeout(() => reset(siteId), AUTO_CLEAR_DONE_MS);
    return () => window.clearTimeout(id);
  }, [siteId, live.phase, reset]);

  // Stale backstop for active phases.
  useEffect(() => {
    if (live.phase !== "queued" && live.phase !== "converting") return;
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
      {live.phase === "converting" && (
        <>
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin text-blue-500 dark:text-blue-400" />
          <span className="text-foreground">
            {live.total > 0
              ? `Converting fonts (${live.processed}/${live.total})…`
              : "Converting fonts…"}
          </span>
        </>
      )}
      {live.phase === "done" && (
        <>
          <CheckCircle2 aria-hidden="true" className="size-3.5 text-green-600 dark:text-green-400" />
          <span className="text-foreground">
            {live.savings_pct !== null && live.total > 0
              ? `Converted ${live.total} font${live.total === 1 ? "" : "s"}, saved ${live.savings_pct.toFixed(0)}%`
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
