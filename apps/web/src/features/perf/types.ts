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
  { value: "30mins", label: "Every 30 minutes" },
  { value: "1hour", label: "Every hour" },
  { value: "2hours", label: "Every 2 hours" },
  { value: "6hours", label: "Every 6 hours" },
  { value: "12hours", label: "Every 12 hours" },
  { value: "daily", label: "Daily" },
] as const;

/** JS delay execution methods (js_delay_method). */
export const JS_DELAY_METHODS = [
  { value: "defer", label: "Defer (recommended)" },
  { value: "delay", label: "Delay until interaction" },
] as const;

/** CDN file-type scopes (cdn_file_types). */
export const CDN_FILE_TYPES = [
  { value: "all", label: "All static files" },
  { value: "images", label: "Images only" },
  { value: "css_js", label: "CSS & JS only" },
] as const;

/** Database auto-clean intervals (db_auto_clean_interval). */
export const DB_CLEAN_INTERVALS = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "monthly", label: "Monthly" },
] as const;
