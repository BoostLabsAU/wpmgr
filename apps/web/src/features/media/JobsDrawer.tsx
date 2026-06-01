import { useMemo } from "react";
import { AnimatePresence, motion } from "motion/react";
import { Activity, ChevronUp, Loader2, X } from "lucide-react";
import { Virtuoso } from "react-virtuoso";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { drawerUp } from "@/lib/motion-presets";
import { StatusDot } from "@/components/status/status-dot";

import {
  dedupeRows,
  mapServerJobToRow,
  runningCount,
  selectSiteRows,
  useJobsStore,
  type LiveJobRow,
} from "./jobs-store";
import { useMediaJobs } from "./hooks/useMediaJobs";
import { isTerminalJobState, type JobState } from "./types";

// Recently-terminal server jobs still merge in (so a job that finished while the
// tab was closed/refreshing is briefly visible) — older terminal jobs belong in
// the authoritative Jobs list, not this live drawer.
const RECENT_TERMINAL_MS = 5 * 60_000;

// JobsDrawer — slide-up drawer (drawerUp preset) showing live per-asset job
// rows fed by SSE (the jobs-store). It is dismissible WITHOUT cancelling: the X
// only collapses the drawer; the jobs keep running and an "N jobs running"
// badge lets the operator re-open it. Cancelling is a separate explicit action
// (the BulkActionBar / toolbar's Cancel-all).
//
// Layering: fixed bottom strip, z-40 (under the topbar's z-50 dialogs). Borders
// over shadows except the drawer itself, which literally floats (shadow-lg per
// DESIGN "drawer = lg").
//
// Large batches: the row list is virtualized via react-virtuoso's <Virtuoso>
// (same dep as AssetsTable's <TableVirtuoso>) so 300+ rows stay performant.
// An aggregate summary bar shows total / succeeded / failed / running counts
// and a compact progress bar so the operator gets the big picture at a glance
// without scrolling through every row.

const KIND_LABEL: Record<LiveJobRow["kind"], string> = {
  optimize: "Optimizing",
  restore: "Restoring",
  delete_originals: "Deleting originals",
  sync: "Syncing",
};

function stateTone(state: JobState): {
  tone: "info" | "success" | "destructive" | "muted";
  pulse: boolean;
} {
  switch (state) {
    case "queued":
    case "in_progress":
      return { tone: "info", pulse: true };
    case "succeeded":
    case "partially_succeeded":
      return { tone: "success", pulse: false };
    case "failed":
      return { tone: "destructive", pulse: false };
    case "cancelled":
      return { tone: "muted", pulse: false };
  }
}

export interface JobsDrawerProps {
  siteId: string;
}

