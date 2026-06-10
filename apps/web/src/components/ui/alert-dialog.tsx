import * as React from "react";
import * as RadixAlertDialog from "@radix-ui/react-alert-dialog";
import { AnimatePresence, motion } from "motion/react";

import { cn } from "@/lib/utils";
import { dur, ease, fade, scaleIn } from "@/lib/motion-presets";
import { buttonVariants } from "./button";

// AlertDialog primitive built on @radix-ui/react-alert-dialog.
//
// Semantically distinct from Dialog: AlertDialog requires an explicit user
// confirmation before the action is taken. The overlay click does NOT close it
// (AlertDialog's default), which prevents accidental dismissal of destructive
// prompts. All keyboard and focus semantics are provided by Radix.
//
// API mirrors the Dialog component for consistency: controlled via `open`
// + `onOpenChange`. Sub-components exported individually so callers can
// assemble the confirm/cancel button pair with their own copy.

const AlertDialogOpenContext = React.createContext<boolean>(false);

// ---------------------------------------------------------------------------
// Root
// ---------------------------------------------------------------------------

export interface AlertDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  children: React.ReactNode;
}

export function AlertDialog({ open, onOpenChange, children }: AlertDialogProps) {
  return (
    <RadixAlertDialog.Root open={open} onOpenChange={onOpenChange}>
      <AlertDialogOpenContext.Provider value={open}>
        {children}
      </AlertDialogOpenContext.Provider>
    </RadixAlertDialog.Root>
  );
}

// ---------------------------------------------------------------------------
// Content — portal + overlay + animated panel
// ---------------------------------------------------------------------------

export interface AlertDialogContentProps {
  className?: string;
  children: React.ReactNode;
}

export function AlertDialogContent({ className, children }: AlertDialogContentProps) {
  const open = React.useContext(AlertDialogOpenContext);

  return (
    <RadixAlertDialog.Portal forceMount>
      <AnimatePresence>
        {open ? (
          <RadixAlertDialog.Overlay asChild forceMount>
            <motion.div
              key="alert-dialog-overlay"
              variants={fade}
              initial="initial"
              animate="animate"
              exit="exit"
              className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[var(--scrim)]"
            >
              <RadixAlertDialog.Content
                asChild
                forceMount
                onClick={(e) => e.stopPropagation()}
              >
                <motion.div
                  key="alert-dialog-panel"
                  variants={scaleIn}
                  initial="initial"
                  animate="animate"
                  exit="exit"
                  transition={{ duration: dur.fast, ease: ease.outExpo }}
                  className={cn(
                    "relative z-10 w-full max-w-md",
                    "rounded-xl border border-[var(--color-border)]",
                    "bg-[var(--color-popover)] text-[var(--color-popover-foreground)]",
                    "shadow-lg p-6 space-y-4",
                    className,
                  )}
                >
                  {children}
                </motion.div>
              </RadixAlertDialog.Content>
            </motion.div>
          </RadixAlertDialog.Overlay>
        ) : null}
      </AnimatePresence>
    </RadixAlertDialog.Portal>
  );
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

export function AlertDialogHeader({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return <div className={cn("space-y-1", className)}>{children}</div>;
}

export function AlertDialogTitle({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <RadixAlertDialog.Title
      className={cn("text-lg font-semibold", className)}
    >
      {children}
    </RadixAlertDialog.Title>
  );
}

export function AlertDialogDescription({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <RadixAlertDialog.Description
      className={cn("text-sm text-[var(--color-muted-foreground)]", className)}
    >
      {children}
    </RadixAlertDialog.Description>
  );
}

export function AlertDialogFooter({
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

// ---------------------------------------------------------------------------
// Action + Cancel buttons — Radix manages focus-return on close
// ---------------------------------------------------------------------------

export interface AlertDialogActionProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "destructive" | "default";
}

export function AlertDialogAction({
  className,
  variant = "destructive",
  ...props
}: AlertDialogActionProps) {
  return (
    <RadixAlertDialog.Action
      asChild
    >
      <button
        type="button"
        className={cn(buttonVariants({ variant }), className)}
        {...props}
      />
    </RadixAlertDialog.Action>
  );
}

export function AlertDialogCancel({
  className,
  children = "Cancel",
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <RadixAlertDialog.Cancel asChild>
      <button
        type="button"
        className={cn(buttonVariants({ variant: "outline" }), className)}
        {...props}
      >
        {children}
      </button>
    </RadixAlertDialog.Cancel>
  );
}
