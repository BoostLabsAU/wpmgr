import { useState } from "react";

import { SelectField, TextField } from "../components/Field";
import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { CDN_FILE_TYPES, CDN_PROVIDERS, type PerfConfig } from "../types";

// CDN delivery: enable + base URL + file-type scope + provider + write-only
// credentials.
//
// SECURITY (load-bearing): the stored CDN credential value is NEVER rendered.
// The GET config shape carries no secret — only `cdn_has_credentials` (a bool).
// We hold the operator's NEW token in local component state and send it as
// `cdn_credentials` ONLY when they type one. The token input always starts
// EMPTY; when one is already stored the placeholder reads "••• set" so the
// operator knows there's a value without it ever being shown. Leaving the field
// blank on save sends no `cdn_credentials` → the stored secret is unchanged.
//
// CDN ENABLE FLOW (draft-enable to prevent 422):
//
// The server requires cdn_url to be a valid http/https URL whenever
// cdn_enabled=true. SettingRow hides its children while unchecked, so the old
// auto-save-on-toggle fired a PUT with an empty cdn_url and got a 422 with no
// inline feedback.
//
// New flow:
//   Toggle ON, no stored url  -> enter `enabling` draft state; reveal + focus
//                                the URL field; DO NOT save yet.
//   Toggle ON, stored url set -> save({ cdn_enabled: true }) immediately.
//   Toggle OFF                -> exit draft; if was enabled, save({cdn_enabled:false}).
//
// While enabling: URL field commit (blur) or the "Enable CDN" button validates
// client-side (parseable http/https URL with a non-empty host, matching
// service.go:349), then sends ONE atomic patch {cdn_enabled:true, cdn_url}.
// A server 422 surfaces inline on the URL field rather than only as a toast.
// Navigating away mid-draft is safe — nothing was saved.

export interface CdnSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>, onError?: (err: Error) => void) => void;
  disabled: boolean;
  isSaving: (key: string) => boolean;
}

/**
 * Validates that `url` is a parseable http/https URL with a non-empty host.
 * Mirrors service.go:349 exactly so inline validation matches server rules.
 */
function isValidCdnUrl(url: string): boolean {
  try {
    const parsed = new URL(url.trim());
    return (
      (parsed.protocol === "http:" || parsed.protocol === "https:") &&
      parsed.host.length > 0
    );
  } catch {
    return false;
  }
}

