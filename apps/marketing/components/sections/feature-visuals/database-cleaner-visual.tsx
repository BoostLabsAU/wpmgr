"use client";

import { cn } from "@/lib/utils";

// Illustrative database health scan widget for the database-cleaner feature page.
// Sample data only.

const TABLES = [
  { name: "wp_posts", rows: 4218, size: "14.2 MB", owner: "WordPress core", flagged: false },
  { name: "wp_postmeta", rows: 31420, size: "42.8 MB", owner: "WordPress core", flagged: false },
  { name: "wp_options (autoloaded)", rows: 892, size: "8.1 MB", owner: "Various plugins", flagged: true },
  { name: "wp_redirection_items", rows: 14012, size: "18.6 MB", owner: "Orphaned (plugin inactive)", flagged: true },
  { name: "wp_revisions", rows: 8944, size: "22.3 MB", owner: "WordPress core", flagged: true },
] as const;

const TREND = [48, 51, 54, 52, 58, 61, 57, 55, 53, 50, 48, 45];
const TREND_LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

export function DatabaseCleanerVisual() {
  const max = Math.max(...TREND);
  const min = Math.min(...TREND);
  const range = max - min || 1;
  const H = 48;

  const pts = TREND.map((v, i) => ({
    x: (i / (TREND.length - 1)) * 240,
    y: H - ((v - min) / range) * H,
  }));
  const polyline = pts.map((p) => `${p.x},${p.y}`).join(" ");

  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Database scan results</span>
        <span className="rounded-md bg-[var(--warning-subtle-fg)]/12 px-2 py-0.5 text-xs font-medium text-[var(--warning-subtle-fg)]">
          3 tables flagged
        </span>
      </div>

      {/* Table list */}
      <div className="flex flex-col gap-1.5">
        {TABLES.map((t) => (
          <div
            key={t.name}
            className={cn(
              "flex items-center gap-2.5 rounded-lg border px-3 py-2",
              t.flagged
                ? "border-[var(--warning-subtle-fg)]/30 bg-[var(--warning-subtle-fg)]/5"
                : "border-[var(--border)]/60 bg-[var(--background)]",
            )}
          >
            {t.flagged ? (
              <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--warning-subtle-fg)]" />
            ) : (
              <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--success)]" />
            )}
            <div className="min-w-0 flex-1">
              <span className="block truncate font-mono text-xs font-medium text-foreground">
                {t.name}
              </span>
              <span className="text-[10px] text-[var(--muted-foreground)]">{t.owner}</span>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-0.5">
              <span className="font-mono text-[10px] text-foreground">{t.size}</span>
              <span className="font-mono text-[10px] text-[var(--muted-foreground)]">
                {t.rows.toLocaleString()} rows
              </span>
            </div>
          </div>
        ))}
      </div>

      {/* 90-day trend */}
      <div className="rounded-lg bg-[var(--muted)]/50 p-3">
        <span className="mb-2 block text-xs font-medium text-[var(--muted-foreground)]">
          90-day database size trend (MB)
        </span>
        <svg viewBox={`0 0 240 ${H + 4}`} className="w-full" aria-hidden style={{ height: H + 4 }}>
          <polyline
            points={polyline}
            fill="none"
            strokeWidth={1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="stroke-[var(--primary)]"
          />
          {pts.map((p, i) => (
            <circle
              key={i}
              cx={p.x}
              cy={p.y}
              r={i === pts.length - 1 ? 3 : 1.5}
              className="fill-[var(--primary)]"
            />
          ))}
        </svg>
        <div className="mt-1 flex justify-between">
          {TREND_LABELS.filter((_, i) => i % 3 === 0).map((l) => (
            <span key={l} className="font-mono text-[10px] text-[var(--muted-foreground)]">{l}</span>
          ))}
        </div>
        <p className="mt-1.5 text-[10px] text-[var(--success)]">
          Trend declining after last clean run
        </p>
      </div>
    </div>
  );
}
