// Performance Suite (Phase 7 / m36) wire types.
//
// These mirror the hand-rolled Go DTOs in apps/api/internal/perf/dto.go. The
// perf routes are NOT in the generated @wpmgr/api SDK, so we type the shapes
// here and call them via the raw `client.get/put/post` (same pattern as
// features/admin/use-admin.ts and features/media/hooks/useMediaSettings.ts).
//
// SECURITY: CDN credentials are WRITE-ONLY. The GET shape never carries the
// stored value (the service decrypts server-side only); `cdn_has_credentials`
// is the read-only "one is set" flag. The UI sends `cdn_credentials` only when
// the operator types a new value — sending nothing leaves the stored value
// unchanged. The stored value is NEVER rendered.

/** Write-only CDN credentials sub-object — accepted on PUT, never returned. */
export interface CdnCredentials {
  api_token: string;
  zone_id?: string;
  zone?: string;
}

/** The full per-site performance config (GET/PUT /perf/config). */
export interface PerfConfig {
  // Caching
  cache_enabled: boolean;
  cache_logged_in: boolean;
  cache_mobile: boolean;
  cache_refresh: boolean;
  cache_refresh_interval: string;
  cache_link_prefetch: boolean;
  cache_bypass_urls: string[];
  cache_bypass_cookies: string[];
  cache_include_queries: string[];
  cache_include_cookies: string[];

  // Preload tuning (operator-tunable throttle; clamped server-side)
  preload_concurrency: number; // 1..4   — parallel warm workers
  preload_delay_ms: number; // 0..10000  — inter-request delay (ms); 0 = none
  preload_batch_size: number; // 1..500  — URLs per drain pass
  preload_max_load: number; // 0..64    — load-per-core ceiling; 0 = disabled

  // CSS / JS
  css_js_minify: boolean;
  css_rucss: boolean;
  css_rucss_include_selectors: string[];
  css_js_self_host_third_party: boolean;
  js_delay: boolean;
  js_delay_method: string;
  js_delay_excludes: string[];
  js_delay_third_party: boolean;
  js_delay_third_party_excludes: string[];

  // Fonts
  fonts_display_swap: boolean;
  fonts_optimize_google: boolean;
  fonts_preload: boolean;

  // Media / lazy-load
  lazy_load: boolean;
  lazy_load_exclusions: string[];
  properly_size_images: boolean;
  youtube_placeholder: boolean;
  self_host_gravatars: boolean;

  // CDN — credentials are write-only (see header). `cdn_has_credentials` is
  // read-only; `cdn_credentials` is only ever SENT, never received.
  cdn_enabled: boolean;
  cdn_url: string;
  cdn_file_types: string;
  cdn_provider: string;
  cdn_has_credentials: boolean;
  cdn_credentials?: CdnCredentials;

  // Database cleanup
  db_auto_clean: boolean;
  db_auto_clean_interval: string;
  db_post_revisions: boolean;
  db_post_auto_drafts: boolean;
  db_post_trashed: boolean;
  db_comments_spam: boolean;
  db_comments_trashed: boolean;
  db_transients_expired: boolean;
  db_optimize_tables: boolean;

  // Bloat removal
  bloat_disable_block_css: boolean;
  bloat_disable_dashicons: boolean;
  bloat_disable_emojis: boolean;
  bloat_disable_jquery_migrate: boolean;
  bloat_disable_xml_rpc: boolean;
  bloat_disable_rss_feed: boolean;
  bloat_disable_oembeds: boolean;
  bloat_heartbeat_control: boolean;
  bloat_post_revisions_control: boolean;

  // Server / install state (read-only, agent-reported)
  server_software?: string;
  dropin_installed: boolean;
  wp_cache_constant_set: boolean;
  htaccess_managed: boolean;

  config_version: number;
  updated_at?: string;
}

/** Latest cache gauges the agent reports (GET /cache/stats). */
export interface CacheStats {
  cached_pages_count: number;
  cache_size_bytes: number;
  last_purged_at?: string;
  last_purge_kind?: string;
  last_preload_at?: string;
  preload_pending: number;
  preload_total: number;
  reported_at?: string;
}

/** One cached RUCSS result row (GET /rucss/results). */
export interface RucssResult {
  id: string;
  structure_hash: string;
  url?: string;
  original_css_bytes: number;
  used_css_bytes: number;
  reduction_pct: number;
  used_css_s3_key: string;
  last_used_at?: string;
}

