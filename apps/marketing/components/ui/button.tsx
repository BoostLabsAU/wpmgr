import { cn } from "@/lib/utils";
import type { AnchorHTMLAttributes } from "react";

type Variant = "primary" | "secondary" | "ghost";
type Size = "sm" | "md" | "lg";

const base =
  "inline-flex items-center justify-center gap-2 rounded-[var(--radius)] font-medium whitespace-nowrap cursor-pointer select-none transition-[background-color,color,border-color,box-shadow,transform] duration-[var(--duration-fast)] ease-[var(--ease-out-quint)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] disabled:pointer-events-none disabled:opacity-55 active:translate-y-px";

const variants: Record<Variant, string> = {
  primary:
    "bg-primary text-[var(--primary-foreground)] shadow-sm hover:bg-[var(--primary-hover)] active:bg-[var(--primary-pressed)]",
  secondary:
    "bg-card text-foreground border border-[var(--border)] hover:bg-[var(--accent)] hover:text-[var(--accent-foreground)]",
  ghost: "bg-transparent text-foreground hover:bg-[var(--accent)] hover:text-[var(--accent-foreground)]",
};

const sizes: Record<Size, string> = {
  sm: "h-9 px-3 text-sm",
  md: "h-10 px-4 text-sm",
  lg: "h-12 px-6 text-base",
};

export interface ButtonProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
  variant?: Variant;
  size?: Size;
}

/** Anchor-styled CTA button. Marketing CTAs are all links, so this renders an <a>. */
export function Button({ variant = "primary", size = "md", className, ...props }: ButtonProps) {
  return (
    <a
      className={cn(base, variants[variant], sizes[size], className)}
      {...props}
    />
  );
}
