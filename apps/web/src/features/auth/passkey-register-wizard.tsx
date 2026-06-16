/**
 * Passkey / WebAuthn registration wizard.
 *
 * Steps:
 *   1. Name  — friendly device label (pre-filled with detected platform)
 *   2. Ceremony — trigger navigator.credentials.create(), wait for result
 *   3. Done  — success confirmation
 */

import { useState, useRef, useEffect } from "react";
import { AlertTriangle, KeyRound, Loader2, Check } from "lucide-react";

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
  useWebAuthnBeginRegistration,
  useWebAuthnFinishRegistration,
} from "@/features/auth/use-2fa";

interface PasskeyRegisterWizardProps {
  open: boolean;
  onClose: () => void;
}

type Step = "name" | "ceremony" | "done";

function detectPlatformLabel(): string {
  if (typeof navigator === "undefined") return "My passkey";
  const ua = navigator.userAgent;
  if (/iPhone/.test(ua)) return "iPhone";
  if (/iPad/.test(ua)) return "iPad";
  if (/Macintosh/.test(ua)) return "Mac";
  if (/Windows/.test(ua)) return "Windows PC";
  if (/Android/.test(ua)) return "Android device";
  if (/Linux/.test(ua)) return "Linux PC";
  return "My passkey";
}

export function PasskeyRegisterWizard({ open, onClose }: PasskeyRegisterWizardProps) {
  const [step, setStep] = useState<Step>("name");
  const [name, setName] = useState(() => detectPlatformLabel());
  const [nameError, setNameError] = useState<string | null>(null);
  const [ceremonyError, setCeremonyError] = useState<string | null>(null);

  const beginMutation = useWebAuthnBeginRegistration();
  const finishMutation = useWebAuthnFinishRegistration();

  const inputRef = useRef<HTMLInputElement>(null);

  // Derived state: reset when `open` transitions false → true.
  // Mirrors the pattern in destructive-confirm.tsx.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setStep("name");
      setName(detectPlatformLabel());
      setNameError(null);
      setCeremonyError(null);
    }
  }

  useEffect(() => {
    if (step === "name") {
      window.setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [step]);

  async function handleStart(e: React.FormEvent) {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) {
      setNameError("Enter a name for this passkey");
      return;
    }
    setNameError(null);
    setStep("ceremony");
    setCeremonyError(null);

    try {
      // 1. Get creation options from the server
      const options = await beginMutation.mutateAsync();

      // 2. Run the browser create ceremony using @github/webauthn-json
      const { create } = await import("@github/webauthn-json");
      const credential = await create({
        publicKey: options as unknown as Parameters<typeof create>[0]["publicKey"],
      });

      // 3. Serialize the attestation to JSON and base64-encode it.
      const attestationJson = JSON.stringify(credential);
      const attestationB64 = btoa(attestationJson);

      // 4. Send the attestation to the server with the friendly name.
      await finishMutation.mutateAsync({
        name: trimmed,
        attestation: attestationB64,
      });

      setStep("done");
    } catch (err) {
      if (err instanceof DOMException) {
        if (err.name === "NotAllowedError") {
          setCeremonyError("Passkey creation was cancelled.");
        } else {
          setCeremonyError("Your browser or device rejected the passkey creation. Try again.");
        }
      } else {
        const e = err as Error;
        setCeremonyError(e.message);
      }
      setStep("name");
    }
  }

  const isPending = beginMutation.isPending || finishMutation.isPending;

  const isSupported = typeof window !== "undefined" && !!window.PublicKeyCredential;

  return (
    <Dialog open={open} onClose={step === "ceremony" ? () => {} : onClose}>
      <DialogContent>
        {(step === "name" || step === "ceremony") && (
          <>
            <DialogHeader>
              <DialogTitle id="passkey-wizard-title">Add a passkey</DialogTitle>
            </DialogHeader>
            <DialogBody>
              {!isSupported ? (
                <div
                  role="alert"
                  className="flex items-start gap-2.5 rounded-md border border-[var(--color-border)] bg-[var(--color-card)] px-3 py-2.5"
                >
                  <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]" />
                  <p className="text-sm text-[var(--color-muted-foreground)]">
                    Your browser does not support passkeys. Try a modern browser.
                  </p>
                </div>
              ) : (
                <>
                  <p className="text-sm text-[var(--color-muted-foreground)]">
                    Passkeys let you sign in using your device's biometrics or a hardware security key.
                  </p>

                  {ceremonyError ? (
                    <div role="alert" className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5">
                      <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]" />
                      <p className="text-sm text-[var(--color-destructive)]">{ceremonyError}</p>
                    </div>
                  ) : null}

                  <form
                    id="passkey-name-form"
                    onSubmit={(e) => void handleStart(e)}
                    noValidate
                    className="space-y-2"
                  >
                    <Label htmlFor="passkey-name">Device name</Label>
                    <Input
                      ref={inputRef}
                      id="passkey-name"
                      type="text"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="MacBook Pro"
                      maxLength={100}
                      disabled={isPending}
                      aria-invalid={nameError ? true : undefined}
                      aria-describedby={nameError ? "passkey-name-error" : undefined}
                    />
                    {nameError ? (
                      <p id="passkey-name-error" role="alert" className="text-sm text-[var(--color-destructive)]">
                        {nameError}
                      </p>
                    ) : null}
                  </form>

                  {step === "ceremony" && (
                    <div className="flex items-center gap-2.5 text-sm text-[var(--color-muted-foreground)]">
                      <Loader2 aria-hidden="true" className="animate-spin size-4 shrink-0" />
                      Waiting for your device…
                    </div>
                  )}
                </>
              )}
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
              {isSupported && (
                <Button
                  type="submit"
                  form="passkey-name-form"
                  disabled={isPending || !isSupported}
                >
                  {isPending ? (
                    <>
                      <Loader2 aria-hidden="true" className="animate-spin" />
                      Creating…
                    </>
                  ) : (
                    <>
                      <KeyRound aria-hidden="true" />
                      Create passkey
                    </>
                  )}
                </Button>
              )}
            </DialogFooter>
          </>
        )}

        {step === "done" && (
          <>
            <DialogHeader>
              <DialogTitle id="passkey-wizard-title">Passkey added</DialogTitle>
            </DialogHeader>
            <DialogBody>
              <div className="flex flex-col items-center gap-3 py-4 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-[var(--color-primary)]/10">
                  <Check aria-hidden="true" className="size-6 text-[var(--color-primary)]" />
                </div>
                <p className="text-sm text-[var(--color-muted-foreground)]">
                  Your passkey has been added. You can now use it to sign in.
                </p>
              </div>
            </DialogBody>
            <DialogFooter>
              <Button type="button" onClick={onClose}>
                Done
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
