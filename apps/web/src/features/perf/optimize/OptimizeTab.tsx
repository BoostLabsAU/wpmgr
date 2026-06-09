import { PageError } from "@/components/feedback";
import { Skeleton } from "@/components/ui/skeleton";

import { usePerfConfig, useUpdatePerfConfig } from "../hooks/usePerfConfig";
import { usePerfEvents } from "../hooks/usePerfEvents";
import type { PerfConfig } from "../types";
import { CssJsSection } from "./CssJsSection";
import { FontsSection } from "./FontsSection";
import { MediaHtmlSection } from "./MediaHtmlSection";
import { CdnSection } from "./CdnSection";
import { BloatSection } from "./BloatSection";
import { DatabaseSection } from "./DatabaseSection";
import { RucssResultsTable } from "./RucssResultsTable";
import { FontResultsTable } from "./FontResultsTable";
import { RumSection } from "./RumSection";
import { RumResultsTable } from "./RumResultsTable";

// OptimizeTab — the asset-optimization entry surface. Settings sections
// (CSS/JS, Fonts, Media/HTML, CDN, Bloat, Database, RUM) plus results tables
// (RUCSS, Fonts, RUM). SSE is wired here via usePerfEvents so config, RUCSS
// results, and RUM rollup data stay live. Every setting autosaves
// (optimistic PUT with rollback on error).

export interface OptimizeTabProps {
  siteId: string;
  hostname: string;
  /** operator+ — change settings, clear RUCSS, clean DB. */
  canOperate: boolean;
}

export function OptimizeTab({ siteId, hostname, canOperate }: OptimizeTabProps) {
  usePerfEvents(siteId);

  const config = usePerfConfig(siteId);
  const update = useUpdatePerfConfig(siteId);

  function save(patch: Partial<PerfConfig>) {
    if (!config.data) return;
    update.mutate({ ...config.data, ...patch });
  }

  if (config.isPending) {
    return <OptimizeTabSkeleton />;
  }

  if (config.isError || !config.data) {
    return (
      <PageError
        what="Could not load this site's optimization settings."
        why={config.error?.message ?? "Unknown error"}
        onRetry={() => void config.refetch()}
        retryLabel="Reload optimization"
      />
    );
  }

  const cfg = config.data;
  const disabled = !canOperate || update.isPending;
  const saving = update.isPending;

  return (
    <div className="space-y-4">
      <CssJsSection config={cfg} save={save} disabled={disabled} saving={saving} />
      <FontsSection config={cfg} save={save} disabled={disabled} saving={saving} />
      <MediaHtmlSection
        config={cfg}
        save={save}
        disabled={disabled}
        saving={saving}
      />
      <CdnSection config={cfg} save={save} disabled={disabled} saving={saving} />
      <BloatSection config={cfg} save={save} disabled={disabled} saving={saving} />
      <DatabaseSection
        siteId={siteId}
        config={cfg}
        save={save}
        disabled={disabled}
        saving={saving}
        canOperate={canOperate}
      />
      <RucssResultsTable
        siteId={siteId}
        hostname={hostname}
        canOperate={canOperate}
      />
      <FontResultsTable
        siteId={siteId}
        hostname={hostname}
        canOperate={canOperate}
      />
      <RumSection
        config={cfg}
        save={save}
        disabled={disabled}
        saving={saving}
      />
      <RumResultsTable siteId={siteId} perSite />
    </div>
  );
}

function OptimizeTabSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Loading optimization settings"
      className="space-y-4"
    >
      <span className="sr-only">Loading optimization settings</span>
      {Array.from({ length: 4 }).map((_, i) => (
        <Skeleton key={i} className="h-48 w-full rounded-xl" />
      ))}
    </div>
  );
}
