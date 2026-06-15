import { MailX, AlertOctagon, CheckCircle2, Clock, XCircle } from "lucide-react";
import { cn } from "@/lib/utils";

// Shared status badge used in both the log table and the detail dialog.
// bounced = warning tone (deliverability risk, not a hard failure)
// complained = destructive tone (spam report, reputation critical)
// These must be visually DISTINCT — not both plain red.

interface StatusVisual {
  label: string;
  icon: typeof MailX;
  classes: string;
  iconClass: string;
}

const STATUS_VISUALS: Record<string, StatusVisual> = {
  sent: {
    label: "Sent",
    icon: CheckCircle2,
    classes:
      "bg-[var(--color-success-subtle)] text-[var(--color-success-subtle-fg)] border-[var(--color-success)]/30",
    iconClass: "text-[var(--color-success)]",
  },
  failed: {
    label: "Failed",
    icon: XCircle,
    classes:
      "bg-[var(--color-destructive-subtle)] text-[var(--color-destructive-subtle-fg)] border-[var(--color-destructive)]/30",
    iconClass: "text-[var(--color-destructive)]",
  },
  pending: {
    label: "Pending",
    icon: Clock,
    classes:
      "bg-[var(--color-muted)] text-[var(--color-muted-foreground)] border-[var(--color-border)]",
    iconClass: "text-[var(--color-muted-foreground)]",
  },
  // Bounced: warning tone — deliverability risk, not a hard send failure.
  bounced: {
    label: "Bounced",
    icon: MailX,
    classes:
      "bg-[var(--color-warning-subtle)] text-[var(--color-warning-subtle-fg)] border-[var(--color-warning)]/30",
    iconClass: "text-[var(--color-warning)]",
  },
  // Complained: destructive tone — spam complaint, reputation-critical.
  complained: {
    label: "Complained",
    icon: AlertOctagon,
    classes:
      "bg-[var(--color-destructive-subtle)] text-[var(--color-destructive-subtle-fg)] border-[var(--color-destructive)]/30",
    iconClass: "text-[var(--color-destructive)]",
  },
};

const FALLBACK_VISUAL: StatusVisual = {
  label: "",
  icon: Clock,
  classes: "bg-[var(--color-muted)] text-[var(--color-muted-foreground)] border-[var(--color-border)]",
  iconClass: "text-[var(--color-muted-foreground)]",
};

export function EmailStatusBadge({ status }: { status: string }) {
  const v = STATUS_VISUALS[status] ?? { ...FALLBACK_VISUAL, label: status };
  const Icon = v.icon;
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs font-medium",
        v.classes,
      )}
    >
      <Icon aria-hidden="true" className={cn("size-3 shrink-0", v.iconClass)} />
      {v.label}
    </span>
  );
}
