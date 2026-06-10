import { useState, useId } from "react";
import { Send } from "lucide-react";

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogBody,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "@/components/toast";
import { useTestEmail } from "./use-email";

// ---------------------------------------------------------------------------
// Test email dialog
//
// Opens via a "Send test email" button. Dispatches POST .../email/test and
// shows the result inline. A successful dispatch shows a success toast;
// an agent-side failure (ok:false) shows an error inline since the agent
// may return a WP_Error message that's useful to surface.
// ---------------------------------------------------------------------------

export interface EmailTestDialogProps {
  siteId: string;
  open: boolean;
  onClose: () => void;
}

export function EmailTestDialog({
  siteId,
  open,
  onClose,
}: EmailTestDialogProps) {
  const testEmail = useTestEmail(siteId);
  const [to, setTo] = useState("");
  const [subject, setSubject] = useState("");
  const titleId = useId();
  const descId = useId();
  const toId = useId();
  const subjectId = useId();

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!to.trim()) return;
    testEmail.mutate(
      {
        to: to.trim(),
        ...(subject.trim() ? { subject: subject.trim() } : {}),
      },
      {
        onSuccess: (result) => {
          if (result.ok) {
            toast.success("Test email dispatched", {
              description: `Sent to ${to.trim()}`,
            });
            onClose();
          } else {
            // Don't close — surface the agent error inline so the operator
            // can diagnose the provider configuration.
          }
        },
        onError: (err) => {
          toast.error("Test email failed", { description: err.message });
        },
      },
    );
  }

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent ariaLabelledBy={titleId} ariaDescribedBy={descId}>
        <DialogHeader>
          <DialogTitle id={titleId}>Send test email</DialogTitle>
          <DialogDescription id={descId}>
            Send a test message through this site's configured email provider to
            verify delivery is working.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} noValidate>
          <DialogBody className="space-y-4 py-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={toId}>
                Recipient address
                <span className="ml-1 text-[var(--color-destructive)]">*</span>
              </Label>
              <Input
                id={toId}
                type="email"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                placeholder="you@example.com"
                required
                aria-required="true"
                disabled={testEmail.isPending}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor={subjectId}>Subject (optional)</Label>
              <Input
                id={subjectId}
                type="text"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                placeholder="WPMgr test email"
                disabled={testEmail.isPending}
              />
            </div>

            {/* Agent error result (ok: false) — shown inline, not as a toast */}
            {testEmail.isSuccess && !testEmail.data.ok ? (
              <div
                role="alert"
                className="rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] p-3"
              >
                <p className="text-sm font-medium text-[var(--color-destructive)]">
                  The agent reported a failure
                </p>
                {testEmail.data.detail ? (
                  <p className="mt-1 text-xs text-[var(--color-muted-foreground)]">
                    {testEmail.data.detail}
                  </p>
                ) : null}
              </div>
            ) : null}
          </DialogBody>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={testEmail.isPending}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={testEmail.isPending || !to.trim()}
              className="gap-2"
            >
              <Send aria-hidden="true" className="size-4" />
              {testEmail.isPending ? "Sending…" : "Send test"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