/** Generic agent-action ack returned by purge/preload/enable/disable/db-clean. */
export interface PerfActionResult {
  ok: boolean;
  detail?: string;
  purge_id?: string;
  rows_cleaned?: number;
}

/** Purge request body (POST /cache/purge). */
export interface PurgeBody {
  scope: "all" | "url";
  url?: string;
  delete_everything?: boolean;
}

/** One bulk-route per-site result (POST /cache/bulk-purge, PUT /cache/bulk-config). */
export interface BulkResult {
  site_id: string;
  ok: boolean;
  detail: string;
  config_version?: number;
}

/** The bulk-config presets the backend accepts (presetConfig in dto.go). */
export type PerfPreset = "safe" | "balanced" | "aggressive";

/** Cache refresh interval options (cache_refresh_interval). */
export const CACHE_REFRESH_INTERVALS = [
  { value: "30min", label: "Every 30 minutes" },
  { value: "1hour", label: "Every hour" },
  { value: "2hours", label: "Every 2 hours" },
  { value: "6hours", label: "Every 6 hours" },
  { value: "12hours", label: "Every 12 hours" },
  { value: "daily", label: "Daily" },
] as const;

/** JS delay execution methods (js_delay_method). */
export const JS_DELAY_METHODS = [
  { value: "defer", label: "Defer (recommended)" },
  { value: "interaction", label: "Delay until interaction" },
] as const;

/** CDN file-type scopes (cdn_file_types). */
export const CDN_FILE_TYPES = [
  { value: "all", label: "All static files" },
  { value: "images", label: "Images only" },
  { value: "css_js", label: "CSS & JS only" },
] as const;

/**
 * CDN providers (cdn_provider). The backend accepts ONLY these three values (plus
 * empty); a free-text field let operators type partial/invalid values that 422.
 * The leading empty option lets it be cleared (empty is backend-valid).
 */
export const CDN_PROVIDERS = [
  { value: "", label: "Select a provider…" },
  { value: "cloudflare", label: "Cloudflare" },
  { value: "bunny", label: "Bunny" },
  { value: "keycdn", label: "KeyCDN" },
] as const;

/** Database auto-clean intervals (db_auto_clean_interval). */
export const DB_CLEAN_INTERVALS = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "monthly", label: "Monthly" },
] as const;

// ---------------------------------------------------------------------------
// Cache Hit-Ratio History (#162)
// ---------------------------------------------------------------------------

/** One data point in the cache hit-ratio trend (GET /perf/cache/health). */
export interface CacheHitRatioPoint {
  /** Number of cache hits in the sample window. */
  hit_count: number;
  /** Number of cache misses in the sample window. */
  miss_count: number;
  /** Hit ratio as a percentage (0..100). */
  ratio_pct: number;
  /** RFC 3339 timestamp of the sample. */
  sampled_at: string;
}

/** Response from GET /api/v1/sites/{siteId}/perf/cache/health */
export interface CacheHealthResponse {
  /** Trend series ordered oldest-first. Empty until the agent reports traffic. */
  points: CacheHitRatioPoint[];
  /** Average hit ratio across all points (0.0 when < 1 point). */
  avg_ratio_pct: number;
}

// ---------------------------------------------------------------------------
// DB Size History (Phase 3.4)
// ---------------------------------------------------------------------------

/** One data point in the 90-day DB-size history. */
export interface DbSizeTrendPoint {
  /** UUID */
  id: string;
  /** ISO 8601 timestamp of the scan that produced this row. */
  scanned_at: string;
  /** Raw database size in bytes. */
  db_size_bytes: number;
  /** Number of tables at scan time. */
  table_count: number;
}

/** Response from GET /api/v1/sites/{siteId}/perf/db/health */
export interface DBHealthResponse {
  /** Trend series ordered oldest-first. Empty until scans accumulate. */
  points: DbSizeTrendPoint[];
  /** Absolute byte growth from first to last point (0 when < 2 points). */
  growth_bytes: number;
  /** Percent growth from first to last point (0.0 when < 2 points). */
  growth_pct: number;
}

// ---------------------------------------------------------------------------
// Fleet DB Health aggregate (Phase 3.7)
// ---------------------------------------------------------------------------

/**
 * One entry in the top-N largest / fastest-growing site list returned by
 * GET /api/v1/perf/db/fleet-health.
 *
 * Field names match the Go FleetSiteDbSummary JSON tags exactly.
 */
