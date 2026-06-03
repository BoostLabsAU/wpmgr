import { useState } from "react";

import { SelectField, TextField } from "../components/Field";
import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { CDN_FILE_TYPES, type PerfConfig } from "../types";

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

export interface CdnSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
}

export function CdnSection({ config, save, disabled, saving }: CdnSectionProps) {
  // Local-only draft of the NEW credential value. Never seeded from the config
  // (the config never contains it). Cleared after a successful send.
  const [apiToken, setApiToken] = useState("");
  const [zoneId, setZoneId] = useState("");
  const [zone, setZone] = useState("");
  const [cdnUrl, setCdnUrl] = useState(config.cdn_url);

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

  return (
    <SettingsCard
      title="CDN"
      description="Serve static assets from a content delivery network."
    >
      <SettingRow
        label="Enable CDN"
        description="Rewrite static asset URLs to your CDN host."
        checked={config.cdn_enabled}
        onChange={(v) => save({ cdn_enabled: v })}
        disabled={disabled}
        saving={saving}
      >
        <div className="space-y-4">
          <TextField
            label="CDN URL"
            type="url"
            value={cdnUrl}
            onChange={setCdnUrl}
            onCommit={(v) => save({ cdn_url: v.trim() })}
            placeholder="https://cdn.example.com"
            disabled={disabled}
            hint="Assets are rewritten to this host."
          />
          <SelectField
            label="File types"
            value={config.cdn_file_types}
            options={CDN_FILE_TYPES}
            onChange={(v) => save({ cdn_file_types: v })}
            disabled={disabled}
          />
          <TextField
            label="Provider"
            value={config.cdn_provider}
            onChange={(v) => save({ cdn_provider: v })}
            onCommit={(v) => save({ cdn_provider: v.trim() })}
            placeholder="cloudflare"
            disabled={disabled}
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
