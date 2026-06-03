import { Database, FileStack, RefreshCw, Trash2 } from "lucide-react";

import { formatBytes, formatCount, formatWhen } from "../format";
import type { CacheStats } from "../types";

// Four cache overview tiles: cached pages, on-disk size, last purge, last
// preload. Honest empties: a never-measured value renders "–" / "Never" rather
// than a fabricated zero (Health-tab convention).

export interface CacheOverviewProps {
  stats: CacheStats | undefined;
}

export function CacheOverview({ stats }: CacheOverviewProps) {
  const tiles = [
    {
      icon: FileStack,
      label: "Cached pages",
      value: formatCount(stats?.cached_pages_count),
      hint: "Pages currently served from cache",
    },
    {
      icon: Database,
      label: "Cache size",
      value: formatBytes(stats?.cache_size_bytes),
      hint: "On-disk size of the cache",
    },
    {
      icon: Trash2,
      label: "Last purge",
      value: formatWhen(stats?.last_purged_at),
      hint: stats?.last_purge_kind
        ? `Scope: ${stats.last_purge_kind}`
        : "No purge recorded yet",
    },
    {
      icon: RefreshCw,
      label: "Last preload",
      value: formatWhen(stats?.last_preload_at),
      hint: "Most recent cache warm-up",
    },
  ];

  return (
    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-4">
      {tiles.map((tile) => (
        <div key={tile.label} className="space-y-2 bg-card p-4">
          <div className="flex items-center gap-1.5 text-muted-foreground">
            <tile.icon aria-hidden="true" className="size-4" />
            <span className="text-xs font-medium uppercase tracking-[0.02em]">
              {tile.label}
            </span>
          </div>
          <p className="text-2xl font-semibold tabular-nums text-foreground">
            {tile.value}
          </p>
          <p className="truncate text-xs text-muted-foreground" title={tile.hint}>
            {tile.hint}
          </p>
        </div>
      ))}
    </div>
  );
}
