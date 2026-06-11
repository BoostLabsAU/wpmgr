// Portal overview page — /portal (v2 dashboard rewrite)
//
// Two requests: GET /portal/overview (client header) + GET /portal/summary
// (all KPI/chart/card/timeline data). The sites grid is fed from
// summary.sites — this page no longer calls /portal/sites.
//
// Layout order:
//   Mobile:  hero -> report callout -> sites -> charts -> timeline
//   Desktop: hero -> charts -> callout -> sites -> timeline
//
// Zero useMutation here (logout lives in PortalShell).

import { useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { Globe } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { cn } from "@/lib/utils";
import {
  usePortalOverview,
  usePortalSummary,
  type PortalSummaryRange,
} from "@/features/portal/use-portal";
import { PortalStatusBanner } from "@/features/portal/portal-status-banner";
import { PortalHero, PortalHeroSkeleton } from "@/features/portal/portal-hero";
import {
  PortalMonthGlance,
  PortalMonthGlanceSkeleton,
} from "@/features/portal/portal-month-glance";
import { PortalReportCallout } from "@/features/portal/portal-report-callout";
import { PortalSiteCardV2 } from "@/features/portal/portal-site-card-v2";
import { PortalRecentWork } from "@/features/portal/portal-recent-work";

export const Route = createFileRoute("/portal/")({
  component: PortalIndexPage,
});

// ---------------------------------------------------------------------------
// Loading skeleton — mirrors the final layout so CLS is minimal
// ---------------------------------------------------------------------------

function PageSkeleton() {
  return (
    <div>
      {/* Header skeleton */}
      <div className="mb-6 space-y-2">
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-64" />
      </div>
      {/* Banner */}
      <Skeleton className="mb-6 h-12 w-full rounded-lg" />
      {/* Hero KPIs */}
      <PortalHeroSkeleton />
      {/* Charts (desktop layout only — mobile shows callout first) */}
      <PortalMonthGlanceSkeleton />
      {/* Report callout */}
      <Skeleton className="mb-6 h-16 w-full rounded-lg" />
      {/* Sites grid */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-48 w-full rounded-lg" />
        ))}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

// RangeSwitcher — segmented 7d/30d/90d control driving the summary query.
const RANGE_OPTIONS: { value: PortalSummaryRange; label: string }[] = [
  { value: "7d", label: "7 days" },
  { value: "30d", label: "30 days" },
  { value: "90d", label: "90 days" },
];

function RangeSwitcher({
  range,
  onChange,
}: {
  range: PortalSummaryRange;
  onChange: (r: PortalSummaryRange) => void;
}) {
  return (
    <div
      role="group"
      aria-label="Summary period"
      className="inline-flex items-center gap-0.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-0.5"
    >
      {RANGE_OPTIONS.map((opt) => (
        <button
          key={opt.value}
          type="button"
          aria-pressed={range === opt.value}
          onClick={() => onChange(opt.value)}
          className={cn(
            "rounded-md px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
            range === opt.value
              ? "bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
              : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]",
          )}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}

function PortalIndexPage() {
  const [range, setRange] = useState<PortalSummaryRange>("30d");

  const {
    data: overview,
    isPending: overviewPending,
    isError: overviewError,
    error: overviewErr,
    refetch: overviewRefetch,
    isFetching: overviewFetching,
  } = usePortalOverview();

  const {
    data: summary,
    isPending: summaryPending,
    isError: summaryError,
    error: summaryErr,
    refetch: summaryRefetch,
    isFetching: summaryFetching,
  } = usePortalSummary(range);

  const isPending = overviewPending || summaryPending;

  // Full loading state — show the page skeleton
  if (isPending) return <PageSkeleton />;

  // Critical failure — overview is load-bearing for the page header
  if (overviewError) {
    return (
      <PageError
        what="Could not load portal."
        why={overviewErr?.message}
        onRetry={() => void overviewRefetch()}
        isRetrying={overviewFetching}
      />
    );
  }

  if (!overview) return null;

  const agencyName = overview.client?.name ?? "";
  const sites = summary?.sites ?? [];
  const hasSites = sites.length > 0;

  // Zero-sites empty state — keep the Globe empty state verbatim and suppress
  // banner/KPIs/timeline (per contract §2.7).
  if (!summaryPending && !summaryError && hasSites === false) {
    return (
      <div>
        {/* Header */}
        <PortalPageHeader overview={overview} />
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-8 text-center">
          <Globe
            aria-hidden="true"
            className="mx-auto mb-3 size-8 text-[var(--color-muted-foreground)]"
          />
          <p className="text-sm font-medium text-[var(--color-foreground)]">
            No sites yet
          </p>
          <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
            Sites assigned to your account will appear here.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div>
      {/* Page header */}
      <PortalPageHeader overview={overview} />

      {/* Status banner + period switcher row */}
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          {summary && hasSites ? (
            <PortalStatusBanner
              sites={summary.sites}
              generatedAt={summary.generated_at}
            />
          ) : null}
        </div>
        <RangeSwitcher range={range} onChange={setRange} />
      </div>

      {/* Summary section: error inline if summary fails but overview was OK */}
      {summaryError ? (
        <div className="mb-6 rounded-lg border border-[var(--color-destructive)]/30 bg-[var(--color-destructive)]/5 p-4">
          <p className="text-sm font-medium text-[var(--color-destructive)]">
            Could not load dashboard data.
          </p>
          <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
            {summaryErr?.message ?? "An unexpected error occurred."}
          </p>
          <button
            type="button"
            onClick={() => void summaryRefetch()}
            disabled={summaryFetching}
            className="mt-2 text-xs text-[var(--color-primary)] underline underline-offset-2 hover:opacity-80 disabled:opacity-50"
          >
            {summaryFetching ? "Retrying..." : "Retry"}
          </button>
        </div>
      ) : null}

      {summary ? (
        <>
          {/* Hero KPIs */}
          <PortalHero
            agencyName={agencyName}
            periodLabel={summary.period_label}
            totals={summary.totals}
            vitalsOverall={summary.vitals_overall}
          />

          {/* Mobile: report callout before charts; Desktop: charts first (order swap via order CSS) */}
          {/* Report callout — mobile position (shows above charts on small screens) */}
          <div className="block md:hidden">
            <PortalReportCallout latestReport={summary.latest_report} />
          </div>

          {/* Charts: fleet uptime + vitals band */}
          <PortalMonthGlance
            uptimeDaily={summary.uptime_daily}
            vitalsDistribution={summary.vitals_distribution}
            totalSites={summary.totals.site_count}
          />

          {/* Report callout — desktop position (shows after charts on larger screens) */}
          <div className="hidden md:block">
            <PortalReportCallout latestReport={summary.latest_report} />
          </div>

          {/* Sites grid */}
          {hasSites ? (
            <section className="mb-6">
              <h2 className="mb-3 text-sm font-semibold text-[var(--color-muted-foreground)] uppercase tracking-wider">
                Your sites
              </h2>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {summary.sites.map((site) => (
                  <PortalSiteCardV2 key={site.id} site={site} />
                ))}
              </div>
            </section>
          ) : null}

          {/* Recent work timeline */}
          <section>
            <h2 className="mb-3 text-sm font-semibold text-[var(--color-muted-foreground)] uppercase tracking-wider">
              Recent work
            </h2>
            <PortalRecentWork
              items={summary.recent_work}
              totals={summary.totals}
              agencyName={agencyName}
            />
          </section>
        </>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page header sub-component (client name + report count link)
// ---------------------------------------------------------------------------

function PortalPageHeader({
  overview,
}: {
  overview: NonNullable<ReturnType<typeof usePortalOverview>["data"]>;
}) {
  return (
    <div className="mb-6">
      <h1 className="text-xl font-semibold tracking-tight text-[var(--color-foreground)]">
        {overview.client.name}
      </h1>
      <p className="mt-1 text-sm text-[var(--color-muted-foreground)]">
        <span className="font-mono tabular-nums">{overview.site_count}</span>{" "}
        {overview.site_count === 1 ? "site" : "sites"}
        {overview.report_count > 0 ? (
          <>
            {" "}
            &middot;{" "}
            <Link
              to="/portal/reports"
              className="underline underline-offset-2 hover:text-[var(--color-foreground)]"
            >
              <span className="font-mono tabular-nums">
                {overview.report_count}
              </span>{" "}
              {overview.report_count === 1 ? "report" : "reports"}
            </Link>
          </>
        ) : null}
      </p>
    </div>
  );
}
