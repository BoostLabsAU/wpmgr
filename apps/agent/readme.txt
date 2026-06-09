=== Fleet Agent for WPMgr ===
Contributors: mosamlife
Tags: backup, restore, performance, cache, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.33.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress site to a user-chosen WPMgr control plane for managed backups, performance, and updates.

== Description ==

Fleet Agent for WPMgr links your WordPress site to a control plane that YOU choose and configure. The default endpoint is none -- the plugin is completely inert until you supply a control-plane URL and complete a one-time signed enrollment. The control plane is either a WPMgr instance you self-host or the hosted service at manage.wpmgr.app.

**How it works / security**

The agent accepts only a closed, named allow-list of commands (backup, restore, update, cache operations, diagnostics, and similar). Every inbound command is Ed25519-signature-verified against the enrollment key established at connect time. There is no eval, no remote include, no remote PHP execution of any kind. Core, plugin, and theme updates are applied using WordPress's own native Upgrader against packages from wordpress.org -- not from the control plane directly.

**Feature set**

* Backup and restore -- full or incremental database and file backups, encrypted at rest, streamed to the storage destination your control plane configures. Incremental chains use a content-addressed chunk store so only changed blocks are transferred.
* Performance -- disk-based full-page cache with nginx/Apache fast-path bypassing PHP entirely; Remove Unused CSS (computed on the control plane, no headless browser required); self-hosted web font transcoding (TTF/OTF/WOFF to WOFF2); image optimization pipeline (WebP/AVIF conversion, lossless/lossy re-encode, format transcoding).
* Updates -- bulk WordPress core, plugin, and theme updates with rollback support, applied via the WordPress native Upgrader.
* Security scanning -- vulnerability checks against a managed database, login protection, and error monitoring.
* Uptime and health -- site health diagnostics surfaced in the control-plane dashboard, periodic heartbeat, and environment metadata (PHP, server, active plugins, theme).

All features are opt-in. Connecting the agent and initiating actions from the control plane is the only way any data leaves the site.

== Installation ==

1. Upload and activate the plugin, or install it from the WordPress plugin directory.
2. In your WPMgr control plane (self-hosted or manage.wpmgr.app), open the site-connection screen and generate a one-time signed enrollment token.
3. Paste the token into the Fleet Agent settings screen and click Connect.
4. The site appears in your control-plane dashboard. All management actions run from there.

To disconnect, click Disconnect in the Fleet Agent settings screen or remove the plugin. All communication stops immediately.

== Frequently Asked Questions ==

= Does this plugin phone home? =

No. The plugin contains no default endpoint and makes zero outbound connections until you connect it to a control plane that you supply. It is completely inert on activation.

= Do I need a WPMgr account? =

Only if you use the hosted service at manage.wpmgr.app. You can also self-host the entire WPMgr control plane -- the agent works identically either way. The plugin itself has no dependency on any specific account or service.

= Is my data sent anywhere by default? =

No. Without an active connection to a control plane you configured, no data is sent anywhere. All transmission is initiated by commands from the control plane you enrolled, never autonomously.

= How are updates handled? =

Updates to this plugin are delivered via the WordPress.org plugin directory and applied through the standard WordPress update mechanism. There is no separate update channel in this build.

= Can the control plane execute arbitrary code on my site? =

No. The command dispatcher accepts only a closed, named allow-list of commands. Every command is verified against an Ed25519 signature tied to the enrollment key. There is no mechanism to execute arbitrary PHP, SQL, or shell code.

= What happens if I deactivate the plugin? =

All outbound communication stops immediately. The control plane can no longer reach the site. Stored cache files, optimized images, and backup archives that already exist on disk are not automatically removed -- you can clean those up from the plugin settings before deactivating.

== Privacy / What data is sent and where ==

Fleet Agent for WPMgr does not contact any external service until you connect it to a WPMgr control plane that you choose. There is NO default endpoint; the agent is inert until you supply a control-plane URL and complete a one-time, signed enrollment from that control plane. The control plane is software you point the agent at -- either a WPMgr instance you self-host, or the hosted WPMgr service at https://manage.wpmgr.app.

Once connected, the agent communicates only with the control-plane URL you configured. It sends the following data, only to that endpoint, and only for the management actions you (or your schedules) initiate:

