import { useState, useId } from "react";
import { Search, Plus, Trash2, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Label } from "@/components/ui/label";
import { PageError } from "@/components/feedback";
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogAction,
  AlertDialogCancel,
} from "@/components/ui/alert-dialog";
import { TooltipProvider } from "@/components/ui/tooltip";
import { relativeTime } from "@/lib/utils";
import type { EmailSuppressionEntry } from "@wpmgr/api";
import {
  useSiteEmailSuppression,
  useAddSiteEmailSuppression,
  useDeleteSiteEmailSuppression,
  useFleetEmailSuppression,
  useAddFleetEmailSuppression,
  useDeleteFleetEmailSuppression,
} from "./use-email";

// ---------------------------------------------------------------------------
// Reason badge variants
// ---------------------------------------------------------------------------

const REASON_VARIANT: Record<
  string,
  "destructive" | "muted" | "outline" | "secondary"
> = {
  hard_bounce: "destructive",
  complaint: "destructive",
  unsubscribe: "muted",
  manual: "secondary",
};

const REASON_LABEL: Record<string, string> = {
  hard_bounce: "Hard bounce",
  complaint: "Complaint",
  unsubscribe: "Unsubscribe",
  manual: "Manual",
};

// ---------------------------------------------------------------------------
// Remove confirm dialog
// ---------------------------------------------------------------------------

