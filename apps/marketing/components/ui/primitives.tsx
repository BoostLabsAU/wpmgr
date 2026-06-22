import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/** Max-width content rail with responsive gutters. */
export function Container({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={cn("mx-auto w-full max-w-6xl px-5 sm:px-6 lg:px-8", className)}>
      {children}
    </div>
  );
}

/** A vertical section band. `tone` swaps the surface for alternating rhythm. */
export function Section({
  id,
  tone = "base",
  className,
  children,
}: {
  id?: string;
  tone?: "base" | "muted";
  className?: string;
  children: ReactNode;
}) {
  return (
    <section
      id={id}
      className={cn(
        "scroll-mt-20 py-20 sm:py-24 lg:py-28",
        tone === "muted" && "bg-[var(--muted)]/40",
        className,
      )}
    >
      {children}
    </section>
  );
}

/** Eyebrow label above a section heading. Solid teal text, no glow. */
export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-[var(--eyebrow)]">
      <span aria-hidden className="h-px w-6 bg-[var(--primary)]/60" />
      {children}
    </span>
  );
}

/** Centered section header: eyebrow, title, optional lead paragraph. */
export function SectionHeading({
  eyebrow,
  title,
  lead,
  align = "center",
}: {
  eyebrow?: string;
  title: ReactNode;
  lead?: ReactNode;
  align?: "center" | "left";
}) {
  return (
    <div
      className={cn(
        "flex flex-col gap-4",
        align === "center"
          ? "mx-auto max-w-2xl items-center text-center"
          : "items-start text-left",
      )}
    >
      {eyebrow ? <Eyebrow>{eyebrow}</Eyebrow> : null}
      <h2 className="text-3xl font-semibold text-foreground sm:text-4xl">{title}</h2>
      {lead ? (
        <p className="text-lg leading-relaxed text-[var(--muted-foreground)]">{lead}</p>
      ) : null}
    </div>
  );
}

/** Small pill badge. */
export function Badge({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full border border-[var(--border)] bg-card px-3 py-1 text-xs font-medium text-[var(--muted-foreground)]",
        className,
      )}
    >
      {children}
    </span>
  );
}

/** Soft hairline card. */
export function Card({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={cn("rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm", className)}>
      {children}
    </div>
  );
}