- Site & environment metadata -- site URL, WordPress/PHP/server versions, active theme and plugins, and Site Health diagnostics. Sent on connect, on a periodic heartbeat, and when you click Re-run checks. Used to display your site's status in the dashboard.
- Update inventory -- the list of available core, plugin, and theme updates. Sent when inventory is refreshed. Used to show and apply updates.
- Backup archives (encrypted) -- when you run or schedule a backup, the agent creates an archive of your database and/or files, encrypts it, and uploads it to the storage destination configured by your control plane. Archive contents may include your site's content and personal data; they are encrypted before leaving the server.
- Rendered HTML -- for CSS optimization (used-CSS generation), the agent submits rendered HTML of selected pages so unused CSS can be computed. Used only to produce optimized stylesheets.
- Diagnostics & activity logs -- error logs, performance/cache statistics, and a record of management actions, sent so they can be surfaced in the dashboard.

The agent does not sell or share this data with third parties. It receives signed, allow-listed commands (backup, restore, update, cache operations) from your control plane; it does NOT download or execute arbitrary remote PHP code.

**Real User Monitoring (when you enable it)**

Real User Monitoring (RUM) is off by default and must be enabled per site. It is the one exception to the agent-as-sole-transmitter model above.

When RUM is enabled, the agent injects a small, public measurement script into cached pages at cache-write time. Your site visitor's own browser -- not the agent -- then sends anonymous performance measurements directly to the control plane. The agent itself transmits nothing new; it only adds the script to the HTML it already serves.

What the visitor's browser sends:

- Core Web Vitals (LCP, INP, CLS) plus TTFB and FCP, and page-load timing.
- The page path only -- query strings are stripped before transmission, so tokens, emails, and order IDs in URLs are never sent.
- Coarse, non-identifying context: browser and device type derived from the User-Agent, connection type, and an approximate country code.

What is never collected: cookies, localStorage, cross-site identifiers, or the visitor's full IP address. The IP is used only transiently for rate-limiting and coarse country lookup, then discarded and never stored.

Because this data originates from your site visitors' browsers, you (the site owner) are the data controller for it and must disclose it in your own site's privacy policy. If you self-host the control plane, RUM data stays entirely on your own infrastructure and never reaches WPMgr. If you use the hosted service at https://manage.wpmgr.app, that service processes the measurements on your behalf.

Disable RUM at any time in the Performance settings; the script is removed from newly cached pages immediately.

If you connect to the hosted WPMgr service, that service's Terms of Service (https://manage.wpmgr.app/terms) and Privacy Policy (https://manage.wpmgr.app/privacy) apply. If you self-host the control plane, you operate the receiving service and your own policies apply. You can stop all data transmission at any time by disconnecting the agent (Disconnect in the agent admin screen) or deactivating the plugin.

**How it works / security**

Commands arrive from the control plane over HTTPS. Each command carries an Ed25519 signature produced with the key established at enrollment; the agent verifies the signature before executing any action. The allow-list of permitted commands is compiled into the plugin -- no mechanism exists to add new command types at runtime, and there is no eval, remote include, or remote PHP execution. Core, plugin, and theme updates are applied using WordPress's own Upgrader against packages from wordpress.org only.

== Third-party / Credits ==

**matthiasmullie/minify (MIT)**

CSS and JavaScript minification uses matthiasmullie/minify (^1.3, MIT license), a pure-PHP minification library included in the plugin's Composer dependencies. Source and license: https://github.com/matthiasmullie/minify

Copyright (c) 2012 Matthias Mullie. Licensed under the MIT License.

No other third-party libraries are bundled in the plugin zip. Image encoding and WOFF2 font transcoding run on the control-plane service, not inside this plugin.

== Screenshots ==

1. Fleet Agent connect screen -- enter a control-plane URL and enrollment token to pair the site.
2. Control-plane dashboard -- live status, environment metadata, and health indicators for the connected site.
3. Backup in progress -- incremental backup running with chunk-transfer progress and estimated completion.
4. Performance settings -- page cache, Remove Unused CSS, self-hosted fonts, and image optimization controls.

== Changelog ==

