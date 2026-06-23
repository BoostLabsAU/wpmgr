import { useCallback, useMemo } from "react";
import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import {
  ClipboardList,
  ShieldCheck,
  ShieldAlert,
  ChevronLeft,
  ChevronRight,
  Inbox,
  RefreshCw,
} from "lucide-react";
import { z } from "zod";

import { PageHeader } from "@/components/shared/page-header";
import { PageError } from "@/components/feedback";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { cn, relativeTime } from "@/lib/utils";
import { useSites } from "@/features/sites/use-sites";
import { useAudit, useAuditVerify } from "@/features/audit/use-audit";
import type { AuditEntry } from "@wpmgr/api";

// ---------------------------------------------------------------------------
// Route definition + search params
// ---------------------------------------------------------------------------

const searchSchema = z.object({
  // Prefix-match on the action field; "" means all.
  action: z.string().optional(),
  // UUID of a specific site; "" means all sites.
  site_id: z.string().optional(),
  // Pagination offset.
  offset: z.number().int().nonnegative().optional(),
});

type AuditSearch = z.infer<typeof searchSchema>;

export const Route = createFileRoute("/_authed/audit")({
  validateSearch: searchSchema,
  component: AuditPage,
});

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const PAGE_LIMIT = 50;

// Quick-filter presets for the action field.
const ACTION_PRESETS: { label: string; value: string }[] = [
  { label: "All events", value: "" },
  { label: "File manager", value: "site.files." },
  { label: "Backups", value: "backup." },
  { label: "Updates", value: "update." },
  { label: "Settings", value: "settings." },
  { label: "Security", value: "security." },
  { label: "Cache", value: "cache." },
];

// ---------------------------------------------------------------------------
// Action label mapping
// ---------------------------------------------------------------------------

/**
 * Derive a human-readable label from an audit action string.
 *
 * Strategy: check exact known actions first, then fall back to
 * segment-based decomposition so new actions get a reasonable label
 * automatically without needing to hand-code every one.
 */
function actionLabel(action: string): string {
  // Check for denied suffix first so it reads clearly regardless of domain.
  if (action.endsWith(".denied")) {
    const base = action.slice(0, -".denied".length);
    return `${actionLabel(base)} (denied)`;
  }

  // Exact or prefix-matched known labels.
  const KNOWN: Record<string, string> = {
    // File manager
    "site.files.read": "Read file",
    "site.files.write": "Edited file",
    "site.files.delete": "Deleted file",
    "site.files.extract": "Extracted archive",
    "site.files.archive": "Created archive",
    "site.files.mkdir": "Created folder",
    "site.files.rename": "Renamed file",
    "site.files.chmod": "Changed permissions",
    "site.files.upload": "Uploaded file",
    "site.files.download": "Downloaded file",
    "site.files.search": "Searched files",
    "site.files.version.list": "Viewed version history",
    "site.files.version.restore": "Restored file version",
    // Backups
    "backup.create": "Created backup",
    "backup.restore": "Restored backup",
    "backup.delete": "Deleted backup",
    "backup.schedule.update": "Updated backup schedule",
    "backup.download": "Downloaded backup",
    // Updates
    "update.run": "Ran updates",
    "update.schedule.update": "Updated update schedule",
    // Settings
    "settings.update": "Updated settings",
    "settings.smtp.update": "Updated SMTP settings",
    // Security
    "security.ban.create": "Created ban rule",
    "security.ban.delete": "Deleted ban rule",
    "security.hardening.update": "Updated hardening",
    "security.2fa.enable": "Enabled 2FA",
    "security.2fa.disable": "Disabled 2FA",
    // Cache
    "cache.purge": "Purged cache",
    "cache.enable": "Enabled cache",
    "cache.disable": "Disabled cache",
  };

  if (action in KNOWN) return KNOWN[action] as string;

  // Segment-based fallback: take the last two segments and title-case them.
  const parts = action.split(".");
  if (parts.length >= 2) {
    const last = parts[parts.length - 1] ?? "";
    const prev = parts[parts.length - 2] ?? "";
    const verb = last.charAt(0).toUpperCase() + last.slice(1);
    const noun = prev.charAt(0).toUpperCase() + prev.slice(1);
    return `${verb} ${noun}`;
  }

  // Last resort: title-case the whole action.
  return action.charAt(0).toUpperCase() + action.slice(1);
}

