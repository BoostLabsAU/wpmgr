# Changelog

All notable changes to WPMgr are documented here.
Format: Keep a Changelog (keepachangelog.com). Versioning: SemVer (semver.org).
House rules: no em dashes, no en dashes, no competitor names. Use "to" for ranges.

## [Unreleased]

## [0.33.9] - 2026-06-10

### Changed

- WordPress.org plugin-directory compliance hardening for the agent (raised by the directory pre-review): request inputs including `$_SERVER` and `$_COOKIE` are sanitized; the media quarantine and database-snapshot data now write under the uploads directory, with a read fallback to the legacy `wp-content` location so existing installs keep working; the diagnostics info REST endpoint now binds its signed token to the site and endpoint (not just signature-valid); the login-screen branding CSS is enqueued instead of echoed; and the agent readme now documents every external service it can contact (control plane, object storage, ipify, Cloudflare, Google Fonts, Gravatar, and the optional third-party asset self-hosting) plus the public source of the bundled minified scripts. The streaming `mysqli` backup/restore connections and local file reads are kept and justified to the reviewer (the same pattern approved backup plugins use). No change to backup, cache, or optimization behavior.

## [0.33.8] - 2026-06-10

### Fixed

- Resolved 15 code-review findings (raised by automated review on earlier merged PRs, each re-verified against current code before fixing):
  - Agent: WooCommerce cart-fragments now inject on themes whose body tag carries attributes (the shim previously matched only a bare body tag); the cart-fragments load replay fires on the window; the cache hit tally counts 304 and HEAD responses; cache stats are staged and deleted only after a confirmed upload (with recovery of an interrupted batch); the stats consumer counts events by file size instead of reading whole files; and the Unused Image Cleaner bounds its in-use list.
  - Control plane: the cache hit-ratio history now returns the most recent data, daily-downsampled, instead of the oldest 366 hourly rows; a backup status no longer regresses after a failure is published; Media Cleaner thumbnail URLs are sanitized server-side; the OpenAPI auth documentation was refreshed and the missing auth paths documented; and a brittle deprecated-refcount test assertion was removed.
  - Web: Media Cleaner guards agent-supplied thumbnail URLs to http and https only; the agent-plugin download opens in a separate tab so a failed cross-origin download cannot replace the dashboard and lose the pairing code.
  - Build: the landing copy gate now runs as part of the landing build and uses a portable file path; the release Makefile validates the version as semver before stamping it into the plugin.

## [0.33.7] - 2026-06-10

### Fixed

- Optimize tab: changing one setting no longer makes every toggle flicker. The saving spinner and disabled state are now scoped to the row being changed instead of being applied to all rows at once, and a fast double-toggle no longer momentarily reverts.

## [0.33.6] - 2026-06-10

### Changed

- The site header "Open wp-admin" button now logs owners and admins straight into wp-admin in a new tab (one-click auto-login using a signed, single-use token) instead of landing on the WordPress login form. Non-admin viewers keep a plain wp-admin link.

## [0.33.5] - 2026-06-09

### Fixed

- The Real User Monitoring dashboard's default "All devices" tab showed "No data" even when the per-device tabs had data. The summary read path returned one row per device and country but never the device-agnostic aggregate the "All" tab reads, so the default view found nothing. The summary now returns, per metric, one country-collapsed row per device plus one all-devices aggregate (device-agnostic, summed across every device and country), and the 28-day trend collapses to a single series per metric for the selected device segment (or across all devices for "All"). The all-devices aggregate also crosses the minimum-sample floor sooner, so the dashboard populates with fewer total pageviews. Per-device tabs now also sum correctly across countries instead of showing a single country's slice. Control-plane only; no agent, migration, or data change.

### Added

