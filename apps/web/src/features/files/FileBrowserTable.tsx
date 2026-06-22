import {
  forwardRef,
  memo,
  useEffect,
  useRef,
  useMemo,
  type CSSProperties,
  type HTMLAttributes,
  type TableHTMLAttributes,
} from "react";
import { File, Folder, Link2, Loader2 } from "lucide-react";
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type Row,
} from "@tanstack/react-table";
import { TableVirtuoso, type TableComponents } from "react-virtuoso";

import { Checkbox } from "@/components/ui/checkbox";
import { cn, formatBytes, relativeTime } from "@/lib/utils";
import type { FileEntry } from "@wpmgr/api";

import { FileActionMenu } from "./FileActionMenu";

// FileBrowserTable — virtualized directory listing.
//
// P3 additions:
//   - Checkbox column for bulk selection (leftmost). Checkboxes only shown to
//     admin+ users (canManage=true). A header checkbox selects/deselects all.
//   - selectedPaths / onSelectionChange props for bulk archive.
//
// Columns (P3): checkbox | icon+name | size | modified | mode | actions.

export interface FileBrowserTableProps {
  entries: FileEntry[];
  currentPath: string;
  onNavigate: (path: string) => void;
  onFileClick: (entry: FileEntry) => void;
  onEndReached?: () => void;
  isFetchingNextPage?: boolean;
  siteId: string;
  writeEnabled: boolean;
  canManage: boolean;
  isOwner: boolean;
  // P3: bulk selection
  selectedPaths: string[];
  onSelectionChange: (paths: string[]) => void;
}

// Stable per-row context.
interface RowContext {
  currentPath: string;
  onNavigate: (path: string) => void;
  onFileClick: (entry: FileEntry) => void;
  isFetchingNextPage: boolean;
  siteId: string;
  writeEnabled: boolean;
  canManage: boolean;
  isOwner: boolean;
  selectedPaths: string[];
  onSelectionChange: (paths: string[]) => void;
}

const COL_CHECK_PX = 40;
const COL_SIZE_PX = 90;
const COL_MODIFIED_PX = 110;
const COL_MODE_PX = 100;
const COL_ACTIONS_PX = 48;

function entryPath(currentPath: string, name: string): string {
  return currentPath ? `${currentPath}/${name}` : name;
}

function buildColumns(): ColumnDef<FileEntry>[] {
  return [
    {
      id: "select",
      header: "",
      enableSorting: false,
      size: COL_CHECK_PX,
      cell: () => null, // rendered in BodyCells via context
    },
    {
      id: "name",
      header: "Name",
      enableSorting: false,
      cell: ({ row }) => {
        const e = row.original;
        return (
          <span className="flex min-w-0 items-center gap-2">
            <EntryIcon entry={e} />
            <span className="min-w-0 truncate font-mono text-sm text-[var(--color-foreground)]">
              {e.name}
            </span>
            {e.is_link ? (
              <span
                aria-label="Symbolic link"
                title="Symbolic link"
                className="inline-flex shrink-0 items-center rounded border border-[var(--color-border)] px-1 py-px font-mono text-[10px] text-[var(--color-muted-foreground)]"
              >
                <Link2 aria-hidden="true" className="mr-0.5 size-2.5" />
                symlink
              </span>
            ) : null}
          </span>
        );
      },
    },
    {
      id: "size",
      header: "Size",
      enableSorting: false,
      size: COL_SIZE_PX,
      cell: ({ row }) => {
        const e = row.original;
        return (
          <span
            className={cn(
              "font-mono text-xs tabular-nums",
              e.is_dir
                ? "text-[var(--color-muted-foreground)]/60"
                : "text-[var(--color-muted-foreground)]",
            )}
          >
            {e.is_dir ? "" : formatBytes(e.size)}
          </span>
        );
      },
    },
    {
      id: "modified",
      header: "Modified",
      enableSorting: false,
      size: COL_MODIFIED_PX,
      cell: ({ row }) => {
        const iso = new Date(row.original.mtime * 1000).toISOString();
        return (
          <span
            title={new Date(row.original.mtime * 1000).toLocaleString()}
            className="font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]"
          >
            {relativeTime(iso) ?? "—"}
          </span>
        );
      },
    },
    {
      id: "mode",
      header: "Mode",
      enableSorting: false,
      size: COL_MODE_PX,
      cell: ({ row }) => (
        <span className="font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
          {row.original.mode}
        </span>
      ),
    },
    {
      id: "actions",
      header: "",
      enableSorting: false,
      size: COL_ACTIONS_PX,
      cell: () => null,
    },
  ];
}

const FILE_COLUMNS: ColumnDef<FileEntry>[] = buildColumns();

const EntryIcon = memo(function EntryIcon({ entry }: { entry: FileEntry }) {
  if (entry.is_dir) {
    return (
      <Folder
        aria-hidden="true"
        className="size-4 shrink-0 text-[var(--color-muted-foreground)]"
      />
    );
  }
  return (
    <File
      aria-hidden="true"
      className="size-4 shrink-0 text-[var(--color-muted-foreground)]/70"
    />
  );
});