export interface FleetDbTopSite {
  /** UUID of the site. */
  site_id: string;
  /** Human-readable site name (Go field: site_name). */
  site_name: string;
  /** Latest recorded database size in bytes. */
  db_size_bytes: number;
  /** Number of orphaned wp_options candidates from the latest scan. */
  orphaned_options_count: number;
  /** Number of orphaned cron-event candidates from the latest scan. */
  orphaned_cron_count: number;
  /** Absolute byte growth from first to last recorded point (0 = no data). */
  growth_bytes: number;
}

/**
 * Tenant-level aggregate returned by GET /api/v1/perf/db/fleet-health.
 *
 * Field names match the Go FleetDbHealth JSON tags exactly
 * (apps/api/internal/perf/model.go, FleetDbHealth struct).
 */
export interface FleetDbHealth {
  /** Number of sites that have at least one scan result. */
  total_sites_scanned: number;
  /** Sum of the latest recorded DB size across all scanned sites (bytes). */
  total_db_size_bytes: number;
  /** Sum of table counts across all scanned sites. */
  total_table_count: number;
  /** Sum of orphaned wp_options candidates across all scanned sites. */
  total_orphaned_options: number;
  /** Sum of orphaned cron-event candidates across all scanned sites. */
  total_orphaned_cron: number;
  /** Number of sites that have at least one orphan candidate (options or cron). */
  sites_with_orphans: number;
  /** Top-N sites ordered by DB size descending (typically <= 10). */
  top_sites: FleetDbTopSite[];
}

// ---------------------------------------------------------------------------
// DB Orphans (Phase 3.5 / 3.6)
// ---------------------------------------------------------------------------

/**
 * Confidence level of the corpus-based ownership attribution for an orphan item.
 *   exact      — a corpus entry matched the name exactly.
 *   prefix     — a corpus entry matched a table/option prefix pattern.
 *   heuristic  — a heuristic rule (e.g. known option naming conventions) matched.
 *   unknown    — no corpus match; ownership cannot be attributed.
 */
export type OrphanConfidence = "exact" | "prefix" | "heuristic" | "unknown";

/**
 * One orphaned item — options row, cron event, or database table.
 * Fields are optional per the DTO: not all fields apply to all three categories.
 */
export interface OrphanItem {
  /** The option name, cron hook name, or table name. */
  name: string;
  /** The likely owning plugin slug (uninstalled). Empty when confidence="unknown". */
  owner_slug?: string;
  /** How confident the corpus match is. */
  confidence: OrphanConfidence;
  /** All corpus slugs that matched (length > 1 = ambiguous attribution). */
  known_plugins?: string[];
  /** True when the item is still owned by a currently-installed plugin (not a real orphan). */
  installed: boolean;
  /** Conservative pre-gate: true when this item is eligible for deletion in a later phase. */
  deletable_eligible: boolean;
  /** Size in bytes — options + tables only. */
  size_bytes?: number;
  /** Whether the option is autoloaded on every request — options only. */
  autoload?: boolean;
  /** Next scheduled run (Unix timestamp) — cron only. */
  next_run_at?: number;
  /** Recurrence string (e.g. "hourly") — cron only. */
  recurrence?: string;
  /** Row count — tables only. */
  rows?: number;
}

/** Aggregate counts returned alongside the orphan lists. */
export interface OrphanCounts {
  options: number;
  cron: number;
  tables: number;
  /** Total items eligible for deletion (conservative pre-gate). */
  deletable: number;
}

/**
 * Full orphan report from GET /api/v1/sites/{siteId}/perf/db/orphans.
 * Mirrors the Go OrphansReport DTO exactly.
 */
export interface OrphansReport {
  options: OrphanItem[];
  cron: OrphanItem[];
  tables: OrphanItem[];
  /** Corpus version used for the attribution pass. */
  corpus_version: number;
  /**
   * When false the scan came from an agent version that does not include the
   * installed-plugins snapshot required for ownership attribution. Counts are
   * surfaced but no item is marked eligible.
   */
  snapshot_available: boolean;
  counts: OrphanCounts;
  /**
   * Number of items that were attributed to an installed plugin and excluded
   * from the options/cron/tables lists. These are not true orphans.
   * Absent (or zero) when no items were hidden.
   */
  hidden_installed?: number;
}
