// PortalSiteCard — read-only site summary card for the portal overview grid.
//
// Status copy: connected -> "Monitoring active"; anything else -> "Needs attention"
// (locked decision, contract section 9/Decisions).

import { Link } from "@tanstack/react-router";
import { ExternalLink, Shield, ShieldOff } from "lucide-react";

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { relativeTime } from "@/lib/utils";
import type { PortalSite } from "./use-portal";

// ---------------------------------------------------------------------------
// Soft status labels (locked decision, contract 9.3)
// ---------------------------------------------------------------------------

function siteStatusLabel(status: string): string {
  return status === "connected" ? "Monitoring active" : "Needs attention";
}

function siteStatusVariant(status: string): "default" | "secondary" | "destructive" | "outline" {
  return status === "connected" ? "default" : "destructive";
}

// ---------------------------------------------------------------------------
// TLS expiry helper
// ---------------------------------------------------------------------------

function tlsLabel(expiresAt: string | undefined): string | null {
  if (!expiresAt) return null;
  const exp = new Date(expiresAt);
  const now = Date.now();
  const daysLeft = Math.round((exp.getTime() - now) / 86_400_000);
  if (daysLeft <= 0) return "TLS expired";
  if (daysLeft <= 30) return `TLS expires in ${daysLeft}d`;
  return null;
}

// ---------------------------------------------------------------------------
// Uptime badge
// ---------------------------------------------------------------------------

function uptimeBadge(pct: number | undefined): string | null {
  if (pct === undefined || pct === null) return null;
  return `${pct.toFixed(1)}% uptime`;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface PortalSiteCardProps {
  site: PortalSite;
}

export function PortalSiteCard({ site }: PortalSiteCardProps) {
  const statusLabel = siteStatusLabel(site.status);
  const statusVariant = siteStatusVariant(site.status);
  const tls = tlsLabel(site.tls_expires_at);
  const uptime = uptimeBadge(site.uptime_30d_pct);

  return (
    <Card className="flex flex-col transition-shadow hover:shadow-sm">
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between gap-2">
          <CardTitle className="min-w-0 flex-1 text-base">
            <Link
              to="/portal/sites/$siteId"
              params={{ siteId: site.id }}
              className="truncate hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
            >
              {site.name}
            </Link>
          </CardTitle>
          <Badge
            variant={statusVariant}
            className="shrink-0 text-xs"
          >
            {statusLabel}
          </Badge>
        </div>
        <a
          href={site.url}
          target="_blank"
          rel="noopener noreferrer"
          className="flex min-w-0 items-center gap-1 truncate text-xs text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]"
        >
          <ExternalLink aria-hidden="true" className="size-3 shrink-0" />
          <span className="truncate">{site.url.replace(/^https?:\/\//, "")}</span>
        </a>
      </CardHeader>

      <CardContent className="flex flex-1 flex-col justify-end gap-2 pt-0">
        <div className="flex flex-wrap items-center gap-2">
          {uptime ? (
            <span className="font-mono text-xs tabular-nums text-[var(--color-muted-foreground)]">
              {uptime}
            </span>
          ) : null}

          {tls ? (
            <span
              className={cn(
                "text-xs",
                tls === "TLS expired"
                  ? "text-[var(--color-destructive)]"
                  : "text-[var(--color-warning,var(--color-muted-foreground))]",
              )}
            >
              {tls.startsWith("TLS exp") ? (
                <>
                  {site.status === "connected" ? (
                    <Shield aria-hidden="true" className="mr-0.5 inline size-3" />
                  ) : (
                    <ShieldOff aria-hidden="true" className="mr-0.5 inline size-3" />
                  )}
                  {tls}
                </>
              ) : (
                tls
              )}
            </span>
          ) : null}
        </div>

        {site.last_backup_at ? (
          <p className="text-xs text-[var(--color-muted-foreground)]">
            Last backup {relativeTime(site.last_backup_at)}
          </p>
        ) : (
          <p className="text-xs text-[var(--color-muted-foreground)]">
            No backups recorded
          </p>
        )}
      </CardContent>
    </Card>
  );
}
