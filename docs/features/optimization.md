# Asset optimization

Shrink and defer a site's front-end assets so anonymous pages render faster. The
agent runs a fixed pipeline over the rendered HTML **inside the cache writer's
miss path**, so the optimized bytes are what get cached AND what the live visitor
receives. Every transform is per-site config-gated and is a no-op when off.

Design: [ADR-046](../adr/ADR-046-performance-suite-architecture.md). RUCSS detail:
[features/rucss.md](./rucss.md). API: [api/perf.md](../api/perf.md).

> Minification uses **matthiasmullie/minify** (MIT). RUCSS is a Go engine on the
> control plane. All other transforms are standard WordPress hooks/filters. See
> [NOTICE.md](../../NOTICE.md) for dependency attribution.

## Graceful degradation (the load-bearing guarantee)

**A failed optimization never breaks the page.** Each stage runs inside a
hard failure guard: any error returns the HTML exactly as it entered that stage,
so a single broken transform degrades to a no-op instead of corrupting or
dropping the page. Logged-in responses are skipped entirely (personalised admin
UI keeps its full, un-deferred CSS/JS). Only full HTML documents are touched;
non-HTML buffers pass straight through. RUCSS is doubly guaranteed never to
throw to this path and serves full CSS on any miss or failure.

## The pipeline (fixed order)

Each step below is on its own per-site toggle. The order is fixed because later
stages depend on earlier ones (CDN rewrite runs last so it catches the URLs the
earlier stages produced).

| # | Stage | What it does |
|---|-------|--------------|
| 1 | **Fonts** | `font-display: swap`, self-host Google Fonts, optional preload of critical fonts |
| 2 | **CSS minify** + self-host | minify CSS via `matthiasmullie/minify`; optionally localise external stylesheets |
| 3 | **RUCSS** | Remove Unused CSS via the control plane (graceful skip on any failure) |
| 4 | **JS minify** + self-host | minify JS; optionally localise external scripts |
| 5 | **Images** | add missing `width`/`height`, lazy-load, `fetchpriority`; **`srcset` is preserved** |
| 6 | **IFrame** | YouTube facade (lightweight placeholder, real embed on interaction) |
| 7 | **Gravatar** | self-host avatar images |
| 8 | **JS delay** | rewrite `src` to a delay attribute + inject the delay runtime |
| 9 | **Speculation** | emit prefetch / Speculation Rules for in-viewport links |
| 10 | **CDN rewrite** | rewrite local asset URLs to the configured CDN host (last) |

## CSS / JS minify

`css_js_minify` (on by default) minifies the page's CSS and JS with
`matthiasmullie/minify`, a small pure-PHP library. **Self-host third-party**
(`css_js_self_host_third_party`) downloads and serves external stylesheets and
scripts from the site's own origin (fewer third-party connections, one fewer DNS
lookup per host). A sheet/script that cannot be resolved to a local file is left
as-is.

## RUCSS (+ safelist)

Remove Unused CSS replaces a page's render-blocking stylesheets with only the
rules its DOM actually uses, computed on the control plane in pure Go. Toggle:
`css_rucss`. The safelist `css_rucss_include_selectors` force-keeps selectors a
static analysis cannot see (for example classes a script adds at runtime). On a
cache miss or any failure the agent serves the full CSS for that render and never
blocks. Full detail: [features/rucss.md](./rucss.md).

## JS delay

`js_delay` defers script execution until the first user interaction so scripts do
not block first paint. Methods (`js_delay_method`): `defer`, `async`, or
`interaction` (delay until scroll/click/keypress). Excludes
(`js_delay_excludes`) keep critical scripts immediate. Third-party scripts can be
delayed independently (`js_delay_third_party` + its excludes).

## Bloat removal

Strip front-end weight WordPress emits that most sites do not use. Each is an
independent toggle:

