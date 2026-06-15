// Fleet Email page — agency-scale cross-site email view.
//
// Design: operator-grade, aligned with the uptime/performance fleet dashboards.
// Uses the shared fleet primitives: ExceptionSummaryTiles + useFilterToggle,
// FleetTable (single sticky table + colgroup), SparklineCell.
//
// Layout:
//   1. PageHeader + SiteScopeSelector (?site= URL param)
//   2. FLEET view (default — no ?site selected):
//      a. ExceptionSummaryTiles (Sent / Failed / Bounced / Complained)
//      b. Per-site Deliverability FleetTable (GET /email/deliverability)
//      c. Trend chart — Sent / Bounced / Complained over window with threshold
//         reference lines
//      d. Email notifications card
//      e. Fleet email log
//      f. Fleet suppression list
//   3. PER-SITE view (?site=<id>): reuses the per-site email tab sections
//      (Deliverability + Log + Suppressions) with a breadcrumb back link.
//
// Reputation thresholds encoded as constants (SES / major provider limits):
//   bounce_rate:    warn >= 2%, danger >= 5%
//   complaint_rate: warn >= 0.05%, danger >= 0.1%

import { useCallback, useId } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import {
  AreaChart,
  Area,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  Legend,
} from "recharts";
import {
  Mail,
  Send,
  AlertOctagon,
  MailX,
  XCircle,
  ChevronLeft,
  TrendingDown,
} from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";

import { PageHeader } from "@/components/shared/page-header";
import { PageError } from "@/components/feedback";
import { FleetTable } from "@/features/fleet/FleetTable";
import {
  ExceptionSummaryTiles,
  useFilterToggle,
} from "@/features/fleet/ExceptionSummaryTiles";
import { SparklineCell } from "@/features/fleet/SparklineCell";
import { FleetEmailLogTable } from "@/features/email/fleet-email-log-table";
import { FleetEmailSuppressionList } from "@/features/email/email-suppression-list";
import { EmailNotifySettingsCard } from "@/features/email/email-notify-settings-card";
import { EmailDeliverability } from "@/features/email/email-deliverability";
import { EmailSuppressionList } from "@/features/email/email-suppression-list";
import { EmailLogTable } from "@/features/email/email-log-table";
import {
  useFleetEmailStats,
  useFleetEmailDeliverability,
} from "@/features/email/use-email";
import { useFleetEmailEvents } from "@/features/email/use-email-events";
import { useSites } from "@/features/sites/use-sites";
import { relativeTime, cn } from "@/lib/utils";
import type { SiteDeliveryItem } from "@wpmgr/api";

// ---------------------------------------------------------------------------
// Route — search params
// ---------------------------------------------------------------------------

const searchSchema = z.object({
  site: z.string().optional(),
  window: z.coerce.number().min(1).max(365).optional().default(30),
});

export const Route = createFileRoute("/_authed/email/")({
  validateSearch: searchSchema,
  component: FleetEmailPage,
});

// ---------------------------------------------------------------------------
// Reputation thresholds
// ---------------------------------------------------------------------------

const BOUNCE_WARN = 2;    // >= 2% warn
const BOUNCE_DANGER = 5;  // >= 5% danger
const COMPLAINT_WARN = 0.05;   // >= 0.05% warn
const COMPLAINT_DANGER = 0.1;  // >= 0.1% danger

function bounceRateClass(rate: number): string {
  if (rate >= BOUNCE_DANGER)
    return "text-[var(--color-destructive-subtle-fg)] bg-[var(--color-destructive-subtle)]";
  if (rate >= BOUNCE_WARN)
    return "text-[var(--color-warning-subtle-fg)] bg-[var(--color-warning-subtle)]";
  return "text-[var(--color-muted-foreground)]";
}

function complaintRateClass(rate: number): string {
  if (rate >= COMPLAINT_DANGER)
    return "text-[var(--color-destructive-subtle-fg)] bg-[var(--color-destructive-subtle)]";
  if (rate >= COMPLAINT_WARN)
    return "text-[var(--color-warning-subtle-fg)] bg-[var(--color-warning-subtle)]";
  return "text-[var(--color-muted-foreground)]";
}

function fmtRate(rate: number): string {
  if (rate < 0.01) return "0%";
  return `${rate.toFixed(2)}%`;
}

function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

