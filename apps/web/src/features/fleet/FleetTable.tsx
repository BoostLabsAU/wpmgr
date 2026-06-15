// FleetTable — a react-virtuoso TableVirtuoso shell reused by all three fleet
// dashboards. Provides:
//   - Sticky header with sortable columns
//   - Right-aligned tabular-nums numeric cells
//   - Row hover, focus, keyboard navigation
//   - Skeleton, empty, and error states
//
// Callers supply column definitions (FleetColumnDef[]) and row data. The shell
// manages sort state and passes sorted rows to react-virtuoso.

import {
  forwardRef,
  useCallback,
  useRef,
  useState,
  type HTMLAttributes,
  type ReactNode,
  type TableHTMLAttributes,
} from "react";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type Row,
  type SortingState,
} from "@tanstack/react-table";
import { TableVirtuoso, type TableComponents } from "react-virtuoso";
import { ChevronDown, ChevronUp, ChevronsUpDown } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { cn } from "@/lib/utils";

// Re-export for callers.
export type { ColumnDef as FleetColumnDef };

// ---------------------------------------------------------------------------
// Column meta shape (narrowed helper — avoids runtime type assertions)
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

// ---------------------------------------------------------------------------
// Column width computation
//
// Given an ordered list of meta.width values (percentages like "28%" or
// undefined), derive a resolved width per column so header and body always
// share identical geometry under table-layout:fixed.
//
// Algorithm:
//   1. Sum the explicit percentage widths.
//   2. Distribute the remainder equally across columns that have no width.
//   3. Return one string per column in the same order.
// ---------------------------------------------------------------------------

function resolveColWidths(rawWidths: Array<string | undefined>): string[] {
  const total = rawWidths.length;
  if (total === 0) return [];

  let fixedSum = 0;
  let freeCount = 0;

  for (const w of rawWidths) {
    if (w) {
      // Accept "28%", "10%", etc.  Non-percentage values are passed through as-is
      // and not counted against the free pool.
      const pct = parseFloat(w);
      if (!Number.isNaN(pct) && w.trim().endsWith("%")) {
        fixedSum += pct;
      }
    } else {
      freeCount++;
    }
  }

  const freeShare =
    freeCount > 0
      ? Math.max(0, (100 - fixedSum) / freeCount)
      : 0;

  return rawWidths.map((w) => {
    if (w) return w;
    return `${freeShare.toFixed(4)}%`;
  });
}

// ---------------------------------------------------------------------------
// Module-level ref: the table component slot is stateless (Virtuoso creates it
// once as a stable element type) so we thread column widths through a ref that
// VirtuosoTable reads synchronously during render. This avoids prop-drilling
// through the TableComponents slot API which does not support extra props.
//
// Assumption: React's synchronous render model guarantees that FleetTable
// writes colWidthsRef.current before its VirtuosoTable child reads it in the
// same render pass. The three fleet dashboards each render exactly one
// FleetTable at a time, so there is no cross-instance collision.
// ---------------------------------------------------------------------------

const colWidthsRef: { current: string[] } = { current: [] };

// ---------------------------------------------------------------------------
// Virtuoso component slots (hoisted to avoid re-creation on parent render)
// ---------------------------------------------------------------------------

const VirtuosoScroller = forwardRef<
  HTMLDivElement,
  HTMLAttributes<HTMLDivElement>
>(function VirtuosoScroller({ style, ...props }, ref) {
  return (
    <div
      ref={ref}
      style={style}
      {...props}
      className="overflow-x-auto overflow-y-auto"
    />
  );
});

function VirtuosoTable({
  style,
  children,
  ...props
}: TableHTMLAttributes<HTMLTableElement>) {
  const widths = colWidthsRef.current;
  return (
    <table
      {...props}
      style={{ ...style, borderCollapse: "collapse", tableLayout: "fixed" }}
      className="w-full min-w-[640px] text-sm"
    >
      {widths.length > 0 && (
        <colgroup>
          {widths.map((w, i) => (
            <col key={i} style={{ width: w }} />
          ))}
        </colgroup>
      )}
      {children}
    </table>
  );
}

const VirtuosoTableHead = forwardRef<
  HTMLTableSectionElement,
  HTMLAttributes<HTMLTableSectionElement>
>(function VirtuosoTableHead(props, ref) {
  return (
    <thead
      ref={ref}
      {...props}
      className="sticky top-0 z-10 bg-[var(--color-card)]"
    />
  );
});

// ---------------------------------------------------------------------------
// Sort icon helper
// ---------------------------------------------------------------------------

