// DayBarStrip — a horizontal strip of day cells (green/amber/red) for
// showing N days of status history inline in a table row.
// Colour: green = up, amber = degraded, red = down/incident, muted = unknown/no data.
// Each cell has an aria-label for keyboard/screen-reader users.
// Reduced-motion: the strip is static (no width animation).

import { cn } from "@/lib/utils";
import {
  TooltipProvider,
  TooltipRoot,
  TooltipTrigger,
  TooltipContent,
} from "@/components/ui/tooltip";

export type DayStatus = "up" | "degraded" | "incident" | "unknown";

export interface DayBarCell {
  /** ISO date string "YYYY-MM-DD". */
  date: string;
  status: DayStatus;
  /** Optional human-readable label for the tooltip. */
  label?: string;
}

export interface DayBarStripProps {
  days: DayBarCell[];
  /** Cell width in px. Defaults to 6. */
  cellW?: number;
  /** Cell height in px. Defaults to 20. */
  cellH?: number;
}

const DAY_BG: Record<DayStatus, string> = {
  up: "bg-[var(--color-success)]",
  degraded: "bg-[var(--color-warning)]",
  incident: "bg-[var(--color-destructive)]",
  unknown: "bg-[var(--color-muted-foreground)]/30",
};

const DAY_LABEL: Record<DayStatus, string> = {
  up: "Up",
  degraded: "Degraded",
  incident: "Incident",
  unknown: "No data",
};

// Format a date string as a short label for tooltips ("Jun 14").
function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

export function DayBarStrip({
  days,
  cellW = 6,
  cellH = 20,
}: DayBarStripProps) {
  if (days.length === 0) {
    return (
      <span className="text-xs text-[var(--color-muted-foreground)]">
        No data
      </span>
    );
  }

  return (
    <TooltipProvider>
      <div
        role="img"
        aria-label={`${days.length}-day status history`}
        className="flex items-center gap-px"
      >
        {days.map((day) => {
          const tipText = `${shortDate(day.date)}: ${day.label ?? DAY_LABEL[day.status]}`;
          return (
            <TooltipRoot key={day.date}>
              <TooltipTrigger asChild>
                <span
                  aria-label={tipText}
                  role="presentation"
                  style={{ width: cellW, height: cellH }}
                  className={cn(
                    "inline-block rounded-[2px] transition-opacity duration-100 hover:opacity-80",
                    DAY_BG[day.status],
                  )}
                />
              </TooltipTrigger>
              <TooltipContent side="top">{tipText}</TooltipContent>
            </TooltipRoot>
          );
        })}
      </div>
    </TooltipProvider>
  );
}
