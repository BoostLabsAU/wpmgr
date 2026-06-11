// Portal access tab — /clients/$clientId/members
//
// Agency-side management of who has read-only portal access to this client:
//   - Members list (email, name, added date, Revoke)
//   - Pending invitations list (status, Revoke, Regenerate)
//   - Invite form (email only; role is implicitly "client" and not selectable)
//   - Copy-link affordance on every invite result (accept_link is always present)

import { useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Copy, Loader2, MailPlus, Trash2, RefreshCw, Users } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { DestructiveConfirm } from "@/components/dialogs/destructive-confirm";
import { toast } from "@/components/toast";
import { relativeTime } from "@/lib/utils";
import {
  useClientMembers,
  useClientInvitations,
  useAddClientMember,
  useRemoveClientMember,
  useRevokeClientInvitation,
  useRegenerateClientInvitation,
  type ClientMember,
  type ClientInvitation,
  type ClientMemberInviteResult,
} from "@/features/clients/use-client-members";

export const Route = createFileRoute("/_authed/clients/$clientId/members")({
  component: ClientMembersPage,
});

// ---------------------------------------------------------------------------
// Status badge for invitations
// ---------------------------------------------------------------------------

function InvitationStatusBadge({
  status,
}: {
  status: ClientInvitation["status"];
}) {
  const variant: Record<
    ClientInvitation["status"],
    "default" | "secondary" | "destructive" | "outline"
  > = {
    pending: "secondary",
    accepted: "default",
    expired: "destructive",
    revoked: "outline",
  };
  return (
    <Badge variant={variant[status] ?? "outline"} className="text-xs capitalize">
      {status}
    </Badge>
  );
}

// ---------------------------------------------------------------------------
// Accept link copy helper
// ---------------------------------------------------------------------------

