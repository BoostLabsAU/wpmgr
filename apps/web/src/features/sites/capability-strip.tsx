/**
 * CapabilityGroup — labeled 2-column grid replacing the old unlabeled icon row.
 *
 * Each capability renders as: status dot + text label (+ optional chip detail).
 * On/off encoded by opacity-100 vs opacity-40, never hue alone.
 * Screen readers see aria-label "{name}: enabled/disabled" on each item.
 *
 * Backwards-compat: CapabilityItem and CapabilityStrip are re-exported so
 * any other import of capability-strip still compiles without changes.
 */
import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

// ─── Types ────────────────────────────────────────────────────────────────────

export interface CapabilityItem {
  /** Lucide icon component to render. */
  icon: LucideIcon;
  /** Tooltip / aria label text. Also used as the visible text label. */
  label: string;
  /** When true the item is lit (enabled). When false it is dimmed (disabled). */
  enabled: boolean;
  /** Optional chip/badge/text rendered at the far right of the row. */
  detail?: ReactNode;
}

export interface CapabilityGroupProps {
  items: CapabilityItem[];
  className?: string;
}

// ─── Labeled grid component ───────────────────────────────────────────────────

/**
 * CapabilityGroup — 2-column labeled grid. Renders two items per row.
 * Always shows all items at a fixed height so cards align row-to-row.
 */
export function CapabilityGroup({ items, className }: CapabilityGroupProps) {
  if (items.length === 0) return null;

  // Pair items into rows of 2 for the 2-column grid.
  const rows: CapabilityItem[][] = [];
  for (let i = 0; i < items.length; i += 2) {
    rows.push(items.slice(i, i + 2));
  }

  return (
    <div className={cn("border-t border-border/50 pt-2 pb-1", className)}>
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">
        Site configuration
      </p>
      <div
        role="list"
        aria-label="Site capabilities"
        className="grid grid-cols-2 gap-x-3 gap-y-1"
      >
        {items.map(({ icon: Icon, label, enabled, detail }) => (
          <div
            key={label}
            role="listitem"
            aria-label={`${label}: ${enabled ? "enabled" : "disabled"}`}
            title={`${label}: ${enabled ? "enabled" : "disabled"}`}
            className={cn(
              "flex min-w-0 items-center gap-1.5 text-xs transition-opacity",
              enabled
                ? "opacity-100 text-foreground"
                : "opacity-40 text-muted-foreground",
            )}
          >
            {/* Status dot: filled green when enabled, gray hollow when disabled */}
            <span
              aria-hidden="true"
              className={cn(
                "inline-block size-1.5 shrink-0 rounded-full",
                enabled ? "bg-success" : "border border-muted-foreground/60",
              )}
            />
            <Icon aria-hidden="true" className="size-3 shrink-0" />
            <span className="truncate font-medium">{label}</span>
            {detail ? (
              <span className="ml-auto shrink-0 pl-1">{detail}</span>
            ) : null}
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Legacy alias ─────────────────────────────────────────────────────────────

/**
 * CapabilityStrip — backwards-compat shim; renders the same items as
 * CapabilityGroup. Keep existing import sites compiling unchanged.
 */
export function CapabilityStrip({
  items,
  className,
}: {
  items: CapabilityItem[];
  className?: string;
}) {
  return <CapabilityGroup items={items} className={className} />;
}

export interface CapabilityStripProps {
  items: CapabilityItem[];
  className?: string;
}