export function JobsDrawer({ siteId }: JobsDrawerProps) {
  const rowsRecord = useJobsStore((s) => selectSiteRows(s, siteId));
  const open = useJobsStore((s) => s.openBySite[siteId] ?? false);
  const setOpen = useJobsStore((s) => s.setOpen);

  // Authoritative server jobs — the rehydration backstop. After a refresh the
  // in-memory SSE rows (rowsRecord) are empty, so a mid-flight job would vanish;
  // we merge server jobs that have no live row to keep it visible.
  const { data: serverJobs } = useMediaJobs(siteId);

  // Merge: live SSE rows ALWAYS win for the same jobId (they carry real-time
  // progress); server jobs only fill the gaps. Include server-only jobs that are
  // still queued/in_progress, or that reached a terminal state recently.
  const merged = useMemo(() => {
    const out: Record<string, LiveJobRow> = { ...rowsRecord };
    const now = Date.now();
    for (const job of serverJobs?.items ?? []) {
      if (job.id in out) continue; // live row already covers this job → it wins.
      const row = mapServerJobToRow(job);
      const keep =
        !isTerminalJobState(row.state) || now - row.updatedAt < RECENT_TERMINAL_MS;
      if (keep) out[job.id] = row;
    }
    return out;
  }, [rowsRecord, serverJobs]);

  // Dedupe to one row per attachment + drop stale phantoms, then sort newest
  // first. The badge, header pill, and rendered list all read this same set.
  const rows = useMemo(
    () =>
      dedupeRows(merged, RECENT_TERMINAL_MS).sort(
        (a, b) => b.updatedAt - a.updatedAt,
      ),
    [merged],
  );
  const running = runningCount(rows);

  // Aggregate summary stats for the header bar.
  const summary = useMemo(() => {
    let succeeded = 0;
    let failed = 0;
    let queued = 0;
    for (const r of rows) {
      if (r.state === "succeeded" || r.state === "partially_succeeded")
        succeeded += 1;
      else if (r.state === "failed") failed += 1;
      else if (r.state === "queued" || r.state === "in_progress") queued += 1;
    }
    const total = rows.length;
    const done = succeeded + failed;
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    return { total, succeeded, failed, queued, pct };
  }, [rows]);

  // Hide entirely only when there are no live rows AND the drawer isn't
  // explicitly open. When opened via the toolbar "Jobs" button (no live rows
  // yet), still render so the user gets a discoverable panel + empty state
  // instead of nothing.
  if (rows.length === 0 && !open) return null;

  return (
    <>
      {/* Collapsed re-open badge — bottom-right pill when the drawer is closed
          but jobs are present/running. */}
      <AnimatePresence>
        {!open ? (
          <motion.div
            key="jobs-badge"
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 8 }}
            className="fixed bottom-4 right-4 z-40"
          >
            <Button
              type="button"
              size="sm"
              onClick={() => setOpen(siteId, true)}
              aria-label={
                running > 0
                  ? `${running} media jobs running. Open jobs drawer`
                  : "Open media jobs drawer"
              }
              className="gap-2 shadow-lg"
            >
              {running > 0 ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <Activity aria-hidden="true" className="size-4" />
              )}
              <span className="tabular-nums">
                {running > 0
                  ? `${running} ${running === 1 ? "job" : "jobs"} running`
                  : "Jobs"}
              </span>
              <ChevronUp aria-hidden="true" className="size-4" />
            </Button>
          </motion.div>
        ) : null}
      </AnimatePresence>

      {/* The drawer itself. */}
      <AnimatePresence>
        {open ? (
          <motion.aside
            key="jobs-drawer"
            variants={drawerUp}
            initial="initial"
            animate="animate"
            exit="exit"
            role="region"
            aria-label="Media jobs"
            className="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-[1200px] rounded-t-xl border border-[var(--color-border)] bg-[var(--color-card)] shadow-lg"
          >
            {/* Header row */}
            <header className="flex h-11 items-center justify-between border-b border-[var(--color-border)] px-4">
              <div className="flex items-center gap-2 text-sm font-medium text-[var(--color-foreground)]">
                <Activity
                  aria-hidden="true"
                  className="size-4 text-[var(--color-primary)]"
                />
                Media jobs
                {running > 0 ? (
                  <span className="rounded-full bg-[var(--color-info)]/10 px-2 py-0.5 text-xs tabular-nums text-[var(--color-info)]">
                    {running} running
                  </span>
                ) : null}
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={() => setOpen(siteId, false)}
                aria-label="Collapse jobs drawer (jobs keep running)"
                className="size-7"
              >
                <X aria-hidden="true" className="size-4" />
              </Button>
            </header>

            {/* Aggregate summary bar — only shown when there are rows. */}
            {rows.length > 0 ? (
              <div className="flex flex-col gap-1.5 border-b border-[var(--color-border)] px-4 py-2.5">
                {/* Counts row */}
                <div className="flex items-center gap-3 text-xs tabular-nums text-[var(--color-muted-foreground)]">
                  <span className="font-medium text-[var(--color-foreground)]">
                    {summary.total.toLocaleString()} job
                    {summary.total !== 1 ? "s" : ""}
                  </span>
                  {summary.queued > 0 ? (
                    <span className="text-[var(--color-info)]">
                      {summary.queued.toLocaleString()} running/queued
                    </span>
                  ) : null}
                  {summary.succeeded > 0 ? (
                    <span className="text-[var(--color-success)]">
                      {summary.succeeded.toLocaleString()} done
                    </span>
                  ) : null}
                  {summary.failed > 0 ? (
                    <span className="text-[var(--color-destructive)]">
                      {summary.failed.toLocaleString()} failed
                    </span>
                  ) : null}
                </div>
                {/* Compact progress bar */}
                <div
                  className="h-1 w-full overflow-hidden rounded-full bg-[var(--color-muted)]"
                  role="progressbar"
                  aria-valuenow={summary.pct}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label={`Overall job progress: ${summary.pct}%`}
                >
                  <div
                    className={cn(
                      "h-full rounded-full transition-[width] duration-300",
                      summary.failed > 0 && summary.succeeded === 0
                        ? "bg-[var(--color-destructive)]"
                        : summary.failed > 0
                          ? "bg-[var(--color-warning)]"
                          : "bg-[var(--color-info)]",
                    )}
                    style={{ width: `${summary.pct}%` }}
                  />
                </div>
              </div>
            ) : null}

            {/* Virtualized row list — performant at 300+ rows. */}
            <div className="max-h-[35vh]" aria-live="polite">
              {rows.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-[var(--color-muted-foreground)]">
                  No active media jobs. Optimize or restore assets to see live
                  progress here.
                </p>
              ) : (
                <Virtuoso
                  style={{ height: Math.min(rows.length * 52, window.innerHeight * 0.35) }}
                  totalCount={rows.length}
                  data={rows}
                  computeItemKey={(_, row) => row.jobId}
                  itemContent={(_, row) => <JobRow row={row} />}
                  // Render all items without virtualization up to ~20 rows so the
                  // drawer feels instant for small batches; virtualize above that.
                  increaseViewportBy={rows.length <= 20 ? 9999 : 200}
                />
              )}
            </div>
          </motion.aside>
        ) : null}
      </AnimatePresence>
    </>
  );
}

