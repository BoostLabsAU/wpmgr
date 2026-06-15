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
  ...props
}: TableHTMLAttributes<HTMLTableElement>) {
  return (
    <table
      {...props}
      style={{ ...style, borderCollapse: "collapse", tableLayout: "fixed" }}
      className="w-full min-w-[640px] text-sm"
    />
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
  // Virtuoso slot components bound to current table instance. Hoisted into a
  // stable ref so Virtuoso doesn't see new component objects on each render.
  // ---------------------------------------------------------------------------

  const tableRef = useRef(table);
  tableRef.current = table;

  // Memoized fixed-header renderer for Virtuoso. Defined with useCallback so
  // Virtuoso always sees the same function reference and does not unmount/remount
  // the sticky header on every parent re-render (which would cause scroll-to-top
  // flicker on sort).
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
                  style={{ width: meta?.width }}
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
                  style={{ width: meta?.width }}
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
