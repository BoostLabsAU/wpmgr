# Performance API — caching, optimization & RUCSS

Endpoints for the Performance Suite (M36 / Phase 6). Two surfaces: operator-facing
dashboard routes under `/api/v1/sites/{siteId}/...` plus portfolio bulk routes
under `/api/v1/cache/...` (session + RBAC), and agent-callback routes under
`/agent/v1/...` (Ed25519 signed-request).

Design: [ADR-046](../adr/ADR-046-performance-suite-architecture.md).
User guides: [features/caching.md](../features/caching.md),
[features/optimization.md](../features/optimization.md),
[features/rucss.md](../features/rucss.md).
Architecture: [architecture/perf-suite.md](../architecture/perf-suite.md).

> **Hand-rolled DTOs.** Per ADR-046 these routes hand-roll local DTO structs +
> `c.JSON` (the scan/media convention), **not** ogen-generated types. They are
> not in the OpenAPI spec and not exposed by the `@wpmgr/api` TS client. Source of
> truth: `apps/api/internal/perf/handler.go`, `.../agent_handler.go`, `.../dto.go`,
> and the CP→agent contract `apps/api/internal/agentcmd/cache_contract.go`.

## Auth & RBAC

Every per-site route nests under `/sites/{siteId}/...` with
`RequireSiteAccess(:siteId)`, so site-scoped collaborators are gated on the
allowlist (belt-and-braces in front of the m36 RLS). Bulk routes check each
`site_id` against the principal's allowlist per item inside the handler.

| Route group | Permission | Min role |
|-------------|-----------|----------|
| `GET .../perf/config`, `PUT .../perf/config`, `POST .../rucss/clear`, `POST .../perf/rucss/compute`, `PUT /cache/bulk-config` | `site.perf.config` | operator |
| `GET .../cache/stats`, `GET .../rucss/results` | `site:read` | viewer |
| `POST .../cache/purge`, `/cache/preload`, `POST /cache/bulk-purge` | `site.cache.purge` | operator |
| `POST .../cache/enable`, `/cache/disable`, `POST .../db/clean` | `site.cache.manage` | operator |
| `POST .../cache/purge` with `delete_everything: true` | `site.cache.delete-everything` | **admin** |
| `POST /agent/v1/...` | Ed25519 signed-request | tenant/site bound from the verified agent key |

The destructive delete-everything flavour is re-checked at the admin gate inside
the purge handler (the route itself allows the normal purge permission).

---

## Operator endpoints

### GET /api/v1/sites/{siteId}/perf/config

Returns the full per-site performance config. **CDN credentials are never
echoed** (`cdn_has_credentials` is the read-only presence flag); server/install
state is read-only (agent-reported).

**Response** `200 OK` (abridged — all toggles in `dto.go`)

```json
{
  "cache_enabled": true,
  "cache_logged_in": false,
  "cache_mobile": true,
  "cache_refresh": true,
  "cache_refresh_interval": "2hours",
  "cache_link_prefetch": true,
  "cache_bypass_urls": ["/cart", "/checkout"],
  "cache_bypass_cookies": ["woocommerce_items_in_cart"],
  "cache_include_queries": [],
  "cache_include_cookies": [],
  "css_js_minify": true,
  "css_rucss": true,
  "css_rucss_include_selectors": ["swiper-", "/^is-(open|active)$/"],
  "js_delay": true,
  "js_delay_method": "defer",
  "lazy_load": true,
  "properly_size_images": true,
  "cdn_enabled": false,
  "cdn_file_types": "all",
  "cdn_has_credentials": false,
  "db_auto_clean": false,
  "db_auto_clean_interval": "weekly",
  "server_software": "Apache/2.4",
  "dropin_installed": true,
  "wp_cache_constant_set": true,
  "htaccess_managed": true,
  "config_version": 7,
  "updated_at": "2026-06-03T10:11:12Z"
}
```

### PUT /api/v1/sites/{siteId}/perf/config

Saves the whole config. The CP validates, bumps `config_version`, persists, then
pushes the non-secret config to the agent. CDN credentials, when included, are
**write-only** and encrypted at rest.

**Request** (the config DTO above, plus the optional write-only block)

```json
{
  "cache_enabled": true,
  "css_js_minify": true,
  "css_rucss": true,
  "cdn_enabled": true,
  "cdn_url": "https://cdn.example.com",
  "cdn_provider": "cloudflare",
  "cdn_credentials": { "api_token": "…", "zone_id": "…" }
}
```

Validation: `cache_refresh_interval` ∈ `30min|1hour|2hours|6hours|12hours|daily|
weekly`; `js_delay_method` ∈ `defer|async|interaction`; `db_auto_clean_interval`
∈ `daily|weekly|monthly`; `cdn_file_types` ∈ `all|images|css_js`; `cdn_provider`
∈ `cloudflare|bunny|keycdn`; `cdn_url` required + valid `http(s)` when
`cdn_enabled`.

