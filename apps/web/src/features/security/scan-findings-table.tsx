import { useState } from "react";
import { Eye, EyeOff, FileX } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableHead,
  TableHeader,
  TableRow,
  TableCell,
} from "@/components/ui/table";
import { PageError } from "@/components/feedback";
import { SeverityChip } from "@/components/shared/severity-chip";
import { toast } from "@/components/toast/use-toast-helpers";

import {
  useScanFindings,
  useIgnoreFinding,
  type ScanFinding,
  type ScanFindingSeverity,
  type ScanFindingType,
} from "./use-scan";
import { FindingFileModal } from "./finding-file-modal";

// S3 — Integrity scan findings table.
//
// Groups/sorts findings by severity (high → medium → low).
// Columns: Severity / Type / Path / Expected MD5 / Actual MD5 / Actions
//
// Design rules:
//   - font-mono on path, md5 columns.
//   - MD5 truncated to 8 chars (full on hover via title attr).
//   - tabular-nums on counts.
//   - "Ignore"/"Unignore" toggle per row.
//   - "View file" on core_unknown_injected and core_modified (explicit click).
//   - Empty state: honest copy distinguishing "no findings" vs "no run yet".

// ---------------------------------------------------------------------------
// Severity ordering
// ---------------------------------------------------------------------------

const SEVERITY_ORDER: Record<ScanFindingSeverity, number> = {
  high: 0,
  medium: 1,
  low: 2,
};

function sortedFindings(findings: ScanFinding[]): ScanFinding[] {
  return [...findings].sort(
    (a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity],
  );
}

// ---------------------------------------------------------------------------
// Finding type chip
//
// TYPE_LABEL and TYPE_CLASSES are keyed on ScanFindingType (all known values).
// Any finding_type string arriving from the API that is NOT in the union is
// rendered by FindingTypeChip's fallback path — no crash.
//
// String values are confirmed against apps/api/internal/scan/model.go
// FindingFile* / FindingPlugin* constants.
// ---------------------------------------------------------------------------

const TYPE_LABEL: Record<ScanFindingType, string> = {
  // Phase 1 — core checksums
  core_modified: "Core modified",
  core_missing: "Core missing",
  core_unknown_injected: "Unknown file",
  // Phase 2 — full file-integrity
  file_added: "File added",
  file_changed: "File changed",
  file_removed: "File removed",
  plugin_modified: "Plugin file modified",
  plugin_unknown: "Unrecognized plugin file",
};

// Semantic-token classes only — no raw hex.
const TYPE_CLASSES: Record<ScanFindingType, string> = {
  // high severity — destructive tone
  core_modified:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  core_missing:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  core_unknown_injected:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  file_changed:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  plugin_modified:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  plugin_unknown:
    "bg-[var(--color-destructive-subtle,_oklch(0.97_0.04_25))] text-[var(--color-destructive-subtle-fg,_oklch(0.45_0.2_25))]",
  // medium severity — warning tone
  file_added:
    "bg-[var(--color-warning-subtle,_oklch(0.97_0.05_85))] text-[var(--color-warning-subtle-fg,_oklch(0.45_0.15_85))]",
  // low severity — muted tone
  file_removed: "bg-[var(--color-muted)] text-[var(--color-muted-foreground)]",
};

// Fallback chip for any finding_type the client does not yet recognise.
// Renders a neutral badge instead of crashing.
const FALLBACK_CHIP_CLASSES =
  "bg-[var(--color-muted)] text-[var(--color-muted-foreground)]";

