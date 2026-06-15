/**
 * SiteCard — rich info-dense card for the Sites grid view.
 *
 * Single `rounded-lg border bg-card` surface. No nested cards, no side-stripe
 * borders. Selection uses a ring (ring-2 ring-primary) + bg-primary/5, NOT a
 * left stripe.
 *
 * Card anatomy (top to bottom):
 *   1. Hero band          — SiteCardThumbnail (16:10 comfortable / 16:9 compact)
 *   2. Header row         — site name link + SiteRowActions menu
 *   3. Status rail        — ConnectionStateBadge + hostname (mono)
 *   4. Capability strip   — cache / object-cache / HTTPS / backups / multisite
 *   5. Chip flow          — UpdateChip / calm "Up to date" | BackupChip / calm
 *                           "No backups yet" | SslChip (when tls_expires_at)
 *   6. Uptime row         — pct + latency + StatusDot (text only; no sparkline)
 *   7. Meta footer        — WP/PHP/agent versions, host_provider, client, tags
 *
 * Density:
 *   comfortable — all sections inline
 *   compact     — hero 16:9, footer hover-reveal, caption hidden
 */
import { Link } from "@tanstack/react-router";
import {
  CheckCircle2,
  Database,
  Globe,
  HardDrive,
  Lock,
  RefreshCcw,
} from "lucide-react";
import type { Site } from "@wpmgr/api";

import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import {
  BackupChip,
  ConnectionStateBadge,
  SslChip,
  StatusDot,
  UpdateChip,
  type BackupChipStatus,
} from "@/components/status";
import {
  connectionStateOf,
  asConnectedSite,
} from "@/features/sites/connection-state";
import { SiteRowActions } from "@/features/sites/site-row-actions";
import { SiteCardThumbnail } from "@/features/sites/site-card-thumbnail";
import { CapabilityStrip, type CapabilityItem } from "@/features/sites/capability-strip";
import { useSitesSelection } from "@/features/sites/use-sites-selection";
import type { CardSize } from "@/features/sites/use-sites-view";
import { cn } from "@/lib/utils";

// ─── Helpers ──────────────────────────────────────────────────────────────────

