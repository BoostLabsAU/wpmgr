import {
  useQuery,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  listAudit,
  verifyAudit,
  type AuditEntry,
  type AuditVerify,
} from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// Fleet-wide audit log hooks backed by the /api/v1/audit endpoint.
//
// useAudit: paginated listing with optional action-prefix and site_id filters.
//   Uses offset pagination (limit/offset) matching the SDK shape.
//   Each page fetch is a separate query key so the "Load more" affordance
//   appends without invalidating existing pages.
//
// useAuditVerify: calls /api/v1/audit/verify to check the hash-chain integrity
//   of the tenant's audit log. Returns { ok, broken_at }.

export const auditKeys = {
  all: ["audit"] as const,
  lists: () => [...auditKeys.all, "list"] as const,
  list: (params: AuditParams) => [...auditKeys.lists(), params] as const,
  verify: () => [...auditKeys.all, "verify"] as const,
};

export interface AuditParams {
  action: string;
  siteId: string;
  limit: number;
  offset: number;
}

export interface UseAuditResult {
  items: AuditEntry[];
  isPending: boolean;
  isError: boolean;
  error: Error | null;
  refetch: UseQueryResult<AuditEntry[], Error>["refetch"];
}

export function useAudit(params: AuditParams): UseAuditResult {
  const result = useQuery<AuditEntry[], Error>({
    queryKey: auditKeys.list(params),
    queryFn: async () => {
      const { data, error } = await listAudit({
        query: {
          limit: params.limit,
          offset: params.offset,
          ...(params.action ? { action: params.action } : {}),
          ...(params.siteId ? { site_id: params.siteId } : {}),
        },
      });
      if (error) throw toError(error);
      return data?.items ?? [];
    },
    // Keep data alive while the user pages; don't discard the previous result
    // when offset/action change so the table does not flash empty.
    placeholderData: (prev) => prev,
  });

  return {
    items: result.data ?? [],
    isPending: result.isPending,
    isError: result.isError,
    error: result.error,
    refetch: result.refetch,
  };
}

export function useAuditVerify(): UseQueryResult<AuditVerify, Error> {
  return useQuery<AuditVerify, Error>({
    queryKey: auditKeys.verify(),
    queryFn: async () => {
      const { data, error } = await verifyAudit();
      if (error) throw toError(error);
      return data ?? { ok: true };
    },
    refetchInterval: 60_000,
  });
}