= 0.33.4 =
* Fix: the Real User Monitoring collector script is now loaded from a versioned URL, so a content delivery network or browser cache refetches it whenever the plugin updates. Previously the collector was served from a static filename, so a long-lived edge cache could keep serving the previous collector after an update until the cache was manually purged. This is the control-plane dashboard release that adds Core Web Vitals distribution bars (good / needs improvement / poor) and a 28-day trend chart; the agent change in this version is the cache-busting fix only.

= 0.33.3 =
* Fix: Real User Monitoring now reliably collects CLS on cached pages. The Core Web Vital collectors are registered in the order recommended by the web-vitals library (paint metrics before layout shift) so the CLS reporter is always armed before a load-and-leave visitor can hide the page. Previously, on an already-cached page, the CLS measurement could be dropped in a brief timing window. No effect on backups, cache, or other features.

= 0.33.2 =
* Fix: Real User Monitoring now collects CLS. The collector is upgraded to web-vitals 5 and loaded early (async, in the head) so the CLS reporter is armed before the page is hidden; previously, on a load-and-leave visit, CLS was never sent. No effect on backups, cache, or other features.

= 0.33.1 =
* Fix: Real User Monitoring now collects CLS and INP. The collector sends each Core Web Vital the moment it is finalized instead of batching at page-hide, so CLS and INP (which finalize at page-hide) are no longer dropped. INP still requires a real visitor interaction; CLS reports 0 on pages with no layout shift.

= 0.33.0 =
* Performance: Real User Monitoring (RUM), per-site and off by default. When enabled, the agent injects a tiny first-party measurement script into cached pages; the visitor's browser sends anonymous Core Web Vitals (LCP, INP, CLS, FCP, TTFB) and page-load timing directly to your control plane. No cookies, no cross-site identifiers, the page path is stored with the query string stripped, and the visitor IP is never stored. See the Privacy section.

= 0.32.1 =
* Maintenance: version alignment with the control plane. No plugin functional changes (the fix in this release was control-plane only).

= 0.32.0 =
* Performance: self-hosted font subsetting (experimental, default off). Discovers fonts loaded via external stylesheets in addition to inline font-face rules, and reports per-font conversion progress to the control-plane dashboard. Subsetting and transcoding run on the control-plane service, not inside the plugin.

= 0.31.1 =
* Onboarding: cancel action hard-deletes a site that was never connected; Disconnected-sites empty-state panel now shows Reconnect and Remove actions.
* Backup: incremental backup engine v1 with content-addressed chunk store (ADR-048); incremental chain restore (ADR-049).
* Media: WOFF2 font transcoding -- converts TTF/OTF/WOFF to WOFF2 via a pure-Go transcoder on the control plane (ADR-052); flag `fonts_transcode_woff2` defaults off.
* Web: Fleet Hub brand favicon (SVG) and theme-color meta.
* Fix: PHP and JS CI jobs green.

== Upgrade Notice ==

= 0.33.4 =
Serves the Real User Monitoring collector from a versioned URL so future collector updates are never masked by a content delivery network or browser cache. Pairs with a control-plane dashboard update that adds Core Web Vitals distribution bars and a 28-day trend. Safe to update in place.

= 0.33.3 =
Fixes Real User Monitoring so it reliably collects CLS on cached pages by registering the Web Vitals collectors in the recommended order. Update to capture CLS from real visitors. Safe to update in place.

= 0.33.2 =
Fixes Real User Monitoring so it collects CLS (Cumulative Layout Shift), completing Core Web Vitals coverage. Update to capture CLS from real visitors. Safe to update in place.

= 0.33.1 =
Fixes Real User Monitoring so it collects CLS and INP (not just LCP, FCP, TTFB). Update to capture all Core Web Vitals from real visitors. Safe to update in place.

= 0.33.0 =
Adds opt-in, off-by-default Real User Monitoring (anonymous Core Web Vitals from real visitors). No database changes on the site. Safe to update in place.

= 0.32.1 =
Version alignment with the control plane. No plugin functional changes. Safe to update in place.

= 0.31.1 =
Adds incremental backup engine, WOFF2 font transcoding, and an onboarding cancel fix that hard-deletes never-connected sites. No database changes required. Safe to update in place.