// ── Virtuoso slots ────────────────────────────────────────────────────────────

const VirtuosoScroller = forwardRef<
  HTMLDivElement,
  HTMLAttributes<HTMLDivElement>
>(function VirtuosoScroller(props, ref) {
  return (
    <div
      ref={ref}
      {...props}
      className={cn("overflow-auto focus-visible:outline-none", props.className)}
    />
  );
});

function VirtuosoTable({
  style,
  ...rest
}: TableHTMLAttributes<HTMLTableElement>) {
  return (
    <table
      {...rest}
      role="grid"
      aria-label="File browser"
      style={{ ...style, width: "100%", minWidth: "600px", tableLayout: "fixed" }}
      className="border-collapse"
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
      className={cn(
        "sticky top-0 z-10 bg-[var(--color-background)]",
        props.className,
      )}
    />
  );
});

// ── Main component ────────────────────────────────────────────────────────────

export function FileBrowserTable({
  entries,
  currentPath,
  onNavigate,
  onFileClick,
  onEndReached,
  isFetchingNextPage = false,
  siteId,
  writeEnabled,
  canManage,
  isOwner,
  selectedPaths,
  onSelectionChange,
}: FileBrowserTableProps) {
  const table = useReactTable({
    data: entries,
    columns: FILE_COLUMNS,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (e) => entryPath(currentPath, e.name),
  });

  const rows = table.getRowModel().rows;

  const allEntryPaths = useMemo(
    () => entries.map((e) => entryPath(currentPath, e.name)),
    [entries, currentPath],
  );
  const allSelected =
    allEntryPaths.length > 0 &&
    allEntryPaths.every((p) => selectedPaths.includes(p));
  const someSelected =
    !allSelected && allEntryPaths.some((p) => selectedPaths.includes(p));

  const handleToggleAll = (checked: boolean) => {
    if (checked) {
      onSelectionChange([
        ...new Set([...selectedPaths, ...allEntryPaths]),
      ]);
    } else {
      onSelectionChange(
        selectedPaths.filter((p) => !allEntryPaths.includes(p)),
      );
    }
  };

  const components = useMemo<TableComponents<Row<FileEntry>, RowContext>>(
    () => ({
      Scroller: VirtuosoScroller,
      Table: VirtuosoTable,
      TableHead: VirtuosoTableHead,
      TableRow: ({ item, style, context, ...rest }) => {
        const entry = item.original;
        const ep = entryPath(context?.currentPath ?? "", entry.name);
        const isSelected = context?.selectedPaths.includes(ep) ?? false;
        return (
          <tr
            {...rest}
            style={style}
            role="row"
            aria-selected={isSelected}
            aria-label={
              entry.is_dir
                ? `Directory: ${entry.name}`
                : `File: ${entry.name}`
            }
            tabIndex={0}
            onClick={() => {
              if (!context) return;
              if (entry.is_dir) {
                context.onNavigate(ep);
              } else {
                context.onFileClick(entry);
              }
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                if (!context) return;
                if (entry.is_dir) {
                  context.onNavigate(ep);
                } else {
                  context.onFileClick(entry);
                }
              }
            }}
            className={cn(
              "h-10 cursor-pointer border-b border-[var(--color-border)] transition-colors duration-[80ms]",
              isSelected && "bg-[var(--color-primary)]/8",
              "hover:bg-[var(--color-muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-inset",
            )}
          />
        );
      },
      Footer: ({ context }: { context?: RowContext }) =>
        context?.isFetchingNextPage ? (
          <tr>
            <td
              colSpan={6}
              className="border-t border-[var(--color-border)] px-4 py-3 text-center text-xs text-[var(--color-muted-foreground)]"
              aria-live="polite"
              aria-label="Loading more entries"
            >
              <span className="inline-flex items-center gap-1.5">
                <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                Loading more...
              </span>
            </td>
          </tr>
        ) : null,
    }),
    [],
  );

  const rowContext = useMemo<RowContext>(
    () => ({
      currentPath,
      onNavigate,
      onFileClick,
      isFetchingNextPage,
      siteId,
      writeEnabled,
      canManage,
      isOwner,
      selectedPaths,
      onSelectionChange,
    }),
    [
      currentPath,
      onNavigate,
      onFileClick,
      isFetchingNextPage,
      siteId,
      writeEnabled,
      canManage,
      isOwner,
      selectedPaths,
      onSelectionChange,
    ],
  );

  return (
    <div className="relative h-[calc(100vh-22rem)] min-h-[300px] w-full overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]">
      <TableVirtuoso<Row<FileEntry>, RowContext>
        data={rows}
        context={rowContext}
        totalCount={rows.length}
        components={components}
        computeItemKey={(_, row) => row.id}
        endReached={onEndReached}
        fixedHeaderContent={() => (
          <HeaderRow
            headerGroups={table.getHeaderGroups()}
            canManage={canManage}
            allSelected={allSelected}
            someSelected={someSelected}
            onToggleAll={handleToggleAll}
          />
        )}
        itemContent={(_, row) => (
          <BodyCells row={row} context={rowContext} />
        )}
      />
    </div>
  );
}

