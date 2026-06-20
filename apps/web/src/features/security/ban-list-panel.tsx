import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { DestructiveConfirm } from "@/components/dialogs/destructive-confirm";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { toast } from "@/components/toast";
import { relativeTime } from "@/lib/utils";

import {
  useBans,
  useCreateBan,
  useDeleteBan,
  validateBanValue,
  type BanType,
  type Ban,
} from "./use-hardening";

// Security Suite Phase 1 — Ban list panel (per-site).
//
// Layout:
//   [Add form row]  — type select + value input + comment input + Add button
//   [Table]         — type badge | value | comment | added | delete
//   [Empty state]   — when no bans exist
//
// Design rules:
//   - Type badge uses a background tint that matches the semantic tone.
//   - Client-side validation blocks invalid IPs / CIDRs before hitting the API.
//   - Delete routes through DestructiveConfirm (irreversible action).
//   - canWrite=false: add form and delete buttons are hidden (not just disabled)
//     so the read-only view is unambiguous.

export function BanListPanel({
  siteId,
  canWrite,
}: {
  siteId: string;
  canWrite: boolean;
}) {
  const { data, isPending, isError, error, refetch } = useBans(siteId);

  if (isPending) {
    return <BanListSkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load ban list."
        why={error instanceof Error ? error.message : "Unknown error"}
        onRetry={() => void refetch()}
        retryLabel="Reload ban list"
      />
    );
  }

  return (
    <div className="space-y-4">
      {canWrite ? (
        <AddBanForm siteId={siteId} />
      ) : null}

      {data && data.length > 0 ? (
        <BanTable bans={data} siteId={siteId} canWrite={canWrite} />
      ) : (
        <BanEmptyState />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Add form
// ---------------------------------------------------------------------------

const BAN_TYPE_OPTIONS: { value: BanType; label: string }[] = [
  { value: "ip", label: "IP address" },
  { value: "range", label: "IP range (CIDR)" },
  { value: "user_agent", label: "User agent" },
];

function AddBanForm({ siteId }: { siteId: string }) {
  const create = useCreateBan(siteId);

  const [type, setType] = useState<BanType>("ip");
  const [value, setValue] = useState("");
  const [comment, setComment] = useState("");
  const [valueError, setValueError] = useState<string | null>(null);

  const placeholders: Record<BanType, string> = {
    ip: "203.0.113.42",
    range: "203.0.113.0/24",
    user_agent: "BadBot/1.0",
  };

  function handleAdd() {
    const trimmedValue = value.trim();
    const validationError = validateBanValue(type, trimmedValue);
    if (validationError) {
      setValueError(validationError);
      return;
    }

    setValueError(null);
    create.mutate(
      { type, value: trimmedValue, comment: comment.trim() },
      {
        onSuccess: (result) => {
          setValue("");
          setComment("");
          const detail = result.detail;
          if (detail) {
            toast.success("Ban added.", { description: detail });
          } else {
            toast.success("Ban added.");
          }
        },
        onError: (err: Error) => {
          toast.error("Could not add ban.", { description: err.message });
        },
      },
    );
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        handleAdd();
      }}
      noValidate
      className="flex flex-col gap-3 rounded-lg border border-[var(--color-border)] p-4 sm:flex-row sm:items-start"
      aria-label="Add a ban"
    >
      {/* Type select */}
      <div className="shrink-0">
        <label htmlFor="ban-type" className="sr-only">
          Ban type
        </label>
        <select
          id="ban-type"
          value={type}
          onChange={(e) => {
            setType(e.target.value as BanType);
            setValue("");
            setValueError(null);
          }}
          disabled={create.isPending}
          className="flex h-9 w-full rounded-md border border-[var(--color-input)] bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] disabled:cursor-not-allowed disabled:opacity-50 sm:w-44"
        >
          {BAN_TYPE_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      </div>

      {/* Value input */}
      <div className="flex-1 space-y-1">
        <label htmlFor="ban-value" className="sr-only">
          {type === "ip"
            ? "IP address to ban"
            : type === "range"
              ? "CIDR range to ban"
              : "User agent to ban"}
        </label>
        <Input
          id="ban-value"
          type="text"
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            setValueError(null);
          }}
          placeholder={placeholders[type]}
          disabled={create.isPending}
          aria-invalid={valueError !== null}
          aria-describedby={valueError ? "ban-value-error" : undefined}
          className="font-mono text-sm"
        />
        {valueError ? (
          <p
            id="ban-value-error"
            role="alert"
            className="text-xs text-[var(--color-destructive)]"
          >
            {valueError}
          </p>
        ) : null}
      </div>

      {/* Comment input */}
      <div className="flex-1">
        <label htmlFor="ban-comment" className="sr-only">
          Comment (optional)
        </label>
        <Input
          id="ban-comment"
          type="text"
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          placeholder="Optional note"
          disabled={create.isPending}
          className="text-sm"
        />
      </div>

      {/* Add button */}
      <Button
        type="submit"
        variant="outline"
        size="sm"
        disabled={create.isPending}
        aria-busy={create.isPending}
        className="shrink-0 self-start"
      >
        <Plus aria-hidden="true" className="size-3.5" />
        {create.isPending ? "Adding..." : "Add ban"}
      </Button>
    </form>
  );
}

