import { useCallback, useState } from "react";

/**
 * The active view mode for the Sites page: "list" (default table) or "grid"
 * (card view with screenshot heroes).
 */
export type SitesView = "grid" | "list";

/**
 * Card size within the grid view. "comfortable" shows all metadata; "compact"
 * hides secondary fields (WP/PHP versions) behind a hover.
 */
export type CardSize = "comfortable" | "compact";

// ─── localStorage keys ────────────────────────────────────────────────────────

const VIEW_KEY = "wpmgr.sites.view";
const CARD_SIZE_KEY = "wpmgr.sites.cardSize";

const ALLOWED_VIEWS: ReadonlySet<SitesView> = new Set<SitesView>([
  "list",
  "grid",
]);
const ALLOWED_CARD_SIZES: ReadonlySet<CardSize> = new Set<CardSize>([
  "comfortable",
  "compact",
]);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function readView(): SitesView {
  // SSR-safe; privacy-mode localStorage throws are caught silently.
  if (typeof window === "undefined") return "list";
  try {
    const raw = window.localStorage.getItem(VIEW_KEY);
    if (raw && ALLOWED_VIEWS.has(raw as SitesView)) return raw as SitesView;
  } catch {
    /* non-fatal */
  }
  return "list";
}

function writeView(v: SitesView): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(VIEW_KEY, v);
  } catch {
    /* non-fatal */
  }
}

function readCardSize(): CardSize {
  if (typeof window === "undefined") return "comfortable";
  try {
    const raw = window.localStorage.getItem(CARD_SIZE_KEY);
    if (raw && ALLOWED_CARD_SIZES.has(raw as CardSize))
      return raw as CardSize;
  } catch {
    /* non-fatal */
  }
  return "comfortable";
}

function writeCardSize(s: CardSize): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(CARD_SIZE_KEY, s);
  } catch {
    /* non-fatal */
  }
}

// ─── Hooks ────────────────────────────────────────────────────────────────────

/**
 * View mode (list | grid) for the Sites page. Resolved from:
 *   1. `urlView` — the `?view=` URL param the route reads via `validateSearch`
 *   2. localStorage `wpmgr.sites.view`
 *   3. "list" default
 *
 * Both reading strategies are colocated so callers can pass `undefined` if the
 * route param is absent (or before the route validates it). Toggling writes
 * both localStorage AND calls `onToggle` so the route can keep the URL in sync.
 *
 * Pattern mirrors `useSitesDensity` (lazy initializer, SSR-safe, best-effort
 * persistence).
 */
export function useSitesView(
  urlView?: SitesView,
  onToggle?: (next: SitesView) => void,
): [SitesView, (next: SitesView) => void] {
  const [view, setViewState] = useState<SitesView>(
    () => urlView ?? readView(),
  );

  // Keep in sync when the URL param changes (e.g. user edits address bar).
  const [prevUrlView, setPrevUrlView] = useState<SitesView | undefined>(urlView);
  if (prevUrlView !== urlView && urlView !== undefined) {
    setPrevUrlView(urlView);
    setViewState(urlView);
  }

  const setView = useCallback(
    (next: SitesView) => {
      setViewState(next);
      writeView(next);
      onToggle?.(next);
    },
    [onToggle],
  );

  return [view, setView];
}

/**
 * Card size within grid view. Persisted to localStorage only (no URL param
 * — it's a presentation preference, not a shareable state).
 */
export function useCardSize(): [CardSize, (next: CardSize) => void] {
  const [size, setSizeState] = useState<CardSize>(() => readCardSize());

  const setSize = useCallback((next: CardSize) => {
    setSizeState(next);
    writeCardSize(next);
  }, []);

  return [size, setSize];
}
