// Set-client dialog — bulk action that assigns (or unassigns) a client to a
// batch of selected sites. Presents a single-select client picker with a
// "No client (unassign)" option at the top.

import { useState, useId } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogBody,
  DialogFooter,
} from "@/components/ui/dialog";
import { toast } from "@/components/toast";
import { cn } from "@/lib/utils";

import { useClients, useAssignSites } from "./use-clients";

// ---------------------------------------------------------------------------
// ColorDot — small swatch, duplicated from clients-list to avoid a cross-
// component import cycle.
// ---------------------------------------------------------------------------

function ColorDot({ color }: { color?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "inline-block size-2.5 shrink-0 rounded-full border border-[var(--color-border)]",
        !color && "bg-[var(--color-muted)]",
      )}
      style={color ? { backgroundColor: color } : undefined}
    />
  );
}

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

export interface SetClientDialogProps {
  open: boolean;
  onClose: () => void;
  siteIds: string[];
  /** Called after a successful assignment so the parent can clear selection. */
  onSuccess?: () => void;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export function SetClientDialog({
  open,
  onClose,
  siteIds,
  onSuccess,
}: SetClientDialogProps) {
  const uid = useId();
  const titleId = `${uid}-title`;
  const descId = `${uid}-desc`;

  // "null" means "no client / unassign"; undefined means "nothing chosen yet".
  const [selected, setSelected] = useState<string | null | undefined>(undefined);

  const { data: clients, isPending: clientsPending } = useClients();
  const assign = useAssignSites();

  const count = siteIds.length;
  const sitesNoun = count === 1 ? "site" : "sites";

  function handleClose() {
    setSelected(undefined);
    onClose();
  }

  async function handleApply() {
    if (selected === undefined) return;
    try {
      const result = await assign.mutateAsync({
        client_id: selected ?? undefined,
        site_ids: siteIds,
      });
      const updated = result.updated ?? count;
      if (selected === null) {
        toast.success(`Unassigned client from ${updated} ${sitesNoun}`);
      } else {
        const clientName =
          clients?.find((c) => c.id === selected)?.name ?? "client";
        toast.success(`Assigned ${updated} ${sitesNoun} to "${clientName}"`);
      }
      onSuccess?.();
      handleClose();
    } catch (err) {
      toast.error("Could not assign client", {
        description: err instanceof Error ? err.message : "An unexpected error occurred.",
      });
    }
  }

  return (
    <Dialog open={open} onClose={handleClose}>
      <DialogContent ariaLabelledBy={titleId} ariaDescribedBy={descId}>
        <DialogHeader>
          <DialogTitle id={titleId}>
            Set client for {count} {sitesNoun}
          </DialogTitle>
          <DialogDescription id={descId}>
            Choose a client to assign, or select "No client" to unassign.
          </DialogDescription>
        </DialogHeader>

        <DialogBody className="mt-4">
          {clientsPending ? (
            <p role="status" className="text-sm text-[var(--color-muted-foreground)]">
              Loading clients…
            </p>
          ) : (
            <div
              role="listbox"
              aria-label="Client options"
              aria-activedescendant={
                selected !== undefined
                  ? selected === null
                    ? `${uid}-opt-none`
                    : `${uid}-opt-${selected}`
                  : undefined
              }
              className="space-y-1"
            >
              {/* No client (unassign) option */}
              <ClientOption
                id={`${uid}-opt-none`}
                label="No client"
                description="Remove client assignment from selected sites"
                selected={selected === null}
                onSelect={() => setSelected(null)}
              />

              {(clients ?? []).map((client) => (
                <ClientOption
                  key={client.id}
                  id={`${uid}-opt-${client.id}`}
                  label={client.name}
                  description={[client.company, client.contact_email]
                    .filter(Boolean)
                    .join(" · ") || undefined}
                  color={client.color}
                  selected={selected === client.id}
                  onSelect={() => setSelected(client.id)}
                />
              ))}

              {(clients ?? []).length === 0 ? (
                <p className="py-3 text-center text-sm text-[var(--color-muted-foreground)]">
                  No clients yet. Add one from the Clients page first.
                </p>
              ) : null}
            </div>
          )}
        </DialogBody>

        <DialogFooter className="mt-6">
          <Button type="button" variant="ghost" onClick={handleClose}>
            Cancel
          </Button>
          <Button
            type="button"
            disabled={selected === undefined || assign.isPending}
            onClick={() => void handleApply()}
          >
            {assign.isPending ? "Applying…" : "Apply"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// ClientOption — single option row in the picker listbox.
// ---------------------------------------------------------------------------

function ClientOption({
  id,
  label,
  description,
  color,
  selected,
  onSelect,
}: {
  id: string;
  label: string;
  description?: string;
  color?: string;
  selected: boolean;
  onSelect: () => void;
}) {
  return (
    <button
      id={id}
      type="button"
      role="option"
      aria-selected={selected}
      onClick={onSelect}
      className={cn(
        "flex w-full items-start gap-3 rounded-md border px-3 py-2.5 text-left text-sm transition-colors",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        selected
          ? "border-[var(--color-primary)] bg-[var(--color-primary)]/5 text-[var(--color-foreground)]"
          : "border-[var(--color-border)] hover:bg-[var(--color-muted)]/50 text-[var(--color-foreground)]",
      )}
    >
      <ColorDot color={color} />
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="font-medium leading-tight">{label}</span>
        {description ? (
          <span className="truncate text-xs text-[var(--color-muted-foreground)]">
            {description}
          </span>
        ) : null}
      </span>
      {selected ? (
        <svg
          aria-hidden="true"
          className="size-4 shrink-0 text-[var(--color-primary)]"
          fill="currentColor"
          viewBox="0 0 20 20"
        >
          <path
            fillRule="evenodd"
            d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
            clipRule="evenodd"
          />
        </svg>
      ) : null}
    </button>
  );
}
