"use client";

import { motion } from "motion/react";
import { cn } from "@/lib/utils";
import { useEffect, useRef, useState } from "react";

// Ported BeforeAfterCard + ByteBar from media-showcase for feature-page reuse.

function prefersReducedMotion() {
  return (
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  );
}

function CountUp({ value, suffix = "", format = (n: number) => Math.round(n).toLocaleString("en-US"), className }: {
  value: number;
  suffix?: string;
  format?: (n: number) => string;
  className?: string;
}) {
  const ref = useRef<HTMLSpanElement>(null);
  const [display, setDisplay] = useState(() => prefersReducedMotion() ? value : 0);

  useEffect(() => {
    const el = ref.current;
    if (!el || prefersReducedMotion()) { setDisplay(value); return; }
    let raf = 0, start = 0, done = false;
    const step = (t: number) => {
      if (!start) start = t;
      const p = Math.min(1, (t - start) / 1100);
      setDisplay(value * (1 - Math.pow(1 - p, 5)));
      if (p < 1) raf = requestAnimationFrame(step);
    };
    const io = new IntersectionObserver((e) => {
      if (e[0]?.isIntersecting && !done) { done = true; raf = requestAnimationFrame(step); io.disconnect(); }
    }, { threshold: 0.4 });
    io.observe(el);
    return () => { io.disconnect(); cancelAnimationFrame(raf); };
  }, [value]);

  return (
    <span ref={ref} className={className} style={{ fontVariantNumeric: "tabular-nums" }}>
      {format(display)}{suffix}
    </span>
  );
}

const MB = (b: number) => `${(b / 1_000_000).toFixed(2)} MB`;

type LibSeg = { label: string; pct: number; tone: "success" | "warning" | "muted" };
const FILL: Record<LibSeg["tone"], string> = {
  success: "bg-[var(--success)]",
  warning: "bg-[var(--warning-subtle-fg)]",
  muted: "bg-[var(--muted-foreground)]/35",
};

export function MediaVisual() {
  const original = 2_480_000;
  const optimized = 712_000;
  const ratio = optimized / original;
  const saved = original - optimized;
  const savedPct = Math.round((saved / original) * 100);
  const library: LibSeg[] = [
    { label: "Optimized", pct: 72, tone: "success" },
    { label: "Pending", pct: 18, tone: "warning" },
    { label: "Unsupported", pct: 10, tone: "muted" },
  ];

  return (
    <div className="flex flex-col gap-5 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between border-b border-[var(--border)] pb-4">
        <span className="text-sm font-semibold text-foreground">Bytes saved on a sample upload</span>
        <span className="font-mono text-xs text-[var(--muted-foreground)]">full image + thumbnails</span>
      </div>

      <div className="flex flex-col gap-4">
        {/* Original */}
        <div className="flex flex-col gap-1.5">
          <div className="flex items-baseline justify-between">
            <span className="font-mono text-xs font-medium text-[var(--muted-foreground)]">Original JPEG</span>
            <span className="font-mono text-sm font-medium text-foreground" style={{ fontVariantNumeric: "tabular-nums" }}>{MB(original)}</span>
          </div>
          <div className="h-8 overflow-hidden rounded-md border border-dashed border-[var(--border)] bg-[var(--background)]">
            <div className="h-full w-full rounded-[5px] bg-[var(--muted-foreground)]/30" />
          </div>
        </div>
        {/* Optimized */}
        <div className="flex flex-col gap-1.5">
          <div className="flex items-baseline justify-between">
            <span className="font-mono text-xs font-medium text-[var(--muted-foreground)]">Optimized AVIF</span>
            <span className="font-mono text-sm font-medium text-foreground" style={{ fontVariantNumeric: "tabular-nums" }}>{MB(optimized)}</span>
          </div>
          <div className="relative h-8 overflow-hidden rounded-md border border-dashed border-[var(--border)] bg-[var(--background)]">
            <motion.div
              className="absolute inset-y-0 left-0 rounded-[5px] bg-primary"
              initial={{ scaleX: 0 }}
              whileInView={{ scaleX: ratio }}
              viewport={{ once: true, margin: "-60px" }}
              style={{ originX: 0, width: "100%" }}
              transition={{ duration: 0.8, ease: [0.22, 1, 0.36, 1] }}
            />
          </div>
        </div>
      </div>

      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-t border-[var(--border)] pt-4">
        <CountUp
          value={savedPct}
          suffix="%"
          format={(n) => `${Math.round(n)}`}
          className="font-mono text-3xl font-semibold text-[var(--primary)]"
        />
        <span className="text-sm font-medium text-foreground">smaller on this sample</span>
      </div>

      <div className="flex flex-col gap-2.5">
        <span className="text-xs font-medium text-[var(--muted-foreground)]">Library coverage</span>
        <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-[var(--muted)]">
          {library.map((seg) => (
            <div key={seg.label} className={cn("h-full", FILL[seg.tone])} style={{ width: `${seg.pct}%` }} />
          ))}
        </div>
        <div className="flex flex-wrap gap-x-5 gap-y-1.5">
          {library.map((seg) => (
            <span key={seg.label} className="inline-flex items-center gap-1.5 text-xs text-[var(--muted-foreground)]">
              <span className={cn("h-2 w-2 rounded-full", FILL[seg.tone])} />
              {seg.label} <span className="font-mono" style={{ fontVariantNumeric: "tabular-nums" }}>{seg.pct}%</span>
            </span>
          ))}
        </div>
      </div>
    </div>
  );
}
