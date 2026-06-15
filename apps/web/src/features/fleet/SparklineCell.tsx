// SparklineCell — wraps the existing Sparkline component for use inside
// FleetTable cells. Handles the >=2-point guard, normalises number[] input,
// and adds a fallback dash when insufficient data.

import { Sparkline, type SparklineDatum } from "@/components/charts/sparkline";

export interface SparklineCellProps {
  data: number[];
  tone?: "primary" | "success" | "warning" | "destructive";
  width?: number;
  height?: number;
  ariaLabel?: string;
}

export function SparklineCell({
  data,
  tone = "primary",
  width = 56,
  height = 20,
  ariaLabel = "Trend sparkline",
}: SparklineCellProps) {
  const normalized: SparklineDatum[] = data
    .filter((v) => Number.isFinite(v))
    .map((v) => ({ value: v }));

  if (normalized.length < 2) {
    return (
      <span
        aria-hidden="true"
        className="inline-block text-[var(--color-muted-foreground)]"
        style={{ width, height, lineHeight: `${height}px` }}
      >
        {"-"}
      </span>
    );
  }

  return (
    <Sparkline
      data={normalized}
      tone={tone}
      width={width}
      height={height}
      ariaLabel={ariaLabel}
    />
  );
}
