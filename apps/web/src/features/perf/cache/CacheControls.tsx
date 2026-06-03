import { useId, useState } from "react";
import { Loader2, Power, RefreshCw, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DestructiveConfirm } from "@/components/dialogs/destructive-confirm";
import { toast } from "@/components/toast";

import {
  usePreloadCache,
  usePurgeCache,
  useToggleCache,
} from "../hooks/useCacheStats";

// Cache action toolbar: Purge all (destructive confirm), Purge URL, Preload,
// and the Enable/Disable caching toggle button. Each action's authoritative
// result lands via SSE → useCacheStats invalidation; the buttons only fire the
// command and toast.

export interface CacheControlsProps {
  siteId: string;
  hostname: string;
  /** Whether caching is currently enabled (drives the toggle button). */
  cacheEnabled: boolean;
  /** operator+ — purge / preload. */
  canPurge: boolean;
  /** operator+ — enable / disable caching. */
  canManage: boolean;
  /** admin+ — the destructive "delete everything" purge. */
  canDeleteAll: boolean;
}

export function CacheControls({
  siteId,
  hostname,
  cacheEnabled,
  canPurge,
  canManage,
  canDeleteAll,
}: CacheControlsProps) {
  const [purgeAllOpen, setPurgeAllOpen] = useState(false);
  const [purgeUrlOpen, setPurgeUrlOpen] = useState(false);

  const purge = usePurgeCache(siteId);
  const preload = usePreloadCache(siteId);
  const toggle = useToggleCache(siteId);

  function confirmPurgeAll() {
    purge.mutate(
      { scope: "all", delete_everything: canDeleteAll },
      {
        onSuccess: (res) => {
          setPurgeAllOpen(false);
          if (res.ok) {
            toast.success("Purging the entire cache.", {
              description: "The server is clearing every cached page.",
            });
          } else {
            toast.error("The agent declined the purge.", {
              description: res.detail,
            });
          }
        },
      },
    );
  }

  function runPreload() {
    preload.mutate(undefined, {
      onSuccess: (res) => {
        if (res.ok) {
          toast.success("Preload started.", {
            description: "Warming up the cache in the background.",
          });
        } else {
          toast.error("Could not start preload.", { description: res.detail });
        }
      },
    });
  }

  function runToggle() {
    const next = !cacheEnabled;
    toggle.mutate(next, {
      onSuccess: (res) => {
        if (res.ok) {
          toast.success(next ? "Caching enabled." : "Caching disabled.", {
            description: next
              ? "Applying the drop-in on the server…"
              : "The cache drop-in is being removed.",
          });
        } else {
          toast.error("Could not change caching.", { description: res.detail });
        }
      },
    });
  }

  const busy = purge.isPending || preload.isPending || toggle.isPending;

  return (
    <div className="flex flex-wrap items-center gap-2">
      {canManage ? (
        <Button
          type="button"
          variant={cacheEnabled ? "outline" : "default"}
          size="sm"
          onClick={runToggle}
          disabled={busy}
          aria-label={cacheEnabled ? "Disable caching" : "Enable caching"}
        >
          {toggle.isPending ? (
            <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          ) : (
            <Power aria-hidden="true" className="size-4" />
          )}
          {cacheEnabled ? "Disable caching" : "Enable caching"}
        </Button>
      ) : null}

      {canPurge ? (
        <>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setPurgeUrlOpen(true)}
            disabled={busy}
          >
            <Trash2 aria-hidden="true" className="size-4" />
            Purge URL
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={runPreload}
            disabled={busy}
          >
            <RefreshCw
              aria-hidden="true"
              className={preload.isPending ? "size-4 animate-spin" : "size-4"}
            />
            Preload
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            onClick={() => setPurgeAllOpen(true)}
            disabled={busy}
          >
            <Trash2 aria-hidden="true" className="size-4" />
            Purge everything
          </Button>
        </>
      ) : null}

      {/* Destructive: typing the hostname gates the full purge. */}
      <DestructiveConfirm
        open={purgeAllOpen}
        onClose={() => setPurgeAllOpen(false)}
        onConfirm={confirmPurgeAll}
        title={`Purge everything for ${hostname}`}
        resourceName={hostname}
        confirmLabel="Purge everything"
        cancelLabel="Keep cache"
        isPending={purge.isPending}
        errorMessage={purge.isError ? purge.error.message : null}
        consequencesBody={
          <div className="space-y-2">
            <p>
              Every cached page for this site is cleared. The next visitor to
              each page triggers a fresh render until the cache rebuilds.
            </p>
            <p>
              {canDeleteAll
                ? "This also removes the generated cache files on disk."
                : "Cached pages are cleared; cache files are regenerated on demand."}
            </p>
          </div>
        }
      />

      <PurgeUrlDialog
        open={purgeUrlOpen}
        onClose={() => setPurgeUrlOpen(false)}
        isPending={purge.isPending}
        onConfirm={(url) =>
          purge.mutate(
            { scope: "url", url },
            {
              onSuccess: (res) => {
                setPurgeUrlOpen(false);
                if (res.ok) {
                  toast.success("Purged the URL.", { description: url });
                } else {
                  toast.error("The agent declined the purge.", {
                    description: res.detail,
                  });
                }
              },
            },
          )
        }
      />
    </div>
  );
}

interface PurgeUrlDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: (url: string) => void;
  isPending: boolean;
}

function PurgeUrlDialog({
  open,
  onClose,
  onConfirm,
  isPending,
}: PurgeUrlDialogProps) {
  const titleId = useId();
  const inputId = useId();
  const [url, setUrl] = useState("");

  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) setUrl("");
  }

  const trimmed = url.trim();
  const canConfirm = trimmed.length > 0 && !isPending;

  return (
    <Dialog open={open} onClose={isPending ? () => {} : onClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId}>Purge a single URL</DialogTitle>
        </DialogHeader>
        <DialogBody>
          <p className="text-sm text-muted-foreground">
            Clear the cached copy of one page. Visitors get a fresh render on
            the next request to that URL.
          </p>
          <div className="space-y-2">
            <Label htmlFor={inputId}>Page URL or path</Label>
            <Input
              id={inputId}
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="/blog/my-post or https://example.com/page"
              data-autofocus
              disabled={isPending}
              aria-label="URL to purge from the cache"
            />
          </div>
        </DialogBody>
        <DialogFooter className="pt-2">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={isPending}
          >
            Keep cache
          </Button>
          <Button
            type="button"
            onClick={() => canConfirm && onConfirm(trimmed)}
            disabled={!canConfirm}
          >
            {isPending ? (
              <>
                <Loader2 aria-hidden="true" className="animate-spin" />
                <span className="sr-only">Purge URL</span>
              </>
            ) : (
              "Purge URL"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
