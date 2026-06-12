import { useCallback } from "react";
import { useQueryClient, type QueryClient } from "@tanstack/react-query";

import { toast } from "@/components/toast";
import {
  useSiteEvents,
  useSiteReconnect,
  type SiteEvent,
} from "@/features/sites/use-site-events";
import { type OrphanItemKind } from "./useOrphanDelete";

import { perfKeys } from "../perf-keys";
import { usePreloadStore } from "../preload-store";
import { useRucssStore, type RucssPhase } from "../rucss-store";
import { useDbCleanStore, type CategoryState } from "../db-clean-store";
import {
  useDbScanStore,
  type DbScanCategoryResult,
  type DbScanTableInventoryRow,
} from "../stores/dbScanStore";
import { useFontsStore, type FontRowPhase } from "../fonts-store";

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

/** The slice of the DB-clean store the reducer drives. */
export interface DbCleanActions {
  startJob: (siteId: string, job_id: string, tasks: string[], trigger: "manual" | "scheduled") => void;
  progressCategory: (
    siteId: string,
    job_id: string,
    category: string,
    rows_deleted: number,
    bytes_freed: number,
    state: CategoryState,
    detail?: string,
  ) => void;
  completeJob: (
    siteId: string,
    job_id: string,
    rows_deleted: number,
    bytes_freed: number,
    categories: Record<string, { rows_deleted: number; bytes_freed: number; state: string }>,
  ) => void;
  failJob: (siteId: string, job_id: string, detail: string) => void;
}

/** The slice of the DB-scan store the reducer drives. */
export interface DbScanActions {
  startScan: (siteId: string, job_id: string, categories: string[]) => void;
  completeScan: (
    siteId: string,
    job_id: string,
    categories: Record<string, DbScanCategoryResult>,
    db_size_bytes: number,
    table_count: number,
    scanned_at: number,
    tables: DbScanTableInventoryRow[],
  ) => void;
  failScan: (siteId: string, job_id: string, detail: string) => void;
}

/** Per-item result from the agent progress push (orphan delete). */
export interface OrphanDeleteItemResult {
  kind: OrphanItemKind;
  name: string;
  /** "done" | "skipped" | "error" | "not_found" */
  status: string;
  detail: string;
}

/** Cumulative progress counters carried in each progress/completed push. */
export interface OrphanDeleteProgress {
  job_id: string;
  deleted_options: number;
  deleted_cron: number;
  deleted_tables: number;
  skipped: number;
}

/** The slice of the orphan-delete lifecycle the reducer drives. */
export interface DbOrphanDeleteActions {
  /**
   * Called when `db.orphan.delete.started` arrives — the CP has dispatched
   * the command and the async job is in flight.
   */
  onStarted: (siteId: string, job_id: string, accepted_count: number) => void;
  /**
   * Called on each intermediate `db.orphan.delete.progress` batch push.
   */
  onProgress: (siteId: string, progress: OrphanDeleteProgress) => void;
  /**
   * Called once on `db.orphan.delete.completed` (final push, done=true).
   * Callers should show the result toast and invalidate the orphans query.
   */
  onCompleted: (siteId: string, progress: OrphanDeleteProgress) => void;
  /**
   * Called on `db.orphan.delete.failed` (transport error, watchdog stall,
   * or agent ok=false ACK).
   */
  onFailed: (siteId: string, job_id: string, detail: string) => void;
}

/** The slice of the fonts store the reducer drives. */
export interface FontsActions {
  setPhase: (
    siteId: string,
    phase: import("../fonts-store").FontsPhase,
    extra?: { processed?: number; total?: number; savings_pct?: number },
  ) => void;
  setFontRowPhase: (siteId: string, sourceHash: string, rowPhase: FontRowPhase) => void;
  incrementProcessed: (siteId: string) => void;
  reset: (siteId: string) => void;
}

/** No-op implementation used when no orphan-delete listener is mounted. */
const noopOrphanDelete: DbOrphanDeleteActions = {
  onStarted: () => undefined,
  onProgress: () => undefined,
  onCompleted: () => undefined,
  onFailed: () => undefined,
};

export interface PerfEventDeps {
  siteId: string;
  queryClient: Pick<QueryClient, "invalidateQueries">;
  preload: PreloadActions;
  rucss: RucssActions;
  dbClean: DbCleanActions;
  dbScan: DbScanActions;
  dbOrphanDelete?: DbOrphanDeleteActions;
  fonts: FontsActions;
}

/**
 * Project one perf SSE event onto the caches + preload store. Exported so the
 * routing logic can be unit-tested without a React tree (mirrors
 * mediaEventReducer in useMediaEvents.ts).
 */
