import { create } from "zustand";

// Live preload/purge progress, keyed by site. This is EPHEMERAL UI state driven
// by the cache.preload.* / cache.purge.* SSE frames — never server state, so it
// lives in Zustand (per the "never mix server state into Zustand" rule). The
// authoritative gauges (page count, last purge/preload) stay in the TanStack
// Query cache via useCacheStats.

export interface PreloadProgress {
  /** Phase the live action is in; null when idle. */
  phase: "purging" | "preloading" | null;
  /** Pages preloaded so far (cache.preload.progress). */
  done: number;
  /** Total pages to preload (cache.preload.started / progress). */
  total: number;
  /** Monotonic timestamp of the last frame (for stale auto-hide). */
  updatedAt: number;
}

interface PreloadState {
  bySite: Record<string, PreloadProgress>;
  start: (siteId: string, phase: "purging" | "preloading", total: number) => void;
  progress: (siteId: string, done: number, total: number) => void;
  finish: (siteId: string) => void;
}

const IDLE: PreloadProgress = {
  phase: null,
  done: 0,
  total: 0,
  updatedAt: 0,
};

export const usePreloadStore = create<PreloadState>((set) => ({
  bySite: {},
  start: (siteId, phase, total) =>
    set((s) => ({
      bySite: {
        ...s.bySite,
        [siteId]: { phase, done: 0, total, updatedAt: Date.now() },
      },
    })),
  progress: (siteId, done, total) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? IDLE;
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            phase: prev.phase ?? "preloading",
            done,
            total: total || prev.total,
            updatedAt: Date.now(),
          },
        },
      };
    }),
  finish: (siteId) =>
    set((s) => ({
      bySite: { ...s.bySite, [siteId]: { ...IDLE } },
    })),
}));

/** Selector: the live progress for one site (defaults to idle). */
export function selectPreload(
  state: PreloadState,
  siteId: string,
): PreloadProgress {
  return state.bySite[siteId] ?? IDLE;
}
