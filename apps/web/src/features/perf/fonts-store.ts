import { create } from "zustand";

// Ephemeral font-processing live state, driven by the font.queued /
// font.converting / font.ready / font.subset / font.skipped / font.failed /
// font.completed SSE events (usePerfEvents). Never server state — lives in
// Zustand per the "never mix server state into Zustand" convention. The
// authoritative font_results rows stay in TanStack Query (useFontResults).
//
// Two separate concerns are combined here:
//   1. Batch-level phase machine (queued → converting → done | failed) used by
//      FontsLiveIndicator to show "Converting fonts (3/7)…" or "Converted 7
//      fonts, saved 58%".
//   2. Per-font row ephemeral state (converting | skipped | failed) that overlays
//      the DB-persisted state in FontResultsTable. The DB rows carry
//      pending | ready | subset | negative; converting / skipped / failed are
//      SSE-only transients and are never persisted.

/** Batch-level phase for the live indicator. */
export type FontsPhase = "queued" | "converting" | "done" | "failed" | null;

/**
 * Ephemeral per-font-row state overlay from SSE frames. Merges with the DB
 * state when rendering the table badge:
 *   converting → blue spinner (in-flight, not yet in DB)
 *   skipped    → amber "Skipped (icon/variable font)"
 *   failed     → red XCircle (also surfaces when DB state = "negative")
 */
export type FontRowPhase = "converting" | "skipped" | "failed";

export interface FontsLive {
  /** Current batch phase; null when idle. */
  phase: FontsPhase;
  /** Fonts processed so far (incremented on font.ready / font.subset / font.skipped / font.failed). */
  processed: number;
  /** Total fonts expected for this batch (set on font.queued, updated on font.converting). */
  total: number;
  /** Aggregate savings percentage from the completed event. */
  savings_pct: number | null;
  /** Monotonic timestamp of the last frame (for stale auto-hide). */
  updatedAt: number;
  /** Per-source-hash ephemeral row states (cleared on done / reset). */
  fontStates: Record<string, FontRowPhase>;
}

interface FontsState {
  bySite: Record<string, FontsLive>;

  setPhase: (
    siteId: string,
    phase: FontsPhase,
    extra?: {
      processed?: number;
      total?: number;
      savings_pct?: number;
    },
  ) => void;

  /** Mark one font row as converting / skipped / failed. */
  setFontRowPhase: (siteId: string, sourceHash: string, rowPhase: FontRowPhase) => void;

  /** Increment the processed counter (font.ready / font.subset / font.skipped / font.failed). */
  incrementProcessed: (siteId: string) => void;

  reset: (siteId: string) => void;
}

const IDLE: FontsLive = {
  phase: null,
  processed: 0,
  total: 0,
  savings_pct: null,
  updatedAt: 0,
  fontStates: {},
};

export const useFontsStore = create<FontsState>((set) => ({
  bySite: {},

  setPhase: (siteId, phase, extra) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            phase,
            processed: extra?.processed ?? prev.processed,
            total: extra?.total ?? prev.total,
            savings_pct: extra?.savings_pct ?? prev.savings_pct,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  setFontRowPhase: (siteId, sourceHash, rowPhase) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            fontStates: { ...prev.fontStates, [sourceHash]: rowPhase },
            updatedAt: Date.now(),
          },
        },
      };
    }),

  incrementProcessed: (siteId) =>
    set((s) => {
      const prev = s.bySite[siteId] ?? { ...IDLE };
      return {
        bySite: {
          ...s.bySite,
          [siteId]: {
            ...prev,
            processed: prev.processed + 1,
            updatedAt: Date.now(),
          },
        },
      };
    }),

  reset: (siteId) =>
    set((s) => ({ bySite: { ...s.bySite, [siteId]: { ...IDLE } } })),
}));

/** Selector: live fonts state for one site (defaults to idle). */
export function selectFonts(state: FontsState, siteId: string): FontsLive {
  return state.bySite[siteId] ?? IDLE;
}
