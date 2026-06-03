import { createFileRoute } from "@tanstack/react-router";

import { OptimizeTab } from "@/features/perf/optimize/OptimizeTab";
import { useSite } from "@/features/sites/use-sites";
import { useMe, canOperate } from "@/features/auth/use-auth";

// `/sites/$siteId/optimize` — Performance Suite, Optimize (Phase 7 / m36). A
// thin file-route delegating to the feature component. Settings + RUCSS clear +
// DB clean gate on operator+ (PermSitePerfConfig / PermSiteCacheManage); the
// server is authoritative and we mirror the gate in the UI.

export const Route = createFileRoute("/_authed/sites/$siteId/optimize")({
  component: OptimizeTabRoute,
});

function OptimizeTabRoute() {
  const { siteId } = Route.useParams();
  const { data: site } = useSite(siteId);
  const { data: me } = useMe();

  const hostname = hostnameOf(site?.url ?? "");

  return (
    <section aria-label="Optimize" className="px-4 pb-8 pt-6 sm:px-6">
      <OptimizeTab
        siteId={siteId}
        hostname={hostname}
        canOperate={canOperate(me)}
      />
    </section>
  );
}

function hostnameOf(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return url;
  }
}