- Core Web Vitals distribution bars on the Real User Monitoring dashboard. Under each p75 metric card (LCP, INP, CLS, and the secondary FCP and TTFB) a single stacked bar now shows the share of real pageviews in the good, needs-improvement, and poor bands, the way PageSpeed Insights and Search Console present field data. The bands are folded server-side from the histogram rollups already stored, at the standard Core Web Vitals thresholds, and respect the same minimum-sample floor that suppresses the p75 (a low-sample slice shows "insufficient samples", never a misleading bar).
- A 28-day p75 trend chart per metric on the Real User Monitoring dashboard, with the good and needs-improvement threshold lines drawn on it, so the operator can see where each metric sits relative to passing over time. Days below the sample floor render as a gap rather than a zero. A new read endpoint, `GET /api/v1/sites/:siteId/perf/rum/trend`, serves the daily series from the existing rollups, with no new tables and no agent change. Both the distribution and the trend follow the selected device tab and update live over SSE.

### Fixed

- The Real User Monitoring collector script is now served from a versioned URL, so a CDN or browser cache refetches it whenever the agent updates. The collector was served from a static, unversioned filename, so a long-lived edge cache (for example a one-year CDN TTL) could keep serving the previous collector build after a plugin update until the cache was manually purged, masking collector fixes. Versioning the URL changes it on every update, so the edge and the browser pick up the new bytes automatically.

## [0.33.3] - 2026-06-09

### Fixed

- Real User Monitoring now reliably collects CLS (Cumulative Layout Shift) on cached pages. In web-vitals, the CLS reporter is armed inside the First Contentful Paint callback; the browser collector was registering CLS before FCP, which on an already-cached page widened the timing window in which a load-and-leave visitor could hide the page before the CLS reporter was armed, dropping the measurement. The collectors are now registered in the canonical web-vitals order (TTFB, FCP, LCP, CLS, INP) so the CLS reporter is armed in the same delivery task as FCP, before any page-hide can interrupt it. Verified with a headless-Chromium repro test that induces a guaranteed layout shift then forces page-hide. Agent-only; no server or data change.

## [0.33.2] - 2026-06-09

### Fixed

- Real User Monitoring now collects CLS (Cumulative Layout Shift), completing Core Web Vitals coverage. In web-vitals, the CLS reporter is armed only after First Contentful Paint resolves, and the collector was loaded as a deferred script at the end of the page, so on a load-and-leave visit the page could be hidden before the CLS reporter was ever armed and no CLS measurement was sent. The collector is upgraded to web-vitals 5 (which resolves the paint gate correctly on briefly-hidden pages) and is now loaded early and asynchronously from the page head, so CLS is captured on every visit. Loading the collector earlier also slightly improves LCP and FCP accuracy. No server or data change.

## [0.33.1] - 2026-06-09

### Fixed

- Real User Monitoring now collects CLS and INP. The browser collector queued metrics and sent them in one batch when the page was hidden, but CLS and INP only finalize at page-hide and could be dropped by that flush, so only LCP, FCP, and TTFB were reported. The collector now sends each metric the moment it is finalized, so all Core Web Vitals are captured. INP still requires a real visitor interaction to exist, and CLS reports 0 on pages with no layout shift.

## [0.33.0] - 2026-06-09

### Added

- Real User Monitoring (RUM). Per-site, opt-in, off by default. When enabled, a
  tiny first-party collector script is injected into cached pages by the agent at
  cache-write time. The site visitor's browser beacons Core Web Vitals (LCP, INP,
  CLS, FCP, TTFB) plus page-load timing directly to the control plane. Data is
  anonymous: the page path is stored with the query string stripped, the IP is
  used only transiently for coarse country lookup then discarded, and no cookies
  or cross-site identifiers are set. Measurements are stored in Postgres histogram
  rollups (hourly and daily, with ClickHouse available as an opt-in scale backend
  via the same boot-selection pattern as the existing metrics store). The operator
  dashboard shows p75 per metric with per-URL and per-device breakdowns, live
  updates over SSE, and a minimum-sample floor that suppresses any slice below the
  configured count so noise is never presented as a metric. On a self-hosted
  control plane, all RUM data stays on the operator's own infrastructure.

## [0.32.1] - 2026-06-09

### Fixed

