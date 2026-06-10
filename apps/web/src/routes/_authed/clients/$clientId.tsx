import { createFileRoute, Link, Outlet, useLocation } from "@tanstack/react-router";
import { Users } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { cn } from "@/lib/utils";

import { useClient } from "@/features/clients/use-clients";

export const Route = createFileRoute("/_authed/clients/$clientId")({
  component: ClientDetailLayout,
});

// ---------------------------------------------------------------------------
// Tabs config
// ---------------------------------------------------------------------------

interface TabDef {
  label: string;
  path: string;
  disabled?: boolean;
  disabledLabel?: string;
}

const TABS: TabDef[] = [
  { label: "Sites", path: "sites" },
  { label: "Reports", path: "reports", disabled: true, disabledLabel: "Reports arrive with the next phase" },
];

function ClientDetailLayout() {
  const { clientId } = Route.useParams();
  const location = useLocation();
  const { data: client, isPending, isError, error, refetch, isFetching } = useClient(clientId);

  if (isPending) {
    return <ClientDetailSkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load client."
        why={error.message}
        onRetry={() => void refetch()}
        retryLabel="Reload"
        isRetrying={isFetching}
      />
    );
  }

  const pathname = location.pathname;
  // Determine the active tab from the pathname suffix.
  const activeTab =
    TABS.find((t) => pathname.endsWith(`/${t.path}`))?.path ?? "sites";

  return (
    <section aria-labelledby="client-detail-heading" className="space-y-0">
      {/* Header ---------------------------------------------------------------- */}
      <div className="mb-4 flex items-start gap-3">
        {client.color ? (
          <span
            aria-hidden="true"
            className="mt-1 inline-block size-4 shrink-0 rounded-full border border-[var(--color-border)]"
            style={{ backgroundColor: client.color }}
          />
        ) : (
          <Users
            aria-hidden="true"
            className="mt-1 size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
        )}
        <div className="min-w-0 flex-1">
          <h1
            id="client-detail-heading"
            className="text-xl font-semibold tracking-tight text-[var(--color-foreground)]"
          >
            {client.name}
          </h1>
          {(client.company || client.contact_email) ? (
            <p className="mt-0.5 truncate text-sm text-[var(--color-muted-foreground)]">
              {[client.company, client.contact_email].filter(Boolean).join(" · ")}
            </p>
          ) : null}
        </div>
        <span className="shrink-0 font-mono text-sm tabular-nums text-[var(--color-muted-foreground)]">
          {client.site_count} {client.site_count === 1 ? "site" : "sites"}
        </span>
      </div>

      {/* Tab nav -------------------------------------------------------------- */}
      <nav
        aria-label="Client detail tabs"
        className="flex border-b border-[var(--color-border)]"
      >
        {TABS.map((tab) => {
          const isActive = tab.path === activeTab;
          if (tab.disabled) {
            return (
              <span
                key={tab.path}
                title={tab.disabledLabel}
                aria-disabled="true"
                className="relative px-4 py-2.5 text-sm font-medium text-[var(--color-muted-foreground)]/50 cursor-not-allowed select-none"
              >
                {tab.label}
              </span>
            );
          }
          return (
            <Link
              key={tab.path}
              to="/clients/$clientId/sites"
              params={{ clientId }}
              className={cn(
                "relative px-4 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                "after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:rounded-t-sm",
                isActive
                  ? "text-[var(--color-foreground)] after:bg-[var(--color-primary)]"
                  : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] after:bg-transparent",
              )}
              aria-current={isActive ? "page" : undefined}
            >
              {tab.label}
            </Link>
          );
        })}
      </nav>

      {/* Outlet --------------------------------------------------------------- */}
      <div className="pt-6">
        <Outlet />
      </div>
    </section>
  );
}

function ClientDetailSkeleton() {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Skeleton className="size-4 rounded-full" />
        <Skeleton className="h-6 w-48" />
      </div>
      <div className="flex border-b border-[var(--color-border)]">
        <Skeleton className="mx-4 my-2.5 h-4 w-10" />
        <Skeleton className="mx-4 my-2.5 h-4 w-16" />
      </div>
      <div className="pt-4 space-y-3">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </div>
    </div>
  );
}