function JobRow({ row }: { row: LiveJobRow }) {
  const { tone, pulse } = stateTone(row.state);
  const terminal = isTerminalJobState(row.state);
  const pct =
    typeof row.progress === "number"
      ? Math.max(0, Math.min(100, Math.round(row.progress)))
      : null;

  return (
    <div className="flex items-center gap-3 border-b border-[var(--color-border)] px-4 py-2.5">
      <StatusDot tone={tone} pulse={pulse} />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate text-sm text-[var(--color-foreground)]">
            {KIND_LABEL[row.kind]}
            {typeof row.wpAttachmentID === "number" ? (
              <span className="ml-1 font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
                #{row.wpAttachmentID}
              </span>
            ) : null}
          </span>
        </div>
        {row.reason ? (
          <p className="truncate text-xs text-[var(--color-destructive)]">
            {row.reason}
          </p>
        ) : null}
      </div>

      {/* Progress / state on the right. */}
      <div className="flex w-28 shrink-0 items-center justify-end gap-2">
        {!terminal && pct !== null ? (
          <>
            <div
              className="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--color-muted)]"
              role="progressbar"
              aria-valuenow={pct}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="Encode progress"
            >
              <div
                className="h-full rounded-full bg-[var(--color-info)] transition-[width] duration-300"
                style={{ width: `${pct}%` }}
              />
            </div>
            <span className="font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
              {pct}%
            </span>
          </>
        ) : (
          <span
            className={cn(
              "text-xs font-medium tabular-nums",
              tone === "success" && "text-[var(--color-success)]",
              tone === "destructive" && "text-[var(--color-destructive)]",
              tone === "muted" && "text-[var(--color-muted-foreground)]",
              tone === "info" && "text-[var(--color-info)]",
            )}
          >
            {stateText(row.state)}
          </span>
        )}
      </div>
    </div>
  );
}

function stateText(state: JobState): string {
  switch (state) {
    case "queued":
      return "Queued";
    case "in_progress":
      return "Running";
    case "succeeded":
      return "Done";
    case "partially_succeeded":
      return "Partial";
    case "failed":
      return "Failed";
    case "cancelled":
      return "Cancelled";
  }
}
