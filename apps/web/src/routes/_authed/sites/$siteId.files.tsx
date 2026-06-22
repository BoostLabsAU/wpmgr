import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "@/components/shared/page-header";
import { PageError } from "@/components/feedback";
import { Skeleton } from "@/components/ui/skeleton";
import { useMe, canManage, activeRole } from "@/features/auth/use-auth";

import {
  useFileManagerSettings,
  useUpdateFileManagerSettings,
} from "@/features/files/hooks/use-file-manager-settings";
import { FilesDisabledGate } from "@/features/files/FilesDisabledGate";
import { FileBrowser } from "@/features/files/FileBrowser";

// `/sites/$siteId/files` — site-level file manager tab.
//
// This is a READ-ONLY browser (P1). Write/upload/delete are P2.
//
// Authorization: `site.files.read` = admin+ (enforced by the server).
// Viewers and operators never see this tab — even if they reach the URL,
// the backend returns 403.
//
// Settings gate: the feature is off by default per site (migration m82).
// On load we fetch settings; if `enabled === false` we show FilesDisabledGate.
// Admins see an Enable button; lower roles see a "contact admin" message.

export const Route = createFileRoute("/_authed/sites/$siteId/files")({
  component: FilesTabRoute,
});

function FilesTabRoute() {
  const { siteId } = Route.useParams();
  const { data: me } = useMe();

  const manage = canManage(me);
  const role = activeRole(me);
  const isOwner = role === "owner";

  const settings = useFileManagerSettings(siteId);
  const updateSettings = useUpdateFileManagerSettings(siteId);

  const handleEnable = () => {
    updateSettings.mutate({ enabled: true });
  };

  const handleDisable = () => {
    updateSettings.mutate({ enabled: false });
  };

  return (
    <section aria-label="File manager" className="space-y-4 px-4 pb-8 pt-6 sm:px-6">
      <PageHeader
        title="Files"
        subline="Read-only file browser. All access is audited."
      />

      {settings.isPending ? (
        <FilesSettingsSkeleton />
      ) : settings.isError ? (
        <PageError
          what="Could not load file manager settings."
          why={settings.error.message}
          onRetry={() => void settings.refetch()}
          retryLabel="Reload settings"
        />
      ) : settings.data?.enabled ? (
        <FileBrowser
          siteId={siteId}
          canManage={manage}
          isOwner={isOwner}
          onDisable={handleDisable}
          isDisabling={updateSettings.isPending}
        />
      ) : (
        <FilesDisabledGate
          canManage={manage}
          isPending={updateSettings.isPending}
          onEnable={handleEnable}
        />
      )}
    </section>
  );
}

// ── Loading skeleton while settings load ───────────────────────────────────

function FilesSettingsSkeleton() {
  return (
    <div
      aria-label="Loading file manager"
      aria-busy="true"
      className="flex flex-col items-center gap-4 py-16"
    >
      <Skeleton className="size-10 rounded-lg" />
      <div className="space-y-2">
        <Skeleton className="h-3 w-48" />
        <Skeleton className="h-3 w-64" />
      </div>
      <Skeleton className="h-8 w-32 rounded-md" />
    </div>
  );
}