// ---------------------------------------------------------------------------
// Site scope selector (same pattern as performance.tsx)
// ---------------------------------------------------------------------------

interface SiteScopeSelectorProps {
  value: string | undefined;
  onChange: (siteId: string | undefined) => void;
}

function SiteScopeSelector({ value, onChange }: SiteScopeSelectorProps) {
  const selectId = useId();
  const { data: sites = [], isPending } = useSites();

  return (
    <div className="flex items-center gap-2">
      <label
        htmlFor={selectId}
        className="shrink-0 text-xs text-[var(--color-muted-foreground)]"
      >
        Site
      </label>
      <select
        id={selectId}
        value={value ?? ""}
        onChange={(e) => {
          const v = e.target.value;
          onChange(v === "" ? undefined : v);
        }}
        disabled={isPending}
        aria-label="Filter by site"
        className={cn(
          "h-8 min-w-[160px] max-w-[240px] appearance-none rounded-md border border-[var(--color-border)]",
          "bg-[var(--color-background)] px-2.5 py-1 text-xs text-[var(--color-foreground)]",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-1",
          "disabled:cursor-not-allowed disabled:opacity-50",
        )}
      >
        <option value="">All sites</option>
        {sites.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name || s.url}
          </option>
        ))}
      </select>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Deliverability trend chart (Sent / Bounced / Complained over window)
// ---------------------------------------------------------------------------

interface TrendPoint {
  day: string;
  sent_count: number;
  bounced_count: number;
  complained_count: number;
}

