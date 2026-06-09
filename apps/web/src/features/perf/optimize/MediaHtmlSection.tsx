import { ChipInput } from "../components/ChipInput";
import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Media & HTML optimization: lazy-load (+ exclusions), explicit width/height,
// YouTube facade, and self-hosted gravatars.

export interface MediaHtmlSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  isSaving: (key: string) => boolean;
}

export function MediaHtmlSection({
  config,
  save,
  disabled,
  isSaving,
}: MediaHtmlSectionProps) {
  return (
    <SettingsCard
      title="Media & HTML"
      description="Defer offscreen images and trim layout shift."
    >
      <SettingRow
        label="Lazy-load images & iframes"
        description="Load offscreen images and iframes only as they scroll into view."
        checked={config.lazy_load}
        onChange={(v) => save({ lazy_load: v })}
        disabled={disabled || isSaving("lazy_load")}
        saving={isSaving("lazy_load")}
      >
        <ChipInput
          label="Lazy-load exclusions"
          description="Images that should load eagerly (e.g. your above-the-fold hero)."
          values={config.lazy_load_exclusions}
          onChange={(v) => save({ lazy_load_exclusions: v })}
          placeholder="hero.jpg"
          disabled={disabled}
        />
      </SettingRow>
      <SettingRow
        label="Add image dimensions"
        description="Set width and height on images to reserve space and reduce layout shift."
        checked={config.properly_size_images}
        onChange={(v) => save({ properly_size_images: v })}
        disabled={disabled || isSaving("properly_size_images")}
        saving={isSaving("properly_size_images")}
      />
      <SettingRow
        label="YouTube facade"
        description="Replace embedded YouTube players with a lightweight preview until clicked."
        checked={config.youtube_placeholder}
        onChange={(v) => save({ youtube_placeholder: v })}
        disabled={disabled || isSaving("youtube_placeholder")}
        saving={isSaving("youtube_placeholder")}
      />
      <SettingRow
        label="Self-host gravatars"
        description="Cache Gravatar avatars locally to avoid the extra third-party request."
        checked={config.self_host_gravatars}
        onChange={(v) => save({ self_host_gravatars: v })}
        disabled={disabled || isSaving("self_host_gravatars")}
        saving={isSaving("self_host_gravatars")}
      />
    </SettingsCard>
  );
}
