import { useId, type ReactNode } from "react";
import { Loader2 } from "lucide-react";

import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";

// One autosave toggle row: label + description on the left, a Switch on the
// right with a saving spinner. Optional `applying` flag renders an "Applying to
// server…" note for settings that change the cache drop-in / .htaccess (the row
// stays in that state until the agent acks via the perf.config.updated /
// cache.* SSE event, which invalidates the config query).

export interface SettingRowProps {
  label: string;
  description?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  /** Show the small inline saving spinner. */
  saving?: boolean;
  /** Server-affecting change: show "Applying to server…" until the SSE ack. */
  applying?: boolean;
  /**
   * Optional reveal slot rendered under the row when `checked`.
   * All existing call sites pass neither `open` nor children — behaviour is
   * identical to before this prop existed.
   */
  children?: ReactNode;
  /**
   * Additive override: when true the children slot is revealed even when
   * `checked` is false (used by CdnSection's draft-enable flow). Has no effect
   * when `children` is absent. Default: false.
   */
  open?: boolean;
}

export function SettingRow({
  label,
  description,
  checked,
  onChange,
  disabled = false,
  saving = false,
  applying = false,
  children,
  open = false,
}: SettingRowProps) {
  const id = useId();
  return (
    <div className="px-5 py-4">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <Label
            htmlFor={id}
            className="cursor-pointer text-sm font-medium text-foreground"
          >
            {label}
          </Label>
          {description ? (
            <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
          ) : null}
          {applying ? (
            <p className="mt-1 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
              <Loader2
                aria-hidden="true"
                className="size-3 animate-spin"
              />
              Applying to server…
            </p>
          ) : null}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {saving ? (
            <Loader2
              aria-hidden="true"
              className="size-4 animate-spin text-muted-foreground"
            />
          ) : null}
          <Switch
            id={id}
            checked={checked}
            onCheckedChange={onChange}
            disabled={disabled}
            aria-label={label}
          />
        </div>
      </div>
      {(checked || open) && children ? (
        <div className="mt-3 border-t border-border pt-3">{children}</div>
      ) : null}
    </div>
  );
}