function DeliverabilityTrendChart({
  data,
  windowDays,
}: {
  data: TrendPoint[];
  windowDays: number;
}) {
  if (data.length < 2) {
    return (
      <p className="py-8 text-center text-xs text-[var(--color-muted-foreground)]">
        Not enough data for a {windowDays}-day trend.
      </p>
    );
  }

  const chartData = data.map((p) => ({
    date: p.day,
    Sent: p.sent_count,
    Bounced: p.bounced_count,
    Complained: p.complained_count,
  }));

  return (
    <ResponsiveContainer width="100%" height={180}>
      <AreaChart
        data={chartData}
        margin={{ top: 4, right: 4, bottom: 0, left: 0 }}
      >
        <defs>
          <linearGradient id="grad-sent" x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor="var(--color-primary)" stopOpacity={0.2} />
            <stop offset="95%" stopColor="var(--color-primary)" stopOpacity={0} />
          </linearGradient>
          <linearGradient id="grad-bounced" x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor="var(--color-warning)" stopOpacity={0.25} />
            <stop offset="95%" stopColor="var(--color-warning)" stopOpacity={0} />
          </linearGradient>
          <linearGradient id="grad-complained" x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor="var(--color-destructive)" stopOpacity={0.25} />
            <stop offset="95%" stopColor="var(--color-destructive)" stopOpacity={0} />
          </linearGradient>
        </defs>
        <CartesianGrid
          strokeDasharray="3 3"
          stroke="var(--color-border)"
          vertical={false}
        />
        <XAxis
          dataKey="date"
          tickFormatter={shortDate}
          tick={{ fontSize: 10, fill: "var(--color-muted-foreground)" }}
          tickLine={false}
          axisLine={false}
          interval="preserveStartEnd"
        />
        <YAxis
          allowDecimals={false}
          tick={{ fontSize: 10, fill: "var(--color-muted-foreground)" }}
          tickLine={false}
          axisLine={false}
          width={36}
        />
        <Tooltip
          contentStyle={{
            background: "var(--color-card)",
            border: "1px solid var(--color-border)",
            borderRadius: 6,
            fontSize: 12,
            color: "var(--color-foreground)",
          }}
          labelFormatter={(label: unknown) =>
            typeof label === "string" ? shortDate(label) : String(label)
          }
        />
        <Legend
          wrapperStyle={{ fontSize: 11, color: "var(--color-muted-foreground)" }}
        />
        <Area
          type="monotone"
          dataKey="Sent"
          stroke="var(--color-primary)"
          strokeWidth={1.5}
          fill="url(#grad-sent)"
          dot={false}
          activeDot={{ r: 3 }}
          isAnimationActive={false}
        />
        <Area
          type="monotone"
          dataKey="Bounced"
          stroke="var(--color-warning)"
          strokeWidth={1.5}
          fill="url(#grad-bounced)"
          dot={false}
          activeDot={{ r: 3 }}
          isAnimationActive={false}
        />
        <Area
          type="monotone"
          dataKey="Complained"
          stroke="var(--color-destructive)"
          strokeWidth={1.5}
          fill="url(#grad-complained)"
          dot={false}
          activeDot={{ r: 3 }}
          isAnimationActive={false}
        />
        {/* Bounce rate reference lines are on count axis — omit here, they
            live in the rate column cells in the FleetTable instead */}
        <ReferenceLine
          y={0}
          stroke="var(--color-border)"
          strokeWidth={1}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}

// ---------------------------------------------------------------------------
// Per-site deliverability FleetTable columns
// ---------------------------------------------------------------------------

function buildDeliverabilityColumns(
  onDrillSite: (row: SiteDeliveryItem) => void,
): ColumnDef<SiteDeliveryItem>[] {
  return [
    {
      id: "site_name",
      header: "Site",
      accessorFn: (row) => row.site_name || row.site_url,
      meta: { width: "22%" },
      cell: ({ row }) => (
        <button
          type="button"
          onClick={() => onDrillSite(row.original)}
          className="text-left font-medium text-[var(--color-foreground)] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-1"
        >
          <span className="block max-w-[180px] truncate">
            {row.original.site_name || row.original.site_url}
          </span>
        </button>
      ),
    },
    {
      id: "provider",
      header: "Provider",
      accessorFn: (row) => row.provider,
      meta: { width: "12%" },
      cell: ({ row }) =>
        row.original.provider ? (
          <span className="inline-block max-w-[96px] truncate rounded border border-[var(--color-border)] bg-[var(--color-muted)] px-1.5 py-0.5 text-xs text-[var(--color-muted-foreground)]">
            {row.original.provider}
          </span>
        ) : (
          <span className="text-xs italic text-[var(--color-muted-foreground)]">
            none
          </span>
        ),
    },
    {
      id: "sent_count",
      header: "Sent",
      accessorFn: (row) => row.sent_count,
      meta: { numeric: true, width: "8%" },
      cell: ({ row }) => (
        <span className="tabular-nums">{row.original.sent_count.toLocaleString()}</span>
      ),
    },
    {
      id: "failed_count",
      header: "Failed",
      accessorFn: (row) => row.failed_count,
      meta: { numeric: true, width: "8%" },
      cell: ({ row }) => (
        <span
          className={cn(
            "tabular-nums",
            row.original.failed_count > 0
              ? "text-[var(--color-destructive-subtle-fg)]"
              : "text-[var(--color-muted-foreground)]",
          )}
        >
          {row.original.failed_count.toLocaleString()}
        </span>
      ),
    },
    {
      id: "bounce_rate",
      header: "Bounce %",
      accessorFn: (row) => row.bounce_rate,
      meta: { numeric: true, width: "10%" },
      cell: ({ row }) => {
        const rate = row.original.bounce_rate;
        const cls = bounceRateClass(rate);
        const needsBadge = rate >= BOUNCE_WARN;
        return (
          <span
            className={cn(
              "tabular-nums text-xs",
              needsBadge
                ? cn("rounded px-1.5 py-0.5 font-medium", cls)
                : "text-[var(--color-muted-foreground)]",
            )}
            title={`${row.original.bounced_count.toLocaleString()} bounced / ${row.original.total.toLocaleString()} total`}
          >
            {fmtRate(rate)}
          </span>
        );
      },
    },
    {
      id: "complaint_rate",
      header: "Complaint %",
      accessorFn: (row) => row.complaint_rate,
      meta: { numeric: true, width: "10%" },
      cell: ({ row }) => {
        const rate = row.original.complaint_rate;
        const cls = complaintRateClass(rate);
        const needsBadge = rate >= COMPLAINT_WARN;
        return (
          <span
            className={cn(
              "tabular-nums text-xs",
              needsBadge
                ? cn("rounded px-1.5 py-0.5 font-medium", cls)
                : "text-[var(--color-muted-foreground)]",
            )}
            title={`${row.original.complained_count.toLocaleString()} complaints / ${row.original.total.toLocaleString()} total`}
          >
            {fmtRate(rate)}
          </span>
        );
      },
    },
    {
      id: "last_sent_at",
      header: "Last send",
      accessorFn: (row) => row.last_sent_at ?? "",
      meta: { width: "12%" },
      cell: ({ row }) =>
        row.original.last_sent_at ? (
          <span className="text-xs text-[var(--color-muted-foreground)]">
            {relativeTime(row.original.last_sent_at)}
          </span>
        ) : (
          <span className="text-xs italic text-[var(--color-muted-foreground)]">
            never
          </span>
        ),
    },
    {
      id: "sparkline",
      header: "Volume",
      enableSorting: false,
      meta: { width: "10%" },
      cell: ({ row }) => (
        <SparklineCell
          data={row.original.sparkline}
          tone="primary"
          width={56}
          height={20}
          ariaLabel={`Daily sent volume for ${row.original.site_name || row.original.site_url}`}
        />
      ),
    },
  ];
}

// ---------------------------------------------------------------------------
// Fleet view
// ---------------------------------------------------------------------------

interface FleetEmailViewProps {
  windowDays: number;
  onDrillSite: (siteId: string) => void;
}

function FleetEmailView({ windowDays, onDrillSite }: FleetEmailViewProps) {
  const stats = useFleetEmailStats();
  const deliverability = useFleetEmailDeliverability(windowDays);
  const { activeKeys, toggle } = useFilterToggle();

  const s = stats.data;
  const tiles = s
    ? [
        {
          key: "sent",
          label: "Sent",
          count: s.sent_count,
          icon: <Send className="size-5" />,
          tone: "success" as const,
        },
        {
          key: "failed",
          label: "Failed",
          count: s.failed_count,
          icon: <XCircle className="size-5" />,
          tone: "destructive" as const,
        },
        {
          key: "bounced",
          label: "Bounced",
          count: s.bounced_count,
          icon: <MailX className="size-5" />,
          // Warning tone: bounced is a deliverability risk, not a hard failure
          tone: "warning" as const,
        },
        {
          key: "complained",
          label: "Complained",
          count: s.complained_count,
          icon: <AlertOctagon className="size-5" />,
          // Destructive tone: spam complaint is reputation-critical
          tone: "destructive" as const,
        },
      ]
    : [];

  // Derive fleet-wide bounce/complaint rates from stats
  const fleetBounceRate =
    s && s.total > 0 ? (s.bounced_count / s.total) * 100 : 0;
  const fleetComplaintRate =
    s && s.total > 0 ? (s.complained_count / s.total) * 100 : 0;

  const deliverabilityColumns = buildDeliverabilityColumns((row) =>
    onDrillSite(row.site_id),
  );

  const filteredItems =
    deliverability.data?.items.filter((item) => {
      if (activeKeys.size === 0) return true;
      if (activeKeys.has("sent") && item.sent_count > 0) return true;
      if (activeKeys.has("failed") && item.failed_count > 0) return true;
      if (activeKeys.has("bounced") && item.bounced_count > 0) return true;
      if (activeKeys.has("complained") && item.complained_count > 0) return true;
      return false;
    }) ?? [];

  return (
    <>
      {/* Exception summary tiles */}
      <section aria-labelledby="email-tiles-heading">
        <h2
          id="email-tiles-heading"
          className="sr-only"
        >
          Email status summary
        </h2>
        <ExceptionSummaryTiles
          tiles={tiles}
          activeKeys={activeKeys}
          onToggle={toggle}
          loading={stats.isPending}
        />

        {/* Fleet-wide reputation rates — surface prominently when risky */}
        {!stats.isPending && s && s.total > 0 ? (
          <div className="mt-3 flex flex-wrap items-center gap-4">
            <div className="flex items-center gap-1.5 text-xs">
              <MailX
                aria-hidden="true"
                className="size-3.5 text-[var(--color-muted-foreground)]"
              />
              <span className="text-[var(--color-muted-foreground)]">
                Fleet bounce rate:
              </span>
              <span
                className={cn(
                  "font-semibold tabular-nums",
                  fleetBounceRate >= BOUNCE_DANGER
                    ? "text-[var(--color-destructive-subtle-fg)]"
                    : fleetBounceRate >= BOUNCE_WARN
                      ? "text-[var(--color-warning-subtle-fg)]"
                      : "text-[var(--color-foreground)]",
                )}
              >
                {fmtRate(fleetBounceRate)}
              </span>
              {fleetBounceRate >= BOUNCE_WARN ? (
                <span
                  className={cn(
                    "rounded px-1 py-0.5 text-xs font-medium",
                    fleetBounceRate >= BOUNCE_DANGER
                      ? "bg-[var(--color-destructive-subtle)] text-[var(--color-destructive-subtle-fg)]"
                      : "bg-[var(--color-warning-subtle)] text-[var(--color-warning-subtle-fg)]",
                  )}
                >
                  {fleetBounceRate >= BOUNCE_DANGER ? "Danger" : "Warning"}
                </span>
              ) : null}
            </div>
            <div className="flex items-center gap-1.5 text-xs">
              <AlertOctagon
                aria-hidden="true"
                className="size-3.5 text-[var(--color-muted-foreground)]"
              />
              <span className="text-[var(--color-muted-foreground)]">
                Fleet complaint rate:
              </span>
              <span
                className={cn(
                  "font-semibold tabular-nums",
                  fleetComplaintRate >= COMPLAINT_DANGER
                    ? "text-[var(--color-destructive-subtle-fg)]"
                    : fleetComplaintRate >= COMPLAINT_WARN
                      ? "text-[var(--color-warning-subtle-fg)]"
                      : "text-[var(--color-foreground)]",
                )}
              >
                {fmtRate(fleetComplaintRate)}
              </span>
              {fleetComplaintRate >= COMPLAINT_WARN ? (
                <span
                  className={cn(
                    "rounded px-1 py-0.5 text-xs font-medium",
                    fleetComplaintRate >= COMPLAINT_DANGER
                      ? "bg-[var(--color-destructive-subtle)] text-[var(--color-destructive-subtle-fg)]"
                      : "bg-[var(--color-warning-subtle)] text-[var(--color-warning-subtle-fg)]",
                  )}
                >
                  {fleetComplaintRate >= COMPLAINT_DANGER ? "Danger" : "Warning"}
                </span>
              ) : null}
            </div>
          </div>
        ) : null}

        {stats.isError ? (
          <div className="mt-3">
            <PageError
              what="Could not load fleet email stats."
              why={stats.error?.message}
              onRetry={() => void stats.refetch()}
            />
          </div>
        ) : null}
      </section>

      {/* Per-site deliverability table */}
      <section aria-labelledby="deliverability-table-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <TrendingDown
            aria-hidden="true"
            className="size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
          <h2
            id="deliverability-table-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Per-site deliverability
          </h2>
          <span className="text-xs text-[var(--color-muted-foreground)]">
            {windowDays}-day window, sorted by bounce rate
          </span>
        </div>

        {deliverability.isError ? (
          <PageError
            what="Could not load deliverability data."
            why={deliverability.error?.message}
            onRetry={() => void deliverability.refetch()}
          />
        ) : (
          <FleetTable<SiteDeliveryItem>
            data={filteredItems}
            columns={deliverabilityColumns}
            loading={deliverability.isPending}
            height={Math.min(520, Math.max(200, filteredItems.length * 48 + 44))}
            ariaLabel="Per-site deliverability"
            defaultSorting={[{ id: "bounce_rate", desc: true }]}
            onRowClick={(row) => onDrillSite(row.site_id)}
            emptyState={
              <div className="flex flex-col items-center gap-2 py-2">
                <Mail
                  aria-hidden="true"
                  strokeWidth={1.5}
                  className="size-7 text-[var(--color-muted-foreground)]/40"
                />
                <p className="text-sm text-[var(--color-muted-foreground)]">
                  No sites have sent email in the last {windowDays} days.
                </p>
              </div>
            }
          />
        )}
      </section>

      {/* Deliverability trend chart */}
      {!stats.isPending &&
        !stats.isError &&
        s &&
        s.by_day.length >= 2 ? (
          <section aria-labelledby="trend-chart-heading">
            <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
              <h2
                id="trend-chart-heading"
                className="text-sm font-semibold text-[var(--color-foreground)]"
              >
                Delivery trend
              </h2>
              <span className="text-xs text-[var(--color-muted-foreground)]">
                Sent vs bounced vs complained — {windowDays}-day window
              </span>
            </div>
            <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-4 py-4">
              <DeliverabilityTrendChart data={s.by_day} windowDays={windowDays} />
            </div>
          </section>
        ) : null}

      {/* Email notifications */}
      <section aria-labelledby="notifications-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="notifications-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Notifications
          </h2>
        </div>
        <EmailNotifySettingsCard />
      </section>

      {/* Fleet email log */}
      <section aria-labelledby="fleet-log-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="fleet-log-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Email log
          </h2>
        </div>
        <FleetEmailLogTable />
      </section>

      {/* Fleet suppression list */}
      <section aria-labelledby="fleet-suppression-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="fleet-suppression-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Suppression list
          </h2>
        </div>
        <p className="mb-4 text-sm text-[var(--color-muted-foreground)]">
          Addresses suppressed fleet-wide apply to all sites in your tenant.
          Per-site suppressions are managed from each site's Email tab.
        </p>
        <FleetEmailSuppressionList />
      </section>
    </>
  );
}

// ---------------------------------------------------------------------------
// Per-site view (drill-in from fleet table or scope selector)
// ---------------------------------------------------------------------------

interface PerSiteEmailViewProps {
  siteId: string;
  siteName: string;
  onBack: () => void;
}

function PerSiteEmailView({ siteId, siteName, onBack }: PerSiteEmailViewProps) {
  return (
    <section aria-labelledby="per-site-email-heading" className="space-y-6">
      {/* Breadcrumb back */}
      <div className="flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={onBack}
          className={cn(
            "inline-flex items-center gap-1.5 rounded text-xs text-[var(--color-muted-foreground)]",
            "transition-colors duration-150 hover:text-[var(--color-foreground)]",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-1",
          )}
          aria-label="Back to all sites"
        >
          <ChevronLeft aria-hidden="true" className="size-3.5" />
          All sites
        </button>
        <span aria-hidden="true" className="text-xs text-[var(--color-border)]">
          /
        </span>
        <span
          id="per-site-email-heading"
          className="text-xs font-medium text-[var(--color-foreground)]"
        >
          {siteName || siteId}
        </span>
      </div>

      {/* Deliverability stats (per-site) */}
      <section aria-labelledby="per-site-deliverability-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="per-site-deliverability-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Deliverability
          </h2>
        </div>
        <EmailDeliverability siteId={siteId} />
      </section>

      {/* Per-site log */}
      <section aria-labelledby="per-site-log-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="per-site-log-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Email log
          </h2>
        </div>
        <EmailLogTable siteId={siteId} />
      </section>

      {/* Per-site suppressions */}
      <section aria-labelledby="per-site-suppression-heading">
        <div className="mb-3 flex items-center gap-2 border-b border-[var(--color-border)] pb-3">
          <h2
            id="per-site-suppression-heading"
            className="text-sm font-semibold text-[var(--color-foreground)]"
          >
            Suppression list
          </h2>
        </div>
        <EmailSuppressionList siteId={siteId} />
      </section>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

function FleetEmailPage() {
  const { site: selectedSiteId, window: windowDays } = Route.useSearch();
  const navigate = useNavigate({ from: Route.fullPath });

  const setSite = useCallback(
    (siteId: string | undefined) => {
      void navigate({ search: (prev) => ({ ...prev, site: siteId }) });
    },
    [navigate],
  );

  // Resolve site name for breadcrumb / subline
  const { data: sites = [] } = useSites();
  const selectedSite = selectedSiteId
    ? sites.find((s) => s.id === selectedSiteId)
    : undefined;
  const siteName = selectedSite?.name ?? selectedSite?.url ?? selectedSiteId ?? "";

  const isPerSite = Boolean(selectedSiteId);

  // Live SSE updates — fleet scope covers all sites
  useFleetEmailEvents();

  return (
    <section aria-labelledby="fleet-email-heading" className="space-y-8">
      <PageHeader
        title="Email"
        subline={
          isPerSite
            ? `Email delivery for ${siteName || (selectedSiteId ?? "")}`
            : "Fleet-wide email delivery across all connected sites"
        }
        badges={
          <span aria-hidden="true">
            <Mail className="size-4 text-[var(--color-muted-foreground)]" />
          </span>
        }
        actions={
          <SiteScopeSelector value={selectedSiteId} onChange={setSite} />
        }
      />

      {isPerSite && selectedSiteId ? (
        <PerSiteEmailView
          siteId={selectedSiteId}
          siteName={siteName}
          onBack={() => setSite(undefined)}
        />
      ) : (
        <FleetEmailView
          windowDays={windowDays}
          onDrillSite={(siteId) => setSite(siteId)}
        />
      )}
    </section>
  );
}
