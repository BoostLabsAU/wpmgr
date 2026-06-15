// FleetTable — a sticky-header data table shared by all three fleet dashboards.
//
// Implemented as a single semantic <table> with table-layout:fixed and a
// <colgroup> that is the single source of column geometry. Because the header
// and body live in the same table element, their columns ALWAYS align — no
// virtualized-header desync (an earlier react-virtuoso implementation could not
// keep the sticky header and the body columns in sync).
//
// Rows render with content-visibility:auto so the browser skips layout/paint
// for offscreen rows: this keeps large fleets responsive without a virtualizer
// (and without its alignment pitfalls). Provides:
//   - Sticky, sortable header with sort icons + aria-sort
//   - Right-aligned tabular-nums numeric cells
//   - Row hover / focus / click
//   - Skeleton, empty, and error states

import {
  useState,
  type ReactNode,
} from "react";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from "@tanstack/react-table";
import { ChevronDown, ChevronUp, ChevronsUpDown } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { cn } from "@/lib/utils";

// Re-export for callers.
export type { ColumnDef as FleetColumnDef };

// ---------------------------------------------------------------------------
// Column meta
// ---------------------------------------------------------------------------

interface ColMeta {
  numeric?: boolean;
  width?: string;
}

function colMeta(raw: unknown): ColMeta {
  if (raw && typeof raw === "object") {
    const m = raw as Partial<ColMeta>;
    return { numeric: m.numeric, width: m.width };
  }
  return {};
}

// Resolve one width string per leaf column. Explicit percentage widths are kept;
// the remaining percentage is split equally across columns without a width so
// the colgroup always describes every column.
function resolveColWidths(rawWidths: Array<string | undefined>): string[] {
  if (rawWidths.length === 0) return [];
  let fixedPct = 0;
  let freeCount = 0;
  for (const w of rawWidths) {
    if (w && w.trim().endsWith("%")) {
      const pct = parseFloat(w);
      if (!Number.isNaN(pct)) fixedPct += pct;
    } else if (!w) {
      freeCount++;
    }
  }
  const freeShare = freeCount > 0 ? Math.max(0, (100 - fixedPct) / freeCount) : 0;
  return rawWidths.map((w) => (w ? w : `${freeShare.toFixed(4)}%`));
}

function SortIcon({ sorted }: { sorted: "asc" | "desc" | false }) {
  if (sorted === "asc")
    return (
      <ChevronUp
        aria-hidden="true"
        className="ml-1 inline size-3.5 shrink-0 text-[var(--color-primary)]"
      />
    );
  if (sorted === "desc")
    return (
      <ChevronDown
        aria-hidden="true"
        className="ml-1 inline size-3.5 shrink-0 text-[var(--color-primary)]"
      />
    );
  return (
    <ChevronsUpDown
      aria-hidden="true"
      className="ml-1 inline size-3.5 shrink-0 text-[var(--color-muted-foreground)] opacity-40"
    />
  );
}

// ---------------------------------------------------------------------------
// Public props
// ---------------------------------------------------------------------------

export interface FleetTableProps<TData extends object> {
  data: TData[];
  columns: ColumnDef<TData>[];
  /** Max table height; the body scrolls under the sticky header past this. */
  height?: number | string;
  loading?: boolean;
  isError?: boolean;
  errorMessage?: string;
  onRetry?: () => void;
  emptyState?: ReactNode;
  /** Aria-label for the scrollable region. */
  ariaLabel?: string;
  /** Skeleton row count rendered while loading. */
  skeletonRows?: number;
  /** Initial sort state. */
  defaultSorting?: SortingState;
  /** Row-click handler. */
  onRowClick?: (row: TData) => void;
  /** Additional className on the outer wrapper. */
  className?: string;
}

