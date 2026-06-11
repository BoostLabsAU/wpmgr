// PortalReportsTable — completed reports list with HTML/PDF download links.
// Downloads are opened in a new tab via a click handler that fetches the
// presigned URL from the portal endpoint.

import { useState } from "react";
import { Download, FileText, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { toast } from "@/components/toast";
import { relativeTime } from "@/lib/utils";
import { fetchPortalReportDownload, type PortalReportItem } from "./use-portal";
import { toError } from "@/features/auth/use-auth";

// ---------------------------------------------------------------------------
// Loading skeleton
// ---------------------------------------------------------------------------

export function PortalReportsTableSkeleton() {
  return (
    <div className="space-y-2">
      {[0, 1, 2].map((i) => (
        <Skeleton key={i} className="h-14 w-full" />
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Report row with download buttons
// ---------------------------------------------------------------------------

function ReportRow({ report }: { report: PortalReportItem }) {
  const [downloading, setDownloading] = useState<"html" | "pdf" | null>(null);

  async function handleDownload(format: "html" | "pdf") {
    if (downloading) return;
    setDownloading(format);
    try {
      const result = await fetchPortalReportDownload(report.id, format);
      window.open(result.url, "_blank", "noopener,noreferrer");
    } catch (err) {
      toast.error(toError(err).message || "Could not get download link.");
    } finally {
      setDownloading(null);
    }
  }

  const periodStart = new Date(report.period_start).toLocaleDateString(
    undefined,
    { month: "short", day: "numeric", year: "numeric" },
  );
  const periodEnd = new Date(report.period_end).toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });

  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-4 py-3">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <FileText
            aria-hidden="true"
            className="size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
          <span className="truncate text-sm font-medium text-[var(--color-foreground)]">
            {periodStart} &ndash; {periodEnd}
          </span>
        </div>
        <p className="mt-0.5 text-xs text-[var(--color-muted-foreground)]">
          Generated {relativeTime(report.completed_at ?? report.created_at)}
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={downloading !== null}
          onClick={() => void handleDownload("html")}
          aria-label="Download HTML report"
        >
          {downloading === "html" ? (
            <Loader2 aria-hidden="true" className="mr-1.5 size-3.5 animate-spin" />
          ) : (
            <Download aria-hidden="true" className="mr-1.5 size-3.5" />
          )}
          HTML
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={downloading !== null}
          onClick={() => void handleDownload("pdf")}
          aria-label="Download PDF report"
        >
          {downloading === "pdf" ? (
            <Loader2 aria-hidden="true" className="mr-1.5 size-3.5 animate-spin" />
          ) : (
            <Download aria-hidden="true" className="mr-1.5 size-3.5" />
          )}
          PDF
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export interface PortalReportsTableProps {
  items: PortalReportItem[] | null | undefined;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  onRetry: () => void;
  isRetrying: boolean;
}

export function PortalReportsTable({
  items,
  isLoading,
  isError,
  error,
  onRetry,
  isRetrying,
}: PortalReportsTableProps) {
  if (isLoading) return <PortalReportsTableSkeleton />;

  if (isError) {
    return (
      <PageError
        what="Could not load reports."
        why={error?.message}
        onRetry={onRetry}
        isRetrying={isRetrying}
      />
    );
  }

  const list = items ?? [];

  if (list.length === 0) {
    return (
      <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-8 text-center">
        <FileText
          aria-hidden="true"
          className="mx-auto mb-3 size-8 text-[var(--color-muted-foreground)]"
        />
        <p className="text-sm font-medium text-[var(--color-foreground)]">
          No reports yet
        </p>
        <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
          Reports will appear here once they have been generated.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {list.map((report) => (
        <ReportRow key={report.id} report={report} />
      ))}
    </div>
  );
}
