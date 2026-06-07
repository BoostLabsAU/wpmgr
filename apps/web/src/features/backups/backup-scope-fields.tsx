import { Controller } from "react-hook-form";
import type { Control, FieldPath, FieldValues } from "react-hook-form";

import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError } from "@/components/forms/field-error";
import {
  BACKUP_COMPONENT_OPTIONS,
  CORE_COMPONENT_OPTION,
  type BackupComponent,
} from "@/features/backups/backup-scope-constants";


// ---------------------------------------------------------------------------
// BackupComponentsField
// ---------------------------------------------------------------------------

/**
 * Checkbox group for selecting which backup components to include.
 * Renders the five standard components plus a separate "include_core" toggle.
 *
 * Expected form field names:
 *   - backup_components: BackupComponent[]   (excluding "core")
 *   - include_core:      boolean
 *
 * When all five standard components are checked, the CP dispatches all
 * components. When none are checked, the CP treats it as "all" (by sending
 * an empty array / absent field). A partially selected set restricts the
 * backup to only those components.
 */
interface BackupComponentsFieldProps<TFieldValues extends FieldValues> {
  control: Control<TFieldValues>;
  componentsName: FieldPath<TFieldValues>;
  includeCoreNameProp: FieldPath<TFieldValues>;
  disabled?: boolean;
  /** Error message for the components field. */
  componentsError?: string;
}

export function BackupComponentsField<TFieldValues extends FieldValues>({
  control,
  componentsName,
  includeCoreNameProp,
  disabled = false,
  componentsError,
}: BackupComponentsFieldProps<TFieldValues>) {
  return (
    <fieldset disabled={disabled} className="space-y-3">
      <legend className="text-sm font-medium text-foreground">
        Components to include
      </legend>
      <p className="text-xs text-muted-foreground">
        All components are included when none are checked. Restrict to specific
        components to reduce backup size or duration.
      </p>

      <Controller
        control={control}
        name={componentsName}
        render={({ field }) => {
          const selected: BackupComponent[] = Array.isArray(field.value)
            ? (field.value as BackupComponent[])
            : [];

          function toggle(component: BackupComponent, checked: boolean) {
            const next = checked
              ? [...selected, component]
              : selected.filter((c) => c !== component);
            field.onChange(next);
          }

          return (
            <div className="space-y-2 pl-1">
              {BACKUP_COMPONENT_OPTIONS.map((opt) => {
                const checkboxId = `bsc-${opt.value}`;
                return (
                  <label
                    key={opt.value}
                    htmlFor={checkboxId}
                    className="flex items-start gap-2 text-sm"
                  >
                    <Checkbox
                      id={checkboxId}
                      checked={selected.includes(opt.value)}
                      onChange={(e) => toggle(opt.value, e.target.checked)}
                      className="mt-0.5"
                      disabled={disabled}
                    />
                    <span>
                      <span className="font-medium">{opt.label}</span>
                      <span className="block text-xs text-muted-foreground">
                        {opt.description}
                      </span>
                    </span>
                  </label>
                );
              })}
            </div>
          );
        }}
      />

      {componentsError ? (
        <FieldError
          what={componentsError}
          why="At least one component must be selected, or leave all unchecked for a full backup."
          how="Check the components you want to include above."
        />
      ) : null}

      {/* WordPress core — always a separate toggle */}
      <div className="border-t border-border pt-3">
        <Controller
          control={control}
          name={includeCoreNameProp}
          render={({ field }) => {
            const checked = field.value === true;
            return (
              <label
                htmlFor="bsc-core"
                className="flex items-start gap-2 text-sm"
              >
                <Checkbox
                  id="bsc-core"
                  checked={checked}
                  onChange={(e) => field.onChange(e.target.checked)}
                  className="mt-0.5"
                  disabled={disabled}
                />
                <span>
                  <span className="font-medium">
                    {CORE_COMPONENT_OPTION.label}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {CORE_COMPONENT_OPTION.description}
                  </span>
                </span>
              </label>
            );
          }}
        />
      </div>
    </fieldset>
  );
}

// ---------------------------------------------------------------------------
// BackupExclusionsField
// ---------------------------------------------------------------------------

/**
 * Exclusions sub-form for the backup scope. Three independent inputs:
 *   - exclude_paths:        array<string> — path segments (newline or comma separated)
 *   - exclude_extensions:   array<string> — comma-separated, no leading dot
 *   - exclude_file_size_mb: number        — files larger than this MiB are skipped (0 = off)
 *
 * All three are optional; absent = the agent's own default excludes apply.
 */
interface BackupExclusionsFieldProps<TFieldValues extends FieldValues> {
  control: Control<TFieldValues>;
  excludePathsName: FieldPath<TFieldValues>;
  excludeExtensionsName: FieldPath<TFieldValues>;
  excludeFileSizeMbName: FieldPath<TFieldValues>;
  disabled?: boolean;
  excludePathsError?: string;
  excludeExtensionsError?: string;
  excludeFileSizeMbError?: string;
}

