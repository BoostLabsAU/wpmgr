import { useState } from "react";
import { ChevronLeft, ChevronRight, Loader2, Play, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { DestructiveConfirm } from "@/components/dialogs/destructive-confirm";
import { toast } from "@/components/toast";

import { formatBytes, formatWhen } from "../format";
import { useComputeRucss } from "../hooks/useComputeRucss";
import { useClearRucss } from "../hooks/useCacheStats";
import { RUCSS_PAGE_SIZE, useRucssResults } from "../hooks/useRucssResults";
import { RucssLiveIndicator } from "./RucssLiveIndicator";

// RUCSS results table: one row per page structure, showing original → used CSS,
// the reduction %, and when it was last used. Paginated (offset windows of 25).
// "Clear results" wipes the cached Used-CSS for the site (destructive confirm).

export interface RucssResultsTableProps {
  siteId: string;
  hostname: string;
  /** operator+ — can clear cached results. */
  canOperate: boolean;
}

export function RucssResultsTable({
  siteId,
  hostname,
  canOperate,
}: RucssResultsTableProps) {
  const [page, setPage] = useState(0);
  const [clearOpen, setClearOpen] = useState(false);
  const results = useRucssResults(siteId, page);
  const clear = useClearRucss(siteId);
  const compute = useComputeRucss(siteId);

  const items = results.data ?? [];
  const hasNext = items.length === RUCSS_PAGE_SIZE;

  function confirmClear() {
    clear.mutate(undefined, {
      onSuccess: (res) => {
        setClearOpen(false);
        setPage(0);
        toast.success(
          `Cleared ${res.cleared.toLocaleString()} cached result${res.cleared === 1 ? "" : "s"}.`,
        );
      },
    });
  }

  return (
    <section className="space-y-3 rounded-xl border border-border bg-card text-card-foreground shadow-sm">
      <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">
            Used-CSS results
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            One cached result per page structure, with how much CSS was removed.
          </p>
        </div>
        {canOperate ? (
          <div className="flex shrink-0 items-center gap-2">
            <RucssLiveIndicator siteId={siteId} />
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => compute.mutate()}
              disabled={compute.isPending}
              title="Compute Used-CSS for the home page now"
            >
              {compute.isPending ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <Play aria-hidden="true" className="size-4" />
              )}
              Compute now
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setClearOpen(true)}
              disabled={clear.isPending || items.length === 0}
            >
              {clear.isPending ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <Trash2 aria-hidden="true" className="size-4" />
              )}
              Clear results
            </Button>
          </div>
        ) : null}
      </div>

      {results.isPending ? (
        <div
          role="status"
          aria-busy="true"
          aria-label="Loading Used-CSS results"
          className="space-y-2 p-5"
        >
          <span className="sr-only">Loading Used-CSS results</span>
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      ) : results.isError ? (
        <p role="alert" className="px-5 py-8 text-center text-sm text-muted-foreground">
          Could not load Used-CSS results. {results.error.message}
        </p>
      ) : items.length === 0 ? (
        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
          No Used-CSS results yet. They appear here once Remove Unused CSS runs
          on a page.
        </p>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Page</TableHead>
                <TableHead className="text-right">Original → Used</TableHead>
                <TableHead className="text-right">Reduction</TableHead>
                <TableHead className="text-right">Last used</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="max-w-[280px]">
                    <span
                      className="block truncate font-mono text-xs text-foreground"
                      title={r.url ?? r.structure_hash}
                    >
                      {r.url ?? r.structure_hash}
                    </span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums text-muted-foreground">
                    {formatBytes(r.original_css_bytes)} →{" "}
                    <span className="text-foreground">
                      {formatBytes(r.used_css_bytes)}
                    </span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums font-medium text-foreground">
                    {Number.isFinite(r.reduction_pct)
                      ? `${r.reduction_pct.toFixed(0)}%`
                      : "–"}
                  </TableCell>
                  <TableCell className="text-right text-muted-foreground">
                    {formatWhen(r.last_used_at)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          <div className="flex items-center justify-between gap-3 px-5 py-3">
            <span className="text-xs text-muted-foreground">
              Page {page + 1}
            </span>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => Math.max(0, p - 1))}
                disabled={page === 0 || results.isFetching}
              >
                <ChevronLeft aria-hidden="true" className="size-4" />
                Previous
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setPage((p) => p + 1)}
                disabled={!hasNext || results.isFetching}
              >
                Next
                <ChevronRight aria-hidden="true" className="size-4" />
              </Button>
            </div>
          </div>
        </>
      )}

      <DestructiveConfirm
        open={clearOpen}
        onClose={() => setClearOpen(false)}
        onConfirm={confirmClear}
        title={`Clear Used-CSS for ${hostname}`}
        resourceName={hostname}
        confirmLabel="Clear results"
        cancelLabel="Keep results"
        isPending={clear.isPending}
        errorMessage={clear.isError ? clear.error.message : null}
        consequencesBody={
          <p>
            All cached Used-CSS results are removed. The next visit to each page
            serves full CSS and re-triggers the computation, so the first load
            after clearing is slightly heavier until results rebuild.
          </p>
        }
      />
    </section>
  );
}
