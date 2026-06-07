import { useMutation, type UseMutationResult } from "@tanstack/react-query";
import {
  runSearchReplace,
  type SearchReplaceRequest,
  type SearchReplaceResult,
} from "@wpmgr/api";
import { toError } from "@/features/auth/use-auth";

// useSearchReplace — POST /api/v1/sites/{siteId}/perf/db/search-replace
//
// The search_replace command is synchronous: the agent scans (or rewrites) the
// whole database and returns the full row counts in the ACK body. No async
// progress; no SSE is emitted by the CP for this command.
//
// The endpoint requires PermSiteWrite (operator+). A 403 surfaces as an Error
// and the caller's onError fires. The UI presents a dry-run count before
// allowing an apply (dry_run=false) call — this hook is called twice with the
// same inputs: once dry_run=true (preview) and once dry_run=false (apply).

// Re-export generated types so consumers do not have to import from two places.
export type { SearchReplaceRequest, SearchReplaceResult };

/** TanStack Query mutation for the search-replace command. */
export function useSearchReplace(
  siteId: string,
): UseMutationResult<SearchReplaceResult, Error, SearchReplaceRequest> {
  return useMutation({
    mutationFn: async (
      body: SearchReplaceRequest,
    ): Promise<SearchReplaceResult> => {
      const { data, error } = await runSearchReplace({
        path: { siteId },
        body,
      });
      if (error) throw toError(error);
      if (!data) throw new Error("Empty response from search_replace");
      return data;
    },
  });
}
