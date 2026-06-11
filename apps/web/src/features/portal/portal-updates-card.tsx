// PortalUpdatesCard — read-only applied-updates log for the portal site detail page.

import { RefreshCw } from "lucide-react";

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { relativeTime } from "@/lib/utils";
import type { PortalUpdateItem } from "./use-portal";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function typeLabel(type: string): string {
  switch (type) {
    case "plugin":
      return "Plugin";
    case "theme":
      return "Theme";
    case "core":
      return "WordPress";
    default:
      return type;
  }
}

function statusVariant(
  status: string,
): "default" | "secondary" | "destructive" | "outline" {
  switch (status) {
    case "succeeded":
    case "success":
    case "done":
      return "default";
    case "running":
      return "secondary";
    case "failed":
      return "destructive";
    default:
      return "outline";
  }
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalUpdatesCardSkeleton() {
  return (
    <Card>
      <CardHeader>
        <Skeleton className="h-5 w-36" />
      </CardHeader>
      <CardContent className="space-y-2">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalUpdatesCardProps {
  items: PortalUpdateItem[] | null | undefined;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  onRetry: () => void;
  isRetrying: boolean;
}

export function PortalUpdatesCard({
  items,
  isLoading,
  isError,
  error,
  onRetry,
  isRetrying,
}: PortalUpdatesCardProps) {
  if (isLoading) return <PortalUpdatesCardSkeleton />;

  if (isError) {
    return (
      <Card>
        <CardContent className="pt-6">
          <PageError
            what="Could not load updates."
            why={error?.message}
            onRetry={onRetry}
            isRetrying={isRetrying}
          />
        </CardContent>
      </Card>
    );
  }

  const list = items ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <RefreshCw aria-hidden="true" className="size-4 text-[var(--color-muted-foreground)]" />
          Applied Updates
          <span className="ml-auto font-mono text-xs font-normal tabular-nums text-[var(--color-muted-foreground)]">
            {list.length} shown
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        {list.length === 0 ? (
          <p className="text-sm text-[var(--color-muted-foreground)]">
            No updates recorded yet.
          </p>
        ) : (
          <div className="divide-y divide-[var(--color-border)]">
            {list.map((u, i) => (
              <div
                key={i}
                className="flex items-center justify-between gap-3 py-2.5"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <Badge variant="outline" className="text-xs">
                      {typeLabel(u.type)}
                    </Badge>
                    <span className="truncate text-sm text-[var(--color-foreground)]">
                      {u.name}
                    </span>
                  </div>
                  {u.from_version && u.to_version ? (
                    <p className="mt-0.5 font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
                      {u.from_version} &rarr; {u.to_version}
                    </p>
                  ) : null}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-0.5">
                  <Badge variant={statusVariant(u.status)} className="text-xs">
                    {u.status}
                  </Badge>
                  {u.finished_at ? (
                    <span className="text-xs text-[var(--color-muted-foreground)]">
                      {relativeTime(u.finished_at)}
                    </span>
                  ) : null}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
