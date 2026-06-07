import { createFileRoute } from "@tanstack/react-router";

import { SearchReplacePanel } from "@/features/tools/SearchReplacePanel";
import { useSite } from "@/features/sites/use-sites";
import { useMe, canOperate } from "@/features/auth/use-auth";

// `/sites/$siteId/tools` — site-level tools tab (#188).
//
// Currently houses the serialization-safe database search-replace tool.
// The tab is intentionally narrow in scope: destructive-adjacent utilities that
// are not part of the day-to-day monitoring/cache workflow live here.
//
// Authorization: `canOperate` mirrors PermSiteWrite (operator+), which the
// server enforces at the route level. Viewers see an explanatory notice.

export const Route = createFileRoute("/_authed/sites/$siteId/tools")({
  component: ToolsTabRoute,
});

function ToolsTabRoute() {
  const { siteId } = Route.useParams();
  // Keep the site query live for the connection badge (layout already handles
  // this, but including it here avoids a flash if the tab is deep-linked).
  const { data: _site } = useSite(siteId);
  const { data: me } = useMe();

  return (
    <section aria-label="Tools" className="px-4 pb-8 pt-6 sm:px-6">
      <SearchReplacePanel siteId={siteId} canOperate={canOperate(me)} />
    </section>
  );
}
