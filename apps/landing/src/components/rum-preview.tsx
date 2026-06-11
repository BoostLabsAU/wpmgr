import { cn } from "@/lib/cn";
import { TrendSparkline } from "@/components/sparkline";
import { DistributionBar, type DistributionSegment, type Rating } from "@/components/distribution-bar";

// Rating badge colour maps. "good" = green (success token), "needs-improvement"
// = amber (warning token), "poor" = a desaturated red within the token family.
// No raw hex, no neon.
const RATING_BADGE: Record<Rating, string> = {
  "good":              "bg-[var(--success-subtle)] text-[var(--success-subtle-fg)]",
  "needs-improvement": "bg-[var(--warning-subtle)] text-[var(--warning-subtle-fg)]",
  "poor":              "bg-[oklch(95%_0.03_22)] text-[oklch(40%_0.14_22)] dark:bg-[oklch(28%_0.08_22)] dark:text-[oklch(85%_0.10_22)]",
};

type MetricRow = {
  name: string;
  p75: string;
  rating: Rating;
};

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
  trend: number[];
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
