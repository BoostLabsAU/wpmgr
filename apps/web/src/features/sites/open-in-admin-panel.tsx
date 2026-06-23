import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ExternalLink, Loader2, X } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { fade, drawerUp } from "@/lib/motion-presets";
import { toast } from "@/components/toast";
import { useAutoLogin } from "@/features/sites/use-autologin";
import type { Site } from "@wpmgr/api";

// Open-in-wp-admin persistent panel (#103).
//
// Replaces the toast fan-out pattern for bulk "Open in wp-admin" actions.
// Problems with toasts: Sonner caps visible toasts at ~5, they auto-dismiss
// after the first tab steal focus, and 21 sites would need 21 separate user
// gestures via ephemeral toast actions.
//
// This panel:
//   - Slides up from the bottom (same drawerUp preset as BulkActionDrawer).
//   - Lists ALL selected sites (no cap).
//   - Each row has its own "Open" button — each click is a distinct user
//     gesture so browsers allow the popup.
//   - The panel stays until the operator explicitly dismisses it (X button or
//     "Done" button at the bottom) so focus-steal does not lose sites.
//   - Resolves auto-login URLs in the background; rows show a spinner while
//     pending and an "Open" button when ready (or an error + retry).

interface SiteLoginState {
  siteId: string;
  siteName: string;
  status: "pending" | "ready" | "error";
  url?: string;
  errorMessage?: string;
}

export interface OpenInAdminPanelProps {
  /** The selected sites to open. Pass an empty array or null to close. */
  sites: Site[] | null;
  /** Called when the panel is dismissed. */
  onClose: () => void;
}

export function OpenInAdminPanel({ sites, onClose }: OpenInAdminPanelProps) {
  const visible = sites !== null && sites.length > 0;

  return (
    <AnimatePresence>
      {visible && sites !== null ? (
        <PanelContent sites={sites} onClose={onClose} />
      ) : null}
    </AnimatePresence>
  );
}

