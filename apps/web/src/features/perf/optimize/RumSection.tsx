import { useId, useState } from "react";
import { useParams } from "@tanstack/react-router";
import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  KeyRound,
  Loader2,
  RefreshCw,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import { useReprovisionRumBeaconKey } from "../hooks/usePerfConfig";
import type { PerfConfig } from "../types";

// Real User Monitoring (RUM) settings section.
//
// Mirrors FontsSection: a SettingsCard with one or more SettingRows, each
// saving through the perf-config PUT (the parent OptimizeTab calls `save`).
//
// Why off by default: enabling RUM turns on a visitor data flow -- the
// collector injects a small script into every cached page that sends Core Web
// Vitals from the site visitor's browser to this control plane. Operators must
// make an active choice to enable it and disclose the data flow in their own
// site privacy policy.
//
// The sample-rate control appears only when rum_enabled is on, matching the
// FontsSection pattern of revealing dependent settings under the parent toggle.

export interface RumSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  isSaving: (key: string) => boolean;
}

export function RumSection({
  config,
  save,
  disabled,
  isSaving,
}: RumSectionProps) {
  const params = useParams({ strict: false });
  const siteId = typeof params.siteId === "string" ? params.siteId : "";
  const reprovision = useReprovisionRumBeaconKey(siteId);

  return (
    <SettingsCard
      title="Real User Monitoring"
      description="Measure actual visitor experience using Core Web Vitals collected from real browsers."
    >
      <SettingRow
        label="Enable Real User Monitoring"
        description={
          "Measure real visitors' Core Web Vitals (LCP, INP, CLS) from their browsers. " +
          "A small first-party script is injected into cached pages; results appear in the " +
          "Performance dashboard after enough pageviews accumulate. Off by default because " +
          "this turns on a visitor data flow -- enable only if your site's privacy policy " +
          "covers the collection of page-timing data from visitors."
        }
        checked={config.rum_enabled ?? false}
        onChange={(v) => save({ rum_enabled: v })}
        disabled={disabled || isSaving("rum_enabled")}
        saving={isSaving("rum_enabled")}
      >
        <SampleRateRow
          value={config.rum_sample_rate ?? 1.0}
          onChange={(v) => save({ rum_sample_rate: v })}
          disabled={disabled}
          saving={isSaving("rum_sample_rate")}
        />
        <MinSampleCountRow
          value={config.min_sample_count ?? 30}
          onCommit={(v) => save({ min_sample_count: v })}
          disabled={disabled}
          saving={isSaving("min_sample_count")}
        />
        <RumKeyStatus
          config={config}
          disabled={disabled || siteId === ""}
          reprovisioning={reprovision.isPending}
          onReprovision={() => reprovision.mutate()}
        />
      </SettingRow>
    </SettingsCard>
  );
}

interface RumKeyStatusProps {
  config: PerfConfig;
  disabled: boolean;
  reprovisioning: boolean;
  onReprovision: () => void;
}

