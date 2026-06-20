import { useState } from "react";
import { AlertTriangle, ShieldCheck, Info } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { toast } from "@/components/toast";

import {
  useSiteSecurityPolicy,
  useUpdateSiteSecurityPolicy,
  validateHideBackendSlug,
  validateHideBackendRedirect,
  TFA_ENABLE_NUDGE,
  type SiteSecurityPolicy,
} from "./use-policy";

// Security Suite Phase 3 — site-user auth policy panel.
//
// Three sub-sections:
//   1. Two-factor authentication (2FA) — master toggle, method multi-select,
//      required roles, grace logins, remember-device TTL, XML-RPC block.
//   2. Password policy — min strength (zxcvbn 0-4), block compromised,
//      reuse-block count, max age days, roles for strength/expiry.
//   3. Hide login page — master toggle, secret slug (validated client-side),
//      optional redirect URL.
//
// Shell: PolicyPanel (loading/error/data) -> PolicyLoaded (keyed on server values).
// Single "Save authentication policy" action covering all three sub-sections,
// mirroring the HardeningPanel pattern (no partial save confusion).

// ---------------------------------------------------------------------------
// Known WP roles available for selection
// ---------------------------------------------------------------------------

const WP_ROLES = [
  { value: "administrator", label: "Administrator" },
  { value: "editor", label: "Editor" },
  { value: "author", label: "Author" },
  { value: "contributor", label: "Contributor" },
  { value: "subscriber", label: "Subscriber" },
] as const;

type WpRole = (typeof WP_ROLES)[number]["value"];

const TFA_METHODS = [
  { value: "totp", label: "Authenticator app (TOTP)" },
  { value: "email", label: "Email code" },
  { value: "backup", label: "Backup codes" },
] as const;

type TfaMethod = (typeof TFA_METHODS)[number]["value"];

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Section — which sub-form the card wants to render.
// "all" keeps the original three-section layout for backward compatibility.
// ---------------------------------------------------------------------------

export type PolicySection = "two_factor" | "password" | "hide_backend" | "all";

// PolicyPanel — loading / error shell
// ---------------------------------------------------------------------------

export function PolicyPanel({
  siteId,
  canWrite,
  section = "all",
}: {
  siteId: string;
  canWrite: boolean;
  /** Which sub-section to render. Defaults to "all" for the legacy three-in-one layout. */
  section?: PolicySection;
}) {
  const { data, isPending, isError, error, refetch } =
    useSiteSecurityPolicy(siteId);

  if (isPending) {
    return <PolicySkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load authentication policy."
        why={error instanceof Error ? error.message : "Unknown error"}
        onRetry={() => void refetch()}
        retryLabel="Reload policy"
      />
    );
  }

  if (!data) return null;

  // Key on a stable hash of the current server-state so the form resets when
  // a background refetch returns a changed policy (e.g. another operator saved).
  const policyKey = [
    data.two_factor_enabled,
    data.two_factor_methods.join(","),
    data.two_factor_required_roles.join(","),
    data.two_factor_grace_logins,
    data.two_factor_remember_device_days,
    data.block_xmlrpc_for_2fa_users,
    data.password_min_zxcvbn_score,
    data.password_min_zxcvbn_roles.join(","),
    data.password_block_compromised,
    data.password_reuse_block_count,
    data.password_max_age_days,
    data.password_expiry_roles.join(","),
    data.hide_backend_enabled,
    data.hide_backend_slug,
    data.hide_backend_redirect,
  ].join("|");

  return (
    <PolicyLoaded
      key={policyKey}
      siteId={siteId}
      initialPolicy={data}
      canWrite={canWrite}
      section={section}
    />
  );
}

// ---------------------------------------------------------------------------
// PolicyLoaded — the actual editable form
// ---------------------------------------------------------------------------

interface LoadedProps {
  siteId: string;
  initialPolicy: SiteSecurityPolicy;
  canWrite: boolean;
  section: PolicySection;
}

