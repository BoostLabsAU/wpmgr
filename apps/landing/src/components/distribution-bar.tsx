import { motion } from "motion/react";
import { cn } from "@/lib/cn";

export type Rating = "good" | "needs-improvement" | "poor";

export type DistributionSegment = {
  label: string;
  pct: number;
  tone: Rating;
};

const RATING_BAR: Record<Rating, string> = {
  "good":              "bg-[var(--success)]",
  "needs-improvement": "bg-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(55%_0.16_22)]",
};
const RATING_DOT: Record<Rating, string> = {
  "good":              "bg-[var(--success)]",
  "needs-improvement": "bg-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(55%_0.16_22)]",
};

/** A three-segment horizontal distribution bar (good / needs-improvement / poor).
 *  Segments animate in from the left using opacity on each. */
export function DistributionBar({ segments }: { segments: DistributionSegment[] }) {
  return (
    <div className="flex flex-col gap-2">
      <div className="flex h-2 w-full overflow-hidden rounded-full bg-muted">
        {segments.map((seg, i) => (
          <motion.div
            key={seg.label}
            className={cn("h-full", RATING_BAR[seg.tone])}
            style={{ width: `${seg.pct}%` }}
            initial={{ opacity: 0 }}
            whileInView={{ opacity: 1 }}
            viewport={{ once: true, margin: "-60px" }}
            transition={{ duration: 0.5, delay: i * 0.1, ease: [0.22, 1, 0.36, 1] }}
          />
        ))}
      </div>
      <div className="flex flex-wrap gap-x-4 gap-y-1">
        {segments.map((seg) => (
          <span key={seg.label} className="inline-flex items-center gap-1.5 text-2xs text-muted-foreground">
            <span className={cn("h-1.5 w-1.5 shrink-0 rounded-full", RATING_DOT[seg.tone])} />
            <span>{seg.label}</span>
            <span className="font-mono" style={{ fontVariantNumeric: "tabular-nums" }}>
              {seg.pct}%
            </span>
          </span>
        ))}
      </div>
    </div>
  );
}