export function FleetTable<TData extends object>({
  data,
  columns,
  height = 480,
  loading = false,
  isError = false,
  errorMessage,
  onRetry,
  emptyState,
  ariaLabel = "Fleet data table",
  skeletonRows = 8,
  defaultSorting,
  onRowClick,
  className,
}: FleetTableProps<TData>) {
  const [sorting, setSorting] = useState<SortingState>(defaultSorting ?? []);

  const table = useReactTable({
    data,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
  });

  const rows = table.getRowModel().rows;
  const leafCols = table.getVisibleLeafColumns();
  const widths = resolveColWidths(
    leafCols.map((c) => colMeta(c.columnDef.meta).width),
  );

  const wrapper = (inner: ReactNode) => (
    <div
      className={cn(
        "rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] overflow-hidden",
        className,
      )}
    >
      {inner}
    </div>
  );

  if (loading) {
    return wrapper(
      <FleetTableSkeleton
        columns={columns.length}
        rows={skeletonRows}
        ariaLabel={ariaLabel}
      />,
    );
  }
  if (isError) {
    return wrapper(
      <div className="px-5 py-8">
        <PageError
          what={`Could not load ${ariaLabel.toLowerCase()}.`}
          why={errorMessage ?? "Unknown error"}
          onRetry={onRetry}
          retryLabel="Reload"
        />
      </div>,
    );
  }
  if (rows.length === 0) {
    return wrapper(
      <div className="px-5 py-10">
        {emptyState ?? (
          <p className="text-center text-sm text-[var(--color-muted-foreground)]">
            No data
          </p>
        )}
      </div>,
    );
  }

  return wrapper(
    <div
      className="overflow-auto"
      style={{ maxHeight: height }}
      role="region"
      aria-label={ariaLabel}
      tabIndex={0}
    >
      <table
        className="w-full min-w-[640px] text-sm"
        style={{ tableLayout: "fixed", borderCollapse: "collapse" }}
      >
        <colgroup>
          {widths.map((w, i) => (
            <col key={i} style={{ width: w }} />
          ))}
        </colgroup>
        <thead className="sticky top-0 z-10 bg-[var(--color-card)]">
          {table.getHeaderGroups().map((hg) => (
            <tr key={hg.id} className="border-b border-[var(--color-border)]">
              {hg.headers.map((header) => {
                const canSort = header.column.getCanSort();
                const sorted = header.column.getIsSorted();
                const meta = colMeta(header.column.columnDef.meta);
                return (
                  <th
                    key={header.id}
                    colSpan={header.colSpan}
                    scope="col"
                    aria-sort={
                      sorted === "asc"
                        ? "ascending"
                        : sorted === "desc"
                          ? "descending"
                          : undefined
                    }
                    className={cn(
                      "px-3 py-2.5 text-left align-bottom text-xs font-medium text-[var(--color-muted-foreground)]",
                      meta.numeric && "text-right",
                      canSort &&
                        "cursor-pointer select-none hover:text-[var(--color-foreground)]",
                    )}
                    onClick={
                      canSort
                        ? header.column.getToggleSortingHandler()
                        : undefined
                    }
                    onKeyDown={
                      canSort
                        ? (e) => {
                            if (e.key === "Enter" || e.key === " ") {
                              e.preventDefault();
                              header.column.getToggleSortingHandler()?.(e);
                            }
                          }
                        : undefined
                    }
                    tabIndex={canSort ? 0 : undefined}
                  >
                    <span
                      className={cn(
                        "inline-flex items-center",
                        meta.numeric && "justify-end",
                      )}
                    >
                      {header.isPlaceholder
                        ? null
                        : flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                          )}
                      {canSort ? <SortIcon sorted={sorted} /> : null}
                    </span>
                  </th>
                );
              })}
            </tr>
          ))}
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.id}
              className={cn(
                "border-b border-[var(--color-border)] last:border-0 transition-colors duration-100",
                "hover:bg-[var(--color-accent)] focus-within:bg-[var(--color-accent)]",
                onRowClick && "cursor-pointer",
              )}
              style={{ contentVisibility: "auto" }}
              onClick={onRowClick ? () => onRowClick(row.original) : undefined}
            >
              {row.getVisibleCells().map((cell) => {
                const meta = colMeta(cell.column.columnDef.meta);
                return (
                  <td
                    key={cell.id}
                    className={cn(
                      "px-3 py-2.5 align-middle text-sm text-[var(--color-foreground)]",
                      meta.numeric && "text-right tabular-nums font-mono",
                    )}
                  >
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>,
  );
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

function FleetTableSkeleton({
  columns,
  rows,
  ariaLabel,
}: {
  columns: number;
  rows: number;
  ariaLabel: string;
}) {
  return (
    <div role="status" aria-busy="true" aria-label={`Loading ${ariaLabel}`}>
      <span className="sr-only">Loading {ariaLabel}</span>
      <div className="flex gap-3 border-b border-[var(--color-border)] px-3 py-2.5">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton key={i} className="h-3 flex-1 rounded" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, i) => (
        <div
          key={i}
          className="flex gap-3 border-b border-[var(--color-border)] px-3 py-2.5 last:border-0"
        >
          {Array.from({ length: columns }).map((_, j) => (
            <Skeleton key={j} className="h-4 flex-1 rounded" />
          ))}
        </div>
      ))}
    </div>
  );
}
