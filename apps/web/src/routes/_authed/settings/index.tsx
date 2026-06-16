import { createFileRoute, redirect } from "@tanstack/react-router";

// Redirect /settings → /settings/account so visiting the settings area root
// always lands on a concrete section. The layout route (route.tsx) owns the
// side-menu shell; this file just ensures the default child is Account.
export const Route = createFileRoute("/_authed/settings/")({
  beforeLoad: () => {
    throw redirect({ to: "/settings/account" });
  },
});
