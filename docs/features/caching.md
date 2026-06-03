# Page caching

Serve a site's anonymous pages as pre-gzipped HTML files straight from disk,
skipping PHP and the database on a hit. Caching runs **entirely in the agent**;
the control plane (CP) is the source of truth for configuration and a mirror of
the gauges the agent reports. **No cached HTML ever lives on the CP.**

Design: [ADR-046](../adr/ADR-046-performance-suite-architecture.md).
Architecture: [architecture/perf-suite.md](../architecture/perf-suite.md).
API: [api/perf.md](../api/perf.md). Agent quirks:
[agent.md → Page cache](../agent.md#page-cache).

> Caching follows the standard WordPress disk-cache pattern used by WP Super
> Cache and Cache Enabler (GPLv2). The implementation is original WPMgr code; no
> third-party plugin source is included. See [NOTICE.md](../../NOTICE.md).

## Enable it (per site)

Per-site, off by default. From the site's **Cache** tab, or:

```bash
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/cache/enable \
  -H "Authorization: Bearer $TOKEN"
```

```json
{ "ok": true, "detail": "cache enabled" }
```

Enable does three things on the host, in order:

1. Sets `define('WP_CACHE', true)` in `wp-config.php` (atomic temp-file + rename).
2. Installs the serving drop-in `wp-content/advanced-cache.php`.
3. Splices the managed `# BEGIN WPMgr Cache` block into the site-root `.htaccess`
   (Apache only; see [server support](#server-support)).

Enabling also pushes the full optimization config (CSS/JS minify, lazy-load, font
display-swap, properly-sized images) to the site immediately, so it is
production-ready out of the box. Each optimization toggle can be turned off
individually afterward via the perf config.

The agent reports back which of these took (`dropin_installed`,
`wp_cache_constant_set`, `htaccess_managed`, `server_software`) into
`site_perf_config`, so the Cache tab shows the true install state. Disable
reverses all three and purges everything.

## Server-status verify card

The **Cache** tab surfaces the real install state of the cache on the host,
reported by the agent on every heartbeat, on enable, and during preload:

| Field | What it means |
|-------|---------------|
| `server_software` | Web server detected (e.g. `Apache/2.4`, `nginx`) |
| `dropin_installed` | `wp-content/advanced-cache.php` is present and owned by WPMgr |
| `wp_cache_constant_set` | `define('WP_CACHE', true)` is in `wp-config.php` |
| `htaccess_managed` | The `# BEGIN WPMgr Cache` block is in `.htaccess` (Apache only) |

Live gauges alongside the status card: **cached pages**, **cache size**, **last
purge**, and **last preload** (sourced from `POST /agent/v1/cache/stats-report`
on a 60s heartbeat cadence).

### WP_CACHE remediation

When `wp-config.php` is not writable, the agent cannot set `WP_CACHE`
automatically. The dashboard detects `wp_cache_constant_set: false` and surfaces
the exact line to add:

```php
define('WP_CACHE', true);
```

Add it in `wp-config.php`, above the line `/* That's all, stop editing! */`.
The drop-in continues to serve cache hits while the constant is absent (WordPress
will not call the drop-in on cache misses, so caching degrades to warm-only until
the constant is set).

### nginx / OpenResty

On nginx and OpenResty the agent detects the server from `$_SERVER['SERVER_SOFTWARE']`,
skips the `.htaccess` write, and sets `htaccess_managed: false`. This is the
correct state — the PHP drop-in serves cache hits without `.htaccess` on these
servers, and `htaccess_managed: false` is not shown as an error on the status card
for nginx/OpenResty sites. See [Server support](#server-support) for the optional
server-level fast-path snippet.

## What gets cached (and what never does)

Only a **full anonymous HTML document, status 200, GET** is stored. The agent's
cacheability gate refuses everything else: POST/AJAX/admin requests, non-200
responses, password-protected singular content, any response that is not a
`<!DOCTYPE html>` document, and any admin/auth/API/system path
(`/wp-admin`, `/wp-login`, `/wp-cron`, `/wp-json`, `xmlrpc.php`, feeds,
sitemaps). A URL carrying an **unknown query parameter** (one that is neither a
known marketing param nor a configured cache-varying param) is treated as
dynamic and is not cached.

Files live at:

```
wp-content/cache/wpmgr/<host>/<path>/<variant>.html.gz
```

## Cache variants

One URL can produce several cache files, one per **variant**. The variant name
is built identically by the PHP drop-in and the agent's key builder so a hit
never mismatches:

| Variant segment | Driven by | When it appears |
|-----------------|-----------|-----------------|
| `index` | always | the base anonymous desktop page |
| `-logged-in` | **Cache logged-in users** | a `wordpress_logged_in_*` cookie is present and logged-in caching is on |
| `-<role>` | **Per role** | the non-HTTPOnly `wpmgr_logged_in_roles` cookie (a logged-in variant is split per role) |
| `-<value>` | **Include cookies** | each configured include-cookie name, in order, appends its sanitized value |
| `-mobile` | **Mobile cache** | the User-Agent matches the mobile pattern and mobile caching is on |
| `-<md5>` | **Include queries** | the surviving query params (marketing params dropped) are `ksort`ed and hashed |

Notes:

- **Logged-in caching is off by default.** With it off, any request carrying a
  `wordpress_logged_in_*` cookie falls through to PHP and is never served from
  disk. With it on, logged-in pages are cached **per role** so an editor never
  sees an admin's cached shell.
- The **query hash** drops known marketing params (`utm_*`, `fbclid`, `gclid`,
  and the like) before hashing, so the same page shared with different campaign
  tags reuses one cache file. A request with **more than 12 distinct
  cache-varying query keys is non-cacheable** so an attacker cannot mint
  unbounded cache files from arbitrary params.

## Bypass rules

Three operator-configured lists disable caching for a request:

- **Bypass URLs** (`cache_bypass_urls`): a case-insensitive substring of the URL
  (for example `/checkout`, `/cart`, `/my-account`).
- **Bypass cookies** (`cache_bypass_cookies`): a substring of any cookie **name**.
  The usual targets are commerce cart/session cookies (`woocommerce_items_in_cart`,
  `edd_items_in_cart`, `comment_author`).
- Two literal opt-outs are always honoured: a URL containing `nocache` or
  `no_optimize`.

`Include queries` and `include cookies` do the opposite: they add a param or
cookie to the cache **key** (a new variant) instead of bypassing.

## Refresh interval, purge, auto-purge, preload

**Refresh interval.** When **Refresh** is on, a WP-Cron job periodically purges
and re-warms the cache on the configured cadence (`30min`, `1hour`, `2hours`
[default], `6hours`, `12hours`, `daily`, `weekly`). This keeps time-sensitive
pages fresh without a manual purge.

**Manual purge.** Purge the whole site, or specific URLs:

```bash
# Purge everything for the site
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/cache/purge \
  -H "Authorization: Bearer $TOKEN" -d '{"scope":"all"}'

# Purge specific URLs
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/cache/purge \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"scope":"url","urls":["https://blog.example.com/hello-world/"]}'
```

Every purge is recorded in `cache_purge_audit` (kind, initiator, URLs) so the
Cache tab can show purge history, and the operator action is hash-chained into
the [audit log](../security.md). When a CDN is configured the purge also signals
the edge (best-effort, tag-based where supported); a CDN failure never fails the
local origin purge.

**Auto-purge.** When caching is enabled the agent hooks WordPress content-change
events and purges the affected URLs automatically (a published or edited post,
its archives, and the home page), then queues them for re-warm.

**Preload (warming).** Preload fetches the configured URLs with a real HTTP
request carrying `x-wpmgr-preload: 1` so the drop-in lets WordPress render fresh
HTML that the writer then stores. Both a desktop and a mobile pass run when
mobile caching is on. Warming is throttled (0.5s between requests, backs off on
429/5xx) and is **SSRF-guarded**: every URL is filtered to the site's own host
before it is fetched, so a command-supplied off-host URL (loopback, cloud
metadata) is dropped.

```bash
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/cache/preload \
  -H "Authorization: Bearer $TOKEN"
```

**Live preload progress.** The agent emits `cache.preload.progress` SSE events
as it warms URLs, and a final `cache.preload.completed` event when the pass
finishes. The dashboard resolves the spinner to a result without polling. If no
completion event arrives within the client-side stale timeout, the UI treats the
preload as finished and re-fetches stats, so it never hangs indefinitely.

## Server support

The PHP drop-in always serves the cache on a hit. A server-level fast-path can
short-circuit to the static `.html.gz` **before** PHP loads, which is faster
still.

### Apache (fast-path, automatic)

On Apache the agent writes a managed `# BEGIN WPMgr Cache` / `# END WPMgr Cache`
block into the site-root `.htaccess` (prepended before WordPress's own rewrites
so the disk fast-path wins). For an anonymous, query-less, cookie-less GET/HEAD
it `RewriteRule`s straight to `wp-content/cache/wpmgr/<host>/<uri>/index.html.gz`
(or `index-mobile.html.gz`). The block is idempotent: re-running rewrites exactly
one block. Direct hits on the cache directory are blocked (`[F]`).

### nginx (manual snippet)

nginx ignores `.htaccess`, so the agent **cannot** auto-install the fast-path. It
detects nginx via `SERVER_SOFTWARE`, skips the file edit, and surfaces a manual
`location` snippet to paste into your server block. Without the snippet the PHP
drop-in still serves every hit. See
[agent.md → Page cache](../agent.md#page-cache) for the snippet and the verify
step.

### OpenLiteSpeed / LiteSpeed

Detected via `LSWS_EDITION`. The agent **strips its own gzip/deflate section**
from the `.htaccess` block because the server already compresses, and
double-gzipping a `.html.gz` corrupts the response. The serve rules stay; the
server handles compression.

### WP Engine and managed Atomic hosts

These platforms ship their own `advanced-cache.php`. The agent detects them (the
`Atomic_Persistent_Data` class) and installs **under the alternate filename**
`wpmgr-advanced-cache.php` instead of clobbering the platform's drop-in, degrading
to leave the platform-owned page cache in place where it is managed. The agent
never overwrites a foreign canonical `advanced-cache.php` it does not recognise
as its own; if another cache plugin owns the drop-in, install reports the
conflict rather than fighting it.

## Page-source marker

Every cached and optimized page carries an HTML comment at the end of `<body>`:

```html
<!-- Optimized and cached by WPMgr | 2026-06-03T10:11:12Z -->
```

View page source to confirm caching and optimization are active. The timestamp is
the moment the cache file was written (UTC). The comment is stripped from any
response the drop-in serves uncached (PHP fall-through, bypass, or miss).

## Cache headers

A served hit carries `x-wpmgr-cache: HIT` and `x-wpmgr-source: PHP` (drop-in) or
`Web Server` (Apache fast-path); a miss carries `x-wpmgr-cache: MISS`. The
response sets `Content-Encoding: gzip`, a `Cache-Tag` (the host, for surgical CDN
purges), `CDN-Cache-Control: max-age=2592000`, and `Cache-Control: no-cache,
must-revalidate`. `Last-Modified` + `If-Modified-Since` yield a `304` when
unchanged.

## Permissions (RBAC)

| Action | Minimum role | Permission |
|--------|--------------|------------|
| View Cache tab / stats | viewer | `site:read` |
| Purge / preload | **operator** | `site.cache.purge` |
| Enable / disable caching, DB clean | **operator** | `site.cache.manage` |
| Save performance config | **operator** | `site.perf.config` |
| **Delete everything** (wipe the whole cache dir) | **admin** | `site.cache.delete-everything` |

Delete-everything is a destructive purge flavour (`scope:"all"` with
`delete_everything:true`); it is re-checked at the admin gate inside the handler
and recorded in the audit log with the consenting actor. Site-scoped
collaborators are gated per-site on every route.
