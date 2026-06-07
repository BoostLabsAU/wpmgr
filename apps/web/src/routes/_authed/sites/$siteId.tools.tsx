import { createFileRoute } from "@tanstack/react-router";

import { SearchReplacePanel } from "@/features/tools/SearchReplacePanel";
import { DbSnapshotPanel } from "@/features/tools/DbSnapshotPanel";
import { useSite } from "@/features/sites/use-sites";
import { useMe, canOperate } from "@/features/auth/use-auth";

// `/sites/$siteId/tools` — site-level tools tab.
//
// Houses destructive-adjacent utilities that are not part of the day-to-day
// monitoring/cache workflow:
//   #188 — serialization-safe database search-replace.
//   #189 — local database snapshots (fast local safety-net before risky changes).
//
// Authorization: `canOperate` mirrors PermSiteWrite (operator+), which the
// server enforces at the route level. Viewers can see the snapshot list but
// cannot take, revert, or delete snapshots.

export const Route = createFileRoute("/_authed/sites/$siteId/tools")({
  component: ToolsTabRoute,
});

function ToolsTabRoute() {
  const { siteId } = Route.useParams();
  // Keep the site query live for the connection badge (layout already handles
  // this, but including it here avoids a flash if the tab is deep-linked).
  const { data: _site } = useSite(siteId);
  const { data: me } = useMe();
  const operable = canOperate(me);

  return (
    <section aria-label="Tools" className="space-y-10 px-4 pb-8 pt-6 sm:px-6">
      {/* #189 — Database Snapshots: fast local safety-net. Placed first so
          operators can capture a snapshot before using search-replace below. */}
      <DbSnapshotPanel siteId={siteId} canOperate={operable} />

      {/* Divider */}
      <div aria-hidden="true" className="border-t border-border" />

      {/* #188 — Serialization-safe database search-replace. */}
      <SearchReplacePanel siteId={siteId} canOperate={operable} />
    </section>
  );
}
