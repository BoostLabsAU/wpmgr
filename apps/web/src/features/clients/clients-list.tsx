// Clients list — renders the tenant's agency client roster with add/edit/delete.
//
// Column layout: name (with color dot) | company | contact | site_count | actions
// Mirrors destinations-list.tsx table pattern.

import { useState } from "react";
import { Plus, Pencil, Trash2, Users } from "lucide-react";
import type { AgencyClient } from "@wpmgr/api";

import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { DestructiveConfirm } from "@/components/dialogs/destructive-confirm";
import { PageError } from "@/components/feedback";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { toast } from "@/components/toast";
import { cn } from "@/lib/utils";

import { useClients, useDeleteClient } from "./use-clients";
import { ClientForm } from "./client-form";

// ---------------------------------------------------------------------------
// Color dot — tiny colored circle rendered before the client name.
// ---------------------------------------------------------------------------

function ColorDot({ color }: { color?: string }) {
  const style = color ? { backgroundColor: color } : undefined;
  return (
    <span
      aria-hidden="true"
      className={cn(
        "inline-block size-2.5 shrink-0 rounded-full border border-[var(--color-border)]",
        !color && "bg-[var(--color-muted)]",
      )}
      style={style}
      title={color ?? "No color set"}
    />
  );
}

// ---------------------------------------------------------------------------
// Client dialog wrapper
// ---------------------------------------------------------------------------

function ClientDialog({
  open,
  onClose,
  initial,
}: {
  open: boolean;
  onClose: () => void;
  initial?: AgencyClient | null;
}) {
  const editing = !!initial;
  const titleId = editing ? "edit-client-title" : "add-client-title";
  const descId = editing ? "edit-client-desc" : "add-client-desc";

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent ariaLabelledBy={titleId} ariaDescribedBy={descId}>
        <DialogHeader>
          <DialogTitle id={titleId}>{editing ? "Edit client" : "Add client"}</DialogTitle>
          <DialogDescription id={descId}>
            {editing
              ? "Update the details for this client. Changes apply immediately."
              : "Add a new agency client to group and filter your sites."}
          </DialogDescription>
        </DialogHeader>
        <div className="mt-4">
          <ClientForm
            initial={initial}
            onSaved={() => onClose()}
            onCancel={() => onClose()}
          />
        </div>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// ClientsList — public surface
// ---------------------------------------------------------------------------

export function ClientsList() {
  const { data, isPending, isError, error, refetch, isRefetching } = useClients();
  const del = useDeleteClient();

  const [addOpen, setAddOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<AgencyClient | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<AgencyClient | null>(null);

  async function performDelete() {
    if (!deleteTarget) return;
    try {
      await del.mutateAsync(deleteTarget.id);
      toast.success(`Client "${deleteTarget.name}" deleted`, {
        description: "Assigned sites have been unassigned.",
      });
      setDeleteTarget(null);
    } catch {
      // Error surfaces inside the dialog via del.error.
    }
  }

  return (
    <section aria-labelledby="clients-list-heading" className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <h2 id="clients-list-heading" className="text-base font-semibold">
          Clients
        </h2>
        <Button type="button" size="sm" onClick={() => setAddOpen(true)}>
          <Plus aria-hidden="true" className="size-4" />
          Add client
        </Button>
      </div>

      {isPending ? (
        <p role="status" className="text-sm text-[var(--color-muted-foreground)]">
          Loading clients…
        </p>
      ) : isError ? (
        <PageError
          what="Could not load clients."
          why={error.message}
          onRetry={() => void refetch()}
          retryLabel="Reload clients"
          isRetrying={isRefetching}
        />
      ) : data.length === 0 ? (
        <EmptyState onAdd={() => setAddOpen(true)} />
      ) : (
        <div className="rounded-xl border border-[var(--color-border)]">
          <Table>
            <caption className="sr-only">Agency clients</caption>
            <TableHeader>
              <TableRow>
                <TableHead>Client</TableHead>
                <TableHead>Company</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead className="text-right">Sites</TableHead>
                <TableHead className="sr-only">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((client) => (
                <TableRow key={client.id}>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <ColorDot color={client.color} />
                      <span className="font-medium">{client.name}</span>
                    </div>
                  </TableCell>
                  <TableCell className="text-sm text-[var(--color-muted-foreground)]">
                    {client.company ?? (
                      <span aria-hidden="true" className="text-[var(--color-muted-foreground)]/50">
                        —
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="text-sm">
                    {client.contact_email ? (
                      <a
                        href={`mailto:${client.contact_email}`}
                        className="text-[var(--color-foreground)] underline-offset-4 hover:underline"
                      >
                        {client.contact_email}
                      </a>
                    ) : (
                      <span aria-hidden="true" className="text-[var(--color-muted-foreground)]/50">
                        —
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    <Badge variant="secondary" className="tabular-nums">
                      {client.site_count}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditTarget(client)}
                        aria-label={`Edit ${client.name}`}
                      >
                        <Pencil aria-hidden="true" className="size-4" />
                        Edit
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setDeleteTarget(client)}
                        aria-label={`Delete ${client.name}`}
                      >
                        <Trash2 aria-hidden="true" className="size-4" />
                        Delete
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {/* Add client dialog */}
      <ClientDialog
        open={addOpen}
        onClose={() => setAddOpen(false)}
      />

      {/* Edit client dialog */}
      <ClientDialog
        open={editTarget !== null}
        onClose={() => setEditTarget(null)}
        initial={editTarget}
      />

      {/* Delete client confirm */}
      <DestructiveConfirm
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        onConfirm={performDelete}
        title={`Delete client "${deleteTarget?.name ?? ""}"`}
        resourceName={deleteTarget?.name ?? ""}
        confirmLabel="Delete client"
        cancelLabel="Keep client"
        isPending={del.isPending}
        errorMessage={del.isError ? del.error.message : null}
        consequencesBody={
          <div className="space-y-2">
            <p>
              This deletes the client record permanently. Sites currently
              assigned to this client will be unassigned but are not deleted.
            </p>
            {deleteTarget && deleteTarget.site_count > 0 ? (
              <p>
                <strong>{deleteTarget.site_count}</strong>{" "}
                {deleteTarget.site_count === 1 ? "site" : "sites"} will be
                unassigned.
              </p>
            ) : null}
            <p>
              Type <strong>{deleteTarget?.name ?? ""}</strong> to confirm.
            </p>
          </div>
        }
      />
    </section>
  );
}

function EmptyState({ onAdd }: { onAdd: () => void }) {
  return (
    <div
      role="status"
      aria-label="No clients yet"
      className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-[var(--color-border)] py-12 text-center"
    >
      <Users
        aria-hidden="true"
        strokeWidth={1.5}
        className="size-8 text-[var(--color-muted-foreground)]/50"
      />
      <div className="space-y-1">
        <p className="text-balance text-sm font-medium text-[var(--color-foreground)]">
          No clients yet.
        </p>
        <p className="text-balance text-sm text-[var(--color-muted-foreground)]">
          Add a client to group your sites and filter by customer.
        </p>
      </div>
      <Button type="button" size="sm" onClick={onAdd}>
        <Plus aria-hidden="true" className="size-4" />
        Add your first client
      </Button>
    </div>
  );
}
