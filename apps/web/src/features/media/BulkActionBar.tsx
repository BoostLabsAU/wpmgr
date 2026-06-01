import { AnimatePresence, motion } from "motion/react";
import { RotateCcw, Sparkles, Trash2, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { drawerUp } from "@/lib/motion-presets";

// BulkActionBar — the sticky action toolbar that slides up (drawerUp subset)
// when one or more assets are selected. Shows BOTH counts:
//   • N selected  — the total selection (all formats; used by Restore + Delete)
//   • M optimizable — the subset that the encoder can actually process (JPEG/PNG)
//
// The Optimize button reflects M (the real batch size); Restore/Delete reflect N.
// Delete-originals is admin-gated + destructive (only shown when canDelete).

export interface BulkActionBarProps {
  selectedCount: number;
  /** Count of selected assets whose MIME is optimizable (JPEG/PNG). */
  optimizableCount: number;
  /** Operator+: optimize/restore. */
  canOperate: boolean;
  /** Admin+: delete originals. */
  canDelete: boolean;
  onClear: () => void;
  onOptimize: () => void;
  onRestore: () => void;
  onDeleteOriginals: () => void;
}

export function BulkActionBar({
  selectedCount,
  optimizableCount,
  canOperate,
  canDelete,
  onClear,
  onOptimize,
  onRestore,
  onDeleteOriginals,
}: BulkActionBarProps) {
  const show = selectedCount > 0;

  // When the whole selection is optimizable, omit the "M optimizable" note to
  // keep the bar compact. Only add it when there are non-optimizable rows in the
  // selection (so the user understands why Optimize targets fewer assets).
  const showOptimizableNote =
    optimizableCount < selectedCount && optimizableCount > 0;
  const noOptimizable = optimizableCount === 0;

  return (
    <AnimatePresence>
      {show ? (
        <motion.div
          key="bulk-bar"
          variants={drawerUp}
          initial="initial"
          animate="animate"
          exit="exit"
          role="toolbar"
          aria-label={`${selectedCount} assets selected`}
          className="sticky bottom-4 z-30 mx-auto flex w-fit max-w-full items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] px-3 py-2 shadow-lg"
        >
          <div className="flex items-center gap-2 pr-1">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={onClear}
              aria-label="Clear selection"
              className="size-7"
            >
              <X aria-hidden="true" className="size-4" />
            </Button>
            <div className="flex flex-col">
              <span className="text-sm font-medium tabular-nums text-[var(--color-foreground)]">
                {selectedCount.toLocaleString()} selected
              </span>
              {showOptimizableNote ? (
                <span className="text-[11px] tabular-nums text-[var(--color-muted-foreground)]">
                  {optimizableCount.toLocaleString()} optimizable
                </span>
              ) : null}
            </div>
          </div>

          <div className="h-5 w-px bg-[var(--color-border)]" aria-hidden="true" />

          {canOperate ? (
            <>
              <Button
                type="button"
                size="sm"
                onClick={onOptimize}
                disabled={noOptimizable}
                title={
                  noOptimizable
                    ? "None of the selected assets are optimizable (JPEG/PNG only)"
                    : undefined
                }
              >
                <Sparkles aria-hidden="true" className="size-4" />
                Optimize {optimizableCount.toLocaleString()}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={onRestore}
              >
                <RotateCcw aria-hidden="true" className="size-4" />
                Restore {selectedCount.toLocaleString()}
              </Button>
            </>
          ) : null}

          {canDelete ? (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={onDeleteOriginals}
              className="text-[var(--color-destructive)] hover:bg-[var(--color-destructive)]/10 hover:text-[var(--color-destructive)]"
            >
              <Trash2 aria-hidden="true" className="size-4" />
              Delete originals ({selectedCount.toLocaleString()})
            </Button>
          ) : null}
        </motion.div>
      ) : null}
    </AnimatePresence>
  );
}
