"use client";

// Desktop disclosure-button megamenu. Triggers live in the sticky header;
// panels portal to document.body as fixed siblings so they escape the
// backdrop-blur stacking context and max-width Container clip.
//
// A11y: W3C APG Disclosure Navigation (NOT role=menu). Triggers are buttons
// with aria-expanded + aria-controls. Panel links are plain <a> elements.
// Keyboard: Tab walks through panel links in DOM order, Escape closes the
// open panel and returns focus to the trigger, Enter/Space toggles.
// Hover-intent: open after ~120ms, close after ~275ms; safe-triangle keeps
// the panel open while the cursor moves diagonally toward it.

import { useState, useRef, useEffect, useCallback, useId } from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "motion/react";
import { cn } from "@/lib/utils";
import { Icon } from "@/components/ui/icon";
import { MegaMenuPanel } from "./mega-menu-panel";
import {
  NAV_ITEMS,
  FEATURES_COLUMNS,
  SOLUTIONS_COLUMNS,
  type PanelId,
} from "./nav-data";

// ---------------------------------------------------------------------------
// Motion constants
// ---------------------------------------------------------------------------

const PANEL_MOTION = {
  initial: { opacity: 0, y: 8 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: 4 },
  transition: {
    duration: 0.24,
    ease: [0.22, 1, 0.36, 1] as [number, number, number, number],
  },
};

const REDUCED_PANEL_MOTION = {
  initial: { opacity: 0 },
  animate: { opacity: 1 },
  exit: { opacity: 0 },
  transition: { duration: 0.08 },
};

// ---------------------------------------------------------------------------
// Hook: detect prefers-reduced-motion
// ---------------------------------------------------------------------------

function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    const mq = window.matchMedia("(prefers-reduced-motion: reduce)");
    setReduced(mq.matches);
    const handler = (e: MediaQueryListEvent) => setReduced(e.matches);
    mq.addEventListener("change", handler);
    return () => mq.removeEventListener("change", handler);
  }, []);
  return reduced;
}

// ---------------------------------------------------------------------------
// Hook: hover-intent with safe-triangle
//
// The safe-triangle prevents the panel from closing when the cursor moves
// diagonally from the trigger toward the panel. We compute a triangle from
// the current cursor position to the two near corners of the panel element
// and keep the panel open while the cursor is inside that triangle.
// ---------------------------------------------------------------------------

type Point = { x: number; y: number };

function cross(o: Point, a: Point, b: Point): number {
  return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
}

function pointInTriangle(p: Point, a: Point, b: Point, c: Point): boolean {
  const d1 = cross(p, a, b);
  const d2 = cross(p, b, c);
  const d3 = cross(p, c, a);
  const hasNeg = d1 < 0 || d2 < 0 || d3 < 0;
  const hasPos = d1 > 0 || d2 > 0 || d3 > 0;
  return !(hasNeg && hasPos);
}

// ---------------------------------------------------------------------------
// Individual trigger button
// ---------------------------------------------------------------------------

type TriggerProps = {
  label: string;
  panelId: PanelId;
  controlsId: string;
  isOpen: boolean;
  onOpen: () => void;
  onClose: () => void;
  onToggle: () => void;
  triggerRef: React.RefObject<HTMLButtonElement | null>;
  onPointerEnter: (e: React.PointerEvent<HTMLButtonElement>) => void;
  onPointerLeave: (e: React.PointerEvent<HTMLButtonElement>) => void;
};

function NavTrigger({
  label,
  controlsId,
  isOpen,
  onToggle,
  triggerRef,
  onPointerEnter,
  onPointerLeave,
}: TriggerProps) {
  return (
    <button
      ref={triggerRef}
      type="button"
      aria-expanded={isOpen}
      aria-controls={controlsId}
      onClick={onToggle}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
      className={cn(
        "inline-flex items-center gap-1 text-sm font-medium cursor-pointer",
        "rounded-sm transition-colors duration-[var(--duration-fast)]",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]",
        isOpen
          ? "text-foreground"
          : "text-[var(--muted-foreground)] hover:text-foreground",
      )}
    >
      {label}
      <Icon
        name="ChevronDown"
        size={14}
        strokeWidth={2}
        className={cn(
          "transition-transform duration-[var(--duration-fast)] ease-[var(--ease-out-quint)]",
          isOpen && "rotate-180",
        )}
      />
    </button>
  );
}

