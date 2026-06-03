import { useEffect } from "react";

import { Progress } from "@/components/ui/progress";

import { selectPreload, usePreloadStore } from "../preload-store";

// Live purge/preload progress bar driven by the cache.preload.*/cache.purge.*
// SSE frames (usePerfEvents → preload-store). Self-hides when idle. The purge
// phase has no measurable percent → indeterminate shimmer; the preload phase
// shows done/total when known.
//
// STALE BACKSTOP: if the phase is active and no SSE frame arrives for 90s the
// bar is force-finished (a dropped final cache.preload.completed frame must not
// spin forever). The timer resets on every `updatedAt` change. `finish` is a
// stable Zustand action reference — safe to include in the dep array.

const STALE_TIMEOUT_MS = 90_000;

export interface PreloadProgressProps {
  siteId: string;
}

export function PreloadProgress({ siteId }: PreloadProgressProps) {
  const live = usePreloadStore((s) => selectPreload(s, siteId));
  // `finish` is the stable Zustand action — its identity never changes between
  // renders, so including it in the effect dep array is safe and correct.
  const finish = usePreloadStore((s) => s.finish);

  // Stale-timeout backstop: reset whenever the phase becomes active or a frame
  // arrives (updatedAt changes). Clear when the phase goes idle.
  useEffect(() => {
    if (live.phase === null) return;
    const id = window.setTimeout(() => {
      finish(siteId);
    }, STALE_TIMEOUT_MS);
    return () => window.clearTimeout(id);
  }, [siteId, live.phase, live.updatedAt, finish]);

  if (live.phase === null) return null;

  const isPurge = live.phase === "purging";
  const hasTotal = !isPurge && live.total > 0;
  const pct = hasTotal
    ? Math.min(100, Math.round((live.done / live.total) * 100))
    : null;

  const title = isPurge ? "Purging cache…" : "Preloading cache…";
  const detail = hasTotal
    ? `${live.done.toLocaleString()} of ${live.total.toLocaleString()} pages`
    : isPurge
      ? "Clearing cached pages on the server"
      : "Warming up cached pages";

  return (
    <div
      role="status"
      aria-live="polite"
      className="space-y-2 rounded-xl border border-border bg-card p-4"
    >
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="font-medium text-foreground">{title}</span>
        {pct !== null ? (
          <span className="tabular-nums text-muted-foreground">{pct}%</span>
        ) : null}
      </div>
      <Progress value={pct} label={title} />
      <p className="text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}