**Response** `200 OK` — the stored config (same shape as GET). If the agent push
fails after a successful store, the config is still saved and the warning is
returned in the **`X-Agent-Push-Warning`** response header (body is the stored
config).

### GET /api/v1/sites/{siteId}/cache/stats

**Response** `200 OK`

```json
{
  "cached_pages_count": 412,
  "cache_size_bytes": 38221004,
  "last_purged_at": "2026-06-03T09:00:00Z",
  "last_purge_kind": "auto",
  "last_preload_at": "2026-06-03T09:01:30Z",
  "preload_pending": 0,
  "preload_total": 412,
  "reported_at": "2026-06-03T10:00:00Z"
}
```

### POST /api/v1/sites/{siteId}/cache/purge

**Request**

```json
{ "scope": "all" }
```

```json
{ "scope": "url", "urls": ["https://blog.example.com/hello-world/"] }
```

```json
{ "scope": "all", "delete_everything": true }
```

`scope` ∈ `all | url`. `url` scope requires at least one URL. `delete_everything`
(admin only) wipes the whole cache directory.

**Response** `200 OK`

```json
{ "ok": true, "detail": "purged all", "purge_id": "1f2e3d4c-…" }
```

An agent rejection is surfaced as `200 { "ok": false, "detail": "…" }` (the
audit record is written before the agent call, so a failed purge stays
attributable).

### POST /api/v1/sites/{siteId}/cache/preload · /cache/enable · /cache/disable

No body. Each returns `200 { "ok": true, "detail": "…" }`.

```json
{ "ok": true, "detail": "cache enabled" }
```

### POST /api/v1/sites/{siteId}/db/clean

No body. Runs the cleanup scoped to the site's `db_*` config.

**Response** `200 OK`

```json
{ "ok": true, "detail": "db clean complete", "rows_cleaned": 1284 }
```

### GET /api/v1/sites/{siteId}/rucss/results

Query: `limit` (default 50, max 500), `offset`.

**Response** `200 OK`

```json
{
  "items": [
    {
      "id": "9a8b7c6d-…",
      "structure_hash": "3f1a…",
      "url": "https://blog.example.com/hello-world/",
      "original_css_bytes": 184320,
      "used_css_bytes": 21044,
      "reduction_pct": 88.58,
      "used_css_s3_key": "rucss/<tenant>/<site>/3f1a….css.gz",
      "last_used_at": "2026-06-03T10:00:00Z"
    }
  ]
}
```

### POST /api/v1/sites/{siteId}/rucss/clear

No body. Clears cached RUCSS results for the site (forces a recompute next
request).

**Response** `200 OK`

```json
{ "ok": true, "cleared": 14 }
```

### POST /api/v1/sites/{siteId}/perf/rucss/compute

Trigger on-demand RUCSS computation for one or more URLs. Permission:
`site.perf.config` (operator).

**Request**

```json
{ "urls": ["https://blog.example.com/hello-world/"] }
```

`urls` is optional. When omitted or empty, the home page is used. Each URL must
be on the site's own host (SSRF-guarded).

**Response** `202 Accepted`

```json
{
  "ok": true,
  "jobs": [
    { "job_id": "01J4QV…", "url": "https://blog.example.com/hello-world/", "status": "queued" }
  ]
}
```

**SSE events** — the job streams `rucss.*` events on the shared tenant bus
(`GET /api/v1/sites/events`, filter by `site_id`):

| Event | Payload |
|-------|---------|
| `rucss.queued` | `{"job_id":"…","url":"…"}` |
| `rucss.computing` | `{"job_id":"…","url":"…"}` |
| `rucss.completed` | `{"job_id":"…","url":"…","reduction_pct":88.6,"used_css_bytes":21044}` |
| `rucss.failed` | `{"job_id":"…","url":"…","reason":"…"}` |

---

## Portfolio bulk endpoints

### POST /api/v1/cache/bulk-purge

Purge every listed site (scope `all`). Each `site_id` is checked against the
principal's allowlist; a forbidden or invalid site is reported per item.

**Request**

```json
{ "site_ids": ["6f1c2b7e-…", "7a2d3e8f-…"] }
```

**Response** `200 OK`

```json
{
  "results": [
    { "site_id": "6f1c2b7e-…", "ok": true,  "detail": "purged all" },
    { "site_id": "7a2d3e8f-…", "ok": false, "detail": "forbidden" }
  ]
}
```

### PUT /api/v1/cache/bulk-config

