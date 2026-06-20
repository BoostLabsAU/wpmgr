import { useState } from "react";
import { AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import { toast } from "@/components/toast";

import {
  useHardeningConfig,
  useUpdateHardeningConfig,
  type HardeningConfig,
  type XmlrpcMode,
  type RestApiAccess,
  type LoginIdentifier,
} from "./use-hardening";

// Security Suite Phase 1 — Hardening config panel.
//
// Layout strategy: each sub-section is a <section> with an ARIA label and a
// plain-text sub-heading. Controls are disabled when `canWrite` is false
// (viewer role). A single "Save hardening settings" button at the bottom is
// shared across all sub-sections — avoiding per-section save buttons keeps the
// form model simpler and avoids partial-save confusion.
//
// The PUT response may include an optional `detail` caveat string (e.g.
// "wp-config.php not writable — runtime filter only"). This is surfaced as a
// subtle inline amber notice below the save button.
//
// Shell pattern: HardeningPanel → HardeningLoaded (keyed on config fields).

export function HardeningPanel({
  siteId,
  canWrite,
}: {
  siteId: string;
  canWrite: boolean;
}) {
  const { data, isPending, isError, error, refetch } =
    useHardeningConfig(siteId);

  if (isPending) {
    return <HardeningSkeleton />;
  }

  if (isError) {
    return (
      <PageError
        what="Could not load hardening config."
        why={error instanceof Error ? error.message : "Unknown error"}
        onRetry={() => void refetch()}
        retryLabel="Reload config"
      />
    );
  }

  if (!data) return null;

  // The GET returns the config FLAT (data IS the config). Key on it so the form
  // resets if a background refetch returns different server values (e.g. another
  // operator changed the config). The "not writable" caveat only arrives on save.
  const configKey = Object.entries(data)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => `${k}:${String(v)}`)
    .join("|");

  return (
    <HardeningLoaded
      key={configKey}
      siteId={siteId}
      initialConfig={data}
      canWrite={canWrite}
    />
  );
}

// ---------------------------------------------------------------------------
// Loaded form
// ---------------------------------------------------------------------------

interface LoadedProps {
  siteId: string;
  initialConfig: HardeningConfig;
  initialDetail?: string;
  canWrite: boolean;
}

