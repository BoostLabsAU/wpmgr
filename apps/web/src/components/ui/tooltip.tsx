import * as React from "react";
import * as RadixTooltip from "@radix-ui/react-tooltip";

import { cn } from "@/lib/utils";

// Tooltip primitive built on @radix-ui/react-tooltip.
//
// Provides accessible, keyboard-reachable tooltips. The Provider must wrap
// the root of the app (or at least the subtree that uses tooltips). Since
// we mount it at the root of the feature subtree here, callers only need
// to import Tooltip, TooltipTrigger, and TooltipContent.
//
// Usage:
//   <Tooltip content="Body not stored">
//     <Button disabled>Resend</Button>
//   </Tooltip>
//
// Or with sub-components:
//   <TooltipRoot>
//     <TooltipTrigger asChild><Button /></TooltipTrigger>
//     <TooltipContent>Body not stored</TooltipContent>
//   </TooltipRoot>

// ---------------------------------------------------------------------------
// Provider — wrap at app root or feature boundary
// ---------------------------------------------------------------------------

export function TooltipProvider({ children }: { children: React.ReactNode }) {
  return (
    <RadixTooltip.Provider delayDuration={400} skipDelayDuration={200}>
      {children}
    </RadixTooltip.Provider>
  );
}

// ---------------------------------------------------------------------------
// Primitive sub-components
// ---------------------------------------------------------------------------

// eslint-disable-next-line react-refresh/only-export-components -- shadcn/ui primitive: Radix re-exports are intentionally co-located; fast-refresh hint does not apply to these pass-through assignments.
export const TooltipRoot = RadixTooltip.Root;
// eslint-disable-next-line react-refresh/only-export-components -- shadcn/ui primitive: see above.
export const TooltipTrigger = RadixTooltip.Trigger;

export interface TooltipContentProps
  extends React.ComponentPropsWithoutRef<typeof RadixTooltip.Content> {
  className?: string;
}

export const TooltipContent = React.forwardRef<
  React.ElementRef<typeof RadixTooltip.Content>,
  TooltipContentProps
>(({ className, sideOffset = 4, ...props }, ref) => (
  <RadixTooltip.Portal>
    <RadixTooltip.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        "z-50 max-w-xs rounded-md bg-[var(--color-foreground)] px-2.5 py-1.5 text-xs text-[var(--color-background)]",
        "shadow-sm animate-in fade-in-0 zoom-in-95",
        "data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95",
        "data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2",
        "data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2",
        className,
      )}
      {...props}
    />
  </RadixTooltip.Portal>
));
TooltipContent.displayName = "TooltipContent";

// ---------------------------------------------------------------------------
// Convenience wrapper — Tooltip with a single `content` prop
// ---------------------------------------------------------------------------

export interface TooltipProps {
  content: React.ReactNode;
  children: React.ReactNode;
  /** Defaults to false. When true, the tooltip is NOT shown. */
  disabled?: boolean;
}

export function Tooltip({ content, children, disabled = false }: TooltipProps) {
  if (disabled) return <>{children}</>;
  return (
    <TooltipRoot>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent>{content}</TooltipContent>
    </TooltipRoot>
  );
}
