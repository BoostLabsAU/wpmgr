import { useId, useState, type KeyboardEvent } from "react";
import { X } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

// A chip / tag input for the array config fields (bypass URLs, cookies, RUCSS
// safelist, JS excludes, etc.). Commit a chip on Enter or comma; remove with the
// chip's × or Backspace on an empty input. Commits the whole array on every
// change so the parent autosaves through the perf-config PUT.

export interface ChipInputProps {
  label: string;
  description?: string;
  values: string[];
  onChange: (values: string[]) => void;
  placeholder?: string;
  disabled?: boolean;
}

export function ChipInput({
  label,
  description,
  values,
  onChange,
  placeholder,
  disabled = false,
}: ChipInputProps) {
  const id = useId();
  const [draft, setDraft] = useState("");

  function commit(raw: string) {
    const value = raw.trim();
    if (!value || values.includes(value)) {
      setDraft("");
      return;
    }
    onChange([...values, value]);
    setDraft("");
  }

  function remove(value: string) {
    onChange(values.filter((v) => v !== value));
  }

  function handleKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter" || e.key === ",") {
      e.preventDefault();
      commit(draft);
    } else if (e.key === "Backspace" && draft === "" && values.length > 0) {
      const last = values[values.length - 1];
      if (last !== undefined) remove(last);
    }
  }

  return (
    <div className="space-y-1.5">
      <Label
        htmlFor={id}
        className="text-xs uppercase tracking-[0.02em] text-muted-foreground"
      >
        {label}
      </Label>
      {description ? (
        <p className="text-xs text-muted-foreground">{description}</p>
      ) : null}
      <div className="flex flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent p-1.5">
        {values.map((value) => (
          <span
            key={value}
            className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-foreground"
          >
            <span className="max-w-[220px] truncate font-mono">{value}</span>
            {!disabled ? (
              <button
                type="button"
                onClick={() => remove(value)}
                aria-label={`Remove ${value}`}
                className="rounded-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <X aria-hidden="true" className="size-3" />
              </button>
            ) : null}
          </span>
        ))}
        <Input
          id={id}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={handleKeyDown}
          onBlur={() => commit(draft)}
          placeholder={values.length === 0 ? placeholder : undefined}
          disabled={disabled}
          aria-label={label}
          className="h-7 min-w-[120px] flex-1 border-0 px-1 shadow-none focus-visible:ring-0"
        />
      </div>
    </div>
  );
}
