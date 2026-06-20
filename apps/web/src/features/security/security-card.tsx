import { useState, useId } from "react";
import { ChevronDown } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

// SecurityCard — collapsible bordered card for the Security tab.
//
// Design rules (Impeccable):
//   - Bordered section, rounded-2xl (16px), bg-card, no shadow.
//   - NEVER nested: the card is the outermost container; panels inside must not
//     add their own card wrapper.
//   - Header is the only expand/collapse trigger (aria-expanded, keyboard-accessible).
//   - Body is separated by a border-t, bg-muted/30, p-4.
//   - The icon sits in a size-9 tinted square; the pill uses Badge variants.
//   - Danger/warning cards auto-expand; others collapse by default.
//   - An optional `action` slot in the header right-area (e.g. "Run scan" button).

// ---------------------------------------------------------------------------
// Status pill types
// ---------------------------------------------------------------------------

export type CardStatus =
  | { variant: "success"; label: string }
  | { variant: "warning"; label: string }
  | { variant: "destructive"; label: string }
  | { variant: "muted"; label: string };

// Map our semantic status to Badge variants.
const STATUS_TO_BADGE: Record<
  CardStatus["variant"],
  "success" | "destructive" | "muted" | "outline"
> = {
  success: "success",
  warning: "outline",       // amber via className override
  destructive: "destructive",
  muted: "muted",
};

// ---------------------------------------------------------------------------
// SecurityCard
// ---------------------------------------------------------------------------

export interface SecurityCardProps {
  /** Lucide icon element — already sized by the parent (size-5). */
  icon: React.ReactNode;
  /** Tint colour for the icon square (Tailwind bg-* class, semantic token). */
  iconTint: string;
  title: string;
  purpose: string;
  status: CardStatus;
  /** Optional element rendered to the right of the status pill (e.g. a button). */
  action?: React.ReactNode;
  children: React.ReactNode;
  /** id used by aria-labelledby/controls; defaults to a generated id. */
  id?: string;
  /** Override the default collapsed state (collapses by default unless danger/warning). */
  defaultExpanded?: boolean;
}

export function SecurityCard({
  icon,
  iconTint,
  title,
  purpose,
  status,
  action,
  children,
  id: idProp,
  defaultExpanded,
}: SecurityCardProps) {
  const generatedId = useId();
  const id = idProp ?? generatedId;
  const headingId = `${id}-heading`;
  const bodyId = `${id}-body`;

  // Danger / warning cards auto-expand so the user sees the alert immediately.
  const autoExpand =
    defaultExpanded !== undefined
      ? defaultExpanded
      : status.variant === "destructive" || status.variant === "warning";

  const [expanded, setExpanded] = useState(autoExpand);

  const badgeVariant = STATUS_TO_BADGE[status.variant];

  return (
    <section
      aria-labelledby={headingId}
      className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-card)]"
    >
      {/* ── Header (click-to-toggle) ── */}
      <button
        type="button"
        aria-expanded={expanded}
        aria-controls={bodyId}
        onClick={() => setExpanded((prev) => !prev)}
        className={cn(
          "flex w-full items-center gap-3 p-4 text-left",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] focus-visible:ring-offset-2",
          "rounded-2xl",
          // When expanded the header top corners stay rounded; body handles bottom.
          expanded && "rounded-b-none",
        )}
      >
        {/* Icon square */}
        <span
          aria-hidden="true"
          className={cn(
            "flex size-9 shrink-0 items-center justify-center rounded-lg",
            iconTint,
          )}
        >
          {icon}
        </span>

        {/* Title + purpose */}
        <span className="min-w-0 flex-1">
          <span
            id={headingId}
            className="block text-sm font-semibold text-[var(--color-foreground)]"
          >
            {title}
          </span>
          <span className="block text-sm text-[var(--color-muted-foreground)]">
            {purpose}
          </span>
        </span>

        {/* Status pill + optional action slot + chevron */}
        <span className="flex shrink-0 items-center gap-2">
          <Badge
            variant={badgeVariant}
            className={cn(
              status.variant === "warning" &&
                "border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300",
            )}
          >
            {status.label}
          </Badge>

          {/* action slot — stopPropagation so click doesn't toggle the card */}
          {action ? (
            <span
              onClick={(e) => e.stopPropagation()}
              onKeyDown={(e) => {
                // Let keyboard events on the action pass through without expanding.
                if (e.key === "Enter" || e.key === " ") {
                  e.stopPropagation();
                }
              }}
            >
              {action}
            </span>
          ) : null}

          <ChevronDown
            aria-hidden="true"
            className={cn(
              "size-4 shrink-0 text-[var(--color-muted-foreground)] transition-transform duration-200",
              expanded && "rotate-180",
            )}
          />
        </span>
      </button>

      {/* ── Body ── */}
      {expanded ? (
        <div
          id={bodyId}
          className="border-t border-[var(--color-border)] bg-[var(--color-muted)]/30 p-4"
        >
          {children}
        </div>
      ) : null}
    </section>
  );
}
