// OrphanReviewSection — classified orphan review with P3.8 delete affordances.
//
// Layout:
//   1. Snapshot notice banner (when snapshot_available=false — disables ALL delete).
//   2. Three collapsible groups: Options / Scheduled tasks / Tables.
//      Each group header shows a summary + "Delete selected (N)" action button
//      when eligible items are selected within that group.
//   3. Rows with deletable_eligible=true get a checkbox (default unchecked).
//      installed / heuristic / unknown / ambiguous rows have NO checkbox and
//      are visually de-emphasized.
//   4. "Delete selected (N)" opens a type-to-confirm dialog matching the
//      DestructiveConfirmDialog pattern from DatabaseTableView.tsx.
//   5. SSE events (db.orphan.delete.*) drive an in-progress state + refetch.

import { useState, useCallback } from "react";
import {
  ChevronDown,
  ChevronRight,
  AlertCircle,
  Info,
  Trash2,
  Loader2,
  AlertTriangle,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { toast } from "@/components/toast";
import { useSiteEvents, type SiteEvent } from "@/features/sites/use-site-events";

import type { OrphanItem, OrphanConfidence, OrphansReport } from "../types";
import { formatBytes } from "../format";
import {
  useOrphanDelete,
  computeConfirmToken,
  type OrphanDeleteItem,
  type OrphanItemKind,
} from "../hooks/useOrphanDelete";

// ---------------------------------------------------------------------------
// Confidence badge styling
// ---------------------------------------------------------------------------

const CONFIDENCE_CLASS: Record<OrphanConfidence, string> = {
  exact:
    "bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-800",
  prefix:
    "bg-violet-100 text-violet-800 border-violet-200 dark:bg-violet-950 dark:text-violet-300 dark:border-violet-800",
  heuristic: "text-muted-foreground bg-muted border-transparent",
  unknown: "text-muted-foreground bg-transparent border-border",
};

const CONFIDENCE_LABEL: Record<OrphanConfidence, string> = {
  exact: "exact",
  prefix: "prefix",
  heuristic: "heuristic",
  unknown: "unknown",
};

function ConfidenceBadge({ confidence }: { confidence: OrphanConfidence }) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${CONFIDENCE_CLASS[confidence]}`}
      aria-label={`Attribution confidence: ${CONFIDENCE_LABEL[confidence]}`}
    >
      {CONFIDENCE_LABEL[confidence]}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Non-eligible reason — explicit, text-based, screen-reader friendly
// ---------------------------------------------------------------------------

/**
 * Returns a human-readable reason string for why a non-eligible item has no
 * delete checkbox. Returns null for eligible items (they get a checkbox instead).
 */
function nonEligibleReason(item: OrphanItem): string | null {
  if (item.deletable_eligible) return null;

  // Defensive: installed items should no longer appear after Track 1 filters
  // them server-side, but guard anyway.
  if (item.installed) return "Owner is installed — not an orphan";

  if (item.known_plugins !== undefined && item.known_plugins.length > 1) {
    return "Matches multiple plugins — not auto-removable";
  }

  if (
    item.confidence === "unknown" ||
    item.confidence === "heuristic"
  ) {
    return "Owner unknown — not auto-removable";
  }

  // Fallback for any other gate (e.g. snapshot_available=false)
  return "Not eligible for automatic removal";
}

// ---------------------------------------------------------------------------
// State chip: "still installed" | "ambiguous" | "eligible to remove" | "orphan"
// ---------------------------------------------------------------------------

function StateChip({ item }: { item: OrphanItem }) {
  if (item.installed) {
    return (
      <span className="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
        still installed
      </span>
    );
  }

  const isAmbiguous =
    item.known_plugins !== undefined && item.known_plugins.length > 1;
  if (isAmbiguous) {
    return (
      <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
        ambiguous
      </span>
    );
  }

  if (item.deletable_eligible) {
    return (
      <span className="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">
        eligible to remove
      </span>
    );
  }

  return (
    <span className="inline-flex items-center rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
      orphan
    </span>
  );
}

// ---------------------------------------------------------------------------
// Row components — one per category, each accepting checkbox props
// ---------------------------------------------------------------------------

interface RowProps {
  item: OrphanItem;
  selectable: boolean;
  selected: boolean;
  onToggle: () => void;
  isBusy: boolean;
}

function OptionRow({ item, selectable, selected, onToggle, isBusy }: RowProps) {
  const dimmed = !item.deletable_eligible || item.installed;
  const reason = nonEligibleReason(item);
  return (
    <div
      className={`flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 ${dimmed ? "opacity-50" : ""}`}
    >
      {/* Checkbox — only rendered for selectable (deletable_eligible) rows */}
      <div className="w-5 shrink-0">
        {selectable && (
          <Checkbox
            checked={selected}
            onChange={onToggle}
            disabled={isBusy}
            aria-label={`Select ${item.name} for deletion`}
          />
        )}
      </div>

      {/* Name */}
      <span
        className="min-w-0 flex-1 truncate font-mono text-xs text-foreground"
        title={item.name}
      >
        {item.name}
      </span>

      {/* Metadata strip */}
      <div className="flex shrink-0 flex-wrap items-center gap-1.5">
        {item.autoload === true && (
          <Badge variant="outline" className="py-0 text-[10px] font-normal">
            autoload
          </Badge>
        )}
        {item.size_bytes !== undefined && item.size_bytes > 0 && (
          <span className="tabular-nums text-xs text-muted-foreground">
            {formatBytes(item.size_bytes)}
          </span>
        )}
        {item.owner_slug ? (
          <span className="max-w-[96px] truncate text-xs text-muted-foreground">
            {item.owner_slug}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">unknown owner</span>
        )}
        <ConfidenceBadge confidence={item.confidence} />
        <StateChip item={item} />
        {reason !== null && (
          <span className="text-[10px] text-muted-foreground" aria-label={reason}>
            {reason}
          </span>
        )}
      </div>
    </div>
  );
}

function CronRow({ item, selectable, selected, onToggle, isBusy }: RowProps) {
  const dimmed = !item.deletable_eligible || item.installed;
  const reason = nonEligibleReason(item);
  const nextRun =
    item.next_run_at !== undefined
      ? new Date(item.next_run_at * 1000).toLocaleString(undefined, {
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        })
      : null;

  return (
    <div
      className={`flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 ${dimmed ? "opacity-50" : ""}`}
    >
      <div className="w-5 shrink-0">
        {selectable && (
          <Checkbox
            checked={selected}
            onChange={onToggle}
            disabled={isBusy}
            aria-label={`Select ${item.name} for deletion`}
          />
        )}
      </div>

      <span
        className="min-w-0 flex-1 truncate font-mono text-xs text-foreground"
        title={item.name}
      >
        {item.name}
      </span>

      <div className="flex shrink-0 flex-wrap items-center gap-1.5">
        {item.recurrence && (
          <Badge variant="outline" className="py-0 text-[10px] font-normal">
            {item.recurrence}
          </Badge>
        )}
        {nextRun && (
          <span
            className="text-xs text-muted-foreground"
            title="Next scheduled run"
          >
            next {nextRun}
          </span>
        )}
        {item.owner_slug ? (
          <span className="max-w-[96px] truncate text-xs text-muted-foreground">
            {item.owner_slug}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">unknown owner</span>
        )}
        <ConfidenceBadge confidence={item.confidence} />
        <StateChip item={item} />
        {reason !== null && (
          <span className="text-[10px] text-muted-foreground" aria-label={reason}>
            {reason}
          </span>
        )}
      </div>
    </div>
  );
}

function TableRow({ item, selectable, selected, onToggle, isBusy }: RowProps) {
  const dimmed = !item.deletable_eligible || item.installed;
  const reason = nonEligibleReason(item);
  return (
    <div
      className={`flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 ${dimmed ? "opacity-50" : ""}`}
    >
      <div className="w-5 shrink-0">
        {selectable && (
          <Checkbox
            checked={selected}
            onChange={onToggle}
            disabled={isBusy}
            aria-label={`Select ${item.name} for deletion`}
          />
        )}
      </div>

      <span
        className="min-w-0 flex-1 truncate font-mono text-xs text-foreground"
        title={item.name}
      >
        {item.name}
      </span>

      <div className="flex shrink-0 flex-wrap items-center gap-1.5">
        {item.rows !== undefined && (
          <span className="tabular-nums text-xs text-muted-foreground">
            {item.rows.toLocaleString()} rows
          </span>
        )}
        {item.size_bytes !== undefined && item.size_bytes > 0 && (
          <span className="tabular-nums text-xs text-muted-foreground">
            {formatBytes(item.size_bytes)}
          </span>
        )}
        {item.owner_slug ? (
          <span className="max-w-[96px] truncate text-xs text-muted-foreground">
            {item.owner_slug}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">unknown owner</span>
        )}
        <ConfidenceBadge confidence={item.confidence} />
        <StateChip item={item} />
        {reason !== null && (
          <span className="text-[10px] text-muted-foreground" aria-label={reason}>
            {reason}
          </span>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Orphan confirm dialog
//
// Reuses the same type-to-confirm pattern as DestructiveConfirmDialog in
// DatabaseTableView.tsx. Red styling only (all orphan deletes are destructive).
// ---------------------------------------------------------------------------

interface OrphanConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  itemCount: number;
  expectedToken: string;
  backupWarning: string | null;
  isPending: boolean;
  typed: string;
  onTypedChange: (v: string) => void;
}

function OrphanConfirmDialog({
  open,
  onClose,
  onConfirm,
  itemCount,
  expectedToken,
  backupWarning,
  isPending,
  typed,
  onTypedChange,
}: OrphanConfirmDialogProps) {
  const dialogId = "orphan-delete-confirm";
  const matches = typed === expectedToken;
  const plural = itemCount !== 1;

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent
        ariaLabelledBy={`${dialogId}-title`}
        ariaDescribedBy={`${dialogId}-desc`}
      >
        <DialogHeader>
          <DialogTitle
            id={`${dialogId}-title`}
            className="flex items-center gap-2"
          >
            <Trash2
              aria-hidden="true"
              className="size-4 text-destructive"
            />
            Delete orphaned {plural ? "items" : "item"}
          </DialogTitle>
          <DialogDescription id={`${dialogId}-desc`}>
            This will permanently delete{" "}
            <strong>
              {itemCount} orphaned {plural ? "items" : "item"}
            </strong>{" "}
            (options, scheduled tasks, or tables) that are no longer associated
            with any installed plugin. This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        {backupWarning !== null && (
          <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-800 dark:bg-amber-950/40">
            <AlertTriangle
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <p className="text-xs text-amber-800 dark:text-amber-300">
              {backupWarning}
            </p>
          </div>
        )}

        {/* Backup nudge — shown when no backup warning from server */}
        {backupWarning === null && (
          <div className="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-800 dark:bg-amber-950/40">
            <AlertTriangle
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <p className="text-xs text-amber-800 dark:text-amber-300">
              Consider running a database backup before deleting orphaned data.
              Orphan deletion is permanent and the agent will not recreate these
              items.
            </p>
          </div>
        )}

        <div className="mt-4 space-y-2">
          <label
            className="block text-xs font-medium text-foreground"
            htmlFor={`${dialogId}-input`}
          >
            Type{" "}
            <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">
              {expectedToken}
            </code>{" "}
            to confirm
          </label>
          <Input
            id={`${dialogId}-input`}
            data-autofocus
            type="text"
            value={typed}
            onChange={(e) => onTypedChange(e.target.value)}
            placeholder={expectedToken}
            className="font-mono text-xs"
            aria-label={`Type ${expectedToken} to confirm deletion`}
          />
        </div>

        <DialogFooter className="mt-5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onClose}
            disabled={isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="default"
            size="sm"
            onClick={onConfirm}
            disabled={!matches || isPending}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            {isPending ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : (
              <Trash2 aria-hidden="true" className="size-4" />
            )}
            Delete
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// In-progress banner (shown while the async job is running)
// ---------------------------------------------------------------------------

interface InProgressBannerProps {
  deletedOptions: number;
  deletedCron: number;
  deletedTables: number;
  skipped: number;
}

function InProgressBanner({
  deletedOptions,
  deletedCron,
  deletedTables,
  skipped,
}: InProgressBannerProps) {
  const total = deletedOptions + deletedCron + deletedTables;
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-800 dark:bg-blue-950/30"
    >
      <Loader2
        aria-hidden="true"
        className="size-4 shrink-0 animate-spin text-blue-600 dark:text-blue-400"
      />
      <div className="min-w-0">
        <p className="text-xs font-medium text-blue-800 dark:text-blue-300">
          Deletion in progress
        </p>
        <p className="mt-0.5 text-xs text-blue-700 dark:text-blue-400">
          {total} deleted so far
          {skipped > 0 ? ` — ${skipped} skipped (re-verified as safe)` : ""}
        </p>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Generic collapsible group with per-group selection + delete action
// ---------------------------------------------------------------------------

interface OrphanGroupProps {
  title: string;
  kind: OrphanItemKind;
  items: OrphanItem[];
  defaultOpen?: boolean;
  renderRow: (
    item: OrphanItem,
    selectable: boolean,
    selected: boolean,
    onToggle: () => void,
  ) => React.ReactNode;
  emptyLabel: string;
  /** Names of eligible items currently selected in this group. */
  selectedNames: Set<string>;
  onToggleItem: (kind: OrphanItemKind, name: string) => void;
  onDeleteGroup: (kind: OrphanItemKind) => void;
  canDelete: boolean;
  isBusy: boolean;
}

function OrphanGroup({
  title,
  kind,
  items,
  defaultOpen = true,
  renderRow,
  emptyLabel,
  selectedNames,
  onToggleItem,
  onDeleteGroup,
  canDelete,
  isBusy,
}: OrphanGroupProps) {
  const [open, setOpen] = useState(defaultOpen);

  const eligibleItems = items.filter(
    (i) => i.deletable_eligible && !i.installed,
  );
  const eligibleCount = eligibleItems.length;
  const selectedCount = eligibleItems.filter((i) =>
    selectedNames.has(i.name),
  ).length;

  const allEligibleSelected =
    eligibleCount > 0 && selectedCount === eligibleCount;
  const someEligibleSelected = selectedCount > 0 && !allEligibleSelected;

  function toggleSelectAll() {
    if (allEligibleSelected) {
      // Deselect all in this group
      for (const item of eligibleItems) {
        if (selectedNames.has(item.name)) onToggleItem(kind, item.name);
      }
    } else {
      // Select all eligible in this group
      for (const item of eligibleItems) {
        if (!selectedNames.has(item.name)) onToggleItem(kind, item.name);
      }
    }
  }

  const selectAllId = `orphan-group-select-all-${kind}`;

  return (
    <section
      aria-label={title}
      className="border-b border-border last:border-b-0"
    >
      {/* Group header */}
      <div className="flex items-center gap-2 px-4 py-3">
        {/* Select-all checkbox for eligible items in this group */}
        {eligibleCount > 0 && canDelete && (
          <div className="shrink-0">
            <Checkbox
              id={selectAllId}
              checked={allEligibleSelected}
              ref={(el) => {
                if (el) el.indeterminate = someEligibleSelected;
              }}
              onChange={toggleSelectAll}
              disabled={isBusy}
              aria-label={
                allEligibleSelected
                  ? `Deselect all ${eligibleCount} eligible ${title.toLowerCase()}`
                  : `Select all ${eligibleCount} eligible ${title.toLowerCase()}`
              }
            />
          </div>
        )}

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          className="flex flex-1 items-center gap-2 text-left transition-colors hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
        >
          {open ? (
            <ChevronDown
              aria-hidden="true"
              className="size-3.5 shrink-0 text-muted-foreground"
            />
          ) : (
            <ChevronRight
              aria-hidden="true"
              className="size-3.5 shrink-0 text-muted-foreground"
            />
          )}
          <span className="flex-1 text-xs font-semibold text-foreground">
            {title}
          </span>
          <span className="tabular-nums text-xs text-muted-foreground">
            {items.length.toLocaleString()} found
          </span>
          {eligibleCount > 0 && (
            <span className="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-950/40 dark:text-red-400">
              {eligibleCount} eligible
            </span>
          )}
        </button>

        {/* Delete selected button — only shown when items are selected */}
        {selectedCount > 0 && canDelete && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onDeleteGroup(kind)}
            disabled={isBusy}
            className="ml-2 shrink-0 border-destructive/30 text-destructive hover:border-destructive/60 hover:text-destructive"
            aria-label={`Delete ${selectedCount} selected ${title.toLowerCase()}`}
          >
            {isBusy ? (
              <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
            ) : (
              <Trash2 aria-hidden="true" className="size-3.5" />
            )}
            Delete selected ({selectedCount})
          </Button>
        )}
      </div>

      {/* Item list */}
      {open && (
        <div className="divide-y divide-border">
          {items.length === 0 ? (
            <div className="px-5 py-6 text-center text-xs text-muted-foreground">
              {emptyLabel}
            </div>
          ) : (
            items.map((item) => {
              const selectable =
                canDelete && item.deletable_eligible && !item.installed;
              const selected = selectedNames.has(item.name);
              return (
                <div key={item.name}>
                  {renderRow(item, selectable, selected, () =>
                    onToggleItem(kind, item.name),
                  )}
                </div>
              );
            })
          )}
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------
// Snapshot notice banner
// ---------------------------------------------------------------------------

function SnapshotNoticeBanner() {
  return (
    <div
      role="status"
      className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30"
    >
      <Info
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
      />
      <div className="min-w-0">
        <p className="text-xs font-medium text-amber-800 dark:text-amber-300">
          Ownership attribution requires a fresh scan
        </p>
        <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
          This scan was produced by an older agent version that did not include
          the installed-plugin snapshot. Orphan counts are shown, but no items
          are marked eligible. Update the agent and re-run the scan to enable
          full attribution.
        </p>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Hidden-installed note: shown when the server excluded installed-owner items
// ---------------------------------------------------------------------------

function HiddenInstalledNote({ count }: { count: number }) {
  if (count <= 0) return null;
  return (
    <div
      role="note"
      className="flex items-start gap-3 rounded-lg border border-border bg-muted/50 px-4 py-3"
    >
      <Info
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
      />
      <p className="text-xs text-muted-foreground">
        <span className="font-medium text-foreground">
          {count} {count === 1 ? "item" : "items"}
        </span>{" "}
        {count === 1 ? "was" : "were"} attributed to installed plugins and
        hidden — {count === 1 ? "it is" : "they are"} not orphaned.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Empty state: no scan yet
// ---------------------------------------------------------------------------

function NoScanEmptyState() {
  return (
    <div className="flex flex-col items-center gap-2 px-5 py-12 text-center">
      <AlertCircle
        aria-hidden="true"
        className="size-8 text-muted-foreground/40"
      />
      <p className="text-sm font-medium text-foreground">No scan yet</p>
      <p className="max-w-xs text-xs text-muted-foreground">
        Run a database scan from the cleanup section to generate an orphan
        report for this site.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Empty state: scan ran but zero orphans across all categories
// ---------------------------------------------------------------------------

function ZeroOrphansEmptyState({ hiddenInstalled }: { hiddenInstalled: number }) {
  return (
    <div className="flex flex-col items-center gap-2 px-5 py-12 text-center">
      <span
        aria-hidden="true"
        className="flex size-8 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400"
      >
        <svg
          viewBox="0 0 16 16"
          fill="none"
          className="size-4"
          aria-hidden="true"
        >
          <path
            d="M3 8l3 3 7-7"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </span>
      <p className="text-sm font-medium text-foreground">
        No orphaned items found
      </p>
      <p className="max-w-xs text-xs text-muted-foreground">
        The last scan found no orphaned options, cron events, or tables.
        {hiddenInstalled > 0 && (
          <> {hiddenInstalled} {hiddenInstalled === 1 ? "item was" : "items were"} attributed to installed plugins and excluded from this list.</>
        )}
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// SSE progress state for this component
// ---------------------------------------------------------------------------

interface OrphanDeleteJobState {
  jobId: string;
  acceptedCount: number;
  deletedOptions: number;
  deletedCron: number;
  deletedTables: number;
  skipped: number;
  phase: "running" | "completed" | "failed";
  failDetail?: string;
}

function asNum(v: unknown): number {
  return typeof v === "number" ? v : 0;
}
function asStr(v: unknown): string {
  return typeof v === "string" ? v : "";
}

// ---------------------------------------------------------------------------
// Main export
// ---------------------------------------------------------------------------

export interface OrphanReviewSectionProps {
  siteId: string;
  /** Full orphan report, or null when no scan has run yet. */
  report: OrphansReport | null;
}

export function OrphanReviewSection({
  siteId,
  report,
}: OrphanReviewSectionProps) {
  // ---------------------------------------------------------------------------
  // Selection state — keyed by "kind:name" to avoid collisions across groups
  // ---------------------------------------------------------------------------
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set());

  function toggleItem(kind: OrphanItemKind, name: string) {
    const key = `${kind}:${name}`;
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  // Selected names per group (for rendering and building the request).
  function selectedNamesForKind(kind: OrphanItemKind): Set<string> {
    const result = new Set<string>();
    for (const key of selectedKeys) {
      if (key.startsWith(`${kind}:`)) {
        result.add(key.slice(kind.length + 1));
      }
    }
    return result;
  }

  // ---------------------------------------------------------------------------
  // Confirm dialog state
  // ---------------------------------------------------------------------------
  const [dialog, setDialog] = useState<{
    open: boolean;
    items: OrphanDeleteItem[];
    expectedToken: string;
    backupWarning: string | null;
  }>({
    open: false,
    items: [],
    expectedToken: "",
    backupWarning: null,
  });
  const [typed, setTyped] = useState("");

  // ---------------------------------------------------------------------------
  // Async job state (driven by SSE)
  // ---------------------------------------------------------------------------
  const [activeJob, setActiveJob] = useState<OrphanDeleteJobState | null>(null);

  // ---------------------------------------------------------------------------
  // SSE subscription for db.orphan.delete.* events
  // ---------------------------------------------------------------------------
  const handleSiteEvent = useCallback(
    (ev: SiteEvent) => {
      if (ev.site_id !== siteId) return;

      const data =
        typeof ev.data === "object" && ev.data !== null
          ? (ev.data as Record<string, unknown>)
          : {};

      if (ev.type === "db.orphan.delete.started") {
        const jobId = asStr(data.job_id);
        const acceptedCount = asNum(data.accepted_count);
        if (jobId) {
          setActiveJob({
            jobId,
            acceptedCount,
            deletedOptions: 0,
            deletedCron: 0,
            deletedTables: 0,
            skipped: 0,
            phase: "running",
          });
          // Clear selection now that the job is in flight
          setSelectedKeys(new Set());
        }
        return;
      }

      if (
        ev.type === "db.orphan.delete.progress" ||
        ev.type === "db.orphan.delete.completed"
      ) {
        const jobId = asStr(data.job_id);
        setActiveJob((prev) => {
          if (!prev || prev.jobId !== jobId) return prev;
          const next: OrphanDeleteJobState = {
            ...prev,
            deletedOptions: asNum(data.deleted_options),
            deletedCron: asNum(data.deleted_cron),
            deletedTables: asNum(data.deleted_tables),
            skipped: asNum(data.skipped),
            phase:
              ev.type === "db.orphan.delete.completed" ? "completed" : "running",
          };
          return next;
        });

        if (ev.type === "db.orphan.delete.completed") {
          const deleted =
            asNum(data.deleted_options) +
            asNum(data.deleted_cron) +
            asNum(data.deleted_tables);
          const skipped = asNum(data.skipped);
          const extra =
            skipped > 0
              ? ` (${skipped} re-verified as safe and skipped)`
              : "";
          toast.success("Orphaned items deleted.", {
            description: `Removed ${deleted} item${deleted === 1 ? "" : "s"}${extra}.`,
          });
          // Clear the job banner after a short delay so it doesn't flash away
          setTimeout(() => setActiveJob(null), 3000);
        }
        return;
      }

      if (ev.type === "db.orphan.delete.failed") {
        const jobId = asStr(data.job_id);
        const detail = asStr(data.detail) || "Orphan deletion failed.";
        setActiveJob((prev) => {
          if (!prev || (jobId && prev.jobId !== jobId)) return prev;
          return { ...prev, phase: "failed", failDetail: detail };
        });
        toast.error("Orphan deletion failed.", { description: detail });
        setTimeout(() => setActiveJob(null), 5000);
      }
    },
    [siteId],
  );

  useSiteEvents(handleSiteEvent);

  // ---------------------------------------------------------------------------
  // Mutation
  // ---------------------------------------------------------------------------
  const mutation = useOrphanDelete(siteId);
  const isBusy = mutation.isPending || activeJob?.phase === "running";

  // ---------------------------------------------------------------------------
  // Open delete dialog for a specific group
  // ---------------------------------------------------------------------------
  function openDeleteDialog(kind: OrphanItemKind) {
    const groupItems = report
      ? (kind === "option"
          ? report.options
          : kind === "cron"
            ? report.cron
            : report.tables
        ).filter(
          (i) => i.deletable_eligible && !i.installed && i.owner_slug,
        )
      : [];

    const selected = selectedNamesForKind(kind);
    const chosenItems: OrphanDeleteItem[] = groupItems
      .filter((i) => selected.has(i.name))
      .map((i) => ({
        kind,
        name: i.name,
        // owner_slug guaranteed non-empty by the filter above
        owner_slug: i.owner_slug!,
      }));

    if (chosenItems.length === 0) return;

    const token = computeConfirmToken(chosenItems);
    setTyped("");
    setDialog({
      open: true,
      items: chosenItems,
      expectedToken: token,
      backupWarning: null,
    });
  }

  // ---------------------------------------------------------------------------
  // Execute deletion after confirm
  // ---------------------------------------------------------------------------
  function executeDelete() {
    mutation.mutate(
      { items: dialog.items, confirm: dialog.expectedToken },
      {
        onSuccess: (res) => {
          setDialog((d) => ({ ...d, open: false }));
          if (res.backup_warning) {
            // Surface the backup advisory from the server response (if any)
            // — the dialog already shows a generic nudge, but this is the
            // server-side specific advisory.
            toast.info("Backup advisory.", { description: res.backup_warning });
          }
          if (res.dropped_count > 0) {
            toast.info(`${res.dropped_count} item${res.dropped_count === 1 ? "" : "s"} were dropped.`, {
              description:
                "The CP re-verified eligibility before signing. Some items changed state since the list loaded.",
            });
          }
          if (res.accepted_count === 0) {
            toast.error("No items sent to agent.", {
              description:
                "All selected items were filtered out during re-classify. The orphan list will refresh.",
            });
          }
          // Note: final result toast fires when the SSE "completed" event arrives.
        },
        onError: (err) => {
          toast.error("Could not start orphan deletion.", {
            description: err.message,
          });
          setDialog((d) => ({ ...d, open: false }));
        },
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Render guards
  // ---------------------------------------------------------------------------

  if (report === null) {
    return <NoScanEmptyState />;
  }

  const totalItems =
    report.counts.options + report.counts.cron + report.counts.tables;

  const hiddenInstalled = report.hidden_installed ?? 0;

  if (totalItems === 0) {
    return <ZeroOrphansEmptyState hiddenInstalled={hiddenInstalled} />;
  }

  // snapshot_available=false disables ALL delete affordances
  const canDelete = report.snapshot_available;

  const optionSelectedNames = selectedNamesForKind("option");
  const cronSelectedNames = selectedNamesForKind("cron");
  const tableSelectedNames = selectedNamesForKind("table");

  const hasBannerAbove = !report.snapshot_available || activeJob?.phase === "running";

  return (
    <>
      {/* Orphan confirm dialog */}
      <OrphanConfirmDialog
        open={dialog.open}
        onClose={() => {
          if (!isBusy) setDialog((d) => ({ ...d, open: false }));
        }}
        onConfirm={executeDelete}
        itemCount={dialog.items.length}
        expectedToken={dialog.expectedToken}
        backupWarning={dialog.backupWarning}
        isPending={isBusy}
        typed={typed}
        onTypedChange={setTyped}
      />

      <div className="space-y-3">
        {!report.snapshot_available && <SnapshotNoticeBanner />}

        {/* In-progress / completed banner */}
        {activeJob !== null && activeJob.phase === "running" && (
          <InProgressBanner
            deletedOptions={activeJob.deletedOptions}
            deletedCron={activeJob.deletedCron}
            deletedTables={activeJob.deletedTables}
            skipped={activeJob.skipped}
          />
        )}

        {/* Hidden-installed note */}
        <HiddenInstalledNote count={hiddenInstalled} />

        {/* Section subtitle — helps users understand rows without checkboxes */}
        <p className="px-1 text-xs text-muted-foreground">
          Rows without a checkbox are shown for visibility only. Each row
          displays the reason it cannot be auto-removed.
        </p>

        <div className={hasBannerAbove ? "mt-1" : undefined}>
          <OrphanGroup
            title="Orphaned options"
            kind="option"
            items={report.options}
            defaultOpen
            renderRow={(item, selectable, selected, onToggle) => (
              <OptionRow
                item={item}
                selectable={selectable}
                selected={selected}
                onToggle={onToggle}
                isBusy={isBusy}
              />
            )}
            emptyLabel="No orphaned options found."
            selectedNames={optionSelectedNames}
            onToggleItem={toggleItem}
            onDeleteGroup={openDeleteDialog}
            canDelete={canDelete}
            isBusy={isBusy}
          />
          <OrphanGroup
            title="Orphaned scheduled tasks"
            kind="cron"
            items={report.cron}
            defaultOpen
            renderRow={(item, selectable, selected, onToggle) => (
              <CronRow
                item={item}
                selectable={selectable}
                selected={selected}
                onToggle={onToggle}
                isBusy={isBusy}
              />
            )}
            emptyLabel="No orphaned scheduled tasks found."
            selectedNames={cronSelectedNames}
            onToggleItem={toggleItem}
            onDeleteGroup={openDeleteDialog}
            canDelete={canDelete}
            isBusy={isBusy}
          />
          <OrphanGroup
            title="Orphaned tables"
            kind="table"
            items={report.tables}
            defaultOpen
            renderRow={(item, selectable, selected, onToggle) => (
              <TableRow
                item={item}
                selectable={selectable}
                selected={selected}
                onToggle={onToggle}
                isBusy={isBusy}
              />
            )}
            emptyLabel="No orphaned tables found."
            selectedNames={tableSelectedNames}
            onToggleItem={toggleItem}
            onDeleteGroup={openDeleteDialog}
            canDelete={canDelete}
            isBusy={isBusy}
          />
        </div>
      </div>
    </>
  );
}