- The Cache and Optimize settings pages failed to load with an internal server error for every site after 0.32.0. The font-subsetting change in 0.32.0 added three new per-site columns but the read and save queries for the performance config were not regenerated to match, so the database read returned more fields than the query selected and errored. Both queries are now aligned; loading and saving performance settings works again, and the font-subsetting toggle now persists correctly (it was silently not saving in 0.32.0). Control-plane only; no agent, migration, or data change.

## [0.32.0] - 2026-06-09

### Added

- Font subsetting (experimental, default OFF). When both WOFF2 transcoding and font subsetting are enabled, the media-encoder produces a subsetted WOFF2 covering the latin-ext unicode range (U+0000 to 00FF, U+0100 to 024F, U+1E00 to 1EFF) alongside the full WOFF2. The browser fetches the subset for in-range codepoints and falls back to the full WOFF2 for anything outside that range, so no codepoint is ever broken. Typical savings on top of WOFF2 transcoding are 60 to 90 percent for body-text Latin fonts. Variable fonts and icon fonts are detected and skipped automatically; the full WOFF2 serves for those. Subsetting is gated behind the new `fonts_subset` per-site flag (default OFF) because OpenType shaping features (GPOS/GSUB ligatures and contextual kerning) are not preserved in the subset output.
- Per-font processing table on the Optimize tab. Each self-hosted font discovered on the site appears as a row showing its family name, original format, original size, WOFF2 size, subset size when available, savings percentage, and current state (pending, converting, ready, subsetted, skipped, or failed). A live indicator in the card header streams aggregate progress during an active page build. Skipped and failed rows show the reason so you can verify that icon or variable fonts were correctly left alone.
- External-stylesheet font discovery. The agent now scans fonts loaded by classic themes and plugins via enqueued external stylesheets, in addition to the inline style block scan added in ADR-052. This closes the main discovery gap for sites that load fonts through `wp_enqueue_style` rather than printing inline font-face rules.

## [0.31.2] - 2026-06-09

### Added
- WordPress.org distribution build ("Fleet Agent for WPMgr") that passes the official Plugin Check with zero errors. A build-time variant excludes the control-plane self-updater from the WordPress.org package, since those builds update through WordPress.org; the self-hosted and SaaS builds keep control-plane self-update.
- Public Terms of Service and Privacy Policy pages on the control plane (manage.wpmgr.app/terms and /privacy), linked as the external-service disclosure from the agent readme.

### Changed
- Agent code hygiene for WordPress.org compliance: all diagnostic logging now routes through a debug-gated helper that writes only under WP_DEBUG_LOG or WPMGR_DEBUG; swapped to WordPress wrappers where appropriate (wp_parse_url, wp_delete_file, wp_mkdir_p, wp_rand, wp_remote_get); added request unslashing and sanitization; and annotated the intentional streaming file and plugin-owned table database operations. No behavior change to backups, restore, cache, or performance.
- The WordPress.org build declares GPLv2 or later. The source stays MIT, which is GPL compatible.

## [0.31.1] - 2026-06-08

### Fixed

- Cancelling enrollment of a site that never connected now removes it cleanly so you can add the same URL again immediately. Sites that have connected are still archived with their history, as before.
- The Sites page now surfaces disconnected sites even when you have no active sites, with Reconnect and Remove actions, so a previously connected or stranded site is never trapped on an empty screen.
- Adding a URL already on your account now offers to reconnect that site (or open it if already connected) instead of returning a raw error.

## [0.31.0] - 2026-06-08

### Added

- Font transcoding to WOFF2. Per-site, opt-in, default OFF. When enabled, WPMgr transcodes self-hosted fonts (TTF, OTF, WOFF) to WOFF2 and serves the compressed variant with the original as a format() fallback. Typical savings are 50 to 65 percent for TTF and OTF, and 20 to 30 percent for WOFF. Transcoding runs in the background in the media-encoder service; the original font is served until the WOFF2 is ready, so pages never wait, and any transcoding failure falls back to the original so a font never renders broken.

### Fixed

- Google Fonts setting copy that incorrectly said "and combine": WPMgr self-hosts each Google Fonts stylesheet individually and does not combine them.

