import { createFileRoute, Link, Outlet, redirect, useLocation } from "@tanstack/react-router";
import { ShieldCheck, Users, type LucideIcon } from "lucide-react";

import { ensureMe, isSuperadmin } from "@/features/auth/use-auth";
import { cn } from "@/lib/utils";

// ---------------------------------------------------------------------------
// Superadmin layout route — wraps all /admin/* sub-pages with a left nav.
//
// Auth gate: beforeLoad checks isSuperadmin and redirects non-superadmins to
// /sites. This gate runs on every navigation into any /admin/* route, including
// direct URL access, so sub-pages never need their own guard.
// ---------------------------------------------------------------------------

export const Route = createFileRoute("/_authed/admin")({
  beforeLoad: async ({ context }) => {
    const me = await ensureMe(context.queryClient);
    if (!isSuperadmin(me)) {
      throw redirect({ to: "/sites" });
    }
  },
  component: AdminLayout,
});

// ---------------------------------------------------------------------------
// Nav item definitions — add new admin sections here only.
// ---------------------------------------------------------------------------

type AdminNavPath = "/admin" | "/admin/vuln-feed";

interface AdminNavItem {
  label: string;
  to: AdminNavPath;
  icon: LucideIcon;
}

const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  { label: "Users", to: "/admin", icon: Users },
  { label: "Vulnerability feed", to: "/admin/vuln-feed", icon: ShieldCheck },
];

// ---------------------------------------------------------------------------
// Layout component
// ---------------------------------------------------------------------------

function AdminLayout() {
  const location = useLocation();
  const pathname = location.pathname;

  return (
    <div className="mx-auto w-full max-w-5xl">
      <div className="flex flex-col gap-6 md:flex-row md:gap-10">
        {/* Left navigation — mirrors the Settings area side-menu pattern. */}
        <nav
          aria-label="Admin"
          className={cn(
            "flex flex-row gap-1 overflow-x-auto pb-1",
            "md:w-[210px] md:shrink-0 md:flex-col md:overflow-x-visible md:pb-0",
          )}
        >
          <p
            aria-hidden="true"
            className="hidden select-none px-3 pb-1 text-xs font-medium uppercase tracking-[0.06em] text-muted-foreground md:block"
          >
            Instance Admin
          </p>

          {ADMIN_NAV_ITEMS.map((item) => {
            // Exact match for the index (/admin) so it does not also light up
            // for /admin/vuln-feed; prefix match for sub-routes of the others.
            const active =
              item.to === "/admin"
                ? pathname === "/admin"
                : pathname === item.to || pathname.startsWith(`${item.to}/`);

            const Icon = item.icon;
            return (
              <Link
                key={item.to}
                to={item.to}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "relative flex min-w-max items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                  "border-b-2 md:border-b-0 md:border-l-2",
                  active
                    ? "border-primary bg-accent text-accent-foreground"
                    : "border-transparent text-foreground hover:bg-muted/50",
                  "md:w-full md:min-w-0",
                )}
              >
                <Icon aria-hidden="true" className="size-4 shrink-0" />
                <span className="truncate">{item.label}</span>
              </Link>
            );
          })}
        </nav>

        {/* Page content */}
        <main className="min-w-0 flex-1">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
