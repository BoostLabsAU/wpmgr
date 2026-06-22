"use client";

import { cn } from "@/lib/utils";

// Illustrative team access and audit log widget for the team-access feature page.
// Sample data only.

const MEMBERS = [
  { name: "Jordan M.", email: "jordan@agency.com", role: "Admin", sites: "All sites", avatar: "JM" },
  { name: "Sam T.", email: "sam@agency.com", role: "Member", sites: "All sites", avatar: "ST" },
  { name: "Client A", email: "alice@client-a.com", role: "Viewer", sites: "shop.client-a.com", avatar: "CA" },
] as const;

const ROLE_STYLE: Record<string, string> = {
  Admin: "bg-[var(--primary-subtle)] text-[var(--primary-pressed)]",
  Member: "bg-[var(--muted)] text-[var(--muted-foreground)]",
  Viewer: "bg-[var(--success)]/10 text-[var(--success)]",
  Owner: "bg-[var(--primary)]/15 text-[var(--primary-pressed)]",
};

const AUDIT_LOG = [
  { action: "Backup started", user: "Jordan M.", site: "shop.client-a.com", time: "4m ago", ok: true },
  { action: "Plugin updated", user: "Sam T.", site: "blog.acme.com", time: "22m ago", ok: true },
  { action: "Login failed", user: "unknown", site: "shop.client-a.com", time: "1h ago", ok: false },
  { action: "Role changed", user: "Jordan M.", site: "--", time: "3h ago", ok: true },
] as const;

export function TeamAccessVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">Team and access</span>
        <span className="rounded-md bg-[var(--primary-subtle)] px-2 py-0.5 text-xs font-medium text-[var(--primary-pressed)]">
          3 members
        </span>
      </div>

      {/* Member list */}
      <div className="flex flex-col gap-1.5">
        {MEMBERS.map((m) => (
          <div
            key={m.email}
            className="flex items-center gap-3 rounded-lg border border-[var(--border)]/60 bg-[var(--background)] px-3 py-2"
          >
            <div className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--primary-subtle)] font-mono text-[10px] font-semibold text-[var(--primary-pressed)]">
              {m.avatar}
            </div>
            <div className="min-w-0 flex-1">
              <span className="block text-xs font-medium text-foreground">{m.name}</span>
              <span className="block truncate text-[10px] text-[var(--muted-foreground)]">
                {m.sites}
              </span>
            </div>
            <span
              className={cn(
                "shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium",
                ROLE_STYLE[m.role] ?? "bg-[var(--muted)] text-[var(--muted-foreground)]",
              )}
            >
              {m.role}
            </span>
          </div>
        ))}
      </div>

      {/* Audit log preview */}
      <div className="flex flex-col gap-2 border-t border-[var(--border)] pt-3">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-[var(--muted-foreground)]">Audit log</span>
          <span className="text-[10px] text-[var(--muted-foreground)]">hash-chained</span>
        </div>
        <div className="flex flex-col gap-1">
          {AUDIT_LOG.map((entry, i) => (
            <div
              key={i}
              className="flex items-center gap-2 text-[10px] text-[var(--muted-foreground)]"
            >
              <span
                className={cn(
                  "h-1.5 w-1.5 shrink-0 rounded-full",
                  entry.ok ? "bg-[var(--success)]" : "bg-destructive",
                )}
              />
              <span className="font-medium text-foreground">{entry.action}</span>
              <span className="truncate">{entry.user}</span>
              <span className="ml-auto shrink-0">{entry.time}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
