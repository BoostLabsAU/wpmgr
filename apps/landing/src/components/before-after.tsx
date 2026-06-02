import { motion } from "motion/react";
import { CountUp } from "@/components/count-up";
import { Card } from "@/components/cards";
import { cn } from "@/lib/cn";

const MB = (bytes: number) => `${(bytes / 1_000_000).toFixed(2)} MB`;

type LibrarySegment = {
  label: string;
  pct: number;
  tone: "success" | "warning" | "muted";
};

const SEG_FILL: Record<LibrarySegment["tone"], string> = {
  success: "bg-[var(--success)]",
  warning: "bg-[var(--warning-subtle-fg)]",
  muted: "bg-muted-foreground/35",
};
const SEG_DOT: Record<LibrarySegment["tone"], string> = {
  success: "bg-[var(--success)]",
  warning: "bg-[var(--warning-subtle-fg)]",
  muted: "bg-muted-foreground/45",
};

/** One byte bar. The dashed track is the original size; the solid fill scales
 *  in from the left to the optimized ratio, so the dashed remainder reads as
 *  trimmed bytes. Only transform animates. */
function ByteBar({
  label,
  bytes,
  ratio,
  accent,
}: {
  label: string;
  bytes: number;
  ratio: number;
  accent: "neutral" | "teal";
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-baseline justify-between">
        <span className="font-mono text-xs font-medium text-muted-foreground">{label}</span>
        <span
          className="font-mono text-sm font-medium text-foreground"
          style={{ fontVariantNumeric: "tabular-nums" }}
        >
          {MB(bytes)}
        </span>
      </div>
      <div className="relative h-8 w-full overflow-hidden rounded-md border border-dashed border-border bg-background">
        <motion.div
          className={cn(
            "absolute inset-y-0 left-0 w-full origin-left rounded-[5px]",
            accent === "teal" ? "bg-primary" : "bg-muted-foreground/30",
          )}
          initial={{ scaleX: 0 }}
          whileInView={{ scaleX: ratio }}
          viewport={{ once: true, margin: "-60px" }}
          transition={{ duration: 0.8, ease: [0.22, 1, 0.36, 1] }}
        />
      </div>
    </div>
  );
}

export function BeforeAfterCard({
  caption,
  originalLabel,
  originalBytes,
  optimizedLabel,
  optimizedBytes,
  library,
}: {
  caption: string;
  originalLabel: string;
  originalBytes: number;
  optimizedLabel: string;
  optimizedBytes: number;
  library: LibrarySegment[];
}) {
  const ratio = optimizedBytes / originalBytes;
  const savedBytes = originalBytes - optimizedBytes;
  const savedPct = Math.round((savedBytes / originalBytes) * 100);

  return (
    <Card className="flex flex-col gap-6">
      <div className="flex items-center justify-between gap-3 border-b border-border pb-4">
        <span className="text-sm font-semibold text-foreground">Bytes saved on a sample upload</span>
        <span className="font-mono text-xs text-muted-foreground">{caption}</span>
      </div>

      <div className="flex flex-col gap-4">
        <ByteBar label={originalLabel} bytes={originalBytes} ratio={1} accent="neutral" />
        <ByteBar label={optimizedLabel} bytes={optimizedBytes} ratio={ratio} accent="teal" />
      </div>

      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-t border-border pt-4">
        <CountUp
          value={savedPct}
          suffix="%"
          format={(n) => `${Math.round(n)}`}
          className="font-mono text-3xl font-semibold text-primary"
        />
        <span className="text-sm font-medium text-foreground">smaller on this sample,</span>
        <CountUp
          value={savedBytes / 1_000_000}
          suffix=" MB"
          format={(n) => n.toFixed(2)}
          className="font-mono text-sm font-medium text-muted-foreground"
        />
        <span className="text-sm text-muted-foreground">lighter, full image and thumbnails counted.</span>
      </div>

      <div className="flex flex-col gap-2.5">
        <span className="text-xs font-medium text-muted-foreground">Library coverage</span>
        <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
          {library.map((seg) => (
            <div
              key={seg.label}
              className={cn("h-full", SEG_FILL[seg.tone])}
              style={{ width: `${seg.pct}%` }}
            />
          ))}
        </div>
        <div className="flex flex-wrap gap-x-5 gap-y-1.5">
          {library.map((seg) => (
            <span key={seg.label} className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
              <span className={cn("h-2 w-2 rounded-full", SEG_DOT[seg.tone])} />
              {seg.label}
              <span className="font-mono" style={{ fontVariantNumeric: "tabular-nums" }}>
                {seg.pct}%
              </span>
            </span>
          ))}
        </div>
      </div>
    </Card>
  );
}