export function BackupExclusionsField<TFieldValues extends FieldValues>({
  control,
  excludePathsName,
  excludeExtensionsName,
  excludeFileSizeMbName,
  disabled = false,
  excludePathsError,
  excludeExtensionsError,
  excludeFileSizeMbError,
}: BackupExclusionsFieldProps<TFieldValues>) {
  return (
    <fieldset disabled={disabled} className="space-y-4">
      <legend className="text-sm font-medium text-foreground">
        Exclusions
      </legend>
      <p className="text-xs text-muted-foreground">
        Files matching any exclusion rule are skipped. The agent&apos;s
        built-in excludes (e.g. cache directories) always apply in addition.
      </p>

      {/* Exclude paths */}
      <div className="space-y-1">
        <Label htmlFor="excl-paths">
          Exclude paths{" "}
          <span className="text-xs font-normal text-muted-foreground">
            (path segments, one per line)
          </span>
        </Label>
        <Controller
          control={control}
          name={excludePathsName}
          render={({ field }) => {
            const raw: unknown = field.value;
            const textValue = Array.isArray(raw)
              ? (raw as string[]).join("\n")
              : "";
            return (
              <textarea
                id="excl-paths"
                value={textValue}
                onChange={(e) => {
                  const lines = e.target.value
                    .split(/[\n,]+/)
                    .map((s) => s.trim())
                    .filter((s) => s.length > 0);
                  field.onChange(lines);
                }}
                rows={3}
                disabled={disabled}
                placeholder={"cache\nbackup\n.git"}
                aria-invalid={excludePathsError ? "true" : undefined}
                className="w-full rounded-md border border-[var(--color-input)] bg-transparent p-2 font-mono text-xs focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
              />
            );
          }}
        />
        <p className="text-xs text-muted-foreground">
          Path segment names (not full paths) matched against any directory or
          file name in the walk. e.g. &ldquo;cache&rdquo; excludes any
          directory named &ldquo;cache&rdquo; at any depth.
        </p>
        {excludePathsError ? (
          <FieldError
            what={excludePathsError}
            why="Path exclusions must be valid segment names."
            how="Enter one path segment per line."
          />
        ) : null}
      </div>

      {/* Exclude extensions */}
      <div className="space-y-1">
        <Label htmlFor="excl-ext">
          Exclude extensions{" "}
          <span className="text-xs font-normal text-muted-foreground">
            (comma-separated, no leading dot)
          </span>
        </Label>
        <Controller
          control={control}
          name={excludeExtensionsName}
          render={({ field }) => {
            const raw: unknown = field.value;
            const textValue = Array.isArray(raw)
              ? (raw as string[]).join(", ")
              : "";
            return (
              <Input
                id="excl-ext"
                value={textValue}
                onChange={(e) => {
                  const parts = e.target.value
                    .split(/[,\s]+/)
                    .map((s) => s.trim().replace(/^\./, "").toLowerCase())
                    .filter((s) => s.length > 0);
                  field.onChange(parts);
                }}
                disabled={disabled}
                placeholder="log, bak, tmp"
                aria-invalid={excludeExtensionsError ? "true" : undefined}
              />
            );
          }}
        />
        <p className="text-xs text-muted-foreground">
          Lowercase, without leading dot. Files whose name ends in any of
          these extensions are skipped.
        </p>
        {excludeExtensionsError ? (
          <FieldError
            what={excludeExtensionsError}
            why="Extensions must be lowercase without a leading dot."
            how="Enter comma-separated extensions, e.g. log, bak."
          />
        ) : null}
      </div>

      {/* Exclude by file size */}
      <div className="space-y-1">
        <Label htmlFor="excl-size">
          Skip files larger than{" "}
          <span className="text-xs font-normal text-muted-foreground">
            (MiB, 0 = no limit)
          </span>
        </Label>
        <Controller
          control={control}
          name={excludeFileSizeMbName}
          render={({ field }) => {
            const numVal: number =
              typeof field.value === "number" ? field.value : 0;
            return (
              <Input
                id="excl-size"
                type="number"
                min={0}
                step={1}
                value={numVal === 0 ? "" : numVal}
                onChange={(e) => {
                  const parsed = parseInt(e.target.value, 10);
                  field.onChange(isNaN(parsed) || parsed < 0 ? 0 : parsed);
                }}
                disabled={disabled}
                placeholder="0"
                className="w-36"
                aria-invalid={excludeFileSizeMbError ? "true" : undefined}
                aria-describedby="excl-size-help"
              />
            );
          }}
        />
        <p id="excl-size-help" className="text-xs text-muted-foreground">
          Files strictly larger than this value (in MiB) are skipped. 0 or
          blank means no size limit. Database dumps are never size-filtered.
        </p>
        {excludeFileSizeMbError ? (
          <FieldError
            what={excludeFileSizeMbError}
            why="Size limit must be a positive integer in MiB."
            how="Enter a whole number greater than 0, or leave blank for no limit."
          />
        ) : null}
      </div>
    </fieldset>
  );
}