- `bloat_disable_block_css` — remove the global block-library stylesheet
- `bloat_disable_dashicons` — dequeue Dashicons for anonymous visitors
- `bloat_disable_emojis` — remove the emoji detection script + styles
- `bloat_disable_jquery_migrate` — drop jquery-migrate
- `bloat_disable_xml_rpc` — disable XML-RPC
- `bloat_disable_rss_feed` — disable the RSS/Atom feeds
- `bloat_disable_oembeds` — remove oEmbed discovery + the host JS
- `bloat_heartbeat_control` — throttle the WP Heartbeat API
- `bloat_post_revisions_control` — limit stored post revisions

## Fonts

- `fonts_display_swap` (on by default) — inject `font-display: swap` so text is
  visible while web fonts load.
- `fonts_optimize_google` — self-host Google Fonts (no request to a third-party
  font host).
- `fonts_preload` — preload critical fonts.

## Lazy-load, width/height, srcset

- `lazy_load` (on by default) — add `loading="lazy"` to below-the-fold images and
  iframes. `lazy_load_exclusions` keeps named images eager (typically the LCP/hero
  image).
- `properly_size_images` (on by default) — add missing `width`/`height` so the
  browser reserves layout space (fewer layout shifts), and set `fetchpriority` on
  the primary image.
- **`srcset` is always preserved.** Responsive image sets are never stripped, so
  the browser still picks the right size for the viewport.
- `youtube_placeholder` — replace YouTube iframes with a lightweight facade that
  loads the real embed on click.
- `self_host_gravatars` — serve avatar images from the site's own origin.

## CDN rewrite

`cdn_enabled` rewrites local asset URLs to the configured `cdn_url` for the
selected `cdn_file_types` (`all`, `images`, or `css_js`). It runs **last** in the
pipeline so it rewrites the URLs the earlier stages produced (minified,
self-hosted, RUCSS-inlined). The agent also emits `Cache-Tag` /
`CDN-Cache-Control` headers so the edge can be purged by tag rather than by
enumerating URLs.

Supported providers for tag-based purge: `cloudflare`, `bunny`, `keycdn`. CDN
credentials are entered once and **encrypted at rest on the control plane**
(`cdn_credentials_encrypted`); the CP holds ciphertext only and performs the edge
purge itself. The agent never receives or decrypts CDN credentials. A `cdn_url`
must be a valid `http(s)` URL when CDN is enabled.

## Database cleanup

Reclaim DB weight on demand or on a schedule. Run an ad-hoc clean scoped to the
site's `db_*` toggles:

```bash
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/db/clean \
  -H "Authorization: Bearer $TOKEN"
```

```json
{ "ok": true, "detail": "db clean complete", "rows_cleaned": 1284 }
```

Toggles: `db_post_revisions`, `db_post_auto_drafts`, `db_post_trashed`,
`db_comments_spam`, `db_comments_trashed`, `db_transients_expired`, and
`db_optimize_tables` (runs `OPTIMIZE TABLE`). Set `db_auto_clean` with an interval
(`daily`, `weekly` [default], `monthly`) to run it on a schedule. The DB clean
permission is `site.cache.manage` (operator+).

## Defaults on enable

When you enable the page cache for a site, the full optimization config is pushed
to the agent immediately with the following toggles on by default:

| Toggle | Default |
|--------|---------|
| `css_js_minify` | on |
| `lazy_load` | on |
| `properly_size_images` | on |
| `fonts_display_swap` | on |

All other toggles (RUCSS, JS delay, CDN, bloat removal, etc.) start off. Turn
individual toggles on or off without re-enabling the cache.

## Saving the config

All toggles live in one per-site `site_perf_config` row. Save the whole config
with `PUT /api/v1/sites/{siteId}/perf/config` (operator, `site.perf.config`); the
CP validates it, bumps `config_version`, persists, and pushes it to the agent.
If the agent push fails the config is still stored and a warning is returned in
the `X-Agent-Push-Warning` header (the agent re-syncs on its next config push).
For a portfolio, apply a preset (`safe`, `balanced`, `aggressive`) across many
sites at once via `PUT /api/v1/cache/bulk-config`. Shapes and presets:
[api/perf.md](../api/perf.md).