// ---------------------------------------------------------------------------
// MegaMenu
// ---------------------------------------------------------------------------

export function MegaMenu() {
  const [openPanel, setOpenPanel] = useState<PanelId | null>(null);
  const [mounted, setMounted] = useState(false);
  const reducedMotion = usePrefersReducedMotion();

  // Stable IDs for aria-controls
  const featuresId = useId();
  const solutionsId = useId();

  // Trigger refs so we can return focus on Escape
  const featuresTriggerRef = useRef<HTMLButtonElement>(null);
  const solutionsTriggerRef = useRef<HTMLButtonElement>(null);

  // Panel DOM ref for safe-triangle calculation
  const panelRef = useRef<HTMLDivElement>(null);

  // Hover-intent timers
  const openTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const closeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Last known cursor position for safe-triangle check
  const cursorRef = useRef<Point>({ x: 0, y: 0 });

  // Track which panel the cursor is in (trigger area or panel area)
  const inTriggerRef = useRef(false);
  const inPanelRef = useRef(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  // Track global cursor position (needed for safe-triangle)
  useEffect(() => {
    function onMouseMove(e: MouseEvent) {
      cursorRef.current = { x: e.clientX, y: e.clientY };
    }
    window.addEventListener("mousemove", onMouseMove);
    return () => window.removeEventListener("mousemove", onMouseMove);
  }, []);

  const clearTimers = useCallback(() => {
    if (openTimerRef.current) clearTimeout(openTimerRef.current);
    if (closeTimerRef.current) clearTimeout(closeTimerRef.current);
    openTimerRef.current = null;
    closeTimerRef.current = null;
  }, []);

  const close = useCallback(() => {
    clearTimers();
    setOpenPanel(null);
  }, [clearTimers]);

  const openWith = useCallback(
    (id: PanelId) => {
      clearTimers();
      setOpenPanel(id);
    },
    [clearTimers],
  );

  // Escape key closes the panel and returns focus to the trigger
  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape" && openPanel !== null) {
        close();
        if (openPanel === "features") featuresTriggerRef.current?.focus();
        if (openPanel === "solutions") solutionsTriggerRef.current?.focus();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [openPanel, close]);

  // Click outside closes
  useEffect(() => {
    if (!openPanel) return;
    function onPointerDown(e: PointerEvent) {
      const target = e.target as Node;
      if (!panelRef.current?.contains(target)) {
        // Check if click is on a trigger button (toggle handles that)
        if (
          featuresTriggerRef.current?.contains(target) ||
          solutionsTriggerRef.current?.contains(target)
        )
          return;
        close();
      }
    }
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [openPanel, close]);

  // Hover-intent helpers
  function scheduleOpen(id: PanelId) {
    clearTimers();
    openTimerRef.current = setTimeout(() => openWith(id), 120);
  }

  function scheduleClose() {
    clearTimers();
    closeTimerRef.current = setTimeout(() => {
      // Only close if the cursor is not in either the trigger or the panel.
      if (!inTriggerRef.current && !inPanelRef.current) {
        setOpenPanel(null);
      }
    }, 275);
  }

  // Safe-triangle check: keep panel open while cursor moves toward the panel.
  function isCursorInSafeTriangle(triggerEl: HTMLButtonElement): boolean {
    const panel = panelRef.current;
    if (!panel) return false;
    const panelRect = panel.getBoundingClientRect();
    const triggerRect = triggerEl.getBoundingClientRect();
    const cursor = cursorRef.current;
    // Triangle: cursor apex -> two near corners of the panel
    const topLeft: Point = { x: panelRect.left, y: panelRect.top };
    const topRight: Point = { x: panelRect.right, y: panelRect.top };
    const triggerCenter: Point = {
      x: (triggerRect.left + triggerRect.right) / 2,
      y: triggerRect.bottom,
    };
    return (
      pointInTriangle(cursor, triggerCenter, topLeft, topRight) ||
      pointInTriangle(cursor, topLeft, topRight, cursor) // degenerate fallback
    );
  }

  // -------------------------------------------------------------------------
  // Trigger-level pointer handlers (one pair per trigger)
  // -------------------------------------------------------------------------

  function makeTriggerPointerHandlers(id: PanelId) {
    return {
      onPointerEnter: (_e: React.PointerEvent<HTMLButtonElement>) => {
        inTriggerRef.current = true;
        scheduleOpen(id);
      },
      onPointerLeave: (e: React.PointerEvent<HTMLButtonElement>) => {
        inTriggerRef.current = false;
        // Check safe-triangle before scheduling close
        const triggerEl = e.currentTarget as HTMLButtonElement;
        setTimeout(() => {
          if (isCursorInSafeTriangle(triggerEl)) return;
          if (!inPanelRef.current) scheduleClose();
        }, 40);
      },
    };
  }

  const featuresHandlers = makeTriggerPointerHandlers("features");
  const solutionsHandlers = makeTriggerPointerHandlers("solutions");

  // Panel-level pointer handlers
  const panelPointerEnter = useCallback(() => {
    inPanelRef.current = true;
    clearTimers();
  }, [clearTimers]);

  const panelPointerLeave = useCallback(() => {
    inPanelRef.current = false;
    scheduleClose();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Toggle for click/keyboard
  function toggle(id: PanelId) {
    setOpenPanel((prev) => (prev === id ? null : id));
    clearTimers();
  }

  const panelMotionProps = reducedMotion ? REDUCED_PANEL_MOTION : PANEL_MOTION;

  const controlsId = (id: PanelId) =>
    id === "features" ? featuresId : solutionsId;

  const triggerRef = (id: PanelId) =>
    id === "features" ? featuresTriggerRef : solutionsTriggerRef;

  return (
    <>
      {/* Desktop nav links */}
      <nav
        className="hidden items-center gap-6 md:flex"
        aria-label="Main navigation"
      >
        {NAV_ITEMS.map((item) => {
          if (item.kind === "panel") {
            const handlers =
              item.id === "features" ? featuresHandlers : solutionsHandlers;
            return (
              <NavTrigger
                key={item.id}
                label={item.label}
                panelId={item.id}
                controlsId={controlsId(item.id)}
                isOpen={openPanel === item.id}
                onOpen={() => openWith(item.id)}
                onClose={close}
                onToggle={() => toggle(item.id)}
                triggerRef={triggerRef(item.id)}
                onPointerEnter={handlers.onPointerEnter}
                onPointerLeave={handlers.onPointerLeave}
              />
            );
          }
          return (
            <a
              key={item.href}
              href={item.href}
              {...(item.external
                ? { target: "_blank", rel: "noreferrer noopener" }
                : {})}
              className="text-sm font-medium text-[var(--muted-foreground)] transition-colors duration-[var(--duration-fast)] hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] rounded-sm"
            >
              {item.label}
            </a>
          );
        })}
      </nav>

      {/* Portaled panel overlay */}
      {mounted &&
        createPortal(
          <AnimatePresence>
            {openPanel !== null && (
              <>
                {/* Dismiss scrim. Starts below the 64px header so it never
                    covers the trigger buttons: otherwise, with one panel open,
                    the scrim (portaled after the header at the same z) would
                    swallow pointer events on the other triggers and the menu
                    could not switch panels on hover or click. */}
                <div
                  className="fixed inset-x-0 bottom-0 top-16 z-40"
                  aria-hidden
                  onClick={close}
                />
                {/* Panel */}
                <motion.div
                  ref={panelRef}
                  id={controlsId(openPanel)}
                  role="region"
                  aria-label={`${openPanel === "features" ? "Features" : "Solutions"} navigation`}
                  {...panelMotionProps}
                  className={cn(
                    "fixed left-0 right-0 top-16 z-50",
                    "border-b border-[var(--border)] bg-card",
                    "shadow-[var(--shadow-lg)]",
                  )}
                  onPointerEnter={panelPointerEnter}
                  onPointerLeave={panelPointerLeave}
                >
                  <MegaMenuPanel
                    panelId={openPanel}
                    featuresColumns={FEATURES_COLUMNS}
                    solutionsColumns={SOLUTIONS_COLUMNS}
                    onClose={close}
                  />
                </motion.div>
              </>
            )}
          </AnimatePresence>,
          document.body,
        )}
    </>
  );
}
