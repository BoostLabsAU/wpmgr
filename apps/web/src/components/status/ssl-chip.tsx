import { ShieldCheck } from "lucide-react";

import { cn } from "@/lib/utils";
import { useNow } from "@/lib/use-now";

export interface SslChipProps {
  /** RFC 3339 TLS certificate expiry timestamp from `tls_expires_at`. */
  expiresAt: string;
  className?: string;
}

/**
 * SslChip — compact SSL expiry indicator for the site card chip flow.
 *
 * Palette thresholds (days until expiry):
 *   <=7d   destructive-subtle  (urgent: cert is about to expire)
 *   <=21d  warning-subtle      (warn: renew soon)
 *   >21d   muted               (calm: no action needed)
 *
 * Uses tabular-nums for the day count so digits do not shift the chip width
 * on rerender. Never shown when `expiresAt` is absent (callers guard this).
 *
 * `useNow` is used instead of `Date.now()` directly during render, per the
 * react-hooks/purity rule (see `lib/use-now.ts`).
 */
export function SslChip({ expiresAt, className }: SslChipProps) {
  // Tick every 60 s — more than enough precision for day-level expiry display.
  const now = useNow(60_000);

  const expiresMs = Date.parse(expiresAt);
  if (Number.isNaN(expiresMs)) return null;

  const daysLeft = Math.ceil((expiresMs - now) / (1000 * 60 * 60 * 24));

  const paletteClass =
    daysLeft <= 7
      ? "bg-destructive-subtle text-destructive-subtle-fg"
      : daysLeft <= 21
        ? "bg-warning-subtle text-warning-subtle-fg"
        : "bg-muted text-muted-foreground";

  const label = daysLeft <= 0 ? "SSL expired" : `SSL ${daysLeft}d`;

  return (
    <span
      title={`TLS certificate expires ${new Date(expiresMs).toLocaleDateString()}`}
      className={cn(
        "inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium",
        paletteClass,
        className,
      )}
    >
      <ShieldCheck aria-hidden="true" className="size-3 shrink-0" />
      <span className="tabular-nums">{label}</span>
    </span>
  );
}
