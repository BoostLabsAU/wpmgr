import { create } from "zustand";

// Ephemeral DB-clean job indicator, driven by the db.clean.started /
// db.clean.progress / db.clean.completed / db.clean.failed SSE events
// (usePerfEvents). Never server state — lives in Zustand per the "never mix
// server state into Zustand" convention. The authoritative clean history stays
// in TanStack Query.
//
// The 14 canonical category ids (frozen contract):
export const DB_CLEAN_CATEGORY_IDS = [
  "revisions",
  "auto_drafts",
  "trashed_posts",
  "spam_comments",
  "trashed_comments",
  "expired_transients",
  "optimize_tables",
  "orphaned_postmeta",
  "orphaned_commentmeta",
  "orphaned_term_relationships",
  "oembed_cache",
  "duplicate_postmeta",
  "action_scheduler_completed",
  "action_scheduler_failed",
] as const;

export type DbCleanCategoryId = (typeof DB_CLEAN_CATEGORY_IDS)[number];

/** State of one category during a running job. */
export type CategoryState = "pending" | "done" | "skipped" | "error";

export interface CategoryProgress {
  category: string;
  rows_deleted: number;
  bytes_freed: number;
  state: CategoryState;
  detail?: string;
}

export type DbCleanPhase = "running" | "completed" | "failed" | null;

export interface DbCleanLive {
  /** Current phase; null when idle. */
  phase: DbCleanPhase;
  /** job_id from the started event — used to correlate progress frames. */
  job_id: string | null;
  /** "manual" | "scheduled" from the started event. */
  trigger: "manual" | "scheduled" | null;
  /** Ordered list of category ids the job is running (from db.clean.started). */
  tasks: string[];
  /** Per-category progress accumulator keyed by category id. */
  categories: Record<string, CategoryProgress>;
  /** Running total across all categories (updated per progress frame). */
  rows_deleted_total: number;
  /** Running bytes total (updated per progress frame). */
  bytes_freed_total: number;
  /** Human detail string from db.clean.failed. */
  failed_detail: string | null;
  /** Monotonic timestamp of the last frame (for stale auto-hide). */
  updatedAt: number;
}

interface DbCleanState {
  bySite: Record<string, DbCleanLive>;

  /** db.clean.started: begin job, reset accumulators. */
  startJob: (
    siteId: string,
    job_id: string,
    tasks: string[],
    trigger: "manual" | "scheduled",
  ) => void;

  /** db.clean.progress: accumulate one category result. */
  progressCategory: (
    siteId: string,
    job_id: string,
    category: string,
    rows_deleted: number,
    bytes_freed: number,
    state: CategoryState,
    detail?: string,
  ) => void;

  /** db.clean.completed: mark done, store final totals + category summary. */
  completeJob: (
    siteId: string,
    job_id: string,
    rows_deleted: number,
    bytes_freed: number,
    categories: Record<string, { rows_deleted: number; bytes_freed: number; state: string }>,
  ) => void;

  /** db.clean.failed: mark failed with detail. */
  failJob: (siteId: string, job_id: string, detail: string) => void;

  /** Reset to idle (used by auto-clear timer). */
  reset: (siteId: string) => void;
}

const IDLE: DbCleanLive = {
  phase: null,
  job_id: null,
  trigger: null,
  tasks: [],
  categories: {},
  rows_deleted_total: 0,
  bytes_freed_total: 0,
  failed_detail: null,
  updatedAt: 0,
};

export const useDbCleanStore = create<DbCleanState>((set) => ({
  bySite: {},

  startJob: (siteId, job_id, tasks, trigger) =>
    set((s) => ({
      bySite: {
        ...s.bySite,
        [siteId]: {
          phase: "running",
          job_id,
          trigger,
          tasks,
          categories: {},
          rows_deleted_total: 0,
          bytes_freed_total: 0,
          failed_detail: null,
          updatedAt: Date.now(),
        },
      },
    })),

  progressCategory: (siteId, job_id, category, rows_deleted, bytes_freed, state, detail) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      // Only update if the job_id matches (stale frames from a previous job are
      // dropped). If the store is idle (no job_id), accept the push — guards
      // against a CP-restart that clears the started event.
      if (prev.job_id !== null && prev.job_id !== job_id) return s;

      const prevCat = prev.categories[category];
      const newCat: CategoryProgress = {
        category,
        rows_deleted,
        bytes_freed,
        state,
        detail,
      };

      // Recompute totals: subtract old category contribution, add new.
      const prevRows = prevCat?.rows_deleted ?? 0;
      const prevBytes = prevCat?.bytes_freed ?? 0;

      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            job_id: prev.job_id ?? job_id,
            categories: { ...prev.categories, [category]: newCat },
            rows_deleted_total:
              prev.rows_deleted_total - prevRows + rows_deleted,
            bytes_freed_total:
              prev.bytes_freed_total - prevBytes + bytes_freed,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  completeJob: (siteId, job_id, rows_deleted, bytes_freed, categories) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      // Same sentinel handling as dbScanStore: "" is the optimistic sentinel set
      // by the mutation's onMutate before the real job_id is known. Treat it like
      // null so a completeJob arriving after a missed startJob is accepted, and so
      // the hydration path (which passes "" for historical results) is not blocked.
      if (prev.job_id !== null && prev.job_id !== "" && prev.job_id !== job_id) return s;

      // Merge final category summary from the completed payload (authoritative).
      const merged: Record<string, CategoryProgress> = { ...prev.categories };
      for (const [cat, result] of Object.entries(categories)) {
        merged[cat] = {
          category: cat,
          rows_deleted: result.rows_deleted,
          bytes_freed: result.bytes_freed,
          state: result.state as CategoryState,
        };
      }

      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            phase: "completed",
            rows_deleted_total: rows_deleted,
            bytes_freed_total: bytes_freed,
            categories: merged,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  failJob: (siteId, job_id, detail) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      // Same sentinel handling as completeJob: "" matches any job so a failJob
      // arriving after a missed startJob (or from the hydration stale-timeout
      // path) correctly transitions the store out of its stuck state.
      if (prev.job_id !== null && prev.job_id !== "" && prev.job_id !== job_id) return s;
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            phase: "failed",
            failed_detail: detail,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  reset: (siteId) =>
    set((s) => ({ bySite: { ...s.bySite, [siteId]: { ...IDLE } } })),
}));

/** Selector: live DB-clean state for one site (defaults to idle). */
export function selectDbClean(state: DbCleanState, siteId: string): DbCleanLive {
  return state.bySite[siteId] ?? IDLE;
}
