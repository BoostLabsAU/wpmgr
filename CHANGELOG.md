# Changelog

All notable changes to WPMgr are documented here.
Format: Keep a Changelog (keepachangelog.com). Versioning: SemVer (semver.org).
House rules: no em dashes, no en dashes, no competitor names. Use "to" for ranges.

## [Unreleased]

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
