import { cn } from "@/lib/utils";

/**
 * Fleet Hub mark. One filled center node (your dashboard) wired to four hollow
 * satellite nodes (the sites you watch over) by short floating spokes. It reads
 * as one self-hosted control center with several WordPress sites wired into it.
 * Deliberately avoids any WordPress letterform or circle-W. Solid teal, no glow,
 * no gradient. Color follows the current text color so it tracks the active
 * theme's --primary when wrapped in a teal-colored element.
 */
export function FleetHubLogo({
  size = 28,
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
      className={cn("text-[var(--primary)]", className)}
    >
      {/* Center node: the dashboard */}
      <rect x="12" y="12" width="8" height="8" rx="2" fill="currentColor" />
      {/* Four satellite sites: hollow rounded squares */}
      <g stroke="currentColor" strokeWidth="1.75" fill="none">
        <rect x="4.5" y="4.5" width="5" height="5" rx="1.5" />
        <rect x="22.5" y="4.5" width="5" height="5" rx="1.5" />
        <rect x="4.5" y="22.5" width="5" height="5" rx="1.5" />
        <rect x="22.5" y="22.5" width="5" height="5" rx="1.5" />
      </g>
      {/* Floating spokes: remote control plus live monitoring */}
      <g stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
        <line x1="9.8" y1="9.8" x2="11.8" y2="11.8" />
        <line x1="22.2" y1="9.8" x2="20.2" y2="11.8" />
        <line x1="9.8" y1="22.2" x2="11.8" y2="20.2" />
        <line x1="22.2" y1="22.2" x2="20.2" y2="20.2" />
      </g>
    </svg>
  );
}

/** Wordmark: "wpmgr" in IBM Plex Mono, "wp" in ink and "mgr" in solid teal. */
export function Wordmark({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        "font-mono text-lg font-medium tracking-[-0.01em] text-foreground",
        className,
      )}
    >
      wp<span className="text-[oklch(48%_0.14_195)] dark:text-[var(--primary)]">mgr</span>
    </span>
  );
}

/** Lockup: mark beside the wordmark, separated by a gap about one "m" wide. */
export function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-2.5", className)}>
      <FleetHubLogo size={26} />
      <Wordmark />
    </span>
  );
}
