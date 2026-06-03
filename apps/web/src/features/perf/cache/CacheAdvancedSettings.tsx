import { ChipInput } from "../components/ChipInput";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Advanced cache rules: bypass lists (never cache these) + include lists (cache
// despite a query string / cookie). All four are chip inputs that autosave the
// whole array on every change.

export interface CacheAdvancedSettingsProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
}

export function CacheAdvancedSettings({
  config,
  save,
  disabled,
}: CacheAdvancedSettingsProps) {
  return (
    <SettingsCard
      title="Advanced cache rules"
      description="Fine-tune what bypasses the cache and what gets cached despite query strings or cookies."
    >
      <div className="grid gap-5 px-5 py-4 sm:grid-cols-2">
        <ChipInput
          label="Bypass URLs"
          description="Pages that must never be cached (e.g. /cart, /checkout)."
          values={config.cache_bypass_urls}
          onChange={(v) => save({ cache_bypass_urls: v })}
          placeholder="/checkout"
          disabled={disabled}
        />
        <ChipInput
          label="Bypass cookies"
          description="Skip the cache when one of these cookies is present."
          values={config.cache_bypass_cookies}
          onChange={(v) => save({ cache_bypass_cookies: v })}
          placeholder="wordpress_logged_in"
          disabled={disabled}
        />
        <ChipInput
          label="Include query strings"
          description="Cache separate copies for these query parameters instead of bypassing."
          values={config.cache_include_queries}
          onChange={(v) => save({ cache_include_queries: v })}
          placeholder="lang"
          disabled={disabled}
        />
        <ChipInput
          label="Include cookies"
          description="Cache separate copies keyed on these cookies."
          values={config.cache_include_cookies}
          onChange={(v) => save({ cache_include_cookies: v })}
          placeholder="currency"
          disabled={disabled}
        />
      </div>
    </SettingsCard>
  );
}
