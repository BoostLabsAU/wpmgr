// Client-reports domain hooks.
//
// Pattern mirrors use-clients.ts: typed key factory, useQuery/useMutation hooks
// that unwrap {data, error} and throw toError(error), mutations invalidate
// affected keys in onSuccess + emit toast outcomes.

import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryResult,
  type UseMutationResult,
} from "@tanstack/react-query";
import {
  getClientReportSchedule,
  putClientReportSchedule,
  listClientReports,
  generateClientReport,
  getClientReport,
  deleteClientReport,
  type ClientReport,
  type ClientReportList,
  type ClientReportSchedule,
  type ClientReportScheduleUpdate,
  type GenerateClientReportRequest,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

// ---------------------------------------------------------------------------
// Key factory
// ---------------------------------------------------------------------------

export const reportKeys = {
  all: ["reports"] as const,
  schedules: () => [...reportKeys.all, "schedule"] as const,
  schedule: (clientId: string) =>
    [...reportKeys.schedules(), clientId] as const,
  lists: () => [...reportKeys.all, "list"] as const,
  list: (clientId: string) => [...reportKeys.lists(), clientId] as const,
  detail: (clientId: string, reportId: string) =>
    [...reportKeys.all, "detail", clientId, reportId] as const,
};

// ---------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------

export function useReportSchedule(
  clientId: string,
): UseQueryResult<ClientReportSchedule, Error> {
  return useQuery({
    queryKey: reportKeys.schedule(clientId),
    queryFn: async () => {
      const { data, error } = await getClientReportSchedule({
        path: { clientId },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!clientId,
  });
}

export function useUpdateReportSchedule(): UseMutationResult<
  ClientReportSchedule,
  Error,
  { clientId: string; body: ClientReportScheduleUpdate }
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ clientId, body }) => {
      const { data, error } = await putClientReportSchedule({
        path: { clientId },
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (_data, { clientId }) => {
      void queryClient.invalidateQueries({
        queryKey: reportKeys.schedule(clientId),
      });
      toast.success("Report schedule saved");
    },
  });
}

// ---------------------------------------------------------------------------
// Report list
// ---------------------------------------------------------------------------

export function useReports(
  clientId: string,
): UseQueryResult<ClientReportList, Error> {
  return useQuery({
    queryKey: reportKeys.list(clientId),
    queryFn: async () => {
      const { data, error } = await listClientReports({
        path: { clientId },
        query: { limit: 20 },
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!clientId,
    // Poll every 5 s while any report is still in-flight so the status badge
    // updates without a manual refresh.
    refetchInterval: (query) =>
      query.state.data?.items.some(
        (r) => r.status === "queued" || r.status === "generating",
      )
        ? 5000
        : false,
  });
}

export function useReport(
  clientId: string,
  reportId: string,
  enabled = true,
): UseQueryResult<ClientReport, Error> {
  return useQuery({
    queryKey: reportKeys.detail(clientId, reportId),
    queryFn: async () => {
      const { data, error, response } = await getClientReport({
        path: { clientId, reportId },
      });
      if (response?.status === 404) throw new Error("Report not found");
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    enabled: !!clientId && !!reportId && enabled,
    // Poll while job is in flight so status updates without a manual refresh.
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      if (status === "queued" || status === "generating") return 3000;
      return false;
    },
  });
}

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

export function useGenerateReport(): UseMutationResult<
  ClientReport,
  Error,
  { clientId: string; body?: GenerateClientReportRequest }
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ clientId, body }) => {
      const { data, error } = await generateClientReport({
        path: { clientId },
        body: body ?? {},
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response");
      return data;
    },
    onSuccess: (_data, { clientId }) => {
      void queryClient.invalidateQueries({
        queryKey: reportKeys.list(clientId),
      });
      toast.success("Report generation started");
    },
  });
}

export function useDeleteReport(): UseMutationResult<
  void,
  Error,
  { clientId: string; reportId: string }
> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ clientId, reportId }) => {
      const { error, response } = await deleteClientReport({
        path: { clientId, reportId },
      });
      if (response?.status === 404) throw new Error("Report not found");
      if (error) throw toError(error);
    },
    onSuccess: (_data, { clientId }) => {
      void queryClient.invalidateQueries({
        queryKey: reportKeys.list(clientId),
      });
      toast.success("Report deleted");
    },
  });
}
