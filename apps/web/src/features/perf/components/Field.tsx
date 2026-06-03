import { useId, type ReactNode } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

// Small labelled field atoms for the perf settings sections. SelectField and
// TextField both autosave-on-change/blur through the parent's `save`.

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
