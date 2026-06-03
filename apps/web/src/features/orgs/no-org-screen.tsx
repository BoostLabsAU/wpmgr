import { useId, useState } from "react";
import { useNavigate } from "@tanstack/react-router";
import { Building2, Loader2 } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useCreateOrg } from "@/features/orgs/use-orgs";
import { authKeys } from "@/features/auth/use-auth";

// NoOrgScreen — shown when an authenticated user has zero organisation
// memberships AND no active tenant. This occurs when:
//   - A former site-share collaborator has had all their access revoked.
//   - A brand-new user whose registration flow did not create an org.
//
// Users who are site-scoped collaborators (active_tenant_id set but not in
// memberships) still have access and must NOT see this screen; the guard in
// _authed.tsx enforces that distinction.
//
// On successful org creation the mutation invalidates auth.me (so memberships
// updates) and we navigate to /sites.

export function NoOrgScreen() {
  const [showCreate, setShowCreate] = useState(false);

  return (
    <>
      <div
        role="status"
        aria-labelledby="no-org-heading"
        className="flex min-h-[80vh] flex-col items-center justify-center px-6"
      >
        <div className="w-full max-w-md rounded-lg border border-border bg-card p-8 shadow-sm">
          {/* Brand icon */}
          <div className="mb-6 flex justify-center">
            <span
              aria-hidden="true"
              className="flex size-14 items-center justify-center rounded-xl border border-border bg-muted"
            >
              <Building2
                strokeWidth={1.25}
                className="size-7 text-muted-foreground"
              />
            </span>
          </div>

          {/* Heading + body */}
          <h1
            id="no-org-heading"
            className="mb-2 text-center text-xl font-semibold text-foreground"
          >
            Welcome to WPMgr
          </h1>
          <p className="mb-8 text-center text-sm text-muted-foreground">
            You&apos;re not part of an organisation yet. Create one to start
            adding sites.
          </p>

          {/* Primary CTA */}
          <Button
            type="button"
            className="w-full"
            onClick={() => setShowCreate(true)}
          >
            Create organisation
          </Button>
        </div>
      </div>

      <CreateOrgOnboardingDialog
        open={showCreate}
        onClose={() => setShowCreate(false)}
      />
    </>
  );
}

// ---------------------------------------------------------------------------
// Create-org dialog — reuses useCreateOrg from use-orgs.ts. On success it
// invalidates auth.me and navigates to /sites so the user lands in their new
// org context immediately.
// ---------------------------------------------------------------------------

function CreateOrgOnboardingDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const titleId = useId();
  const createOrg = useCreateOrg();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setName("");
    setError(null);
  }

  function handleClose() {
    reset();
    onClose();
  }

  async function handleCreate() {
    const trimmed = name.trim();
    if (!trimmed) {
      setError("Name is required");
      return;
    }
    setError(null);
    try {
      await createOrg.mutateAsync({ name: trimmed });
      // useCreateOrg.onSuccess already invalidates auth.me. Wait for the
      // refetch so the _authed guard sees the new membership before we navigate.
      await queryClient.refetchQueries({ queryKey: authKeys.me });
      reset();
      onClose();
      void navigate({ to: "/sites" });
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Could not create organisation",
      );
    }
  }

  return (
    <Dialog open={open} onClose={handleClose}>
      <DialogContent ariaLabelledBy={titleId}>
        <DialogHeader>
          <DialogTitle id={titleId}>Create organisation</DialogTitle>
        </DialogHeader>

        <DialogBody className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="onboarding-org-name">Organisation name</Label>
            <Input
              id="onboarding-org-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Acme Corp"
              data-autofocus
              aria-invalid={error ? true : undefined}
              disabled={createOrg.isPending}
              onKeyDown={(e) => {
                if (e.key === "Enter") void handleCreate();
              }}
            />
            {error ? (
              <p
                role="alert"
                className="text-sm text-[var(--color-destructive)]"
              >
                {error}
              </p>
            ) : null}
          </div>
        </DialogBody>

        <DialogFooter className="pt-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={createOrg.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={() => void handleCreate()}
            disabled={createOrg.isPending || !name.trim()}
          >
            {createOrg.isPending ? (
              <>
                <Loader2 aria-hidden="true" className="animate-spin" />
                <span>Creating...</span>
              </>
            ) : (
              "Create"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
