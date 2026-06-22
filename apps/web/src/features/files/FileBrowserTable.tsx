import {
  forwardRef,
  memo,
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

import { cn, formatBytes, relativeTime } from "@/lib/utils";
import type { FileEntry } from "@wpmgr/api";

import { FileActionMenu } from "./FileActionMenu";

// FileBrowserTable — virtualized directory listing.
//
// Mirrors AssetsTable.tsx: TanStack Table v8 owns column defs + row model;
// react-virtuoso's <TableVirtuoso> handles the scroll container, sticky
// header, and row virtualization. The table uses role="grid" semantics.
//
// P2 additions:
//   - Actions column: per-row FileActionMenu (open/download/rename/chmod/delete).
//   - The "Edit" action is intentionally omitted from the row menu: the user
//     opens the file (clicks the row) to get the detail drawer, then clicks
//     "Edit" in the drawer where the content is already loaded. This avoids
//     loading file content for every visible table row.
//
// Columns: icon+name, size, modified, mode, actions.
// Directories sort first (done in use-files.ts, not here).

export interface FileBrowserTableProps {
  entries: FileEntry[];
  currentPath: string;
  /** Called when a folder row is clicked — navigates into it. */
  onNavigate: (path: string) => void;
  /** Called when a file row is clicked — opens the detail drawer. */
  onFileClick: (entry: FileEntry) => void;
  /** Called when the user scrolls near the bottom — triggers fetchNextPage. */
  onEndReached?: () => void;
  isFetchingNextPage?: boolean;
  // P2 props
  siteId: string;
  writeEnabled: boolean;
  canManage: boolean;
  isOwner: boolean;
}

// Stable per-row context so selection + click handlers don't change row identity.
interface RowContext {
  currentPath: string;
  onNavigate: (path: string) => void;
  onFileClick: (entry: FileEntry) => void;
  isFetchingNextPage: boolean;
  siteId: string;
  writeEnabled: boolean;
  canManage: boolean;
  isOwner: boolean;
}

const COL_SIZE_PX = 90;
const COL_MODIFIED_PX = 110;
const COL_MODE_PX = 100;
const COL_ACTIONS_PX = 48;

// Determine an entry's full relative path by combining the current dir path.
function entryPath(currentPath: string, name: string): string {
  return currentPath ? `${currentPath}/${name}` : name;
}

function buildColumns(): ColumnDef<FileEntry>[] {
  return [
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
      // Rendered in BodyCells where context is accessible.
      cell: () => null,
    },
  ];
}

const FILE_COLUMNS: ColumnDef<FileEntry>[] = buildColumns();

// ── Entry icon — memoized so SSE patches / parent re-renders don't re-create it.
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

// ── Virtuoso slots — hoisted so Virtuoso keeps stable component identity ────

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
      style={{ ...style, width: "100%", minWidth: "560px", tableLayout: "fixed" }}
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
}: FileBrowserTableProps) {
  const table = useReactTable({
    data: entries,
    columns: FILE_COLUMNS,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (e) => entryPath(currentPath, e.name),
  });

  const rows = table.getRowModel().rows;

  // STABLE component identity — deps [] — selection/click flow through context.
  const components = useMemo<TableComponents<Row<FileEntry>, RowContext>>(
    () => ({
      Scroller: VirtuosoScroller,
      Table: VirtuosoTable,
      TableHead: VirtuosoTableHead,
      TableRow: ({ item, style, context, ...rest }) => {
        const entry = item.original;
        const ep = entryPath(context?.currentPath ?? "", entry.name);
        return (
          <tr
            {...rest}
            style={style}
            role="row"
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
              "hover:bg-[var(--color-muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-inset",
            )}
          />
        );
      },
      Footer: ({ context }: { context?: RowContext }) =>
        context?.isFetchingNextPage ? (
          <tr>
            <td
              colSpan={5}
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
          <HeaderRow headerGroups={table.getHeaderGroups()} />
        )}
        itemContent={(_, row) => (
          <BodyCells
            row={row}
            context={rowContext}
          />
        )}
      />
    </div>
  );
}

function HeaderRow({
  headerGroups,
}: {
  headerGroups: ReturnType<
    ReturnType<typeof useReactTable<FileEntry>>["getHeaderGroups"]
  >;
}) {
  return (
    <>
      {headerGroups.map((hg) => (
        <tr
          key={hg.id}
          className="h-9 border-b border-[var(--color-border)] bg-[var(--color-background)]"
        >
          {hg.headers.map((header) => {
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

function BodyCells({
  row,
  context,
}: {
  row: Row<FileEntry>;
  context: RowContext;
}) {
  const entry = row.original;
  const ep = entryPath(context.currentPath, entry.name);

  return (
    <>
      {row.getVisibleCells().map((cell) => {
        if (cell.column.id === "actions") {
          return (
            <td
              key={cell.id}
              className="border-b border-[var(--color-border)] pr-2 align-middle"
              // Stop the row click handler from also firing when clicking the menu.
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
                // Edit is handled via the detail drawer (click row → open drawer → Edit)
                // to avoid loading content for every visible row.
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
