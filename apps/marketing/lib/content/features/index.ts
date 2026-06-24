// Feature content registry. All 14 feature pages, keyed by slug.
// Import from here to get a typed FeaturePageData for any slug.
export * from "./backups";
export * from "./updates";
export * from "./uptime-monitoring";
export * from "./performance";
export * from "./object-cache";
export * from "./real-user-monitoring";
export * from "./media-optimizer";
export * from "./database-cleaner";
export * from "./security";
export * from "./two-factor-auth";
export * from "./client-reports";
export * from "./email-deliverability";
export * from "./team-access";
export * from "./file-manager";

import type { FeaturePageData } from "@/lib/content/types";
import { BACKUPS_PAGE } from "./backups";
import { UPDATES_PAGE } from "./updates";
import { UPTIME_MONITORING_PAGE } from "./uptime-monitoring";
import { PERFORMANCE_PAGE } from "./performance";
import { OBJECT_CACHE_PAGE } from "./object-cache";
import { RUM_PAGE } from "./real-user-monitoring";
import { MEDIA_OPTIMIZER_PAGE } from "./media-optimizer";
import { DATABASE_CLEANER_PAGE } from "./database-cleaner";
import { SECURITY_PAGE } from "./security";
import { TWO_FACTOR_AUTH_PAGE } from "./two-factor-auth";
import { CLIENT_REPORTS_PAGE } from "./client-reports";
import { EMAIL_DELIVERABILITY_PAGE } from "./email-deliverability";
import { TEAM_ACCESS_PAGE } from "./team-access";
import { FILE_MANAGER_PAGE } from "./file-manager";

export const FEATURE_REGISTRY: Record<string, FeaturePageData> = {
  backups: BACKUPS_PAGE,
  updates: UPDATES_PAGE,
  "uptime-monitoring": UPTIME_MONITORING_PAGE,
  performance: PERFORMANCE_PAGE,
  "object-cache": OBJECT_CACHE_PAGE,
  "real-user-monitoring": RUM_PAGE,
  "media-optimizer": MEDIA_OPTIMIZER_PAGE,
  "database-cleaner": DATABASE_CLEANER_PAGE,
  security: SECURITY_PAGE,
  "two-factor-auth": TWO_FACTOR_AUTH_PAGE,
  "client-reports": CLIENT_REPORTS_PAGE,
  "email-deliverability": EMAIL_DELIVERABILITY_PAGE,
  "team-access": TEAM_ACCESS_PAGE,
  "file-manager": FILE_MANAGER_PAGE,
};

export const FEATURE_SLUGS = Object.keys(FEATURE_REGISTRY) as (keyof typeof FEATURE_REGISTRY)[];

export function getFeature(slug: string): FeaturePageData | undefined {
  return FEATURE_REGISTRY[slug];
}

// Hub card data: minimal descriptors for the features hub bento grid.
// Clusters mirror the 5 content.ts clusters (Operate / Accelerate / Clean up / Serve clients / Protect).
export type FeatureHubCard = {
  slug: string;
  icon: string;
  title: string;
  summary: string;
};

export type FeatureHubCluster = {
  id: string;
  icon: string;
  name: string;
  tagline: string;
  features: FeatureHubCard[];
};

export const HUB_CLUSTERS: FeatureHubCluster[] = [
  {
    id: "hub-operate",
    icon: "ServerCog",
    name: "Operate",
    tagline: "Connect a site in under a minute, then run the whole fleet from one screen.",
    features: [
      { slug: "backups", icon: "DatabaseBackup", title: "Backups and restore", summary: "Scheduled incremental backups with point-in-time restore and fleet-wide backup health." },
      { slug: "updates", icon: "RefreshCw", title: "Safe fleet updates", summary: "Bulk update plugins, themes, and core with auto-snapshot and auto-revert on failure." },
      { slug: "uptime-monitoring", icon: "Activity", title: "Uptime and health monitoring", summary: "Fleet status matrix, response-time trends, TLS expiry warnings, and instant alerts." },
      { slug: "file-manager", icon: "FolderOpen", title: "File Manager", summary: "Browse, edit, upload, and manage site files from the dashboard. Version history, archive and extract, file search. Off by default, owner-gated, fully audited." },
    ],
  },
  {
    id: "hub-accelerate",
    icon: "Gauge",
    name: "Accelerate",
    tagline: "Make every page faster, then prove it with real-visitor data.",
    features: [
      { slug: "performance", icon: "Zap", title: "Performance and page caching", summary: "Full-page caching, unused CSS removal, WOFF2 fonts, and WooCommerce-aware bypasses." },
      { slug: "object-cache", icon: "HardDrive", title: "Redis Object Cache", summary: "Per-site Redis object cache with hit ratio, memory, and latency tracked in the dashboard." },
      { slug: "media-optimizer", icon: "ImageDown", title: "Media Optimizer", summary: "Convert your WordPress media library to AVIF and WebP, originals archived, fully reversible." },
      { slug: "real-user-monitoring", icon: "BarChart2", title: "Real User Monitoring", summary: "Core Web Vitals at p75 from real visitors, 28-day trends, per-URL and per-device." },
    ],
  },
  {
    id: "hub-clean",
    icon: "Eraser",
    name: "Clean up",
    tagline: "Database and media hygiene that previews first and reverses cleanly.",
    features: [
      { slug: "database-cleaner", icon: "DatabaseZap", title: "Database Cleaner", summary: "Per-table scan, orphan classification corpus, 90-day trend, and fleet-wide view." },
    ],
  },
  {
    id: "hub-clients",
    icon: "Handshake",
    name: "Serve clients",
    tagline: "Group sites by customer and put your brand on everything they see.",
    features: [
      { slug: "client-reports", icon: "ScrollText", title: "White-label reports", summary: "Branded maintenance reports by email or PDF, on a schedule or on demand." },
      { slug: "email-deliverability", icon: "MailCheck", title: "Per-site email and log", summary: "SES, SendGrid, Mailgun, Postmark, or SMTP per site, with a central delivery log." },
    ],
  },
  {
    id: "hub-protect",
    icon: "LockKeyhole",
    name: "Protect",
    tagline: "Hardening, access control, and audit trails that cannot lock you out.",
    features: [
      { slug: "security", icon: "ShieldCheck", title: "Security suite", summary: "Hardening, IP bans, file integrity, vulnerability scanning, and site-user password policy." },
      { slug: "two-factor-auth", icon: "Smartphone", title: "Two-factor authentication", summary: "TOTP and email code 2FA for WordPress site users, enforced per role with recovery paths." },
      { slug: "team-access", icon: "Users", title: "Team and access control", summary: "Four roles, per-site sharing, OIDC SSO, and a tamper-evident hash-chained audit log." },
    ],
  },
];
