import * as React from "react";
import * as RadixDialog from "@radix-ui/react-dialog";
import { AnimatePresence, motion } from "motion/react";

import { cn } from "@/lib/utils";
import { dur, ease, fade, scaleIn } from "@/lib/motion-presets";

// Dialog primitive built on @radix-ui/react-dialog.
//
// Why Radix: the previous hand-rolled implementation locked `document.body`
// scroll but the content panel overflowed a viewport-tall dialog past the
// top and bottom with no way to reach clipped content. Radix provides
// scroll-lock, focus-trap, ESC handling, and ARIA semantics; we add
// scrollable content and keep the motion enter/exit animations via
// `AnimatePresence`.
//
// Public API — identical to the previous implementation so all 24 call
// sites compile without changes.
//
// Exit animation fix (overlay-leak bug #99): the previous implementation used
// `forceMount` on the Portal so it remained in the DOM permanently. Radix
// Dialog v1.1+ removes the body scroll-lock and pointer-events guard when
// `open` goes false — but with `forceMount` the Portal element stays in the
// DOM, and on some code-paths Radix keeps the lock because it still sees a
// mounted Portal. The fix is three-part:
//   1. Remove `forceMount` from the Portal so Radix fully unmounts it (and
//      cleans up its body guards) once the exit animation finishes.
//   2. Move `AnimatePresence` OUTSIDE the Portal so the exit animation can
//      play while the Portal (and its Radix state) is still alive.
//   3. Add a mount-time effect to defensively clear any residual body styles
//      so a component that unmounts mid-close can never leave the page broken.
//
// Scroll fix: the content panel is capped at `max-h-[calc(100dvh-2rem)]`
// and scrolls internally with `overflow-y-auto overscroll-contain`. `dvh`
// tracks mobile browser chrome so the dialog top and bottom are always
// reachable. The overlay is a fixed-inset flex container that centres the
// panel with `p-4` clearance.

// ---------------------------------------------------------------------------
// Internal context — threads `open` from Dialog → DialogContent so the
// AnimatePresence key inside the Portal can gate on it.
// ---------------------------------------------------------------------------

const DialogOpenContext = React.createContext<boolean>(false);

// ---------------------------------------------------------------------------
// Defensive cleanup — clears Radix's residual body pointer-events / overflow
// styles if a dialog unmounts without completing its close sequence (e.g.
// the parent conditionally removes the Dialog element before the exit
// animation finishes). Called on unmount of DialogContent.
// ---------------------------------------------------------------------------

function clearBodyLock() {
  // Radix Dialog sets pointer-events: none and overflow: hidden on document.body.
  // Clear both so a prematurely-unmounted dialog can never leave the page frozen.
  if (typeof document === "undefined") return;
  document.body.style.removeProperty("pointer-events");
  document.body.style.removeProperty("overflow");
  // Radix also adds a data attribute it uses to track open dialogs.
  // Remove it so the lock counter is consistent.
  document.body.removeAttribute("data-scroll-locked");
}

// ---------------------------------------------------------------------------
// Dialog — controlled root
// ---------------------------------------------------------------------------

export interface DialogProps {
  open: boolean;
  onClose: () => void;
  children: React.ReactNode;
}

export function Dialog({ open, onClose, children }: DialogProps) {
  return (
    <RadixDialog.Root
      open={open}
      onOpenChange={(next) => {
        // Radix fires onOpenChange(false) on: ESC keydown, overlay click, and
        // any programmatic close. Route all three to onClose so existing call
        // sites keep working — they never interact with Radix directly.
        if (!next) onClose();
      }}
    >
      <DialogOpenContext.Provider value={open}>
        {children}
      </DialogOpenContext.Provider>
    </RadixDialog.Root>
  );
}

// ---------------------------------------------------------------------------
// DialogContent — portal, overlay, animated panel
// ---------------------------------------------------------------------------

export interface DialogContentProps {
  ariaLabelledBy?: string;
  ariaDescribedBy?: string;
  className?: string;
  children: React.ReactNode;
}

