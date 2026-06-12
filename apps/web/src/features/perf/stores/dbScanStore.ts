import { create } from "zustand";

// Ephemeral DB-scan job indicator, driven by db.scan.started / db.scan.completed /
// db.scan.failed SSE events (usePerfEvents). Never server state — lives in
// Zustand per the "never mix server state into Zustand" convention. The
// authoritative scan result (once persisted by the CP) is the preview displayed
// in the scan->preview->clean flow.
//
// The 14 canonical category ids (frozen contract — matches db-clean-store.ts):
export const DB_SCAN_CATEGORY_IDS = [
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

export type DbScanCategoryId = (typeof DB_SCAN_CATEGORY_IDS)[number];

/** Per-table detail for the optimize_tables category. */
export interface DbScanTableDetail {
  name: string;
  engine: string;
  data_length: number;
  data_free: number;
}

/** One category's scan result (count + reclaimable bytes). */
export interface DbScanCategoryResult {
  count: number;
  bytes: number;
  /** Present only for optimize_tables. */
  tables?: DbScanTableDetail[];
  /** True when the count was capped at 10 000 (estimate). */
  capped?: boolean;
}

export type DbScanPhase = "scanning" | "completed" | "failed" | null;

/** owner_type enum — matches the frozen contract exactly. */
export type DbScanOwnerType = "core" | "plugin" | "theme" | "orphan" | "unknown";

/**
 * Per-table inventory row returned by the agent's db_scan.
 * JSON keys are stable across all layers (agent → CP → SSE → store → view).
 */
export interface DbScanTableInventoryRow {
  /** Full table name including the wp_ prefix, e.g. "wp_posts". */
  name: string;
  /** TABLE_ROWS from information_schema. InnoDB = estimate; MyISAM = exact. */
  rows: number;
  /** DATA_LENGTH + INDEX_LENGTH in bytes. */
  size_bytes: number;
  /** ENGINE column, e.g. "InnoDB" | "MyISAM". */
  engine: string;
  /** DATA_FREE in bytes (fragmentation overhead). 0 for most InnoDB tables. */
  overhead_bytes: number;
  /**
   * Human-readable label: "WordPress core" | plugin display name |
   * theme display name | "Orphan".
   */
  belongs_to: string;
  /** Ownership classification enum. */
  owner_type: DbScanOwnerType;
}

export interface DbScanLive {
  /** Current phase; null when idle (no scan has run). */
  phase: DbScanPhase;
  /** job_id from the started event — correlates completed/failed frames. */
  job_id: string | null;
  /** Categories requested (from db.scan.started). */
  categories_requested: string[];
  /** Per-category scan results — populated from db.scan.completed. */
  categories: Record<string, DbScanCategoryResult>;
  /** Full per-table inventory — populated from db.scan.completed. */
  tables: DbScanTableInventoryRow[];
  /** Total database size bytes (from db.scan.completed). */
  db_size_bytes: number;
  /** Number of tables (from db.scan.completed). */
  table_count: number;
  /** Unix timestamp the agent performed the scan (from db.scan.completed). */
  scanned_at: number | null;
  /** Human detail string from db.scan.failed. */
  failed_detail: string | null;
  /** Monotonic timestamp of the last state change (for stale-timeout guards). */
  updatedAt: number;
}

interface DbScanState {
  bySite: Record<string, DbScanLive>;

  /** db.scan.started: begin scan, reset previous result. */
  startScan: (siteId: string, job_id: string, categories: string[]) => void;

  /** db.scan.completed: store the full per-category result + table inventory. */
  completeScan: (
    siteId: string,
    job_id: string,
    categories: Record<string, DbScanCategoryResult>,
    db_size_bytes: number,
    table_count: number,
    scanned_at: number,
    tables: DbScanTableInventoryRow[],
  ) => void;

  /** db.scan.failed: mark failed with detail. */
  failScan: (siteId: string, job_id: string, detail: string) => void;

  /** Reset to idle (clears the preview — used before a new scan or on dismiss). */
  reset: (siteId: string) => void;
}

const IDLE: DbScanLive = {
  phase: null,
  job_id: null,
  categories_requested: [],
  categories: {},
  tables: [],
  db_size_bytes: 0,
  table_count: 0,
  scanned_at: null,
  failed_detail: null,
  updatedAt: 0,
};

export const useDbScanStore = create<DbScanState>((set) => ({
  bySite: {},

  startScan: (siteId, job_id, categories) =>
    set((s) => ({
      bySite: {
        ...s.bySite,
        [siteId]: {
          phase: "scanning",
          job_id,
          categories_requested: categories,
          categories: {},
          tables: [],
          db_size_bytes: 0,
          table_count: 0,
          scanned_at: null,
          failed_detail: null,
          updatedAt: Date.now(),
        },
      },
    })),

  completeScan: (siteId, job_id, categories, db_size_bytes, table_count, scanned_at, tables) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      // Discard stale frames: if job_id doesn't match the in-flight scan, drop.
      // The empty string is the optimistic sentinel set by useDbScan before the
      // real job_id is known; treat it like null so the ACK result and a
      // completed frame arriving after a missed started frame are accepted.
      if (prev.job_id !== null && prev.job_id !== "" && prev.job_id !== job_id) return s;
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            phase: "completed",
            job_id,
            categories,
            tables,
            db_size_bytes,
            table_count,
            scanned_at,
            failed_detail: null,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  failScan: (siteId, job_id, detail) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      // Same sentinel handling as completeScan: "" matches any job.
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

/** Selector: live DB-scan state for one site (defaults to idle). */
export function selectDbScan(state: DbScanState, siteId: string): DbScanLive {
  return state.bySite[siteId] ?? IDLE;
}
