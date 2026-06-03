import { cn } from "@/lib/utils";

/**
 * Fleet Hub mark. One filled teal center node (the dashboard) wired by short
 * floating spokes to four hollow satellite nodes (the managed sites). Original
 * mark (no WordPress letterform); solid teal, no glow. Shared with the marketing
 * site so the brand is one identity. Inherits the current theme's --primary via
 * currentColor.
 */
export function FleetHubLogo({
  size = 22,
  className,
}: {
  size?: number;
  className?: string;
}) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      role="img"
      aria-label="WPMgr"
      className={cn("text-primary", className)}
    >
      <rect x="12" y="12" width="8" height="8" rx="2" fill="currentColor" />
      <g stroke="currentColor" strokeWidth="1.75" fill="none">
        <rect x="4.5" y="4.5" width="5" height="5" rx="1.5" />
        <rect x="22.5" y="4.5" width="5" height="5" rx="1.5" />
        <rect x="4.5" y="22.5" width="5" height="5" rx="1.5" />
        <rect x="22.5" y="22.5" width="5" height="5" rx="1.5" />
      </g>
      <g stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
        <line x1="9.8" y1="9.8" x2="11.8" y2="11.8" />
        <line x1="22.2" y1="9.8" x2="20.2" y2="11.8" />
        <line x1="9.8" y1="22.2" x2="11.8" y2="20.2" />
        <line x1="22.2" y1="22.2" x2="20.2" y2="20.2" />
      </g>
    </svg>
  );
}

/** Wordmark: "wp" in ink, "mgr" in teal, IBM Plex Mono. The light-mode teal is
 *  darkened to clear AA contrast; dark mode uses the brighter --primary. */
export function Wordmark({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        "font-mono text-sm font-semibold tracking-[-0.01em] text-foreground",
        className,
      )}
    >
      wp<span className="text-[oklch(48%_0.14_195)] dark:text-primary">mgr</span>
    </span>
  );
}

/** Lockup: mark + wordmark. */
export function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-2", className)}>
      <FleetHubLogo size={20} />
      <Wordmark />
    </span>
  );
}
