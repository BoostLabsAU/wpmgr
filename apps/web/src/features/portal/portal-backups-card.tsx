// PortalBackupsCard — read-only backup inventory for the portal site detail page.
// No download/restore affordances; status, size, and timestamps only.

import { DatabaseBackup } from "lucide-react";

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
import type { PortalBackupItem } from "./use-portal";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatBytes(bytes: number | undefined): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function statusVariant(
  status: string,
): "default" | "secondary" | "destructive" | "outline" {
  switch (status) {
    case "completed":
      return "default";
    case "running":
      return "secondary";
    case "failed":
      return "destructive";
    default:
      return "outline";
  }
}

function kindLabel(kind: string): string {
  switch (kind) {
    case "full":
      return "Full";
    case "incremental":
      return "Incremental";
    case "database":
      return "Database";
    case "files":
      return "Files";
    default:
      return kind;
  }
}

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalBackupsCardSkeleton() {
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

export interface PortalBackupsCardProps {
  items: PortalBackupItem[] | null | undefined;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  onRetry: () => void;
  isRetrying: boolean;
}

export function PortalBackupsCard({
  items,
  isLoading,
  isError,
  error,
  onRetry,
  isRetrying,
}: PortalBackupsCardProps) {
  if (isLoading) return <PortalBackupsCardSkeleton />;

  if (isError) {
    return (
      <Card>
        <CardContent className="pt-6">
          <PageError
            what="Could not load backups."
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
          <DatabaseBackup aria-hidden="true" className="size-4 text-[var(--color-muted-foreground)]" />
          Recent Backups
          <span className="ml-auto font-mono text-xs font-normal tabular-nums text-[var(--color-muted-foreground)]">
            {list.length} shown
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        {list.length === 0 ? (
          <p className="text-sm text-[var(--color-muted-foreground)]">
            No completed backups yet.
          </p>
        ) : (
          <div className="divide-y divide-[var(--color-border)]">
            {list.map((b) => (
              <div
                key={b.id}
                className="flex items-center justify-between gap-3 py-2.5"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <Badge variant={statusVariant(b.status)} className="text-xs">
                      {b.status}
                    </Badge>
                    <span className="text-sm text-[var(--color-foreground)]">
                      {kindLabel(b.kind)}
                    </span>
                  </div>
                  <p className="mt-0.5 text-xs text-[var(--color-muted-foreground)]">
                    {relativeTime(b.created_at)}
                  </p>
                </div>
                {b.size_bytes ? (
                  <span className="shrink-0 font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
                    {formatBytes(b.size_bytes)}
                  </span>
                ) : null}
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
