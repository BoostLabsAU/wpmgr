# ADR-046 — Performance Suite: caching, optimization & pure-Go RUCSS topology

**Status:** Accepted · **Date:** 2026-06-03
**Relates:** ADR-043 (media optimizer — agent/CP split, presigned transport), ADR-031 (CP→agent signed commands), ADR-038 (SSE channel scoping), ADR-002 (RLS authored by hand), ADR-010 (object storage).
**Recon:** `analysis/perf-suite-recon.md`, `analysis/cache-plugin-patterns.md`.

## Context

WPMgr is adding a Performance Suite with a product surface comparable to leading
WordPress caching/optimization plugins: full-page caching, CSS/JS minification,
Remove Unused CSS (RUCSS), JS delay, font optimization, lazy-load, CDN rewriting,
database cleanup and "bloat" removal. WPMgr is **open-source and self-hostable**,
so every choice must work for a self-hoster running plain `docker compose`, not
only the hosted (Cloud Run) deployment.

Recon surfaced the hard constraints that shape the topology:

- The control plane is a tiny **`CGO_ENABLED=0` `distroless/static`** binary and
  **never executes site PHP**. Page caching is intrinsically a request-time, on-
  box concern — it must live in the **agent**.
- The agent→CP signed channel is **JSON-only with a 4 MiB body cap**; large CSS
  blobs and image bytes never travel through it (ADR-043) — they move via
  **presigned object storage**.
- RUCSS in the reference ecosystem is done with a **headless Chrome SaaS**. That
  is a heavy, proprietary, non-self-hostable dependency. We want an equivalent
  result with **no browser** and **no third-party service**.

## Decisions

### 1. Caching — agent-side disk cache (standard `WP_CACHE` drop-in); CP stores config + stats only

- **Where it runs:** entirely in the **agent**. The agent installs a standard
  WordPress **advanced-cache drop-in** (`wp-content/advanced-cache.php`) and sets
  the **`WP_CACHE` constant** in `wp-config.php`; pages are stored as gzip + plain
  HTML files under `wp-content/cache/wpmgr/` and served from disk on a cache hit
  (PHP drop-in fast-path, with an `.htaccess`/nginx rule to short-circuit to the
  static file before PHP where possible). This is the conventional, well-understood
  WP page-cache mechanism — **no custom kernel module, no Redis requirement**.
- **What the CP holds:** the CP is the **source of truth for configuration**
  (`site_perf_config`) and a **mirror of agent-reported stats** (`site_cache_stats`,
  `cache_purge_audit`) only. **No cached HTML ever lives on the control plane.**
  The agent reads its local mirror of the config on the request fast-path (typed
  `wpmgr_perf_*` wp-options, off by default); the CP pushes changes via a
  `sync_perf_config` signed command (ADR-031), and the agent reports gauges on the
  existing heartbeat/metadata channels.
- **Lifecycle:** the drop-in + `WP_CACHE` + managed `.htaccess` block are installed
  by the `cache_enable` command and on activation, and removed on `cache_disable`,
  deactivation, and the destructive **delete-everything** action. All install
  state the agent observes (`dropin_installed`, `wp_cache_constant_set`,
  `htaccess_managed`, `server_software`) is reported back into `site_perf_config`
  via a dedicated `UpdatePerfInstallState` write so an operator config save never
  clobbers agent-reported facts (and vice-versa).

### 2. Optimization (agent) — minify via `matthiasmullie/minify` ^1.3, everything else native WP filters

- **Minify:** `matthiasmullie/minify` **^1.3** (MIT) for CSS and JS minification —
  a small, pure-PHP, widely-used library that drops into the agent's Composer deps
  (the agent ships its own `vendor/`). Rejected: hand-rolled regex minifiers
  (correctness risk on edge-case CSS/JS) and any native/CGO minifier (the agent is
  PHP). Adding this library to the agent is a **minor dependency** in the agent
  package, recorded here per the no-major-dep-without-ADR rule.
- **The rest** — JS delay/defer, font `display:swap` + Google-font self-hosting +
  preload, lazy-load + properly-sized images, self-host third-party/gravatars,
  bloat removal (block CSS, dashicons, emojis, jQuery-migrate, XML-RPC, RSS,
  oEmbeds, heartbeat control, revisions control), and **database cleanup**
  (revisions/auto-drafts/trashed/spam/expired-transients + `OPTIMIZE TABLE`) — are
  implemented with **standard WP hooks/filters and direct DB queries in the
  agent**, configured by the same per-site `site_perf_config` row. No third-party
  plugin code is copied (attribution: `apps/agent/NOTICE.md`).

