import { createFileRoute } from "@tanstack/react-router";

import { CacheTab } from "@/features/perf/cache/CacheTab";
import { useSite } from "@/features/sites/use-sites";
import { useMe, canOperate, canManage } from "@/features/auth/use-auth";

// `/sites/$siteId/cache` — Performance Suite, Cache (Phase 7 / m36). A thin
// file-route that delegates to the feature component (recon §Web integration).
// purge/preload/enable/disable gate on operator+ (PermSiteCacheManage /
// PermSiteCachePurge); the destructive delete-everything purge gates on admin+
// (PermSiteCacheDeleteAll) → we pass canManage as canDeleteAll. The server is
// authoritative; we mirror the gates in the UI so viewers don't see write
// actions they can't perform.

export const Route = createFileRoute("/_authed/sites/$siteId/cache")({
  component: CacheTabRoute,
});

function CacheTabRoute() {
  const { siteId } = Route.useParams();
  const { data: site } = useSite(siteId);
  const { data: me } = useMe();

  const hostname = hostnameOf(site?.url ?? "");

  return (
    <section aria-label="Cache" className="px-4 pb-8 pt-6 sm:px-6">
      <CacheTab
        siteId={siteId}
        hostname={hostname}
        canOperate={canOperate(me)}
        canManage={canManage(me)}
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
