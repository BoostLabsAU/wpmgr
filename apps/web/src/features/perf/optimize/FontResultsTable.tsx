import {
  CheckCircle2,
  Clock,
  Loader2,
  Scissors,
  XCircle,
} from "lucide-react";

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";

import { formatBytes } from "../format";
import { useFontResults } from "../hooks/useFontResults";
import { useFontsBannerHydration } from "../hooks/useFontsBannerHydration";
import { useFontsStore, selectFonts, type FontRowPhase } from "../fonts-store";
import { FontsLiveIndicator } from "./FontsLiveIndicator";
import { useSiteReconnect } from "@/features/sites/use-site-events";
import type { FontResult } from "../types";

// Font results table: one row per discovered self-hosted font, showing the
// conversion chain (source ext → WOFF2), sizes, subset savings, and per-font
// state badge. The state badge is the main QA surface: it makes every
// pending / converting / ready / subset / skipped / failed outcome visible so a
// bad subset is caught in review, not by an end user.
//
// Observe-only: fonts are discovered passively on page-build (unlike RUCSS
// Compute-now). No trigger button is rendered.

export interface FontResultsTableProps {
  siteId: string;
  hostname: string;
  /** operator+ permission: currently unused (observe-only), wired for future actions. */
  canOperate: boolean;
}

// ---------------------------------------------------------------------------
// Per-row state badge
// ---------------------------------------------------------------------------

/** Resolved display state — union of DB state + SSE ephemeral overlay. */
type RowDisplayState =
  | "pending"
  | "converting"
  | "ready"
  | "subset"
  | "skipped"
  | "failed";

function resolveRowState(
  dbState: FontResult["state"],
  rowPhase: FontRowPhase | undefined,
): RowDisplayState {
  // SSE-ephemeral overlay takes priority while processing is in flight.
  if (rowPhase === "converting") return "converting";
  if (rowPhase === "skipped") return "skipped";
  if (rowPhase === "failed") return "failed";
  // Fall back to DB-persisted state.
  if (dbState === "ready") return "ready";
  if (dbState === "subset") return "subset";
  if (dbState === "negative") return "failed";
  return "pending";
}

interface StateBadgeProps {
  state: RowDisplayState;
  errorDetail?: string;
}

function StateBadge({ state, errorDetail }: StateBadgeProps) {
  if (state === "pending") {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Clock aria-hidden="true" className="size-3.5 shrink-0" />
        <span>Pending</span>
      </span>
    );
  }
  if (state === "converting") {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
        <Loader2 aria-hidden="true" className="size-3.5 shrink-0 animate-spin" />
        <span>Converting</span>
      </span>
    );
  }
  if (state === "ready") {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
        <CheckCircle2 aria-hidden="true" className="size-3.5 shrink-0" />
        <span>Ready</span>
      </span>
    );
  }
  if (state === "subset") {
    return (
      <span
        className="inline-flex items-center gap-1 text-xs"
        style={{ color: "var(--color-primary)" }}
      >
        <Scissors aria-hidden="true" className="size-3.5 shrink-0" />
        <span>Subset</span>
      </span>
    );
  }
  if (state === "skipped") {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
        <span aria-hidden="true" className="text-base leading-none">!</span>
        <span>Skipped (icon/variable font)</span>
      </span>
    );
  }
  // failed
  return (
    <span
      className="inline-flex items-center gap-1 text-xs text-red-700 dark:text-red-400"
      title={errorDetail ?? undefined}
    >
      <XCircle aria-hidden="true" className="size-3.5 shrink-0" />
      <span>Failed</span>
    </span>
  );
}

// ---------------------------------------------------------------------------
// Source-ext badge
// ---------------------------------------------------------------------------

function ExtBadge({ ext }: { ext: string | undefined }) {
  if (!ext) return <span className="text-muted-foreground">–</span>;
  return (
    <Badge variant="secondary" className="font-mono text-xs uppercase">
      {ext}
    </Badge>
  );
}

// ---------------------------------------------------------------------------
// Main table component
// ---------------------------------------------------------------------------

