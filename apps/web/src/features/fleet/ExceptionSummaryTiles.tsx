// ExceptionSummaryTiles — a row of status counter tiles that double as filter
// toggles. Clicking a tile toggles that status into/out of the active filter
// set; the surrounding table re-filters reactively.
//
// Design: operator-grade. Numbers use tabular-nums. Active = subtle bg tint
// matching the tile's semantic colour + ring. No gradient, no shadows on the
// tiles, borders only. Icon + number + label per tile.

import type { ReactNode } from "react";
import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

export interface TileDefinition {
  /** Machine-stable key, used as the filter value. */
  key: string;
  /** Human-readable label below the count. */
  label: string;
  /** The numeric count rendered large. */
  count: number;
  /** Lucide-compatible icon node (already sized by caller). */
  icon: ReactNode;
  /** Semantic colour family driving border/bg/text tints. */
  tone: "success" | "warning" | "destructive" | "muted" | "info" | "primary";
}

// Tone -> class maps. Uses semantic tokens; never raw hex.
const TILE_IDLE_CLASSES: Record<TileDefinition["tone"], string> = {
  success:
    "border-[var(--color-border)] hover:border-[var(--color-success)] hover:bg-[var(--color-success-subtle)]",
  warning:
    "border-[var(--color-border)] hover:border-[var(--color-warning)] hover:bg-[var(--color-warning-subtle)]",
  destructive:
    "border-[var(--color-border)] hover:border-[var(--color-destructive)] hover:bg-[var(--color-destructive-subtle)]",
  muted: "border-[var(--color-border)] hover:bg-[var(--color-muted)]",
  info: "border-[var(--color-border)] hover:border-[var(--color-info)] hover:bg-[var(--color-info-subtle)]",
  primary:
    "border-[var(--color-border)] hover:border-[var(--color-primary)] hover:bg-[var(--color-accent)]",
};

const TILE_ACTIVE_CLASSES: Record<TileDefinition["tone"], string> = {
  success:
    "border-[var(--color-success)] bg-[var(--color-success-subtle)] ring-1 ring-[var(--color-success)]",
  warning:
    "border-[var(--color-warning)] bg-[var(--color-warning-subtle)] ring-1 ring-[var(--color-warning)]",
  destructive:
    "border-[var(--color-destructive)] bg-[var(--color-destructive-subtle)] ring-1 ring-[var(--color-destructive)]",
  muted: "border-[var(--color-border)] bg-[var(--color-muted)] ring-1 ring-[var(--color-muted-foreground)]",
  info: "border-[var(--color-info)] bg-[var(--color-info-subtle)] ring-1 ring-[var(--color-info)]",
  primary:
    "border-[var(--color-primary)] bg-[var(--color-accent)] ring-1 ring-[var(--color-primary)]",
};

const COUNT_CLASSES: Record<TileDefinition["tone"], string> = {
  success: "text-[var(--color-success-subtle-fg)]",
  warning: "text-[var(--color-warning-subtle-fg)]",
  destructive: "text-[var(--color-destructive-subtle-fg)]",
  muted: "text-[var(--color-muted-foreground)]",
  info: "text-[var(--color-info-subtle-fg)]",
  primary: "text-[var(--color-primary)]",
};

export interface ExceptionSummaryTilesProps {
  tiles: TileDefinition[];
  /** Currently active filter keys. Empty = no filter (show all). */
  activeKeys: ReadonlySet<string>;
  onToggle: (key: string) => void;
  loading?: boolean;
}

export function ExceptionSummaryTiles({
  tiles,
  activeKeys,
  onToggle,
  loading = false,
}: ExceptionSummaryTilesProps) {
  if (loading) {
    return (
      <div
        role="status"
        aria-label="Loading status summary"
        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
      >
        <span className="sr-only">Loading</span>
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="flex flex-col gap-2 rounded-lg border border-[var(--color-border)] px-4 py-3"
          >
            <Skeleton className="h-5 w-5 rounded" />
            <Skeleton className="h-7 w-12 rounded" />
            <Skeleton className="h-3 w-16 rounded" />
          </div>
        ))}
      </div>
    );
  }

  const hasActiveFilter = activeKeys.size > 0;

  return (
    <div
      role="group"
      aria-label="Status filter"
      className="grid grid-cols-2 gap-3 sm:grid-cols-4"
    >
      {tiles.map((tile) => {
        const isActive = activeKeys.has(tile.key);
        // When a filter is active, dim tiles NOT in the active set.
        const isDimmed = hasActiveFilter && !isActive;
        return (
          <button
            key={tile.key}
            type="button"
            role="checkbox"
            aria-checked={isActive}
            aria-label={`${tile.label}: ${tile.count}. ${isActive ? "Remove filter" : "Filter by"} ${tile.label}`}
            onClick={() => onToggle(tile.key)}
            className={cn(
              "flex flex-col gap-2 rounded-lg border px-4 py-3 text-left",
              "transition-all duration-150 ease-out",
              "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-2",
              "cursor-pointer select-none",
              isActive
                ? TILE_ACTIVE_CLASSES[tile.tone]
                : TILE_IDLE_CLASSES[tile.tone],
              isDimmed && "opacity-50",
            )}
          >
            {/* Icon */}
            <span
              aria-hidden="true"
              className={cn(
                "size-5 text-[var(--color-muted-foreground)]",
                isActive && COUNT_CLASSES[tile.tone],
              )}
            >
              {tile.icon}
            </span>
            {/* Count */}
            <span
              className={cn(
                "text-2xl font-semibold tabular-nums leading-none",
                isActive
                  ? COUNT_CLASSES[tile.tone]
                  : "text-[var(--color-foreground)]",
              )}
            >
              {tile.count.toLocaleString()}
            </span>
            {/* Label */}
            <span className="text-xs text-[var(--color-muted-foreground)]">
              {tile.label}
            </span>
          </button>
        );
      })}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Filter-state helper hook
// ---------------------------------------------------------------------------

import { useState, useCallback } from "react";

// eslint-disable-next-line react-refresh/only-export-components -- hook co-located with its tile component; fast-refresh only applies to component-only files, not hook exports
export function useFilterToggle(initial: string[] = []) {
  const [activeKeys, setActiveKeys] = useState<ReadonlySet<string>>(
    new Set(initial),
  );
  const toggle = useCallback((key: string) => {
    setActiveKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  }, []);
  const clear = useCallback(() => setActiveKeys(new Set()), []);
  return { activeKeys, toggle, clear };
}
