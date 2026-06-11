// PortalVitalsCard — Core Web Vitals p75 display for the portal site detail page.
// Shows LCP, INP, CLS as tiles with rating-coloured badges.

import { Activity } from "lucide-react";

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import type { PortalVitalsSummary, PortalVitalMetric } from "./use-portal";

// ---------------------------------------------------------------------------
// Rating display helpers
// ---------------------------------------------------------------------------

const METRIC_LABELS: Record<string, string> = {
  lcp: "Largest Contentful Paint",
  inp: "Interaction to Next Paint",
  cls: "Cumulative Layout Shift",
};

const METRIC_UNITS: Record<string, string> = {
  lcp: "ms",
  inp: "ms",
  cls: "",
};

function formatMetricValue(metric: string, p75: number): string {
  if (metric === "cls") return p75.toFixed(3);
  return `${Math.round(p75)}${METRIC_UNITS[metric] ?? ""}`;
}

function ratingVariant(
  rating: PortalVitalMetric["rating"],
): "default" | "secondary" | "destructive" | "outline" {
  switch (rating) {
    case "good":
      return "default";
    case "needs-improvement":
      return "secondary";
    case "poor":
      return "destructive";
    default:
      return "outline";
  }
}

function ratingLabel(rating: PortalVitalMetric["rating"]): string {
  switch (rating) {
    case "good":
      return "Good";
    case "needs-improvement":
      return "Needs improvement";
    case "poor":
      return "Poor";
    default:
      return "Insufficient data";
  }
}

// ---------------------------------------------------------------------------
// Metric tile
// ---------------------------------------------------------------------------

function VitalTile({ metric }: { metric: PortalVitalMetric }) {
  return (
    <div className="flex flex-col gap-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-3">
      <p className="text-xs text-[var(--color-muted-foreground)]">
        {METRIC_LABELS[metric.metric] ?? metric.metric.toUpperCase()}
      </p>
      <p className="font-mono text-xl font-bold tabular-nums text-[var(--color-foreground)]">
        {metric.rating === "insufficient-data"
          ? "--"
          : formatMetricValue(metric.metric, metric.p75)}
      </p>
      <div className="flex items-center justify-between gap-2">
        <Badge variant={ratingVariant(metric.rating)} className="text-xs">
          {ratingLabel(metric.rating)}
        </Badge>
        {metric.samples > 0 ? (
          <span className="font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
            {metric.samples.toLocaleString()} samples
          </span>
        ) : null}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalVitalsCardSkeleton() {
  return (
    <Card>
      <CardHeader>
        <Skeleton className="h-5 w-40" />
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-24 w-full" />
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalVitalsCardProps {
  data: PortalVitalsSummary | null | undefined;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  onRetry: () => void;
  isRetrying: boolean;
}

export function PortalVitalsCard({
  data,
  isLoading,
  isError,
  error,
  onRetry,
  isRetrying,
}: PortalVitalsCardProps) {
  if (isLoading) return <PortalVitalsCardSkeleton />;

  if (isError) {
    return (
      <Card>
        <CardContent className="pt-6">
          <PageError
            what="Could not load Core Web Vitals."
            why={error?.message}
            onRetry={onRetry}
            isRetrying={isRetrying}
          />
        </CardContent>
      </Card>
    );
  }

  if (!data || data.metrics.length === 0) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Activity aria-hidden="true" className="size-4 text-[var(--color-muted-foreground)]" />
            Core Web Vitals
          </CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-[var(--color-muted-foreground)]">
            No field data available yet. Vitals are measured from real visitor sessions.
          </p>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Activity aria-hidden="true" className="size-4 text-[var(--color-muted-foreground)]" />
          Core Web Vitals
          <span className="ml-auto font-mono text-xs font-normal tabular-nums text-[var(--color-muted-foreground)]">
            p75 · {data.range}
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {data.metrics.map((m) => (
            <VitalTile key={m.metric} metric={m} />
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
