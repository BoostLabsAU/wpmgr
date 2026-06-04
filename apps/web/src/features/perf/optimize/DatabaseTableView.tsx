import { useState, useMemo, useCallback, useRef } from "react";
import {
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  ChevronDown,
  Search,
  MoreHorizontal,
  Wrench,
  Zap,
  Trash2,
  Loader2,
  AlertTriangle,
  Eraser,
  RefreshCw,
  Database,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { toast } from "@/components/toast";

import type { DbScanTableInventoryRow, DbScanOwnerType } from "../stores/dbScanStore";
import { formatBytes } from "../format";
import {
  useDbTableAction,
  newJobId,
  type DBTableActionResult,
  type DbTableActionVerb,
} from "../hooks/useDbTableAction";

// DatabaseTableView — per-table inventory with per-row and bulk ACTIONS.
//
// Actions:
//   Optimize  — OPTIMIZE TABLE (safe, all owner_types)
//   Repair    — REPAIR TABLE   (safe, all owner_types, MyISAM-meaningful)
//   Empty     — TRUNCATE TABLE (non-core: plugin/theme/orphan, type-to-confirm)
//               Deletes all rows but keeps the table structure.
//   Drop      — DROP TABLE     (non-core: plugin/theme/orphan, type-to-confirm)
//               Removes the entire table; the owning plugin may recreate an empty one.
//
// Row checkboxes allow bulk selection. The floating bulk bar at the bottom
// appears when any rows are selected and offers:
//   [Optimize | Repair | Empty] selector + Apply button — verb-specific eligibility
//   Delete (N)                                           — non-core (plugin/theme/orphan), type-to-confirm
//
// WP-core tables are protected from BOTH empty and drop. Unknown tables are
// also excluded (agent refuses to act on unidentified tables).
//
// Classification correctness is agent-side (Phase 2.2). The UI simply renders
// whatever owner_type / belongs_to the agent returns.

const PAGE_SIZE = 25;

// ---------------------------------------------------------------------------
// Filter bucket definitions
// ---------------------------------------------------------------------------

type FilterKey = "all" | "core" | "plugin" | "theme" | "orphan";

const FILTER_LABELS: Record<FilterKey, string> = {
  all: "All",
  core: "WP Core",
  plugin: "Plugins",
  theme: "Themes",
  orphan: "Orphans",
};

const FILTER_OWNER_TYPES: Record<FilterKey, DbScanOwnerType[] | null> = {
  all: null,
  core: ["core"],
  plugin: ["plugin"],
  theme: ["theme"],
  // orphan bucket includes unknown (forward-compat)
  orphan: ["orphan", "unknown"],
};

// ---------------------------------------------------------------------------
// Sort definitions
// ---------------------------------------------------------------------------

type SortKey = "name" | "rows" | "size_bytes" | "overhead_bytes" | "engine";
type SortDir = "asc" | "desc";

function compareRows(
  a: DbScanTableInventoryRow,
  b: DbScanTableInventoryRow,
  key: SortKey,
  dir: SortDir,
): number {
  let cmp = 0;
  if (key === "name" || key === "engine") {
    cmp = a[key].localeCompare(b[key]);
  } else {
    cmp = a[key] - b[key];
  }
  return dir === "asc" ? cmp : -cmp;
}

// ---------------------------------------------------------------------------
// Owner-type badge
// ---------------------------------------------------------------------------

const OWNER_BADGE_VARIANT: Record<DbScanOwnerType, string> = {
  core: "text-blue-700 bg-blue-100 border-blue-200 dark:text-blue-300 dark:bg-blue-950 dark:border-blue-800",
  plugin: "text-violet-700 bg-violet-100 border-violet-200 dark:text-violet-300 dark:bg-violet-950 dark:border-violet-800",
  theme: "text-amber-700 bg-amber-100 border-amber-200 dark:text-amber-300 dark:bg-amber-950 dark:border-amber-800",
  orphan: "text-red-700 bg-red-100 border-red-200 dark:text-red-300 dark:bg-red-950 dark:border-red-800",
  unknown: "text-muted-foreground bg-muted border-transparent",
};

function OwnerBadge({ ownerType }: { ownerType: DbScanOwnerType }) {
  const className = OWNER_BADGE_VARIANT[ownerType] ?? OWNER_BADGE_VARIANT.unknown;
  return (
    <span
      className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-xs font-medium ${className}`}
    >
      {ownerType}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Sort icon
// ---------------------------------------------------------------------------

function SortIcon({ active, dir }: { active: boolean; dir: SortDir }) {
  if (!active) {
    return (
      <span aria-hidden="true" className="ml-1 inline-flex flex-col opacity-30">
        <ChevronUp className="size-2.5 -mb-0.5" />
        <ChevronDown className="size-2.5" />
      </span>
    );
  }
  return dir === "asc" ? (
    <ChevronUp aria-hidden="true" className="ml-1 size-3 shrink-0" />
  ) : (
    <ChevronDown aria-hidden="true" className="ml-1 size-3 shrink-0" />
  );
}

// ---------------------------------------------------------------------------
// Sortable column header button
// ---------------------------------------------------------------------------

interface SortableHeadProps {
  label: string;
  sortKey: SortKey;
  currentKey: SortKey;
  dir: SortDir;
  onSort: (key: SortKey) => void;
  className?: string;
}

function SortableHead({
  label,
  sortKey,
  currentKey,
  dir,
  onSort,
  className,
}: SortableHeadProps) {
  const active = sortKey === currentKey;
  return (
    <TableHead className={className}>
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className="inline-flex items-center gap-0.5 cursor-pointer hover:text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
        aria-label={`Sort by ${label}${active ? (dir === "asc" ? ", ascending" : ", descending") : ""}`}
      >
        {label}
        <SortIcon active={active} dir={dir} />
      </button>
    </TableHead>
  );
}

// ---------------------------------------------------------------------------
// Destructive action confirm dialog
//
// Handles both "drop" and "empty" via the `actionVerb` prop.
// Drop: red styling, "Drop table(s)" title, "permanently remove" description.
// Empty: amber styling, "Empty table(s)" title, "delete all rows but keep schema" description.
// ---------------------------------------------------------------------------

interface DestructiveConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  actionVerb: "drop" | "empty";
  /** Single table name, or a human-readable description for bulk. */
  target: string;
  /** Expected confirm token: the table name (single) or "DROP N TABLES" / "EMPTY N TABLES" (bulk). */
  expectedToken: string;
  backupWarning: string | null;
  isPending: boolean;
  /** Controlled confirm input value — parent manages so it can reset on open. */
  typed: string;
  onTypedChange: (v: string) => void;
}

function DestructiveConfirmDialog({
  open,
  onClose,
  onConfirm,
  actionVerb,
  target,
  expectedToken,
  backupWarning,
  isPending,
  typed,
  onTypedChange,
}: DestructiveConfirmDialogProps) {
  const matches = typed === expectedToken;
  const isBulkToken = expectedToken.startsWith("DROP ") || expectedToken.startsWith("EMPTY ");

  const isDrop = actionVerb === "drop";
  const isMultiple = isBulkToken;

  const title = isDrop
    ? `Drop table${isMultiple ? "s" : ""}`
    : `Empty table${isMultiple ? "s" : ""}`;

  const description = isDrop ? (
    <>
      This will permanently remove{" "}
      <span className="font-medium text-foreground">{target}</span> and all its
      data. The owning plugin may recreate an empty one. This action cannot be
      undone.
    </>
  ) : (
    <>
      This will delete <strong>all rows</strong> in{" "}
      <span className="font-medium text-foreground">{target}</span> but keep the
      table structure. All data will be gone. This action cannot be undone.
    </>
  );

  const dialogId = isDrop ? "drop-confirm" : "empty-confirm";
  const iconColor = isDrop
    ? "text-destructive"
    : "text-amber-600 dark:text-amber-400";
  const buttonClass = isDrop
    ? "bg-destructive text-destructive-foreground hover:bg-destructive/90"
    : "bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-500 dark:hover:bg-amber-600";
  const buttonLabel = isDrop ? "Drop" : "Empty";
  const ButtonIcon = isDrop ? Trash2 : Eraser;

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent
        ariaLabelledBy={`${dialogId}-title`}
        ariaDescribedBy={`${dialogId}-desc`}
      >
        <DialogHeader>
          <DialogTitle id={`${dialogId}-title`} className="flex items-center gap-2">
            <ButtonIcon aria-hidden="true" className={`size-4 ${iconColor}`} />
            {title}
          </DialogTitle>
          <DialogDescription id={`${dialogId}-desc`}>
            {description}
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
            aria-label={`Type ${expectedToken} to confirm ${actionVerb}`}
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
            className={buttonClass}
          >
            {isPending ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : (
              <ButtonIcon aria-hidden="true" className="size-4" />
            )}
            {buttonLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// Result processing helper
// ---------------------------------------------------------------------------

function summariseResult(result: DBTableActionResult, verb: string): void {
  if (!result.ok) {
    toast.error(`${verb} failed.`, {
      description: result.detail ?? "The agent refused the request.",
    });
    return;
  }
  const results = result.results ?? [];
  const done = results.filter((r) => r.status === "done").length;
  const skipped = results.filter((r) => r.status === "skipped").length;
  const errors = results.filter((r) => r.status === "error" || r.status === "rejected").length;

  if (errors > 0) {
    const first = results.find((r) => r.status === "error" || r.status === "rejected");
    toast.error(`${verb}: ${errors} table${errors === 1 ? "" : "s"} failed.`, {
      description: first?.detail ?? "Check agent logs for details.",
    });
  } else if (done > 0) {
    const extra = skipped > 0 ? ` (${skipped} skipped)` : "";
    toast.success(
      `${verb} complete.`,
      { description: `${done} table${done === 1 ? "" : "s"} processed${extra}.` },
    );
  } else if (skipped > 0) {
    toast.info(`No tables were modified.`, {
      description: `${skipped} table${skipped === 1 ? " is" : "s are"} skipped.`,
    });
  }
}

// ---------------------------------------------------------------------------
// Bulk action verb type (subset of DbTableActionVerb that appears in bulk bar)
// ---------------------------------------------------------------------------

type BulkVerb = "optimize" | "repair" | "empty" | "analyze";

const BULK_VERB_LABELS: Record<BulkVerb, string> = {
  optimize: "Optimize",
  repair: "Repair",
  empty: "Empty",
  analyze: "Analyze",
};

const BULK_VERB_ICONS: Record<BulkVerb, React.FC<{ className?: string; "aria-hidden"?: "true" }>> = {
  optimize: ({ className, ...rest }) => <Zap className={className} {...rest} />,
  repair: ({ className, ...rest }) => <Wrench className={className} {...rest} />,
  empty: ({ className, ...rest }) => <Eraser className={className} {...rest} />,
  analyze: ({ className, ...rest }) => <RefreshCw className={className} {...rest} />,
};

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

export interface DatabaseTableViewProps {
  siteId: string;
  tables: DbScanTableInventoryRow[];
  /** Trigger a fresh scan to refresh the inventory after an action. */
  onRequestRescan: () => void;
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export function DatabaseTableView({ siteId, tables, onRequestRescan }: DatabaseTableViewProps) {
  const [filter, setFilter] = useState<FilterKey>("all");
  const [searchRaw, setSearchRaw] = useState("");
  const [sortKey, setSortKey] = useState<SortKey>("size_bytes");
  const [sortDir, setSortDir] = useState<SortDir>("desc");
  const [page, setPage] = useState(0);
  const [selectedNames, setSelectedNames] = useState<Set<string>>(new Set());

  // Bulk verb selector state (optimize/repair/empty — not drop which has its own button)
  const [bulkVerb, setBulkVerb] = useState<BulkVerb>("optimize");

  // Unified destructive dialog state (used for both drop and empty, single and bulk)
  const [destructiveDialog, setDestructiveDialog] = useState<{
    open: boolean;
    actionVerb: "drop" | "empty";
    tables: string[];
    target: string;
    expectedToken: string;
    backupWarning: string | null;
  }>({
    open: false,
    actionVerb: "drop",
    tables: [],
    target: "",
    expectedToken: "",
    backupWarning: null,
  });
  // Controlled confirm-input value — reset to "" whenever the dialog opens.
  const [destructiveTyped, setDestructiveTyped] = useState("");

  // Debounce search 200 ms
  const [searchDebounced, setSearchDebounced] = useState("");
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const handleSearch = useCallback((value: string) => {
    setSearchRaw(value);
    if (debounceRef.current !== null) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setSearchDebounced(value);
      setPage(0);
    }, 200);
  }, []);

  const action = useDbTableAction(siteId);

  // Filter-tab counts (computed from entire table list, ignoring search).
  const filterCounts = useMemo<Record<FilterKey, number>>(() => {
    const counts: Record<FilterKey, number> = { all: tables.length, core: 0, plugin: 0, theme: 0, orphan: 0 };
    for (const t of tables) {
      if (t.owner_type === "core") counts.core++;
      else if (t.owner_type === "plugin") counts.plugin++;
      else if (t.owner_type === "theme") counts.theme++;
      else counts.orphan++; // orphan + unknown
    }
    return counts;
  }, [tables]);

  // Apply filter + search + sort.
  const filteredSorted = useMemo(() => {
    const ownerTypes = FILTER_OWNER_TYPES[filter];
    const needle = searchDebounced.toLowerCase();

    let result = tables;

    if (ownerTypes !== null) {
      result = result.filter((t) => ownerTypes.includes(t.owner_type));
    }

    if (needle) {
      result = result.filter((t) => t.name.toLowerCase().includes(needle));
    }

    const sorted = [...result].sort((a, b) => compareRows(a, b, sortKey, sortDir));
    return sorted;
  }, [tables, filter, searchDebounced, sortKey, sortDir]);

  const totalPages = Math.max(1, Math.ceil(filteredSorted.length / PAGE_SIZE));
  const safePage = Math.min(page, totalPages - 1);
  const pageRows = filteredSorted.slice(safePage * PAGE_SIZE, (safePage + 1) * PAGE_SIZE);

  function handleFilterChange(key: FilterKey) {
    setFilter(key);
    setPage(0);
    setSelectedNames(new Set()); // clear selection on tab switch
  }

  function handleSort(key: SortKey) {
    if (key === sortKey) {
      setSortDir((d) => (d === "asc" ? "desc" : "asc"));
    } else {
      setSortKey(key);
      setSortDir(key === "name" || key === "engine" ? "asc" : "desc");
    }
    setPage(0);
  }

  // Selection helpers — scoped to the current visible page for clarity.
  const pageRowNames = pageRows.map((r) => r.name);
  const pageAllSelected = pageRowNames.length > 0 && pageRowNames.every((n) => selectedNames.has(n));
  const pageSomeSelected = !pageAllSelected && pageRowNames.some((n) => selectedNames.has(n));

  function toggleRowSelected(name: string) {
    setSelectedNames((prev) => {
      const next = new Set(prev);
      if (next.has(name)) next.delete(name);
      else next.add(name);
      return next;
    });
  }

  function togglePageSelectAll() {
    if (pageAllSelected) {
      setSelectedNames((prev) => {
        const next = new Set(prev);
        for (const n of pageRowNames) next.delete(n);
        return next;
      });
    } else {
      setSelectedNames((prev) => {
        const next = new Set(prev);
        for (const n of pageRowNames) next.add(n);
        return next;
      });
    }
  }

  // Map for quick owner_type lookup
  const tableByName = useMemo<Map<string, DbScanTableInventoryRow>>(() => {
    const m = new Map<string, DbScanTableInventoryRow>();
    for (const t of tables) m.set(t.name, t);
    return m;
  }, [tables]);

  const selectedArray = Array.from(selectedNames);
  // Non-core set: plugin/theme/orphan — used for BOTH empty and drop gates.
  // Excludes core (site destruction) and unknown (agent refuses unidentified
  // tables). Mirrors the agent gate for both TRUNCATE and DROP TABLE exactly.
  const selectedNonCoreNames = selectedArray.filter((n) => {
    const t = tableByName.get(n);
    return (
      t &&
      (t.owner_type === "plugin" ||
        t.owner_type === "theme" ||
        t.owner_type === "orphan")
    );
  });
  // Aliases kept for clarity at call-sites.
  const selectedEmptyNames = selectedNonCoreNames;
  const selectedDropNames = selectedNonCoreNames;

  // ---------------------------------------------------------------------------
  // Per-row action dispatch
  // ---------------------------------------------------------------------------

  function dispatchSingleAction(tableName: string, verb: DbTableActionVerb) {
    if (verb === "drop") {
      setDestructiveTyped("");
      setDestructiveDialog({
        open: true,
        actionVerb: "drop",
        tables: [tableName],
        target: tableName,
        expectedToken: tableName,
        backupWarning: null,
      });
      return;
    }

    if (verb === "empty") {
      setDestructiveTyped("");
      setDestructiveDialog({
        open: true,
        actionVerb: "empty",
        tables: [tableName],
        target: tableName,
        expectedToken: tableName,
        backupWarning: null,
      });
      return;
    }

    // optimize / repair / analyze / convert_innodb — fire immediately, no confirmation
    const IMMEDIATE_LABELS: Record<string, string> = {
      optimize: "Optimize",
      repair: "Repair",
      analyze: "Analyze",
      convert_innodb: "Convert to InnoDB",
    };
    const label = IMMEDIATE_LABELS[verb] ?? verb;
    action.mutate(
      { job_id: newJobId(), action: verb, tables: [tableName] },
      {
        onSuccess: (res) => {
          if (res.backup_warning) {
            toast.info("No recent backup found.", {
              description: res.backup_warning,
            });
          }
          summariseResult(res, label);
          if (res.ok) onRequestRescan();
        },
        onError: (err) => {
          toast.error(`Could not ${verb} table.`, { description: err.message });
        },
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Bulk action: Apply selected with chosen verb
  // ---------------------------------------------------------------------------

  function dispatchBulkVerb(verb: BulkVerb) {
    if (selectedArray.length === 0) return;

    if (verb === "empty") {
      // Filter to non-core tables only; core tables cannot be emptied
      const targets = selectedEmptyNames;
      if (targets.length === 0) {
        toast.info("No eligible tables.", {
          description: "None of the selected tables can be emptied (WP core tables are protected).",
        });
        return;
      }
      const n = targets.length;
      const token = n === 1 ? (targets[0] ?? "") : `EMPTY ${n} TABLES`;
      setDestructiveTyped("");
      setDestructiveDialog({
        open: true,
        actionVerb: "empty",
        tables: targets,
        target:
          n === 1
            ? (targets[0] ?? "")
            : `${n} table${n === 1 ? "" : "s"}`,
        expectedToken: token,
        backupWarning: null,
      });
      return;
    }

    // optimize / repair / analyze — fire immediately
    const label = BULK_VERB_LABELS[verb];
    action.mutate(
      { job_id: newJobId(), action: verb, tables: selectedArray },
      {
        onSuccess: (res) => {
          if (res.backup_warning) {
            toast.info("No recent backup found.", { description: res.backup_warning });
          }
          summariseResult(res, label);
          if (res.ok) onRequestRescan();
        },
        onError: (err) => {
          toast.error(`Could not ${verb.toLowerCase()} tables.`, { description: err.message });
        },
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Bulk action: delete (drop) non-core tables
  // ---------------------------------------------------------------------------

  function openBulkDrop() {
    if (selectedDropNames.length === 0) return;
    const n = selectedDropNames.length;
    setDestructiveTyped("");
    setDestructiveDialog({
      open: true,
      actionVerb: "drop",
      tables: selectedDropNames,
      target: `${n} table${n === 1 ? "" : "s"}`,
      expectedToken: n === 1 ? (selectedDropNames[0] ?? "") : `DROP ${n} TABLES`,
      backupWarning: null,
    });
  }

  // ---------------------------------------------------------------------------
  // Execute destructive action (after dialog confirm)
  // ---------------------------------------------------------------------------

  function executeDestructiveConfirmed() {
    const { tables: targetTables, expectedToken, actionVerb } = destructiveDialog;
    const label = actionVerb === "drop" ? "Drop" : "Empty";
    action.mutate(
      {
        job_id: newJobId(),
        action: actionVerb,
        tables: targetTables,
        confirm: expectedToken,
      },
      {
        onSuccess: (res) => {
          if (res.backup_warning) {
            toast.info("No recent backup found.", { description: res.backup_warning });
          }
          summariseResult(res, label);
          setDestructiveDialog((d) => ({ ...d, open: false }));
          if (actionVerb === "drop") {
            // Remove dropped tables from selection
            setSelectedNames((prev) => {
              const next = new Set(prev);
              for (const t of targetTables) next.delete(t);
              return next;
            });
          }
          if (res.ok) onRequestRescan();
        },
        onError: (err) => {
          toast.error(
            targetTables.length > 1
              ? `Could not ${actionVerb} tables.`
              : `Could not ${actionVerb} table.`,
            { description: err.message },
          );
          setDestructiveDialog((d) => ({ ...d, open: false }));
        },
      },
    );
  }

  const isBusy = action.isPending;

  // Bulk verb icon component for the current selection
  const BulkVerbIcon = BULK_VERB_ICONS[bulkVerb];

  // Label describing eligible count for the Apply button.
  // "empty" is filtered to non-core; all other verbs (optimize/repair/analyze) use full selection.
  const bulkApplyCount =
    bulkVerb === "empty" ? selectedEmptyNames.length : selectedArray.length;

  return (
    <>
      {/* Destructive confirm dialog — shared for drop and empty, single and bulk */}
      <DestructiveConfirmDialog
        open={destructiveDialog.open}
        onClose={() => {
          if (!isBusy) setDestructiveDialog((d) => ({ ...d, open: false }));
        }}
        onConfirm={executeDestructiveConfirmed}
        actionVerb={destructiveDialog.actionVerb}
        target={destructiveDialog.target}
        expectedToken={destructiveDialog.expectedToken}
        backupWarning={destructiveDialog.backupWarning}
        isPending={isBusy}
        typed={destructiveTyped}
        onTypedChange={setDestructiveTyped}
      />

      <div className="divide-y divide-border">
        {/* Filter tabs + search row */}
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
          {/* Filter tab pills */}
          <div
            role="tablist"
            aria-label="Table ownership filter"
            className="flex flex-wrap items-center gap-1"
          >
            {(Object.keys(FILTER_LABELS) as FilterKey[]).map((key) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={filter === key}
                onClick={() => handleFilterChange(key)}
                className={[
                  "inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  filter === key
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-border bg-transparent text-muted-foreground hover:text-foreground",
                ].join(" ")}
              >
                {FILTER_LABELS[key]}
                <span
                  className={[
                    "rounded-full px-1 tabular-nums",
                    filter === key
                      ? "bg-primary-foreground/20 text-primary-foreground"
                      : "bg-muted text-muted-foreground",
                  ].join(" ")}
                >
                  {filterCounts[key]}
                </span>
              </button>
            ))}
          </div>

          {/* Search box */}
          <div className="relative w-48 shrink-0">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              type="search"
              placeholder="Search tables…"
              value={searchRaw}
              onChange={(e) => handleSearch(e.target.value)}
              className="h-8 pl-8 text-xs"
              aria-label="Search tables by name"
            />
          </div>
        </div>

        {/* Bulk action bar — shown when any rows are selected */}
        {selectedArray.length > 0 && (
          <div
            role="region"
            aria-label="Bulk table actions"
            className="flex flex-wrap items-center justify-between gap-2 bg-muted/40 px-5 py-2"
          >
            <span className="text-xs text-muted-foreground">
              <span className="font-medium text-foreground tabular-nums">
                {selectedArray.length}
              </span>{" "}
              table{selectedArray.length === 1 ? "" : "s"} selected
            </span>

            <div className="flex items-center gap-2">
              {/* Action selector + Apply split control */}
              <div className="flex items-center">
                {/* Verb picker dropdown */}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={isBusy}
                      className="rounded-r-none border-r-0 px-2.5"
                      aria-label={`Selected bulk action: ${BULK_VERB_LABELS[bulkVerb]}. Click to change.`}
                    >
                      <BulkVerbIcon aria-hidden="true" className="size-3.5" />
                      {BULK_VERB_LABELS[bulkVerb]}
                      <ChevronDown aria-hidden="true" className="size-3 ml-0.5 text-muted-foreground" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" className="min-w-[9rem]">
                    {(["optimize", "repair", "analyze", "empty"] as BulkVerb[]).map((verb) => {
                      const Icon = BULK_VERB_ICONS[verb];
                      return (
                        <DropdownMenuItem
                          key={verb}
                          onClick={() => setBulkVerb(verb)}
                          className="text-xs gap-2"
                          aria-current={bulkVerb === verb ? "true" : undefined}
                        >
                          <Icon aria-hidden="true" className="size-3.5 text-muted-foreground" />
                          {BULK_VERB_LABELS[verb]}
                        </DropdownMenuItem>
                      );
                    })}
                  </DropdownMenuContent>
                </DropdownMenu>

                {/* Apply button */}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => dispatchBulkVerb(bulkVerb)}
                  disabled={isBusy || bulkApplyCount === 0}
                  className={[
                    "rounded-l-none",
                    bulkVerb === "empty"
                      ? "text-amber-700 hover:text-amber-800 border-amber-300 hover:border-amber-400 dark:text-amber-400 dark:hover:text-amber-300 dark:border-amber-700"
                      : "",
                  ].join(" ")}
                  aria-label={`Apply ${BULK_VERB_LABELS[bulkVerb]} to ${bulkApplyCount} table${bulkApplyCount === 1 ? "" : "s"}`}
                >
                  {isBusy ? (
                    <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                  ) : null}
                  Apply{bulkVerb === "empty" && selectedEmptyNames.length < selectedArray.length
                    ? ` (${bulkApplyCount})`
                    : ""}
                </Button>
              </div>

              {/* Delete (drop) — separate button, non-core tables only */}
              {selectedDropNames.length > 0 && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={openBulkDrop}
                  disabled={isBusy}
                  className="text-destructive hover:text-destructive border-destructive/30 hover:border-destructive/60"
                  aria-label={`Delete ${selectedDropNames.length} table${selectedDropNames.length === 1 ? "" : "s"}`}
                >
                  <Trash2 aria-hidden="true" className="size-3.5" />
                  Delete ({selectedDropNames.length})
                </Button>
              )}

              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setSelectedNames(new Set())}
                className="text-muted-foreground"
                aria-label="Clear selection"
              >
                Clear
              </Button>
            </div>
          </div>
        )}

        {/* Table */}
        {filteredSorted.length === 0 ? (
          <div className="px-5 py-10 text-center text-xs text-muted-foreground">
            {searchDebounced
              ? "No tables match your search."
              : "No tables in this category."}
          </div>
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  {/* Select-all for the current page */}
                  <TableHead className="w-10 px-3">
                    <Checkbox
                      checked={pageAllSelected}
                      ref={(el) => {
                        if (el) el.indeterminate = pageSomeSelected;
                      }}
                      onChange={togglePageSelectAll}
                      aria-label={pageAllSelected ? "Deselect all on this page" : "Select all on this page"}
                    />
                  </TableHead>
                  <SortableHead
                    label="Table name"
                    sortKey="name"
                    currentKey={sortKey}
                    dir={sortDir}
                    onSort={handleSort}
                    className="w-[34%] min-w-[160px]"
                  />
                  <TableHead className="w-[16%]">Belongs to</TableHead>
                  <SortableHead
                    label="Rows"
                    sortKey="rows"
                    currentKey={sortKey}
                    dir={sortDir}
                    onSort={handleSort}
                    className="text-right w-[9%]"
                  />
                  <SortableHead
                    label="Size"
                    sortKey="size_bytes"
                    currentKey={sortKey}
                    dir={sortDir}
                    onSort={handleSort}
                    className="text-right w-[11%]"
                  />
                  <SortableHead
                    label="Engine"
                    sortKey="engine"
                    currentKey={sortKey}
                    dir={sortDir}
                    onSort={handleSort}
                    className="text-right w-[9%]"
                  />
                  <SortableHead
                    label="Overhead"
                    sortKey="overhead_bytes"
                    currentKey={sortKey}
                    dir={sortDir}
                    onSort={handleSort}
                    className="text-right w-[11%]"
                  />
                  {/* Actions column */}
                  <TableHead className="w-12 text-right" aria-label="Actions" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {pageRows.map((row) => (
                  <TableInventoryRow
                    key={row.name}
                    row={row}
                    selected={selectedNames.has(row.name)}
                    onToggleSelect={() => toggleRowSelected(row.name)}
                    onAction={(verb) => dispatchSingleAction(row.name, verb)}
                    isBusy={isBusy}
                  />
                ))}
              </TableBody>
            </Table>

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between gap-3 px-5 py-3">
                <span className="text-xs text-muted-foreground">
                  Page {safePage + 1} of {totalPages}
                  <span className="ml-2 text-muted-foreground/70">
                    ({filteredSorted.length} tables)
                  </span>
                </span>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setPage((p) => Math.max(0, p - 1))}
                    disabled={safePage === 0}
                  >
                    <ChevronLeft aria-hidden="true" className="size-4" />
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setPage((p) => Math.min(totalPages - 1, p + 1))}
                    disabled={safePage >= totalPages - 1}
                  >
                    Next
                    <ChevronRight aria-hidden="true" className="size-4" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </>
  );
}

// ---------------------------------------------------------------------------
// Individual table row
// ---------------------------------------------------------------------------

interface TableInventoryRowProps {
  row: DbScanTableInventoryRow;
  selected: boolean;
  onToggleSelect: () => void;
  onAction: (verb: DbTableActionVerb) => void;
  isBusy: boolean;
}

function TableInventoryRow({
  row,
  selected,
  onToggleSelect,
  onAction,
  isBusy,
}: TableInventoryRowProps) {
  // InnoDB TABLE_ROWS is an estimate — prefix with "~" to set expectations.
  const isInnoDB = row.engine.toLowerCase() === "innodb";
  const rowsDisplay = `${isInnoDB ? "~" : ""}${row.rows.toLocaleString()}`;
  // Non-core gate: plugin/theme/orphan — applies to BOTH empty and drop.
  // Excludes core (site destruction) and unknown (agent refuses unidentified
  // tables). Mirrors the agent TRUNCATE and DROP gates exactly.
  const canEmpty =
    row.owner_type === "plugin" ||
    row.owner_type === "theme" ||
    row.owner_type === "orphan";
  const canDrop = canEmpty;

  return (
    <TableRow data-selected={selected || undefined} className={selected ? "bg-muted/30" : undefined}>
      {/* Checkbox */}
      <TableCell className="w-10 px-3">
        <Checkbox
          checked={selected}
          onChange={onToggleSelect}
          aria-label={`Select ${row.name}`}
        />
      </TableCell>

      {/* Table name */}
      <TableCell className="max-w-[240px]">
        <span
          className="block truncate font-mono text-xs font-medium text-foreground"
          title={row.name}
        >
          {row.name}
        </span>
      </TableCell>

      {/* Belongs to + owner_type badge */}
      <TableCell>
        <div className="flex items-center gap-1.5 min-w-0">
          <span
            className="block truncate text-xs text-foreground max-w-[100px]"
            title={row.belongs_to}
          >
            {row.belongs_to}
          </span>
          <OwnerBadge ownerType={row.owner_type} />
        </div>
      </TableCell>

      {/* Rows (right-aligned, tabular-nums) */}
      <TableCell className="text-right tabular-nums text-xs text-muted-foreground">
        {rowsDisplay}
      </TableCell>

      {/* Size */}
      <TableCell className="text-right tabular-nums text-xs text-foreground">
        {formatBytes(row.size_bytes)}
      </TableCell>

      {/* Engine badge */}
      <TableCell className="text-right">
        <Badge variant="outline" className="text-xs font-normal">
          {row.engine}
        </Badge>
      </TableCell>

      {/* Overhead */}
      <TableCell className="text-right tabular-nums text-xs text-muted-foreground">
        {row.overhead_bytes > 0 ? (
          <span className="text-amber-600 dark:text-amber-400">
            {formatBytes(row.overhead_bytes)}
          </span>
        ) : (
          "–"
        )}
      </TableCell>

      {/* Action menu */}
      <TableCell className="text-right">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              disabled={isBusy}
              aria-label={`Actions for ${row.name}`}
            >
              <MoreHorizontal aria-hidden="true" className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="min-w-[10rem]">
            <DropdownMenuItem
              onClick={() => onAction("optimize")}
              className="text-xs gap-2"
            >
              <Zap aria-hidden="true" className="size-3.5 text-muted-foreground" />
              Optimize
            </DropdownMenuItem>
            <DropdownMenuItem
              onClick={() => onAction("repair")}
              className="text-xs gap-2"
            >
              <Wrench aria-hidden="true" className="size-3.5 text-muted-foreground" />
              Repair
            </DropdownMenuItem>
            <DropdownMenuItem
              onClick={() => onAction("analyze")}
              className="text-xs gap-2"
              aria-label={`Analyze ${row.name} — refresh row counts and statistics`}
            >
              <RefreshCw aria-hidden="true" className="size-3.5 text-muted-foreground" />
              Analyze
              <span className="ml-auto text-[10px] text-muted-foreground hidden sm:block">
                refresh stats
              </span>
            </DropdownMenuItem>
            {!isInnoDB && (
              <DropdownMenuItem
                onClick={() => onAction("convert_innodb")}
                className="text-xs gap-2"
                aria-label={`Convert ${row.name} to InnoDB engine`}
              >
                <Database aria-hidden="true" className="size-3.5 text-muted-foreground" />
                Convert to InnoDB
                <span className="ml-auto text-[10px] text-muted-foreground hidden sm:block">
                  engine swap
                </span>
              </DropdownMenuItem>
            )}
            {(canEmpty || canDrop) && (
              <>
                <DropdownMenuSeparator />
                {canEmpty && (
                  <DropdownMenuItem
                    onClick={() => onAction("empty")}
                    className="text-xs gap-2 text-amber-700 focus:text-amber-700 focus:bg-amber-50 dark:text-amber-400 dark:focus:text-amber-400 dark:focus:bg-amber-950/30"
                    aria-label={`Empty rows in ${row.name} — deletes all rows, keeps the table`}
                  >
                    <Eraser aria-hidden="true" className="size-3.5" />
                    Empty rows
                    <span className="ml-auto text-[10px] text-muted-foreground hidden sm:block">
                      keeps table
                    </span>
                  </DropdownMenuItem>
                )}
                {canDrop && (
                  <DropdownMenuItem
                    onClick={() => onAction("drop")}
                    className="text-xs gap-2 text-destructive focus:text-destructive focus:bg-destructive/10"
                    aria-label={`Delete ${row.name} — removes the entire table`}
                  >
                    <Trash2 aria-hidden="true" className="size-3.5" />
                    Delete table
                    <span className="ml-auto text-[10px] text-muted-foreground hidden sm:block">
                      removes table
                    </span>
                  </DropdownMenuItem>
                )}
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}