export function FontResultsTable({
  siteId,
  hostname: _hostname,
  canOperate: _canOperate,
}: FontResultsTableProps) {
  // Observe-only: no pagination state needed for small font counts, but we wire
  // page=0 explicitly to match the hook signature.
  const results = useFontResults(siteId, 0);
  const fontStates = useFontsStore((s) => selectFonts(s, siteId).fontStates);

  // On mount and on SSE reconnect, reconcile the live banner against the query
  // cache: if the banner is active but no fonts are still pending in the DB,
  // clear the banner and invalidate the query so the table reflects server truth.
  // This closes the gap where a missed font.completed leaves a converting banner
  // showing indefinitely (the 120 s stale backstop in FontsLiveIndicator is the
  // primary guard; this is the secondary reconciliation on reconnect).
  const reconcileBanner = useFontsBannerHydration(siteId);
  useSiteReconnect(reconcileBanner);

  const items = results.data ?? [];

  return (
    <section className="space-y-3 rounded-xl border border-border bg-card text-card-foreground shadow-sm">
      <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">
            Font conversion results
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            One row per self-hosted font discovered during page build. Subset
            rows also carry a subsetting savings figure.
          </p>
        </div>
        <div className="shrink-0">
          <FontsLiveIndicator siteId={siteId} />
        </div>
      </div>

      {results.isPending ? (
        <div
          role="status"
          aria-busy="true"
          aria-label="Loading font results"
          className="space-y-2 p-5"
        >
          <span className="sr-only">Loading font results</span>
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      ) : results.isError ? (
        <p role="alert" className="px-5 py-8 text-center text-sm text-muted-foreground">
          Could not load font results. {results.error.message}
        </p>
      ) : items.length === 0 ? (
        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
          No fonts discovered yet. They appear here once a page with
          self-hosted fonts is built with WOFF2 conversion on.
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Font</TableHead>
              <TableHead>Format</TableHead>
              <TableHead className="text-right">Original</TableHead>
              <TableHead className="text-right">WOFF2</TableHead>
              <TableHead className="text-right">Subset</TableHead>
              <TableHead className="text-right">Savings</TableHead>
              <TableHead>State</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.map((r, idx) => {
              const rowPhase = r.source_hash
                ? fontStates[r.source_hash]
                : undefined;
              const displayState = resolveRowState(r.state, rowPhase);
              const displayName = r.family ?? r.source_file ?? r.source_hash ?? "—";

              return (
                <TableRow key={r.id ?? r.source_hash ?? idx}>
                  {/* Font name */}
                  <TableCell className="max-w-[200px]">
                    <span
                      className="block truncate font-mono text-xs text-foreground"
                      title={r.source_file ?? r.source_hash ?? displayName}
                    >
                      {displayName}
                    </span>
                  </TableCell>

                  {/* Format: source_ext badge → woff2 */}
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                      <ExtBadge ext={r.original_ext} />
                      <span aria-hidden="true">→</span>
                      <Badge variant="secondary" className="font-mono text-xs uppercase">
                        woff2
                      </Badge>
                    </span>
                  </TableCell>

                  {/* Original size */}
                  <TableCell className="text-right tabular-nums text-muted-foreground">
                    {formatBytes(r.original_size)}
                  </TableCell>

                  {/* WOFF2 size */}
                  <TableCell className="text-right tabular-nums text-foreground">
                    {r.woff2_size ? formatBytes(r.woff2_size) : "–"}
                  </TableCell>

                  {/* Subset size */}
                  <TableCell className="text-right tabular-nums text-foreground">
                    {r.subset_size ? formatBytes(r.subset_size) : "–"}
                  </TableCell>

                  {/* Savings % */}
                  <TableCell className="text-right tabular-nums font-medium text-foreground">
                    {Number.isFinite(r.savings_pct) && (r.savings_pct ?? 0) > 0
                      ? `${(r.savings_pct ?? 0).toFixed(0)}%`
                      : "–"}
                  </TableCell>

                  {/* State badge */}
                  <TableCell>
                    <StateBadge
                      state={displayState}
                      errorDetail={r.error_detail}
                    />
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      <div className="h-1" aria-hidden="true" />
    </section>
  );
}
