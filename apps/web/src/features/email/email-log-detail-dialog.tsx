import { useId } from "react";
import { ChevronLeft, ChevronRight, X, RotateCcw, Loader2 } from "lucide-react";

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogBody,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { TooltipProvider, Tooltip } from "@/components/ui/tooltip";
import { relativeTime } from "@/lib/utils";
import type { SiteEmailLogEntry } from "@wpmgr/api";
import { useEmailLogDetail, useResendEmail, BodyNotStoredError } from "./use-email";
import { EmailStatusBadge } from "./email-status-badge";

// ---------------------------------------------------------------------------
// Email log detail dialog
//
// Shows full headers / response / error for a log entry.
// Prev/Next navigation uses the prev_id / next_id from the EmailLogDetail
// response so you can step through the log in chronological order without
// closing the dialog.
//
// Phase 4a additions:
//   - Resend button: enabled when body_stored=true, disabled with tooltip
//     when body_stored=false. Handles the 409 body_not_stored response.
// ---------------------------------------------------------------------------

export interface EmailLogDetailDialogProps {
  siteId: string;
  logId: string | null;
  onClose: () => void;
  onNavigate: (id: string) => void;
}

export function EmailLogDetailDialog({
  siteId,
  logId,
  onClose,
  onNavigate,
}: EmailLogDetailDialogProps) {
  const detail = useEmailLogDetail(siteId, logId);
  const titleId = useId();

  return (
    <TooltipProvider>
      <Dialog open={logId !== null} onClose={onClose}>
        <DialogContent
          ariaLabelledBy={titleId}
          className="max-w-[min(640px,calc(100vw-2rem))]"
        >
          <DialogHeader>
            <div className="flex items-center justify-between gap-2">
              <DialogTitle id={titleId}>Email detail</DialogTitle>
              <button
                type="button"
                aria-label="Close detail"
                onClick={onClose}
                className="rounded text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
              >
                <X aria-hidden="true" className="size-4" />
              </button>
            </div>
          </DialogHeader>

          <DialogBody>
            {detail.isPending ? (
              <div className="space-y-3">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-4 w-2/3" />
              </div>
            ) : detail.isError ? (
              <PageError
                what="Could not load email detail."
                why={detail.error?.message}
                onRetry={() => void detail.refetch()}
              />
            ) : detail.data ? (
              <LogDetailBody
                siteId={siteId}
                entry={detail.data}
                prevId={detail.data.prev_id ?? null}
                nextId={detail.data.next_id ?? null}
                onNavigate={onNavigate}
              />
            ) : null}
          </DialogBody>
        </DialogContent>
      </Dialog>
    </TooltipProvider>
  );
}

// ---------------------------------------------------------------------------
// Detail body
// ---------------------------------------------------------------------------

interface LogDetailBodyProps {
  siteId: string;
  entry: { entry: SiteEmailLogEntry; prev_id?: string; next_id?: string };
  prevId: string | null;
  nextId: string | null;
  onNavigate: (id: string) => void;
}

function LogDetailBody({ siteId, entry: detail, prevId, nextId, onNavigate }: LogDetailBodyProps) {
  const e = detail.entry;
  const responseText = Object.keys(e.response ?? {}).length
    ? JSON.stringify(e.response, null, 2)
    : null;

  const resend = useResendEmail(siteId);
  const canResend = e.body_stored;

  return (
    <div className="space-y-4">
      {/* Header row */}
      <div className="flex flex-wrap items-start gap-2">
        <EmailStatusBadge status={e.status} />
        <span className="text-xs text-[var(--color-muted-foreground)]">
          {relativeTime(e.created_at)}
        </span>
        {e.message_id ? (
          <span className="font-mono text-xs text-[var(--color-muted-foreground)]">
            {e.message_id}
          </span>
        ) : null}
        {/* Resend button */}
        <div className="ml-auto">
          <Tooltip
            content="Email body was not stored — resend unavailable"
            disabled={canResend}
          >
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!canResend || resend.isPending}
              onClick={() => {
                if (!canResend) return;
                resend.mutate(e.id);
              }}
              className="gap-1.5"
              aria-label={
                canResend
                  ? "Resend this email"
                  : "Resend unavailable — body not stored"
              }
            >
              {resend.isPending ? (
                <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
              ) : (
                <RotateCcw aria-hidden="true" className="size-3.5" />
              )}
              Resend
            </Button>
          </Tooltip>
        </div>
      </div>

      {/* Metadata */}
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
        <dt className="font-medium text-[var(--color-muted-foreground)]">
          To
        </dt>
        <dd className="break-all">{e.to_addresses.join(", ")}</dd>

        <dt className="font-medium text-[var(--color-muted-foreground)]">
          From
        </dt>
        <dd className="break-all">{e.from_address}</dd>

        <dt className="font-medium text-[var(--color-muted-foreground)]">
          Subject
        </dt>
        <dd className="break-all">{e.subject}</dd>

        <dt className="font-medium text-[var(--color-muted-foreground)]">
          Provider
        </dt>
        <dd>
          <Badge variant="outline">{e.provider}</Badge>
        </dd>

        {e.retries > 0 ? (
          <>
            <dt className="font-medium text-[var(--color-muted-foreground)]">
              Retries
            </dt>
            <dd>{e.retries}</dd>
          </>
        ) : null}

        <dt className="font-medium text-[var(--color-muted-foreground)]">
          Body stored
        </dt>
        <dd>
          {e.body_stored ? (
            <Badge variant="success">Yes</Badge>
          ) : (
            <Badge variant="muted">No</Badge>
          )}
        </dd>
      </dl>

      {/* Error */}
      {e.error ? (
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)]">
            Error
          </p>
          <pre className="overflow-x-auto rounded-md bg-[var(--color-muted)] px-3 py-2 text-xs text-[var(--color-destructive)]">
            {e.error}
          </pre>
        </div>
      ) : null}

      {/* Provider response */}
      {responseText ? (
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)]">
            Provider response
          </p>
          <pre className="max-h-48 overflow-auto rounded-md bg-[var(--color-muted)] px-3 py-2 text-xs text-[var(--color-foreground)]">
            {responseText}
          </pre>
        </div>
      ) : null}

      {/* Body (only present when body_stored and the server returned it) */}
      {e.body_stored && e.body ? (
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)]">
            Email body
          </p>
          <pre className="max-h-64 overflow-auto rounded-md bg-[var(--color-muted)] px-3 py-2 text-xs text-[var(--color-foreground)]">
            {e.body}
          </pre>
        </div>
      ) : null}

      {/* Prev / Next navigation */}
      <div className="flex items-center justify-between border-t border-[var(--color-border)] pt-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={!prevId}
          onClick={() => prevId && onNavigate(prevId)}
          className="gap-1"
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
          Older
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={!nextId}
          onClick={() => nextId && onNavigate(nextId)}
          className="gap-1"
        >
          Newer
          <ChevronRight aria-hidden="true" className="size-4" />
        </Button>
      </div>
    </div>
  );
}

// Re-export for test access
export { BodyNotStoredError };