export function DialogContent({
  ariaLabelledBy,
  ariaDescribedBy,
  className,
  children,
}: DialogContentProps) {
  // Read `open` from the context set by <Dialog>.
  const open = React.useContext(DialogOpenContext);

  // Defensive cleanup on unmount: if the component tree is torn down before
  // the exit animation completes (e.g. a route navigation while a dialog is
  // open), Radix may leave pointer-events:none on the body. Clear it.
  React.useEffect(() => {
    return () => {
      clearBodyLock();
    };
  }, []);

  return (
    // AnimatePresence is OUTSIDE the Portal so the exit animation plays while
    // the Portal (and Radix's open-state bookkeeping) is still alive. When
    // `open` is false, AnimatePresence runs the exit variant; once finished,
    // the Portal unmounts and Radix cleanly releases its body guards.
    <AnimatePresence>
      {open ? (
        <RadixDialog.Portal key="dialog-portal">
          {/* Overlay: the fixed centering container + scrim backdrop.
              RadixDialog.Overlay's `asChild` forwards Radix's data-state to
              the motion.div while framer-motion drives the opacity transition. */}
          <RadixDialog.Overlay asChild>
            <motion.div
              key="dialog-overlay"
              variants={fade}
              initial="initial"
              animate="animate"
              exit="exit"
              // Fixed inset centres the panel across the full viewport.
              // z-50 places it above the app shell (z-40).
              className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[var(--scrim)]"
            >
              {/* Content: RadixDialog.Content provides role="dialog",
                  aria-modal="true", focus-trap, ESC handling, and the
                  scroll-lock. `asChild` forwards all of that to the
                  motion.div so ARIA attributes land on the visible element. */}
              <RadixDialog.Content
                asChild
                aria-labelledby={ariaLabelledBy}
                aria-describedby={ariaDescribedBy}
                // Stop panel clicks from bubbling to the overlay and
                // triggering a second onOpenChange(false) call.
                onClick={(e) => e.stopPropagation()}
              >
                <motion.div
                  key="dialog-panel"
                  variants={scaleIn}
                  initial="initial"
                  animate="animate"
                  exit="exit"
                  // Override the preset ease with outExpo for the dialog: the
                  // sharpest deceleration makes the panel read as "placed", not
                  // "drifted in". Matches the original implementation exactly.
                  transition={{ duration: dur.fast, ease: ease.outExpo }}
                  className={cn(
                    "relative z-10 w-full max-w-[min(480px,calc(100vw-2rem))]",
                    "rounded-xl border border-[var(--color-border)]",
                    "bg-[var(--color-popover)] text-[var(--color-popover-foreground)]",
                    "shadow-lg",
                    // Scroll fix: cap height to the viewport (minus the p-4
                    // clearance = 2rem) and scroll the panel internally. `dvh`
                    // tracks mobile browser chrome so the dialog top and bottom
                    // are always reachable. `overscroll-contain` stops the
                    // internal scroll from chaining to the locked body once the
                    // panel hits its limits.
                    "max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain",
                    "p-6",
                    className,
                  )}
                >
                  {children}
                </motion.div>
              </RadixDialog.Content>
            </motion.div>
          </RadixDialog.Overlay>
        </RadixDialog.Portal>
      ) : null}
    </AnimatePresence>
  );
}

// ---------------------------------------------------------------------------
// Styled sub-components — identical API and classNames to the original.
//
// These are kept as plain styled wrappers (not mapped to RadixDialog.Title /
// RadixDialog.Description) because:
//   1. All call sites use explicit id= / ariaLabelledBy / ariaDescribedBy
//      wiring which already satisfies ARIA without Radix's own primitives.
//   2. Radix would log a dev warning if RadixDialog.Title is absent from
//      RadixDialog.Content — we suppress that by passing aria-labelledby
//      directly on Content (via the ariaLabelledBy prop) so Radix considers
//      the labelling requirement met without requiring its own Title element.
// ---------------------------------------------------------------------------

export function DialogHeader({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return <div className={cn("space-y-1", className)}>{children}</div>;
}

export function DialogTitle({
  id,
  children,
  className,
}: {
  id?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <h2 id={id} className={cn("text-lg font-semibold", className)}>
      {children}
    </h2>
  );
}

export function DialogDescription({
  id,
  children,
  className,
}: {
  id?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <p
      id={id}
      className={cn("text-sm text-[var(--color-muted-foreground)]", className)}
    >
      {children}
    </p>
  );
}

export function DialogBody({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return <div className={cn("space-y-4", className)}>{children}</div>;
}

export function DialogFooter({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex justify-end gap-2", className)}>{children}</div>
  );
}
