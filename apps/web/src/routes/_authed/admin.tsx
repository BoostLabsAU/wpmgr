import { useId, useState } from "react";
import { createFileRoute, redirect } from "@tanstack/react-router";
import {
  Ban,
  CheckCircle2,
  MailCheck,
  ShieldCheck,
  Trash2,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageError } from "@/components/feedback";
import { PageHeader } from "@/components/shared/page-header";
import { ensureMe, isSuperadmin } from "@/features/auth/use-auth";
import {
  useAdminStats,
  useAdminUsers,
  useAdminDeleteUser,
  useAdminSetStatus,
  useAdminResendVerification,
  type AdminUser,
} from "@/features/admin/use-admin";

export const Route = createFileRoute("/_authed/admin")({
  beforeLoad: async ({ context }) => {
    const me = await ensureMe(context.queryClient);
    if (!isSuperadmin(me)) {
      throw redirect({ to: "/sites" });
    }
  },
  component: AdminPage,
});

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function AdminPage() {
  const [search, setSearch] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<AdminUser | null>(null);
  const [confirmText, setConfirmText] = useState("");

  const { data: stats } = useAdminStats();
  const {
    data: users,
    isPending,
    isError,
    error,
    refetch,
    isRefetching,
  } = useAdminUsers(search);

  const deleteUser = useAdminDeleteUser();
  const setStatus = useAdminSetStatus();
  const resend = useAdminResendVerification();

  function openDelete(u: AdminUser) {
    setDeleteTarget(u);
    setConfirmText("");
  }

  function closeDelete() {
    setDeleteTarget(null);
    setConfirmText("");
  }

  return (
    <section aria-labelledby="admin-heading" className="max-w-5xl space-y-6">
      <PageHeader
        title="Instance Admin"
        subline="Superadmin view — all users across this WPMgr installation."
      />

      {/* Stats strip */}
      {stats ? (
        <div className="grid grid-cols-3 gap-4">
          {(
            [
              { label: "Users", value: stats.users },
              { label: "Organisations", value: stats.organizations },
              { label: "Sites", value: stats.sites },
            ] as const
          ).map((s) => (
            <Card key={s.label}>
              <CardHeader className="pb-2">
                <CardTitle className="text-xs font-medium uppercase tracking-[0.02em] text-muted-foreground">
                  {s.label}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-semibold tabular-nums">
                  {s.value}
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : null}

      {/* Search */}
      <div className="max-w-sm">
        <Input
          type="search"
          placeholder="Search by email or name..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          aria-label="Search users"
        />
      </div>

      {/* Error state */}
      {isError ? (
        <PageError
          what="Could not load users."
          why={error?.message}
          onRetry={() => void refetch()}
          isRetrying={isRefetching}
        />
      ) : null}

      {/* Table */}
      <div className="rounded-xl border border-[var(--color-border)]">
        <Table>
          <caption className="sr-only">All instance users</caption>
          <TableHeader>
            <TableRow>
              <TableHead>Email</TableHead>
              <TableHead>Name</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Verified</TableHead>
              <TableHead className="text-right">Orgs</TableHead>
              <TableHead>Created</TableHead>
              <TableHead>Last login</TableHead>
              <TableHead className="sr-only">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isPending ? (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-10 text-center text-sm text-muted-foreground"
                >
                  Loading users...
                </TableCell>
              </TableRow>
            ) : !users || users.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-10 text-center text-sm text-muted-foreground"
                >
                  {search ? "No users match your search." : "No users found."}
                </TableCell>
              </TableRow>
            ) : (
              users.map((u) => (
                <UserRow
                  key={u.id}
                  user={u}
                  onDelete={() => openDelete(u)}
                  onToggleStatus={() =>
                    setStatus.mutate({
                      userId: u.id,
                      status: u.status === "disabled" ? "active" : "disabled",
                    })
                  }
                  onResend={() => resend.mutate(u.id)}
                  isToggling={
                    setStatus.isPending &&
                    setStatus.variables?.userId === u.id
                  }
                  isResending={
                    resend.isPending && resend.variables === u.id
                  }
                />
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Delete confirm dialog */}
      <DeleteDialog
        target={deleteTarget}
        confirmText={confirmText}
        onConfirmTextChange={setConfirmText}
        isPending={deleteUser.isPending}
        onCancel={closeDelete}
        onConfirm={() => {
          if (!deleteTarget) return;
          deleteUser.mutate(deleteTarget.id, { onSuccess: closeDelete });
        }}
      />
    </section>
  );
}

// ---------------------------------------------------------------------------
// UserRow
// ---------------------------------------------------------------------------

function UserRow({
  user,
  onDelete,
  onToggleStatus,
  onResend,
  isToggling,
  isResending,
}: {
  user: AdminUser;
  onDelete: () => void;
  onToggleStatus: () => void;
  onResend: () => void;
  isToggling: boolean;
  isResending: boolean;
}) {
  const statusClass =
    user.status === "active"
      ? "text-green-700 dark:text-green-400"
      : user.status === "pending"
        ? "text-yellow-700 dark:text-yellow-400"
        : "text-muted-foreground";

  return (
    <TableRow>
      <TableCell>
        <span className="font-mono text-xs">{user.email}</span>
        {user.is_superadmin ? (
          <span
            className="ml-1.5 inline-flex items-center gap-0.5 rounded bg-amber-100 px-1 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
            title="Superadmin"
          >
            <ShieldCheck aria-hidden="true" className="size-2.5" />
            SA
          </span>
        ) : null}
      </TableCell>
      <TableCell className="text-sm">{user.name || "—"}</TableCell>
      <TableCell>
        <span className={`text-sm font-medium ${statusClass}`}>
          {user.status}
        </span>
      </TableCell>
      <TableCell className="text-sm">
        {user.email_verified ? "Yes" : "No"}
      </TableCell>
      <TableCell className="text-right tabular-nums text-sm">
        {user.org_count}
      </TableCell>
      <TableCell className="text-xs text-muted-foreground">
        {new Date(user.created_at).toLocaleDateString()}
      </TableCell>
      <TableCell className="text-xs text-muted-foreground">
        {user.last_login_at
          ? new Date(user.last_login_at).toLocaleDateString()
          : "—"}
      </TableCell>
      <TableCell>
        <div className="flex items-center justify-end gap-1">
          {user.status === "pending" ? (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              title="Resend verification email"
              aria-label={`Resend verification email for ${user.email}`}
              disabled={isResending}
              onClick={onResend}
            >
              <MailCheck aria-hidden="true" className="size-3.5" />
            </Button>
          ) : null}
          {!user.is_superadmin ? (
            <>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                title={
                  user.status === "disabled"
                    ? `Enable ${user.email}`
                    : `Disable ${user.email}`
                }
                aria-label={
                  user.status === "disabled"
                    ? `Enable ${user.email}`
                    : `Disable ${user.email}`
                }
                disabled={isToggling}
                onClick={onToggleStatus}
              >
                {user.status === "disabled" ? (
                  <CheckCircle2 aria-hidden="true" className="size-3.5" />
                ) : (
                  <Ban aria-hidden="true" className="size-3.5" />
                )}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                title={`Delete ${user.email}`}
                aria-label={`Delete ${user.email}`}
                onClick={onDelete}
              >
                <Trash2
                  aria-hidden="true"
                  className="size-3.5 text-destructive"
                />
              </Button>
            </>
          ) : null}
        </div>
      </TableCell>
    </TableRow>
  );
}

// ---------------------------------------------------------------------------
// DeleteDialog
// ---------------------------------------------------------------------------

function DeleteDialog({
  target,
  confirmText,
  onConfirmTextChange,
  isPending,
  onCancel,
  onConfirm,
}: {
  target: AdminUser | null;
  confirmText: string;
  onConfirmTextChange: (v: string) => void;
  isPending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  const titleId = useId();
  const confirmed = confirmText === "delete";

  return (
    <Dialog open={target !== null} onClose={onCancel}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId}>Delete user?</DialogTitle>
        </DialogHeader>
        <DialogBody className="space-y-4">
          <p className="text-sm">
            This permanently deletes{" "}
            <strong className="font-mono text-xs">{target?.email}</strong> and
            all their data. This cannot be undone.
          </p>
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
              Type <strong>delete</strong> to confirm.
            </p>
            <Input
              value={confirmText}
              onChange={(e) => onConfirmTextChange(e.target.value)}
              placeholder="delete"
              autoComplete="off"
              data-autofocus
              aria-label="Type delete to confirm"
              onKeyDown={(e) => {
                if (e.key === "Enter" && confirmed && !isPending) onConfirm();
              }}
            />
          </div>
        </DialogBody>
        <DialogFooter className="pt-2">
          <Button
            type="button"
            variant="outline"
            disabled={isPending}
            onClick={onCancel}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={!confirmed || isPending}
            onClick={onConfirm}
          >
            {isPending ? "Deleting..." : "Delete user"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
