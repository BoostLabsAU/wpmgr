# Performance Suite

Speed up every WordPress site in your portfolio. The Performance Suite covers
page caching, asset optimization, Remove Unused CSS (RUCSS), CDN delivery,
bloat removal, media encoding, and database housekeeping. All features are
per-site and toggled independently.

Design: [ADR-046](../adr/ADR-046-performance-suite-architecture.md).
Architecture: [architecture/perf-suite.md](../architecture/perf-suite.md).
API reference: [api/perf.md](../api/perf.md).

---

## Feature map

| Feature | Where | Detail doc |
|---------|-------|------------|
| Page caching | Agent (PHP drop-in) | [features/caching.md](./caching.md) |
| Asset optimization (minify, fonts, images, JS delay, CDN, bloat) | Agent (WP hooks) | [features/optimization.md](./optimization.md) |
| Remove Unused CSS | Control plane (pure Go) | [features/rucss.md](./rucss.md) |
| Media Optimizer (WebP/AVIF encoding) | Control plane encoder service | [features/media-optimizer.md](./media-optimizer.md) |
| Database Cleaner | Agent SQL + CP corpus | [features/database-cleaner.md](./database-cleaner.md) |

---

## Page caching

Serve anonymous pages as pre-gzipped HTML files from disk, skipping PHP and
the database entirely on a hit.

- The agent installs a `WP_CACHE` drop-in (`advanced-cache.php`), sets
  `define('WP_CACHE', true)` in `wp-config.php`, and on Apache splices a
  managed `.htaccess` fast-path block that serves `index.html.gz` before PHP
  loads.
- On nginx the PHP drop-in still serves every hit; the dashboard surfaces a
  manual `location` snippet for the optional server-level fast-path.
- Cached HTML never leaves the WordPress host. The control plane holds only
  stats (page count, size, last purge/preload) reported by the agent.
- Cache variants let you cache logged-in users per role, mobile visitors
  separately, and pages that vary on specific query parameters or cookies.
- Auto-purge hooks into WordPress content-change events; the refresh interval
  re-warms the cache on a schedule (30 min to weekly).

Enable per site from the **Cache** tab or:

```bash
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/perf/cache/enable \
  -H "Authorization: Bearer $TOKEN"
```

---

## Asset optimization

A fixed 10-stage pipeline runs over rendered HTML in the cache writer's miss
path, so the bytes cached on disk are the optimized bytes.

| Stage | Toggle | Default |
|-------|--------|:-------:|
| Fonts (display-swap, self-host Google Fonts, preload) | `fonts_display_swap` | **on** |
| CSS minify + optional self-host third-party | `css_js_minify` | **on** |
| Remove Unused CSS (RUCSS) | `css_rucss` | off |
| JS minify + optional self-host third-party | `css_js_minify` | **on** |
| Images (lazy-load, width/height, fetchpriority) | `lazy_load`, `properly_size_images` | **on** |
| YouTube facade (load on click) | `youtube_placeholder` | off |
| Self-host Gravatars | `self_host_gravatars` | off |
| JS delay (defer/async/interaction) | `js_delay` | off |
| Speculation / prefetch | `speculation` | off |
| CDN URL rewrite (runs last, catches URLs from prior stages) | `cdn_enabled` | off |

A failed stage degrades to a no-op. A single broken transform never corrupts
or drops the page.

**Bloat removal** strips WordPress default overhead that most sites do not use:
block-library CSS, Dashicons for anonymous visitors, emoji scripts, jQuery
Migrate, XML-RPC, RSS feeds, oEmbed, Heartbeat API throttling, and post
revision limits. Each is an independent toggle under the `bloat_*` prefix.

---

## Remove Unused CSS

Replace a page's render-blocking stylesheets with only the CSS rules its DOM
actually uses. The RUCSS engine runs on the control plane in **pure Go**: no
headless browser, no JavaScript execution.

