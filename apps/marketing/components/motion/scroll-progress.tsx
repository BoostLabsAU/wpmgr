"use client";

import { useScroll, useTransform, motion } from "motion/react";

/**
 * A thin progress bar at the top of the viewport driven by window scroll.
 * Uses useScroll + useTransform for a pure transform-based animation (no
 * layout shifts). Colour matches --primary token.
 */
export function ScrollProgress() {
  const { scrollYProgress } = useScroll();
  const scaleX = useTransform(scrollYProgress, [0, 1], [0, 1]);

  return (
    <motion.div
      aria-hidden
      className="fixed left-0 right-0 top-0 z-[1000] h-0.5 origin-left bg-[var(--primary)]"
      style={{ scaleX }}
    />
  );
}