export function perfEventReducer(ev: SiteEvent, deps: PerfEventDeps): void {
  const {
    siteId,
    queryClient,
    preload,
    rucss,
    dbClean,
    dbScan,
    dbOrphanDelete = noopOrphanDelete,
    fonts,
  } = deps;
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
      // A new stats push may carry updated hit/miss counters — refresh the
      // hit-ratio trend so the chart stays current without a manual reload.
      void queryClient.invalidateQueries({ queryKey: perfKeys.cacheHealth(siteId) });
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
    case "db.clean.started": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const tasks = Array.isArray(data.tasks)
        ? (data.tasks as unknown[]).filter((t): t is string => typeof t === "string")
        : [];
      const trigger =
        data.trigger === "scheduled" ? "scheduled" : "manual";
      if (jobId) dbClean.startJob(siteId, jobId, tasks, trigger);
      break;
    }
    case "db.clean.progress": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const category = typeof data.category === "string" ? data.category : "";
      const rows = typeof data.rows_deleted === "number" ? data.rows_deleted : 0;
      const bytes = typeof data.bytes_freed === "number" ? data.bytes_freed : 0;
      const state = (["done", "skipped", "error"].includes(data.state as string)
        ? data.state
        : "done") as CategoryState;
      const detail =
        typeof data.detail === "string" ? data.detail : undefined;
      if (jobId && category) {
        dbClean.progressCategory(siteId, jobId, category, rows, bytes, state, detail);
      }
      break;
    }
    case "db.clean.completed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const rows = typeof data.rows_deleted === "number" ? data.rows_deleted : 0;
      const bytes = typeof data.bytes_freed === "number" ? data.bytes_freed : 0;
      const cats =
        typeof data.categories === "object" && data.categories !== null
          ? (data.categories as Record<string, { rows_deleted: number; bytes_freed: number; state: string }>)
          : {};
      if (jobId) dbClean.completeJob(siteId, jobId, rows, bytes, cats);
      void queryClient.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
      break;
    }
    case "db.clean.failed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const detail =
        typeof data.detail === "string" ? data.detail : "Database cleanup failed.";
      if (jobId) dbClean.failJob(siteId, jobId, detail);
      toast.error("Database cleanup failed.", { description: detail });
      break;
    }

    // ── database scan (Phase 2 — synchronous read-only preview) ─────────────
    case "db.scan.started": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const cats = Array.isArray(data.categories)
        ? (data.categories as unknown[]).filter((c): c is string => typeof c === "string")
        : [];
      if (jobId) dbScan.startScan(siteId, jobId, cats);
      break;
    }
    case "db.scan.completed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const rawCats =
        typeof data.categories === "object" && data.categories !== null
          ? (data.categories as Record<string, DbScanCategoryResult>)
          : {};
      const dbSizeBytes =
        typeof data.db_size_bytes === "number" ? data.db_size_bytes : 0;
      const tableCount =
        typeof data.table_count === "number" ? data.table_count : 0;
      const scannedAt =
        typeof data.scanned_at === "number" ? data.scanned_at : 0;
      // Extract the per-table inventory array. Rows that lack the expected shape
      // are filtered out so the store always receives a typed array.
      const rawTables = Array.isArray(data.tables) ? data.tables : [];
      const tables = rawTables.filter(
        (t): t is DbScanTableInventoryRow =>
          typeof t === "object" &&
          t !== null &&
          typeof (t as Record<string, unknown>).name === "string" &&
          typeof (t as Record<string, unknown>).engine === "string" &&
          typeof (t as Record<string, unknown>).belongs_to === "string" &&
          typeof (t as Record<string, unknown>).owner_type === "string" &&
          // Numeric fields must be real numbers too — a malformed agent payload
          // must not leak a string/NaN into the size/row sort + humanizer.
          typeof (t as Record<string, unknown>).rows === "number" &&
          typeof (t as Record<string, unknown>).size_bytes === "number" &&
          typeof (t as Record<string, unknown>).overhead_bytes === "number",
      );
      if (jobId) {
        dbScan.completeScan(siteId, jobId, rawCats, dbSizeBytes, tableCount, scannedAt, tables);
      }
      break;
    }
    case "db.scan.failed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const detail =
        typeof data.detail === "string" ? data.detail : "Database scan failed.";
      if (jobId) dbScan.failScan(siteId, jobId, detail);
      toast.error("Database scan failed.", { description: detail });
      break;
    }

    // ── Orphan delete (P3.8 — async) ────────────────────────────────────────
    case "db.orphan.delete.started": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const accepted =
        typeof data.accepted_count === "number" ? data.accepted_count : 0;
      if (jobId) dbOrphanDelete.onStarted(siteId, jobId, accepted);
      break;
    }
    case "db.orphan.delete.progress": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      if (jobId) {
        dbOrphanDelete.onProgress(siteId, {
          job_id: jobId,
          deleted_options:
            typeof data.deleted_options === "number" ? data.deleted_options : 0,
          deleted_cron:
            typeof data.deleted_cron === "number" ? data.deleted_cron : 0,
          deleted_tables:
            typeof data.deleted_tables === "number" ? data.deleted_tables : 0,
          skipped: typeof data.skipped === "number" ? data.skipped : 0,
        });
      }
      break;
    }
    case "db.orphan.delete.completed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      if (jobId) {
        const progress: OrphanDeleteProgress = {
          job_id: jobId,
          deleted_options:
            typeof data.deleted_options === "number" ? data.deleted_options : 0,
          deleted_cron:
            typeof data.deleted_cron === "number" ? data.deleted_cron : 0,
          deleted_tables:
            typeof data.deleted_tables === "number" ? data.deleted_tables : 0,
          skipped: typeof data.skipped === "number" ? data.skipped : 0,
        };
        dbOrphanDelete.onCompleted(siteId, progress);
        // Invalidate orphans report so the list refreshes after deletion.
        void queryClient.invalidateQueries({
          queryKey: perfKeys.dbOrphans(siteId),
        });
      }
      break;
    }
    case "db.orphan.delete.failed": {
      const jobId = typeof data.job_id === "string" ? data.job_id : "";
      const detail =
        typeof data.detail === "string"
          ? data.detail
          : "Orphan deletion failed.";
      dbOrphanDelete.onFailed(siteId, jobId, detail);
      toast.error("Orphan deletion failed.", { description: detail });
      break;
    }

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

    // ── Font processing lifecycle (ADR-052 Phase 2) ──────────────────────────
    // font.queued: a batch of font transcode jobs was enqueued for this site.
    case "font.queued": {
      const total = num(data.total) ?? 0;
      fonts.setPhase(siteId, "queued", { total, processed: 0 });
      break;
    }
    // font.converting: one specific font is now being processed.
    case "font.converting": {
      const sourceHash = typeof data.source_hash === "string" ? data.source_hash : "";
      fonts.setPhase(siteId, "converting");
      if (sourceHash) fonts.setFontRowPhase(siteId, sourceHash, "converting");
      break;
    }
    // font.ready: full WOFF2 produced for one font (no subset, or subset not
    // requested). Row transitions to ready in DB; invalidate to pick up sizes.
    // We do NOT set an SSE row-phase here — the DB state "ready" is the
    // authority, and Query invalidation surfaces it without an overlay.
    case "font.ready": {
      fonts.incrementProcessed(siteId);
      void queryClient.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
      break;
    }
    // font.subset: subset WOFF2 also produced for one font.
    case "font.subset": {
      fonts.incrementProcessed(siteId);
      void queryClient.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
      break;
    }
    // font.skipped: font was skipped (variable font, icon font, or guard).
    case "font.skipped": {
      const sourceHash = typeof data.source_hash === "string" ? data.source_hash : "";
      fonts.incrementProcessed(siteId);
      if (sourceHash) fonts.setFontRowPhase(siteId, sourceHash, "skipped");
      break;
    }
    // font.failed: permanent negative for one font (variable, icon, subset error,
    // panic). The DB row will be marked negative; invalidate + set SSE overlay.
    case "font.failed": {
      const sourceHash = typeof data.source_hash === "string" ? data.source_hash : "";
      fonts.incrementProcessed(siteId);
      if (sourceHash) fonts.setFontRowPhase(siteId, sourceHash, "failed");
      void queryClient.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
      break;
    }
    // font.completed: all fonts for the current page-build pass are done.
    // savings_pct = 1 - sum(best_output) / sum(original) from the CP.
    case "font.completed": {
      const savingsPct = num(data.savings_pct);
      const total = num(data.total) ?? 0;
      fonts.setPhase(siteId, "done", { savings_pct: savingsPct, total });
      void queryClient.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
      break;
    }

    // ── RUM rollup signal (Phase 3b) ─────────────────────────────────────────
    // rum.rollup_updated: the rollup worker has folded new beacons into the
    // hourly/daily tables for this site. Invalidate the summary + breakdown
    // queries so the CWV panel refreshes. This is a throttled aggregate signal
    // from the CP (at most once every few seconds), not a per-beacon stream, so
    // a query invalidation per frame is the correct and only reaction.
    //
    // Also invalidate the trend query. The trend key includes device + windowDays
    // as extra segments after siteId, so a prefix-match on the siteId-anchored
    // key invalidates all device/window variants for this site at once.
    case "rum.rollup_updated":
      void queryClient.invalidateQueries({ queryKey: perfKeys.rumSummary(siteId) });
      void queryClient.invalidateQueries({ queryKey: perfKeys.rum(siteId) });
      // Prefix-match: ["perf", "rumTrend", siteId] covers all device/windowDays
      // variants cached for this site.
      void queryClient.invalidateQueries({
        queryKey: [...perfKeys.all, "rumTrend", siteId],
      });
      break;

    // ── Object cache (m68 Phase 3) ───────────────────────────────────────────
    // objectcache.status_changed: a connectivity state transition (connected /
    // degraded / down / disabled). Bypass the 10s stats debounce — transition
    // events must update the pill immediately. Re-read the config (carries live
    // oc_state / oc_latency_ms / oc_last_error_class / oc_used_memory_bytes).
    case "objectcache.status_changed":
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
      break;

    // objectcache.stats_updated: non-transition heartbeat drift (latency /
    // memory). Apply the same 10s badge-debounce precedent: the query
    // invalidation is throttled naturally because the CP publishes this at most
    // once per heartbeat cycle. Re-read config for the updated metric fields.
    case "objectcache.stats_updated":
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheStats(siteId) });
      break;

    // objectcache.flushed: flush completed — refresh the config so the panel
    // reflects any state reset.
    case "objectcache.flushed":
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
      break;

    // objectcache.config_applied: the agent has applied the new connection
    // config. Refresh the config so last_test_config_hash and updated_at
    // are current.
    case "objectcache.config_applied":
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
      break;

    // objectcache.test_completed: the test probe has returned. Refresh the
    // config so last_tested_at and last_test_config_hash are current, and
    // the test-result panel can update.
    case "objectcache.test_completed":
      void queryClient.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
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
    type.startsWith("perf.") ||
    type.startsWith("font.") ||
    type.startsWith("rum.") ||
    type.startsWith("objectcache.")
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
  const dbCleanStartJob = useDbCleanStore((s) => s.startJob);
  const dbCleanProgress = useDbCleanStore((s) => s.progressCategory);
  const dbCleanComplete = useDbCleanStore((s) => s.completeJob);
  const dbCleanFail = useDbCleanStore((s) => s.failJob);
  const dbScanStartScan = useDbScanStore((s) => s.startScan);
  const dbScanComplete = useDbScanStore((s) => s.completeScan);
  const dbScanFail = useDbScanStore((s) => s.failScan);
  const fontsSetPhase = useFontsStore((s) => s.setPhase);
  const fontsSetFontRowPhase = useFontsStore((s) => s.setFontRowPhase);
  const fontsIncrementProcessed = useFontsStore((s) => s.incrementProcessed);
  const fontsReset = useFontsStore((s) => s.reset);

  const handler = useCallback(
    (ev: SiteEvent) => {
      if (ev.site_id !== siteId) return;
      if (!isPerfEvent(ev.type)) return;
      perfEventReducer(ev, {
        siteId,
        queryClient: qc,
        preload: { start, progress, finish },
        rucss: { setPhase: rucssSetPhase, reset: rucssReset },
        dbClean: {
          startJob: dbCleanStartJob,
          progressCategory: dbCleanProgress,
          completeJob: dbCleanComplete,
          failJob: dbCleanFail,
        },
        dbScan: {
          startScan: dbScanStartScan,
          completeScan: dbScanComplete,
          failScan: dbScanFail,
        },
        fonts: {
          setPhase: fontsSetPhase,
          setFontRowPhase: fontsSetFontRowPhase,
          incrementProcessed: fontsIncrementProcessed,
          reset: fontsReset,
        },
      });
    },
    [
      siteId,
      qc,
      start,
      progress,
      finish,
      rucssSetPhase,
      rucssReset,
      dbCleanStartJob,
      dbCleanProgress,
      dbCleanComplete,
      dbCleanFail,
      dbScanStartScan,
      dbScanComplete,
      dbScanFail,
      fontsSetPhase,
      fontsSetFontRowPhase,
      fontsIncrementProcessed,
      fontsReset,
    ],
  );

  useSiteEvents(handler);

  // On SSE reconnect, invalidate all TanStack Query state that is backed by a
  // live server endpoint. This covers every perf surface that was relying on
  // a missed SSE frame to drive a refresh (object-cache status pill, RUCSS
  // results, cache stats, font results, RUM summary). Zustand-only stores
  // (preload, rucss-store, db-scan, db-clean, fonts-store) require their own
  // domain-specific re-hydration (handled in DatabaseSection for db-scan; the
  // others only display transient progress and self-timeout via their stale-
  // backstops, so they are acceptable as noted in the audit table).
  const reconnectHandler = useCallback(() => {
    void qc.invalidateQueries({ queryKey: perfKeys.config(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.stats(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.fonts(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.objectCacheConfig(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.objectCacheStats(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.cacheHealth(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.rumSummary(siteId) });
    void qc.invalidateQueries({ queryKey: perfKeys.rum(siteId) });
  }, [siteId, qc]);

  useSiteReconnect(reconnectHandler);
}