function CopyLink({ link }: { link: string }) {
  const [copied, setCopied] = useState(false);

  function handleCopy() {
    void navigator.clipboard.writeText(link).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }

  return (
    <div className="flex flex-col gap-1">
      <p className="text-xs text-[var(--color-muted-foreground)]">
        Share this link directly if email is not configured.
      </p>
      <div className="flex items-center gap-2">
        <code className="min-w-0 flex-1 truncate rounded-md border border-[var(--color-border)] bg-[var(--color-muted)] px-2 py-1 font-mono text-xs text-[var(--color-foreground)]">
          {link}
        </code>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={handleCopy}
          aria-label="Copy invitation link"
        >
          <Copy aria-hidden="true" className="mr-1 size-3.5" />
          {copied ? "Copied" : "Copy"}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Invite form + result display
// ---------------------------------------------------------------------------

function InviteForm({ clientId }: { clientId: string }) {
  const [email, setEmail] = useState("");
  const [result, setResult] = useState<ClientMemberInviteResult | null>(null);
  const addMutation = useAddClientMember(clientId);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const trimmed = email.trim();
    if (!trimmed) return;
    try {
      const res = await addMutation.mutateAsync({ email: trimmed });
      setResult(res);
      setEmail("");
    } catch {
      // Error shown via mutation state below.
    }
  }

  return (
    <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4">
      <h3 className="mb-3 text-sm font-semibold text-[var(--color-foreground)]">
        Invite portal user
      </h3>
      <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="invite-email">Email address</Label>
          <div className="flex gap-2">
            <Input
              id="invite-email"
              type="email"
              autoComplete="off"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                setResult(null);
              }}
              placeholder="client@example.com"
              disabled={addMutation.isPending}
              className="flex-1"
            />
            <Button
              type="submit"
              disabled={addMutation.isPending || !email.trim()}
            >
              {addMutation.isPending ? (
                <Loader2 aria-hidden="true" className="animate-spin" />
              ) : (
                <MailPlus aria-hidden="true" />
              )}
              <span className="ml-1.5">
                {addMutation.isPending ? "Inviting..." : "Invite"}
              </span>
            </Button>
          </div>
        </div>

        {addMutation.isError ? (
          <p
            role="alert"
            className="text-sm text-[var(--color-destructive)]"
          >
            {addMutation.error.message}
          </p>
        ) : null}
      </form>

      {result ? (
        <div className="mt-3 space-y-2 rounded-md border border-[var(--color-border)] bg-[var(--color-background)] p-3">
          {result.invited ? (
            <p className="text-sm text-[var(--color-foreground)]">
              Invitation sent to{" "}
              <span className="font-medium">{result.email}</span>.
            </p>
          ) : (
            <p className="text-sm text-[var(--color-foreground)]">
              Portal access granted to{" "}
              <span className="font-medium">{result.email}</span>.
            </p>
          )}
          {result.accept_link ? (
            <CopyLink link={result.accept_link} />
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Members list
// ---------------------------------------------------------------------------

function MemberRow({
  member,
  onRevoke,
}: {
  member: ClientMember;
  onRevoke: (userId: string, email: string) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 py-2.5">
      <div className="min-w-0 flex-1">
        {member.name ? (
          <p className="truncate text-sm font-medium text-[var(--color-foreground)]">
            {member.name}
          </p>
        ) : null}
        <p className="truncate text-sm text-[var(--color-muted-foreground)]">
          {member.email}
        </p>
        <p className="text-xs text-[var(--color-muted-foreground)]">
          Added {relativeTime(member.created_at)}
        </p>
      </div>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="shrink-0 text-[var(--color-muted-foreground)] hover:text-[var(--color-destructive)]"
        onClick={() => onRevoke(member.user_id, member.email)}
        aria-label={`Revoke portal access for ${member.email}`}
      >
        <Trash2 aria-hidden="true" className="size-4" />
      </Button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Invitations list
// ---------------------------------------------------------------------------

function InvitationRow({
  invitation,
  onRevoke,
  onRegenerate,
}: {
  invitation: ClientInvitation;
  onRevoke: (id: string) => void;
  onRegenerate: (id: string) => void;
}) {
  const [regen, setRegen] = useState<ClientMemberInviteResult | null>(null);

  return (
    <div className="space-y-2 py-2.5">
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="truncate text-sm font-medium text-[var(--color-foreground)]">
              {invitation.email}
            </p>
            <InvitationStatusBadge status={invitation.status} />
          </div>
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Invited {relativeTime(invitation.created_at)} &middot; Expires{" "}
            {new Date(invitation.expires_at).toLocaleDateString(undefined, {
              month: "short",
              day: "numeric",
            })}
          </p>
        </div>
        {invitation.status === "pending" || invitation.status === "expired" ? (
          <div className="flex shrink-0 items-center gap-1">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]"
              onClick={() => {
                setRegen(null);
                onRegenerate(invitation.id);
              }}
              aria-label={`Regenerate invitation for ${invitation.email}`}
            >
              <RefreshCw aria-hidden="true" className="size-4" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-[var(--color-muted-foreground)] hover:text-[var(--color-destructive)]"
              onClick={() => onRevoke(invitation.id)}
              aria-label={`Revoke invitation for ${invitation.email}`}
            >
              <Trash2 aria-hidden="true" className="size-4" />
            </Button>
          </div>
        ) : null}
      </div>
      {regen?.accept_link ? <CopyLink link={regen.accept_link} /> : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

function ClientMembersPage() {
  const { clientId } = Route.useParams();

  const membersQuery = useClientMembers(clientId);
  const invitationsQuery = useClientInvitations(clientId);
  const removeMutation = useRemoveClientMember(clientId);
  const revokeMutation = useRevokeClientInvitation(clientId);
  const regenerateMutation = useRegenerateClientInvitation(clientId);

  const [confirmRevoke, setConfirmRevoke] = useState<{
    userId: string;
    email: string;
  } | null>(null);

  function handleRevokeMember(userId: string, email: string) {
    setConfirmRevoke({ userId, email });
  }

  async function confirmRevokeMember() {
    if (!confirmRevoke) return;
    await removeMutation.mutateAsync(confirmRevoke.userId);
    setConfirmRevoke(null);
  }

  function handleRevokeInvitation(invitationId: string) {
    void revokeMutation.mutateAsync(invitationId);
  }

  function handleRegenerate(invitationId: string) {
    void regenerateMutation.mutateAsync(invitationId, {
      onSuccess: (result) => {
        if (result.accept_link) {
          toast.success("New link ready — copy it below.");
        }
      },
    });
  }

  return (
    <div className="space-y-6">
      {/* Invite form */}
      <InviteForm clientId={clientId} />

      {/* Members list */}
      <section aria-labelledby="members-heading">
        <h3
          id="members-heading"
          className="mb-3 text-sm font-semibold text-[var(--color-foreground)]"
        >
          Active members
        </h3>

        {membersQuery.isPending ? (
          <div className="space-y-2">
            {[0, 1].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : membersQuery.isError ? (
          <PageError
            what="Could not load members."
            why={membersQuery.error.message}
            onRetry={() => void membersQuery.refetch()}
            isRetrying={membersQuery.isFetching}
          />
        ) : membersQuery.data && membersQuery.data.length > 0 ? (
          <div className="divide-y divide-[var(--color-border)] rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-4">
            {membersQuery.data.map((m) => (
              <MemberRow
                key={m.user_id}
                member={m}
                onRevoke={handleRevokeMember}
              />
            ))}
          </div>
        ) : (
          <div className="flex items-center gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-4 py-4">
            <Users
              aria-hidden="true"
              className="size-4 text-[var(--color-muted-foreground)]"
            />
            <p className="text-sm text-[var(--color-muted-foreground)]">
              No portal users yet.
            </p>
          </div>
        )}
      </section>

      {/* Pending invitations */}
      <section aria-labelledby="invitations-heading">
        <h3
          id="invitations-heading"
          className="mb-3 text-sm font-semibold text-[var(--color-foreground)]"
        >
          Pending invitations
        </h3>

        {invitationsQuery.isPending ? (
          <div className="space-y-2">
            {[0, 1].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : invitationsQuery.isError ? (
          <PageError
            what="Could not load invitations."
            why={invitationsQuery.error.message}
            onRetry={() => void invitationsQuery.refetch()}
            isRetrying={invitationsQuery.isFetching}
          />
        ) : invitationsQuery.data && invitationsQuery.data.length > 0 ? (
          <div className="divide-y divide-[var(--color-border)] rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-4">
            {invitationsQuery.data.map((inv) => (
              <InvitationRow
                key={inv.id}
                invitation={inv}
                onRevoke={handleRevokeInvitation}
                onRegenerate={handleRegenerate}
              />
            ))}
          </div>
        ) : (
          <p className="text-sm text-[var(--color-muted-foreground)]">
            No pending invitations.
          </p>
        )}
      </section>

      {/* Confirm revoke dialog */}
      <DestructiveConfirm
        open={!!confirmRevoke}
        onClose={() => setConfirmRevoke(null)}
        onConfirm={() => void confirmRevokeMember()}
        title="Revoke portal access"
        consequencesBody={
          <p className="text-sm text-[var(--color-muted-foreground)]">
            This person will immediately lose access to the portal. Their
            account is not deleted. You can re-invite them at any time.
          </p>
        }
        resourceName={confirmRevoke?.email ?? ""}
        confirmLabel="Revoke access"
        cancelLabel="Keep access"
        isPending={removeMutation.isPending}
        errorMessage={
          removeMutation.isError ? removeMutation.error.message : null
        }
      />
    </div>
  );
}
