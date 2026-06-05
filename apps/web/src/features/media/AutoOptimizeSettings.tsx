import { useId } from "react";
import { Loader2, Settings2 } from "lucide-react";

import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "@/components/toast";

import { FORMAT_OPTIONS, QUALITY_OPTIONS } from "./optimize-options";
import { useMediaSettings, useUpdateMediaSettings } from "./hooks/useMediaSettings";
import type { MediaSettings, TargetFormat, TargetQuality } from "./types";

// AutoOptimizeSettings — "Automatic optimization" panel mounted on MediaTab.
//
// Reads the site's per-site settings via useMediaSettings; saves on every
// meaningful change (toggle or select) via useUpdateMediaSettings. The panel
// is disabled/loading while the query is in flight and while a save is pending.
// Shows a toast on success/error (matching the MediaTab toast style).
//
// The Format/Quality selects reuse FORMAT_OPTIONS + QUALITY_OPTIONS exported
// from OptimizeDialog so the option labels, hints, and values stay in sync with
// the manual optimize dialog. Copy is intentionally generic (ADR-044 §C).

export interface AutoOptimizeSettingsProps {
  siteId: string;
  /** Whether the current user can change settings (operator+). */
  canOperate: boolean;
}

export function AutoOptimizeSettings({
  siteId,
  canOperate,
}: AutoOptimizeSettingsProps) {
  const toggleId = useId();
  const formatId = useId();
  const qualityId = useId();

  const { data, isPending, isError } = useMediaSettings(siteId);
  const update = useUpdateMediaSettings(siteId);

  const isBusy = isPending || update.isPending;
  const disabled = isBusy || !canOperate;

  function save(patch: Partial<MediaSettings>) {
    if (!data) return;
    const next: MediaSettings = { ...data, ...patch };
    update.mutate(next, {
      onSuccess: () =>
        toast.success("Auto-optimization settings saved."),
      onError: (err) =>
        toast.error("Could not save settings.", { description: err.message }),
    });
  }

  function handleToggle(checked: boolean) {
    save({ auto_optimize_enabled: checked });
  }

  function handleFormat(value: TargetFormat) {
    save({ auto_target_format: value });
  }

  function handleQuality(value: TargetQuality) {
    save({ auto_target_quality: value });
  }

  // ── Skeleton ──────────────────────────────────────────────────────────────

  if (isPending) {
    return (
      <div
        role="status"
        aria-busy="true"
        aria-label="Loading auto-optimization settings"
        className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4"
      >
        <span className="sr-only">Loading auto-optimization settings</span>
        <div className="flex items-start justify-between gap-4">
          <div className="space-y-2">
            <Skeleton className="h-4 w-48" />
            <Skeleton className="h-3 w-72" />
          </div>
          <Skeleton className="mt-0.5 h-5 w-9 shrink-0 rounded-full" />
        </div>
      </div>
    );
  }

  // ── Error (soft — don't block the rest of the tab) ────────────────────────

  if (isError || !data) {
    return null;
  }

  const enabled = data.auto_optimize_enabled;

  // ── Panel ─────────────────────────────────────────────────────────────────

  return (
    <section
      aria-label="Automatic optimization settings"
      className="rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-4"
    >
      {/* Header row: title + toggle */}
      <div className="flex items-start justify-between gap-4">
        <div className="flex min-w-0 items-center gap-2">
          <Settings2
            aria-hidden="true"
            className="mt-0.5 size-4 shrink-0 text-[var(--color-muted-foreground)]"
          />
          <div>
            <Label
              htmlFor={toggleId}
              className="cursor-pointer text-sm font-medium text-[var(--color-foreground)]"
            >
              Optimize new uploads automatically
            </Label>
            <p className="mt-0.5 text-xs text-[var(--color-muted-foreground)]">
              New JPEG, PNG and GIF uploads are optimized in the background.
            </p>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {update.isPending ? (
            <Loader2
              aria-hidden="true"
              className="size-4 animate-spin text-[var(--color-muted-foreground)]"
            />
          ) : null}
          <Switch
            id={toggleId}
            checked={enabled}
            onCheckedChange={handleToggle}
            disabled={disabled}
            aria-label="Enable automatic optimization for new uploads"
          />
        </div>
      </div>

      {/* Format + Quality selects (revealed when enabled) */}
      {enabled ? (
        <div className="mt-4 grid grid-cols-2 gap-3 border-t border-[var(--color-border)] pt-4 sm:grid-cols-[1fr_1fr]">
          {/* Format */}
          <div className="space-y-1.5">
            <Label
              htmlFor={formatId}
              className="text-xs uppercase tracking-[0.02em] text-[var(--color-muted-foreground)]"
            >
              Target format
            </Label>
            <select
              id={formatId}
              value={data.auto_target_format}
              onChange={(e) => handleFormat(e.target.value as TargetFormat)}
              disabled={disabled}
              className="flex h-9 w-full rounded-md border border-[var(--color-input)] bg-transparent px-3 py-1 text-sm text-[var(--color-foreground)] shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--color-ring)] disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="Default target format for automatic optimization"
            >
              {FORMAT_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label} — {opt.hint}
                </option>
              ))}
            </select>
          </div>

          {/* Quality */}
          <div className="space-y-1.5">
            <Label
              htmlFor={qualityId}
              className="text-xs uppercase tracking-[0.02em] text-[var(--color-muted-foreground)]"
            >
              Quality
            </Label>
            <select
              id={qualityId}
              value={data.auto_target_quality}
              onChange={(e) => handleQuality(e.target.value as TargetQuality)}
              disabled={disabled}
              className="flex h-9 w-full rounded-md border border-[var(--color-input)] bg-transparent px-3 py-1 text-sm text-[var(--color-foreground)] shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--color-ring)] disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="Default quality for automatic optimization"
            >
              {QUALITY_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label} — {opt.hint}
                </option>
              ))}
            </select>
          </div>
        </div>
      ) : null}
    </section>
  );
}
