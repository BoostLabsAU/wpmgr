import { useCallback } from "react";
import { useQueryClient, type QueryClient } from "@tanstack/react-query";

import { toast } from "@/components/toast";
import {
  useSiteEvents,
  type SiteEvent,
} from "@/features/sites/use-site-events";

import { perfKeys } from "../perf-keys";
import { usePreloadStore } from "../preload-store";
import { useRucssStore, type RucssPhase } from "../rucss-store";

// usePerfEvents — projects the shared `/sites/events` SSE stream onto the perf
// caches + the live preload/purge progress store. The cache.*/rucss.*/db.*/
// perf.* types were added to SITE_EVENT_TYPES in use-site-events.ts (without
// that registration these frames are Zod-dropped before they reach here).
//
// Reducer contract (the testable part lives in `perfEventReducer`):
//   • perf.config.updated / cache.enabled / cache.disabled
//       → invalidate the config query (re-read the agent-acked install state).
//   • cache.stats.updated / cache.purge.completed / db.clean.completed
//       → invalidate the stats query (authoritative gauges).
//   • cache.preload.started   → store.start(preloading, total)
//   • cache.preload.progress  → store.progress(done, total) + drive the bar
//   • cache.preload.completed → store.finish + invalidate stats
//   • cache.purge.started     → store.start(purging) ; .completed → finish
//   • rucss.queued → rucss-store.setPhase(queued)
//   • rucss.computing → rucss-store.setPhase(computing)
//   • rucss.completed → rucss-store.setPhase(done, reduction_pct) + invalidate
//   • rucss.failed → rucss-store.setPhase(failed) + toast.error + invalidate
//
// Every frame is filtered by site_id first; events for other sites are ignored.

function asRecord(data: unknown): Record<string, unknown> {
  return typeof data === "object" && data !== null
    ? (data as Record<string, unknown>)
    : {};
}
function num(v: unknown): number | undefined {
  return typeof v === "number" ? v : undefined;
}

/** The slice of the preload store the reducer drives (kept narrow for tests). */
export interface PreloadActions {
  start: (siteId: string, phase: "purging" | "preloading", total: number) => void;
  progress: (siteId: string, done: number, total: number) => void;
  finish: (siteId: string) => void;
}

/** The slice of the RUCSS store the reducer drives. */
export interface RucssActions {
  setPhase: (siteId: string, phase: RucssPhase, extra?: { reduction_pct?: number }) => void;
  reset: (siteId: string) => void;
}

export interface PerfEventDeps {
  siteId: string;
  queryClient: Pick<QueryClient, "invalidateQueries">;
  preload: PreloadActions;
  rucss: RucssActions;
}

/**
 * Project one perf SSE event onto the caches + preload store. Exported so the
 * routing logic can be unit-tested without a React tree (mirrors
 * mediaEventReducer in useMediaEvents.ts).
 */
export function perfEventReducer(ev: SiteEvent, deps: PerfEventDeps): void {
  const { siteId, queryClient, preload, rucss } = deps;
  const data = asRecord(ev.data);

  switch (ev.type) {
    // ── config / enable-disable: re-read config (carries install-state ack) ──
    case "perf.config.updated":
    case "cache.enabled":
    case "cache.disabled":
      void queryClient.invalidateQueries({ queryKey: perfKeys.config(siteId) });
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;

    // ── stats: re-read the authoritative gauges ──────────────────────────────
    case "cache.stats.updated":
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;

    // ── purge lifecycle ──────────────────────────────────────────────────────
    case "cache.purge.started":
      preload.start(siteId, "purging", 0);
      break;
    case "cache.purge.completed":
      preload.finish(siteId);
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;

    // ── preload lifecycle ────────────────────────────────────────────────────
    case "cache.preload.started":
      preload.start(siteId, "preloading", num(data.total) ?? 0);
      break;
    case "cache.preload.progress":
      preload.progress(siteId, num(data.done) ?? 0, num(data.total) ?? 0);
      break;
    case "cache.preload.completed":
      preload.finish(siteId);
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;

    // ── database cleanup ─────────────────────────────────────────────────────
    case "db.clean.completed":
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;

    // ── RUCSS live phase ─────────────────────────────────────────────────────
    case "rucss.queued":
      rucss.setPhase(siteId, "queued");
      break;
    case "rucss.computing":
      rucss.setPhase(siteId, "computing");
      break;
    case "rucss.completed": {
      const reductionPct = num(data.reduction_pct);
      rucss.setPhase(siteId, "done", {
        reduction_pct: reductionPct,
      });
      void queryClient.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
      break;
    }
    case "rucss.failed":
      rucss.setPhase(siteId, "failed");
      toast.error("Remove Unused CSS computation failed.", {
        description:
          typeof data.detail === "string" ? data.detail : undefined,
      });
      void queryClient.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
      break;

    default:
      break;
  }
}

/** True for the perf event prefixes this reducer handles. */
function isPerfEvent(type: string): boolean {
  return (
    type.startsWith("cache.") ||
    type.startsWith("rucss.") ||
    type.startsWith("db.") ||
    type.startsWith("perf.")
  );
}

/**
 * Subscribe the perf caches + preload store to the shared SSE stream for one
 * site. Call once from CacheTab / OptimizeTab.
 */
export function usePerfEvents(siteId: string): void {
  const qc = useQueryClient();
  const start = usePreloadStore((s) => s.start);
  const progress = usePreloadStore((s) => s.progress);
  const finish = usePreloadStore((s) => s.finish);
  const rucssSetPhase = useRucssStore((s) => s.setPhase);
  const rucssReset = useRucssStore((s) => s.reset);

  const handler = useCallback(
    (ev: SiteEvent) => {
      if (ev.site_id !== siteId) return;
      if (!isPerfEvent(ev.type)) return;
      perfEventReducer(ev, {
        siteId,
        queryClient: qc,
        preload: { start, progress, finish },
        rucss: { setPhase: rucssSetPhase, reset: rucssReset },
      });
    },
    [siteId, qc, start, progress, finish, rucssSetPhase, rucssReset],
  );

  useSiteEvents(handler);
}
