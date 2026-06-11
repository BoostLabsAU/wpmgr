// PortalRecentWork — day-grouped timeline of up to 20 recent successful
// updates and backups from summary.recent_work.
//
// Proof-of-work sentence ("In the last 30 days, {agency} performed X updates
// and Y backups") is shown only when at least one count is non-zero.
//
// Day groups: Today / Yesterday / short date. Each item shows an icon
// (RefreshCw for updates, DatabaseBackup for backups), site name, label, and
// relative timestamp.
//
// Empty state: muted "Work performed on your sites will appear here."

import { RefreshCw, DatabaseBackup } from "lucide-react";
import { relativeTime } from "@/lib/utils";
import type { PortalRecentWorkItem, PortalSummaryTotals } from "./use-portal";

// ---------------------------------------------------------------------------
// Day grouping helpers
// ---------------------------------------------------------------------------

function dayKey(iso: string): string {
  return iso.slice(0, 10); // "YYYY-MM-DD"
}

function dayLabel(iso: string): string {
  const d = new Date(iso);
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);

  if (dayKey(d.toISOString()) === dayKey(today.toISOString())) return "Today";
  if (dayKey(d.toISOString()) === dayKey(yesterday.toISOString()))
    return "Yesterday";
  return d.toLocaleDateString(undefined, {
    weekday: "short",
    month: "short",
    day: "numeric",
  });
}

interface GroupedWork {
  label: string;
  items: PortalRecentWorkItem[];
}

function groupByDay(items: PortalRecentWorkItem[]): GroupedWork[] {
  const groups: GroupedWork[] = [];
  let current: GroupedWork | null = null;

  for (const item of items) {
    const label = dayLabel(item.occurred_at);
    if (!current || current.label !== label) {
      current = { label, items: [] };
      groups.push(current);
    }
    current.items.push(item);
  }

  return groups;
}

// ---------------------------------------------------------------------------
// Proof-of-work sentence
// ---------------------------------------------------------------------------

interface ProofOfWorkProps {
  agencyName: string;
  totals: PortalSummaryTotals;
}

function ProofOfWork({ agencyName, totals }: ProofOfWorkProps) {
  const updates = totals.updates_applied;
  const backups = totals.backups_count;

  if (updates === 0 && backups === 0) return null;

  const agency = agencyName || "Your agency";
  const parts: string[] = [];
  if (updates > 0) {
    parts.push(
      `${updates.toLocaleString()} ${updates === 1 ? "update" : "updates"}`,
    );
  }
  if (backups > 0) {
    parts.push(
      `${backups.toLocaleString()} ${backups === 1 ? "backup" : "backups"}`,
    );
  }

  const summary =
    parts.length === 2
      ? `${parts[0]} and ${parts[1]}`
      : (parts[0] ?? "");

  return (
    <p className="mb-4 text-sm text-[var(--color-muted-foreground)]">
      In the last 30 days, {agency} performed{" "}
      <span className="font-medium text-[var(--color-foreground)]">
        {summary}
      </span>{" "}
      across your sites.
    </p>
  );
}

// ---------------------------------------------------------------------------
// Work item row
// ---------------------------------------------------------------------------

function WorkItemRow({ item }: { item: PortalRecentWorkItem }) {
  const timeLabel = relativeTime(item.occurred_at) ?? "";

  return (
    <div className="flex items-start gap-3 py-2">
      <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--color-muted)]">
        {item.type === "update" ? (
          <RefreshCw
            aria-hidden="true"
            className="size-3.5 text-[var(--color-muted-foreground)]"
          />
        ) : (
          <DatabaseBackup
            aria-hidden="true"
            className="size-3.5 text-[var(--color-muted-foreground)]"
          />
        )}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm text-[var(--color-foreground)]">
          {item.label}
        </p>
        <p className="text-xs text-[var(--color-muted-foreground)]">
          {item.site_name}
        </p>
      </div>
      <span className="shrink-0 text-xs text-[var(--color-muted-foreground)]">
        {timeLabel}
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalRecentWorkProps {
  items: PortalRecentWorkItem[];
  totals: PortalSummaryTotals;
  agencyName: string;
}

export function PortalRecentWork({
  items,
  totals,
  agencyName,
}: PortalRecentWorkProps) {
  if (items.length === 0) {
    return (
      <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-6 text-center">
        <p className="text-sm text-[var(--color-muted-foreground)]">
          Work performed on your sites will appear here.
        </p>
      </div>
    );
  }

  const groups = groupByDay(items);

  return (
    <div>
      <ProofOfWork agencyName={agencyName} totals={totals} />
      <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]">
        {groups.map((group, gi) => (
          <div key={group.label}>
            <div
              className={`border-b border-[var(--color-border)] bg-[var(--color-muted)]/30 px-4 py-1.5 ${gi === 0 ? "" : ""}`}
            >
              <p className="text-xs font-medium text-[var(--color-muted-foreground)]">
                {group.label}
              </p>
            </div>
            <div className="divide-y divide-[var(--color-border)] px-4">
              {group.items.map((item, ii) => (
                <WorkItemRow key={`${item.site_id}-${item.occurred_at}-${ii}`} item={item} />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
