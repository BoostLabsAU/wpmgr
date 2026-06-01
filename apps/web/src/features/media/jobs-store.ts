import { create } from "zustand";

import { isTerminalJobState, type JobState, type MediaJob } from "./types";

// JobsDrawer store — UI-only client state (Zustand). This holds the LIVE,
// per-asset progress rows the JobsDrawer renders while a batch runs, plus the
// drawer open/dismissed flags. It is intentionally NOT server state: the
// authoritative job records live in TanStack Query (useMediaJobs); this store
// only mirrors the ephemeral SSE progress stream so the drawer can update
// row-by-row without a refetch. (Rule: never mix server state into Zustand.)
//
// Keyed by site_id so switching sites shows that site's running jobs. A row is
// keyed by its ULID job_id (optimize/restore fan out one job per attachment).

/** One live job row shown in the drawer (sourced from SSE progress frames). */
export interface LiveJobRow {
  jobId: string;
  /** WP attachment id (for a human-readable "Attachment #123" label). */
  wpAttachmentID?: number;
  kind: "optimize" | "restore" | "delete_originals" | "sync";
  /** 0–100; undefined until the first progress frame. */
  progress?: number;
  state: JobState;
  /** Failure reason, when state === "failed". */
  reason?: string;
  /** Wall-clock of the last update (for sort stability / staleness). */
  updatedAt: number;
}

interface JobsState {
  /** Per-site live rows, keyed siteId → (jobId → row). */
  rowsBySite: Record<string, Record<string, LiveJobRow>>;
  /** Whether the drawer is currently expanded, per site. */
  openBySite: Record<string, boolean>;
  /** Set the drawer open/closed for a site (close = dismiss-without-cancel). */
  setOpen: (siteId: string, open: boolean) => void;
  /** Upsert a live row (called by the SSE handler on progress/start frames). */
  upsertRow: (siteId: string, row: LiveJobRow) => void;
  /** Drop a terminal/cleared row from the live set. */
  removeRow: (siteId: string, jobId: string) => void;
  /** Clear every live row for a site (e.g. after Cancel-all). */
  clearSite: (siteId: string) => void;
}

export const useJobsStore = create<JobsState>((set) => ({
  rowsBySite: {},
  openBySite: {},
  setOpen: (siteId, open) =>
    set((s) => ({ openBySite: { ...s.openBySite, [siteId]: open } })),
  upsertRow: (siteId, row) =>
    set((s) => {
      const existing = s.rowsBySite[siteId] ?? {};
      const prev = existing[row.jobId];
      // Preserve a known wpAttachmentID/kind if a later frame omits it.
      const merged: LiveJobRow = {
        ...prev,
        ...row,
        wpAttachmentID: row.wpAttachmentID ?? prev?.wpAttachmentID,
      };
      return {
        rowsBySite: {
          ...s.rowsBySite,
          [siteId]: { ...existing, [row.jobId]: merged },
        },
      };
    }),
  removeRow: (siteId, jobId) =>
    set((s) => {
      const existing = s.rowsBySite[siteId];
      if (!existing || !(jobId in existing)) return s;
      const next = { ...existing };
      delete next[jobId];
      return { rowsBySite: { ...s.rowsBySite, [siteId]: next } };
    }),
  clearSite: (siteId) =>
    set((s) => ({ rowsBySite: { ...s.rowsBySite, [siteId]: {} } })),
}));

/** Stable empty object so selectors don't churn when a site has no rows. */
const EMPTY_ROWS: Record<string, LiveJobRow> = {};

/** Select the live rows for a site as a stable record. */
export function selectSiteRows(
  state: JobsState,
  siteId: string,
): Record<string, LiveJobRow> {
  return state.rowsBySite[siteId] ?? EMPTY_ROWS;
}

// Count the genuinely-active rows for the badge. Caller passes the ALREADY-
// deduped array so the "N running" badge equals the rendered row count.
export function runningCount(rows: LiveJobRow[]): number {
  let n = 0;
  for (const r of rows) {
    if (r.state === "queued" || r.state === "in_progress") n += 1;
  }
  return n;
}

/**
 * Collapse rows to one per wp_attachment_id — the most-recently-updated wins, so
 * a live SSE row beats older accumulated server jobs for the same asset. Rows
 * with no attachment id (e.g. site-wide sync) are keyed by jobId so they're
 * never merged. Stale phantoms (terminal, or non-terminal older than maxAgeMs)
 * are dropped. This turns "17 running" (duplicate #16×3/#9×2 + the numberless
 * orphan + leftover queued jobs) into the true active set.
 */
export function dedupeRows(
  rows: Record<string, LiveJobRow>,
  maxAgeMs: number,
  now: number = Date.now(),
): LiveJobRow[] {
  const byKey = new Map<string, LiveJobRow>();
  for (const r of Object.values(rows)) {
    const active = r.state === "queued" || r.state === "in_progress";
    const recentTerminal =
      isTerminalJobState(r.state) && now - r.updatedAt < maxAgeMs;
    if (!active && !recentTerminal) continue; // drop stale phantoms + old terminals
    const hasAttachment =
      typeof r.wpAttachmentID === "number" && r.wpAttachmentID > 0;
    const key = hasAttachment ? `att:${r.wpAttachmentID}` : `job:${r.jobId}`;
    const prev = byKey.get(key);
    if (!prev) {
      byKey.set(key, r);
      continue;
    }
    const prevActive = prev.state === "queued" || prev.state === "in_progress";
    if (active && !prevActive) byKey.set(key, r);
    else if (active === prevActive && r.updatedAt > prev.updatedAt)
      byKey.set(key, r);
  }
  return Array.from(byKey.values());
}

/**
 * Map an authoritative server MediaJob → the drawer's LiveJobRow shape. Used to
 * rehydrate the drawer after a page refresh wipes the in-memory SSE rows: a
 * queued/in_progress (or recently-terminal) job that has no live row is shown
 * from this server snapshot so a mid-flight job stays visible. Live SSE rows
 * always take precedence for the same jobId (this is only a fallback).
 *
 * Progress: the server MediaJob carries no percentage on the wire, only a
 * variants tally; we derive a coarse 0–100 from variants_succeeded/total (the
 * same scale the SSE rows use) so a stuck job still renders a bar rather than a
 * bare "Running". A job with no variants yet leaves progress undefined.
 */
export function mapServerJobToRow(job: MediaJob): LiveJobRow {
  const total = job.variants_total;
  const progress =
    isTerminalJobState(job.state)
      ? 100
      : total > 0
        ? Math.round(((job.variants_succeeded + job.variants_failed) / total) * 100)
        : undefined;
  // started_at is set once running; fall back to created_at for queued jobs.
  const ts = Date.parse(job.completed_at ?? job.started_at ?? job.created_at);
  return {
    jobId: job.id,
    wpAttachmentID: job.wp_attachment_id,
    kind: job.kind,
    progress,
    state: job.state,
    reason: job.error_reason,
    updatedAt: Number.isNaN(ts) ? 0 : ts,
  };
}