function RumKeyStatus({
  config,
  disabled,
  reprovisioning,
  onReprovision,
}: RumKeyStatusProps) {
  const cpKeySet = config.beacon_key_set === true;
  const agentKeySet = config.rum_agent_beacon_key_set;
  const healthy = (config.rum_enabled ?? false) && cpKeySet && agentKeySet === true;

  let Icon = CheckCircle2;
  let label = "Confirmed";
  let description = "The control plane has a key hash and the agent confirmed its local key.";
  let badgeVariant: "success" | "destructive" | "muted" = "success";
  let className =
    "border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200";

  if (!cpKeySet) {
    Icon = KeyRound;
    label = "Provisioning pending";
    description = "The control plane has not recorded a RUM beacon-key hash yet.";
    badgeVariant = "muted";
    className =
      "border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200";
  } else if (agentKeySet === false) {
    Icon = AlertTriangle;
    label = "Agent key missing";
    description = "The agent reported that its local RUM beacon key is missing.";
    badgeVariant = "destructive";
    className =
      "border-red-200 bg-red-50 text-red-950 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200";
  } else if (!healthy) {
    Icon = Clock3;
    label = "Waiting for agent";
    description = "The control plane has a key hash and is waiting for agent confirmation.";
    badgeVariant = "muted";
    className =
      "border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200";
  }

  return (
    <div
      className={`mt-3 flex flex-col gap-3 rounded-md border px-3 py-3 sm:flex-row sm:items-center sm:justify-between ${className}`}
    >
      <div className="flex min-w-0 items-start gap-3">
        <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-medium">RUM key status</p>
            <Badge variant={badgeVariant}>{label}</Badge>
          </div>
          <p className="mt-1 text-xs leading-5 opacity-90">{description}</p>
        </div>
      </div>
      {!healthy ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onReprovision}
          disabled={disabled || reprovisioning}
          className="shrink-0 bg-background/80"
        >
          {reprovisioning ? (
            <Loader2 aria-hidden="true" className="animate-spin" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Reprovision key
        </Button>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Sample-rate sub-control (revealed when rum_enabled is on)
// ---------------------------------------------------------------------------

interface SampleRateRowProps {
  value: number;
  onChange: (v: number) => void;
  disabled: boolean;
  saving: boolean;
}

const SAMPLE_RATE_OPTIONS = [
  { value: 1.0, label: "100% (all pageviews)" },
  { value: 0.5, label: "50%" },
  { value: 0.25, label: "25%" },
  { value: 0.1, label: "10%" },
] as const;

function SampleRateRow({ value, onChange, disabled, saving }: SampleRateRowProps) {
  const id = useId();
  // Nearest discrete option to the stored value (gracefully handles values
  // written by the API or a future finer-grained control).
  const selectedValue = SAMPLE_RATE_OPTIONS.reduce((best, opt) =>
    Math.abs(opt.value - value) < Math.abs(best.value - value) ? opt : best,
  ).value;

  return (
    <div className="flex items-center justify-between gap-4">
      <div className="min-w-0">
        <Label
          htmlFor={id}
          className="cursor-pointer text-sm font-medium text-foreground"
        >
          Sample rate
        </Label>
        <p className="mt-0.5 text-xs text-muted-foreground">
          Fraction of pageviews to measure. A lower rate reduces data volume on
          high-traffic sites while keeping the sample statistically
          representative. 100% is recommended for low-to-medium traffic sites.
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {saving ? (
          <Loader2
            aria-hidden="true"
            className="size-4 animate-spin text-muted-foreground"
          />
        ) : null}
        <select
          id={id}
          value={selectedValue}
          onChange={(e) => onChange(Number(e.target.value))}
          disabled={disabled}
          aria-label="Sample rate"
          className="h-8 rounded-md border border-input bg-background px-2 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {SAMPLE_RATE_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Minimum-sample-count sub-control (revealed when rum_enabled is on)
// ---------------------------------------------------------------------------

interface MinSampleCountRowProps {
  value: number;
  onCommit: (v: number) => void;
  disabled: boolean;
  saving: boolean;
}

const MIN_SAMPLE_MIN = 1;
const MIN_SAMPLE_MAX = 1000;

/**
 * Numeric input for the minimum-samples-to-display floor.
 * Commits on blur / Enter (same pattern as NumberField in Field.tsx) so the
 * perf-config PUT fires once per edit, not once per keystroke. Local draft
 * state lets the operator clear and retype freely.
 */
function MinSampleCountRow({ value, onCommit, disabled, saving }: MinSampleCountRowProps) {
  const id = useId();
  const [draft, setDraft] = useState<string>(String(value));
  const [lastValue, setLastValue] = useState<number>(value);
  if (value !== lastValue) {
    setLastValue(value);
    setDraft(String(value));
  }

  function commit() {
    const parsed = parseInt(draft, 10);
    if (draft.trim() === "" || Number.isNaN(parsed)) {
      setDraft(String(value));
      return;
    }
    const clamped = Math.min(MIN_SAMPLE_MAX, Math.max(MIN_SAMPLE_MIN, parsed));
    setDraft(String(clamped));
    if (clamped !== value) onCommit(clamped);
  }

  return (
    <div className="mt-3 flex items-center justify-between gap-4 border-t border-border pt-3">
      <div className="min-w-0">
        <Label
          htmlFor={id}
          className="cursor-pointer text-sm font-medium text-foreground"
        >
          Minimum samples to display
        </Label>
        <p className="mt-0.5 text-xs text-muted-foreground">
          Hide a metric's score until at least this many real-visitor samples are
          collected, so a noisy average over a handful of visits is never shown.
          Lower it to see scores sooner on low-traffic sites; raise it for
          stricter accuracy.
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {saving ? (
          <Loader2
            aria-hidden="true"
            className="size-4 animate-spin text-muted-foreground"
          />
        ) : null}
        <Input
          id={id}
          type="number"
          inputMode="numeric"
          min={MIN_SAMPLE_MIN}
          max={MIN_SAMPLE_MAX}
          step={1}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              e.currentTarget.blur();
            }
          }}
          disabled={disabled}
          aria-label="Minimum samples to display"
          className="max-w-24"
        />
      </div>
    </div>
  );
}
