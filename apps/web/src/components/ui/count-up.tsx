// CountUp — animates a number from 0 to `value` once it scrolls into view.
// Ported from apps/landing/src/components/count-up.tsx; uses only React and
// browser APIs (no extra deps). Reduced-motion safe: the final value is shown
// immediately when `prefers-reduced-motion: reduce` is set.
//
// Numbers render in mono tabular figures so the width never jitters during the
// animation. The ease-out-quint curve matches the brand motion preset.

import { useEffect, useRef, useState } from "react";

function prefersReducedMotion(): boolean {
  return (
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  );
}

export interface CountUpProps {
  value: number;
  durationMs?: number;
  format?: (n: number) => string;
  prefix?: string;
  suffix?: string;
  className?: string;
}

export function CountUp({
  value,
  durationMs = 1100,
  format = (n) => Math.round(n).toLocaleString("en-US"),
  prefix = "",
  suffix = "",
  className,
}: CountUpProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const [display, setDisplay] = useState<number>(() =>
    prefersReducedMotion() ? value : 0,
  );

  useEffect(() => {
    const el = ref.current;
    if (!el || prefersReducedMotion()) {
      setDisplay(value);
      return;
    }

    let raf = 0;
    let start = 0;
    let done = false;

    const step = (t: number) => {
      if (!start) start = t;
      const p = Math.min(1, (t - start) / durationMs);
      // ease-out-quint — matches the brand motion curve
      const eased = 1 - Math.pow(1 - p, 5);
      setDisplay(value * eased);
      if (p < 1) raf = requestAnimationFrame(step);
    };

    const io = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting && !done) {
          done = true;
          raf = requestAnimationFrame(step);
          io.disconnect();
        }
      },
      { threshold: 0.4 },
    );
    io.observe(el);

    return () => {
      io.disconnect();
      cancelAnimationFrame(raf);
    };
  }, [value, durationMs]);

  return (
    <span
      ref={ref}
      className={className}
      style={{ fontVariantNumeric: "tabular-nums" }}
    >
      {prefix}
      {format(display)}
      {suffix}
    </span>
  );
}