function PanelContent({
  sites,
  onClose,
}: {
  sites: Site[];
  onClose: () => void;
}) {
  const loginMutation = useAutoLogin();

  // Track per-site resolution state.
  const [rows, setRows] = useState<SiteLoginState[]>(() =>
    sites.map((s) => ({ siteId: s.id, siteName: s.name || s.url, status: "pending" })),
  );

  function updateRow(siteId: string, patch: Partial<SiteLoginState>) {
    setRows((prev) => prev.map((r) => (r.siteId === siteId ? { ...r, ...patch } : r)));
  }

  // Resolve all URLs on mount. We use a single shared mutation instance so
  // requests share the same loading state tracking, but we patch rows
  // individually via the ref.
  const resolveAll = useCallback(async () => {
    await Promise.allSettled(
      sites.map(async (site) => {
        try {
          const result = await loginMutation.mutateAsync({ siteId: site.id });
          updateRow(site.id, { status: "ready", url: result.redirect_url });
        } catch (err) {
          updateRow(site.id, {
            status: "error",
            errorMessage: err instanceof Error ? err.message : "Auto-login failed",
          });
        }
      }),
    );
  }, [sites, loginMutation]);

  // Run once on mount. Using a ref to prevent double-fire in strict mode.
  const resolvedRef = useRef(false);
  useEffect(() => {
    if (resolvedRef.current) return;
    resolvedRef.current = true;
    void resolveAll();
  }, [resolveAll]);

  // Escape key closes the panel.
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") {
        e.preventDefault();
        onClose();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const readyCount = useMemo(() => rows.filter((r) => r.status === "ready").length, [rows]);
  const totalCount = rows.length;

  async function retryRow(siteId: string) {
    updateRow(siteId, { status: "pending", errorMessage: undefined });
    const site = sites.find((s) => s.id === siteId);
    if (!site) return;
    try {
      const result = await loginMutation.mutateAsync({ siteId });
      updateRow(siteId, { status: "ready", url: result.redirect_url });
    } catch (err) {
      updateRow(siteId, {
        status: "error",
        errorMessage: err instanceof Error ? err.message : "Auto-login failed",
      });
    }
  }

  function openSite(row: SiteLoginState) {
    if (!row.url) return;
    window.open(row.url, "_blank", "noopener,noreferrer");
  }

  function openAll() {
    let opened = 0;
    for (const row of rows) {
      if (row.status === "ready" && row.url) {
        window.open(row.url, "_blank", "noopener,noreferrer");
        opened++;
      }
    }
    if (opened > 0) {
      toast.success(`Opened ${opened} ${opened === 1 ? "site" : "sites"} in new tabs`);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50"
      aria-hidden="false"
    >
      {/* Scrim */}
      <motion.button
        type="button"
        aria-label="Close panel"
        tabIndex={-1}
        onClick={onClose}
        variants={fade}
        initial="initial"
        animate="animate"
        exit="exit"
        className="absolute inset-0 bg-[var(--scrim)]"
      />

      {/* Panel */}
      <motion.section
        role="dialog"
        aria-modal="true"
        aria-labelledby="open-admin-panel-title"
        variants={drawerUp}
        initial="initial"
        animate="animate"
        exit="exit"
        className={cn(
          "absolute bottom-0 left-0 right-0",
          "max-h-[70vh] overflow-hidden",
          "rounded-t-xl border-t border-border bg-card text-card-foreground shadow-lg",
        )}
      >
        {/* Drag handle */}
        <div
          className="mx-auto mt-2 h-1.5 w-12 rounded-full bg-muted"
          aria-hidden="true"
        />

        <header className="flex items-start justify-between gap-4 px-6 pt-3 pb-2">
          <div className="min-w-0">
            <h2
              id="open-admin-panel-title"
              className="text-base font-semibold text-foreground"
            >
              Open in wp-admin ({totalCount})
            </h2>
            <p className="mt-1 text-xs tabular-nums text-muted-foreground">
              {readyCount < totalCount ? (
                <>
                  Resolving links{" "}
                  <span className="font-mono">{readyCount}</span> /{" "}
                  <span className="font-mono">{totalCount}</span> ready
                </>
              ) : (
                <>
                  <span className="font-mono">{readyCount}</span> links ready
                </>
              )}
            </p>
          </div>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label="Close panel"
            onClick={onClose}
          >
            <X aria-hidden="true" />
          </Button>
        </header>

        <div className="max-h-[calc(70vh-10rem)] overflow-y-auto px-6 pb-2">
          <ul className="divide-y divide-border">
            {rows.map((row) => (
              <OpenAdminRow
                key={row.siteId}
                row={row}
                onOpen={() => openSite(row)}
                onRetry={() => void retryRow(row.siteId)}
              />
            ))}
          </ul>
        </div>

        <footer className="flex items-center justify-between gap-3 border-t border-border bg-muted/40 px-6 py-3">
          <Button
            type="button"
            size="sm"
            onClick={openAll}
            disabled={readyCount === 0}
            aria-label={`Open all ${readyCount} ready sites in new tabs`}
            className="gap-1.5"
          >
            <ExternalLink aria-hidden="true" className="size-3.5" />
            Open all ready ({readyCount})
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onClose}
          >
            Done
          </Button>
        </footer>
      </motion.section>
    </div>
  );
}

function OpenAdminRow({
  row,
  onOpen,
  onRetry,
}: {
  row: SiteLoginState;
  onOpen: () => void;
  onRetry: () => void;
}) {
  return (
    <li className="flex items-center gap-3 py-2.5">
      <span
        className="min-w-0 flex-1 truncate font-mono text-sm text-foreground"
        title={row.siteName}
      >
        {row.siteName}
      </span>

      {row.status === "pending" ? (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
          Resolving
        </span>
      ) : row.status === "error" ? (
        <span className="flex items-center gap-2">
          <span
            className="text-xs text-destructive"
            title={row.errorMessage}
          >
            Failed
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onRetry}
            aria-label={`Retry opening ${row.siteName}`}
          >
            Retry
          </Button>
        </span>
      ) : (
        <Button
          type="button"
          size="sm"
          onClick={onOpen}
          aria-label={`Open ${row.siteName} in wp-admin`}
          className="gap-1.5"
        >
          <ExternalLink aria-hidden="true" className="size-3.5" />
          Open
        </Button>
      )}
    </li>
  );
}