### 3. RUCSS — **pure-Go on the control plane** (no headless Chrome)

The marquee decision. Remove-Unused-CSS runs **on the control plane in pure Go**,
not in a browser and not on the agent:

- **Engine:** `golang.org/x/net/html` (parse the page HTML) +
  `github.com/tdewolff/parse/v2/css` (tokenize each stylesheet) +
  `github.com/andybalholm/cascadia` (compile CSS selectors and test them against
  the parsed DOM). For each stylesheet, every rule's selector list is matched
  against the static DOM; rules with at least one matching (or always-kept)
  selector are emitted, the rest dropped. **No headless Chrome, no JS execution.**
- **No inline-script scan / no runtime evaluation.** We do not attempt to model
  classes added by JavaScript at runtime. Instead we lean on two safety rails:
  1. a **per-site safelist** (`css_rucss_include_selectors`) the operator extends
     when a dynamic widget loses styling, and
  2. **runtime-state pseudo-classes/elements are ALWAYS kept** — selectors
     involving `:hover`, `:focus`, `:active`, `:checked`, `:target`,
     `:focus-within`, `::before`, `::after`, `[aria-*]` state attributes, etc. are
     never dropped on the grounds that the static DOM didn't exercise them. At-rules
     that carry semantics independent of selector matching (`@font-face`,
     `@keyframes`, `@import`, `@charset`, `@media`/`@supports` wrappers) are
     preserved.
- **Cache by structure-hash.** Pages that share a structural signature (theme +
  template + body classes + the set of enqueued stylesheets) reuse one result.
  The key is a **structure-hash**; the computed used-CSS bytes are stored in
  **object storage** (`rucss_results.used_css_s3_key`) and only the metadata +
  reduction stats live in Postgres (`rucss_results`, `UNIQUE(site_id,
  structure_hash)`). A `rucss_jobs` (ULID) row tracks each compute's
  queued→running→done|failed lifecycle on a bounded River queue; the agent fetches
  the used CSS by hash from storage and inlines/serves it.
- **Why CP not agent:** the parse+match work is CPU-spiky and benefits from the
  shared structure-hash cache across a tenant's sites; keeping it on the CP avoids
  shipping a Go toolchain (or a browser) to every WordPress host and reuses the
  same presigned-storage transport as media/backups.

### 4. Graceful degradation — RUCSS/CP unreachable ⇒ serve full CSS, never block

RUCSS is a progressive enhancement. If the CP is unreachable, the structure-hash
has no cached result yet, or the compute job is still queued/failed, the agent
**serves the full, unmodified CSS** for that request and (optionally) enqueues a
compute for next time. **A missing or stale used-CSS result MUST NEVER block page
rendering or strip styles.** The same principle applies to minify/CDN: any
optimization step that cannot complete falls back to the original asset. This
mirrors the media optimizer's per-variant non-blocking failure model (ADR-043 §5).

### 5. CDN — rewrite asset URLs + emit cache-tag headers

When CDN is enabled the agent rewrites local asset URLs to the configured
`cdn_url` for the selected `cdn_file_types`, and emits cache-control headers so the
edge can be purged surgically:

- **`Cache-Tag`** (and the `CDN-Cache-Control` / `Cache-Control` pair) are set so a
  purge can target a tag (e.g. a post id or `all`) rather than enumerating URLs.
- On purge, the agent both clears its local disk cache and signals the CDN
  (tag-based purge where supported), recording the purge in `cache_purge_audit`.
- CDN credentials, when stored, are encrypted at rest in
  `site_perf_config.cdn_credentials_encrypted` (the CP holds ciphertext only,
  consistent with the SMTP/secret precedent).

### 6. Host quirks (the agent must detect and adapt)

Caching touches host-specific drop-in/.htaccess behaviour. The agent detects
`server_software` and adapts:

- **WP Engine / managed-Atomic hosts:** the platform owns `advanced-cache.php`;
  the agent writes its persistent drop-in payload under the
  **`Atomic_Persistent_Data`** drop-in filename convention instead of fighting the
  platform's file, and degrades to object-cache-only behaviour where the page cache
  is platform-managed.
