import { createFileRoute, redirect } from "@tanstack/react-router";

// Redirect the bare /clients/$clientId to /clients/$clientId/sites.
// The to path and params are typed after build regenerates routeTree.gen.ts;
// until then, we cast to suppress the pre-registration errors.

export const Route = createFileRoute("/_authed/clients/$clientId/")({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: "/clients/$clientId/sites",
      params: { clientId: params.clientId },
    });
  },
  component: () => null,
});