The agent posts the rendered page HTML and concatenated CSS to the CP. The CP
parses the DOM, matches selectors, and returns only the used CSS. The agent
inlines it in `<head>` and defers the original stylesheets. Runtime-state
pseudos (`:hover`, `:focus`, `:checked`, etc.) and semantic at-rules are always
kept because a static analyser cannot prove them dead.

RUCSS is cached by **structure hash**: all posts sharing a template collapse
to one cached result, so compute cost is amortized across a tenant's page
families.

RUCSS never blocks render: on a cache miss the agent serves the full,
unmodified CSS and the optimized version becomes available on the next request.
On any CP failure or timeout the page is served unchanged with full CSS.

---

## Media Optimizer

Convert JPEG and PNG attachments to **WebP** or **AVIF** (or re-compress
originals) without touching the WordPress host's CPU. Encoding runs on a
separate control-plane encoder service using Discord's MIT-licensed `lilliput`
library. No third-party SaaS, no per-image fees.

The original file is always preserved until you explicitly **Delete originals**
(an irreversible, admin-gated action). A browser that does not support the new
format is served the original transparently via an `.htaccess` Accept-header
rule (Apache) or a manual nginx snippet.

---

## CDN

Rewrite local asset URLs to a CDN host for any or all file types (`all`,
`images`, `css_js`). Supported providers for tag-based edge purge: Cloudflare,
Bunny, KeyCDN. CDN credentials are encrypted at rest on the CP; the agent never
receives or decrypts them. A CDN purge failure never fails the local origin
purge.

---

## Database Cleaner

Scan, classify, and reclaim dead weight in the site's MySQL database: post
revisions, auto-drafts, spam comments, expired transients, fragmented tables,
orphaned meta, and leftover rows and tables from uninstalled plugins.

Every destructive action requires an admin role and a type-to-confirm token. The
corpus-based orphan classifier attributes options, cron events, and tables to
specific wordpress.org plugin slugs; items that cannot be positively attributed
(including all premium/non-wordpress.org plugins) are displayed read-only with
no delete affordance.

The 90-day DB health trend tracks database size growth over time. The fleet view
aggregates across the entire tenant portfolio.

---

## Portfolio bulk actions

Apply a performance preset across many sites at once without touching per-site
CDN or cache include lists:

```bash
curl -X PUT https://manage.wpmgr.app/api/v1/cache/bulk-config \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"site_ids":["6f1c2b7e-…","7a2d3e8f-…"],"preset":"balanced"}'
```

| Preset | Cache | Minify | RUCSS | JS delay | Lazy-load |
|--------|:-----:|:------:|:-----:|:--------:|:---------:|
| `safe` | yes | yes | no | no | yes |
| `balanced` | yes | yes | yes | no | yes |
| `aggressive` | yes | yes | yes | yes | yes |

Bulk-purge all listed sites:

```bash
curl -X POST https://manage.wpmgr.app/api/v1/cache/bulk-purge \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"site_ids":["6f1c2b7e-…"]}'
```

---

## Permissions summary

| Action | Min role | Permission |
|--------|:--------:|------------|
| View Cache tab, stats, DB health, RUCSS results | viewer | `site:read` |
| Purge / preload cache | **operator** | `site.cache.purge` |
| Enable/disable cache, DB clean, per-table optimize/repair/analyze | **operator** | `site.cache.manage` |
| Save performance config, clear RUCSS cache, trigger RUCSS compute | **operator** | `site.perf.config` |
| Delete everything (wipe cache dir), drop/empty table, delete orphans | **admin** | `site.cache.delete-everything` |
| Delete media originals (irreversible) | **admin** | `media:delete_originals` |

---

## SSE events

All performance actions publish on the shared tenant SSE bus
(`GET /api/v1/sites/events`, filter by `site_id` client-side):

`cache.enabled`, `cache.disabled`, `cache.purge.started`, `cache.purge.completed`,
`cache.preload.started`, `cache.preload.progress`, `cache.preload.completed`,
`cache.stats.updated`, `perf.config.updated`, `db.clean.completed`,
`db.scan.started`, `db.scan.completed`, `rucss.queued`, `rucss.computing`,
`rucss.completed`, `rucss.failed`.
