// Portal layout route — /portal and all /portal/* children.
//
// Guard logic (mirrors _authed.tsx):
//   1. No session -> redirect /login?redirect=<path>
//   2. Session with role !== "client" -> redirect /sites
//      (an org member who somehow lands here should be in the agency app)
//
// The reverse gate (role==="client" inside _authed) lives in _authed.tsx.

import { createFileRoute, Outlet, redirect } from "@tanstack/react-router";

import { ensureMe } from "@/features/auth/use-auth";
import { PortalShell } from "@/components/layout/portal-shell";

export const Route = createFileRoute("/portal")({
  beforeLoad: async ({ context, location }) => {
    const me = await ensureMe(context.queryClient);

    if (!me) {
      throw redirect({
        to: "/login",
        search: { redirect: location.pathname },
      });
    }

    // An authenticated non-client (org member, API-key user, etc.) who somehow
    // ends up at /portal should land in the agency app, not the client portal.
    if (me.role !== "client") {
      throw redirect({ to: "/sites" });
    }
  },
  component: PortalLayout,
});

function PortalLayout() {
  return (
    <PortalShell>
      <Outlet />
    </PortalShell>
  );
}