export function CdnSection({ config, save, disabled, isSaving }: CdnSectionProps) {
  // Local-only draft of the NEW credential value. Never seeded from the config
  // (the config never contains it). Cleared after a successful send.
  const [apiToken, setApiToken] = useState("");
  const [zoneId, setZoneId] = useState("");
  const [zone, setZone] = useState("");

  // Draft CDN URL — resyncs from config on external cache changes (optimistic
  // update or rollback), mirroring NumberField's render-time resync pattern
  // (Field.tsx:98-102).
  const [cdnUrl, setCdnUrl] = useState(config.cdn_url);
  const [lastConfigUrl, setLastConfigUrl] = useState(config.cdn_url);
  if (config.cdn_url !== lastConfigUrl) {
    setLastConfigUrl(config.cdn_url);
    setCdnUrl(config.cdn_url);
  }

  // Draft-enable state: true while the operator has toggled ON but has not yet
  // submitted a valid URL. No mutation is in-flight during this phase.
  const [enabling, setEnabling] = useState(false);

  // Inline URL field error (client validation or server 422).
  const [urlError, setUrlError] = useState<string | undefined>();

  function saveCredentials() {
    const token = apiToken.trim();
    if (!token) return; // blank → leave the stored secret unchanged
    save({
      cdn_credentials: {
        api_token: token,
        zone_id: zoneId.trim() || undefined,
        zone: zone.trim() || undefined,
      },
    });
    // Clear the draft so the secret never lingers in the DOM/state after send.
    setApiToken("");
    setZoneId("");
    setZone("");
  }

  function handleToggle(v: boolean) {
    if (v) {
      // Turning ON
      if (!config.cdn_url) {
        // No stored URL: enter draft mode — reveal the field, do NOT save yet.
        // The URL TextField mounts for the first time with autoFocus={true}.
        setEnabling(true);
        setUrlError(undefined);
      } else {
        // Stored URL exists: safe to save immediately.
        save({ cdn_enabled: true });
      }
    } else {
      // Turning OFF: exit draft, save only if it was persisted as enabled.
      setEnabling(false);
      setUrlError(undefined);
      if (config.cdn_enabled) {
        save({ cdn_enabled: false });
      }
    }
  }

  /**
   * Attempt to commit the CDN URL when the field blurs during the draft-enable
   * flow. Validates client-side; if valid, sends the atomic enable+URL patch.
   * Does NOT fire when CDN is already enabled (handled by the existing per-field
   * auto-save below).
   */
  function commitEnablingUrl(url: string) {
    const trimmed = url.trim();
    if (!trimmed) {
      setUrlError("Enter your CDN URL to finish enabling.");
      return;
    }
    if (!isValidCdnUrl(trimmed)) {
      setUrlError("Enter a valid http or https URL (e.g. https://cdn.example.com).");
      return;
    }
    setUrlError(undefined);
    save({ cdn_enabled: true, cdn_url: trimmed }, (err) => {
      setUrlError(err.message);
    });
    setEnabling(false);
  }

  /**
   * Commit handler for the URL field when CDN is already enabled.
   * Validates before firing to prevent a guaranteed 422 on empty/invalid input.
   */
  function commitExistingUrl(url: string) {
    const trimmed = url.trim();
    if (!trimmed || !isValidCdnUrl(trimmed)) {
      setUrlError(
        trimmed
          ? "Enter a valid http or https URL (e.g. https://cdn.example.com)."
          : "CDN URL is required while CDN is enabled.",
      );
      return;
    }
    setUrlError(undefined);
    save({ cdn_url: trimmed }, (err) => {
      setUrlError(err.message);
    });
  }

  // The children slot is revealed when the toggle is on OR when we are in the
  // draft-enable state (`enabling`). We use the additive `open` prop on
  // SettingRow so all other ~30 rows that pass neither `open` nor children are
  // completely unaffected (SettingRow.tsx default: open=false).
  const slotOpen = config.cdn_enabled || enabling;

  // isSaving("cdn_enabled") covers the immediate-enable path (stored URL).
  // During the draft phase no mutation is in-flight, so no spurious spinner.
  const enablingPending = isSaving("cdn_enabled");

  return (
    <SettingsCard
      title="CDN"
      description="Serve static assets from a content delivery network."
    >
      <SettingRow
        label="Enable CDN"
        description="Rewrite static asset URLs to your CDN host."
        checked={config.cdn_enabled || enabling}
        onChange={handleToggle}
        disabled={disabled || enablingPending}
        saving={enablingPending}
        open={slotOpen}
      >
        <div className="space-y-4">
          {enabling && (
            <p className="text-xs text-muted-foreground">
              Enter your CDN URL to finish enabling.
            </p>
          )}
          <TextField
            label="CDN URL"
            type="url"
            value={cdnUrl}
            onChange={(v) => {
              setCdnUrl(v);
              if (urlError) setUrlError(undefined);
            }}
            onCommit={enabling ? commitEnablingUrl : commitExistingUrl}
            placeholder="https://cdn.example.com"
            disabled={disabled}
            hint={!urlError ? "Assets are rewritten to this host." : undefined}
            error={urlError}
            autoFocus={enabling}
          />

          {/* Enable CDN button — shown only during the draft phase, mirrors the
              "Save credentials" button pattern at line 122-131. */}
          {enabling && (
            <div>
              <button
                type="button"
                onClick={() => commitEnablingUrl(cdnUrl)}
                disabled={disabled || !cdnUrl.trim()}
                className="inline-flex h-8 items-center rounded-md border border-border bg-background px-3 text-xs font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                Enable CDN
              </button>
            </div>
          )}

          <SelectField
            label="File types"
            value={config.cdn_file_types}
            options={CDN_FILE_TYPES}
            onChange={(v) => save({ cdn_file_types: v })}
            disabled={disabled}
          />
          <SelectField
            label="Provider"
            value={config.cdn_provider}
            options={CDN_PROVIDERS}
            onChange={(v) => save({ cdn_provider: v })}
            disabled={disabled}
            hint="Used for edge-cache purges. Only these providers are supported."
          />

          {/* Write-only credentials — the stored value is never shown. */}
          <div className="space-y-4 rounded-lg border border-border bg-background p-3">
            <p className="text-xs text-muted-foreground">
              {config.cdn_has_credentials
                ? "API credentials are set. Enter a new value only to replace them — leave blank to keep the current credentials."
                : "Enter your provider API credentials. They're stored encrypted and never shown again."}
            </p>
            <TextField
              label="API token"
              type="password"
              value={apiToken}
              onChange={setApiToken}
              placeholder={config.cdn_has_credentials ? "••• set" : "API token"}
              disabled={disabled}
              ariaLabel="CDN API token (write-only)"
            />
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField
                label="Zone ID"
                value={zoneId}
                onChange={setZoneId}
                placeholder="optional"
                disabled={disabled}
              />
              <TextField
                label="Zone"
                value={zone}
                onChange={setZone}
                placeholder="optional"
                disabled={disabled}
              />
            </div>
            <div>
              <button
                type="button"
                onClick={saveCredentials}
                disabled={disabled || apiToken.trim() === ""}
                className="inline-flex h-8 items-center rounded-md border border-border bg-background px-3 text-xs font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                Save credentials
              </button>
            </div>
          </div>
        </div>
      </SettingRow>
    </SettingsCard>
  );
}
