import { create } from "zustand";

// Ephemeral RUCSS job indicator, driven by the rucss.queued / rucss.computing /
// rucss.completed / rucss.failed SSE events (usePerfEvents). Never server state
// — lives in Zustand per the "never mix server state into Zustand" convention.
// The authoritative results list stays in TanStack Query (useRucssResults).

export type RucssPhase = "queued" | "computing" | "done" | "failed" | null;

export interface RucssLive {
  /** Current phase; null when idle. */
  phase: RucssPhase;
  /** Reduction percentage from the completed event (available in "done" phase). */
  reduction_pct: number | null;
  /** Monotonic timestamp of the last frame (for stale auto-hide). */
  updatedAt: number;
}

interface RucssState {
  bySite: Record<string, RucssLive>;
  setPhase: (
    siteId: string,
    phase: RucssPhase,
    extra?: { reduction_pct?: number },
  ) => void;
  reset: (siteId: string) => void;
}

const IDLE: RucssLive = { phase: null, reduction_pct: null, updatedAt: 0 };

export const useRucssStore = create<RucssState>((set) => ({
  bySite: {},
  setPhase: (siteId, phase, extra) =>
    set((s) => ({
      bySite: {
        ...s.bySite,
        [siteId]: {
          phase,
          reduction_pct: extra?.reduction_pct ?? s.bySite[siteId]?.reduction_pct ?? null,
          updatedAt: Date.now(),
        },
      },
    })),
  reset: (siteId) =>
    set((s) => ({ bySite: { ...s.bySite, [siteId]: { ...IDLE } } })),
}));

/** Selector: live RUCSS state for one site (defaults to idle). */
export function selectRucss(state: RucssState, siteId: string): RucssLive {
  return state.bySite[siteId] ?? IDLE;
}