- **OpenLiteSpeed / LiteSpeed:** strip our own **gzip** of the served HTML when the
  server already compresses (double-gzip corruption); let the server handle
  compression.
- **nginx:** nginx ignores `.htaccess`. The agent cannot self-install the serve
  rule, so it emits a **manual nginx snippet** in an admin notice (mirroring the
  media `.htaccess` installer's nginx-notice pattern) and **verifies** at runtime
  whether the static-file fast-path is actually taking effect, falling back to the
  PHP drop-in path when it isn't.

### 7. Conventions adopted from recon

- **Migration:** single file `20260603080000_m36_perf_suite.sql`, all
  `CREATE … IF NOT EXISTS` inside `DO $$ … $$`, **no `.up`/`.down`, no
  `set_updated_at` trigger** (`updated_at` set by repo code), `ENABLE` + `FORCE ROW
  LEVEL SECURITY` with a `tenant_isolation` policy
  (`current_setting('app.tenant_id', true)`) **and** an `app.agent` worker policy
  per table; grants inherited via default privileges. **No `_site_scope`
  RESTRICTIVE policy** — collaborator gating is done in-app via
  `authz.RequireSiteAccess(:siteId)` on the routes (the m23 precedent). The five
  tables are mirrored into `db/schema.sql` (the sqlc/Atlas declared end-state) and
  queried via `db/query/perf.sql` (sqlc).
- **RBAC:** four new site-level permissions in `authz/role.go` — `site.cache.manage`,
  `site.cache.purge`, `site.perf.config` at **operator+**, and the destructive
  `site.cache.delete-everything` at **admin+** (mirrors `PermMediaDeleteOriginals`).
  Not added to `orgLevelPerms` — a site-scoped collaborator can manage their site's
  cache.
- **Audit:** `site.cache.enabled` / `.disabled` / `.purged` / `.delete_everything`,
  `site.perf.config.updated`, `site.db.cleaned` action constants; the destructive
  delete-everything is recorded with `ActorUser` + actor id.
- **SSE:** new `perf.*` event types added to the shared tenant bus
  (`SITE_EVENT_TYPES` in `use-site-events.ts`), filtered by `site_id`.
- **API DTOs:** hand-rolled local DTO structs + `c.JSON` (like the `scan`/`media`
  features), not OpenAPI/ogen regen, until a stable endpoint is promoted.
- **Tests:** agent PHPUnit (Brain Monkey) for the cache drop-in/.htaccess/minify
  paths + CP Go testcontainers (Postgres) for RLS/repos and **golden-file** tests
  for the pure-Go RUCSS engine. A live-WordPress cache E2E container does **not**
  exist in the repo and is **deferred** (net-new infra).

## Consequences

- ✅ Control plane stays a lean `CGO_ENABLED=0` static binary; no browser, no
  encode/cache bytes on the CP; RUCSS reuses the proven presigned-storage transport.
- ✅ RUCSS is **fully open-source and self-hostable** — pure Go, no headless Chrome,
  no SaaS. The structure-hash cache amortizes compute across a tenant's pages.
- ✅ Page caching uses the conventional WP `WP_CACHE` drop-in mechanism every host
  understands; degradation is non-blocking everywhere (missing used-CSS ⇒ full CSS).
- ⚠️ Pure-DOM RUCSS cannot see JS-runtime-added classes; mitigated by the per-site
  safelist + always-keep runtime-state pseudos. Operators may need to extend the
  safelist for highly dynamic widgets — surfaced in the UI.
- ⚠️ Host-specific drop-in/.htaccess quirks (WP Engine Atomic, OpenLiteSpeed gzip,
  nginx no-.htaccess) require per-host detection + a verify step; nginx falls back
  to a manual snippet + the PHP fast-path.
- One new minor agent dependency (`matthiasmullie/minify` ^1.3, MIT) recorded here.

## Phased plan

See `PLAN.md` → **Phase 6 — Performance Suite** (6.1 Caching, 6.2 Optimization,
6.3 Pure-Go RUCSS, 6.4 Dashboard). Orchestration patterns (advanced-cache drop-in,
managed `.htaccess` block, structure-hash RUCSS cache, CDN cache-tag headers) are
implemented under WPMgr naming — **no third-party plugin code is copied**
(attribution: `apps/agent/NOTICE.md`).
