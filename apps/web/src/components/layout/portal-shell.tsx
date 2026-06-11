// PortalShell — branded read-only shell for the client portal (/portal/*).
//
// Deliberately separate from AppShell/Sidebar/TopBar: those components carry
// org-switcher, command palette, bulk actions, and write-oriented navigation.
// The portal shell is intentionally minimal: client logo, two nav items, a
// theme toggle, a logout-only user menu, and an agency attribution footer.
//
// Branding: me.portal.color is applied as --color-primary on the shell root
// only, after validating it is a 6-digit hex value. Semantic tokens for all
// other surfaces (destructive, warning, chart-*) stay untouched so status
// colors never shift.

import type { ReactNode } from "react";
import { Link, useLocation, Outlet } from "@tanstack/react-router";
import { Globe, LogOut, UserRound } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { useLogout, useMe } from "@/features/auth/use-auth";
import { cn } from "@/lib/utils";

// ---------------------------------------------------------------------------
// Safe hex color validation — only applies #rrggbb values.
// ---------------------------------------------------------------------------

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

function safeBrandColor(color: string | null | undefined): string | undefined {
  if (!color) return undefined;
  return HEX_RE.test(color) ? color : undefined;
}

// ---------------------------------------------------------------------------
// Safe image URL — mirrors the pattern in features/tools/MediaCleanerPanel.tsx.
// Only allows http/https URLs; rejects data: and javascript: URIs.
// ---------------------------------------------------------------------------

function safeImgSrc(u: string | null | undefined): string | undefined {
  if (!u) return undefined;
  try {
    const parsed = new URL(u);
    if (parsed.protocol === "https:" || parsed.protocol === "http:") return u;
  } catch {
    // Not a valid URL.
  }
  return undefined;
}

// ---------------------------------------------------------------------------
// Nav items
// ---------------------------------------------------------------------------

interface NavItem {
  label: string;
  to: string;
}

const NAV_ITEMS: NavItem[] = [
  { label: "Sites", to: "/portal" },
  { label: "Reports", to: "/portal/reports" },
];

// ---------------------------------------------------------------------------
// Shell
// ---------------------------------------------------------------------------

export function PortalShell({ children }: { children?: ReactNode }) {
  const { data: me } = useMe();
  const location = useLocation();
  const logoutMutation = useLogout();
  const portal = me?.portal;

  const brandColor = safeBrandColor(portal?.color);
  const logoSrc = safeImgSrc(portal?.logo_url);
  const agencyName = portal?.agency_name ?? "";
  const clientName = portal?.client_name ?? "";
  const userName = me?.user?.name ?? me?.user?.email ?? "Account";

  // Apply the client brand color as a scoped CSS variable override.
  // Only --color-primary is overridden; all other semantic tokens are
  // untouched so status/destructive/warning colors remain stable.
  const brandStyle = brandColor
    ? ({ "--color-primary": brandColor } as React.CSSProperties)
    : undefined;

  return (
    <div
      className="flex min-h-dvh flex-col bg-[var(--color-background)] text-[var(--color-foreground)]"
      style={brandStyle}
    >
      {/* Header bar -------------------------------------------------------- */}
      <header className="sticky top-0 z-40 flex h-12 items-center border-b border-[var(--color-border)] bg-[var(--color-background)] px-4 sm:px-6">
        {/* Client identity */}
        <div className="flex min-w-0 flex-1 items-center gap-3">
          {logoSrc ? (
            <img
              src={logoSrc}
              alt={clientName}
              className="h-7 max-w-[120px] object-contain"
              onError={(e) => {
                (e.target as HTMLImageElement).style.display = "none";
              }}
            />
          ) : (
            <div className="flex items-center gap-1.5">
              <Globe
                aria-hidden="true"
                className="size-5 shrink-0 text-[var(--color-primary)]"
              />
              <span className="text-sm font-semibold tracking-tight text-[var(--color-foreground)]">
                {clientName}
              </span>
            </div>
          )}
        </div>

        {/* Primary nav */}
        <nav
          aria-label="Portal navigation"
          className="hidden items-center gap-1 sm:flex"
        >
          {NAV_ITEMS.map((item) => {
            const isActive =
              item.to === "/portal"
                ? location.pathname === "/portal" ||
                  location.pathname.startsWith("/portal/sites")
                : location.pathname.startsWith(item.to);
            return (
              <Link
                key={item.to}
                to={item.to as "/portal"}
                className={cn(
                  "rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                  isActive
                    ? "bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                    : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]",
                )}
                aria-current={isActive ? "page" : undefined}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>

        {/* Right cluster: theme toggle + user menu */}
        <div className="flex items-center gap-1 pl-4">
          <ThemeToggle />

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                aria-label="User menu"
                className="text-[var(--color-muted-foreground)]"
              >
                <UserRound aria-hidden="true" className="size-5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
              <DropdownMenuLabel className="font-normal">
                <p className="truncate text-sm font-medium text-[var(--color-foreground)]">
                  {userName}
                </p>
                {me?.user?.email ? (
                  <p className="truncate text-xs text-[var(--color-muted-foreground)]">
                    {me.user.email}
                  </p>
                ) : null}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={() => void logoutMutation.mutateAsync()}
                disabled={logoutMutation.isPending}
                className="text-[var(--color-destructive)] focus:text-[var(--color-destructive)]"
              >
                <LogOut aria-hidden="true" className="mr-2 size-4" />
                Sign out
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {/* Mobile nav (below header, visible only on sm and smaller) */}
      <nav
        aria-label="Portal navigation (mobile)"
        className="flex items-center gap-1 border-b border-[var(--color-border)] px-4 py-1.5 sm:hidden"
      >
        {NAV_ITEMS.map((item) => {
          const isActive =
            item.to === "/portal"
              ? location.pathname === "/portal" ||
                location.pathname.startsWith("/portal/sites")
              : location.pathname.startsWith(item.to);
          return (
            <Link
              key={item.to}
              to={item.to as "/portal"}
              className={cn(
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                isActive
                  ? "bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                  : "text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]",
              )}
              aria-current={isActive ? "page" : undefined}
            >
              {item.label}
            </Link>
          );
        })}
      </nav>

      {/* Main content */}
      <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
        {children ?? <Outlet />}
      </main>

      {/* Footer — agency attribution only, no product branding */}
      {agencyName ? (
        <footer className="border-t border-[var(--color-border)] px-4 py-3 sm:px-6">
          <p className="text-center text-xs text-[var(--color-muted-foreground)]">
            Managed by {agencyName}
          </p>
        </footer>
      ) : null}
    </div>
  );
}