## [0.30.0] - 2026-06-08

### Added

- WooCommerce cart-session page caching (#169). Per-site, opt-in, default OFF. When enabled, catalog pages (shop, category, home, blog) are served from the page cache for shoppers who already have items in their cart; cart totals and the mini-cart update live via WooCommerce's own cart-fragments mechanism. Cart, checkout, and account pages are always bypassed. WPMgr auto-detects whether the active theme supports cart fragments and only surfaces the toggle when it does. Conservative by design: any uncertainty about theme support, cart state, or a sensitive form token causes the full uncached page to be served so a shopper never sees the wrong cart.

## [0.29.0] - 2026-06-08

### Added

- `validate-env` command (also `make validate-env`) that checks your configuration and prints every problem at once before you start the stack, so you discover missing or invalid environment variables before the first container starts instead of one restart at a time.

### Changed

- The control plane no longer restart-loops when a required setting is missing or invalid. It stays up in a degraded state: `/healthz` keeps answering, and `/readyz` returns a 503 that names exactly which environment variables are misconfigured (names and reasons only, never values), so you can read the endpoint to diagnose the problem instead of watching a crash loop.

### Fixed

- A failed backup now shows the real reason in the dashboard (for example a database connection failure) instead of only a generic "stalled, no progress" message. The agent's failure detail is preserved on the snapshot so you can see what actually went wrong.

## [0.28.1] - 2026-06-08

### Fixed

- Backups on hosts that expose MySQL over a Unix socket (for example a `DB_HOST` of `localhost:/var/run/mysqld/mysqld.sock`). The database dumper now parses the host, port, and socket path from `DB_HOST` the same way WordPress core does, and connects over the socket instead of dropping the path and failing the dump. Sites that connect over TCP are unaffected.

## [0.28.0] - 2026-06-08

### Added

- Cache hit-ratio history (#162). Per-site page-cache hit and miss counts are now recorded as a time-series and shown on the performance dashboard as a trend chart with 7, 30, and 90 day windows. The agent tallies hits and misses to lightweight per-hour files so no database work is added on a cache hit; the control plane mirrors the rollup into its own time-series so you can track how cache effectiveness changes over time without slowing down cached responses.

### Changed

- Guided "Connect your site" onboarding. After signing up, the first-site flow now leads straight into the real connect step. It walks through downloading the agent plugin, opening the WPMgr menu in wp-admin, pasting the control-plane URL (shown inline for one-click copy), pasting the one-time pairing code, and clicking Enroll. The wording matches the labels in wp-admin so there is no guesswork. Earlier experimental auto-install options are hidden for now and will return once the agent is published on the WordPress.org plugin directory.

### Fixed

- Unused Image Cleaner quarantine path safety. If the WordPress content directory cannot be determined, the unused-image quarantine now refuses to run instead of falling back to a path at the filesystem root, which previously could cause permission failures or writes outside the wp-content directory. Normal sites are unaffected.

## [0.27.0] - 2026-06-08

### Changed

- Unified versioning: the open-source release tag, the api/web/media-encoder container images, and the WordPress agent plugin now all share one version number. The number jumps from 0.20.0 to 0.27.0 to land above the agent's prior 0.25.x line so the agent self-update applies cleanly. From this release forward a single tag controls what ships everywhere.

### Fixed

- Unused Image Cleaner: a re-scan no longer resurfaces attachments that are already in quarantine. Isolated items (files moved to quarantine, post still present) are excluded from scan candidates and reported as a separate quarantined count.

## [0.20.0] - 2026-06-08

### Added

- Incremental backup engine v1 (ADR-048) and chain restore (ADR-049). Schedule incremental backups per-site via a toggle on each backup schedule. An increment compares the live file tree against the parent snapshot's file list by size and modified time, packs only changed and new files into standard part archives, and streams deletions to a tombstone sidecar on disk. The state the agent must carry across requests dropped from thousands of per-file records to roughly 25 part names, the same tiny cursor a full backup uses, which was the root cause of the previous 0-files silent data-loss bug. Restore overlays each generation in order with newest-wins extraction and then applies tombstone deletes, so any point in the chain restores correctly. The database is dumped in full on every run. The archive-delta rewrite (ADR-051) replaced the previous per-file chunk scanner and is the shipping incremental model.
- Incremental chain visibility: incremental backups render as a single expandable row grouping the base backup and all its increments, with chain fields, a badge, and SSE phase labels that report progress in real time.
- Point-in-time restore version picker (chain restore, ADR-049): when restoring, pick the exact snapshot to roll back to. Files and database both restore to the chosen point in the chain, with the site staying online throughout.
- Selective-component backup: choose which components to include per backup (files, database, WP core), define exclusion patterns, lock a snapshot to prevent it being swept by retention GC, and receive a backup-completion email. Backup settings are now decoupled from the schedule so each schedule carries its own component selection.
- Mark-and-sweep retention GC (ADR-050): old backup generations are collected automatically based on configurable retention rules without manual cleanup.
- Standalone Search and Replace tool (serialization-safe): run a database-wide find-and-replace that handles PHP-serialized data correctly, so URLs and other structured values survive without corruption.
- Database Snapshots tool: take a quick local database snapshot before a risky change, then revert to it instantly if something goes wrong. Faster and lighter than a full backup for local safety nets.
- Unused Image Cleaner (Media): scans the WordPress media library for attachments that are not referenced anywhere and reports exactly where each in-use image appears (post content, block editor image IDs, SEO meta fields, options pages, and more). Unused images move to a reversible server-backed quarantine; permanent deletion requires an explicit confirmation step. Conservative by design: any ambiguous reference is treated as in-use, so a genuinely used image is never flagged. The optimizer's own bookkeeping metadata is excluded from the scan.
- Media Optimizer reliability: the scale-to-zero encoder now wakes when a job is enqueued, so jobs no longer sit waiting on a cold encoder. The encoder also shuts down gracefully, and cancelling an optimize job cancels its background encode job so no orphaned work remains.
- API spec coverage: the restore-run and schedule-run backup endpoints are now documented in the OpenAPI spec, with a routes-contract test to keep them in sync.
- Brand favicon (Fleet Hub mark) and theme-color meta tag in the web app.

### Fixed

- Self-host key and secret generator now produces correct values; `.env.example` is updated to match. The Docker Compose setup is more resilient to partial starts.
- Incremental reliability: base file-index bootstrap, chain-merge file-index correctness, auto-rebase on corrupt chain, 0-files data-loss prevention, and single-pass chunking performance are all addressed across a series of targeted fixes.
- PHP and JS CI jobs are green: PHPUnit mocks and ESLint both pass cleanly.

## [0.19.0] - 2026-06-04

### Added

- Database Cleaner Phase 3.1 (Corpus Foundation): adds the `plugin_signatures` global reference table to Postgres, a v1 seed covering the ~120 highest-orphan-risk plugins with their known option, transient, table, and cron-hook name patterns, and an `internal/dbclean` Go package skeleton with the `CorpusReader` interface, `CorpusPostgresReader` backed by a new sqlc query, `Signature` type, `ConfidenceLevel` enum (exact / prefix / heuristic / unknown), and the `Classification`, `OrphanedOption`, `OrphanedCronEvent` types. Nothing in this phase is destructive; the corpus is dormant read-only reference data. Includes `tools/corpus-gen/`, an offline tool (separate Go module; never part of the API build) that lists popular slugs from the wordpress.org API, downloads plugin ZIPs, scans PHP source, applies document-frequency suppression and a generic-literal blocklist, and emits a SQL seed migration. The tool enforces ZIP-SLIP and SSRF guards, a 2 req/s rate limit, and validates all emitted patterns as RE2-safe before writing. Migration M40. (Migrations 20260605000000, 20260605010000.)
- Database Cleaner Phase 3.1 security hardening: all anchored prefix patterns in the corpus seed must now have at least 4 characters before the first underscore (the minimum prefix body length). Short prefixes such as `^et_`, `^ep_`, `^lp_`, `^ls_`, `^kb_`, `^vc_`, `^nf_`, `^bp_`, `^gf_`, `^rg_`, `^fm_`, `^ac_`, `^um_`, and `^ct_` were removed or replaced with longer unambiguous co-prefixes (for example `^elasticpress_`, `^ultimate_member_`, `^learnpress_`, `^ninja_forms_`). The `corpus-gen` tool enforces the same floor via `minPrefixBodyLen = 4` and rejects short patterns at generation and emission time. `WPMGR_DB_MIGRATION_DSN` (owner DSN) is now documented as required: the seed migration inserts rows into `plugin_signatures` where `wpmgr_app` has INSERT revoked; the API server logs a startup warning when the env var is unset. The `plugin_signatures` REVOKE statement is now mirrored in `db/schema.sql` so tooling diffing against the live database sees the complete write guard. `.gitignore` updated to exclude the forbidden reference directories and the `corpus-gen` compiled binary.
- Database Cleaner, end to end. A full self-contained workflow now ships for scanning and cleaning a WordPress database: a read-only scan shows how many rows each category holds and how much space a clean would recover before anything is deleted; a per-table inventory lists every table with its row count, size, storage engine, and overhead; each table is labelled as WordPress core, an active plugin or theme, or an orphan left behind by a removed plugin; orphaned options and cron events are classified by matching against the corpus of known plugin signatures and marked with a confidence level (exact, prefix, heuristic, or unknown); a 90-day health trend records database size and overhead over time so you can see whether cleanup is keeping pace with growth; a fleet view surfaces every site's database health in one place so you can act on the worst offenders across a portfolio without opening each site individually; per-table maintenance actions cover optimize, repair, analyze, convert to InnoDB, empty, and delete, each gated by a typed confirmation; orphaned tables and orphaned option rows can be deleted in bulk with a guarded confirmation; cleanup tasks can run on a schedule the control plane drives, stream live per-category progress, and are batched so they never lock a busy database; a failed or silent run is detected and surfaced as failed rather than appearing stuck. Agent 0.15.3 to 0.15.9.
- Performance Suite, per site and across your whole portfolio. Turn on full-page caching and WPMgr serves anonymous pages as pre-gzipped HTML straight from disk, with logged-in, per-role, mobile, and per-query cache variants, bypass rules for cart and checkout pages, a configurable refresh interval, manual and automatic purge, and a preload warmer. The server fast-path installs automatically on Apache, with a copy-paste snippet for nginx and built-in handling for OpenLiteSpeed and WP Engine.
- Asset optimization that makes pages lighter without breaking them: CSS and JS minification, JS delay, font display-swap and self-hosting, lazy-load with width and height and srcset preserved, bloat removal, CDN URL rewriting with encrypted credentials, and on-demand or scheduled database cleanup. A failed optimization never breaks the page, it simply falls back to the original asset.
- Remove Unused CSS strips the rules a page does not use and inlines only what it needs, computed by WPMgr's own engine with no headless browser and no third-party service. Interactive states like hover and focus are always kept, a per-site safelist covers anything added by scripts, and results are cached and shared across pages with the same structure. On a cache miss or any failure the full CSS is served, so rendering is never blocked.
- Per-site controls plus portfolio bulk actions: save the performance config for one site, purge the cache across many sites at once, or apply a safe, balanced, or aggressive preset to a whole group in one run.

### Fixed

- Remove Unused CSS now keeps sliders, lightboxes, and other JavaScript-driven widgets working out of the box. These build their markup and add their state classes after the page loads, so the optimizer could not see them and stripped their styles, which left a slider stuck hidden. WPMgr now ships a built-in safelist of common runtime classes (sliders, carousels, lightboxes, and is-active or is-initialized style state classes) that is always kept, and the agent now actually sends your per-site safelist to the optimizer so anything you add there is honored too. Existing sites recompute their used CSS once after the update with this safety net applied. Agent 0.15.1.
- The cache "Last purge" gauge now records the time of a purge instead of always showing "Never". The control plane stamps it the moment you run a purge from the dashboard, and the agent also reports its own full-cache purges (for example an automatic purge after you edit content) so the gauge stays accurate even for clears the dashboard did not start. Agent 0.15.2.
- Optimize panel toggles no longer flicker or momentarily revert when you change one setting; each save now updates only what changed instead of refetching and re-rendering the whole panel.
- Fixed three settings that were silently rejected and rolled back when saved: the "Delay until interaction" JavaScript option, the "Every 30 minutes" cache refresh interval, and the CDN provider field (now a picker limited to the supported providers instead of free text).
- The database cleaner now actually works. Previously it reported "0 rows cleaned" no matter what, ignored which cleanup tasks you selected, and never ran on a schedule. It now removes the categories you choose (post revisions, auto-drafts, trashed posts, spam and trashed comments, expired transients, orphaned and duplicate metadata, oEmbed cache, table optimization, and more), streams live per-category progress as it runs, and supports a scheduled automatic clean that the control plane drives. Large cleanups are batched so they never lock a busy database, and the cleanup is careful not to remove rows it cannot confidently identify as safe. Agent 0.15.3.
- The database cleaner now scans before it cleans. A new read-only scan shows, per category, how many rows can be removed and how much space you would reclaim (including table-optimization overhead) before you delete anything, so you can pick exactly what to clean and see the total savings up front. Cleanups now also recover gracefully: if a run goes silent it is detected and reported as failed instead of appearing stuck, and each category reports progress as it goes. Agent 0.15.4.
- The database scan now includes a full per-table inventory: every table with its row count, size, storage engine, and overhead, and a "Belongs to" label that identifies whether a table is WordPress core, owned by an active plugin or theme, or an orphan left behind. The table list is paginated, searchable, sortable, and filterable (all tables, orphans, plugin tables, theme tables, WordPress core), so you can see exactly what is taking up space across the whole database. Agent 0.15.5.
- Table ownership is now far more accurate. Tables are matched to the plugin or theme that created them by inspecting installed source, so a plugin's tables are attributed correctly even when the table name does not match the plugin's folder name (for example WooCommerce's wc_ tables). Active plugins' tables are no longer mislabelled as orphans. You can also act on individual tables now: optimize or repair any table, and drop a leftover orphan table, from the table list, with a typed confirmation required before any table is dropped. Agent 0.15.6.
- You can now empty a table to reclaim space. Emptying a table (such as a large plugin log table) deletes all of its rows but keeps the table itself, which is the right way to clear space without removing the table. Emptying is available per table and in bulk, refuses WordPress core tables outright, and requires a typed confirmation. Bulk actions now run the action you choose instead of always optimizing. Agent 0.15.7.
- Deleting a whole table is now available for plugin and theme tables, not just orphans. "Empty" clears a table's rows while keeping the table; "Delete" removes the table entirely (the owning plugin recreates it on next run if it needs it). Both appear as distinct options per table and in bulk, both refuse WordPress core tables, and both require a typed confirmation. Agent 0.15.8.
- Two more per-table maintenance actions: "Analyze" refreshes a table's row-count statistics so the inventory numbers are accurate, and "Convert to InnoDB" upgrades an older MyISAM table to the modern InnoDB engine without losing data (offered only for tables that are not already InnoDB). Both are safe, non-destructive operations. Agent 0.15.9.

## [0.17.0] - 2026-06-03

Agent: 0.14.0-perf-live.

### Added

- Server-status verify card: the Cache tab now shows the real install state of
  the page cache on the host (web server detected, drop-in present, WP_CACHE
  constant set, managed .htaccess block in place) along with live gauges
  (cached pages, cache size, last purge, last preload). Previously the dashboard
  showed "not set" or zeros even when caching was fully operational.
- Optimization auto-applies on enable: turning on the page cache for a site now
  immediately pushes the full optimization config (CSS/JS minify, lazy-load, font
  display-swap, proper image sizing) to the site by default. Each toggle can still
  be turned off individually.
- Live preload progress: cache preload now streams progress and a completion event
  to the dashboard so the spinner resolves to a result. A client-side stale
  timeout fires if the stream goes quiet, so the UI never hangs indefinitely.
- Remove Unused CSS "Compute now" action: operators can trigger RUCSS computation
  for specific URLs on demand from the dashboard. The job streams a live
  queued to computing to reduced-N% progress sequence. Visitor-driven passive
  background computation continues as before.
- Page-source marker: pages optimized and cached by WPMgr now carry an HTML
  comment footprint with a timestamp ("Optimized and cached by WPMgr"), so
  operators can confirm cache and optimization are active by viewing page source.

### Fixed

- WP_CACHE remediation: when the agent cannot write `define('WP_CACHE', true);`
  to wp-config.php (file not writable), the dashboard surfaces the exact line to
  add manually instead of failing silently.
- nginx and OpenResty sites now correctly reflect that the PHP drop-in serves
  cache hits without .htaccess; the install-state card no longer marks
  `htaccess_managed` as an error condition on those servers.

## [0.16.9] - 2026-06-03

### Added
- Operator account recovery: a one-shot, env-driven seeder (`WPMGR_RECOVER_ACCOUNTS`) recreates a deleted user and re-attaches it as owner of an existing organisation it had lost access to, then logs a one-time set-password link. Lets an instance operator restore an account whose organisation and sites are still intact after an accidental user deletion, without touching the database by hand.

## [0.16.8] - 2026-06-03

### Fixed
- The superadmin orphaned-organisation cleanup added in 0.16.7 silently failed to remove empty organisations: deleting a user left their now-empty organisation behind and the organisation count unchanged. This was a database privilege interaction with the append-only audit log; empty orphaned organisations are now removed reliably when their sole owner is deleted, and organisations that still own sites are still kept and flagged.

## [0.16.7] - 2026-06-03

### Changed
- Superadmin user delete now tidies up the organisations that user solely owned: an organisation left with no members and no sites is removed automatically, and the user list shows an accurate organisation count per user.

### Fixed
- Deleting a superadmin-managed user no longer leaves behind an empty, unreachable organisation. When such an organisation still has sites, it is kept and the operator is warned to reassign or remove it rather than losing track of those sites.

## [0.16.5] - 2026-06-03

### Fixed
- Open self-serve sign up failed for everyone after the first account because every new workspace was created with the same internal identifier. Each sign up now gets a unique one, so registrations no longer collide.

## [0.16.0] - 2026-06-03

### Added
- Superadmin area for instance operators: a cross-tenant user list with search, the ability to delete or disable a user, resend a verification email, and an instance stats overview. Visible only to accounts listed in the superadmin allowlist; it cannot be granted through the app.

## [0.15.5] - 2026-06-02

### Added
- Site sharing now emails the person you share with: a new user gets a branded invite link to set their own password, and an existing user gets a notification that a site was shared with them and is ready in their account.

## [0.15.4] - 2026-06-02

### Fixed
- Creating your first organisation from the welcome screen returned a 403; org create, list, and switch no longer require an existing organisation, and creating one now drops you straight into it.

## [0.15.3] - 2026-06-02

### Added
- Invite teammates to an organisation by email: they receive a branded link and set their own password, so admins no longer choose a password on their behalf.
- A welcome screen that invites you to create an organisation when your account does not belong to one yet.

### Changed
- Trying to sign up with an email that already has an account now sends a short "you already have an account" email pointing to sign in or password reset, instead of doing nothing.

### Fixed
- Saving SMTP settings could fail with a server error; the settings now save reliably.

## [0.15.0] - 2026-06-02

### Added
- UI-configured SMTP: admins set SMTP credentials in Settings, the password is stored encrypted, and a test-send button confirms delivery before saving.
- Self-serve password reset and a strengthened change-password flow; changing a password immediately revokes all other active sessions.
- Open self-serve sign up with email verification: new users register with their email address and gain access only after clicking a verification link.

[0.15.0]: https://github.com/mosamlife/wpmgr/releases/tag/v0.15.0
