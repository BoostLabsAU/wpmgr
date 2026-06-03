import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { SelectField } from "../components/Field";
import { CACHE_REFRESH_INTERVALS, type PerfConfig } from "../types";

// Basic caching behaviour: who/what gets cached + scheduled refresh + link
// prefetch. Each toggle autosaves through `save` (optimistic PUT in the parent).

export interface CacheBasicSettingsProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
}

export function CacheBasicSettings({
  config,
  save,
  disabled,
  saving,
}: CacheBasicSettingsProps) {
  return (
    <SettingsCard
      title="Caching behaviour"
      description="Control who gets cached pages and how often the cache refreshes."
    >
      <SettingRow
        label="Cache logged-in users"
        description="Serve cached pages to authenticated visitors too. Leave off if your theme shows per-user content."
        checked={config.cache_logged_in}
        onChange={(v) => save({ cache_logged_in: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Separate mobile cache"
        description="Build a distinct cache for mobile user agents. Enable only if your site serves different mobile markup."
        checked={config.cache_mobile}
        onChange={(v) => save({ cache_mobile: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Scheduled cache refresh"
        description="Periodically rebuild the cache so visitors rarely hit a cold page."
        checked={config.cache_refresh}
        onChange={(v) => save({ cache_refresh: v })}
        disabled={disabled}
        saving={saving}
      >
        <SelectField
          label="Refresh interval"
          value={config.cache_refresh_interval}
          options={CACHE_REFRESH_INTERVALS}
          onChange={(v) => save({ cache_refresh_interval: v })}
          disabled={disabled}
        />
      </SettingRow>
      <SettingRow
        label="Link prefetch"
        description="Prefetch internal links on hover so the next page loads instantly."
        checked={config.cache_link_prefetch}
        onChange={(v) => save({ cache_link_prefetch: v })}
        disabled={disabled}
        saving={saving}
      />
    </SettingsCard>
  );
}
