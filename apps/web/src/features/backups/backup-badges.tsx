import type { BackupSnapshot } from "@wpmgr/api";

import { Badge } from "@/components/ui/badge";

type Status = BackupSnapshot["status"];
type Kind = BackupSnapshot["kind"];

const statusMeta: Record<
  Status,
  { label: string; variant: "success" | "destructive" | "muted" | "secondary" }
> = {
  pending: { label: "Pending", variant: "muted" },
  running: { label: "Running", variant: "secondary" },
  completed: { label: "Completed", variant: "success" },
  failed: { label: "Failed", variant: "destructive" },
};

/** Color-coded snapshot/restore status badge. */
export function StatusBadge({ status }: { status: Status }) {
  const meta = statusMeta[status];
  return (
    <Badge variant={meta.variant} aria-label={`Status: ${meta.label}`}>
      {meta.label}
    </Badge>
  );
}

const kindLabel: Record<Kind, string> = {
  files: "Files",
  db: "Database",
  full: "Full",
};

/** Neutral badge describing what a snapshot captured (files / db / full). */
export function KindBadge({ kind }: { kind: Kind }) {
  return <Badge variant="outline">{kindLabel[kind]}</Badge>;
}

/**
 * ADR-048 incremental visibility. Renders the "Incremental · gen N" badge ONLY
 * for an incremental snapshot; a full/legacy snapshot (is_incremental false or
 * undefined and generation 0/undefined) renders nothing, so existing full
 * snapshots look exactly as they did before this badge existed.
 */
export function IncrementalBadge({
  isIncremental,
  generation,
}: {
  isIncremental?: BackupSnapshot["is_incremental"];
  generation?: BackupSnapshot["generation"];
}) {
  const gen = generation ?? 0;
  if (!isIncremental && gen === 0) {
    return null;
  }
  return (
    <Badge variant="secondary" aria-label={`Incremental snapshot, generation ${gen}`}>
      Incremental · gen {gen}
    </Badge>
  );
}
