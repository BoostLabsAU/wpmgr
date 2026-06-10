import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Bloat removal: nine toggles that disable WordPress features many sites don't
// need. Each maps to a boolean column; on = the feature is removed.

export interface BloatSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  isSaving: (key: string) => boolean;
}

interface BloatToggle {
  key: keyof PerfConfig;
  label: string;
  description: string;
}

const TOGGLES: BloatToggle[] = [
  {
    key: "bloat_disable_block_css",
    label: "Remove block editor CSS",
    description: "Drop the Gutenberg block library stylesheet on the front end if you don't use blocks.",
  },
  {
    key: "bloat_disable_dashicons",
    label: "Remove Dashicons",
    description: "Stop loading the admin icon font for logged-out visitors.",
  },
  {
    key: "bloat_disable_emojis",
    label: "Remove emoji script",
    description: "Drop the legacy emoji detection script and styles.",
  },
  {
    key: "bloat_disable_jquery_migrate",
    label: "Remove jQuery Migrate",
    description: "Skip the legacy jQuery compatibility shim.",
  },
  {
    key: "bloat_disable_xml_rpc",
    label: "Disable XML-RPC",
    description: "Close the XML-RPC endpoint (a common brute-force target).",
  },
  {
    key: "bloat_disable_rss_feed",
    label: "Disable RSS feeds",
    description: "Remove the default RSS / Atom feed endpoints.",
  },
  {
    key: "bloat_disable_oembeds",
    label: "Disable oEmbeds",
    description: "Stop auto-embedding external content and its discovery script.",
  },
  {
    key: "bloat_heartbeat_control",
    label: "Throttle Heartbeat API",
    description: "Slow the admin-ajax heartbeat to reduce background requests.",
  },
  {
    key: "bloat_post_revisions_control",
    label: "Limit post revisions",
    description: "Cap stored post revisions to keep the database lean.",
  },
];

export function BloatSection({
  config,
  save,
  disabled,
  isSaving,
}: BloatSectionProps) {
  return (
    <SettingsCard
      title="Bloat removal"
      description="Disable WordPress features you don't use to cut requests and weight."
    >
      {TOGGLES.map((t) => (
        <SettingRow
          key={t.key}
          label={t.label}
          description={t.description}
          checked={Boolean(config[t.key])}
          onChange={(v) => save({ [t.key]: v })}
          disabled={disabled || isSaving(t.key)}
          saving={isSaving(t.key)}
        />
      ))}
    </SettingsCard>
  );
}
