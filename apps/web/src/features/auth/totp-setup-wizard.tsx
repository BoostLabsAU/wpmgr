/**
 * TOTP enrollment wizard.
 *
 * Steps:
 *   1. Scan  — QR code + manual secret copy
 *   2. Verify — confirm a live code
 *   3. Codes  — show recovery codes once, force acknowledgement
 *
 * The dialog stays open until the user clicks "Done" after saving their codes.
 */

import { useState, useRef, useEffect } from "react";
import { AlertCircle, AlertTriangle, Check, Copy, Download, Loader2 } from "lucide-react";
import QRCode from "react-qr-code";

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
import {
  useTotpBegin,
  useTotpConfirm,
  type TotpBeginResult,
} from "@/features/auth/use-2fa";

interface TotpSetupWizardProps {
  open: boolean;
  onClose: () => void;
}

type Step = "scan" | "verify" | "codes";

export function TotpSetupWizard({ open, onClose }: TotpSetupWizardProps) {
  const [step, setStep] = useState<Step>("scan");
  const [beginData, setBeginData] = useState<TotpBeginResult | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [codesSaved, setCodesSaved] = useState(false);

  const beginMutation = useTotpBegin();

  // Derived state: reset + re-trigger enrollment when `open` transitions
  // false → true. Mirrors the pattern in destructive-confirm.tsx.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setStep("scan");
      setBeginData(null);
      setRecoveryCodes([]);
      setCodesSaved(false);
    }
  }

  // Kick off TOTP enrollment as soon as the dialog opens.
  useEffect(() => {
    if (!open) return;
    beginMutation.mutate(undefined, {
      onSuccess: (data) => setBeginData(data),
    });
    // Only re-run when `open` changes — beginMutation identity is stable.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  function handleClose() {
    // Don't allow closing mid-setup except on the codes step (where TOTP is
    // already confirmed) or before the wizard started.
    if (step === "codes" || beginMutation.isIdle) {
      onClose();
    }
  }

  return (
    <Dialog open={open} onClose={handleClose}>
      <DialogContent>
        {step === "scan" && (
          <ScanStep
            data={beginData}
            isLoading={beginMutation.isPending}
            error={beginMutation.error?.message ?? null}
            onNext={() => setStep("verify")}
          />
        )}
        {step === "verify" && beginData && (
          <VerifyStep
            onSuccess={(codes) => {
              setRecoveryCodes(codes);
              setStep("codes");
            }}
            onBack={() => setStep("scan")}
          />
        )}
        {step === "codes" && (
          <CodesStep
            codes={recoveryCodes}
            codesSaved={codesSaved}
            onSaved={() => setCodesSaved(true)}
            onDone={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// Step 1 — Scan
// ---------------------------------------------------------------------------

function ScanStep({
  data,
  isLoading,
  error,
  onNext,
}: {
  data: TotpBeginResult | null;
  isLoading: boolean;
  error: string | null;
  onNext: () => void;
}) {
  const titleId = "totp-setup-title-scan";
  const [secretCopied, setSecretCopied] = useState(false);

  function copySecret() {
    if (!data?.secret) return;
    void navigator.clipboard.writeText(data.secret).then(() => {
      setSecretCopied(true);
      window.setTimeout(() => setSecretCopied(false), 1500);
    });
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle id={titleId}>Set up authenticator app</DialogTitle>
      </DialogHeader>
      <DialogBody>
        <p className="text-sm text-[var(--color-muted-foreground)]">
          Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.).
        </p>

        {isLoading ? (
          <div className="flex h-40 items-center justify-center">
            <Loader2 aria-hidden="true" className="animate-spin text-[var(--color-muted-foreground)]" />
          </div>
        ) : error ? (
          <div role="alert" className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5">
            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]" />
            <p className="text-sm text-[var(--color-destructive)]">{error}</p>
          </div>
        ) : data ? (
          <div className="space-y-4">
            {/* QR code — rendered entirely client-side, no server image */}
            <div className="flex justify-center rounded-lg border border-[var(--color-border)] bg-white p-4">
              <QRCode
                value={data.otpauth_uri}
                size={180}
                aria-label="TOTP QR code for your authenticator app"
              />
            </div>

            {/* Manual entry fallback */}
            <div className="space-y-1.5">
              <p className="text-xs text-[var(--color-muted-foreground)]">
                Can't scan? Enter this key manually:
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded bg-[var(--color-muted)]/50 px-2 py-1.5 font-mono text-sm tracking-widest tabular-nums break-all text-[var(--color-foreground)]">
                  {data.secret}
                </code>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={copySecret}
                  aria-label="Copy secret key"
                  className="shrink-0"
                >
                  {secretCopied ? (
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
          </div>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <Button
          type="button"
          onClick={onNext}
          disabled={!data || isLoading}
        >
          Next: verify code
        </Button>
      </DialogFooter>
    </>
  );
}

// ---------------------------------------------------------------------------
// Step 2 — Verify
// ---------------------------------------------------------------------------

function VerifyStep({
  onSuccess,
  onBack,
}: {
  onSuccess: (codes: string[]) => void;
  onBack: () => void;
}) {
  const titleId = "totp-setup-title-verify";
  const [code, setCode] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  const confirmMutation = useTotpConfirm();

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const trimmed = code.replace(/\s/g, "");
    if (trimmed.length !== 6) return;

    confirmMutation.mutate(
      { code: trimmed },
      { onSuccess: (data) => onSuccess(data.recovery_codes) },
    );
  }

  const codeError =
    code.length > 0 && code.replace(/\s/g, "").length !== 6
      ? "Enter the full 6-digit code"
      : null;

  return (
    <>
      <DialogHeader>
        <DialogTitle id={titleId}>Confirm your authenticator app</DialogTitle>
      </DialogHeader>
      <DialogBody>
        <p className="text-sm text-[var(--color-muted-foreground)]">
          Enter the 6-digit code shown in your authenticator app to confirm setup.
        </p>

        {confirmMutation.isError ? (
          <div role="alert" className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5">
            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]" />
            <p className="text-sm text-[var(--color-destructive)]">{confirmMutation.error.message}</p>
          </div>
        ) : null}

        <form
          id="totp-verify-form"
          onSubmit={(e) => void handleSubmit(e)}
          noValidate
          className="space-y-2"
        >
          <Label htmlFor="totp-verify-code">Authentication code</Label>
          <Input
            ref={inputRef}
            id="totp-verify-code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            placeholder="123456"
            maxLength={6}
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
            aria-invalid={codeError ? true : undefined}
            aria-describedby={codeError ? "totp-verify-error" : undefined}
            className="font-mono tabular-nums text-lg tracking-widest max-w-[180px]"
          />
          {codeError ? (
            <p id="totp-verify-error" role="alert" className="flex items-center gap-1.5 text-sm text-[var(--color-destructive)]">
              <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
              {codeError}
            </p>
          ) : null}
        </form>
      </DialogBody>
      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          onClick={onBack}
          disabled={confirmMutation.isPending}
        >
          Back
        </Button>
        <Button
          type="submit"
          form="totp-verify-form"
          disabled={code.replace(/\s/g, "").length !== 6 || confirmMutation.isPending}
        >
          {confirmMutation.isPending ? (
            <>
              <Loader2 aria-hidden="true" className="animate-spin" />
              Verifying…
            </>
          ) : (
            "Confirm"
          )}
        </Button>
      </DialogFooter>
    </>
  );
}

// ---------------------------------------------------------------------------
// Step 3 — Recovery codes
// ---------------------------------------------------------------------------

function CodesStep({
  codes,
  codesSaved,
  onSaved,
  onDone,
}: {
  codes: string[];
  codesSaved: boolean;
  onSaved: () => void;
  onDone: () => void;
}) {
  const titleId = "totp-setup-title-codes";
  const [allCopied, setAllCopied] = useState(false);

  function copyAll() {
    void navigator.clipboard.writeText(codes.join("\n")).then(() => {
      setAllCopied(true);
      window.setTimeout(() => setAllCopied(false), 1500);
    });
  }

  function downloadCodes() {
    const content = [
      "WPMgr Recovery Codes",
      "====================",
      "Keep these codes safe. Each code can only be used once.",
      "",
      ...codes,
    ].join("\n");
    const blob = new Blob([content], { type: "text/plain" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "wpmgr-recovery-codes.txt";
    a.click();
    URL.revokeObjectURL(url);
    onSaved();
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle id={titleId}>Save your recovery codes</DialogTitle>
      </DialogHeader>
      <DialogBody>
        <div
          role="alert"
          className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5"
        >
          <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]" />
          <p className="text-sm text-[var(--color-destructive)]">
            These codes are shown once and cannot be retrieved again. Store them somewhere safe.
          </p>
        </div>

        <p className="text-sm text-[var(--color-muted-foreground)]">
          Use these codes to access your account if you lose your authenticator app. Each code works once.
        </p>

        {/* Recovery codes grid */}
        <div className="grid grid-cols-2 gap-1.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-muted)]/30 p-3">
          {codes.map((code, i) => (
            <code
              key={i}
              className="font-mono text-sm tabular-nums tracking-wider text-[var(--color-foreground)]"
            >
              {code}
            </code>
          ))}
        </div>

        {/* Save actions */}
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={copyAll}
            aria-label="Copy all recovery codes"
          >
            {allCopied ? (
              <>
                <Check aria-hidden="true" className="size-3.5" />
                Copied
              </>
            ) : (
              <>
                <Copy aria-hidden="true" className="size-3.5" />
                Copy all
              </>
            )}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={downloadCodes}
            aria-label="Download recovery codes as text file"
          >
            <Download aria-hidden="true" className="size-3.5" />
            Download .txt
          </Button>
        </div>

        {/* Confirmation checkbox */}
        <label className="flex cursor-pointer items-center gap-2.5">
          <input
            type="checkbox"
            checked={codesSaved}
            onChange={(e) => { if (e.target.checked) onSaved(); }}
            className="size-4 shrink-0 cursor-pointer rounded border border-[var(--color-border)] accent-[var(--color-primary)]"
          />
          <span className="text-sm">I have saved my recovery codes in a safe place</span>
        </label>
      </DialogBody>
      <DialogFooter>
        <Button
          type="button"
          onClick={onDone}
          disabled={!codesSaved}
        >
          Done
        </Button>
      </DialogFooter>
    </>
  );
}
