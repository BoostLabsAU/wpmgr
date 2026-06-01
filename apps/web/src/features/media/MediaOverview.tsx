import {
  Ban,
  CheckCircle2,
  Clock,
  FileImage,
  HardDrive,
  Images,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { cn, formatBytes } from "@/lib/utils";

import type { MediaSummary } from "./types";

// MediaOverview — the 4 dashboard tiles: Total / Optimized / Pending / Bytes
// saved. Sourced from the listAssets summary rollup (summaryDTO). Every numeric
// is tabular-nums so the tiles read as a stable, scannable row. Borders over
// shadows per DESIGN; tiles are a single bordered strip, not nested cards.

interface Tile {
  key: keyof MediaSummary | "bytes" | "images";
  label: string;
  icon: LucideIcon;
  value: string;
  /** Optional secondary line under the value (e.g. "2,400 optimized"). */
  sub?: string;
  iconClass: string;
  tooltip?: string;
}

export interface MediaOverviewProps {
  summary: MediaSummary;
  className?: string;
}

export function MediaOverview({ summary, className }: MediaOverviewProps) {
  const tiles: Tile[] = [
    {
      key: "total",
      label: "Total assets",
      icon: FileImage,
      value: summary.total.toLocaleString(),
      iconClass: "text-[var(--color-muted-foreground)]",
      tooltip:
        "WordPress attachments (one per upload). Matches the rows in the table below.",
    },
    {
      key: "images",
      label: "Images (incl. thumbs)",
      icon: Images,
      value: summary.total_images.toLocaleString(),
      sub: `${summary.optimized_images.toLocaleString()} optimized`,
      iconClass: "text-[var(--color-muted-foreground)]",
      tooltip:
        "Every image FILE — each attachment's full image plus its generated thumbnails. The optimizer processes all of them.",
    },
    {
      key: "optimized",
      label: "Optimized",
      icon: CheckCircle2,
      value: summary.optimized.toLocaleString(),
      iconClass: "text-[var(--color-success)]",
    },
    {
      key: "pending",
      label: "Pending",
      icon: Clock,
      value: summary.pending.toLocaleString(),
      iconClass: "text-[var(--color-info)]",
      tooltip:
        "JPEG/PNG (and GIF) awaiting optimization. WebP, AVIF, SVG, HEIC are synced but can’t be optimized — see Unsupported.",
    },
    {
      key: "unsupported",
      label: "Unsupported",
      icon: Ban,
      value: summary.unsupported.toLocaleString(),
      iconClass: "text-[var(--color-muted-foreground)]",
    },
    {
      key: "bytes",
      label: "Bytes saved",
      icon: HardDrive,
      value: formatBytes(summary.bytes_saved),
      iconClass: "text-[var(--color-primary)]",
    },
  ];

  return (
    <dl
      aria-label="Media optimization summary"
      className={cn(
        "grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-border)] sm:grid-cols-3 lg:grid-cols-6",
        className,
      )}
    >
      {tiles.map((t) => {
        const Icon = t.icon;
        return (
          <div
            key={t.key}
            className="flex flex-col gap-1 bg-[var(--color-card)] p-4"
          >
            <dt
              title={t.tooltip}
              className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-[0.02em] text-[var(--color-muted-foreground)]"
            >
              <Icon aria-hidden="true" className={cn("size-3.5", t.iconClass)} />
              {t.label}
            </dt>
            <dd className="text-2xl font-semibold tabular-nums text-[var(--color-foreground)]">
              {t.value}
            </dd>
            {t.sub ? (
              <span className="text-xs tabular-nums text-[var(--color-muted-foreground)]">
                {t.sub}
              </span>
            ) : null}
          </div>
        );
      })}
    </dl>
  );
}
