import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Font loading optimization: font-display swap, self-host Google Fonts, and
// preload critical fonts.

export interface FontsSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
}

export function FontsSection({
  config,
  save,
  disabled,
  saving,
}: FontsSectionProps) {
  return (
    <SettingsCard
      title="Fonts"
      description="Keep text visible while web fonts load and trim external font requests."
    >
      <SettingRow
        label="Swap font display"
        description="Show fallback text immediately and swap in the web font when ready (font-display: swap)."
        checked={config.fonts_display_swap}
        onChange={(v) => save({ fonts_display_swap: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Optimize Google Fonts"
        description="Self-host and combine Google Fonts to remove the extra third-party connection."
        checked={config.fonts_optimize_google}
        onChange={(v) => save({ fonts_optimize_google: v })}
        disabled={disabled}
        saving={saving}
      />
      <SettingRow
        label="Preload fonts"
        description="Preload the fonts used above the fold so headings don't reflow."
        checked={config.fonts_preload}
        onChange={(v) => save({ fonts_preload: v })}
        disabled={disabled}
        saving={saving}
      />
    </SettingsCard>
  );
}
