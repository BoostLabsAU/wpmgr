// Portal overview page — /portal
//
// Shows: client header (name, logo_url, site count, report count from overview)
// + sites grid from GET /portal/sites.

import { createFileRoute, Link } from "@tanstack/react-router";
import { Globe } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import {
  usePortalOverview,
  usePortalSites,
} from "@/features/portal/use-portal";
import { PortalSiteCard } from "@/features/portal/portal-site-card";

export const Route = createFileRoute("/portal/")({
  component: PortalIndexPage,
});

// ---------------------------------------------------------------------------
// Skeletons
// ---------------------------------------------------------------------------

function HeaderSkeleton() {
  return (
    <div className="mb-6 space-y-2">
      <Skeleton className="h-7 w-48" />
      <Skeleton className="h-4 w-64" />
    </div>
  );
}

function SitesGridSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {[0, 1, 2, 3].map((i) => (
        <Skeleton key={i} className="h-40 w-full rounded-lg" />
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function PortalIndexPage() {
  const {
    data: overview,
    isPending: overviewPending,
    isError: overviewError,
    error: overviewErr,
    refetch: overviewRefetch,
    isFetching: overviewFetching,
  } = usePortalOverview();

  const {
    data: sites,
    isPending: sitesPending,
    isError: sitesError,
    error: sitesErr,
    refetch: sitesRefetch,
    isFetching: sitesFetching,
  } = usePortalSites();

  // Header loading / error
  const headerContent = () => {
    if (overviewPending) return <HeaderSkeleton />;
    if (overviewError) {
      return (
        <PageError
          what="Could not load overview."
          why={overviewErr?.message}
          onRetry={() => void overviewRefetch()}
          isRetrying={overviewFetching}
          className="mb-6"
        />
      );
    }
    if (!overview) return null;

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
  };

  // Sites grid loading / error / empty / data
  const sitesContent = () => {
    if (sitesPending) return <SitesGridSkeleton />;
    if (sitesError) {
      return (
        <PageError
          what="Could not load sites."
          why={sitesErr?.message}
          onRetry={() => void sitesRefetch()}
          isRetrying={sitesFetching}
        />
      );
    }

    if (!sites || sites.length === 0) {
      return (
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
      );
    }

    return (
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {sites.map((site) => (
          <PortalSiteCard key={site.id} site={site} />
        ))}
      </div>
    );
  };

  return (
    <div>
      {headerContent()}
      {sitesContent()}
    </div>
  );
}
