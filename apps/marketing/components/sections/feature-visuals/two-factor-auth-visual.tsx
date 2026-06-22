"use client";

import { cn } from "@/lib/utils";

// Illustrative 2FA setup + enforcement widget for the two-factor-auth feature page.
// Distinct from SecurityVisual (which shows hardening controls + vuln scan).
// This widget shows the role-enforcement matrix and a TOTP code entry mockup.
// Sample data only.

const ROLES = [
  { name: "Administrator", requirement: "Required", enrolled: 4, total: 4 },
  { name: "Editor", requirement: "Required", enrolled: 3, total: 5 },
  { name: "Author", requirement: "Optional", enrolled: 1, total: 8 },
  { name: "Subscriber", requirement: "Off", enrolled: 0, total: 42 },
] as const;

const REQ_STYLE: Record<string, string> = {
  Required: "bg-[var(--primary-subtle)] text-[var(--primary-pressed)]",
  Optional: "bg-[var(--muted)] text-[var(--muted-foreground)]",
  Off: "bg-[var(--muted)]/60 text-[var(--muted-foreground)]/60",
};

const TOTP_DIGITS = ["4", "2", "7", "·", "8", "1", "9"];

export function TwoFactorAuthVisual() {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-[var(--border)] bg-card p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-foreground">2FA enforcement per role</span>
        <span className="rounded-md bg-[var(--primary-subtle)] px-2 py-0.5 text-xs font-medium text-[var(--primary-pressed)]">
          site-users
        </span>
      </div>

      {/* Role enforcement matrix */}
      <div className="flex flex-col gap-1.5">
        {ROLES.map((role) => {
          const enrolled = role.requirement !== "Off" ? (role.enrolled / (role.total || 1)) : 0;
          return (
            <div
              key={role.name}
              className="flex items-center gap-3 rounded-lg border border-[var(--border)]/60 bg-[var(--background)] px-3 py-2"
            >
              <span className="w-24 shrink-0 text-xs font-medium text-foreground">{role.name}</span>
              <span
                className={cn(
                  "shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium",
                  REQ_STYLE[role.requirement] ?? REQ_STYLE.Off,
                )}
              >
                {role.requirement}
              </span>
              <div className="flex flex-1 items-center gap-2">
                <div className="flex-1 overflow-hidden rounded-full bg-[var(--muted)] h-1.5">
                  {role.requirement !== "Off" && (
                    <div
                      className={cn(
                        "h-full rounded-full",
                        enrolled === 1
                          ? "bg-[var(--success)]"
                          : enrolled > 0.5
                            ? "bg-[var(--primary)]"
                            : "bg-[var(--warning-subtle-fg)]",
                      )}
                      style={{ width: `${Math.round(enrolled * 100)}%` }}
                    />
                  )}
                </div>
                <span className="shrink-0 font-mono text-[10px] text-[var(--muted-foreground)] tabular-nums">
                  {role.requirement === "Off" ? "--" : `${role.enrolled}/${role.total}`}
                </span>
              </div>
            </div>
          );
        })}
      </div>

      {/* TOTP entry mockup */}
      <div className="rounded-lg border border-[var(--border)] bg-[var(--muted)]/30 p-4">
        <p className="mb-3 text-center text-xs font-medium text-foreground">
          Enter authenticator code
        </p>
        <div className="flex items-center justify-center gap-2">
          {TOTP_DIGITS.map((d, i) =>
            d === "·" ? (
              <span key={i} className="text-[var(--muted-foreground)]" aria-hidden>
                ·
              </span>
            ) : (
              <div
                key={i}
                className={cn(
                  "flex h-9 w-9 items-center justify-center rounded-lg border font-mono text-base font-semibold text-foreground",
                  i < 3
                    ? "border-[var(--primary)]/40 bg-[var(--primary-subtle)] text-[var(--primary-pressed)]"
                    : "border-[var(--border)] bg-card",
                )}
              >
                {i < 3 ? d : "·"}
              </div>
            ),
          )}
        </div>
        <p className="mt-3 text-center text-[10px] text-[var(--muted-foreground)]">
          Backup codes and wp-config recovery available
        </p>
      </div>
    </div>
  );
}
