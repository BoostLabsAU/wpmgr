import { useId, useState } from "react";
import {
  AlertTriangle,
  CheckSquare,
  ChevronDown,
  ChevronUp,
  ExternalLink,
  HardDrive,
  Image,
  ImageOff,
  Layers,
  Loader2,
  RefreshCw,
  RotateCcw,
  ScanSearch,
  Square,
  Trash2,
  XCircle,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "@/components/toast";
import { cn } from "@/lib/utils";

import type { MediaCleanCandidate } from "@wpmgr/api";
import {
  useMediaCleanScan,
  useMediaCleanIsolate,
  useMediaCleanRestore,
  useMediaCleanDelete,
  useMediaCleanQuarantineList,
  CLIENT_PAGE_SIZE,
  type MediaCleanReferenced,
  type MediaCleanUsage,
  type MediaCleanQuarantineManifest,
} from "./use-media-clean";

// ── Surface label map ─────────────────────────────────────────────────────────
//
// Maps the raw surface identifier sent by the agent to a human-readable label.
// Unknown values fall back to a humanized form of the raw string.

const SURFACE_LABELS: Readonly<Record<string, string>> = {
  post_content: "Post / page content",
  post_excerpt: "Post excerpt",
  revision: "Post revision",
  thumbnail: "Featured image",
  postmeta: "Custom field / page builder",
  gallery: "Gallery",
  option: "Site setting",
  widget: "Widget",
  menu: "Navigation menu",
  term_meta: "Category / tag",
  user_meta: "User profile",
  direct_id: "Referenced by ID",
  path: "Referenced by file URL",
};

function surfaceLabel(surface: string): string {
  return (
    SURFACE_LABELS[surface] ??
    surface.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
  );
}

// Returns a sanitized href only when the URL scheme is http or https.
// Agent-supplied edit_url values are forwarded verbatim by the CP; React does
// not sanitize href, so javascript:/data: schemes must be blocked at the sink.
function safeHref(u: string | null | undefined): string | undefined {
  if (!u) return undefined;
  try {
    const parsed = new URL(u, window.location.origin);
    return parsed.protocol === "http:" || parsed.protocol === "https:"
      ? parsed.href
      : undefined;
  } catch {
    return undefined;
  }
}

// Returns a sanitized src only when the URL scheme is http or https.
// Agent-supplied thumb URLs are forwarded verbatim by the CP; while modern
// browsers do not execute scripts from <img src>, non-http(s) schemes (e.g.
// data:) are unexpected from an agent boundary and should be dropped.
function safeImgSrc(u: string | null | undefined): string | undefined {
  if (!u) return undefined;
  try {
    const parsed = new URL(u, window.location.origin);
    return parsed.protocol === "http:" || parsed.protocol === "https:"
      ? parsed.href
      : undefined;
  } catch {
    return undefined;
  }
}

// Derives a display basename from a URL string that may be malformed.
// item.url is the raw WP guid (site-controlled), not guaranteed to be a valid
// absolute URL; new URL() throws on bad input, which would unmount the subtree.
function basenameFromMaybeUrl(u: string): string {
  try {
    return new URL(u, window.location.origin).pathname.split("/").pop() ?? "";
  } catch {
    return u.split("/").pop() ?? "";
  }
}

// MediaCleanerPanel — Unused image cleaner tool (#190).
//
// Scan the media library for attachments not referenced anywhere, isolate
// (quarantine) them reversibly, then permanently delete from quarantine.
//
// Flow:
//   SCAN tab:      Lists candidate unused attachments with thumbnail, title,
//                  size, and sizes count. Multi-select with Isolate button.
//                  Paginated 50 per page (client-side slice of the full list).
//   QUARANTINE tab: Lists active quarantine manifests fetched from the server
//                  (GET /api/v1/sites/{siteId}/media/clean/quarantine). Each
//                  manifest shows its entries (title, attachment ID, file count),
//                  total_files, and isolation time. Per-manifest Restore and
//                  Delete buttons. Delete requires typing "DELETE" to confirm.
//                  The server list survives page refreshes — stranded items are
//                  visible and actionable immediately on re-open.
//
// Safety:
//   - Scan is READ-ONLY; it never touches files.
//   - Isolate moves files to wp-content/wpmgr-quarantine/media/ (reversible).
//   - Restore moves files back.
//   - Delete is permanent and only operates on quarantined items.
//   - A backup advisory is shown prominently before any destructive action.

interface Props {
  siteId: string;
  canOperate: boolean;
}

type TabId = "scan" | "quarantine";

// A pending-delete target: holds the manifest_id from the server list.
interface PendingDelete {
  manifestId: string;
}

export function MediaCleanerPanel({ siteId, canOperate }: Props) {
  const [activeTab, setActiveTab] = useState<TabId>("scan");

  // Client-side pagination state. The scan fetches ALL candidates in one request;
  // Previous/Next slice the in-memory array — no refetch on page changes.
  const [clientPage, setClientPage] = useState(0);

  const { data, isFetching, isError, error, refetch } = useMediaCleanScan(siteId);

  // Derive explicit scan states so disabled-query pending never shows a spinner.
  // idle    — never scanned this session (no data, not fetching)
  // scanning — request in-flight (isFetching === true)
  // done    — data received (ok:true or ok:false with detail)
  const scanPhase: "idle" | "scanning" | "done" =
    isFetching ? "scanning" : data !== undefined ? "done" : "idle";

  // Server can return ok:false on a 200 (e.g. uploads-unresolved).
  const scanAborted = data !== undefined && !data.ok;

  const isolateMut = useMediaCleanIsolate(siteId);
  const restoreMut = useMediaCleanRestore(siteId);
  const deleteMut = useMediaCleanDelete(siteId);

  // Server-side quarantine list: fetched whenever the Quarantine tab is active.
  const quarantineQuery = useMediaCleanQuarantineList(siteId, {
    enabled: activeTab === "quarantine",
  });
  const serverManifests: MediaCleanQuarantineManifest[] =
    quarantineQuery.data?.manifests ?? [];

  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [isolateTarget, setIsolateTarget] = useState<number[] | null>(null);
  const [deleteTargets, setDeleteTargets] = useState<PendingDelete[] | null>(null);

  // All candidates returned by the single scan fetch (full list, up to SCAN_MAX).
  const allCandidates = data?.candidates ?? [];
  const total = data?.total ?? 0;
  const truncated = data?.truncated === true;

  // Summary counts (v2 agent fields — undefined for older agents).
  const totalAttachments = data?.total_attachments;
  const referencedCount = data?.referenced_count;
  const unusedCount = data?.unused_count;
  const referencedItems: MediaCleanReferenced[] = data?.referenced ?? [];

  // Client-side page slice.
  const pageStart = clientPage * CLIENT_PAGE_SIZE;
  const candidates = allCandidates.slice(pageStart, pageStart + CLIENT_PAGE_SIZE);
  const hasMorePages = pageStart + CLIENT_PAGE_SIZE < allCandidates.length;

  // ── Selection helpers ────────────────────────────────────────────────────────

  function toggleOne(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  function toggleAll() {
    if (selected.size === candidates.length && candidates.length > 0) {
      setSelected(new Set());
    } else {
      setSelected(new Set(candidates.map((c) => c.id)));
    }
  }

  const allSelected =
    candidates.length > 0 && selected.size === candidates.length;
  const someSelected =
    selected.size > 0 && selected.size < candidates.length;

  // ── Isolate ──────────────────────────────────────────────────────────────────

  function handleIsolateIntent() {
    const ids = [...selected];
    if (ids.length === 0) return;
    setIsolateTarget(ids);
  }

  async function handleIsolateConfirm() {
    if (!isolateTarget || isolateMut.isPending) return;
    const ids = isolateTarget;
    setIsolateTarget(null);
    try {
      const result = await isolateMut.mutateAsync({ attachmentIds: ids });
      setSelected(new Set());
      toast.success(`${result.moved} file${result.moved !== 1 ? "s" : ""} quarantined.`);
      // Switch to the Quarantine tab — the server list will auto-fetch and
      // show the newly created manifest without any client-side tracking.
      setActiveTab("quarantine");
    } catch (err) {
      toast.error("Isolate failed", {
        description: err instanceof Error ? err.message : "Unknown error",
      });
    }
  }

  // ── Restore ──────────────────────────────────────────────────────────────────

  async function handleRestore(manifestId: string) {
    if (restoreMut.isPending) return;
    try {
      const result = await restoreMut.mutateAsync({
        quarantineIds: [manifestId],
      });
      toast.success(`${result.restored} file${result.restored !== 1 ? "s" : ""} restored.`);
      // The onSuccess invalidation in the mutation refetches the quarantine list.
    } catch (err) {
      toast.error("Restore failed", {
        description: err instanceof Error ? err.message : "Unknown error",
      });
    }
  }

  // ── Delete ───────────────────────────────────────────────────────────────────

  function handleDeleteIntent(targets: PendingDelete[]) {
    setDeleteTargets(targets);
  }

  async function handleDeleteConfirm() {
    if (!deleteTargets || deleteMut.isPending) return;
    const ids = deleteTargets.map((t) => t.manifestId);
    setDeleteTargets(null);
    try {
      const result = await deleteMut.mutateAsync({
        quarantineIds: ids,
        confirm: "DELETE",
      });
      toast.success(`${result.deleted} attachment${result.deleted !== 1 ? "s" : ""} permanently removed.`);
      // The onSuccess invalidation in the mutation refetches the quarantine list.
    } catch (err) {
      toast.error("Delete failed", {
        description: err instanceof Error ? err.message : "Unknown error",
      });
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-base font-semibold">Unused Image Cleaner</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Find attachments that are not referenced anywhere in your content,
          isolate them reversibly, and permanently delete to reclaim disk space.
          Scan uses an exhaustive reference check across all post types, page
          builders, options, and metadata.
        </p>
      </div>

      {/* Backup advisory */}
      <div
        role="note"
        className="flex items-start gap-2.5 rounded-md border border-amber-400/40 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-300"
      >
        <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
        <p>
          <strong>Back up your site before isolating or deleting media.</strong>{" "}
          Isolate is reversible (files can be restored), but permanent delete
          cannot be undone. If you do not have a recent backup, take a Database
          Snapshot above and use your host's file backup first.
        </p>
      </div>

      {/* Tabs: Scan / Quarantine */}
      <Tabs
        value={activeTab}
        onValueChange={(v) => setActiveTab(v as TabId)}
      >
        <TabsList>
          <TabsTrigger value="scan">
            Scan results
            {scanPhase === "done" && !scanAborted && total > 0 && (
              <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums">
                {total}
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="quarantine">
            Quarantine
            {serverManifests.length > 0 && (
              <span className="ml-1.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium tabular-nums text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                {serverManifests.length}
              </span>
            )}
          </TabsTrigger>
        </TabsList>

        {/* ── SCAN TAB ─────────────────────────────────────────────────────── */}
        <TabsContent value="scan" className="mt-4 space-y-4">

          {/* ── STATE: IDLE — never scanned this session ──────────────────── */}
          {scanPhase === "idle" && (
            <div className="flex flex-col items-center gap-4 rounded-md border border-dashed py-10 text-center">
              <ScanSearch
                aria-hidden="true"
                className="size-8 text-muted-foreground"
              />
              <div className="space-y-1">
                <p className="text-sm font-medium">No scan run yet</p>
                <p className="max-w-xs text-xs text-muted-foreground">
                  Click Scan to check the media library for attachments that are
                  not referenced in any post, page, or builder content.
                </p>
              </div>
              <Button
                size="sm"
                className="gap-1.5"
                onClick={() => { setClientPage(0); setSelected(new Set()); void refetch(); }}
                aria-label="Scan media library for unused attachments"
              >
                <ScanSearch aria-hidden="true" className="size-3.5" />
                Scan
              </Button>
            </div>
          )}

          {/* ── STATE: SCANNING — request in-flight ───────────────────────── */}
          {scanPhase === "scanning" && (
            <div
              className="space-y-2"
              aria-label="Scanning media library"
              aria-live="polite"
              aria-busy="true"
            >
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2
                  aria-hidden="true"
                  className="size-4 animate-spin"
                />
                Scanning...
              </div>
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-16 w-full rounded-md" />
              ))}
            </div>
          )}

          {/* ── STATE: DONE ────────────────────────────────────────────────── */}
          {scanPhase === "done" && (
            <>
              {/* Toolbar: Re-scan + summary + isolate */}
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => { setClientPage(0); setSelected(new Set()); void refetch(); }}
                  aria-label="Re-scan media library"
                >
                  <RefreshCw aria-hidden="true" className="size-3.5" />
                  Re-scan
                </Button>

                {!scanAborted && total > 0 && (
                  <span className="text-xs text-muted-foreground">
                    {total} unused attachment{total !== 1 ? "s" : ""} found
                    {truncated && " (first 500 shown)"}
                  </span>
                )}

                {selected.size > 0 && canOperate && (
                  <Button
                    size="sm"
                    className="ml-auto gap-1.5"
                    onClick={handleIsolateIntent}
                    disabled={isolateMut.isPending}
                    aria-label={`Isolate ${selected.size} selected attachment${selected.size !== 1 ? "s" : ""}`}
                  >
                    <HardDrive aria-hidden="true" className="size-3.5" />
                    Isolate {selected.size} selected
                  </Button>
                )}
              </div>

              {/* ── Scan summary stats (v2 agent fields) ─────────────────── */}
              {!scanAborted && totalAttachments !== undefined && (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                  <span className="flex items-center gap-1.5">
                    <Layers aria-hidden="true" className="size-3.5 shrink-0" />
                    <strong className="font-medium tabular-nums text-foreground">
                      {totalAttachments}
                    </strong>{" "}
                    attachment{totalAttachments !== 1 ? "s" : ""} scanned
                  </span>
                  <span aria-hidden="true" className="select-none text-border">
                    &middot;
                  </span>
                  <span className="flex items-center gap-1.5">
                    <HardDrive aria-hidden="true" className="size-3.5 shrink-0" />
                    <strong className="font-medium tabular-nums text-foreground">
                      {unusedCount ?? total}
                    </strong>{" "}
                    unused
                  </span>
                  <span aria-hidden="true" className="select-none text-border">
                    &middot;
                  </span>
                  <span className="flex items-center gap-1.5">
                    <Image aria-hidden="true" className="size-3.5 shrink-0" />
                    <strong className="font-medium tabular-nums text-foreground">
                      {referencedCount ?? 0}
                    </strong>{" "}
                    in use
                  </span>
                </div>
              )}

              {/* Server-side abort (ok:false) — e.g. uploads-unresolved */}
              {scanAborted && (
                <div
                  role="alert"
                  className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <XCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <div className="space-y-1">
                    <p className="font-medium">Scan could not complete.</p>
                    {data.detail && (
                      <p className="text-xs">{data.detail}</p>
                    )}
                  </div>
                </div>
              )}

              {/* Network / HTTP error */}
              {isError && (
                <div
                  role="alert"
                  className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <XCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <div className="space-y-1">
                    <p>Scan failed.</p>
                    {error instanceof Error && (
                      <p className="text-xs">{error.message}</p>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => void refetch()}
                      className="h-auto px-0 text-xs text-destructive"
                    >
                      Retry
                    </Button>
                  </div>
                </div>
              )}

              {/* Empty: scan completed, nothing found */}
              {!scanAborted && !isError && allCandidates.length === 0 && (
                <div className="flex items-center gap-2 rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                  <Image aria-hidden="true" className="size-4 shrink-0" />
                  No unused attachments found.
                </div>
              )}

              {/* Candidate list */}
              {!scanAborted && candidates.length > 0 && (
                <div className="rounded-md border">
                  {/* Select-all header */}
                  {canOperate && (
                    <div className="flex items-center gap-3 border-b px-4 py-2">
                      <button
                        type="button"
                        onClick={toggleAll}
                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                        aria-label={allSelected ? "Deselect all" : "Select all"}
                      >
                        {allSelected ? (
                          <CheckSquare aria-hidden="true" className="size-4" />
                        ) : someSelected ? (
                          <CheckSquare
                            aria-hidden="true"
                            className="size-4 opacity-50"
                          />
                        ) : (
                          <Square aria-hidden="true" className="size-4" />
                        )}
                        {allSelected ? "Deselect all" : "Select all"}
                      </button>
                      <span className="ml-auto text-xs text-muted-foreground">
                        {selected.size} of {candidates.length} selected
                      </span>
                    </div>
                  )}

                  <ul role="list" className="divide-y divide-border">
                    {candidates.map((candidate) => (
                      <CandidateRow
                        key={candidate.id}
                        candidate={candidate}
                        isSelected={selected.has(candidate.id)}
                        canOperate={canOperate}
                        onToggle={() => toggleOne(candidate.id)}
                        onIsolate={() => {
                          setIsolateTarget([candidate.id]);
                        }}
                      />
                    ))}
                  </ul>
                </div>
              )}

              {/* Client-side pagination — slices the in-memory allCandidates array; no refetch */}
              {!scanAborted && allCandidates.length > 0 && (
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={clientPage === 0}
                    onClick={() => {
                      setClientPage((p) => Math.max(0, p - 1));
                      setSelected(new Set());
                    }}
                  >
                    Previous
                  </Button>
                  <span className="text-xs text-muted-foreground">
                    {pageStart + 1}–{pageStart + candidates.length} of {total}
                    {truncated && " (first 500 shown)"}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={!hasMorePages}
                    onClick={() => {
                      setClientPage((p) => p + 1);
                      setSelected(new Set());
                    }}
                  >
                    Next
                  </Button>
                </div>
              )}

              {/* ── In-use section ────────────────────────────────────────── */}
              {/* Only render when the v2 referenced field is present. */}
              {!scanAborted && data?.referenced !== undefined && (
                <InUseSection items={referencedItems} />
              )}
            </>
          )}
        </TabsContent>

        {/* ── QUARANTINE TAB ────────────────────────────────────────────────── */}
        <TabsContent value="quarantine" className="mt-4 space-y-4">

          {/* Loading — isFetching (never isPending) so cached data renders immediately */}
          {quarantineQuery.isFetching && serverManifests.length === 0 && (
            <div
              className="space-y-2"
              aria-label="Loading quarantine list"
              aria-live="polite"
              aria-busy="true"
            >
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                Loading quarantined items...
              </div>
              {[0, 1].map((i) => (
                <Skeleton key={i} className="h-20 w-full rounded-md" />
              ))}
            </div>
          )}

          {/* Network error */}
          {quarantineQuery.isError && (
            <div
              role="alert"
              className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
            >
              <XCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
              <div className="space-y-1">
                <p>Could not load quarantine list.</p>
                {quarantineQuery.error instanceof Error && (
                  <p className="text-xs">{quarantineQuery.error.message}</p>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => void quarantineQuery.refetch()}
                  className="h-auto px-0 text-xs text-destructive"
                >
                  Retry
                </Button>
              </div>
            </div>
          )}

          {/* Empty state (data loaded, no manifests) */}
          {!quarantineQuery.isFetching &&
            !quarantineQuery.isError &&
            serverManifests.length === 0 && (
              <div className="flex items-center gap-2 rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                <HardDrive aria-hidden="true" className="size-4 shrink-0" />
                No quarantined items.
              </div>
            )}

          {/* Manifest list */}
          {serverManifests.length > 0 && (
            <>
              {/* Subtle refresh indicator when re-fetching with stale data visible */}
              {quarantineQuery.isFetching && (
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <Loader2 aria-hidden="true" className="size-3 animate-spin" />
                  Refreshing...
                </div>
              )}

              {/* Bulk delete all */}
              {canOperate && (
                <div className="flex justify-end">
                  <Button
                    variant="destructive"
                    size="sm"
                    className="gap-1.5"
                    onClick={() =>
                      handleDeleteIntent(
                        serverManifests.map((m) => ({ manifestId: m.manifest_id })),
                      )
                    }
                    disabled={deleteMut.isPending}
                    aria-label="Permanently delete all quarantined attachments"
                  >
                    <Trash2 aria-hidden="true" className="size-3.5" />
                    Delete all ({serverManifests.length})
                  </Button>
                </div>
              )}

              <div className="rounded-md border">
                <ul role="list" className="divide-y divide-border">
                  {serverManifests.map((manifest) => (
                    <QuarantineRow
                      key={manifest.manifest_id}
                      manifest={manifest}
                      canOperate={canOperate}
                      isRestoring={
                        restoreMut.isPending &&
                        (restoreMut.variables?.quarantineIds.includes(
                          manifest.manifest_id,
                        ) ?? false)
                      }
                      isDeleting={
                        deleteMut.isPending &&
                        (deleteTargets?.some(
                          (t) => t.manifestId === manifest.manifest_id,
                        ) ?? false)
                      }
                      onRestore={() => void handleRestore(manifest.manifest_id)}
                      onDelete={() =>
                        handleDeleteIntent([{ manifestId: manifest.manifest_id }])
                      }
                    />
                  ))}
                </ul>
              </div>
            </>
          )}
        </TabsContent>
      </Tabs>

      {/* Isolate confirm dialog */}
      {isolateTarget !== null && (
        <IsolateConfirmDialog
          count={isolateTarget.length}
          isPending={isolateMut.isPending}
          errorMessage={isolateMut.isError ? isolateMut.error.message : null}
          onConfirm={() => void handleIsolateConfirm()}
          onClose={() => setIsolateTarget(null)}
        />
      )}

      {/* Delete confirm dialog */}
      {deleteTargets !== null && (
        <DeleteConfirmDialog
          count={deleteTargets.length}
          isPending={deleteMut.isPending}
          errorMessage={deleteMut.isError ? deleteMut.error.message : null}
          onConfirm={() => void handleDeleteConfirm()}
          onClose={() => setDeleteTargets(null)}
        />
      )}
    </div>
  );
}

// ── InUseSection ─────────────────────────────────────────────────────────────
//
// Collapsible list of attachments confirmed to be in use (referenced).
// Informational only — no isolate/delete actions.

interface InUseSectionProps {
  items: MediaCleanReferenced[];
}

function InUseSection({ items }: InUseSectionProps) {
  const [open, setOpen] = useState(false);
  const count = items.length;

  return (
    <div className="rounded-md border">
      {/* Section header — always visible; acts as the collapse toggle */}
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center gap-2 px-4 py-3 text-left"
        aria-expanded={open}
      >
        <Image aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
        <span className="flex-1 text-sm font-medium">
          In use
          <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums">
            {count}
          </span>
        </span>
        <span className="text-xs text-muted-foreground">
          {count === 0
            ? "No attachments are actively referenced"
            : open
              ? "Collapse"
              : "Expand to see where each is used"}
        </span>
        {count > 0 && (
          open ? (
            <ChevronUp aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          ) : (
            <ChevronDown aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          )
        )}
      </button>

      {/* Collapsible body */}
      {open && count > 0 && (
        <ul role="list" className="divide-y divide-border border-t">
          {items.map((item) => (
            <ReferencedRow key={item.id} item={item} />
          ))}
        </ul>
      )}

      {/* Inline empty state when expanded with no items */}
      {open && count === 0 && (
        <div className="border-t px-4 py-4 text-xs text-muted-foreground">
          Nothing else references your media.
        </div>
      )}
    </div>
  );
}

// ── ReferencedRow ─────────────────────────────────────────────────────────────

interface ReferencedRowProps {
  item: MediaCleanReferenced;
}

function ReferencedRow({ item }: ReferencedRowProps) {
  const title = item.title || basenameFromMaybeUrl(item.url) || "Untitled";
  const thumbSrc = safeImgSrc(item.thumb);

  return (
    <li className="flex items-start gap-3 px-4 py-3">
      {/* Thumbnail */}
      <div className="mt-0.5 size-10 shrink-0 overflow-hidden rounded border bg-muted">
        {thumbSrc ? (
          <img
            src={thumbSrc}
            alt=""
            aria-hidden="true"
            className="size-full object-cover"
            loading="lazy"
          />
        ) : (
          <div className="flex size-full items-center justify-center">
            <ImageOff
              aria-hidden="true"
              className="size-4 text-muted-foreground"
            />
          </div>
        )}
      </div>

      {/* Info */}
      <div className="min-w-0 flex-1 space-y-1.5">
        <p className="truncate text-sm font-medium" title={title}>
          {title}
        </p>

        {/* Usage chips */}
        <ul role="list" className="space-y-1">
          {item.usages.map((usage, idx) => (
            <UsageChip
              // eslint-disable-next-line react/no-array-index-key
              key={idx}
              usage={usage}
            />
          ))}
        </ul>
      </div>
    </li>
  );
}

// ── UsageChip ─────────────────────────────────────────────────────────────────

interface UsageChipProps {
  usage: MediaCleanUsage;
}

function UsageChip({ usage }: UsageChipProps) {
  const label = surfaceLabel(usage.surface);
  const withSource =
    usage.source_label ? `${label} — ${usage.source_label}` : label;

  const href = safeHref(usage.edit_url);

  return (
    <li className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5 text-xs">
      {href !== undefined ? (
        <a
          href={href}
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-0.5 rounded bg-muted px-1.5 py-0.5 font-medium text-foreground hover:underline"
        >
          {withSource}
          <ExternalLink aria-hidden="true" className="size-3 shrink-0 opacity-60" />
        </a>
      ) : (
        <span className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 font-medium text-foreground">
          {withSource}
        </span>
      )}
      {usage.detail && (
        <span className="text-muted-foreground">{usage.detail}</span>
      )}
    </li>
  );
}

// ── CandidateRow ─────────────────────────────────────────────────────────────

interface CandidateRowProps {
  candidate: MediaCleanCandidate;
  isSelected: boolean;
  canOperate: boolean;
  onToggle: () => void;
  onIsolate: () => void;
}

function CandidateRow({
  candidate,
  isSelected,
  canOperate,
  onToggle,
  onIsolate,
}: CandidateRowProps) {
  const checkId = useId();
  const title = candidate.title || basenameFromMaybeUrl(candidate.url) || "Untitled";
  const size = formatBytes(candidate.file_size);
  const thumbSrc = safeImgSrc(candidate.thumb);

  return (
    <li
      className={cn(
        "flex items-center gap-3 px-4 py-3 transition-colors",
        isSelected && "bg-muted/40",
      )}
    >
      {canOperate && (
        <Checkbox
          id={checkId}
          checked={isSelected}
          onChange={onToggle}
          aria-label={`Select ${title}`}
        />
      )}

      {/* Thumbnail */}
      <div className="size-12 shrink-0 overflow-hidden rounded border bg-muted">
        {thumbSrc ? (
          <img
            src={thumbSrc}
            alt=""
            aria-hidden="true"
            className="size-full object-cover"
            loading="lazy"
          />
        ) : (
          <div className="flex size-full items-center justify-center">
            <Image
              aria-hidden="true"
              className="size-5 text-muted-foreground"
            />
          </div>
        )}
      </div>

      {/* Info */}
      <div className="min-w-0 flex-1">
        <label
          htmlFor={checkId}
          className="block cursor-pointer truncate text-sm font-medium"
          title={title}
        >
          {title}
        </label>
        <p className="text-xs text-muted-foreground">
          {size}
          {candidate.sizes_count > 0 && ` + ${candidate.sizes_count} size${candidate.sizes_count !== 1 ? "s" : ""}`}
        </p>
      </div>

      {canOperate && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0 gap-1.5"
          onClick={onIsolate}
          aria-label={`Isolate ${title}`}
        >
          <HardDrive aria-hidden="true" className="size-3.5" />
          Isolate
        </Button>
      )}
    </li>
  );
}

// ── QuarantineRow ─────────────────────────────────────────────────────────────
//
// Renders one quarantine manifest returned by the server list endpoint.
// Shows isolation time, total file count, and the per-attachment entry list.

interface QuarantineRowProps {
  manifest: MediaCleanQuarantineManifest;
  canOperate: boolean;
  isRestoring: boolean;
  isDeleting: boolean;
  onRestore: () => void;
  onDelete: () => void;
}

function QuarantineRow({
  manifest,
  canOperate,
  isRestoring,
  isDeleting,
  onRestore,
  onDelete,
}: QuarantineRowProps) {
  // isolated_at is unix seconds — convert to ms for relativeTime.
  const isolated = relativeTime(manifest.isolated_at * 1000);

  return (
    <li className="space-y-2 px-4 py-3">
      {/* Manifest header row */}
      <div className="flex items-start gap-3">
        <HardDrive
          aria-hidden="true"
          className="mt-0.5 size-4 shrink-0 text-muted-foreground"
        />

        <div className="min-w-0 flex-1">
          <p
            className="truncate font-mono text-xs text-foreground"
            title={manifest.manifest_id}
          >
            {manifest.manifest_id.slice(0, 8)}&hellip;
          </p>
          <p className="text-xs text-muted-foreground">
            {manifest.total_files} file{manifest.total_files !== 1 ? "s" : ""}
            &middot; isolated {isolated}
          </p>
        </div>

        {canOperate && (
          <div className="flex shrink-0 items-center gap-1">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={onRestore}
              disabled={isRestoring}
              aria-label="Restore attachment files from quarantine"
            >
              {isRestoring ? (
                <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
              ) : (
                <RotateCcw aria-hidden="true" className="size-3.5" />
              )}
              Restore
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="size-8 text-muted-foreground hover:text-destructive"
              onClick={onDelete}
              disabled={isDeleting}
              aria-label="Permanently delete quarantined attachment"
            >
              {isDeleting ? (
                <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
              ) : (
                <Trash2 aria-hidden="true" className="size-3.5" />
              )}
            </Button>
          </div>
        )}
      </div>

      {/* Per-attachment entry list */}
      {manifest.entries.length > 0 && (
        <ul
          role="list"
          className="ml-7 space-y-0.5 border-l border-border pl-3"
        >
          {manifest.entries.map((entry) => (
            <li
              key={entry.attachment_id}
              className="flex items-baseline gap-2 text-xs text-muted-foreground"
            >
              <span
                className="min-w-0 flex-1 truncate font-medium text-foreground"
                title={entry.title}
              >
                {entry.title || `Attachment #${entry.attachment_id}`}
              </span>
              <span className="shrink-0 tabular-nums">
                #{entry.attachment_id}
              </span>
              <span className="shrink-0 tabular-nums">
                {entry.file_count} file{entry.file_count !== 1 ? "s" : ""}
              </span>
            </li>
          ))}
        </ul>
      )}
    </li>
  );
}

// ── IsolateConfirmDialog ──────────────────────────────────────────────────────

interface IsolateDialogProps {
  count: number;
  isPending: boolean;
  errorMessage: string | null;
  onConfirm: () => void;
  onClose: () => void;
}

function IsolateConfirmDialog({
  count,
  isPending,
  errorMessage,
  onConfirm,
  onClose,
}: IsolateDialogProps) {
  const titleId = useId();
  const descId = useId();

  return (
    <Dialog open onClose={onClose}>
      <DialogContent ariaLabelledBy={titleId} ariaDescribedBy={descId}>
        <DialogHeader>
          <DialogTitle id={titleId}>
            Isolate {count} attachment{count !== 1 ? "s" : ""}?
          </DialogTitle>
        </DialogHeader>

        <DialogBody>
          <div
            id={descId}
            role="note"
            className="flex gap-2 rounded-md border border-amber-400/40 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-300"
          >
            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-1">
              <p>
                The selected files will be moved to a quarantine folder on the
                server. This is <strong>reversible</strong> — use Restore to move
                them back. However, isolating a file that is still in use will
                break the images wherever it is displayed.
              </p>
              <p>
                Ensure you have a recent backup before proceeding.
              </p>
            </div>
          </div>

          {errorMessage && (
            <div className="mt-3 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-2.5 text-sm text-destructive">
              <XCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
              <span>{errorMessage}</span>
            </div>
          )}
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={onConfirm}
            disabled={isPending}
          >
            {isPending ? (
              <>
                <Loader2 aria-hidden="true" className="mr-1.5 size-4 animate-spin" />
                Isolating...
              </>
            ) : (
              `Isolate ${count} attachment${count !== 1 ? "s" : ""}`
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── DeleteConfirmDialog ───────────────────────────────────────────────────────

interface DeleteDialogProps {
  count: number;
  isPending: boolean;
  errorMessage: string | null;
  onConfirm: () => void;
  onClose: () => void;
}

function DeleteConfirmDialog({
  count,
  isPending,
  errorMessage,
  onConfirm,
  onClose,
}: DeleteDialogProps) {
  const titleId = useId();
  const descId = useId();
  const [typed, setTyped] = useState("");
  const canConfirm = typed.trim() === "DELETE" && !isPending;

  return (
    <Dialog open onClose={onClose}>
      <DialogContent ariaLabelledBy={titleId} ariaDescribedBy={descId}>
        <DialogHeader>
          <DialogTitle id={titleId}>
            Permanently delete {count} quarantined batch{count !== 1 ? "es" : ""}?
          </DialogTitle>
        </DialogHeader>

        <DialogBody>
          <div
            id={descId}
            role="alert"
            className="flex gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
          >
            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-1">
              <p>
                <strong>This cannot be undone.</strong> The attachment files and
                WordPress media library entries will be permanently removed from
                the server.
              </p>
              <p>
                Make sure you have a backup before proceeding.
              </p>
            </div>
          </div>

          {errorMessage && (
            <div className="mt-3 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-2.5 text-sm text-destructive">
              <XCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
              <span>{errorMessage}</span>
            </div>
          )}

          <div className="mt-4 space-y-1.5">
            <Label htmlFor="delete-confirm-input">
              Type{" "}
              <code className="rounded bg-muted px-1 font-mono text-xs">
                DELETE
              </code>{" "}
              to confirm
            </Label>
            <Input
              id="delete-confirm-input"
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              disabled={isPending}
              autoComplete="off"
              aria-describedby="delete-confirm-hint"
            />
            <p
              id="delete-confirm-hint"
              className={cn(
                "text-xs",
                typed && !canConfirm && !isPending
                  ? "text-destructive"
                  : "text-muted-foreground",
              )}
            >
              {typed && !canConfirm && !isPending
                ? "Text does not match."
                : "Case-sensitive."}
            </p>
          </div>
        </DialogBody>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={isPending}
          >
            Keep in quarantine
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={onConfirm}
            disabled={!canConfirm}
          >
            {isPending ? (
              <>
                <Loader2 aria-hidden="true" className="mr-1.5 size-4 animate-spin" />
                Deleting...
              </>
            ) : (
              `Delete permanently`
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function relativeTime(ms: number): string {
  if (!ms) return "unknown";
  const diffMs = Date.now() - ms;
  const diffMin = Math.floor(diffMs / 60_000);
  if (diffMin < 1) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDays = Math.floor(diffHr / 24);
  if (diffDays < 7) return `${diffDays}d ago`;
  return new Date(ms).toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
  });
}

function formatBytes(bytes: number): string {
  if (!bytes) return "0 B";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024)
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}