function hostnameFromUrl(url: string): string {
  try {
    return new URL(url).hostname || url;
  } catch {
    return url.replace(/^https?:\/\//i, "").replace(/\/$/, "");
  }
}

/**
 * Derive the capability item list for a site. Items are always in the same
 * order so the strip is stable across re-renders. On/off is encoded by the
 * `enabled` flag which maps to opacity — never hue.
 */
function buildCapabilityItems(site: Site): CapabilityItem[] {
  // Page cache: infer from components list (wpmgr-page-cache plugin active).
  const hasPageCache =
    site.components?.plugins?.some(
      (p) => p.slug === "wpmgr-page-cache" && p.active === true,
    ) ?? false;

  // Object cache: infer from components list (wpmgr-object-cache plugin active).
  const hasObjectCache =
    site.components?.plugins?.some(
      (p) => p.slug === "wpmgr-object-cache" && p.active === true,
    ) ?? false;

  // HTTPS: the site URL uses https.
  const isHttps = site.url.startsWith("https://");

  // Backups on: any non-null last_backup_status means we have attempted backups.
  const hasBackups = site.last_backup_status != null;

  // Multisite: the multisite flag.
  const isMultisite = site.multisite;

  return [
    {
      icon: HardDrive,
      label: `Page cache: ${hasPageCache ? "enabled" : "not detected"}`,
      enabled: hasPageCache,
    },
    {
      icon: Database,
      label: `Object cache: ${hasObjectCache ? "enabled" : "not detected"}`,
      enabled: hasObjectCache,
    },
    {
      icon: Lock,
      label: `HTTPS: ${isHttps ? "enabled" : "not using HTTPS"}`,
      enabled: isHttps,
    },
    {
      icon: RefreshCcw,
      label: `Backups: ${hasBackups ? "enabled" : "not configured"}`,
      enabled: hasBackups,
    },
    {
      icon: Globe,
      label: `Multisite: ${isMultisite ? "yes" : "single site"}`,
      enabled: isMultisite,
    },
  ];
}

// ─── Props ────────────────────────────────────────────────────────────────────

export interface SiteCardProps {
  site: Site;
  cardSize: CardSize;
  selectionCount: number;
  onOpenAutoLogin?: (site: Site) => void;
  onDisconnect?: (site: Site) => void;
  onReconnect?: (site: Site) => void;
}

// ─── Component ────────────────────────────────────────────────────────────────

export function SiteCard({
  site,
  cardSize,
  selectionCount,
  onOpenAutoLogin,
  onDisconnect,
  onReconnect,
}: SiteCardProps) {
  const selection = useSitesSelection();
  const isSelected = selection.selected.has(site.id);
  const anySelected = selectionCount > 0;

  const hostname = hostnameFromUrl(site.url);
  const connectionState = connectionStateOf(site);
  const connectedSite = asConnectedSite(site);
  const disconnectedReason = connectedSite.disconnected_reason ?? null;

  const updatesCount = site.updates_available ?? 0;
  const backupStatus = (site.last_backup_status as BackupChipStatus | null) ?? null;
  const backupTime = site.last_backup_at ?? null;

  const capabilityItems = buildCapabilityItems(site);
  const isCompact = cardSize === "compact";

  // Space toggles selection when the card wrapper itself has focus (not a
  // child element). We check currentTarget === target to distinguish card-level
  // focus from bubbled events.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (e.target !== e.currentTarget) return;
    if (e.key === " ") {
      e.preventDefault();
      selection.toggle(site.id);
    }
  };

  // Versions string — only the parts that are present, joined by " · "
  const versionParts: string[] = [];
  if (site.wp_version) versionParts.push(`WP ${site.wp_version}`);
  if (site.php_version) versionParts.push(`PHP ${site.php_version}`);
  if (site.agent_version) versionParts.push(`agent ${site.agent_version}`);
  const versionString = versionParts.join(" · ");

  return (
    <div
      role="article"
      aria-label={site.name || hostname}
      className={cn(
        "group relative flex flex-col rounded-lg border bg-card text-card-foreground transition-colors",
        "outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        isSelected
          ? "border-primary/50 bg-primary/5 ring-2 ring-primary"
          : "border-border hover:border-border/80 hover:bg-card",
      )}
      tabIndex={0}
      onKeyDown={handleKeyDown}
    >
      {/* ── 1. Hero band ─────────────────────────────────────────────────── */}
      {/* Checkbox overlay top-left — shown on hover/selection */}
      <div
        className={cn(
          "absolute left-2 top-2 z-10 transition-opacity",
          isSelected || anySelected
            ? "opacity-100"
            : "opacity-0 group-hover:opacity-100 group-focus-within:opacity-100",
        )}
      >
        <Checkbox
          aria-label={`Select ${site.name || hostname}`}
          checked={isSelected}
          onChange={() => selection.toggle(site.id)}
          onClick={(e) => e.stopPropagation()}
          className="rounded border-border bg-background shadow-sm"
        />
      </div>

      <SiteCardThumbnail
        site={site}
        hideCaption={isCompact}
        className={isCompact ? "aspect-[16/9]" : undefined}
      />

      {/* ── Card body ────────────────────────────────────────────────────── */}
      <div className="flex flex-1 flex-col gap-2 p-3">

        {/* ── 2. Header row ────────────────────────────────────────────── */}
        <div className="flex min-w-0 items-start justify-between gap-2">
          <Link
            to="/sites/$siteId"
            params={{ siteId: site.id }}
            className="min-w-0 flex-1 truncate text-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            onClick={(e) => e.stopPropagation()}
          >
            {site.name || hostname}
          </Link>
          <div className="shrink-0" onClick={(e) => e.stopPropagation()}>
            <SiteRowActions
              site={site}
              connectionState={connectionState}
              onOpenAutoLogin={onOpenAutoLogin}
              onDisconnect={onDisconnect}
              onReconnect={onReconnect}
            />
          </div>
        </div>

        {/* ── 3. Status rail ───────────────────────────────────────────── */}
        <div className="flex min-w-0 flex-col gap-1">
          <ConnectionStateBadge
            state={connectionState}
            lastSeenAt={site.last_seen_at ?? null}
            disconnectedReason={disconnectedReason}
          />
          {/* Hostname (mono) — only when site name differs from hostname */}
          {site.name && site.name !== hostname ? (
            <span className="truncate font-mono text-xs text-muted-foreground">
              {hostname}
            </span>
          ) : null}
        </div>

        {/* ── 4. Capability strip ──────────────────────────────────────── */}
        {/* Always rendered at fixed height to prevent layout shift when
            data loads. The strip itself handles the empty-array case. */}
        <div className="h-4 flex items-center">
          <CapabilityStrip items={capabilityItems} />
        </div>

        {/* ── 5. Chip flow ─────────────────────────────────────────────── */}
        <div className="flex flex-wrap items-center gap-1.5">
          {/* Updates: chip when >0, calm check when 0 */}
          {updatesCount > 0 ? (
            <UpdateChip count={updatesCount} severity="minor" />
          ) : (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
              <CheckCircle2 aria-hidden="true" className="size-3 text-muted-foreground/60" />
              <span>Up to date</span>
            </span>
          )}

          {/* Backup: chip when status present, calm text when absent */}
          {backupStatus ? (
            <BackupChip
              status={backupStatus}
              time={backupTime ?? undefined}
            />
          ) : (
            <span className="text-xs text-muted-foreground">No backups yet</span>
          )}

          {/* SSL: only when tls_expires_at is present */}
          {site.tls_expires_at ? (
            <SslChip expiresAt={site.tls_expires_at} />
          ) : null}
        </div>

        {/* ── 6. Uptime row ────────────────────────────────────────────── */}
        {/* Reserved-height slot to prevent layout shift when monitoring
            data later arrives. Text-only (no sparkline; backend deferred). */}
        <div className="flex h-5 items-center">
          {site.uptime_pct != null ? (
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <StatusDot
                tone={site.up === false ? "destructive" : "success"}
                label={site.up === false ? "Down" : "Up"}
              />
              <span className="tabular-nums">
                Uptime{" "}
                <span className="font-medium text-foreground tabular-nums">
                  {site.uptime_pct.toFixed(2)}%
                </span>
                {site.avg_latency_ms != null ? (
                  <>
                    {" · "}
                    <span className="tabular-nums">{site.avg_latency_ms}ms</span>
                  </>
                ) : null}
              </span>
            </div>
          ) : (
            <span className="text-xs text-muted-foreground/60">
              Uptime not monitored
            </span>
          )}
        </div>

        {/* ── 7. Meta footer ───────────────────────────────────────────── */}
        {/* comfortable: always visible; compact: hover-reveal */}
        <div
          className={cn(
            "flex flex-col gap-1 border-t border-border/50 pt-2 text-xs text-muted-foreground",
            isCompact &&
              "opacity-0 transition-opacity group-hover:opacity-100",
          )}
        >
          {/* Versions line */}
          {versionString ? (
            <span className="truncate font-mono tabular-nums">
              {versionString}
            </span>
          ) : null}

          {/* Host provider */}
          {site.host_provider ? (
            <span className="truncate">{site.host_provider}</span>
          ) : null}

          {/* Client */}
          {site.client_name ? (
            <span className="flex items-center gap-1.5">
              <span
                aria-hidden="true"
                className="inline-block size-1.5 shrink-0 rounded-full border border-border bg-muted"
              />
              <span className="truncate">{site.client_name}</span>
            </span>
          ) : null}

          {/* Tags: max 2 + overflow badge */}
          {site.tags && site.tags.length > 0 ? (
            <div className="flex flex-wrap gap-1">
              {site.tags.slice(0, 2).map((tag) => (
                <Badge key={tag} variant="muted" className="rounded-sm text-xs">
                  {tag}
                </Badge>
              ))}
              {site.tags.length > 2 ? (
                <Badge variant="muted" className="rounded-sm text-xs">
                  +{site.tags.length - 2}
                </Badge>
              ) : null}
            </div>
          ) : null}
        </div>

      </div>
    </div>
  );
}
