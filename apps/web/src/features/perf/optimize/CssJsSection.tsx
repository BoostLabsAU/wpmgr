import { Info } from "lucide-react";

import { ChipInput } from "../components/ChipInput";
import { SelectField } from "../components/Field";
import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { JS_DELAY_METHODS, type PerfConfig } from "../types";

// CSS & JavaScript optimization: minify, Remove Unused CSS (+ safelist), self-
// hosting third-party assets, and JS delay (method + excludes + third-party).

export interface CssJsSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
}

export function CssJsSection({
  config,
  save,
  disabled,
  saving,
}: CssJsSectionProps) {
  return (
    <SettingsCard
      title="CSS & JavaScript"
      description="Shrink and defer styles and scripts so pages render sooner."
    >
      {config.cache_enabled ? (
        <div className="flex items-start gap-2 border-b border-border px-5 py-3">
          <Info aria-hidden="true" className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
          <p className="text-xs text-muted-foreground">
            After changing optimization settings, Purge and Preload the cache so
            new entries are written with the updated optimizations applied.
            Minify only applies to freshly-written cache entries.
          </p>
        </div>
      ) : null}
      <SettingRow
        label="Minify CSS & JavaScript"
        description="Strip whitespace and comments from served CSS and JS."
        checked={config.css_js_minify}
        onChange={(v) => save({ css_js_minify: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Remove unused CSS"
        description="Compute the used CSS per page structure and inline only what's needed (RUCSS)."
        checked={config.css_rucss}
        onChange={(v) => save({ css_rucss: v })}
        disabled={disabled}
        saving={saving}
        applying={config.css_rucss && saving}
      >
        <ChipInput
          label="Safelist selectors"
          description="Selectors always kept, even if unused in the rendered page (e.g. classes JavaScript adds at runtime). Common sliders, lightboxes and runtime state classes (splide, swiper, slick, is-active…) are protected automatically — add only extras your theme needs."
          values={config.css_rucss_include_selectors}
          onChange={(v) => save({ css_rucss_include_selectors: v })}
          placeholder=".is-active"
          disabled={disabled}
        />
      </SettingRow>
      <SettingRow
        label="Self-host third-party assets"
        description="Copy known third-party scripts (e.g. analytics) locally to cut extra connections."
        checked={config.css_js_self_host_third_party}
        onChange={(v) => save({ css_js_self_host_third_party: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Delay JavaScript"
        description="Hold non-critical scripts until the page is interactive or the user interacts."
        checked={config.js_delay}
        onChange={(v) => save({ js_delay: v })}
        disabled={disabled}
        saving={saving}
      >
        <div className="space-y-4">
          <SelectField
            label="Delay method"
            value={config.js_delay_method}
            options={JS_DELAY_METHODS}
            onChange={(v) => save({ js_delay_method: v })}
            disabled={disabled}
          />
          <ChipInput
            label="Exclude scripts"
            description="Script handles or URL fragments to keep loading normally."
            values={config.js_delay_excludes}
            onChange={(v) => save({ js_delay_excludes: v })}
            placeholder="jquery"
            disabled={disabled}
          />
          <div className="border-t border-border pt-3">
            <SettingRow
              label="Delay third-party scripts"
              description="Also delay externally hosted scripts (analytics, chat widgets)."
              checked={config.js_delay_third_party}
              onChange={(v) => save({ js_delay_third_party: v })}
              disabled={disabled}
              saving={saving}
            >
              <ChipInput
                label="Exclude third-party scripts"
                values={config.js_delay_third_party_excludes}
                onChange={(v) =>
                  save({ js_delay_third_party_excludes: v })
                }
                placeholder="googletagmanager.com"
                disabled={disabled}
              />
            </SettingRow>
          </div>
        </div>
      </SettingRow>
    </SettingsCard>
  );
}
