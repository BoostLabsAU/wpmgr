import { useMemo } from "react";
import { createFileRoute } from "@tanstack/react-router";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Link } from "@tanstack/react-router";

import { useSites } from "@/features/sites/use-sites";

// `/clients/$clientId/sites` — lists the sites assigned to this client.
//
// Reuses the existing useSites() hook, then filters client-side by clientId.
// This avoids duplicating the list infrastructure and keeps a consistent cache.
// The CP already returns client_id on every Site DTO from the m63 migration.

export const Route = createFileRoute("/_authed/clients/$clientId/sites")({
  component: ClientSitesTab,
});

function ClientSitesTab() {
  const { clientId } = Route.useParams();
  const { data: allSites, isPending, isError, error, refetch, isFetching } =
    useSites();

  const clientSites = useMemo(
    () => (allSites ?? []).filter((s) => s.client_id === clientId),
    [allSites, clientId],
  );

  if (isPending) {
    return <ClientSitesSkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load sites."
        why={error instanceof Error ? error.message : "An unexpected error occurred."}
        onRetry={() => void refetch()}
        retryLabel="Reload"
        isRetrying={isFetching}
      />
    );
  }

  if (clientSites.length === 0) {
    return (
      <div
        role="status"
        aria-label="No sites assigned"
        className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-[var(--color-border)] py-10 text-center"
      >
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          No sites assigned to this client yet.
        </p>
        <p className="text-sm text-[var(--color-muted-foreground)]">
          Use the Sites page to assign sites in bulk, or set the client from the
          site table.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-[var(--color-border)]">
      <Table>
        <caption className="sr-only">Sites assigned to this client</caption>
        <TableHeader>
          <TableRow>
            <TableHead>Site</TableHead>
            <TableHead>URL</TableHead>
            <TableHead>WP</TableHead>
            <TableHead>Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {clientSites.map((site) => {
            let hostname = site.url;
            try {
              hostname = new URL(site.url).hostname;
            } catch {
              // keep raw value
            }
            return (
              <TableRow key={site.id}>
                <TableCell className="font-medium">
                  <Link
                    to="/sites/$siteId"
                    params={{ siteId: site.id }}
                    className="text-[var(--color-foreground)] underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  >
                    {site.name || hostname}
                  </Link>
                </TableCell>
                <TableCell className="font-mono text-xs text-[var(--color-muted-foreground)]">
                  {hostname}
                </TableCell>
                <TableCell className="font-mono text-sm">
                  {site.wp_version ?? (
                    <span aria-hidden="true" className="text-[var(--color-muted-foreground)]/50">
                      —
                    </span>
                  )}
                </TableCell>
                <TableCell>
                  <Badge
                    variant={site.connection_state === "connected" ? "secondary" : "muted"}
                    className="capitalize"
                  >
                    {site.connection_state ?? "unknown"}
                  </Badge>
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

function ClientSitesSkeleton() {
  return (
    <div className="space-y-2" role="status" aria-busy="true" aria-label="Loading sites">
      {Array.from({ length: 3 }).map((_, i) => (
        <Skeleton key={i} className="h-12 w-full" />
      ))}
    </div>
  );
}
