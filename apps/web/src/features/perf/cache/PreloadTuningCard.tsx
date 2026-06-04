import { NumberField, SelectField } from "../components/Field";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// Preload tuning: operator controls for how aggressively the agent warms the
// cache. Each field autosaves through `save` (optimistic PUT in the parent).
// The control plane clamps every value to its bounds, so these inputs are a UX
// aid — the dashboard is the primary surface, agent WP filters remain the
// escape hatch. Bounds here mirror the FROZEN config contract.

const CONCURRENCY_OPTIONS = [
  { value: "1", label: "1 worker (serial)" },
  { value: "2", label: "2 workers" },
  { value: "3", label: "3 workers" },
  { value: "4", label: "4 workers" },
] as const;

export interface PreloadTuningCardProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
}

export function PreloadTuningCard({
  config,
  save,
  disabled,
}: PreloadTuningCardProps) {
  return (
    <SettingsCard
      title="Preload tuning"
      description="Tune how quickly the cache is warmed. Higher concurrency and lower delay warm faster but use more server resources."
    >
      <div className="grid gap-5 px-5 py-4 sm:grid-cols-2">
        <SelectField
          label="Concurrency"
          value={String(config.preload_concurrency)}
          options={CONCURRENCY_OPTIONS}
          onChange={(v) => save({ preload_concurrency: Number(v) })}
          disabled={disabled}
          hint="Parallel warm workers. More workers finish sooner but add load."
        />
        <NumberField
          label="Inter-request delay"
          value={config.preload_delay_ms}
          onCommit={(v) => save({ preload_delay_ms: v })}
          min={0}
          max={10000}
          step={50}
          unit="ms"
          disabled={disabled}
          hint="Pause between each warmed URL. 0 = no delay (fastest, highest load)."
        />
        <NumberField
          label="Batch size"
          value={config.preload_batch_size}
          onCommit={(v) => save({ preload_batch_size: v })}
          min={1}
          max={500}
          step={10}
          unit="URLs"
          disabled={disabled}
          hint="Maximum URLs warmed in a single drain pass."
        />
        <NumberField
          label="Max load per core"
          value={config.preload_max_load}
          onCommit={(v) => save({ preload_max_load: v })}
          min={0}
          max={64}
          step={0.1}
          disabled={disabled}
          hint="Defer warming when the 1-minute load average per core exceeds this. 0 = disabled (never defer)."
        />
      </div>
    </SettingsCard>
  );
}
