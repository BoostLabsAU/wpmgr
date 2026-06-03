import { FleetHubLogo, Wordmark } from "@/components/brand/logo";

/**
 * Shared wrapper for unauthenticated pages (login, forgot-password, reset-password).
 * Renders a vertically/horizontally centered column on the app background with
 * the WPMgr brand lockup above the card slot. Matches the layout in login.tsx.
 */
export function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-[var(--color-background)] p-4">
      {/* WPMgr Fleet Hub lockup — same mark as sidebar + marketing site. */}
      <div className="flex items-center gap-2.5">
        <FleetHubLogo size={26} />
        <Wordmark className="text-base" />
      </div>
      {children}
    </main>
  );
}
