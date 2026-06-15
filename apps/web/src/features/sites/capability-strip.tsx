import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

export interface CapabilityItem {
  /** Lucide icon component to render. */
  icon: LucideIcon;
  /** Tooltip text (aria title). */
  label: string;
  /** When true the glyph is lit (enabled). When false it is dimmed (disabled). */
  enabled: boolean;
}

export interface CapabilityStripProps {
  items: CapabilityItem[];
  className?: string;
}

/**
 * CapabilityStrip — a compact horizontal glyph row that encodes per-site
 * feature enablement. Lit glyphs use `text-foreground`; dimmed glyphs use
 * `text-muted-foreground/40`. On/off is encoded by opacity, NOT hue —
 * satisfying the Impeccable rule "status by color+label+shape not hue-alone".
 *
 * No text labels — each icon carries a `title` tooltip for accessibility.
 * Screen readers see the title via the wrapping `<span title>`.
 */
export function CapabilityStrip({ items, className }: CapabilityStripProps) {
  if (items.length === 0) return null;
  return (
    <div
      role="list"
      aria-label="Site capabilities"
      className={cn("flex items-center gap-2", className)}
    >
      {items.map(({ icon: Icon, label, enabled }) => (
        <span
          key={label}
          role="listitem"
          title={label}
          aria-label={`${label}: ${enabled ? "enabled" : "disabled"}`}
          className={cn(
            "inline-flex items-center justify-center transition-opacity",
            enabled
              ? "text-foreground opacity-100"
              : "text-muted-foreground opacity-30",
          )}
        >
          <Icon aria-hidden="true" className="size-3.5" />
        </span>
      ))}
    </div>
  );
}
