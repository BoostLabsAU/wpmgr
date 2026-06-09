import { motion } from "motion/react";
import { cn } from "@/lib/cn";

// Rating colour maps. "good" = green (success token), "needs-improvement" = amber
// (warning token), "poor" = red (a desaturated red that stays within the token
// family). No raw hex, no neon. All three map to the semantic vars in globals.css.
const RATING_BAR: Record<"good" | "needs-improvement" | "poor", string> = {
  "good":              "bg-[var(--success)]",
  "needs-improvement": "bg-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(55%_0.16_22)]",
};
const RATING_DOT: Record<"good" | "needs-improvement" | "poor", string> = {
  "good":              "bg-[var(--success)]",
  "needs-improvement": "bg-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(55%_0.16_22)]",
};
const RATING_BADGE: Record<"good" | "needs-improvement" | "poor", string> = {
  "good":              "bg-[var(--success-subtle)] text-[var(--success-subtle-fg)]",
  "needs-improvement": "bg-[var(--warning-subtle)] text-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(95%_0.03_22)] text-[oklch(40%_0.14_22)] dark:bg-[oklch(28%_0.08_22)] dark:text-[oklch(85%_0.10_22)]",
};

type Rating = "good" | "needs-improvement" | "poor";

type DistributionSegment = {
  label: string;
  pct: number;
  tone: Rating;
};

type MetricRow = {
  name: string;
  p75: string;
  rating: Rating;
};

type TrendPoint = number;

/** A three-segment horizontal distribution bar (good / needs-improvement / poor).
 *  Segments animate in from the left using scaleX on each. */
function DistributionBar({ segments }: { segments: DistributionSegment[] }) {
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

/** A minimal polyline-style sparkline built from divs to avoid an SVG chart
 *  dependency. Renders as a series of bottom-aligned bars at varying heights,
 *  which reads as a trend shape. A horizontal threshold line is drawn at the
 *  passing boundary. All sample data, labelled as such. */
function TrendSparkline({
  points,
  threshold,
}: {
  points: TrendPoint[];
  threshold: number;
}) {
  const max = Math.max(...points, threshold) * 1.15;
  const thresholdPct = (threshold / max) * 100;

  return (
    <div className="relative h-14 w-full">
      {/* Threshold line */}
      <div
        aria-hidden
        className="absolute right-0 left-0 h-px border-t border-dashed border-[var(--warning-subtle-fg)]/60"
        style={{ bottom: `${thresholdPct}%` }}
      />
      {/* Bars */}
      <div className="absolute inset-0 flex items-end gap-px">
        {points.map((v, i) => {
          const pct = Math.round((v / max) * 100);
          const belowThreshold = v <= threshold;
          return (
            <motion.div
              key={i}
              className={cn(
                "flex-1 min-w-0 rounded-t-[2px]",
                belowThreshold ? "bg-[var(--success)]/70" : "bg-[var(--warning-subtle-fg)]/70",
              )}
              style={{ height: `${pct}%` }}
              initial={{ scaleY: 0, transformOrigin: "bottom" }}
              whileInView={{ scaleY: 1 }}
              viewport={{ once: true, margin: "-60px" }}
              transition={{
                duration: 0.4,
                delay: i * 0.025,
                ease: [0.22, 1, 0.36, 1],
              }}
            />
          );
        })}
      </div>
    </div>
  );
}

/** A compact row in the five-metric summary table at the bottom of the card. */
function MetricRow({ name, p75, rating }: MetricRow) {
  return (
    <div className="flex items-center justify-between gap-3 py-2 border-t border-border first:border-t-0 first:pt-0">
      <span className="font-mono text-xs font-medium text-foreground">{name}</span>
      <div className="flex items-center gap-2">
        <span
          className="font-mono text-xs text-muted-foreground"
          style={{ fontVariantNumeric: "tabular-nums" }}
        >
          {p75}
        </span>
        <span className={cn("rounded-full px-1.5 py-0.5 text-2xs font-medium", RATING_BADGE[rating])}>
          {rating === "good" ? "Good" : rating === "needs-improvement" ? "Needs work" : "Poor"}
        </span>
      </div>
    </div>
  );
}

/** The RUM dashboard preview widget. Entirely div/Tailwind, no images or
 *  screenshots. Shows the LCP metric card with a p75 value, distribution bar,
 *  and a sparkline trend with the passing threshold marked. Below it, a compact
 *  five-metric summary mirrors what the real dashboard shows. Labelled as sample
 *  data throughout so nothing is stated as a guaranteed result. */
export function RumPreview({
  metric,
  p75,
  rating,
  distribution,
  trend,
  threshold,
  metrics,
}: {
  metric: string;
  p75: string;
  rating: Rating;
  distribution: DistributionSegment[];
  trend: TrendPoint[];
  threshold: number;
  metrics: MetricRow[];
}) {
  return (
    <div className="flex flex-col gap-3">
      {/* Browser chrome strip */}
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-md">
        <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-4 py-2.5">
          <span className="flex gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
            <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
            <span className="h-2.5 w-2.5 rounded-full bg-muted-foreground/30" />
          </span>
          <span className="ml-2 inline-flex items-center gap-1.5 rounded-md bg-background px-2.5 py-1 font-mono text-xs text-muted-foreground">
            Real User Monitoring
          </span>
          <span className="ml-auto inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-medium bg-[var(--success-subtle)] text-[var(--success-subtle-fg)]">
            <span className="h-1.5 w-1.5 rounded-full bg-[var(--success)]" />
            Live
          </span>
        </div>

        {/* Primary metric card */}
        <div className="flex flex-col gap-4 p-5">
          <div className="flex items-start justify-between gap-3">
            <div className="flex flex-col gap-0.5">
              <span className="text-xs font-semibold uppercase tracking-[0.1em] text-muted-foreground">
                {metric} p75
              </span>
              <div className="flex items-baseline gap-2">
                <span
                  className="font-mono text-3xl font-semibold text-foreground"
                  style={{ fontVariantNumeric: "tabular-nums" }}
                >
                  {p75}
                </span>
                <span className={cn("rounded-full px-2 py-0.5 text-xs font-medium", RATING_BADGE[rating])}>
                  {rating === "good" ? "Good" : rating === "needs-improvement" ? "Needs improvement" : "Poor"}
                </span>
              </div>
            </div>
            <span className="text-2xs text-muted-foreground text-right leading-relaxed">
              28-day trend<br />sample data
            </span>
          </div>

          {/* Trend sparkline */}
          <TrendSparkline points={trend} threshold={threshold} />
          <div className="flex items-center gap-1.5 text-2xs text-muted-foreground">
            <span className="h-px w-4 border-t border-dashed border-[var(--warning-subtle-fg)]/60" />
            <span>Passing threshold ({threshold}s)</span>
          </div>

          {/* Distribution bar */}
          <DistributionBar segments={distribution} />
        </div>

        {/* Five-metric summary */}
        <div className="border-t border-border px-5 pb-4 pt-3">
          <span className="mb-2 block text-xs font-semibold text-muted-foreground">All Core Web Vitals</span>
          <div className="flex flex-col">
            {metrics.map((m) => (
              <MetricRow key={m.name} name={m.name} p75={m.p75} rating={m.rating} />
            ))}
          </div>
        </div>
      </div>
      <p className="text-center text-2xs text-muted-foreground">
        Sample data only. Values shown are illustrative.
      </p>
    </div>
  );
}
