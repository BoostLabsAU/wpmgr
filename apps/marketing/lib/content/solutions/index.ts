// Solutions content registry. All 7 solution pages, keyed by slug.
// Import from here to get a typed SolutionPageData for any slug.
export * from "./agencies";
export * from "./freelancers";
export * from "./hosting-providers";
export * from "./wordpress-security";
export * from "./wordpress-backups";
export * from "./wordpress-performance";
export * from "./manage-multiple-sites";

import type { SolutionPageData } from "@/lib/content/types";
import { AGENCIES_SOLUTION } from "./agencies";
import { FREELANCERS_SOLUTION } from "./freelancers";
import { HOSTING_PROVIDERS_SOLUTION } from "./hosting-providers";
import { WORDPRESS_SECURITY_SOLUTION } from "./wordpress-security";
import { WORDPRESS_BACKUPS_SOLUTION } from "./wordpress-backups";
import { WORDPRESS_PERFORMANCE_SOLUTION } from "./wordpress-performance";
import { MANAGE_MULTIPLE_SITES_SOLUTION } from "./manage-multiple-sites";

export const SOLUTION_REGISTRY: Record<string, SolutionPageData> = {
  agencies: AGENCIES_SOLUTION,
  freelancers: FREELANCERS_SOLUTION,
  "hosting-providers": HOSTING_PROVIDERS_SOLUTION,
  "wordpress-security": WORDPRESS_SECURITY_SOLUTION,
  "wordpress-backups": WORDPRESS_BACKUPS_SOLUTION,
  "wordpress-performance": WORDPRESS_PERFORMANCE_SOLUTION,
  "manage-multiple-sites": MANAGE_MULTIPLE_SITES_SOLUTION,
};

export const SOLUTION_SLUGS = Object.keys(SOLUTION_REGISTRY) as (keyof typeof SOLUTION_REGISTRY)[];

export function getSolution(slug: string): SolutionPageData | undefined {
  return SOLUTION_REGISTRY[slug];
}

// Hub card data: minimal descriptors for the solutions hub page.
export type SolutionHubCard = {
  slug: string;
  icon: string;
  title: string;
  summary: string;
  group: "audience" | "jtbd";
};

export const SOLUTION_HUB_CARDS: SolutionHubCard[] = [
  // Audience solutions
  {
    slug: "agencies",
    icon: "Handshake",
    title: "For agencies",
    summary: "White-label reports, per-site email, team access, and automated backups for your entire client portfolio.",
    group: "audience",
  },
  {
    slug: "freelancers",
    icon: "Laptop",
    title: "For freelancers",
    summary: "Manage every client site from one screen with safe bulk updates, automated backups, and instant uptime alerts.",
    group: "audience",
  },
  {
    slug: "hosting-providers",
    icon: "Server",
    title: "For hosting providers",
    summary: "Embed open-source WordPress tooling into your stack: security, monitoring, and team access without building it yourself.",
    group: "audience",
  },
  // Job-to-be-done solutions
  {
    slug: "wordpress-security",
    icon: "ShieldCheck",
    title: "WordPress security",
    summary: "Harden every site, scan for CVEs via Wordfence Intelligence, enforce 2FA, and maintain a tamper-evident audit log.",
    group: "jtbd",
  },
  {
    slug: "wordpress-backups",
    icon: "DatabaseBackup",
    title: "WordPress backups",
    summary: "Incremental backups with point-in-time restore, fleet-wide health view, and automatic pre-update snapshots.",
    group: "jtbd",
  },
  {
    slug: "wordpress-performance",
    icon: "Gauge",
    title: "Speed up WordPress",
    summary: "Full-page caching, AVIF and WebP via the Media Optimizer, Redis object cache, and Core Web Vitals from real visitors.",
    group: "jtbd",
  },
  {
    slug: "manage-multiple-sites",
    icon: "LayoutGrid",
    title: "Manage multiple sites",
    summary: "One dashboard for every WordPress site you run. Bulk updates, monitoring, backups, and team access at fleet scale.",
    group: "jtbd",
  },
];
