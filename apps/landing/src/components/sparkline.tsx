import { motion } from "motion/react";
import { cn } from "@/lib/cn";

/** A minimal polyline-style sparkline built from divs to avoid an SVG chart
 *  dependency. Renders as a series of bottom-aligned bars at varying heights,
 *  which reads as a trend shape. An optional horizontal threshold line is drawn
 *  at the passing boundary. All figures are sample data. */
export function TrendSparkline({
  points,
  threshold,
  fill = "bg-[var(--success)]/70",
  fillAbove = "bg-[var(--warning-subtle-fg)]/70",
  height = "h-14",
}: {
  points: number[];
  threshold?: number;
  /** Tailwind bg class for bars at or below the threshold (or all bars if no
   *  threshold). Defaults to success green. */
  fill?: string;
  /** Tailwind bg class for bars above the threshold. Defaults to warning amber. */
  fillAbove?: string;
  /** Tailwind height class for the container. */
  height?: string;
}) {
  const max = Math.max(...points, ...(threshold !== undefined ? [threshold] : [])) * 1.15;
  const thresholdPct = threshold !== undefined ? (threshold / max) * 100 : undefined;

  return (
    <div className={cn("relative w-full", height)}>
      {/* Threshold line */}
      {thresholdPct !== undefined && (
        <div
          aria-hidden
          className="absolute right-0 left-0 h-px border-t border-dashed border-[var(--warning-subtle-fg)]/60"
          style={{ bottom: `${thresholdPct}%` }}
        />
      )}
      {/* Bars */}
      <div className="absolute inset-0 flex items-end gap-px">
        {points.map((v, i) => {
          const pct = Math.round((v / max) * 100);
          const above = threshold !== undefined && v > threshold;
          return (
            <motion.div
              key={i}
              className={cn("flex-1 min-w-0 rounded-t-[2px]", above ? fillAbove : fill)}
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
