import type { JSX } from "react";
import { motion } from "motion/react";
import { TrendSparkline } from "@/components/sparkline";
import { DistributionBar } from "@/components/distribution-bar";
import type { FeatureVisual } from "@/data/content";

// ---------------------------------------------------------------------------
// Mini data visuals for the three Accelerate cards that carry a deep-dive link.
// Each is a fixed h-16 decorative block (aria-hidden) with a visible caption
// that carries the meaning. All figures are sample data.
//
// RULE: visuals are ONLY allowed on cards that have a deep-dive link (enforced
// by content.ts schema and check-copy budget gate). Register new visuals here
// as new VISUALS keys; the h-16 height and sample-data caption are REQUIRED.
// ---------------------------------------------------------------------------

/** 94% cache hit-ratio bar chart over 30 days. Bottom-aligned bars, all
 *  green (success token), no threshold line needed. */
function CacheTrendMini() {
  // Illustrative hit-ratio trend: values near 94, occasional dips.
  const points = [88, 91, 90, 93, 94, 92, 95, 94, 93, 96, 94, 94, 95, 94, 93, 94, 95, 94];
  return (
    <div className="flex flex-col gap-1">
      <div aria-hidden className="h-16 w-full">
        <TrendSparkline
          points={points}
          fill="bg-[var(--success)]/70"
          fillAbove="bg-[var(--success)]/70"
          height="h-16"
        />
      </div>
      <p className="text-2xs text-muted-foreground">94% cache hit ratio, 30 days, sample data</p>
    </div>
  );
}

/** LCP distribution bar (68/22/10) plus a mono p75 line with Good badge. */
function RumDistributionMini() {
  const segments = [
    { label: "Good", pct: 68, tone: "good" as const },
    { label: "Needs improvement", pct: 22, tone: "needs-improvement" as const },
    { label: "Poor", pct: 10, tone: "poor" as const },
  ];
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center gap-2">
        <span
          aria-hidden
          className="font-mono text-xs font-semibold text-foreground"
          style={{ fontVariantNumeric: "tabular-nums" }}
        >
          LCP p75 2.1s
        </span>
        <span className="rounded-full bg-[var(--success-subtle)] px-1.5 py-0.5 text-2xs font-medium text-[var(--success-subtle-fg)]">
          Good
        </span>
      </div>
      <div aria-hidden>
        <DistributionBar segments={segments} />
      </div>
      <p className="text-2xs text-muted-foreground">Field data at p75, sample data</p>
    </div>
  );
}

/** Two stacked horizontal byte bars: original full-width vs optimized ~29%. */
function MediaCompareMini() {
  return (
    <div className="flex flex-col gap-1.5">
      <div aria-hidden className="flex flex-col gap-1.5">
        {/* Original bar */}
        <div className="flex items-center gap-2">
          <div className="h-3 w-full overflow-hidden rounded-sm bg-muted-foreground/30" />
          <span className="w-20 shrink-0 font-mono text-2xs text-muted-foreground whitespace-nowrap">
            2.4 MB JPEG
          </span>
        </div>
        {/* Optimized bar */}
        <div className="flex items-center gap-2">
          <div className="h-3 w-full overflow-hidden rounded-sm bg-muted">
            <motion.div
              className="h-full rounded-sm bg-[var(--success)]/70"
              style={{ width: "29%" }}
              initial={{ scaleX: 0, transformOrigin: "left" }}
              whileInView={{ scaleX: 1 }}
              viewport={{ once: true, margin: "-60px" }}
              transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
            />
          </div>
          <span className="w-20 shrink-0 font-mono text-2xs text-muted-foreground whitespace-nowrap">
            712 KB AVIF
          </span>
        </div>
      </div>
      <p className="text-2xs text-muted-foreground">A sample upload and its thumbnails, sample data</p>
    </div>
  );
}

/** Registry keyed by FeatureVisual. ClusterFeatureCard looks up by name. */
export const VISUALS: Record<FeatureVisual, () => JSX.Element> = {
  "cache-trend": CacheTrendMini,
  "rum-distribution": RumDistributionMini,
  "media-compare": MediaCompareMini,
};
