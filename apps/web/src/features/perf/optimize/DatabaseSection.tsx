import { Database, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";

import { SelectField } from "../components/Field";
import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { useDbClean } from "../hooks/useCacheStats";
import { toast } from "@/components/toast";
import { DB_CLEAN_INTERVALS, type PerfConfig } from "../types";

// Database cleanup: scheduled auto-clean (+ interval) and the per-category
// cleanup toggles, plus a "Clean now" action that runs the configured cleanup
// immediately. The result lands via the db.clean.completed SSE event.

export interface DatabaseSectionProps {
  siteId: string;
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
  /** operator+ — can run "Clean now". */
  canOperate: boolean;
}

interface CleanToggle {
  key: keyof PerfConfig;
  label: string;
  description: string;
}

const TOGGLES: CleanToggle[] = [
  {
    key: "db_post_revisions",
    label: "Post revisions",
    description: "Remove stored revisions of posts and pages.",
  },
  {
    key: "db_post_auto_drafts",
    label: "Auto-drafts",
    description: "Remove abandoned auto-draft posts.",
  },
  {
    key: "db_post_trashed",
    label: "Trashed posts",
    description: "Permanently delete posts in the trash.",
  },
  {
    key: "db_comments_spam",
    label: "Spam comments",
    description: "Delete comments marked as spam.",
  },
  {
    key: "db_comments_trashed",
    label: "Trashed comments",
    description: "Permanently delete trashed comments.",
  },
  {
    key: "db_transients_expired",
    label: "Expired transients",
    description: "Clear expired transient cache entries.",
  },
  {
    key: "db_optimize_tables",
    label: "Optimize tables",
    description: "Run OPTIMIZE TABLE to reclaim space after cleanup.",
  },
];

export function DatabaseSection({
  siteId,
  config,
  save,
  disabled,
  saving,
  canOperate,
}: DatabaseSectionProps) {
  const clean = useDbClean(siteId);

  function runClean() {
    clean.mutate(undefined, {
      onSuccess: (res) => {
        if (res.ok) {
          toast.success("Database cleanup started.", {
            description:
              typeof res.rows_cleaned === "number"
                ? `${res.rows_cleaned.toLocaleString()} rows removed.`
                : "Cleaning up in the background.",
          });
        } else {
          toast.error("Could not clean the database.", {
            description: res.detail,
          });
        }
      },
    });
  }

  return (
    <SettingsCard
      title="Database cleanup"
      description="Trim revisions, trashed content, and expired data to keep queries fast."
      action={
        canOperate ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={runClean}
            disabled={clean.isPending}
          >
            {clean.isPending ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : (
              <Database aria-hidden="true" className="size-4" />
            )}
            Clean now
          </Button>
        ) : null
      }
    >
      <SettingRow
        label="Scheduled cleanup"
        description="Automatically run the selected cleanups on a schedule."
        checked={config.db_auto_clean}
        onChange={(v) => save({ db_auto_clean: v })}
        disabled={disabled}
        saving={saving}
      >
        <SelectField
          label="Cleanup interval"
          value={config.db_auto_clean_interval}
          options={DB_CLEAN_INTERVALS}
          onChange={(v) => save({ db_auto_clean_interval: v })}
          disabled={disabled}
        />
      </SettingRow>
      {TOGGLES.map((t) => (
        <SettingRow
          key={t.key}
          label={t.label}
          description={t.description}
          checked={Boolean(config[t.key])}
          onChange={(v) => save({ [t.key]: v })}
          disabled={disabled}
          saving={saving}
        />
      ))}
    </SettingsCard>
  );
}