function FindingTypeChip({ type }: { type: string }) {
  const label =
    type in TYPE_LABEL
      ? TYPE_LABEL[type as ScanFindingType]
      : type.replace(/_/g, " "); // e.g. "some_future_type" → "some future type"
  const classes =
    type in TYPE_CLASSES
      ? TYPE_CLASSES[type as ScanFindingType]
      : FALLBACK_CHIP_CLASSES;

  return (
    <span
      className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${classes}`}
    >
      {label}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

interface ScanFindingsTableProps {
  siteId: string;
  runId: string;
}

export function ScanFindingsTable({ siteId, runId }: ScanFindingsTableProps) {
  const { data, isPending, isError, error, refetch } = useScanFindings(
    siteId,
    runId,
  );
  const ignore = useIgnoreFinding();
  const [fileModal, setFileModal] = useState<ScanFinding | null>(null);
  const [showIgnored, setShowIgnored] = useState(false);

  if (isPending) {
    return <FindingsTableSkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load scan findings."
        why={error instanceof Error ? error.message : "Unknown error"}
        onRetry={() => void refetch()}
        retryLabel="Reload findings"
      />
    );
  }

  const allFindings = data ?? [];
  const visible = showIgnored
    ? allFindings
    : allFindings.filter((f) => !f.ignored);
  const sorted = sortedFindings(visible);
  const ignoredCount = allFindings.filter((f) => f.ignored).length;

  const toolbar = (
    <div className="flex items-center justify-between gap-3">
      <p className="text-xs text-[var(--color-muted-foreground)]">
        {allFindings.length === 0
          ? "No findings"
          : `${allFindings.length} finding${allFindings.length !== 1 ? "s" : ""}`}
        {ignoredCount > 0 && !showIgnored
          ? ` (${ignoredCount} ignored)`
          : null}
      </p>
      <div className="flex items-center gap-2">
        {ignoredCount > 0 && (
          <Button
            type="button"
            size="sm"
            variant={showIgnored ? "outline" : "ghost"}
            onClick={() => setShowIgnored((v) => !v)}
            aria-pressed={showIgnored}
          >
            {showIgnored ? "Hide ignored" : "Show ignored"}
          </Button>
        )}
        <Button
          type="button"
          size="sm"
          variant="ghost"
          onClick={() => void refetch()}
        >
          Reload
        </Button>
      </div>
    </div>
  );

  if (allFindings.length === 0) {
    return (
      <div className="space-y-3">
        {toolbar}
        <NoFindingsEmpty />
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {toolbar}

      {sorted.length === 0 ? (
        <div className="flex items-center justify-center rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-6 py-8">
          <p className="text-sm text-[var(--color-muted-foreground)]">
            All findings are ignored.
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]">
          <div className="w-full overflow-x-auto">
            <Table className="min-w-[700px]">
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[110px]">Severity</TableHead>
                  <TableHead className="w-[130px]">Type</TableHead>
                  <TableHead>Path</TableHead>
                  <TableHead className="w-[110px]">Expected MD5</TableHead>
                  <TableHead className="w-[110px]">Actual MD5</TableHead>
                  <TableHead className="w-[160px] text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sorted.map((finding) => (
                  <FindingRow
                    key={finding.id}
                    finding={finding}
                    siteId={siteId}
                    runId={runId}
                    isIgnoring={
                      ignore.isPending &&
                      ignore.variables?.findingId === finding.id
                    }
                    onIgnore={() => {
                    ignore.mutate(
                      { findingId: finding.id, siteId, runId },
                      {
                        onSuccess: (updated) => {
                          toast.success(
                            updated.ignored
                              ? "Finding ignored."
                              : "Finding restored.",
                          );
                        },
                        onError: (err: Error) => {
                          toast.error("Could not update finding.", {
                            description: err.message,
                          });
                        },
                      },
                    );
                  }}
                  onViewFile={() => setFileModal(finding)}
                />
              ))}
            </TableBody>
          </Table>
          </div>
        </div>
      )}

      {/* File viewer modal — always rendered so AnimatePresence exits cleanly */}
      <FindingFileModal
        siteId={siteId}
        runId={runId}
        finding={fileModal}
        onClose={() => setFileModal(null)}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// FindingRow
// ---------------------------------------------------------------------------

interface FindingRowProps {
  finding: ScanFinding;
  siteId: string;
  runId: string;
  isIgnoring: boolean;
  onIgnore: () => void;
  onViewFile: () => void;
}

function FindingRow({
  finding,
  isIgnoring,
  onIgnore,
  onViewFile,
}: FindingRowProps) {
  // "View file" is available for finding types where the content is meaningful
  // to an operator. file_removed has no current content to fetch.
  const canViewFile =
    finding.finding_type === "core_unknown_injected" ||
    finding.finding_type === "core_modified" ||
    finding.finding_type === "file_changed" ||
    finding.finding_type === "file_added" ||
    finding.finding_type === "plugin_modified";

  return (
    <TableRow
      className={finding.ignored ? "opacity-50" : undefined}
      aria-label={`Finding: ${finding.path}`}
    >
      <TableCell>
        <SeverityChip severity={finding.severity} />
      </TableCell>
      <TableCell>
        <FindingTypeChip type={finding.finding_type} />
      </TableCell>
      <TableCell>
        <span
          className="block max-w-[260px] truncate font-mono text-xs text-[var(--color-foreground)]"
          title={finding.path}
        >
          {finding.path}
        </span>
      </TableCell>
      <TableCell>
        {finding.expected_md5 ? (
          <span
            className="font-mono text-xs text-[var(--color-muted-foreground)] tabular-nums"
            title={finding.expected_md5}
          >
            {finding.expected_md5.slice(0, 8)}
          </span>
        ) : (
          <span className="text-xs text-[var(--color-muted-foreground)]">
            n/a
          </span>
        )}
      </TableCell>
      <TableCell>
        {finding.actual_md5 ? (
          <span
            className="font-mono text-xs text-[var(--color-muted-foreground)] tabular-nums"
            title={finding.actual_md5}
          >
            {finding.actual_md5.slice(0, 8)}
          </span>
        ) : (
          <span className="text-xs text-[var(--color-muted-foreground)]">
            n/a
          </span>
        )}
      </TableCell>
      <TableCell>
        <div className="flex items-center justify-end gap-2">
          {canViewFile && (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={onViewFile}
              title={`View contents of ${finding.path}`}
              className="h-7 px-2 text-xs"
            >
              <Eye aria-hidden="true" className="size-3.5" />
              View file
            </Button>
          )}
          <Button
            type="button"
            size="sm"
            variant="ghost"
            disabled={isIgnoring}
            onClick={onIgnore}
            title={finding.ignored ? "Restore finding" : "Ignore finding"}
            className="h-7 px-2 text-xs"
          >
            {finding.ignored ? (
              <>
                <EyeOff aria-hidden="true" className="size-3.5" />
                Unignore
              </>
            ) : (
              <>
                <EyeOff aria-hidden="true" className="size-3.5" />
                Ignore
              </>
            )}
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
}

// ---------------------------------------------------------------------------
// Empty states
// ---------------------------------------------------------------------------

function NoFindingsEmpty() {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-6 py-12 text-center">
      <FileX aria-hidden="true" className="size-6 text-[var(--color-muted-foreground)]" />
      <p className="text-sm font-medium text-[var(--color-foreground)]">
        No integrity issues found
      </p>
      <p className="max-w-xs text-xs text-[var(--color-muted-foreground)]">
        No modified, missing, or unexpected files were detected in this scan.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function FindingsTableSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading findings"
      className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-card)]"
    >
      <span className="sr-only">Loading findings</span>
      <div className="flex items-center gap-4 border-b border-[var(--color-border)] px-3 py-2.5">
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-3 w-20" />
        <Skeleton className="h-3 flex-1" />
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-3 w-16" />
        <Skeleton className="h-3 w-20 ml-auto" />
      </div>
      {Array.from({ length: 4 }).map((_, i) => (
        <div
          key={i}
          className="flex items-center gap-4 border-b border-[var(--color-border)] px-3 py-3 last:border-0"
        >
          <Skeleton className="h-5 w-16 rounded" />
          <Skeleton className="h-5 w-20 rounded" />
          <Skeleton className="h-3 flex-1" />
          <Skeleton className="h-3 w-16" />
          <Skeleton className="h-3 w-16" />
          <Skeleton className="h-6 w-20 rounded ml-auto" />
        </div>
      ))}
    </div>
  );
}

// Re-export for external consumers
export type { ScanFindingsTableProps };
