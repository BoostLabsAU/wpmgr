import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Font loading optimization: font-display swap, self-host Google Fonts,
// preload critical fonts, WOFF2 transcoding, and subset production (Phase 2).

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
  // fonts_subset requires fonts_transcode_woff2 to be on. The API accepts it
  // independently (per the woo_cacheable_session precedent), but the toggle is
  // disabled until the prerequisite is on so the UX is clear.
  const subsetGated = !config.fonts_transcode_woff2;

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
      <SettingRow
        label="Subset fonts (experimental)"
        description={
          subsetGated
            ? "Requires WOFF2 conversion to be on. Once enabled, a subset WOFF2 restricted to the latin-ext unicode range is produced alongside the full WOFF2, typically cutting size by another 60 to 90 percent. Variable fonts and icon fonts are skipped automatically. OpenType shaping features such as ligatures and small-caps are not preserved in the subset."
            : "Produce a subset WOFF2 restricted to the latin-ext unicode range alongside the full WOFF2. Typically cuts size by another 60 to 90 percent. Variable fonts and icon fonts are skipped automatically. OpenType shaping features such as ligatures and small-caps are not preserved in the subset; the full WOFF2 remains as a browser fallback for any out-of-range codepoints."
        }
        checked={config.fonts_subset ?? false}
        onChange={(v) => save({ fonts_subset: v })}
        disabled={disabled || subsetGated}
        saving={saving}
      />
    </SettingsCard>
  );
}