// ── Header row ────────────────────────────────────────────────────────────────

// ── Indeterminate checkbox ────────────────────────────────────────────────────

function IndeterminateCheckbox({
  checked,
  indeterminate,
  onChange,
  "aria-label": ariaLabel,
}: {
  checked: boolean;
  indeterminate: boolean;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  "aria-label": string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) {
      ref.current.indeterminate = indeterminate;
    }
  }, [indeterminate]);

  return (
    <Checkbox
      ref={ref}
      checked={checked}
      onChange={onChange}
      aria-label={ariaLabel}
    />
  );
}

function HeaderRow({
  headerGroups,
  canManage,
  allSelected,
  someSelected,
  onToggleAll,
}: {
  headerGroups: ReturnType<
    ReturnType<typeof useReactTable<FileEntry>>["getHeaderGroups"]
  >;
  canManage: boolean;
  allSelected: boolean;
  someSelected: boolean;
  onToggleAll: (checked: boolean) => void;
}) {
  return (
    <>
      {headerGroups.map((hg) => (
        <tr
          key={hg.id}
          className="h-9 border-b border-[var(--color-border)] bg-[var(--color-background)]"
        >
          {hg.headers.map((header) => {
            if (header.column.id === "select") {
              return (
                <th
                  key={header.id}
                  scope="col"
                  style={{ width: COL_CHECK_PX, minWidth: COL_CHECK_PX }}
                  className="px-3 align-middle"
                >
                  {canManage ? (
                    <IndeterminateCheckbox
                      checked={allSelected}
                      indeterminate={someSelected}
                      onChange={(e) => onToggleAll(e.target.checked)}
                      aria-label={
                        allSelected ? "Deselect all" : "Select all"
                      }
                    />
                  ) : null}
                </th>
              );
            }
            const width = (header.column.columnDef.size ?? 0) || undefined;
            const style: CSSProperties = width
              ? { width, minWidth: width }
              : {};
            const isNumeric =
              header.column.id === "size" || header.column.id === "modified";
            return (
              <th
                key={header.id}
                scope="col"
                style={style}
                className={cn(
                  "px-3 text-left align-middle text-xs font-medium uppercase tracking-[0.02em] text-[var(--color-muted-foreground)]",
                  isNumeric && "text-right",
                  header.column.id === "name" && "pl-4",
                  header.column.id === "actions" && "pr-2",
                )}
              >
                {header.isPlaceholder
                  ? null
                  : flexRender(
                      header.column.columnDef.header,
                      header.getContext(),
                    )}
              </th>
            );
          })}
        </tr>
      ))}
    </>
  );
}

// ── Body cells ────────────────────────────────────────────────────────────────

function BodyCells({
  row,
  context,
}: {
  row: Row<FileEntry>;
  context: RowContext;
}) {
  const entry = row.original;
  const ep = entryPath(context.currentPath, entry.name);
  const isSelected = context.selectedPaths.includes(ep);

  const handleToggle = (e: React.ChangeEvent<HTMLInputElement>) => {
    e.stopPropagation();
    if (e.target.checked) {
      context.onSelectionChange([...context.selectedPaths, ep]);
    } else {
      context.onSelectionChange(
        context.selectedPaths.filter((p) => p !== ep),
      );
    }
  };

  return (
    <>
      {row.getVisibleCells().map((cell) => {
        if (cell.column.id === "select") {
          return (
            <td
              key={cell.id}
              style={{ width: COL_CHECK_PX, minWidth: COL_CHECK_PX }}
              className="border-b border-[var(--color-border)] px-3 align-middle"
              onClick={(e) => e.stopPropagation()}
            >
              {context.canManage ? (
                <Checkbox
                  checked={isSelected}
                  onChange={handleToggle}
                  aria-label={`Select ${entry.name}`}
                />
              ) : null}
            </td>
          );
        }
        if (cell.column.id === "actions") {
          return (
            <td
              key={cell.id}
              className="border-b border-[var(--color-border)] pr-2 align-middle"
              onClick={(e) => e.stopPropagation()}
            >
              <FileActionMenu
                siteId={context.siteId}
                entry={entry}
                currentDirPath={context.currentPath}
                entryPath={ep}
                writeEnabled={context.writeEnabled}
                canManage={context.canManage}
                isOwner={context.isOwner}
                onOpen={() => context.onNavigate(ep)}
              />
            </td>
          );
        }
        const isNumeric =
          cell.column.id === "size" || cell.column.id === "modified";
        return (
          <td
            key={cell.id}
            className={cn(
              "border-b border-[var(--color-border)] px-3 align-middle",
              cell.column.id === "name" && "pl-4",
              isNumeric && "text-right",
            )}
          >
            {flexRender(cell.column.columnDef.cell, cell.getContext())}
          </td>
        );
      })}
    </>
  );
}
