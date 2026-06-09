import { useEffect, useState } from "react";
import {
  CheckCircle2,
  Database,
  Loader2,
  ScanLine,
  XCircle,
  SkipForward,
  RotateCcw,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { toast } from "@/components/toast";

import { SelectField } from "../components/Field";
import { SettingRow } from "../components/SettingRow";
import { useDbCleanSelected } from "../hooks/useCacheStats";
import { useDbScan } from "../hooks/useDbScan";
import { DB_CLEAN_INTERVALS, type PerfConfig } from "../types";
import {
  useDbCleanStore,
  selectDbClean,
  type CategoryProgress,
  type DbCleanLive,
} from "../db-clean-store";
import {
  useDbScanStore,
  selectDbScan,
  DB_SCAN_CATEGORY_IDS,
  type DbScanLive,
  type DbScanCategoryResult,
} from "../stores/dbScanStore";
import { formatBytes } from "../format";
import { DatabaseTableView } from "./DatabaseTableView";
import { DatabaseHealthView } from "./DatabaseHealthView";

// Database cleanup: scheduled auto-clean (+ interval) and the per-category
// cleanup toggles, plus a scan->preview->clean flow.
//
// UX FLOW:
//   1. "Scan database" button triggers a db_scan (read-only). While scanning the
//      button shows a spinner.
//   2. db.scan.completed SSE arrives → the preview table replaces the static
//      toggle list, showing per-category COUNT + reclaimable BYTES, with a
//      checkbox per category (all ticked by default).
//   3. A totals row shows aggregate reclaimable bytes, DB size, and table count.
//   4. "Clean selected" runs db_clean with only the ticked categories.
//   5. db.clean.started SSE arrives → live per-category progress panel (same as
//      Phase 1) replaces the preview table.
//   6. db.clean.completed / db.clean.failed transitions to the final state.
//
// WATCHDOG: db.clean.failed and db.scan.failed events (fired by the CP watchdog
// or agent shutdown handler) always transition the UI out of "scanning" /
// "starting", so the operator never sees an infinite spinner.

// Human-readable labels for the 14 canonical category ids.
const CATEGORY_LABELS: Record<string, string> = {
  revisions: "Post revisions",
  auto_drafts: "Auto-drafts",
  trashed_posts: "Trashed posts",
  spam_comments: "Spam comments",
  trashed_comments: "Trashed comments",
  expired_transients: "Expired transients",
  optimize_tables: "Optimize tables",
  orphaned_postmeta: "Orphaned post meta",
  orphaned_commentmeta: "Orphaned comment meta",
  orphaned_term_relationships: "Orphaned term relationships",
  oembed_cache: "oEmbed cache",
  duplicate_postmeta: "Duplicate post meta",
  action_scheduler_completed: "Action scheduler (completed)",
  action_scheduler_failed: "Action scheduler (failed)",
};

interface CleanToggle {
  key: keyof PerfConfig;
  label: string;
  description: string;
}

const TOGGLES: CleanToggle[] = [
  {
    key: "db_post_revisions",
    label: "Post revisions",
    description: "Remove stored revisions of posts and pages.",
  },
  {
    key: "db_post_auto_drafts",
    label: "Auto-drafts",
    description: "Remove abandoned auto-draft posts.",
  },
  {
    key: "db_post_trashed",
    label: "Trashed posts",
    description: "Permanently delete posts in the trash.",
  },
  {
    key: "db_comments_spam",
    label: "Spam comments",
    description: "Delete comments marked as spam.",
  },
  {
    key: "db_comments_trashed",
    label: "Trashed comments",
    description: "Permanently delete trashed comments.",
  },
  {
    key: "db_transients_expired",
    label: "Expired transients",
    description: "Clear expired transient cache entries.",
  },
  {
    key: "db_optimize_tables",
    label: "Optimize tables",
    description: "Run OPTIMIZE TABLE to reclaim space after cleanup.",
  },
];

// Auto-clear the completed state after this delay.
const AUTO_CLEAR_COMPLETED_MS = 8_000;
// Stale backstop: if no frame arrives for 3 min while running, auto-reset.
const STALE_TIMEOUT_MS = 180_000;

export interface DatabaseSectionProps {
  siteId: string;
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  isSaving: (key: string) => boolean;
  /** operator+ — can run scan and clean. */
  canOperate: boolean;
}

export function DatabaseSection({
  siteId,
  config,
  save,
  disabled,
  isSaving,
  canOperate,
}: DatabaseSectionProps) {
  // Top-level section tab: "cleanup" (existing flow) | "health" (P3.6)
  const [sectionTab, setSectionTab] = useState<"cleanup" | "health">("cleanup");

  const scan = useDbScan(siteId);
  const cleanSelected = useDbCleanSelected(siteId);
  const scanLive = useDbScanStore((s) => selectDbScan(s, siteId));
  const cleanLive = useDbCleanStore((s) => selectDbClean(s, siteId));
  const resetScan = useDbScanStore((s) => s.reset);
  const resetClean = useDbCleanStore((s) => s.reset);

  // Categories the operator has DESELECTED in the preview table (inverted set).
  // Default state is "all selected" — the deselected set starts empty.
  // When a new scan completes (job_id changes) we reset the deselected set.
  const [deselectedCategories, setDeselectedCategories] = useState<Set<string>>(new Set());
  const [lastScanJobId, setLastScanJobId] = useState<string | null>(null);

  // Active view tab within the scan result ("categories" | "tables").
  // Defaults to "categories" — the actionable cleanup view.
  const [viewTab, setViewTab] = useState<"categories" | "tables">("categories");

  // Reset deselections (and view tab) when a new scan job completes.
  if (scanLive.phase === "completed" && scanLive.job_id !== lastScanJobId) {
    setLastScanJobId(scanLive.job_id);
    setDeselectedCategories(new Set());
    // Keep the user on whichever tab they were on between re-scans; only reset
    // to "categories" on the very first scan (lastScanJobId === null).
    if (lastScanJobId === null) setViewTab("categories");
  }

  // Derive the selected set from the scan result minus deselections.
  const availableCategories = scanLive.phase === "completed"
    ? new Set(Object.keys(scanLive.categories))
    : new Set<string>();
  const selectedCategories = new Set(
    [...availableCategories].filter((id) => !deselectedCategories.has(id)),
  );

  function toggleCategory(cat: string) {
    setDeselectedCategories((prev) => {
      const next = new Set(prev);
      if (next.has(cat)) {
        next.delete(cat);
      } else {
        next.add(cat);
      }
      return next;
    });
  }

  function toggleAllCategories(checked: boolean) {
    if (checked) {
      setDeselectedCategories(new Set());
    } else {
      setDeselectedCategories(new Set(availableCategories));
    }
  }

  // Auto-clear the clean completed state.
  useEffect(() => {
    if (cleanLive.phase !== "completed") return;
    const id = window.setTimeout(() => {
      resetClean(siteId);
      // After the clean completes, clear the scan preview too so the operator
      // sees the clean toggle list again and can re-scan to see fresh counts.
      resetScan(siteId);
    }, AUTO_CLEAR_COMPLETED_MS);
    return () => window.clearTimeout(id);
  }, [siteId, cleanLive.phase, resetClean, resetScan]);

  // Stale backstop while clean is running.
  useEffect(() => {
    if (cleanLive.phase !== "running") return;
    const id = window.setTimeout(() => resetClean(siteId), STALE_TIMEOUT_MS);
    return () => window.clearTimeout(id);
  }, [siteId, cleanLive.phase, cleanLive.updatedAt, resetClean]);

  function runScan() {
    scan.mutate();
  }

  function runCleanSelected() {
    const tasks = Array.from(selectedCategories);
    if (tasks.length === 0) {
      toast.error("No categories selected.", {
        description: "Select at least one category to clean.",
      });
      return;
    }
    cleanSelected.mutate(tasks, {
      onSuccess: (res) => {
        if (!res.ok) {
          toast.error("Could not clean the database.", {
            description: res.detail ?? "The agent refused the request.",
          });
        }
        // ok=true: SSE db.clean.started will arrive shortly.
      },
    });
  }

  // Determine what to show in the Cleanup tab:
  // 1. If a clean is running/completed/failed → live progress panel.
  // 2. Else if a scan has completed → preview table.
  // 3. Else (idle or scan in progress) → static settings list.

  const isCleanActive = cleanLive.phase !== null;
  const isScanCompleted = scanLive.phase === "completed" && !isCleanActive;
  const isScanFailed = scanLive.phase === "failed" && !isCleanActive;
  const isScanning = scanLive.phase === "scanning" || scan.isPending;
  const isCleanPending =
    cleanSelected.isPending || cleanLive.phase === "running";

  return (
    <div className="rounded-xl border border-border bg-card text-card-foreground shadow-sm overflow-hidden">
      {/* Section-level tab bar: Cleanup | Health */}
      <div className="flex items-center justify-between border-b border-border px-5 py-3">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">Database</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Cleanup scheduling and health monitoring
          </p>
        </div>
        <div
          role="tablist"
          aria-label="Database section view"
          className="flex items-center rounded-lg border border-border bg-muted/40 p-0.5"
        >
          <button
            type="button"
            role="tab"
            aria-selected={sectionTab === "cleanup"}
            onClick={() => setSectionTab("cleanup")}
            className={[
              "rounded-md px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              sectionTab === "cleanup"
                ? "bg-background text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            ].join(" ")}
          >
            Cleanup
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={sectionTab === "health"}
            onClick={() => setSectionTab("health")}
            className={[
              "rounded-md px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              sectionTab === "health"
                ? "bg-background text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            ].join(" ")}
          >
            Health
          </button>
        </div>
      </div>

      {/* ── Health tab ──────────────────────────────────────────────────── */}
      {sectionTab === "health" && (
        <div className="p-4">
          <DatabaseHealthView siteId={siteId} />
        </div>
      )}

      {/* ── Cleanup tab ─────────────────────────────────────────────────── */}
      {sectionTab === "cleanup" && (
        <>
          {/* Action slot */}
          {canOperate && (
            <div className="flex items-center justify-end gap-2 border-b border-border px-5 py-2">
              {/* Status badge — scan or clean phase */}
              {isCleanActive && <DbCleanStatusBadge live={cleanLive} />}
              {!isCleanActive && scanLive.phase !== null && (
                <DbScanStatusBadge live={scanLive} />
              )}

              {/* Primary action: scan (when idle or failed) or rescan (when preview shown) */}
              {!isCleanActive && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={runScan}
                  disabled={isScanning || isCleanPending}
                >
                  {isScanning ? (
                    <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                  ) : (
                    <ScanLine aria-hidden="true" className="size-4" />
                  )}
                  {isScanCompleted ? "Re-scan" : "Scan database"}
                </Button>
              )}

              {/* "Clean selected" — only when preview is shown */}
              {isScanCompleted && (
                <Button
                  type="button"
                  variant="default"
                  size="sm"
                  onClick={runCleanSelected}
                  disabled={isCleanPending || selectedCategories.size === 0}
                >
                  {isCleanPending ? (
                    <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                  ) : (
                    <Database aria-hidden="true" className="size-4" />
                  )}
                  Clean selected
                </Button>
              )}
            </div>
          )}

          {/* Priority 1: live clean progress panel */}
          {isCleanActive ? (
            <DbCleanProgressPanel live={cleanLive} />
          ) : isScanCompleted ? (
            /* Priority 2: scan result — Categories + Tables sub-tabs */
            <Tabs value={viewTab} onValueChange={(v) => setViewTab(v as "categories" | "tables")}>
              <TabsList
                aria-label="Database scan result view"
                className="px-5 pt-1"
              >
                <TabsTrigger value="categories">Categories</TabsTrigger>
                <TabsTrigger value="tables">
                  Tables
                  {scanLive.tables.length > 0 && (
                    <span className="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-xs tabular-nums text-muted-foreground">
                      {scanLive.tables.length}
                    </span>
                  )}
                </TabsTrigger>
              </TabsList>

              <TabsContent value="categories">
                <DbScanPreviewTable
                  live={scanLive}
                  selected={selectedCategories}
                  onToggle={toggleCategory}
                  onToggleAll={toggleAllCategories}
                />
              </TabsContent>

              <TabsContent value="tables">
                {scanLive.tables.length === 0 ? (
                  <div className="px-5 py-10 text-center text-xs text-muted-foreground">
                    Table inventory not available. Re-run the scan with agent 0.15.5 or later.
                  </div>
                ) : (
                  <DatabaseTableView
                    siteId={siteId}
                    tables={scanLive.tables}
                    onRequestRescan={runScan}
                  />
                )}
              </TabsContent>
            </Tabs>
          ) : isScanFailed ? (
            /* Priority 2b: scan failed inline message */
            <DbScanFailedRow
              detail={scanLive.failed_detail}
              onRetry={runScan}
              isRetrying={isScanning}
            />
          ) : (
            /* Priority 3: static settings (idle) */
            <div className="divide-y divide-border">
              <SettingRow
                label="Scheduled cleanup"
                description="Automatically run the selected cleanups on a schedule."
                checked={config.db_auto_clean}
                onChange={(v) => save({ db_auto_clean: v })}
                disabled={disabled || isSaving("db_auto_clean")}
                saving={isSaving("db_auto_clean")}
              >
                <SelectField
                  label="Cleanup interval"
                  value={config.db_auto_clean_interval}
                  options={DB_CLEAN_INTERVALS}
                  onChange={(v) => save({ db_auto_clean_interval: v })}
                  disabled={disabled}
                />
              </SettingRow>
              {TOGGLES.map((t) => (
                <SettingRow
                  key={t.key}
                  label={t.label}
                  description={t.description}
                  checked={Boolean(config[t.key])}
                  onChange={(v) => save({ [t.key]: v })}
                  disabled={disabled || isSaving(t.key)}
                  saving={isSaving(t.key)}
                />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Scan status badge (header slot companion)
// ---------------------------------------------------------------------------

function DbScanStatusBadge({ live }: { live: DbScanLive }) {
  if (live.phase === "scanning") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
      >
        <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
        Scanning…
      </span>
    );
  }
  if (live.phase === "completed") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
      >
        <CheckCircle2
          aria-hidden="true"
          className="size-3.5 text-green-600 dark:text-green-400"
        />
        Scan complete
      </span>
    );
  }
  if (live.phase === "failed") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs"
      >
        <XCircle
          aria-hidden="true"
          className="size-3.5 text-red-600 dark:text-red-400"
        />
        <span className="text-red-700 dark:text-red-400">Scan failed</span>
      </span>
    );
  }
  return null;
}

// ---------------------------------------------------------------------------
// Clean status badge (header slot companion)
// ---------------------------------------------------------------------------

function DbCleanStatusBadge({ live }: { live: DbCleanLive }) {
  if (live.phase === "running") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
      >
        <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
        Cleaning…
      </span>
    );
  }
  if (live.phase === "completed") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs"
      >
        <CheckCircle2
          aria-hidden="true"
          className="size-3.5 text-green-600 dark:text-green-400"
        />
        <span className="text-foreground">
          {live.rows_deleted_total > 0
            ? `${live.rows_deleted_total.toLocaleString()} rows removed`
            : "Done"}
        </span>
      </span>
    );
  }
  if (live.phase === "failed") {
    return (
      <span
        role="status"
        aria-live="polite"
        className="inline-flex items-center gap-1.5 text-xs"
      >
        <XCircle
          aria-hidden="true"
          className="size-3.5 text-red-600 dark:text-red-400"
        />
        <span className="text-red-700 dark:text-red-400">Failed</span>
      </span>
    );
  }
  return null;
}

// ---------------------------------------------------------------------------
// Scan preview table — shown after db.scan.completed
// ---------------------------------------------------------------------------

interface DbScanPreviewTableProps {
  live: DbScanLive;
  selected: Set<string>;
  onToggle: (categoryId: string) => void;
  onToggleAll: (checked: boolean) => void;
}

function DbScanPreviewTable({
  live,
  selected,
  onToggle,
  onToggleAll,
}: DbScanPreviewTableProps) {
  const orderedCats = DB_SCAN_CATEGORY_IDS.filter(
    (id) => id in live.categories,
  );

  // Compute totals.
  let totalCount = 0;
  let totalBytes = 0;
  for (const id of orderedCats) {
    const cat = live.categories[id];
    if (!cat) continue;
    totalCount += cat.count;
    totalBytes += cat.bytes;
  }

  const allChecked =
    orderedCats.length > 0 && orderedCats.every((id) => selected.has(id));
  const someChecked = !allChecked && orderedCats.some((id) => selected.has(id));

  return (
    <div
      role="region"
      aria-label="Database scan preview"
      className="divide-y divide-border"
    >
      {/* Summary row */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span>
            <span className="font-medium text-foreground">
              {live.table_count.toLocaleString()}
            </span>{" "}
            tables
          </span>
          <span>
            <span className="font-medium text-foreground">
              {formatBytes(live.db_size_bytes)}
            </span>{" "}
            total DB size
          </span>
          {totalBytes > 0 && (
            <span>
              <span className="font-medium text-green-700 dark:text-green-400">
                {formatBytes(totalBytes)}
              </span>{" "}
              reclaimable (selected)
            </span>
          )}
        </div>
        <span className="text-xs text-muted-foreground">
          {totalCount > 0
            ? `${totalCount.toLocaleString()} items found`
            : "No items found"}
        </span>
      </div>

      {/* Header row with select-all */}
      <div className="flex items-center gap-3 px-5 py-2 bg-muted/40">
        <Checkbox
          id="db-scan-select-all"
          checked={allChecked}
          ref={(el) => {
            if (el) el.indeterminate = someChecked;
          }}
          onChange={(e) => onToggleAll(e.target.checked)}
          aria-label="Select all categories"
        />
        <label
          htmlFor="db-scan-select-all"
          className="flex-1 text-xs font-medium text-muted-foreground cursor-pointer"
        >
          Category
        </label>
        <span className="w-16 text-right text-xs font-medium text-muted-foreground">
          Items
        </span>
        <span className="w-20 text-right text-xs font-medium text-muted-foreground">
          Savings
        </span>
      </div>

      {/* Per-category rows */}
      {orderedCats.length === 0 ? (
        <div className="px-5 py-6 text-center text-xs text-muted-foreground">
          No categories found in scan results.
        </div>
      ) : (
        orderedCats.map((id) => {
          const cat = live.categories[id];
          if (!cat) return null;
          return (
            <ScanPreviewRow
              key={id}
              categoryId={id}
              result={cat}
              checked={selected.has(id)}
              onToggle={() => onToggle(id)}
            />
          );
        })
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// One row in the scan preview table
// ---------------------------------------------------------------------------

interface ScanPreviewRowProps {
  categoryId: string;
  result: DbScanCategoryResult;
  checked: boolean;
  onToggle: () => void;
}

function ScanPreviewRow({
  categoryId,
  result,
  checked,
  onToggle,
}: ScanPreviewRowProps) {
  const label = CATEGORY_LABELS[categoryId] ?? categoryId;
  const checkboxId = `db-scan-cat-${categoryId}`;

  return (
    <div className="flex items-center gap-3 px-5 py-2.5 hover:bg-muted/30 transition-colors">
      <Checkbox
        id={checkboxId}
        checked={checked}
        onChange={onToggle}
        aria-label={`Include ${label} in cleanup`}
        disabled={result.count === 0}
      />
      <label
        htmlFor={checkboxId}
        className="flex-1 min-w-0 cursor-pointer"
      >
        <span className="block text-xs text-foreground truncate">{label}</span>
        {categoryId === "optimize_tables" &&
          result.tables &&
          result.tables.length > 0 && (
            <span className="block text-xs text-muted-foreground truncate">
              {result.tables
                .map((t) => `${t.name} (${t.engine})`)
                .join(", ")}
            </span>
          )}
        {result.capped === true && (
          <span className="text-xs text-muted-foreground">(estimate)</span>
        )}
      </label>
      <span className="w-16 text-right tabular-nums text-xs text-muted-foreground">
        {result.count > 0
          ? result.capped
            ? `${result.count.toLocaleString()}+`
            : result.count.toLocaleString()
          : "–"}
      </span>
      <span className="w-20 text-right tabular-nums text-xs">
        {result.bytes > 0 ? (
          <span className="text-green-700 dark:text-green-400 font-medium">
            {formatBytes(result.bytes)}
          </span>
        ) : (
          <span className="text-muted-foreground">–</span>
        )}
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Scan failed inline row
// ---------------------------------------------------------------------------

interface DbScanFailedRowProps {
  detail: string | null;
  onRetry: () => void;
  isRetrying: boolean;
}

function DbScanFailedRow({ detail, onRetry, isRetrying }: DbScanFailedRowProps) {
  return (
    <div className="flex items-center justify-between gap-4 px-5 py-4">
      <div className="flex items-center gap-2">
        <XCircle
          aria-hidden="true"
          className="size-4 shrink-0 text-red-600 dark:text-red-400"
        />
        <span className="text-xs text-red-700 dark:text-red-400">
          {detail ?? "Scan failed — the agent did not respond."}
        </span>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onRetry}
        disabled={isRetrying}
      >
        {isRetrying ? (
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
        ) : (
          <RotateCcw aria-hidden="true" className="size-4" />
        )}
        Retry
      </Button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Live clean progress panel — replaces the static toggle list while a job is active
// ---------------------------------------------------------------------------

function DbCleanProgressPanel({ live }: { live: DbCleanLive }) {
  // Build the ordered list: tasks from the started event first, then any
  // unexpected categories that arrived in progress pushes.
  const seen = new Set<string>();
  const ordered: string[] = [];
  for (const cat of live.tasks) {
    if (!seen.has(cat)) { seen.add(cat); ordered.push(cat); }
  }
  for (const cat of Object.keys(live.categories)) {
    if (!seen.has(cat)) { seen.add(cat); ordered.push(cat); }
  }

  const isFailed = live.phase === "failed";
  const isCompleted = live.phase === "completed";

  return (
    <div
      role="status"
      aria-live="polite"
      aria-label="Database cleanup progress"
      className="divide-y divide-border"
    >
      {/* Summary row — totals when done, or running indicator */}
      <div className="flex items-center justify-between px-5 py-3">
        <span className="text-xs font-medium text-foreground">
          {isFailed
            ? live.failed_detail ?? "Cleanup failed"
            : isCompleted
            ? `Finished — ${live.rows_deleted_total.toLocaleString()} rows removed`
            : "Cleanup running…"}
        </span>
        {isCompleted && live.bytes_freed_total > 0 && (
          <span className="text-xs text-muted-foreground">
            {formatBytes(live.bytes_freed_total)} freed
          </span>
        )}
      </div>

      {/* Per-category rows */}
      {ordered.length > 0 ? (
        ordered.map((cat) => {
          const result = live.categories[cat];
          return (
            <CategoryRow
              key={cat}
              category={cat}
              result={result}
              isJobRunning={live.phase === "running"}
            />
          );
        })
      ) : (
        // Job started but no tasks listed yet — show a generic spinner.
        <div className="flex items-center gap-2 px-5 py-3">
          <Loader2
            aria-hidden="true"
            className="size-3.5 animate-spin text-muted-foreground"
          />
          <span className="text-xs text-muted-foreground">Starting…</span>
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// One row in the progress panel
// ---------------------------------------------------------------------------

interface CategoryRowProps {
  category: string;
  result: CategoryProgress | undefined;
  isJobRunning: boolean;
}

function CategoryRow({ category, result, isJobRunning }: CategoryRowProps) {
  const label = CATEGORY_LABELS[category] ?? category;

  return (
    <div className="flex items-center justify-between gap-4 px-5 py-2.5">
      <div className="flex min-w-0 items-center gap-2">
        <CategoryIcon result={result} isJobRunning={isJobRunning} />
        <span className="truncate text-xs text-foreground">{label}</span>
        {result?.detail && (
          <span className="truncate text-xs text-muted-foreground">
            ({result.detail})
          </span>
        )}
      </div>
      <div className="shrink-0 text-right">
        {result && result.state === "done" && (
          <span className="text-xs tabular-nums text-muted-foreground">
            {result.rows_deleted > 0
              ? `${result.rows_deleted.toLocaleString()} rows`
              : "0 rows"}
            {result.bytes_freed > 0
              ? ` · ${formatBytes(result.bytes_freed)}`
              : ""}
          </span>
        )}
      </div>
    </div>
  );
}

function CategoryIcon({
  result,
  isJobRunning,
}: {
  result: CategoryProgress | undefined;
  isJobRunning: boolean;
}) {
  if (!result) {
    // No result yet — pending or not started
    if (isJobRunning) {
      return (
        <Loader2
          aria-hidden="true"
          className="size-3.5 shrink-0 animate-spin text-muted-foreground"
        />
      );
    }
    return (
      <span
        aria-hidden="true"
        className="size-3.5 shrink-0 rounded-full border border-border"
      />
    );
  }
  if (result.state === "done") {
    return (
      <CheckCircle2
        aria-hidden="true"
        className="size-3.5 shrink-0 text-green-600 dark:text-green-400"
      />
    );
  }
  if (result.state === "skipped") {
    return (
      <SkipForward
        aria-hidden="true"
        className="size-3.5 shrink-0 text-muted-foreground"
      />
    );
  }
  // error
  return (
    <XCircle
      aria-hidden="true"
      className="size-3.5 shrink-0 text-red-600 dark:text-red-400"
    />
  );
}