Apply a preset across many sites without clobbering per-site CDN/cache include
lists (the preset toggles are merged onto each site's existing config).

**Request** — `preset` ∈ `safe | balanced | aggressive`

```json
{ "site_ids": ["6f1c2b7e-…"], "preset": "balanced" }
```

| Preset | cache | minify | RUCSS | JS delay | lazy-load |
|--------|:-----:|:------:|:-----:|:--------:|:---------:|
| `safe` | yes | yes | no | no | yes |
| `balanced` | yes | yes | yes | no | yes |
| `aggressive` | yes | yes | yes | yes | yes |

**Response** `200 OK`

```json
{
  "results": [
    { "site_id": "6f1c2b7e-…", "ok": true, "detail": "applied", "config_version": 8 }
  ]
}
```

---

## Agent endpoints (Ed25519 signed-request)

Tenant + site come from the verified agent key, never the body.

### POST /agent/v1/cache/stats-report

Cheap gauges for the dashboard (≤64 KiB).

```json
{
  "cached_pages_count": 412,
  "cache_size_bytes": 38221004,
  "last_purged_at": 1717405200,
  "last_purge_kind": "auto",
  "last_preload_at": 1717405290,
  "preload_pending": 0,
  "preload_total": 412
}
```

`200 { "ok": true }`.

### POST /agent/v1/perf/config-ack

Install-state report after applying a config (≤16 KiB).

```json
{
  "config_version": 7,
  "server_software": "Apache/2.4",
  "dropin_installed": true,
  "wp_cache_constant_set": true,
  "htaccess_managed": true
}
```

`200 { "ok": true }`. A missing config row is non-fatal (`{ "ok": true, "detail":
"no config row yet" }`).

### POST /agent/v1/rucss — RUCSS ingest (multipart)

The RUCSS round-trip. The agent POSTs the rendered page + its CSS; the CP returns
the used CSS (hit) or `202` (miss, agent serves full CSS).

**Request** — `Content-Type: multipart/form-data` with parts:

| Part | Type | Notes |
|------|------|-------|
| `meta` | application/json | `{"site_id","url","structure_hash","safelist"?}`. `site_id`, when present, **must** equal the authenticated site (else 403). `structure_hash` is **required**. |
| `html` | text/html | the rendered page (**required**, ≤10 MiB) |
| `css` | text/css | one or more parts; ≤5 MiB total (optional) |

Hard overall request ceiling 16 MiB; a part over its cap → `413`.

**Responses**

- `200 OK` — **cache hit.** Body is the used-CSS **content** (not a key); the
  agent inlines it directly, no object-storage access. Headers:
  `Content-Type: text/css`, `Content-Encoding: gzip` (the bytes are gzip),
  `X-Rucss-Reduction-Pct` (float, informational), `X-Rucss-Used-Bytes` (int,
  uncompressed used-CSS size).
- `202 Accepted` — **cache miss** (or RUCSS degraded/unavailable):
  `{"status":"processing","job_id":"…"}` or `{"status":"unavailable"}`. The agent
  serves **full CSS** this render and never blocks; the used CSS becomes available
  on a later request once the job runs.
- `401` — no verified agent identity.
- `403` — `meta.site_id` does not match the authenticated site.
- `413` — a part (or the whole request) exceeded its size limit.
- `422` — missing/invalid `meta` (no `structure_hash`) or missing `html` part.

---

## CP → agent commands

Each operator action above is dispatched to the agent as a signed
`POST {site_url}/wp-json/wpmgr/v1/command/{cmd}` with an EdDSA JWT
(`aud=siteId`, `cmd=<slug>`). Every command returns the `{ok, detail}` envelope;
an HTTP 200 with `ok=false` is a semantic failure carrying the agent's reason.
Contract: `apps/api/internal/agentcmd/cache_contract.go`.

| Command | Purpose |
|---------|---------|
| `perf_config_update` | mirror the non-secret per-site config locally (CDN credentials never travel here) |
| `cache_enable` / `cache_disable` | install/remove the drop-in + `.htaccess` block and toggle caching |
| `cache_purge` | purge cached pages (`scope: all \| url`) |
| `cache_preload` | start a background warm pass |
| `db_clean` | run the configured database cleanup |

`cache_enable` / `perf_config_update` results additionally report
`server_software`, `dropin_installed`, `wp_cache_constant_set`,
`htaccess_managed`, which the CP records into `site_perf_config`.

## SSE events

The perf service publishes on the shared tenant SSE bus
(`GET /api/v1/sites/events`, ADR-038), filtered by `site_id` client-side:
`cache.enabled`, `cache.disabled`, `cache.purge.started`, `cache.purge.completed`,
`cache.preload.started`, `cache.preload.progress`, `cache.preload.completed`,
`cache.stats.updated`, `perf.config.updated`, `db.clean.completed`,
`rucss.queued`, `rucss.computing`, `rucss.completed`, `rucss.failed`.
