import { useId, useState, type ReactNode } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

// Small labelled field atoms for the perf settings sections. SelectField,
// TextField, and NumberField all autosave through the parent's `save`:
// Select on change, Text/Number on blur (commit) so we PUT once per edit, not
// once per keystroke.

export interface SelectFieldProps {
  label: string;
  value: string;
  options: ReadonlyArray<{ value: string; label: string }>;
  onChange: (value: string) => void;
  disabled?: boolean;
  hint?: ReactNode;
}

export function SelectField({
  label,
  value,
  options,
  onChange,
  disabled = false,
  hint,
}: SelectFieldProps) {
  const id = useId();
  return (
    <div className="space-y-1.5">
      <Label
        htmlFor={id}
        className="text-xs uppercase tracking-[0.02em] text-muted-foreground"
      >
        {label}
      </Label>
      <Select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        aria-label={label}
      >
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </Select>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

export interface NumberFieldProps {
  label: string;
  value: number;
  /** Commit handler — fired on blur / Enter with the clamped numeric value. */
  onCommit: (value: number) => void;
  min: number;
  max: number;
  /** Step for the native spinner (default 1). */
  step?: number;
  /** Suffix unit shown after the input (e.g. "ms"). */
  unit?: string;
  disabled?: boolean;
  hint?: ReactNode;
  ariaLabel?: string;
}

/**
 * A clamped integer/float number input that autosaves on blur (or Enter),
 * mirroring TextField's commit-on-blur pattern. Holds a local string draft so
 * the operator can clear and retype freely; on commit it parses, clamps to
 * [min, max], and fires `onCommit`. An empty / unparseable draft is reverted to
 * the last committed value. The server clamps again, so this is purely a UX aid.
 */
export function NumberField({
  label,
  value,
  onCommit,
  min,
  max,
  step = 1,
  unit,
  disabled = false,
  hint,
  ariaLabel,
}: NumberFieldProps) {
  const id = useId();
  const [draft, setDraft] = useState<string>(String(value));
  // Track the last committed value we synced from. When it changes from
  // elsewhere (optimistic cache update / server echo) we adopt the new value as
  // the draft DURING render — the React-recommended alternative to a syncing
  // effect (https://react.dev/learn/you-might-not-need-an-effect). The input
  // still owns the draft mid-edit until the next external change.
  const [lastValue, setLastValue] = useState<number>(value);
  if (value !== lastValue) {
    setLastValue(value);
    setDraft(String(value));
  }

  function commit() {
    const parsed = Number(draft);
    if (draft.trim() === "" || Number.isNaN(parsed)) {
      setDraft(String(value));
      return;
    }
    const clamped = Math.min(max, Math.max(min, parsed));
    setDraft(String(clamped));
    if (clamped !== value) onCommit(clamped);
  }

  return (
    <div className="space-y-1.5">
      <Label
        htmlFor={id}
        className="text-xs uppercase tracking-[0.02em] text-muted-foreground"
      >
        {label}
      </Label>
      <div className="flex items-center gap-2">
        <Input
          id={id}
          type="number"
          inputMode="decimal"
          min={min}
          max={max}
          step={step}
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
          aria-label={ariaLabel ?? label}
          className={unit ? "max-w-32" : undefined}
        />
        {unit ? (
          <span className="shrink-0 text-xs text-muted-foreground">{unit}</span>
        ) : null}
      </div>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

export interface TextFieldProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  onCommit?: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  type?: "text" | "url" | "password";
  hint?: ReactNode;
  /** aria-label override when the visible label isn't descriptive enough. */
  ariaLabel?: string;
}

export function TextField({
  label,
  value,
  onChange,
  onCommit,
  placeholder,
  disabled = false,
  type = "text",
  hint,
  ariaLabel,
}: TextFieldProps) {
  const id = useId();
  return (
    <div className="space-y-1.5">
      <Label
        htmlFor={id}
        className="text-xs uppercase tracking-[0.02em] text-muted-foreground"
      >
        {label}
      </Label>
      <Input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onCommit ? (e) => onCommit(e.target.value) : undefined}
        placeholder={placeholder}
        disabled={disabled}
        aria-label={ariaLabel ?? label}
        autoComplete={type === "password" ? "off" : undefined}
      />
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