/** True when the action represents a denied access attempt. */
function isDenied(action: string): boolean {
  return action.endsWith(".denied");
}

/** True when the action is a destructive or write operation. */
function isDestructive(action: string): boolean {
  return (
    action.includes(".delete") ||
    action.includes(".restore") ||
    action.includes(".extract") ||
    action.includes(".chmod") ||
    action.includes(".ban.")
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function AuditPage() {
  const navigate = useNavigate({ from: Route.fullPath });
  const search = Route.useSearch();

  const action = search.action ?? "";
  const siteId = search.site_id ?? "";
  const offset = search.offset ?? 0;

  // We build a local "action preset" state that reflects the dropdown; if the
  // user types a site_id deep-link with action=site.files. that value will
  // already match one of the presets so the chip group highlights correctly.
  const activePreset = ACTION_PRESETS.find((p) => p.value === action)?.value ?? action;

  const setFilter = useCallback(
    (patch: Partial<AuditSearch>) => {
      void navigate({
        search: (prev: AuditSearch) => ({
          ...prev,
          ...patch,
          // Reset to page 0 whenever any filter changes.
          offset: "offset" in patch ? patch.offset : 0,
        }),
        replace: true,
      });
    },
    [navigate],
  );

  // Sites list for the site dropdown (share the existing cache).
  const { data: sites = [] } = useSites();

  // Audit data.
  const { items, isPending, isError, error, refetch } = useAudit({
    action,
    siteId,
    limit: PAGE_LIMIT,
    offset,
  });

  // Integrity check.
  const verify = useAuditVerify();

  // Group by calendar day for the timeline display.
  const groups = useMemo(() => groupByDay(items), [items]);

  const totalOnPage = items.length;
  const hasPrev = offset > 0;
  const hasNext = totalOnPage === PAGE_LIMIT;

  return (
    <div className="space-y-6 px-4 pb-10 pt-6 sm:px-6">
      <PageHeader
        title="Audit log"
        subline="Fleet-wide operator event stream, newest first."
        actions={
          <div className="flex items-center gap-2">
            <IntegrityBadge verify={verify} />
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => void refetch()}
              className="gap-1.5"
            >
              <RefreshCw aria-hidden="true" className="size-3.5" />
              Reload
            </Button>
          </div>
        }
      />

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        {/* Action preset chips */}
        <div
          className="inline-flex flex-wrap items-center gap-1 rounded-md border border-[var(--color-border)] bg-[var(--color-card)] p-0.5"
          role="group"
          aria-label="Filter by action category"
        >
          {ACTION_PRESETS.map((preset) => {
            const selected = activePreset === preset.value;
            return (
              <button
                key={preset.value}
                type="button"
                aria-pressed={selected}
                onClick={() => setFilter({ action: preset.value || undefined })}
                className={cn(
                  "rounded px-2.5 py-1 text-xs font-medium transition-colors",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-1",
                  selected
                    ? "bg-[var(--color-muted)] text-[var(--color-foreground)]"
                    : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]",
                )}
              >
                {preset.label}
              </button>
            );
          })}
        </div>

        {/* Site filter */}
        {sites.length > 0 ? (
          <div className="w-[220px]">
            <label htmlFor="audit-site-filter" className="sr-only">
              Filter by site
            </label>
            <Select
              id="audit-site-filter"
              value={siteId}
              onChange={(e) =>
                setFilter({ site_id: e.target.value || undefined })
              }
              aria-label="Filter by site"
            >
              <option value="">All sites</option>
              {sites.map((site) => (
                <option key={site.id} value={site.id}>
                  {site.name ?? site.url}
                </option>
              ))}
            </Select>
          </div>
        ) : null}
      </div>

      {/* Content */}
      {isPending ? (
        <AuditSkeleton />
      ) : isError ? (
        <PageError
          what="Could not load the audit log."
          why={error instanceof Error ? error.message : "Unknown error"}
          onRetry={() => void refetch()}
          retryLabel="Reload audit log"
        />
      ) : items.length === 0 ? (
        <EmptyAudit action={action} siteId={siteId} sites={sites} />
      ) : (
        <div className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]">
          {groups.map((group) => (
            <DayGroup
              key={group.key}
              label={group.label}
              entries={group.entries}
              sites={sites}
            />
          ))}
        </div>
      )}

      {/* Pagination */}
      {!isPending && !isError && items.length > 0 ? (
        <div className="flex items-center justify-between gap-3 px-1">
          <span className="text-xs tabular-nums text-[var(--color-muted-foreground)]">
            {offset + 1}&ndash;{offset + totalOnPage} events
          </span>
          <div className="flex items-center gap-1">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!hasPrev}
              onClick={() => setFilter({ offset: Math.max(0, offset - PAGE_LIMIT) })}
              className="gap-1"
              aria-label="Previous page"
            >
              <ChevronLeft aria-hidden="true" className="size-4" />
              Prev
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!hasNext}
              onClick={() => setFilter({ offset: offset + PAGE_LIMIT })}
              className="gap-1"
              aria-label="Next page"
            >
              Next
              <ChevronRight aria-hidden="true" className="size-4" />
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Integrity badge
// ---------------------------------------------------------------------------

function IntegrityBadge({
  verify,
}: {
  verify: ReturnType<typeof useAuditVerify>;
}) {
  if (verify.isPending) {
    return (
      <span className="inline-flex items-center rounded border border-[var(--color-border)] bg-[var(--color-muted)] px-2 py-0.5 text-xs font-medium text-[var(--color-muted-foreground)]">
        Verifying
      </span>
    );
  }
  if (verify.isError || !verify.data) {
    return null;
  }
  if (verify.data.ok) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded bg-[var(--color-success-subtle,oklch(94%_0.05_145))] px-2 py-0.5 text-xs font-medium text-[var(--color-success-subtle-fg,oklch(38%_0.1_145))]">
        <ShieldCheck aria-hidden="true" className="size-3.5" />
        Chain verified
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1.5 rounded bg-[var(--color-destructive-subtle,oklch(95%_0.04_25))] px-2 py-0.5 text-xs font-medium text-[var(--color-destructive-subtle-fg,oklch(40%_0.15_25))]">
      <ShieldAlert aria-hidden="true" className="size-3.5" />
      Chain break
      {verify.data.broken_at ? (
        <span className="font-mono tabular-nums">{verify.data.broken_at}</span>
      ) : null}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Day grouping
// ---------------------------------------------------------------------------

interface DayGroupData {
  key: string;
  label: string;
  entries: AuditEntry[];
}

function groupByDay(entries: AuditEntry[]): DayGroupData[] {
  const groups: DayGroupData[] = [];
  const byKey = new Map<string, DayGroupData>();
  const now = new Date();
  const todayKey = localDayKey(now);
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  const yesterdayKey = localDayKey(yesterday);

  for (const entry of entries) {
    const date = new Date(entry.created_at);
    const key = Number.isNaN(date.getTime()) ? "unknown" : localDayKey(date);
    let group = byKey.get(key);
    if (!group) {
      group = {
        key,
        label: dayLabel(key, date, todayKey, yesterdayKey),
        entries: [],
      };
      byKey.set(key, group);
      groups.push(group);
    }
    group.entries.push(entry);
  }
  return groups;
}

function localDayKey(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function dayLabel(
  key: string,
  date: Date,
  todayKey: string,
  yesterdayKey: string,
): string {
  if (key === "unknown") return "Unknown date";
  if (key === todayKey) return "Today";
  if (key === yesterdayKey) return "Yesterday";
  return date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

// ---------------------------------------------------------------------------
// DayGroup + AuditEntryRow
// ---------------------------------------------------------------------------

type SiteMin = { id: string; name?: string | null; url: string };

function DayGroup({
  label,
  entries,
  sites,
}: {
  label: string;
  entries: AuditEntry[];
  sites: SiteMin[];
}) {
  return (
    <div>
      <div className="sticky top-0 z-10 border-b border-[var(--color-border)] bg-[var(--color-card)]/95 px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)] backdrop-blur supports-[backdrop-filter]:bg-[var(--color-card)]/80">
        {label}
      </div>
      <ul className="divide-y divide-[var(--color-border)]">
        {entries.map((entry) => (
          <li key={entry.id}>
            <AuditEntryRow entry={entry} sites={sites} />
          </li>
        ))}
      </ul>
    </div>
  );
}

function AuditEntryRow({
  entry,
  sites,
}: {
  entry: AuditEntry;
  sites: SiteMin[];
}) {
  const denied = isDenied(entry.action);
  const destructive = isDestructive(entry.action);
  const label = actionLabel(entry.action);
  const rel = relativeTime(entry.created_at);

  // Resolve target site name.
  const targetSite =
    entry.target_type === "site"
      ? (sites.find((s) => s.id === entry.target_id) ?? null)
      : null;

  // Pull path from metadata for file ops.
  const filePath =
    entry.metadata && typeof entry.metadata["path"] === "string"
      ? entry.metadata["path"]
      : null;

  // Outcome chip: "denied" from action suffix, else "allowed".
  const outcome: "denied" | "allowed" | "destructive" = denied
    ? "denied"
    : destructive
      ? "destructive"
      : "allowed";

  return (
    <div
      className={cn(
        "flex items-start gap-3 px-4 py-3",
        denied &&
          "border-l-2 border-[var(--color-destructive)] bg-[var(--color-destructive)]/5",
      )}
    >
      {/* Outcome indicator dot */}
      <OutcomeDot outcome={outcome} />

      {/* Content */}
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        {/* Line 1: label + time */}
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
            <span
              className={cn(
                "text-sm font-medium",
                denied
                  ? "text-[var(--color-destructive)]"
                  : "text-[var(--color-foreground)]",
              )}
            >
              {label}
            </span>
            <OutcomeChip outcome={outcome} />
          </div>
          <time
            dateTime={entry.created_at}
            title={entry.created_at}
            className="shrink-0 text-xs tabular-nums text-[var(--color-muted-foreground)]"
          >
            {rel ?? "just now"}
          </time>
        </div>

        {/* Line 2: actor + target */}
        <div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-[var(--color-muted-foreground)]">
          {/* Action */}
          <span className="rounded-sm bg-[var(--color-muted)] px-1.5 py-0.5 font-mono text-[11px]">
            {entry.action}
          </span>

          {/* Actor */}
          <span aria-hidden="true">·</span>
          <span>
            {entry.actor_type === "system" || !entry.actor_id ? (
              <span className="italic">system</span>
            ) : (
              <span className="font-medium text-[var(--color-foreground)]">
                {entry.actor_id}
              </span>
            )}
          </span>

          {/* Target site */}
          {targetSite ? (
            <>
              <span aria-hidden="true">·</span>
              <span className="font-medium text-[var(--color-foreground)]">
                {targetSite.name ?? targetSite.url}
              </span>
            </>
          ) : entry.target_id ? (
            <>
              <span aria-hidden="true">·</span>
              <span className="font-mono text-[11px] text-[var(--color-muted-foreground)]">
                {entry.target_type}/{entry.target_id.slice(0, 8)}
              </span>
            </>
          ) : null}

          {/* File path (from metadata) */}
          {filePath ? (
            <>
              <span aria-hidden="true">·</span>
              <span
                className="max-w-[280px] truncate font-mono text-[11px]"
                title={filePath}
              >
                {filePath}
              </span>
            </>
          ) : null}
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Outcome chip + dot
// ---------------------------------------------------------------------------

type Outcome = "allowed" | "denied" | "destructive";

function OutcomeDot({ outcome }: { outcome: Outcome }) {
  const cls =
    outcome === "denied"
      ? "bg-[var(--color-destructive)]"
      : outcome === "destructive"
        ? "bg-[var(--color-warning,oklch(72%_0.15_70))]"
        : "bg-[var(--color-muted-foreground)]";

  return (
    <span
      aria-hidden="true"
      className={cn("mt-2 size-1.5 shrink-0 rounded-full", cls)}
    />
  );
}

function OutcomeChip({ outcome }: { outcome: Outcome }) {
  if (outcome === "denied") {
    return (
      <span className="inline-flex items-center gap-1 rounded bg-[var(--color-destructive)]/15 px-1.5 py-0.5 text-[11px] font-medium text-[var(--color-destructive)]">
        <ClipboardList aria-hidden="true" className="size-3" />
        Denied
      </span>
    );
  }
  if (outcome === "destructive") {
    return (
      <span className="inline-flex items-center rounded bg-[var(--color-warning,oklch(72%_0.15_70))]/15 px-1.5 py-0.5 text-[11px] font-medium text-[var(--color-warning,oklch(40%_0.1_70))]">
        Write
      </span>
    );
  }
  return null;
}

// ---------------------------------------------------------------------------
// Empty + skeleton states
// ---------------------------------------------------------------------------

function EmptyAudit({
  action,
  siteId,
  sites,
}: {
  action: string;
  siteId: string;
  sites: SiteMin[];
}) {
  const hasFilter = !!action || !!siteId;
  const siteName = siteId
    ? (sites.find((s) => s.id === siteId)?.name ?? siteId.slice(0, 8))
    : null;

  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-6 py-14 text-center">
      <Inbox
        aria-hidden="true"
        className="size-6 text-[var(--color-muted-foreground)]"
      />
      {hasFilter ? (
        <>
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            No matching events
          </p>
          <p className="max-w-xs text-xs text-[var(--color-muted-foreground)]">
            {action && siteName
              ? `No ${action} events for ${siteName}.`
              : action
                ? `No events with prefix "${action}".`
                : `No events for ${siteName ?? "this site"}.`}
          </p>
        </>
      ) : (
        <>
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            No audit events yet
          </p>
          <p className="max-w-xs text-xs text-[var(--color-muted-foreground)]">
            Operator events are recorded here as they occur across all sites in
            your account.
          </p>
        </>
      )}
    </div>
  );
}

function AuditSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]"
    >
      <span className="sr-only">Loading audit log</span>
      <div className="border-b border-[var(--color-border)] px-4 py-1.5">
        <Skeleton className="h-3 w-12" />
      </div>
      <ul className="divide-y divide-[var(--color-border)]">
        {Array.from({ length: 8 }).map((_, i) => (
          <li key={i} className="flex items-start gap-3 px-4 py-3">
            <Skeleton className="mt-2 size-1.5 rounded-full" />
            <div className="flex min-w-0 flex-1 flex-col gap-2">
              <div className="flex items-center justify-between gap-3">
                <Skeleton className="h-3.5 w-1/3" />
                <Skeleton className="h-3 w-10 shrink-0" />
              </div>
              <Skeleton className="h-3 w-1/2" />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

// Exported so FileBrowser can import it for the "View activity" deep-link.
// This avoids the caller having to hard-code the URL shape.
export { Link };
