import { createFileRoute } from "@tanstack/react-router";
import { Mail } from "lucide-react";

import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { FleetEmailDeliverability } from "@/features/email/email-deliverability";
import { FleetEmailLogTable } from "@/features/email/fleet-email-log-table";
import { FleetEmailSuppressionList } from "@/features/email/email-suppression-list";
import { useFleetEmailStats } from "@/features/email/use-email";
import { useFleetEmailEvents } from "@/features/email/use-email-events";

// Fleet Email page — agency-scale cross-site email view.
//
// Sections:
//   1. Deliverability stats (fleet-wide) — sent/failed totals + daily chart +
//      per-provider breakdown, powered by GET /email/stats.
//   2. Fleet log — cross-site paginated log table with a "Site" column that
//      links to the per-site Email tab for drill-in.
//   3. Suppressions (Phase 4a) — fleet-wide suppressed address list with add/
//      remove actions.
//
// Phase 4b: useFleetEmailEvents wires up live SSE updates across ALL sites so
// the fleet log, stats, and suppression list refresh automatically.

export const Route = createFileRoute("/_authed/email/")({
  component: FleetEmailPage,
});

function FleetEmailPage() {
  const stats = useFleetEmailStats();

  // Live updates via the shared SSE bus — fleet scope covers all sites
  useFleetEmailEvents();

  return (
    <section
      aria-labelledby="fleet-email-heading"
      className="space-y-8"
    >
      <PageHeader
        title="Email"
        subline="Fleet-wide email delivery across all connected sites"
        badges={
          <span aria-hidden="true">
            <Mail className="size-4 text-[var(--color-muted-foreground)]" />
          </span>
        }
      />

      {/* Deliverability stats */}
      <section aria-labelledby="fleet-stats-heading">
        <h2
          id="fleet-stats-heading"
          className="mb-4 text-sm font-semibold text-[var(--color-foreground)]"
        >
          Deliverability
        </h2>
        {stats.isPending ? (
          <StatsSkeletons />
        ) : stats.isError ? (
          <PageError
            what="Could not load fleet email stats."
            why={stats.error?.message}
            onRetry={() => void stats.refetch()}
          />
        ) : stats.data ? (
          <FleetEmailDeliverability stats={stats.data} />
        ) : null}
      </section>

      {/* Fleet email log */}
      <section aria-labelledby="fleet-log-heading">
        <h2
          id="fleet-log-heading"
          className="mb-4 text-sm font-semibold text-[var(--color-foreground)]"
        >
          Email log
        </h2>
        <FleetEmailLogTable />
      </section>

      {/* Fleet suppression list (Phase 4a) */}
      <section aria-labelledby="fleet-suppression-heading">
        <h2
          id="fleet-suppression-heading"
          className="mb-4 text-sm font-semibold text-[var(--color-foreground)]"
        >
          Suppression list
        </h2>
        <p className="mb-4 text-sm text-[var(--color-muted-foreground)]">
          Addresses suppressed fleet-wide apply to all sites in your tenant.
          Per-site suppressions are managed from each site's Email tab.
        </p>
        <FleetEmailSuppressionList />
      </section>
    </section>
  );
}

function StatsSkeletons() {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-24 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-56 rounded-xl" />
    </div>
  );
}