function HardeningLoaded({
  siteId,
  initialConfig,
  initialDetail,
  canWrite,
}: LoadedProps) {
  const update = useUpdateHardeningConfig(siteId);

  const [config, setConfig] = useState<HardeningConfig>({ ...initialConfig });
  const [saveDetail, setSaveDetail] = useState<string | undefined>(
    initialDetail,
  );

  function toggle(field: keyof HardeningConfig) {
    setConfig((prev) => ({ ...prev, [field]: !prev[field] }));
  }

  function setXmlrpc(mode: XmlrpcMode) {
    setConfig((prev) => ({ ...prev, xmlrpc_mode: mode }));
  }

  function setRestApi(access: RestApiAccess) {
    setConfig((prev) => ({ ...prev, restrict_rest_api: access }));
  }

  function setLoginId(id: LoginIdentifier) {
    setConfig((prev) => ({ ...prev, restrict_login_identifier: id }));
  }

  function handleSave() {
    setSaveDetail(undefined);
    update.mutate(config, {
      onSuccess: (result) => {
        setSaveDetail(result.detail);
        toast.success("Hardening settings saved.", {
          description: result.detail
            ? `Note: ${result.detail}`
            : "Config applied to the site.",
        });
      },
      onError: (err: Error) => {
        toast.error("Could not save hardening settings.", {
          description: err.message,
        });
      },
    });
  }

  const disabled = !canWrite || update.isPending;

  return (
    <div className="space-y-8">
      {/* ── File & content ────────────────────────────────────────────── */}
      <SubSection id="hardening-file-content" title="File and content">
        <ToggleRow
          id="disable-file-editor"
          label="Disable file editor"
          help="Prevents editing plugin and theme files directly from wp-admin. Recommended for all production sites."
          checked={config.disable_file_editor}
          onChange={() => toggle("disable_file_editor")}
          disabled={disabled}
        />
        <ToggleRow
          id="disable-php-uploads"
          label="Block PHP in uploads"
          help="Prevents PHP scripts in the uploads directory from being executed — a common vector for uploaded shell injections."
          checked={config.disable_php_in_uploads}
          onChange={() => toggle("disable_php_in_uploads")}
          disabled={disabled}
        />
        <ToggleRow
          id="protect-system-files"
          label="Protect system files"
          help="Blocks direct HTTP access to wp-config.php, .htaccess, and similar files. Applied via server rules where possible; falls back to a runtime filter."
          checked={config.protect_system_files}
          onChange={() => toggle("protect_system_files")}
          disabled={disabled}
        />
        <ToggleRow
          id="disable-directory-browsing"
          label="Disable directory browsing"
          help="Prevents the web server from listing directory contents when no index file exists."
          checked={config.disable_directory_browsing}
          onChange={() => toggle("disable_directory_browsing")}
          disabled={disabled}
        />
      </SubSection>

      {/* ── XML-RPC ───────────────────────────────────────────────────── */}
      <SubSection id="hardening-xmlrpc" title="XML-RPC">
        <p className="mb-3 text-xs text-[var(--color-muted-foreground)]">
          XML-RPC is a legacy remote-publishing interface. Keeping it fully
          active exposes the site to amplification and brute-force attacks. Use
          Limited mode if Jetpack or a mobile app requires it.
        </p>
        <SegmentedControl<XmlrpcMode>
          id="xmlrpc-mode"
          legend="XML-RPC mode"
          options={[
            { value: "on", label: "On" },
            { value: "limited", label: "Limited" },
            { value: "off", label: "Off" },
          ]}
          value={config.xmlrpc_mode}
          onChange={setXmlrpc}
          disabled={disabled}
          helpText={{
            on: "All XML-RPC methods are enabled (default WordPress behaviour).",
            limited:
              "Only basic ping and Jetpack system calls are allowed; brute-force methods are blocked.",
            off: "XML-RPC endpoint is disabled entirely.",
          }}
        />
      </SubSection>

      {/* ── REST API ──────────────────────────────────────────────────── */}
      <SubSection id="hardening-rest-api" title="REST API">
        <p className="mb-3 text-xs text-[var(--color-muted-foreground)]">
          The WordPress REST API is required by the block editor and many
          plugins. Restricting it adds a layer of obscurity for unauthenticated
          user enumeration endpoints.
        </p>
        <SegmentedControl<RestApiAccess>
          id="rest-api-access"
          legend="REST API access"
          options={[
            { value: "default", label: "Default" },
            { value: "restricted", label: "Restricted" },
          ]}
          value={config.restrict_rest_api}
          onChange={setRestApi}
          disabled={disabled}
          helpText={{
            default:
              "Unauthenticated access to all public REST endpoints is permitted.",
            restricted:
              "Unauthenticated requests to the /wp-json/ namespace are blocked; logged-in requests and the block editor are unaffected.",
          }}
        />
      </SubSection>

      {/* ── Login ─────────────────────────────────────────────────────── */}
      <SubSection id="hardening-login" title="Login">
        <div className="space-y-6">
          <div>
            <p className="mb-3 text-xs text-[var(--color-muted-foreground)]">
              Control which identifiers are accepted on the login form to reduce
              username enumeration risk.
            </p>
            <SegmentedControl<LoginIdentifier>
              id="login-identifier"
              legend="Login identifier"
              options={[
                { value: "username", label: "Username" },
                { value: "email", label: "Email" },
                { value: "both", label: "Both" },
              ]}
              value={config.restrict_login_identifier}
              onChange={setLoginId}
              disabled={disabled}
              helpText={{
                username: "Only usernames are accepted on the login form.",
                email: "Only email addresses are accepted on the login form.",
                both: "Both usernames and email addresses are accepted (WordPress default).",
              }}
            />
          </div>

          <ToggleRow
            id="force-unique-nickname"
            label="Require unique display names"
            help="Prevents two users from sharing the same nickname, which blocks a technique where an attacker sets their display name to match an admin to cause confusion."
            checked={config.force_unique_nickname}
            onChange={() => toggle("force_unique_nickname")}
            disabled={disabled}
          />
          <ToggleRow
            id="disable-author-archive"
            label="Disable author archive enumeration"
            help="Blocks the ?author=N query string, which can reveal WordPress usernames to unauthenticated visitors."
            checked={config.disable_author_archive_enum}
            onChange={() => toggle("disable_author_archive_enum")}
            disabled={disabled}
          />
        </div>
      </SubSection>

      {/* ── Transport ─────────────────────────────────────────────────── */}
      <SubSection id="hardening-transport" title="Transport">
        <ToggleRow
          id="force-ssl"
          label="Force HTTPS"
          help="Redirects all HTTP requests to HTTPS and sets the HTTPS constant for WordPress. Only enable when an SSL certificate is active — enabling without a cert will lock you out."
          checked={config.force_ssl}
          onChange={() => toggle("force_ssl")}
          disabled={disabled}
        />
      </SubSection>

      {/* ── Caveat notice from last save ─────────────────────────────── */}
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

      {/* ── Save ──────────────────────────────────────────────────────── */}
      {canWrite ? (
        <div className="flex items-center gap-3 border-t border-[var(--color-border)] pt-6">
          <Button
            type="button"
            onClick={handleSave}
            disabled={update.isPending}
            aria-busy={update.isPending}
          >
            {update.isPending ? "Saving..." : "Save hardening settings"}
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
// SubSection — a titled section within the hardening form
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
        className="mb-4 text-xs font-medium uppercase tracking-wide text-[var(--color-muted-foreground)]"
      >
        {title}
      </h3>
      {children}
    </section>
  );
}

// ---------------------------------------------------------------------------
// ToggleRow — a labelled switch row
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
// SegmentedControl — radio group rendered as pill buttons
// ---------------------------------------------------------------------------

function SegmentedControl<T extends string>({
  id,
  legend,
  options,
  value,
  onChange,
  disabled,
  helpText,
}: {
  id: string;
  legend: string;
  options: { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  disabled: boolean;
  helpText?: Partial<Record<T, string>>;
}) {
  return (
    <fieldset>
      <legend className="sr-only">{legend}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((opt) => (
          <label key={opt.value} className="cursor-pointer">
            <input
              type="radio"
              name={id}
              value={opt.value}
              checked={value === opt.value}
              onChange={() => onChange(opt.value)}
              disabled={disabled}
              className="sr-only"
            />
            <span
              className={[
                "inline-flex h-8 items-center rounded-md border px-3 text-sm font-medium transition-colors",
                "focus-within:ring-2 focus-within:ring-[var(--color-ring)] focus-within:ring-offset-2",
                disabled ? "cursor-not-allowed opacity-50" : "cursor-pointer",
                value === opt.value
                  ? "border-[var(--color-primary)] bg-[var(--color-primary)] text-white"
                  : "border-[var(--color-border)] bg-[var(--color-background)] text-[var(--color-foreground)] hover:bg-[var(--color-muted)]/40",
              ].join(" ")}
            >
              {opt.label}
            </span>
          </label>
        ))}
      </div>
      {helpText?.[value] ? (
        <p className="mt-2 text-xs text-[var(--color-muted-foreground)]">
          {helpText[value]}
        </p>
      ) : null}
    </fieldset>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

function HardeningSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading hardening settings"
      className="space-y-8"
    >
      <span className="sr-only">Loading hardening settings</span>
      {/* Sub-section skeletons */}
      {[5, 3, 2, 3, 1].map((rows, idx) => (
        <div key={idx} className="space-y-4">
          <Skeleton className="h-3 w-24" />
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
      <Skeleton className="h-9 w-48 rounded-md" />
    </div>
  );
}
