// Portal site detail page — /portal/sites/$siteId
//
// Four read-only cards: uptime, vitals, backups, updates.
// Before fetching detail, validates the siteId is in the portal sites list;
// if not, shows a not-found state (the router notFound() helper is not available
// inside file routes without a loader, so we render an inline error instead).

import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, ExternalLink } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { PageError } from "@/components/feedback";
import {
  usePortalSites,
  usePortalSiteUptime,
  usePortalSiteBackups,
  usePortalSiteUpdates,
  usePortalSiteVitals,
} from "@/features/portal/use-portal";
import { PortalUptimeCard } from "@/features/portal/portal-uptime-card";
import { PortalVitalsCard } from "@/features/portal/portal-vitals-card";
import { PortalBackupsCard } from "@/features/portal/portal-backups-card";
import { PortalUpdatesCard } from "@/features/portal/portal-updates-card";

export const Route = createFileRoute("/portal/sites/$siteId")({
  component: PortalSiteDetailPage,
});

// ---------------------------------------------------------------------------
// Soft status label (locked decision, contract 9.3)
// ---------------------------------------------------------------------------

function siteStatusLabel(status: string): string {
  return status === "connected" ? "Monitoring active" : "Needs attention";
}

function siteStatusVariant(
  status: string,
): "default" | "secondary" | "destructive" | "outline" {
  return status === "connected" ? "default" : "destructive";
}

// ---------------------------------------------------------------------------
// Header skeleton
// ---------------------------------------------------------------------------

function SiteDetailHeaderSkeleton() {
  return (
    <div className="mb-6 space-y-2">
      <Skeleton className="h-4 w-20" />
      <Skeleton className="h-6 w-56" />
      <Skeleton className="h-4 w-40" />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function PortalSiteDetailPage() {
  const { siteId } = Route.useParams();

  // We need the sites list to validate siteId and get the site name/url/status.
  const {
    data: sites,
    isPending: sitesPending,
    isError: sitesError,
    error: sitesErr,
    refetch: sitesRefetch,
    isFetching: sitesFetching,
  } = usePortalSites();

  const uptimeQuery = usePortalSiteUptime(siteId);
  const backupsQuery = usePortalSiteBackups(siteId);
  const updatesQuery = usePortalSiteUpdates(siteId);
  const vitalsQuery = usePortalSiteVitals(siteId);

  // Header state
  if (sitesPending) {
    return (
      <div>
        <SiteDetailHeaderSkeleton />
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-48 w-full rounded-lg" />
          ))}
        </div>
      </div>
    );
  }

  if (sitesError) {
    return (
      <PageError
        what="Could not load site."
        why={sitesErr?.message}
        onRetry={() => void sitesRefetch()}
        isRetrying={sitesFetching}
      />
    );
  }

  const site = sites?.find((s) => s.id === siteId);

  if (!site) {
    return (
      <div className="py-12 text-center">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          Site not found
        </p>
        <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
          This site is not in your portal, or the link is outdated.
        </p>
        <Link
          to="/portal"
          className="mt-4 inline-flex items-center gap-1.5 text-sm text-[var(--color-primary)] underline underline-offset-4"
        >
          <ArrowLeft aria-hidden="true" className="size-3.5" />
          Back to sites
        </Link>
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/portal"
          className="inline-flex items-center gap-1.5 text-xs text-[var(--color-muted-foreground)] transition-colors hover:text-[var(--color-foreground)]"
        >
          <ArrowLeft aria-hidden="true" className="size-3.5" />
          All sites
        </Link>
        <div className="mt-2 flex flex-wrap items-start gap-3">
          <div className="flex-1">
            <h1 className="text-xl font-semibold tracking-tight text-[var(--color-foreground)]">
              {site.name}
            </h1>
            <a
              href={site.url}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-0.5 flex items-center gap-1 text-sm text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]"
            >
              <ExternalLink aria-hidden="true" className="size-3.5 shrink-0" />
              {site.url.replace(/^https?:\/\//, "")}
            </a>
          </div>
          <Badge variant={siteStatusVariant(site.status)}>
            {siteStatusLabel(site.status)}
          </Badge>
        </div>
      </div>

      {/* Four data cards */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <PortalUptimeCard
          data={uptimeQuery.data}
          isLoading={uptimeQuery.isPending}
          isError={uptimeQuery.isError}
          error={uptimeQuery.error}
          onRetry={() => void uptimeQuery.refetch()}
          isRetrying={uptimeQuery.isFetching}
        />
        <PortalVitalsCard
          data={vitalsQuery.data}
          isLoading={vitalsQuery.isPending}
          isError={vitalsQuery.isError}
          error={vitalsQuery.error}
          onRetry={() => void vitalsQuery.refetch()}
          isRetrying={vitalsQuery.isFetching}
        />
        <PortalBackupsCard
          items={backupsQuery.data}
          isLoading={backupsQuery.isPending}
          isError={backupsQuery.isError}
          error={backupsQuery.error}
          onRetry={() => void backupsQuery.refetch()}
          isRetrying={backupsQuery.isFetching}
        />
        <PortalUpdatesCard
          items={updatesQuery.data}
          isLoading={updatesQuery.isPending}
          isError={updatesQuery.isError}
          error={updatesQuery.error}
          onRetry={() => void updatesQuery.refetch()}
          isRetrying={updatesQuery.isFetching}
        />
      </div>
    </div>
  );
}
