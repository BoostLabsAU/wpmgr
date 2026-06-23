"use client";

import { motion } from "motion/react";
import type { ReactNode } from "react";

/**
 * Scroll-into-view reveal. Opacity + small rise only (no layout props, no
 * bounce) so it reads as calm and passes impeccable motion checks. The global
 * MotionConfig in layout.tsx honours prefers-reduced-motion via "user".
 *
 * NEVER wrap the hero headline or LCP element in Reveal (opacity-0 start
 * blocks LCP measurement).
 */
export function Reveal({
  children,
  delay = 0,
  className,
}: {
  children: ReactNode;
  delay?: number;
  className?: string;
}) {
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 14 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, margin: "-100px" }}
      transition={{ duration: 0.34, ease: [0.22, 1, 0.36, 1], delay }}
    >
      {children}
    </motion.div>
  );
}