function PolicyLoaded({ siteId, initialPolicy, canWrite, section }: LoadedProps) {
  const update = useUpdateSiteSecurityPolicy(siteId);

  const [policy, setPolicy] = useState<SiteSecurityPolicy>({ ...initialPolicy });
  const [saveDetail, setSaveDetail] = useState<string | undefined>();
  const [slugError, setSlugError] = useState<string | null>(null);
  const [redirectError, setRedirectError] = useState<string | null>(null);

  // ── helpers ────────────────────────────────────────────────────────────

  function setField<K extends keyof SiteSecurityPolicy>(
    key: K,
    value: SiteSecurityPolicy[K],
  ) {
    setPolicy((prev) => ({ ...prev, [key]: value }));
  }

  function toggleBool(key: keyof SiteSecurityPolicy) {
    setPolicy((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  function toggleStringInList(
    key: keyof SiteSecurityPolicy,
    value: string,
  ) {
    setPolicy((prev) => {
      const current = (prev[key] as string[]) ?? [];
      const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];
      return { ...prev, [key]: next };
    });
  }

  // When the operator first enables 2FA, pre-fill the admins-only TOTP nudge.
  function handleToggle2FA() {
    const enabling = !policy.two_factor_enabled;
    if (enabling && policy.two_factor_required_roles.length === 0) {
      // Apply the recommended default: administrator-only, TOTP + backup.
      setPolicy((prev) => ({
        ...prev,
        two_factor_enabled: true,
        two_factor_required_roles: [...TFA_ENABLE_NUDGE.two_factor_required_roles],
        two_factor_methods: [...TFA_ENABLE_NUDGE.two_factor_methods],
      }));
    } else {
      setField("two_factor_enabled", enabling);
    }
  }

  function handleSlugChange(value: string) {
    setField("hide_backend_slug", value);
    if (policy.hide_backend_enabled || value) {
      setSlugError(validateHideBackendSlug(value));
    }
  }

  function handleRedirectChange(value: string) {
    setField("hide_backend_redirect", value);
    setRedirectError(validateHideBackendRedirect(value));
  }

  // Derive which sub-sections to render from the section prop.
  // Defined before handleSave so showHideBackend is in scope.
  const show2FA = section === "two_factor" || section === "all";
  const showPassword = section === "password" || section === "all";
  const showHideBackend = section === "hide_backend" || section === "all";

  const SAVE_LABEL: Record<PolicySection, string> = {
    two_factor: "Save 2FA settings",
    password: "Save password policy",
    hide_backend: "Save hide login settings",
    all: "Save authentication policy",
  };

  function handleSave() {
    // Client-side validation: only check slug when hide_backend is being shown.
    if (policy.hide_backend_enabled && showHideBackend) {
      const slugErr = validateHideBackendSlug(policy.hide_backend_slug);
      const redirectErr = validateHideBackendRedirect(policy.hide_backend_redirect);
      setSlugError(slugErr);
      setRedirectError(redirectErr);
      if (slugErr || redirectErr) return;
    }

    setSaveDetail(undefined);
    update.mutate(policy, {
      onSuccess: (result) => {
        setSaveDetail(result.detail);
        toast.success("Authentication policy saved.", {
          description: result.detail
            ? `Note: ${result.detail}`
            : "Policy applied to the site.",
        });
      },
      onError: (err: Error) => {
        toast.error("Could not save authentication policy.", {
          description: err.message,
        });
      },
    });
  }

  const disabled = !canWrite || update.isPending;

  return (
    <div className="space-y-8">
      {/* ── Section 1: Two-factor authentication ─────────────────────── */}
      {show2FA ? <SubSection id="policy-2fa" title="Two-factor authentication">
        {/* Master toggle */}
        <ToggleRow
          id="2fa-enabled"
          label="Enable site 2FA"
          help="Activates the two-factor authentication system for WordPress users on this site. Enforcement applies to site users on their next login."
          checked={policy.two_factor_enabled}
          onChange={handleToggle2FA}
          disabled={disabled}
        />

        {/* Safety note — brief, factual, not alarming */}
        {policy.two_factor_enabled ? (
          <div
            role="note"
            className="flex items-start gap-3 rounded-lg border border-[var(--color-info,var(--color-primary))]/30 bg-[var(--color-info,var(--color-primary))]/6 px-4 py-3"
          >
            <Info
              aria-hidden="true"
              className="mt-0.5 size-4 shrink-0 text-[var(--color-primary)]"
            />
            <p className="text-xs text-[var(--color-foreground)]">
              2FA takes effect for required roles on next login. Users can always
              recover via backup codes. Operators retain autologin access from this
              dashboard regardless of policy.
            </p>
          </div>
        ) : null}

        {policy.two_factor_enabled ? (
          <div className="space-y-6 pt-2">
            {/* Allowed methods */}
            <CheckboxGroup
              id="2fa-methods"
              legend="Allowed methods"
              help="Which second factors site users may enroll. At least one method must be allowed."
              options={TFA_METHODS as unknown as { value: string; label: string }[]}
              selected={policy.two_factor_methods}
              onChange={(method) =>
                toggleStringInList("two_factor_methods", method)
              }
              disabled={disabled}
            />

            {/* Required roles */}
            <CheckboxGroup
              id="2fa-required-roles"
              legend="Required roles"
              help="Roles that must complete 2FA setup. Empty means 2FA is optional for all users."
              options={WP_ROLES as unknown as { value: string; label: string }[]}
              selected={policy.two_factor_required_roles}
              onChange={(role) =>
                toggleStringInList("two_factor_required_roles", role)
              }
              disabled={disabled}
            />

            {/* Grace logins */}
            <NumberField
              id="2fa-grace-logins"
              label="Grace logins"
              help="Number of logins allowed before a required-but-unenrolled user is blocked. 0 forces immediate enrollment."
              value={policy.two_factor_grace_logins}
              min={0}
              max={20}
              onChange={(v) => setField("two_factor_grace_logins", v)}
              disabled={disabled}
              suffix="logins"
            />

            {/* Remember-device TTL */}
            <NumberField
              id="2fa-remember-days"
              label="Remember device"
              help="Days a trusted device is remembered. 0 disables the remember-device option entirely."
              value={policy.two_factor_remember_device_days}
              min={0}
              max={365}
              onChange={(v) => setField("two_factor_remember_device_days", v)}
              disabled={disabled}
              suffix="days"
            />

            {/* Block XML-RPC */}
            <ToggleRow
              id="block-xmlrpc-2fa"
              label="Block XML-RPC for 2FA users"
              help="Rejects password-based XML-RPC authentication for any user who has 2FA enrolled. REST API is unaffected."
              checked={policy.block_xmlrpc_for_2fa_users}
              onChange={() => toggleBool("block_xmlrpc_for_2fa_users")}
              disabled={disabled}
            />
          </div>
        ) : null}
      </SubSection> : null}

      {/* ── Section 2: Password policy ───────────────────────────────── */}
      {showPassword ? <SubSection id="policy-password" title="Password policy">
        {/* Min strength */}
        <div className="py-3 first:pt-0">
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
              <label
                htmlFor="pw-min-score"
                className="block text-sm font-medium text-[var(--color-foreground)]"
              >
                Minimum password strength
              </label>
              <p
                id="pw-min-score-help"
                className="mt-0.5 text-xs text-[var(--color-muted-foreground)]"
              >
                Measured by the zxcvbn algorithm (0 = off, 1 = weak, 2 = fair,
                3 = strong, 4 = very strong). 0 disables strength enforcement.
              </p>
            </div>
            <select
              id="pw-min-score"
              value={policy.password_min_zxcvbn_score}
              onChange={(e) =>
                setField("password_min_zxcvbn_score", Number(e.target.value))
              }
              disabled={disabled}
              aria-describedby="pw-min-score-help"
              className={[
                "h-8 rounded-md border px-2 text-sm",
                "border-[var(--color-border)] bg-[var(--color-background)]",
                "text-[var(--color-foreground)]",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
                disabled ? "cursor-not-allowed opacity-50" : "",
              ].join(" ")}
            >
              <option value={0}>0 — Off</option>
              <option value={1}>1 — Weak</option>
              <option value={2}>2 — Fair</option>
              <option value={3}>3 — Strong</option>
              <option value={4}>4 — Very strong</option>
            </select>
          </div>

          {policy.password_min_zxcvbn_score > 0 ? (
            <CheckboxGroup
              id="pw-strength-roles"
              legend="Apply strength rule to"
              help="Roles the strength requirement applies to. Empty means all roles."
              options={WP_ROLES as unknown as { value: string; label: string }[]}
              selected={policy.password_min_zxcvbn_roles}
              onChange={(role) =>
                toggleStringInList("password_min_zxcvbn_roles", role)
              }
              disabled={disabled}
              className="mt-4"
            />
          ) : null}
        </div>

        <ToggleRow
          id="pw-block-compromised"
          label="Block compromised passwords"
          help="Rejects passwords found in public breach data when a user sets or changes their password. The check uses k-anonymity and no plaintext leaves the site."
          checked={policy.password_block_compromised}
          onChange={() => toggleBool("password_block_compromised")}
          disabled={disabled}
        />

        <NumberField
          id="pw-reuse-count"
          label="Reuse block"
          help="Prevents reuse of the last N passwords. 0 disables reuse checking."
          value={policy.password_reuse_block_count}
          min={0}
          max={24}
          onChange={(v) => setField("password_reuse_block_count", v)}
          disabled={disabled}
          suffix="passwords"
        />

        <NumberField
          id="pw-max-age"
          label="Maximum age"
          help="Forces a password change after this many days. 0 disables expiry."
          value={policy.password_max_age_days}
          min={0}
          max={365}
          onChange={(v) => setField("password_max_age_days", v)}
          disabled={disabled}
          suffix="days"
        />

        {policy.password_max_age_days > 0 ? (
          <CheckboxGroup
            id="pw-expiry-roles"
            legend="Apply expiry to"
            help="Roles the expiry rule applies to. Empty means all roles."
            options={WP_ROLES as unknown as { value: string; label: string }[]}
            selected={policy.password_expiry_roles}
            onChange={(role) =>
              toggleStringInList("password_expiry_roles", role)
            }
            disabled={disabled}
            className="py-3"
          />
        ) : null}
      </SubSection> : null}

      {/* ── Section 3: Hide login page ───────────────────────────────── */}
      {showHideBackend ? <SubSection id="policy-hide-backend" title="Hide login page">
        <ToggleRow
          id="hide-backend-enabled"
          label="Enable secret login slug"
          help="Moves the WordPress login page to a secret URL. Requests to the canonical wp-login.php are redirected or return 404. The autologin path from this dashboard is unaffected."
          checked={policy.hide_backend_enabled}
          onChange={() => toggleBool("hide_backend_enabled")}
          disabled={disabled}
        />

        {policy.hide_backend_enabled ? (
          <div className="space-y-4 pt-2">
            {/* Slug field */}
            <div>
              <label
                htmlFor="hide-backend-slug"
                className="block text-sm font-medium text-[var(--color-foreground)]"
              >
                Login slug
              </label>
              <p
                id="hide-backend-slug-help"
                className="mt-0.5 text-xs text-[var(--color-muted-foreground)]"
              >
                The secret path that renders the login form (e.g.{" "}
                <code className="font-mono">my-login</code>). Must be 4-64
                lowercase letters, digits, or hyphens. Avoid reserved paths.
              </p>
              <input
                id="hide-backend-slug"
                type="text"
                value={policy.hide_backend_slug}
                onChange={(e) => handleSlugChange(e.target.value)}
                disabled={disabled}
                placeholder="my-login"
                aria-describedby={
                  slugError ? "hide-backend-slug-error" : "hide-backend-slug-help"
                }
                aria-invalid={slugError !== null ? "true" : undefined}
                className={[
                  "mt-2 block h-9 w-full max-w-sm rounded-md border px-3 font-mono text-sm",
                  "border-[var(--color-border)] bg-[var(--color-background)]",
                  "text-[var(--color-foreground)] placeholder:text-[var(--color-muted-foreground)]",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
                  disabled ? "cursor-not-allowed opacity-50" : "",
                  slugError ? "border-[var(--color-destructive)]" : "",
                ].join(" ")}
              />
              {slugError ? (
                <p
                  id="hide-backend-slug-error"
                  role="alert"
                  className="mt-1 text-xs text-[var(--color-destructive)]"
                >
                  {slugError}
                </p>
              ) : null}
            </div>

            {/* Redirect field */}
            <div>
              <label
                htmlFor="hide-backend-redirect"
                className="block text-sm font-medium text-[var(--color-foreground)]"
              >
                Redirect canonical URLs to
                <span className="ml-1 text-xs font-normal text-[var(--color-muted-foreground)]">
                  (optional)
                </span>
              </label>
              <p
                id="hide-backend-redirect-help"
                className="mt-0.5 text-xs text-[var(--color-muted-foreground)]"
              >
                Where to send logged-out visitors who reach the canonical
                wp-login.php or wp-admin. Leave blank to return 404.
              </p>
              <input
                id="hide-backend-redirect"
                type="text"
                value={policy.hide_backend_redirect}
                onChange={(e) => handleRedirectChange(e.target.value)}
                disabled={disabled}
                placeholder="/home"
                aria-describedby={
                  redirectError
                    ? "hide-backend-redirect-error"
                    : "hide-backend-redirect-help"
                }
                aria-invalid={redirectError !== null ? "true" : undefined}
                className={[
                  "mt-2 block h-9 w-full max-w-sm rounded-md border px-3 text-sm",
                  "border-[var(--color-border)] bg-[var(--color-background)]",
                  "text-[var(--color-foreground)] placeholder:text-[var(--color-muted-foreground)]",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
                  disabled ? "cursor-not-allowed opacity-50" : "",
                  redirectError ? "border-[var(--color-destructive)]" : "",
                ].join(" ")}
              />
              {redirectError ? (
                <p
                  id="hide-backend-redirect-error"
                  role="alert"
                  className="mt-1 text-xs text-[var(--color-destructive)]"
                >
                  {redirectError}
                </p>
              ) : null}
            </div>

            {/* Lockout-recovery reminder */}
            <div className="flex items-start gap-3 rounded-lg border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/8 px-4 py-3">
              <ShieldCheck
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
              />
              <p className="text-xs text-[var(--color-foreground)]">
                Record the login slug before saving. If the slug is lost,
                operators can still reach the site via autologin from this
                dashboard, which bypasses the hidden-login gate.
              </p>
            </div>
          </div>
        ) : null}
      </SubSection> : null}

      {/* ── Agent-push caveat from last save ────────────────────────── */}
      {saveDetail ? (
        <div
          role="status"
          className="flex items-start gap-3 rounded-lg border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/8 px-4 py-3"
        >
          <AlertTriangle
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
          />
          <p className="text-sm text-[var(--color-foreground)]">{saveDetail}</p>
        </div>
      ) : null}

      {/* ── Save / viewer notice ─────────────────────────────────────── */}
      {canWrite ? (
        <div className="flex items-center gap-3 border-t border-[var(--color-border)] pt-6">
          <Button
            type="button"
            onClick={handleSave}
            disabled={update.isPending}
            aria-busy={update.isPending}
          >
            {update.isPending ? "Saving..." : SAVE_LABEL[section]}
          </Button>
        </div>
      ) : (
        <p className="text-xs text-[var(--color-muted-foreground)]">
          You have read-only access. Contact an owner or admin to change these
          settings.
        </p>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// SubSection — titled section within the policy form
// ---------------------------------------------------------------------------

function SubSection({
  id,
  title,
  children,
}: {
  id: string;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section aria-labelledby={id}>
      <h3
        id={id}
        className="mb-4 text-sm font-medium text-[var(--color-foreground)]"
      >
        {title}
      </h3>
      {children}
    </section>
  );
}

// ---------------------------------------------------------------------------
// ToggleRow — a labelled switch row (matches HardeningPanel's pattern)
// ---------------------------------------------------------------------------

function ToggleRow({
  id,
  label,
  help,
  checked,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  help: string;
  checked: boolean;
  onChange: () => void;
  disabled: boolean;
}) {
  const helpId = `${id}-help`;
  return (
    <div className="flex items-start justify-between gap-4 py-3 first:pt-0">
      <div className="min-w-0 flex-1">
        <label
          htmlFor={id}
          className="block text-sm font-medium text-[var(--color-foreground)]"
        >
          {label}
        </label>
        <p
          id={helpId}
          className="mt-0.5 text-xs text-[var(--color-muted-foreground)]"
        >
          {help}
        </p>
      </div>
      <Switch
        id={id}
        checked={checked}
        onCheckedChange={onChange}
        disabled={disabled}
        aria-describedby={helpId}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// CheckboxGroup — multi-select group rendered as chip-style checkboxes
// ---------------------------------------------------------------------------

function CheckboxGroup({
  id,
  legend,
  help,
  options,
  selected,
  onChange,
  disabled,
  className = "",
}: {
  id: string;
  legend: string;
  help?: string;
  options: { value: string; label: string }[];
  selected: string[];
  onChange: (value: string) => void;
  disabled: boolean;
  className?: string;
}) {
  const helpId = `${id}-help`;
  return (
    <fieldset className={className}>
      <legend className="text-sm font-medium text-[var(--color-foreground)]">
        {legend}
      </legend>
      {help ? (
        <p id={helpId} className="mt-0.5 text-xs text-[var(--color-muted-foreground)]">
          {help}
        </p>
      ) : null}
      <div className="mt-2 flex flex-wrap gap-2">
        {options.map((opt) => {
          const checked = selected.includes(opt.value);
          const inputId = `${id}-${opt.value}`;
          return (
            <label
              key={opt.value}
              htmlFor={inputId}
              className={[
                "inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border px-3 text-sm font-medium transition-colors",
                "focus-within:ring-2 focus-within:ring-[var(--color-ring)] focus-within:ring-offset-2",
                disabled ? "cursor-not-allowed opacity-50" : "",
                checked
                  ? "border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                  : "border-[var(--color-border)] bg-[var(--color-background)] text-[var(--color-foreground)] hover:bg-[var(--color-muted)]/40",
              ].join(" ")}
            >
              <input
                id={inputId}
                type="checkbox"
                checked={checked}
                onChange={() => onChange(opt.value)}
                disabled={disabled}
                aria-describedby={help ? helpId : undefined}
                className="sr-only"
              />
              {opt.label}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

// ---------------------------------------------------------------------------
// NumberField — a compact numeric input row
// ---------------------------------------------------------------------------

function NumberField({
  id,
  label,
  help,
  value,
  min,
  max,
  onChange,
  disabled,
  suffix,
}: {
  id: string;
  label: string;
  help: string;
  value: number;
  min: number;
  max: number;
  onChange: (v: number) => void;
  disabled: boolean;
  suffix?: string;
}) {
  const helpId = `${id}-help`;
  return (
    <div className="flex items-start justify-between gap-4 py-3 first:pt-0">
      <div className="min-w-0 flex-1">
        <label
          htmlFor={id}
          className="block text-sm font-medium text-[var(--color-foreground)]"
        >
          {label}
        </label>
        <p
          id={helpId}
          className="mt-0.5 text-xs text-[var(--color-muted-foreground)]"
        >
          {help}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <input
          id={id}
          type="number"
          min={min}
          max={max}
          value={value}
          onChange={(e) => {
            const n = parseInt(e.target.value, 10);
            if (!Number.isNaN(n) && n >= min && n <= max) {
              onChange(n);
            }
          }}
          disabled={disabled}
          aria-describedby={helpId}
          className={[
            "h-8 w-20 rounded-md border px-2 text-right text-sm",
            "border-[var(--color-border)] bg-[var(--color-background)]",
            "text-[var(--color-foreground)]",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]",
            disabled ? "cursor-not-allowed opacity-50" : "",
          ].join(" ")}
        />
        {suffix ? (
          <span className="text-xs text-[var(--color-muted-foreground)]">
            {suffix}
          </span>
        ) : null}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function PolicySkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading authentication policy"
      className="space-y-8"
    >
      <span className="sr-only">Loading authentication policy</span>
      {/* Three sub-section skeletons */}
      {[4, 4, 2].map((rows, idx) => (
        <div key={idx} className="space-y-4">
          <Skeleton className="h-3 w-32" />
          {Array.from({ length: rows }).map((_, i) => (
            <div key={i} className="flex items-center justify-between py-2">
              <div className="space-y-1.5">
                <Skeleton className="h-3.5 w-40" />
                <Skeleton className="h-3 w-64" />
              </div>
              <Skeleton className="h-5 w-9 rounded-full" />
            </div>
          ))}
        </div>
      ))}
      <Skeleton className="h-9 w-56 rounded-md" />
    </div>
  );
}

// ---------------------------------------------------------------------------
// WpRole export for tests
// ---------------------------------------------------------------------------

export type { WpRole, TfaMethod };