interface RemoveConfirmProps {
  entry: EmailSuppressionEntry | null;
  isPending: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

function RemoveConfirm({
  entry,
  isPending,
  onConfirm,
  onCancel,
}: RemoveConfirmProps) {
  return (
    <AlertDialog open={entry !== null} onOpenChange={(open) => !open && onCancel()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Remove suppression</AlertDialogTitle>
          <AlertDialogDescription>
            {entry?.email
              ? `Remove suppression for ${entry.email}? The address will be eligible to receive email again.`
              : "Remove this suppression entry? The address will be eligible to receive email again."}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={onCancel} disabled={isPending} />
          <AlertDialogAction
            variant="destructive"
            disabled={isPending}
            onClick={onConfirm}
          >
            {isPending ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : null}
            Remove
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

// ---------------------------------------------------------------------------
// Add form
// ---------------------------------------------------------------------------

interface AddSuppressionFormProps {
  isPending: boolean;
  onSubmit: (email: string, reason: string) => void;
}

function AddSuppressionForm({ isPending, onSubmit }: AddSuppressionFormProps) {
  const [email, setEmail] = useState("");
  const [reason, setReason] = useState("manual");
  const emailId = useId();
  const reasonId = useId();

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!email.trim()) return;
    onSubmit(email.trim(), reason);
    setEmail("");
  }

  return (
    <form
      onSubmit={handleSubmit}
      noValidate
      className="flex flex-wrap items-end gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-3"
      aria-label="Add suppression"
    >
      <div className="flex flex-col gap-1 flex-1 min-w-[200px]">
        <Label htmlFor={emailId} className="text-xs font-medium">
          Email address
        </Label>
        <Input
          id={emailId}
          type="email"
          placeholder="user@example.com"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          aria-required="true"
          className="h-8 text-sm"
        />
      </div>
      <div className="flex flex-col gap-1 w-36">
        <Label htmlFor={reasonId} className="text-xs font-medium">
          Reason
        </Label>
        <Select
          id={reasonId}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          aria-label="Suppression reason"
          className="h-8 text-sm"
        >
          <option value="manual">Manual</option>
          <option value="unsubscribe">Unsubscribe</option>
        </Select>
      </div>
      <Button
        type="submit"
        size="sm"
        disabled={isPending || !email.trim()}
        className="h-8 gap-1.5"
      >
        {isPending ? (
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
        ) : (
          <Plus aria-hidden="true" className="size-3.5" />
        )}
        Add
      </Button>
    </form>
  );
}

// ---------------------------------------------------------------------------
// Suppression table
// ---------------------------------------------------------------------------

interface SuppressionTableProps {
  entries: EmailSuppressionEntry[];
  onRemove: (entry: EmailSuppressionEntry) => void;
  showSiteColumn?: boolean;
}

function SuppressionTable({
  entries,
  onRemove,
  showSiteColumn = false,
}: SuppressionTableProps) {
  if (entries.length === 0) {
    return (
      <div className="rounded-lg border border-[var(--color-border)] px-5 py-8 text-center">
        <p className="text-sm text-[var(--color-muted-foreground)]">
          No suppressed addresses.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-md border border-[var(--color-border)]">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Email</TableHead>
            <TableHead>Reason</TableHead>
            {showSiteColumn ? <TableHead>Site</TableHead> : null}
            <TableHead>Provider</TableHead>
            <TableHead>Date</TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {entries.map((entry) => (
            <SuppressionRow
              key={entry.id}
              entry={entry}
              showSiteColumn={showSiteColumn}
              onRemove={onRemove}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

interface SuppressionRowProps {
  entry: EmailSuppressionEntry;
  showSiteColumn: boolean;
  onRemove: (entry: EmailSuppressionEntry) => void;
}

function SuppressionRow({ entry, showSiteColumn, onRemove }: SuppressionRowProps) {
  const variant = REASON_VARIANT[entry.reason] ?? "outline";
  const label = REASON_LABEL[entry.reason] ?? entry.reason;

  return (
    <TableRow>
      <TableCell className="font-mono text-sm max-w-[220px] truncate">
        {entry.email ?? (
          <span
            className="text-[var(--color-muted-foreground)]"
            title={entry.email_hash}
            aria-label={`Hash: ${entry.email_hash.slice(0, 16)}…`}
          >
            {entry.email_hash.slice(0, 16)}…
          </span>
        )}
      </TableCell>
      <TableCell>
        <Badge variant={variant}>{label}</Badge>
      </TableCell>
      {showSiteColumn ? (
        <TableCell className="max-w-[100px] truncate text-sm text-[var(--color-muted-foreground)]">
          {entry.site_id ?? (
            <span className="italic">fleet-wide</span>
          )}
        </TableCell>
      ) : null}
      <TableCell>
        <Badge variant="outline" className="text-xs">
          {entry.provider}
        </Badge>
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm text-[var(--color-muted-foreground)]">
        {relativeTime(entry.created_at)}
      </TableCell>
      <TableCell>
        <button
          type="button"
          onClick={() => onRemove(entry)}
          className="rounded p-1 text-[var(--color-muted-foreground)] hover:bg-[var(--color-accent)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
          aria-label={`Remove suppression for ${entry.email ?? entry.email_hash.slice(0, 16)}`}
        >
          <Trash2 aria-hidden="true" className="size-3.5" />
        </button>
      </TableCell>
    </TableRow>
  );
}

// ---------------------------------------------------------------------------
// Per-site suppression list (exported)
// ---------------------------------------------------------------------------

export interface EmailSuppressionListProps {
  siteId: string;
}

export function EmailSuppressionList({ siteId }: EmailSuppressionListProps) {
  const [reasonFilter, setReasonFilter] = useState("");
  const [toRemove, setToRemove] = useState<EmailSuppressionEntry | null>(null);

  const { entries, hasMore, isPending, isError, error, refetch, fetchNextPage, isFetchingNextPage } =
    useSiteEmailSuppression(siteId, { reason: reasonFilter || undefined });

  const addMutation = useAddSiteEmailSuppression(siteId);
  const deleteMutation = useDeleteSiteEmailSuppression(siteId);

  function handleAdd(email: string, reason: string) {
    addMutation.mutate({ email, reason });
  }

  function handleConfirmRemove() {
    if (!toRemove) return;
    deleteMutation.mutate(toRemove.id, {
      onSuccess: () => setToRemove(null),
    });
  }

  return (
    <TooltipProvider>
      <div className="space-y-4">
        {/* Add form */}
        <AddSuppressionForm
          isPending={addMutation.isPending}
          onSubmit={handleAdd}
        />

        {/* Filter bar */}
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 min-w-[160px]">
            <Search
              aria-hidden="true"
              className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-[var(--color-muted-foreground)]"
            />
            <Input
              type="search"
              placeholder="Filter by address…"
              readOnly
              value=""
              className="pl-8 cursor-not-allowed opacity-50"
              aria-label="Address search (coming soon)"
              title="Address search is not yet available"
            />
          </div>
          <div className="w-44">
            <Select
              value={reasonFilter}
              onChange={(e) => setReasonFilter(e.target.value)}
              aria-label="Filter by reason"
            >
              <option value="">All reasons</option>
              <option value="hard_bounce">Hard bounce</option>
              <option value="complaint">Complaint</option>
              <option value="unsubscribe">Unsubscribe</option>
              <option value="manual">Manual</option>
            </Select>
          </div>
        </div>

        {/* Table */}
        {isPending ? (
          <SuppressionSkeleton />
        ) : isError ? (
          <PageError
            what="Could not load suppression list."
            why={error?.message}
            onRetry={() => void refetch()}
          />
        ) : (
          <SuppressionTable entries={entries} onRemove={setToRemove} />
        )}

        {/* Load more */}
        {hasMore ? (
          <div className="flex justify-center">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isFetchingNextPage}
              onClick={() => void fetchNextPage()}
              className="gap-2"
            >
              {isFetchingNextPage ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : null}
              {isFetchingNextPage ? "Loading…" : "Load more"}
            </Button>
          </div>
        ) : null}

        {/* Remove confirm */}
        <RemoveConfirm
          entry={toRemove}
          isPending={deleteMutation.isPending}
          onConfirm={handleConfirmRemove}
          onCancel={() => setToRemove(null)}
        />
      </div>
    </TooltipProvider>
  );
}

// ---------------------------------------------------------------------------
// Fleet suppression list (exported)
// ---------------------------------------------------------------------------

export function FleetEmailSuppressionList() {
  const [reasonFilter, setReasonFilter] = useState("");
  const [toRemove, setToRemove] = useState<EmailSuppressionEntry | null>(null);

  const { entries, hasMore, isPending, isError, error, refetch, fetchNextPage, isFetchingNextPage } =
    useFleetEmailSuppression({ reason: reasonFilter || undefined });

  const addMutation = useAddFleetEmailSuppression();
  const deleteMutation = useDeleteFleetEmailSuppression();

  function handleAdd(email: string, reason: string) {
    addMutation.mutate({ email, reason });
  }

  function handleConfirmRemove() {
    if (!toRemove) return;
    deleteMutation.mutate(toRemove.id, {
      onSuccess: () => setToRemove(null),
    });
  }

  return (
    <TooltipProvider>
      <div className="space-y-4">
        {/* Add form */}
        <AddSuppressionForm
          isPending={addMutation.isPending}
          onSubmit={handleAdd}
        />

        {/* Filter bar */}
        <div className="flex flex-wrap items-center gap-2">
          <div className="w-44">
            <Select
              value={reasonFilter}
              onChange={(e) => setReasonFilter(e.target.value)}
              aria-label="Filter by reason"
            >
              <option value="">All reasons</option>
              <option value="hard_bounce">Hard bounce</option>
              <option value="complaint">Complaint</option>
              <option value="unsubscribe">Unsubscribe</option>
              <option value="manual">Manual</option>
            </Select>
          </div>
        </div>

        {/* Table */}
        {isPending ? (
          <SuppressionSkeleton />
        ) : isError ? (
          <PageError
            what="Could not load suppression list."
            why={error?.message}
            onRetry={() => void refetch()}
          />
        ) : (
          <SuppressionTable
            entries={entries}
            onRemove={setToRemove}
            showSiteColumn
          />
        )}

        {/* Load more */}
        {hasMore ? (
          <div className="flex justify-center">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isFetchingNextPage}
              onClick={() => void fetchNextPage()}
              className="gap-2"
            >
              {isFetchingNextPage ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : null}
              {isFetchingNextPage ? "Loading…" : "Load more"}
            </Button>
          </div>
        ) : null}

        {/* Remove confirm */}
        <RemoveConfirm
          entry={toRemove}
          isPending={deleteMutation.isPending}
          onConfirm={handleConfirmRemove}
          onCancel={() => setToRemove(null)}
        />
      </div>
    </TooltipProvider>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function SuppressionSkeleton() {
  return (
    <div className="space-y-2">
      <div className="flex gap-2">
        <Skeleton className="h-9 flex-1" />
        <Skeleton className="h-9 w-40" />
      </div>
      {Array.from({ length: 5 }).map((_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  );
}
