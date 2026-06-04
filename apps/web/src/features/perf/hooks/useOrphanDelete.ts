// useOrphanDelete — calls POST /api/v1/sites/{siteId}/perf/db/orphan-delete.
//
// The endpoint is async-on-the-agent: the CP signs the eligible subset,
// dispatches the command, and returns immediately with job_id + counts.
// Per-item progress arrives via SSE (db.orphan.delete.progress /
// db.orphan.delete.completed) which usePerfEvents fans into the
// useOrphanDeleteStore Zustand slice.
//
// The CP re-classifies before signing, so AcceptedCount may be smaller than
// Items.length if some items became ineligible between the UI load and the
// DELETE press. The component surfaces both counts in the result toast.

import { useMutation, type UseMutationResult } from "@tanstack/react-query";
import { client } from "@wpmgr/api";

import { toError } from "@/features/auth/use-auth";

/** Artefact category — must match the agent-side kind constants exactly. */
export type OrphanItemKind = "option" | "cron" | "table";

/** One item the operator has selected for deletion. */
export interface OrphanDeleteItem {
  /** "option" | "cron" | "table" */
  kind: OrphanItemKind;
  /** option_name, cron hook name, or full table name (including DB prefix). */
  name: string;
  /** Corpus-attributed owner slug (used by the CP and agent for re-verify). */
  owner_slug: string;
}

/** Request body sent to POST /perf/db/orphan-delete. */
export interface OrphanDeleteRequest {
  items: OrphanDeleteItem[];
  /** Type-to-confirm token. Must match the expected grammar exactly (case-insensitive CP-side). */
  confirm: string;
}

/** Immediate ACK returned by the CP on success (async job started). */
export interface OrphanDeleteResponse {
  job_id: string;
  /** Count of items that passed re-classify and were signed to the agent. */
  accepted_count: number;
  /** Count of items the CP dropped (ineligible or attribution drifted). */
  dropped_count: number;
  /** Advisory when no recent backup exists. Non-empty = show nudge. */
  backup_warning?: string;
}

/**
 * Compute the type-to-confirm token that the CP will validate on the
 * server-side. The grammar:
 *   1 item → the artefact name itself
 *   N items, same kind → "DELETE N OPTIONS" | "DELETE N CRON" | "DELETE N TABLES"
 *   N items, mixed kinds → "DELETE N ORPHANS"
 *
 * Both the CP and this function uppercase before comparison, so the token
 * is always uppercase here.
 */
export function computeConfirmToken(items: OrphanDeleteItem[]): string {
  if (items.length === 0) return "";

  if (items.length === 1) {
    const item = items[0];
    // Non-null assertion safe: items.length === 1 guarantees index 0 exists.
    return item!.name;
  }

  const kindSet = new Set(items.map((i) => i.kind));
  if (kindSet.size === 1) {
    const kind = items[0]!.kind;
    const kindLabel =
      kind === "option" ? "OPTIONS" : kind === "cron" ? "CRON" : "TABLES";
    return `DELETE ${items.length} ${kindLabel}`;
  }

  return `DELETE ${items.length} ORPHANS`;
}

/**
 * Mutation hook for the orphan-delete endpoint.
 *
 * Usage:
 *   const mutation = useOrphanDelete(siteId);
 *   mutation.mutate({ items, confirm: computeConfirmToken(items) });
 */
export function useOrphanDelete(
  siteId: string,
): UseMutationResult<OrphanDeleteResponse, Error, OrphanDeleteRequest> {
  return useMutation({
    mutationFn: async (
      req: OrphanDeleteRequest,
    ): Promise<OrphanDeleteResponse> => {
      const { data, error } = await client.post<
        OrphanDeleteResponse,
        unknown,
        false
      >({
        url: `/api/v1/sites/${siteId}/perf/db/orphan-delete`,
        body: req,
        credentials: "include",
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from orphan-delete");
      return data;
    },
  });
}