// ---------------------------------------------------------------------------
// Table
// ---------------------------------------------------------------------------

function BanTable({
  bans,
  siteId,
  canWrite,
}: {
  bans: Ban[];
  siteId: string;
  canWrite: boolean;
}) {
  const [pendingDelete, setPendingDelete] = useState<Ban | null>(null);
  const deleteBan = useDeleteBan(siteId);

  function confirmDelete() {
    if (!pendingDelete) return;
    deleteBan.mutate(pendingDelete.id, {
      onSuccess: () => {
        setPendingDelete(null);
        toast.success("Ban removed.");
      },
      onError: (err: Error) => {
        toast.error("Could not remove ban.", { description: err.message });
      },
    });
  }

  return (
    <>
      <div className="rounded-lg border border-[var(--color-border)]">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-28">Type</TableHead>
              <TableHead>Value</TableHead>
              <TableHead className="hidden sm:table-cell">Comment</TableHead>
              <TableHead className="hidden md:table-cell w-32">Added</TableHead>
              {canWrite ? (
                <TableHead className="w-12">
                  <span className="sr-only">Actions</span>
                </TableHead>
              ) : null}
            </TableRow>
          </TableHeader>
          <TableBody>
            {bans.map((ban) => (
              <TableRow key={ban.id}>
                <TableCell>
                  <BanTypeBadge type={ban.type} />
                </TableCell>
                <TableCell className="font-mono text-sm">
                  {ban.value}
                </TableCell>
                <TableCell className="hidden text-sm text-[var(--color-muted-foreground)] sm:table-cell">
                  {ban.comment || (
                    <span className="italic text-[var(--color-muted-foreground)]/60">
                      None
                    </span>
                  )}
                </TableCell>
                <TableCell className="hidden text-xs text-[var(--color-muted-foreground)] tabular-nums md:table-cell">
                  {relativeTime(ban.created_at) ?? "–"}
                </TableCell>
                {canWrite ? (
                  <TableCell>
                    <button
                      type="button"
                      aria-label={`Remove ban for ${ban.value}`}
                      onClick={() => setPendingDelete(ban)}
                      className="inline-flex size-7 items-center justify-center rounded text-[var(--color-muted-foreground)] transition-colors hover:bg-[var(--color-destructive)]/10 hover:text-[var(--color-destructive)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
                    >
                      <Trash2 aria-hidden="true" className="size-3.5" />
                    </button>
                  </TableCell>
                ) : null}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {pendingDelete ? (
        <DestructiveConfirm
          open={pendingDelete !== null}
          onClose={() => setPendingDelete(null)}
          onConfirm={confirmDelete}
          title={`Remove ban for ${pendingDelete.value}`}
          resourceName={pendingDelete.value}
          confirmLabel="Remove ban"
          cancelLabel="Keep ban"
          isPending={deleteBan.isPending}
          errorMessage={deleteBan.isError ? deleteBan.error.message : null}
          consequencesBody={
            <p>
              Removing this ban allows traffic from{" "}
              <code className="font-mono text-sm">{pendingDelete.value}</code>{" "}
              to reach the site again immediately.
            </p>
          }
        />
      ) : null}
    </>
  );
}

// ---------------------------------------------------------------------------
// BanTypeBadge
// ---------------------------------------------------------------------------

const BAN_TYPE_LABEL: Record<BanType, string> = {
  ip: "IP",
  range: "CIDR",
  user_agent: "User agent",
};

const BAN_TYPE_CLASS: Record<BanType, string> = {
  ip: "bg-[var(--color-destructive)]/10 text-[var(--color-destructive)]",
  range: "bg-[var(--color-warning)]/15 text-[var(--color-warning)]",
  user_agent:
    "bg-[var(--color-muted)] text-[var(--color-muted-foreground)]",
};

function BanTypeBadge({ type }: { type: BanType }) {
  return (
    <span
      className={[
        "inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium",
        BAN_TYPE_CLASS[type],
      ].join(" ")}
    >
      {BAN_TYPE_LABEL[type]}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Empty state
// ---------------------------------------------------------------------------

function BanEmptyState() {
  return (
    <div
      role="status"
      aria-label="No bans configured"
      className="flex flex-col items-center gap-2 py-10 text-center"
    >
      <p className="text-sm text-[var(--color-muted-foreground)]">
        No bans configured. Use the form above to block specific IPs, CIDR
        ranges, or user agents.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function BanListSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading ban list"
      className="space-y-4"
    >
      <span className="sr-only">Loading ban list</span>
      <Skeleton className="h-14 w-full rounded-lg" />
      <div className="rounded-lg border border-[var(--color-border)]">
        {Array.from({ length: 3 }).map((_, i) => (
          <div
            key={i}
            className="flex items-center gap-4 border-b border-[var(--color-border)] px-4 py-3 last:border-0"
          >
            <Skeleton className="h-5 w-12 rounded-md" />
            <Skeleton className="h-4 w-32 flex-1" />
            <Skeleton className="h-4 w-24 hidden sm:block" />
          </div>
        ))}
      </div>
    </div>
  );
}
