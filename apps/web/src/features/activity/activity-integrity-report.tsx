import { useState } from "react";
import { ShieldAlert, Copy, Check, ChevronDown } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { cn, relativeTime } from "@/lib/utils";
import { useQueryClient } from "@tanstack/react-query";
import type { ChainBreak } from "@wpmgr/api";

import { activityKeys } from "./use-activity";

// Activity log integrity report dialog (ADR-037 Sprint 6).
//
// Opens when an operator clicks the "Chain break at seq N" badge in the
// activity toolbar. Explains the break kind in plain language, shows the
// offending event, and surfaces the raw hash values in a collapsible technical
// detail block. Tone is factual: a break is most often pruned entries, not
// tampering. No em dashes, no alarm language.

export interface ActivityIntegrityReportProps {
  open: boolean;
  onClose: () => void;
  siteId: string;
  breakData: ChainBreak;
  total: number;
}

export function ActivityIntegrityReport({
  open,
  onClose,
  siteId,
  breakData,
  total,
}: ActivityIntegrityReportProps) {
  const [detailOpen, setDetailOpen] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const copy = (label: string, value: string) => {
    void navigator.clipboard.writeText(value).then(() => {
      setCopied(label);
      window.setTimeout(() => setCopied(null), 1500);
    });
  };

  const recheck = () => {
    void queryClient.invalidateQueries({
      queryKey: activityKeys.verify(siteId),
    });
  };

  const actor =
    breakData.event.actor_login.length > 0
      ? breakData.event.actor_login
      : "system";
  const rel = relativeTime(breakData.event.occurred_at) ?? "unknown time";

  const prevHashDiffers =
    breakData.expected_prev_hash !== breakData.stored_prev_hash;
  const thisHashDiffers =
    breakData.recomputed_this_hash !== breakData.stored_this_hash;

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent
        ariaLabelledBy="integrity-report-title"
        className="max-w-[600px]"
      >
        <DialogHeader>
          <DialogTitle id="integrity-report-title">
            Activity log integrity
          </DialogTitle>
        </DialogHeader>

        <DialogBody className="space-y-5">
          {/* Headline status */}
          <div className="space-y-1">
            <div className="inline-flex items-center gap-1.5 rounded bg-destructive-subtle px-2.5 py-1 text-sm font-medium text-destructive-subtle-fg">
              <ShieldAlert aria-hidden="true" className="size-4 shrink-0" />
              Verification failed at event #{breakData.seq}
            </div>
            <p className="text-xs text-muted-foreground tabular-nums">
              {total} event{total !== 1 ? "s" : ""} checked
            </p>
          </div>

          {/* Plain-language cause */}
          <CauseBlock breakData={breakData} />

          {/* Offending event */}
          <div className="space-y-2">
            <h3 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Offending event
            </h3>
            <div className="rounded-lg border border-border px-4 py-3 space-y-2">
              <p className="text-sm text-foreground">{breakData.event.summary}</p>
              <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-muted-foreground">
                  {breakData.event.event_type}
                </span>
                <span className="text-muted-foreground">
                  Actor: <span className="font-medium text-foreground">{actor}</span>
                </span>
                <span
                  className="text-muted-foreground tabular-nums"
                  title={breakData.event.occurred_at}
                >
                  {rel}
                  <span aria-hidden="true"> &middot; </span>
                  <span className="font-mono">{breakData.event.occurred_at}</span>
                </span>
              </div>
            </div>
          </div>

          {/* Technical detail — collapsible */}
          <details
            open={detailOpen}
            onToggle={(e) => setDetailOpen(e.currentTarget.open)}
            className="group"
          >
            <summary
              className={cn(
                "flex cursor-pointer select-none list-none items-center gap-1.5 text-xs font-medium text-muted-foreground",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 rounded",
                "hover:text-foreground transition-colors",
              )}
            >
              <ChevronDown
                aria-hidden="true"
                className={cn(
                  "size-3.5 transition-transform",
                  detailOpen && "rotate-180",
                )}
              />
              Technical detail
            </summary>

            <div className="mt-3 space-y-3">
              {breakData.seq_gap > 0 ? (
                <TechRow
                  label="Sequence gap"
                  value={String(breakData.seq_gap)}
                  mono
                />
              ) : null}

              {prevHashDiffers ? (
                <>
                  <HashTechRow
                    label="Expected prev hash"
                    value={breakData.expected_prev_hash}
                    copied={copied === "expected_prev"}
                    onCopy={() => copy("expected_prev", breakData.expected_prev_hash)}
                  />
                  <HashTechRow
                    label="Stored prev hash"
                    value={breakData.stored_prev_hash}
                    copied={copied === "stored_prev"}
                    onCopy={() => copy("stored_prev", breakData.stored_prev_hash)}
                  />
                </>
              ) : null}

              {thisHashDiffers || breakData.kind === "content_modified" ? (
                <>
                  <HashTechRow
                    label="Recomputed hash"
                    value={breakData.recomputed_this_hash}
                    copied={copied === "recomputed_this"}
                    onCopy={() =>
                      copy("recomputed_this", breakData.recomputed_this_hash)
                    }
                  />
                  <HashTechRow
                    label="Stored hash"
                    value={breakData.stored_this_hash}
                    copied={copied === "stored_this"}
                    onCopy={() => copy("stored_this", breakData.stored_this_hash)}
                  />
                </>
              ) : null}
            </div>
          </details>
        </DialogBody>

        <DialogFooter className="mt-2">
          <Button type="button" variant="ghost" onClick={recheck}>
            Re-check
          </Button>
          <Button type="button" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// Cause block — plain-language explanation keyed on break kind
// ---------------------------------------------------------------------------

function CauseBlock({ breakData }: { breakData: ChainBreak }) {
  const { kind, seq, prior_seq, seq_gap } = breakData;

  switch (kind) {
    case "missing_events":
      return (
        <CauseSection
          cause={
            <>
              <span className="tabular-nums">{seq_gap}</span> event
              {seq_gap !== 1 ? "s" : ""} between #
              <span className="tabular-nums">{prior_seq ?? "?"}</span> and #
              <span className="tabular-nums">{seq}</span> are missing from this
              site&apos;s log. The most common cause is routine log cleanup or
              retention removing older entries, or a row deleted directly in the
              database. On its own this is not proof of tampering.
            </>
          }
          whatToDo="Confirm whether anyone cleared the log or pruned the activity table. If not, treat the gap as suspicious and review access to the site's database."
        />
      );

    case "link_mismatch":
      return (
        <CauseSection
          cause={
            <>
              Event #<span className="tabular-nums">{seq}</span> does not link
              to event #{" "}
              <span className="tabular-nums">{prior_seq ?? "?"}</span> the way
              it should. An event may have been inserted, removed, or reordered,
              or event #{" "}
              <span className="tabular-nums">{prior_seq ?? "?"}</span> was
              changed after it was recorded.
            </>
          }
          whatToDo={`Review events around #${prior_seq ?? "?"} and #${seq} and check who had database or admin access at that time.`}
        />
      );

    case "content_modified":
      return (
        <CauseSection
          cause={
            <>
              Event #<span className="tabular-nums">{seq}</span>&apos;s stored
              content no longer matches its signature. The entry was changed
              after it was recorded, or, less often, the agent and control plane
              disagree on how to hash it. This is the pattern most consistent
              with tampering.
            </>
          }
          whatToDo={`Review event #${seq} below and the actor and IP on nearby events. If this site was recently updated, a one-off agent or control-plane version mismatch can also cause this.`}
        />
      );

    case "chain_start_missing":
      return (
        <CauseSection
          cause="The start of the log is no longer present, so verification cannot begin from a trusted starting point. This usually means the oldest entries were removed by retention, or the agent reset its log."
          whatToDo="If you did not reset the agent, confirm no one cleared the activity table."
        />
      );

    default:
      return null;
  }
}

function CauseSection({
  cause,
  whatToDo,
}: {
  cause: React.ReactNode;
  whatToDo: string;
}) {
  return (
    <div className="space-y-2">
      <h3 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        What happened
      </h3>
      <p className="text-sm text-foreground leading-relaxed">{cause}</p>
      <div className="space-y-1">
        <p className="text-xs font-medium text-muted-foreground">What to do</p>
        <p className="text-sm text-foreground leading-relaxed">{whatToDo}</p>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Tech detail rows
// ---------------------------------------------------------------------------

function TechRow({
  label,
  value,
  mono,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) {
  return (
    <div className="grid grid-cols-[160px_1fr] gap-3 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span
        className={cn(
          "min-w-0 break-words text-foreground",
          mono && "font-mono tabular-nums",
        )}
      >
        {value}
      </span>
    </div>
  );
}

function truncateHash(hash: string): string {
  if (hash.length <= 24) return hash;
  return `${hash.slice(0, 16)}...${hash.slice(-8)}`;
}

function HashTechRow({
  label,
  value,
  copied,
  onCopy,
}: {
  label: string;
  value: string;
  copied: boolean;
  onCopy: () => void;
}) {
  return (
    <div className="grid grid-cols-[160px_1fr] items-start gap-3 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <div className="flex items-start gap-2">
        <code
          className="min-w-0 flex-1 rounded bg-muted/50 px-2 py-1 font-mono text-xs text-foreground"
          title={value}
        >
          {truncateHash(value)}
        </code>
        <Button
          size="sm"
          variant="ghost"
          type="button"
          onClick={onCopy}
          aria-label={`Copy ${label}`}
        >
          {copied ? (
            <>
              <Check aria-hidden="true" className="size-3.5" />
              Copied
            </>
          ) : (
            <>
              <Copy aria-hidden="true" className="size-3.5" />
              Copy
            </>
          )}
        </Button>
      </div>
    </div>
  );
}
