import { useMutation, type UseMutationResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

// useDbTableAction — calls POST /api/v1/sites/{siteId}/perf/db/table-action.
//
// The endpoint is synchronous: the agent processes all requested tables
// sequentially and returns a per-table result array in the ACK body.
// Callers wire success/error toasts themselves (action context differs between
// per-row and bulk, so toast logic lives in the component).
//
// The CP route requires PermSiteManage (admin-level). A 403 surfaces as an
// Error and the caller's onError toast fires.

export type DbTableActionVerb =
  | "optimize"
  | "repair"
  | "drop"
  | "empty"
  | "analyze"
  | "convert_innodb";

export interface DBTableActionTableResult {
  table: string;
  /** done | skipped | error | not_found | rejected */
  status: "done" | "skipped" | "error" | "not_found" | "rejected";
  detail?: string;
}

export interface DBTableActionResult {
  ok: boolean;
  job_id: string;
  action: string;
  results?: DBTableActionTableResult[];
  detail?: string;
  /** Advisory field when no recent backup was found (drop/empty only). */
  backup_warning?: string;
}

export interface DBTableActionRequest {
  job_id: string;
  action: DbTableActionVerb;
  tables: string[];
  /** Type-to-confirm value (validated by the CP handler, not the agent). */
  confirm?: string;
}

export function useDbTableAction(
  siteId: string,
): UseMutationResult<DBTableActionResult, Error, DBTableActionRequest> {
  return useMutation({
    mutationFn: async (req: DBTableActionRequest): Promise<DBTableActionResult> => {
      const { data, error } = await client.post<DBTableActionResult, unknown, false>({
        url: `/api/v1/sites/${siteId}/perf/db/table-action`,
        body: req,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from db_table_action");
      return data;
    },
  });
}

/** Generate a UUID v4 job_id (no external dep needed). */
export function newJobId(): string {
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === "x" ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
