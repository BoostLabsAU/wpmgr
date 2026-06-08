import { useMemo, useState } from "react";
import { ArrowLeft, ArrowRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { useOnboardingState } from "./use-onboarding-state";

// Surface 4.12 — first-site onboarding. Inline wizard (NOT a modal, per
// DESIGN.md "Don't use modals as a reflex"). Replaces NoSitesEmpty for
// brand-new tenants who haven't completed onboarding yet.
//
// AUTO_INSTALL_METHODS_ENABLED controls whether the full 3-step wizard
// (URL → Method → Sync) is shown, or the collapsed single-step flow
// (URL → handoff to the real Add-site connect flow).
//
// When false (current): the wizard captures the site URL, then the "Continue"
// button fires onHandoff + complete(), handing off to AddSiteDialog which
// hosts the real download + pairing-code flow. MethodStep and SyncStep are
// NOT shown — the method picker is unbuilt, and SyncStep is a redundant
// placeholder whose real equivalent is AddSiteDialog's AwaitingStep.
//
// When true: the full 3-step flow runs (Method picker + Sync spinner).
// Re-enable when the WPMgr Agent is approved on wordpress.org and the
// WP-CLI / Manual paths are wired.

// Auto-install method picker (Direct / WP-CLI / Manual). These automated
// install paths are not built yet — re-enable when the WPMgr Agent is
// approved on wordpress.org and the WP-CLI / Manual paths are wired. Until
// then the wizard goes URL -> the real Add-site connect flow (download the
// plugin + paste a pairing code), which IS built.
const AUTO_INSTALL_METHODS_ENABLED = false;

type Step = 1 | 2 | 3;

type EnrollMethod = "direct" | "wp-cli" | "manual";

interface MethodMeta {
  id: EnrollMethod;
  label: string;
  hint: string;
}

const METHODS: readonly MethodMeta[] = [
  {
    id: "direct",
    label: "Direct enrollment (recommended)",
    hint: "Install the WPMgr Agent plugin and paste a one-time pairing code. Fastest path.",
  },
  {
    id: "wp-cli",
    label: "WP-CLI",
    hint: "Run `wp wpmgr enroll <code>` on the server. Best for headless or CI-managed sites.",
  },
  {
    id: "manual",
    label: "Manual upload",
    hint: "Upload the agent plugin zip via wp-admin. Use when SSH and WP-CLI are unavailable.",
  },
];

function isHttpsUrl(value: string): { ok: true; hostname: string } | { ok: false } {
  const trimmed = value.trim();
  if (!/^https:\/\//i.test(trimmed)) return { ok: false };
  try {
    const u = new URL(trimmed);
    if (!u.hostname) return { ok: false };
    return { ok: true, hostname: u.hostname };
  } catch {
    return { ok: false };
  }
}

export interface OnboardingWizardProps {
  /**
   * Invoked when the wizard reaches the terminal CTA. The route can use this
   * to open AddSiteDialog pre-filled with the user's URL. Marking `complete()`
   * is called automatically after onHandoff fires.
   */
  onHandoff?: (input: { url: string; method: EnrollMethod }) => void;
}

export function OnboardingWizard({ onHandoff }: OnboardingWizardProps = {}) {
  const { complete } = useOnboardingState();
  const [step, setStep] = useState<Step>(1);
  const [url, setUrl] = useState("");
  const [method, setMethod] = useState<EnrollMethod>("direct");

  const validation = useMemo(() => isHttpsUrl(url), [url]);
  const canProceedFromUrl = validation.ok;
  const hostname = validation.ok ? validation.hostname : "";

  function handleNext() {
    if (step === 1 && !canProceedFromUrl) return;
    if (AUTO_INSTALL_METHODS_ENABLED) {
      if (step === 1) setStep(2);
      else if (step === 2) setStep(3);
    }
  }

  function handleBack() {
    if (AUTO_INSTALL_METHODS_ENABLED) {
      if (step === 3) setStep(2);
      else if (step === 2) setStep(1);
    }
  }

  function handleFinish() {
    onHandoff?.({ url: url.trim(), method });
    complete();
  }

  function handleSkip() {
    complete();
  }

  // When collapsed: the wizard is a single URL step whose primary CTA fires
  // handleFinish directly (no intermediate steps).
  const isCollapsed = !AUTO_INSTALL_METHODS_ENABLED;

  return (
    <section
      aria-labelledby="onboarding-title"
      className="mx-auto w-full max-w-2xl rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-8"
    >
      <header className="space-y-2">
        <p
          id="onboarding-title"
          className="text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)]"
        >
          First site
        </p>
        <h2 className="text-balance text-xl font-semibold text-[var(--color-foreground)]">
          Enroll a WordPress site to populate this console.
        </h2>
        {!isCollapsed ? <StepIndicator step={step} /> : null}
      </header>

      <div className="mt-8">
        {step === 1 ? (
          <UrlStep
            url={url}
            onUrlChange={setUrl}
            invalid={url.length > 0 && !canProceedFromUrl}
          />
        ) : null}
        {AUTO_INSTALL_METHODS_ENABLED && step === 2 ? (
          <MethodStep method={method} onMethodChange={setMethod} />
        ) : null}
        {AUTO_INSTALL_METHODS_ENABLED && step === 3 ? (
          <SyncStep hostname={hostname} />
        ) : null}
      </div>

      <footer className="mt-8 flex items-center justify-between gap-3 border-t border-[var(--color-border)] pt-6">
        <div className="flex items-center gap-3">
          {AUTO_INSTALL_METHODS_ENABLED && step > 1 ? (
            <Button type="button" variant="ghost" size="sm" onClick={handleBack}>
              <ArrowLeft aria-hidden="true" />
              Back
            </Button>
          ) : (
            <button
              type="button"
              onClick={handleSkip}
              className="text-sm text-[var(--color-muted-foreground)] underline-offset-4 transition-colors hover:text-[var(--color-foreground)] hover:underline focus-visible:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-2"
            >
              Skip onboarding
            </button>
          )}
        </div>
        <div>
          {isCollapsed ? (
            <Button
              type="button"
              onClick={handleFinish}
              disabled={!canProceedFromUrl}
            >
              Continue
              <ArrowRight aria-hidden="true" />
            </Button>
          ) : step < 3 ? (
            <Button
              type="button"
              onClick={handleNext}
              disabled={step === 1 && !canProceedFromUrl}
            >
              Continue
              <ArrowRight aria-hidden="true" />
            </Button>
          ) : (
            <Button type="button" onClick={handleFinish}>
              Generate code
              <ArrowRight aria-hidden="true" />
            </Button>
          )}
        </div>
      </footer>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Step indicator (only rendered in the full 3-step flow)
// ---------------------------------------------------------------------------

function StepIndicator({ step }: { step: Step }) {
  const items: { n: Step; label: string }[] = [
    { n: 1, label: "URL" },
    { n: 2, label: "Method" },
    { n: 3, label: "Sync" },
  ];
  return (
    <ol
      aria-label="Onboarding progress"
      className="flex items-center gap-2 pt-1 text-xs"
    >
      {items.map((item, idx) => {
        const state =
          item.n === step ? "current" : item.n < step ? "done" : "todo";
        return (
          <li key={item.n} className="flex items-center gap-2">
            <span
              aria-current={state === "current" ? "step" : undefined}
              className={cn(
                "inline-flex items-center gap-1.5 tabular-nums",
                state === "current" &&
                  "font-medium text-[var(--color-foreground)]",
                state === "done" && "text-[var(--color-muted-foreground)]",
                state === "todo" && "text-[var(--color-muted-foreground)]/60",
              )}
            >
              <span
                className={cn(
                  "inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-medium",
                  state === "current" &&
                    "border-[var(--color-primary)] bg-[var(--color-primary)] text-[var(--color-primary-foreground)]",
                  state === "done" &&
                    "border-[var(--color-border)] bg-[var(--color-muted)] text-[var(--color-foreground)]",
                  state === "todo" &&
                    "border-[var(--color-border)] text-[var(--color-muted-foreground)]/60",
                )}
              >
                {item.n}
              </span>
              {item.label}
            </span>
            {idx < items.length - 1 ? (
              <span
                aria-hidden="true"
                className="text-[var(--color-muted-foreground)]/50"
              >
                ·
              </span>
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

// ---------------------------------------------------------------------------
// Step 1 — URL
// ---------------------------------------------------------------------------

function UrlStep({
  url,
  onUrlChange,
  invalid,
}: {
  url: string;
  onUrlChange: (v: string) => void;
  invalid: boolean;
}) {
  return (
    <div className="space-y-3">
      <div className="space-y-2">
        <Label htmlFor="onboarding-url" className="text-sm">
          Site URL
        </Label>
        <Input
          id="onboarding-url"
          type="url"
          inputMode="url"
          autoComplete="url"
          placeholder="https://example.com"
          value={url}
          onChange={(e) => onUrlChange(e.target.value)}
          aria-invalid={invalid || undefined}
          aria-describedby="onboarding-url-help"
          className="font-mono"
        />
        <p
          id="onboarding-url-help"
          className={cn(
            "text-xs",
            invalid
              ? "text-[var(--color-destructive)]"
              : "text-[var(--color-muted-foreground)]",
          )}
        >
          {invalid
            ? "URL must start with https://. We don't enroll sites over plain HTTP."
            : "We'll reach the site over HTTPS only."}
        </p>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Step 2 — Connection method (full flow only; gated by AUTO_INSTALL_METHODS_ENABLED)
// ---------------------------------------------------------------------------

function MethodStep({
  method,
  onMethodChange,
}: {
  method: EnrollMethod;
  onMethodChange: (m: EnrollMethod) => void;
}) {
  return (
    <fieldset className="space-y-3">
      <legend className="sr-only">Connection method</legend>
      <p className="text-sm text-[var(--color-muted-foreground)]">
        Pick how the agent plugin will pair with this console.
      </p>
      <div className="space-y-2">
        {METHODS.map((m) => {
          const selected = m.id === method;
          return (
            <label
              key={m.id}
              className={cn(
                "flex cursor-pointer items-start gap-3 rounded-md border p-4 transition-colors",
                selected
                  ? "border-[var(--color-primary)] bg-[var(--color-accent)]"
                  : "border-[var(--color-border)] bg-[var(--color-background)] hover:bg-[var(--color-muted)]",
              )}
            >
              <input
                type="radio"
                name="enroll-method"
                value={m.id}
                checked={selected}
                onChange={() => onMethodChange(m.id)}
                className="mt-1 size-4 accent-[var(--color-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-2"
              />
              <span className="flex min-w-0 flex-col gap-1">
                <span className="text-sm font-medium text-[var(--color-foreground)]">
                  {m.label}
                </span>
                <span className="text-xs text-[var(--color-muted-foreground)]">
                  {m.hint}
                </span>
              </span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

// ---------------------------------------------------------------------------
// Step 3 — First sync placeholder (full flow only; gated by AUTO_INSTALL_METHODS_ENABLED)
// The real handshake/SSE wait lives in AddSiteDialog's AwaitingStep.
// ---------------------------------------------------------------------------

function SyncStep({ hostname }: { hostname: string }) {
  const target = hostname || "the site";
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex flex-col items-center gap-4 py-4 text-center"
    >
      <p className="text-sm text-[var(--color-foreground)]">
        Ready to connect{" "}
        <span className="font-mono text-[var(--color-foreground)]">
          {target}
        </span>
        .
      </p>
      <ol className="space-y-1 text-xs text-[var(--color-muted-foreground)]">
        <li>Pairing code will be generated</li>
        <li>Agent will enroll on paste</li>
      </ol>
    </div>
  );
}
