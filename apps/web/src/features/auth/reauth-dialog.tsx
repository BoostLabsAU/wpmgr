/**
 * Re-auth dialog — prompts for current password before a sensitive action.
 *
 * Used by:
 *   - Disable TOTP
 *   - Remove WebAuthn credential
 *   - Regenerate recovery codes
 *
 * Calls onConfirm(password) and surfaces the error returned by the caller.
 * The caller owns the mutation; this dialog is purely a credential-capture UI.
 */

import { useState, useRef, useEffect } from "react";
import { AlertTriangle, Eye, EyeOff, Loader2 } from "lucide-react";

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

interface ReauthDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: (password: string) => Promise<void>;
  /** Title of the action requiring re-auth. */
  title: string;
  /** Verb-first confirm label. */
  confirmLabel: string;
  /** Optional context message. */
  description?: string;
}

export function ReauthDialog({
  open,
  onClose,
  onConfirm,
  title,
  confirmLabel,
  description,
}: ReauthDialogProps) {
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isPending, setIsPending] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  // Derived state: reset when `open` transitions false → true.
  // Mirrors the pattern in destructive-confirm.tsx.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setPassword("");
      setError(null);
      setIsPending(false);
    }
  }

  // Auto-focus the password input when the dialog opens.
  useEffect(() => {
    if (open) {
      window.setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [open]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!password) {
      setError("Password is required");
      return;
    }
    setError(null);
    setIsPending(true);
    try {
      await onConfirm(password);
      onClose();
    } catch (err) {
      const e = err as Error;
      setError(e.message);
      setIsPending(false);
    }
  }

  return (
    <Dialog open={open} onClose={isPending ? () => {} : onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle id="reauth-dialog-title">{title}</DialogTitle>
        </DialogHeader>
        <DialogBody>
          {description ? (
            <p className="text-sm text-[var(--color-muted-foreground)]">{description}</p>
          ) : null}

          {error ? (
            <div
              role="alert"
              className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5"
            >
              <AlertTriangle
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
              />
              <p className="text-sm text-[var(--color-destructive)]">{error}</p>
            </div>
          ) : null}

          <form
            id="reauth-form"
            onSubmit={(e) => void handleSubmit(e)}
            noValidate
            className="space-y-2"
          >
            <Label htmlFor="reauth-password">Current password</Label>
            <div className="relative max-w-sm">
              <Input
                ref={inputRef}
                id="reauth-password"
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={isPending}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? "reauth-password-error" : undefined}
                className="pr-10"
              />
              <button
                type="button"
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
                onClick={() => setShowPassword((v) => !v)}
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? (
                  <EyeOff aria-hidden="true" className="size-4" />
                ) : (
                  <Eye aria-hidden="true" className="size-4" />
                )}
              </button>
            </div>
          </form>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="reauth-form"
            disabled={!password || isPending}
          >
            {isPending ? (
              <>
                <Loader2 aria-hidden="true" className="animate-spin" />
                Confirming…
              </>
            ) : (
              confirmLabel
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
