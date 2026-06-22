"use client";

import { cn } from "@/lib/utils";

// Illustrative email delivery log widget for the email-deliverability feature page.
// Sample data only.

const LOGS = [
  {
    to: "alice@client-a.com",
    subject: "Your order has shipped",
    via: "SES",
    status: "delivered",
    time: "2m ago",
  },
  {
    to: "bob@client-b.com",
    subject: "Password reset request",
    via: "SendGrid",
    status: "delivered",
    time: "14m ago",
  },
  {
    to: "carol@client-c.com",
    subject: "Monthly invoice",
    via: "Mailgun",
    status: "bounced",
    time: "1h ago",
  },
  {
    to: "dave@client-d.com",
    subject: "New comment on your post",
    via: "SMTP",
    status: "delivered",
    time: "2h ago",
  },
] as const;

const STATUS_STYLE: Record<string, string> = {
  delivered: "bg-[var(--success)]/12 text-[var(--success)]",
  bounced: "bg-destructive/10 text-destructive",
  pending: "bg-[var(--muted)] text-[var(--muted-foreground)]",
  failed: "bg-destructive/10 text-destructive",
};

const VIA_STYLE: Record<string, string> = {
  SES: "bg-[var(--info-subtle,var(--primary-subtle))] text-[var(--info-subtle-fg,var(--primary-pressed))]",
  SendGrid: "bg-[var(--primary-subtle)] text-[var(--primary-pressed)]",
  Mailgun: "bg-[var(--warning-subtle-fg)]/10 text-[var(--warning-subtle-fg)]",
  SMTP: "bg-[var(--muted)] text-[var(--muted-foreground)]",
};

const STATS = [
  { label: "Sent today", value: "142", tone: "foreground" },
  { label: "Delivered", value: "98.6%", tone: "success" },
  { label: "Suppressed", value: "1", tone: "warn" },
] as const;

const STAT_TEXT: Record<string, string> = {
  foreground: "text-foreground",
  success: "text-[var(--success)]",
  warn: "text-[var(--warning-subtle-fg)]",
};

export function EmailDeliverabilityVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Email delivery log</span>
        <span className="rounded-md bg-[var(--success)]/12 px-2 py-0.5 text-xs font-medium text-[var(--success)]">
          Cross-fleet
        </span>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-3 gap-2 rounded-lg bg-[var(--muted)]/50 p-3">
        {STATS.map((s) => (
          <div key={s.label} className="flex flex-col gap-0.5">
            <span
              className={cn(
                "font-mono text-lg font-semibold tabular-nums",
                STAT_TEXT[s.tone],
              )}
            >
              {s.value}
            </span>
            <span className="text-[10px] text-[var(--muted-foreground)]">{s.label}</span>
          </div>
        ))}
      </div>

      {/* Log entries */}
      <div className="flex flex-col gap-1.5">
        {LOGS.map((log) => (
          <div
            key={`${log.to}-${log.time}`}
            className="flex items-center gap-2 rounded-lg border border-[var(--border)]/60 bg-[var(--background)] px-3 py-2"
          >
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <span className="truncate font-mono text-xs text-foreground">{log.to}</span>
                <span
                  className={cn(
                    "shrink-0 rounded px-1 py-px font-mono text-[9px] font-medium",
                    VIA_STYLE[log.via] ?? "bg-[var(--muted)] text-[var(--muted-foreground)]",
                  )}
                >
                  {log.via}
                </span>
              </div>
              <span className="truncate text-[10px] text-[var(--muted-foreground)]">
                {log.subject}
              </span>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-0.5">
              <span
                className={cn(
                  "rounded px-1.5 py-0.5 text-[10px] font-medium capitalize",
                  STATUS_STYLE[log.status] ?? STATUS_STYLE.pending,
                )}
              >
                {log.status}
              </span>
              <span className="text-[10px] text-[var(--muted-foreground)]">{log.time}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