function SortIcon({
  sorted,
}: {
  sorted: "asc" | "desc" | false;
}) {
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
  /** Table height — flex‑driven by container by default. Override for fixed heights. */
  height?: number | string;
  loading?: boolean;
  isError?: boolean;
  errorMessage?: string;
  onRetry?: () => void;
  emptyState?: ReactNode;
  /** Aria-label for the scrollable region. */
  ariaLabel?: string;
  /** Skelton row count rendered while loading. */
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
    // Disable pagination — Virtuoso handles the viewport.
    manualPagination: true,
  });

  const rows = table.getRowModel().rows;

  // ---------------------------------------------------------------------------
  // Derive resolved column widths and write them to the module-level ref so
  // VirtuosoTable can render the <colgroup> synchronously. This must happen
  // before Virtuoso renders the table element on this cycle.
  // ---------------------------------------------------------------------------

  const leafColumns = table.getAllLeafColumns();
  const rawWidths = leafColumns.map((col) => colMeta(col.columnDef.meta).width);
  colWidthsRef.current = resolveColWidths(rawWidths);

  // ---------------------------------------------------------------------------
  // Virtuoso slot components bound to current table instance. Hoisted into a
  // stable ref so Virtuoso doesn't see new component objects on each render.
  // ---------------------------------------------------------------------------

  const tableRef = useRef(table);
  tableRef.current = table;

  // Memoized fixed-header renderer for Virtuoso. Defined with useCallback so
  // Virtuoso always sees the same function reference and does not unmount/remount
  // the sticky header on every parent re-render (which would cause scroll-to-top
  // flicker on sort).
  //
  // Width style is omitted from <th> because the <colgroup> already locks the
  // geometry under table-layout:fixed. The colgroup is the single source of
  // truth; th/td widths would only redundantly repeat it and could desync if
  // the two lists diverged.
  const FixedHeader = useCallback(function FixedHeaderInner() {
    return (
      <thead className="bg-[var(--color-card)]">
        {tableRef.current.getHeaderGroups().map((hg) => (
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
                    "px-3 py-2.5 text-left text-xs font-medium text-[var(--color-muted-foreground)]",
                    meta?.numeric && "text-right",
                    canSort &&
                      "cursor-pointer select-none hover:text-[var(--color-foreground)]",
                  )}
                  onClick={
                    canSort ? header.column.getToggleSortingHandler() : undefined
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
                  <span className="inline-flex items-center">
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
    );
  }, []);

  const components: TableComponents<Row<TData>> = {
    Scroller: VirtuosoScroller,
    Table: VirtuosoTable,
    TableHead: VirtuosoTableHead,
    TableRow: ({ item: _item, ...props }) => (
      <tr
        {...props}
        className="border-b border-[var(--color-border)] hover:bg-[var(--color-accent)] focus-within:bg-[var(--color-accent)] last:border-0 transition-colors duration-100"
        style={{}}
      />
    ),
  };

  return (
    <div
      className={cn(
        "rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] overflow-hidden",
        className,
      )}
    >
      {loading ? (
        <FleetTableSkeleton
          columns={columns.length}
          rows={skeletonRows}
          ariaLabel={ariaLabel}
        />
      ) : isError ? (
        <div className="px-5 py-8">
          <PageError
            what={`Could not load ${ariaLabel.toLowerCase()}.`}
            why={errorMessage ?? "Unknown error"}
            onRetry={onRetry}
            retryLabel="Reload"
          />
        </div>
      ) : rows.length === 0 ? (
        <div className="px-5 py-10">
          {emptyState ?? (
            <p className="text-center text-sm text-[var(--color-muted-foreground)]">
              No data
            </p>
          )}
        </div>
      ) : (
        <TableVirtuoso<Row<TData>>
          style={{ height }}
          totalCount={rows.length}
          data={rows}
          components={components}
          fixedHeaderContent={FixedHeader}
          itemContent={(_, row) => {
            const cells = row.getVisibleCells();
            return cells.map((cell) => {
              const meta = colMeta(cell.column.columnDef.meta);
              return (
                <td
                  key={cell.id}
                  className={cn(
                    "px-3 py-2.5 text-sm text-[var(--color-foreground)]",
                    meta?.numeric && "text-right tabular-nums font-mono",
                    onRowClick && "cursor-pointer",
                  )}
                  onClick={
                    onRowClick ? () => onRowClick(row.original) : undefined
                  }
                >
                  {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </td>
              );
            });
          }}
        />
      )}
    </div>
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
      {/* Header row */}
      <div className="flex gap-3 border-b border-[var(--color-border)] px-3 py-2.5">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton key={i} className="h-3 flex-1 rounded" />
        ))}
      </div>
      {/* Data rows */}
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
