import { useMutation, useQueryClient, type UseMutationResult } from "@tanstack/react-query";
import { computeRucss } from "@wpmgr/api";

import { toast } from "@/components/toast";
import { toError } from "@/features/auth/use-auth";

import { perfKeys } from "../perf-keys";
import { useRucssStore } from "../rucss-store";
import type { PerfActionResult } from "../types";

// Mutation that calls POST /api/v1/sites/{siteId}/perf/rucss/compute (home
// page). On success the CP enqueues a job and emits rucss.queued → rucss.
// computing → rucss.completed SSE events which drive the live indicator in
// RucssLiveIndicator. The optimistic "Queued…" state is set immediately in the
// rucss-store so the operator sees feedback even before the first SSE frame.
//
// Pattern mirrors usePurgeCache / useDbClean in useCacheStats.ts.

export function useComputeRucss(
  siteId: string,
): UseMutationResult<PerfActionResult, Error, void> {
  const qc = useQueryClient();
  const setPhase = useRucssStore((s) => s.setPhase);

  return useMutation({
    mutationFn: async () => {
      const { data, error } = await computeRucss({
        path: { siteId },
        body: {},
      });
      if (error) throw toError(error);
      return (data as PerfActionResult) ?? { ok: false };
    },
    onMutate: () => {
      // Optimistic: show "Queued…" immediately; the real rucss.queued SSE frame
      // will confirm (or override) this within a few hundred ms.
      setPhase(siteId, "queued");
    },
    onSuccess: (res) => {
      if (!res.ok) {
        toast.error("Remove Unused CSS job was not accepted.", {
          description: res.detail ?? "The agent rejected the request.",
        });
        useRucssStore.getState().reset(siteId);
        return;
      }
      // Refresh the results list now: when the page's structure already has
      // computed used-CSS, the agent gets it back immediately (a cache HIT) and
      // applies it — NO job is enqueued, so NO rucss.computing/completed SSE
      // frames ever fire and the optimistic "Queued…" would otherwise spin
      // forever. The authoritative completion still arrives via SSE for a genuine
      // MISS (which first moves the phase to "computing").
      void qc.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
      // Grace-period fallback: if after ~12s the phase is STILL "queued" (never
      // advanced to "computing" by an SSE frame), the compute hit an already-
      // optimized page — resolve the indicator to "done" and re-pull the results.
      window.setTimeout(() => {
        if (useRucssStore.getState().bySite[siteId]?.phase === "queued") {
          useRucssStore.getState().setPhase(siteId, "done");
        }
        void qc.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
      }, 12000);
    },
    onError: (err) => {
      useRucssStore.getState().reset(siteId);
      toast.error("Could not start Used-CSS computation.", {
        description: err.message,
      });
      void qc.invalidateQueries({ queryKey: perfKeys.rucss(siteId) });
    },
  });
}
