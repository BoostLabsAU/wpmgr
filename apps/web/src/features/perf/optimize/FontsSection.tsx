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
        label="Self-host Google Fonts"
        description="Download and serve Google Fonts from your own server to remove the extra third-party connection."
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
      <SettingRow
        label="Convert fonts to WOFF2"
        description="Transcode self-hosted fonts (TTF, OTF, WOFF) to WOFF2, the modern compressed format, and serve them with the original as a fallback. Typically 50 to 65 percent smaller for TTF and OTF fonts. Conversion happens in the background; the original font is served until the WOFF2 is ready, so pages never wait."
        checked={config.fonts_transcode_woff2}
        onChange={(v) => save({ fonts_transcode_woff2: v })}
        disabled={disabled}
        saving={saving}
      />
    </SettingsCard>
  );
}
