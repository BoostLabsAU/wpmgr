// PortalUptimeCard — read-only uptime summary for the portal site detail page.

import { Clock, TrendingUp } from "lucide-react";

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { cn } from "@/lib/utils";
import type { PortalUptimeSummary, PortalIncident } from "./use-portal";

// ---------------------------------------------------------------------------
// Uptime ring helpers
// ---------------------------------------------------------------------------

function uptimeClass(pct: number): string {
  if (pct >= 99.9) return "text-[var(--success,#16a34a)]";
  if (pct >= 99) return "text-[var(--color-foreground)]";
  if (pct >= 95) return "text-[var(--color-warning,#d97706)]";
  return "text-[var(--color-destructive)]";
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
  return `${(seconds / 3600).toFixed(1)}h`;
}

// ---------------------------------------------------------------------------
// Incident row
// ---------------------------------------------------------------------------

function IncidentRow({ incident }: { incident: PortalIncident }) {
  const started = new Date(incident.started_at);
  return (
    <div className="flex items-center justify-between gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-card)] px-3 py-2">
      <div className="min-w-0">
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          {started.toLocaleDateString(undefined, {
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
          })}
        </p>
        {incident.ended_at ? (
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Resolved {new Date(incident.ended_at).toLocaleTimeString()}
          </p>
        ) : (
          <p className="text-xs text-[var(--color-destructive)]">Ongoing</p>
        )}
      </div>
      <span className="shrink-0 font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
        {formatDuration(incident.duration_seconds)}
      </span>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalUptimeCardSkeleton() {
  return (
    <Card>
      <CardHeader>
        <Skeleton className="h-5 w-32" />
      </CardHeader>
      <CardContent className="space-y-4">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-16 w-full" />
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalUptimeCardProps {
  data: PortalUptimeSummary | null | undefined;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  onRetry: () => void;
  isRetrying: boolean;
}

export function PortalUptimeCard({
  data,
  isLoading,
  isError,
  error,
  onRetry,
  isRetrying,
}: PortalUptimeCardProps) {
  if (isLoading) return <PortalUptimeCardSkeleton />;

  if (isError) {
    return (
      <Card>
        <CardContent className="pt-6">
          <PageError
            what="Could not load uptime data."
            why={error?.message}
            onRetry={onRetry}
            isRetrying={isRetrying}
          />
        </CardContent>
      </Card>
    );
  }

  if (!data) return null;

  const incidents = data.incidents ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <TrendingUp aria-hidden="true" className="size-4 text-[var(--color-muted-foreground)]" />
          Uptime (30 days)
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Key metrics */}
        <div className="flex flex-wrap gap-6">
          <div>
            <p
              className={cn(
                "font-mono text-2xl font-bold tabular-nums",
                uptimeClass(data.uptime_pct),
              )}
            >
              {data.uptime_pct.toFixed(2)}%
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Uptime
            </p>
          </div>
          <div>
            <p className="font-mono text-2xl font-bold tabular-nums text-[var(--color-foreground)]">
              {Math.round(data.avg_latency_ms)}
              <span className="text-sm font-normal text-[var(--color-muted-foreground)]">
                ms
              </span>
            </p>
            <p className="text-xs text-[var(--color-muted-foreground)]">
              Avg latency
            </p>
          </div>
          {data.tls_expires_at ? (
            <div>
              <p className="font-mono text-sm tabular-nums text-[var(--color-foreground)]">
                {new Date(data.tls_expires_at).toLocaleDateString(undefined, {
                  month: "short",
                  day: "numeric",
                  year: "numeric",
                })}
              </p>
              <p className="text-xs text-[var(--color-muted-foreground)]">
                TLS expires
              </p>
            </div>
          ) : null}
        </div>

        {/* Incidents */}
        <div>
          <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-[var(--color-muted-foreground)]">
            <Clock aria-hidden="true" className="size-3" />
            {incidents.length === 0
              ? "No incidents in this period"
              : `${incidents.length} incident${incidents.length === 1 ? "" : "s"}`}
          </p>
          {incidents.length > 0 ? (
            <div className="space-y-1.5">
              {incidents.slice(0, 5).map((inc, i) => (
                <IncidentRow key={i} incident={inc} />
              ))}
            </div>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
