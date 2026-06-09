import { useId } from "react";
import { Loader2 } from "lucide-react";

import { Label } from "@/components/ui/label";

import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
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
  saving: boolean;
}

export function RumSection({
  config,
  save,
  disabled,
  saving,
}: RumSectionProps) {
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
        disabled={disabled}
        saving={saving}
      >
        <SampleRateRow
          value={config.rum_sample_rate ?? 1.0}
          onChange={(v) => save({ rum_sample_rate: v })}
          disabled={disabled}
          saving={saving}
        />
      </SettingRow>
    </SettingsCard>
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
