import { type ReactNode } from "react";

// A titled section container for a group of perf settings. Presentational only.
// Uses the utility-class token form (border-border / bg-card / text-foreground)
// preferred for new code per the recon.

export interface SettingsCardProps {
  title: string;
  description?: string;
  /** Optional right-aligned header slot (e.g. a master enable toggle). */
  action?: ReactNode;
  children: ReactNode;
}

export function SettingsCard({
  title,
  description,
  action,
  children,
}: SettingsCardProps) {
  return (
    <section className="rounded-xl border border-border bg-card text-card-foreground shadow-sm">
      <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground">{title}</h3>
          {description ? (
            <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
          ) : null}
        </div>
        {action ? <div className="shrink-0">{action}</div> : null}
      </div>
      <div className="divide-y divide-border">{children}</div>
    </section>
  );
}
