// Portal reports page — /portal/reports
//
// Lists completed reports with HTML/PDF download buttons.

import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "@/components/shared/page-header";
import { usePortalReports } from "@/features/portal/use-portal";
import { PortalReportsTable } from "@/features/portal/portal-reports-table";

export const Route = createFileRoute("/portal/reports")({
  component: PortalReportsPage,
});

function PortalReportsPage() {
  const {
    data: reports,
    isPending,
    isError,
    error,
    refetch,
    isFetching,
  } = usePortalReports();

  return (
    <div className="space-y-6">
      <PageHeader title="Reports" />
      <PortalReportsTable
        items={reports}
        isLoading={isPending}
        isError={isError}
        error={error}
        onRetry={() => void refetch()}
        isRetrying={isFetching}
      />
    </div>
  );
}
