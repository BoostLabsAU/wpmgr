# Performance API — caching, optimization, RUCSS & Database Cleaner

Endpoints for the Performance Suite (M36 / ADR-046 + DB Cleaner P3 phases). Two
surfaces: operator-facing dashboard routes under
`/api/v1/sites/{siteId}/...` plus portfolio bulk routes under `/api/v1/cache/...`
and the tenant-level `/api/v1/perf/db/fleet-health` (session + RBAC), and
agent-callback routes under `/agent/v1/...` (Ed25519 signed-request).

Design: [ADR-046](../adr/ADR-046-performance-suite-architecture.md),
[ADR-047](../adr/ADR-047-hand-written-gin-routes-perf-suite.md).
User guides: [features/caching.md](../features/caching.md),
[features/optimization.md](../features/optimization.md),
[features/rucss.md](../features/rucss.md).
Architecture: [architecture/perf-suite.md](../architecture/perf-suite.md).

> **Routing note.** The cache, config, db-scan, db-clean, and RUCSS routes are
> declared in `packages/openapi/openapi.yaml` so the `@wpmgr/api` TypeScript client
> is generated for them. The five Database Cleaner routes added in Phase 3 (db/health,
> db/orphans, db/orphan-delete, db/table-action, perf/db/fleet-health) are
> **hand-written Gin only** and are documented here instead. See ADR-047 for the
> governance rule that determines which routes belong in the spec versus being
> hand-written.
>
> All perf routes use hand-rolled local DTO structs and `c.JSON` (not ogen-generated
> types). Source of truth: `apps/api/internal/perf/handler.go`,
> `.../agent_handler.go`, `.../model.go`, and the CP-to-agent contract
> `apps/api/internal/agentcmd/backup_contract.go`.

## Auth & RBAC

Every per-site route nests under `/sites/{siteId}/...` with
`RequireSiteAccess(:siteId)`, so site-scoped collaborators are gated on the
allowlist (belt-and-braces in front of the m36 RLS). Bulk routes check each
`site_id` against the principal's allowlist per item inside the handler.

| Route group | Permission | Min role |
|-------------|-----------|----------|
| `GET .../perf/config`, `PUT .../perf/config`, `POST .../rucss/clear`, `POST .../perf/rucss/compute`, `PUT /cache/bulk-config` | `site.perf.config` | operator |
| `GET .../cache/stats`, `GET .../rucss/results`, `GET .../perf/rum/summary`, `GET .../perf/rum/trend` | `site:read` | viewer |
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
  "rum_enabled": true,
  "rum_sample_rate": 0.1,
  "max_distinct_countries": 8,
  "min_sample_count": 30,
  "beacon_key_set": true,
  "rum_agent_beacon_key_set": true,
  "rum_agent_beacon_key_reported_at": "2026-06-03T10:11:13Z",
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

RUM fields use separate key-presence flags: `beacon_key_set` means the control
plane has a stored beacon-key hash, while `rum_agent_beacon_key_set` is nullable
agent-reported plaintext-key presence. `null` means an older or not-yet-confirmed
agent has not reported key status. `false` means the agent reported that its
local plaintext key is missing.

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

### POST /api/v1/sites/{siteId}/perf/db/clean

No body. Runs the cleanup scoped to the site's `db_*` config.

**Response** `200 OK`

```json
{ "ok": true, "detail": "db clean complete", "rows_cleaned": 1284 }
```

---

## Database Cleaner endpoints (hand-written Gin, Phase 3)

These five routes are implemented in `apps/api/internal/perf/handler.go` and are
documented here rather than in the OpenAPI spec. See ADR-047 for the governance
rule. The web layer calls them via the raw `client.get` / `client.post` transport
from `@wpmgr/api` (the same low-level HTTP client, without a typed wrapper).

### GET /api/v1/sites/{siteId}/perf/db/health

**Permission:** `site:read` (viewer+). **Auth:** session cookie or API key.

Returns the DB-size growth trend for the site. The `days` query parameter sets
the lookback window (default 90, clamped server-side to [7, 365]).

**Query parameters:**

| Name | Type | Default | Notes |
|------|------|---------|-------|
| `days` | integer | 90 | Lookback window in days. Server clamps to [7, 365]. |

**Response** `200 OK`

```json
{
  "points": [
    { "db_size_bytes": 41943040, "table_count": 27, "scanned_at": "2026-05-01T03:00:00Z" },
    { "db_size_bytes": 48234496, "table_count": 28, "scanned_at": "2026-06-01T03:00:00Z" }
  ],
  "growth_bytes": 6291456,
  "growth_pct": 15.0
}
```

`points` is ordered oldest-first. `growth_bytes` and `growth_pct` are derived
from `points[0]` versus `points[len-1]`; both are `0` when fewer than two points
exist. The frontend treats fewer than two points as an empty-state condition (no
chart line).

**Source:** `perf.Service.GetDBHealth` + `perf.DBHealthResponse` + `perf.DbSizeTrendPoint`.

---

### GET /api/v1/sites/{siteId}/perf/db/orphans

**Permission:** `site:read` (viewer+). **Auth:** session cookie or API key.

Classifies the orphaned artefacts stored in the latest `db_scan` result and
returns the structured report. Classification runs on-demand against the live
corpus so the report is always current relative to the corpus version. This
endpoint is read-only; it does not delete anything.

Returns `503 corpus_unwired` when the corpus reader is not configured (should
not occur in normal deployments). Returns `404 not_found` (or an empty-result
shape) when no scan has been run for the site yet.

**Response** `200 OK`

```json
{
  "options": [
    {
      "name": "my_plugin_option",
      "owner_slug": "my-plugin",
      "confidence": "exact",
      "known_plugins": ["my-plugin"],
      "installed": false,
      "deletable_eligible": true,
      "size_bytes": 1024,
      "autoload": false
    }
  ],
  "cron": [
    {
      "name": "my_plugin_cron_hook",
      "owner_slug": "my-plugin",
      "confidence": "prefix",
      "known_plugins": ["my-plugin"],
      "installed": false,
      "deletable_eligible": true,
      "next_run_at": 1748822400,
      "recurrence": "daily"
    }
  ],
  "tables": [
    {
      "name": "wp_my_plugin_data",
      "owner_slug": "my-plugin",
      "confidence": "exact",
      "known_plugins": ["my-plugin"],
      "installed": false,
      "deletable_eligible": true,
      "size_bytes": 204800,
      "rows": 512
    }
  ],
  "corpus_version": 42,
  "snapshot_available": true,
  "hidden_installed": 3,
  "counts": {
    "options": 1,
    "cron": 1,
    "tables": 1,
    "deletable": 3
  }
}
```

**Field notes:**

- `confidence` is one of `exact | prefix | heuristic | unknown`.
- `deletable_eligible` is `true` only when confidence is `exact` or `prefix`,
  `known_plugins` has exactly one entry, and `installed` is `false`.
- `snapshot_available` is `false` for scans from agents older than 0.16.0; when
  false, no item is `deletable_eligible` and the UI must prompt for a fresh scan.
- `hidden_installed` is the total count of candidates suppressed because their
  attributed plugin is present in the installed-plugins snapshot at scan time.

**Source:** `perf.Service.GetOrphansReport` + `perf.OrphansReport` + `perf.OrphanItem`.

---

### POST /api/v1/sites/{siteId}/perf/db/table-action

**Route-level permission:** `site.cache.manage` (operator+).
**Destructive actions (`drop`, `empty`) additionally require:** `site.cache.delete-everything` (admin+) — enforced inside the handler body.
**Auth:** session cookie or API key.

Dispatches a per-table DDL operation to the site's agent. The operation is
synchronous: the agent processes all tables sequentially and returns the
per-table result array in the ACK body.

Valid actions:

| Action | Destructive | Notes |
|--------|-------------|-------|
| `optimize` | no | `OPTIMIZE TABLE` — reclaims overhead (fragmentation). |
| `repair` | no | `REPAIR TABLE` — repairs corrupted MyISAM/ARIA tables. |
| `analyze` | no | `ANALYZE TABLE` — updates the optimizer's key-distribution stats. |
| `convert_innodb` | no | `ALTER TABLE … ENGINE=InnoDB` — converts MyISAM to InnoDB. |
| `drop` | **yes** | `DROP TABLE` — permanent; requires `confirm`. |
| `empty` | **yes** | `TRUNCATE TABLE` — deletes all rows; requires `confirm`. |

**Request body**

```json
{
  "action": "optimize",
  "tables": ["wp_postmeta", "wp_options"],
  "confirm": "DROP 2 TABLES"
}
```

`confirm` is required for `drop` and `empty`:
- Single table: must equal the table name exactly (e.g. `"wp_postmeta"`).
- Multiple tables: must equal `"DROP N TABLES"` or `"EMPTY N TABLES"` (uppercase,
  where N is the exact count).

`tables` must contain 1 to 200 entries. The agent independently validates that
each table exists and is not a WordPress core table.

**Response** `200 OK`

```json
{
  "ok": true,
  "job_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "action": "optimize",
  "results": [
    { "table": "wp_postmeta", "status": "done", "detail": "reclaimed 40960 bytes" },
    { "table": "wp_options",  "status": "done", "detail": "reclaimed 0 bytes" }
  ],
  "backup_warning": ""
}
```

Per-table `status` values: `done | skipped | error | not_found | rejected`.

An agent semantic rejection (HTTP 200, `ok: false`) is returned as-is:
`{ "ok": false, "detail": "…" }`.

When no recent backup (within 24 h) is found for destructive actions, the
`X-Backup-Warning` response header is set and `backup_warning` in the body is
non-empty (advisory only; the action still proceeds).

**Source:** `perf.Service.DBTableAction` + `perf.DBTableActionOutput` +
`agentcmd.DBTableActionTableResult`.

---

### POST /api/v1/sites/{siteId}/perf/db/orphan-delete

**Route-level permission:** `site.cache.manage` (operator+).
**Handler-body gate:** `site.cache.delete-everything` (admin+) — checked before re-classify.
**Auth:** session cookie or API key.

Destructive orphan deletion (P3.8). The CP re-classifies every requested item
against the live corpus before signing; items that are no longer
`deletable_eligible` or whose `owner_slug` drifted are silently dropped from the
signed command. The agent performs live re-verification of every item
independently.

The operation is asynchronous on the agent: the CP dispatches the command and
returns immediately. Per-item progress arrives via SSE events
(`db.orphan.delete.started`, `db.orphan.delete.progress`,
`db.orphan.delete.completed`, `db.orphan.delete.failed`) on the shared tenant bus.

**Request body**

```json
{
  "items": [
    { "kind": "option", "name": "my_plugin_option", "owner_slug": "my-plugin" },
    { "kind": "cron",   "name": "my_plugin_cron_hook", "owner_slug": "my-plugin" },
    { "kind": "table",  "name": "wp_my_plugin_data",   "owner_slug": "my-plugin" }
  ],
  "confirm": "DELETE 3 ORPHANS"
}
```

`kind` is one of `option | cron | table`. `owner_slug` must match the value
returned by the orphans endpoint exactly (used by the CP and agent for
re-verification).

`confirm` grammar (case-insensitive server-side; the client should send uppercase):
- 1 item: the artefact name itself (e.g. `"my_plugin_option"`).
- N items, same kind: `"DELETE N OPTIONS"` | `"DELETE N CRON"` | `"DELETE N TABLES"`.
- N items, mixed kinds: `"DELETE N ORPHANS"`.

**Response** `200 OK`

```json
{
  "ok": true,
  "job_id": "7e9f8d6c-1a2b-4c3d-8e4f-5a6b7c8d9e0f",
  "accepted_count": 3,
  "dropped_count": 0,
  "backup_warning": ""
}
```

`accepted_count` may be smaller than `items` length when the CP re-classify
filtered some items. When no recent backup is found, `X-Backup-Warning` is set
and `backup_warning` is non-empty (advisory; the action still proceeds).

An agent semantic rejection is returned as `{ "ok": false, "detail": "…" }`.

**Source:** `perf.Service.DBOrphanDelete` + `perf.OrphanDeleteOutput`.

---

## Real User Monitoring (RUM) endpoints

These routes are hand-written Gin (ADR-047 governance). The web layer calls them
via the raw `client.get` transport from `@wpmgr/api`. Source:
`apps/api/internal/perf/rum_results_handler.go`, `apps/api/internal/perf/dto.go`.

### POST /api/v1/sites/{siteId}/perf/rum/reprovision

**Permission:** `site.perf.config` (operator). **Auth:** session cookie or API key.

Repairs an enabled RUM site whose control plane has a beacon-key hash but the
agent has not confirmed a local plaintext key. The control plane rotates the
stored beacon-key hash, keeps the previous hash in the normal grace slot, pushes
the fresh plaintext key to the agent once, and returns the refreshed performance
config. The plaintext key is never returned by the operator API.

No body.

**Response** `200 OK`

```json
{
  "rum_enabled": true,
  "beacon_key_set": true,
  "rum_agent_beacon_key_set": false,
  "rum_agent_beacon_key_reported_at": null,
  "config_version": 8
}
```

If the post-store agent push fails, the config mutation remains saved and the
warning is returned in the **`X-Agent-Push-Warning`** response header. The UI
should refresh config and keep showing the pending or missing-key state until a
sync response or `/agent/v1/perf/config-ack` confirms
`rum_beacon_key_present: true`.

### GET /api/v1/sites/{siteId}/perf/rum/summary

**Permission:** `site:read` (viewer+). **Auth:** session cookie or API key.

Returns the p75 headline per metric for the site, with a PageSpeed-Insights-style
`distribution` breakdown (good / needs-improvement / poor) folded from the stored
CrUX-aligned 24-bucket histogram. Slices below `min_sample_count` return `null`
for `p75_ms` and omit `distribution` rather than surfacing a misleading number.

**Query parameters:**

| Name | Type | Default | Notes |
|------|------|---------|-------|
| `device` | string | `all` | Filter by `mobile`, `tablet`, `desktop`, or `all`. |
| `window_hours` | integer | 168 | Lookback window in hours (7 days). |

**Response** `200 OK`

```json
{
  "window_hours": 168,
  "min_sample_count": 30,
  "metrics": {
    "lcp": {
      "p75_ms": 2310,
      "rating": "good",
      "sample_count": 812,
      "distribution": {
        "good": 650,
        "needs_improvement": 120,
        "poor": 42,
        "good_pct": 80,
        "needs_improvement_pct": 15,
        "poor_pct": 5
      }
    },
    "inp": { "p75_ms": 180, "rating": "good", "sample_count": 810, "distribution": { "good": 720, "needs_improvement": 70, "poor": 20, "good_pct": 89, "needs_improvement_pct": 9, "poor_pct": 2 } },
    "cls": { "p75_ms": 45,  "rating": "good", "sample_count": 790, "distribution": { "good": 760, "needs_improvement": 20, "poor": 10, "good_pct": 96, "needs_improvement_pct": 3, "poor_pct": 1 } },
    "fcp": { "p75_ms": 1620, "rating": "good", "sample_count": 812, "distribution": { "good": 710, "needs_improvement": 80, "poor": 22, "good_pct": 87, "needs_improvement_pct": 10, "poor_pct": 3 } },
    "ttfb": { "p75_ms": 620, "rating": "good", "sample_count": 812, "distribution": { "good": 780, "needs_improvement": 22, "poor": 10, "good_pct": 96, "needs_improvement_pct": 3, "poor_pct": 1 } }
  }
}
```

**CLS note:** `p75_ms` for CLS is in milli-units (the stored value multiplied by 1000). The
web layer divides by 1000 for display. The good threshold is 100 milli-units (CLS 0.1) and
the needs-improvement threshold is 250 milli-units (CLS 0.25).

**Source:** `perf.Service.GetRumSummary` + `perf.RumSummaryResponse` +
`perf.RumMetricSummary` + `perf.RumDistribution`.

---

### GET /api/v1/sites/{siteId}/perf/rum/trend

**Permission:** `site:read` (viewer+). **Auth:** session cookie or API key.

Returns a per-metric daily p75 series over the requested window, built from
`rum_rollup_daily`. Used by the 28-day trend chart in the RUM dashboard (one
`ReferenceLine` at the good threshold, one at needs-improvement, matching
PageSpeed Insights presentation). Days below `min_sample_count` are suppressed
(`p75_ms: 0`, `suppressed: true`) so the chart renders gaps instead of
misleading zeros.

**Query parameters:**

| Name | Type | Default | Notes |
|------|------|---------|-------|
| `window_days` | integer | 28 | Lookback window in days. Server clamps to [7, 90]. |
| `device` | string | `all` | Filter by `mobile`, `tablet`, `desktop`, or `all`. |

**Response** `200 OK`

```json
{
  "window_days": 28,
  "min_sample_count": 30,
  "metrics": {
    "lcp": [
      { "day": "2026-05-13", "p75_ms": 2310, "sample_count": 812, "rating": "good", "suppressed": false },
      { "day": "2026-05-14", "p75_ms": 0,    "sample_count": 12,  "rating": "",     "suppressed": true  }
    ],
    "inp":  [ { "day": "2026-05-13", "p75_ms": 180,  "sample_count": 810, "rating": "good", "suppressed": false } ],
    "cls":  [ { "day": "2026-05-13", "p75_ms": 45,   "sample_count": 790, "rating": "good", "suppressed": false } ],
    "fcp":  [ { "day": "2026-05-13", "p75_ms": 1620, "sample_count": 812, "rating": "good", "suppressed": false } ],
    "ttfb": [ { "day": "2026-05-13", "p75_ms": 620,  "sample_count": 812, "rating": "good", "suppressed": false } ]
  }
}
```

**Source:** `perf.Service.GetRumTrend` + `perf.RumTrendResponse` + `perf.RumTrendPoint`.
`ComputeP75` in `apps/api/internal/rum/p75.go` is called once per day per metric.

---

## Portfolio / tenant-level endpoints

### GET /api/v1/perf/db/fleet-health

**Permission:** org-scope only (`RequireOrgScope`); `site:read` (viewer+).
**Auth:** session cookie or API key.
**Note:** site-scoped collaborators are blocked by the `RequireOrgScope` middleware.

Returns the tenant-level aggregate of database health across all sites that have
at least one completed scan. The `days` query parameter controls the growth
lookback window (default 90, clamped to [7, 365]).

**Query parameters:**

| Name | Type | Default | Notes |
|------|------|---------|-------|
| `days` | integer | 90 | Growth-lookback window in days. Server clamps to [7, 365]. |

**Response** `200 OK`

When no sites have been scanned yet, all numeric fields are `0` and `top_sites` is
an empty array (`total_sites_scanned === 0`); callers should render the empty-state
panel for this case. The endpoint always returns `200`; never `404`.

```json
{
  "total_sites_scanned": 5,
  "total_db_size_bytes": 314572800,
  "total_table_count": 142,
  "total_orphaned_options": 12,
  "total_orphaned_cron": 3,
  "sites_with_orphans": 2,
  "top_sites": [
    {
      "site_id": "6f1c2b7e-…",
      "site_name": "Main Store",
      "db_size_bytes": 104857600,
      "table_count": 38,
      "orphaned_options_count": 6,
      "orphaned_cron_count": 1,
      "scanned_at": "2026-06-03T03:00:00Z",
      "growth_bytes": 5242880
    }
  ]
}
```

`top_sites` contains at most 10 entries, ordered by `db_size_bytes` descending.
The orphan counts in this response are **raw scan counts** (unclassified by the
corpus); use the per-site `GET /perf/db/orphans` endpoint for the attributed,
deletable-eligible breakdown.

**Source:** `perf.Service.GetFleetDbHealth` + `perf.FleetDbHealth` + `perf.FleetSiteDbSummary`.

---

## RUCSS (Remove-Unused-CSS) endpoints

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
  "htaccess_managed": true,
  "rum_beacon_key_present": true
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
`htaccess_managed`, and optional `rum_beacon_key_present`, which the CP records
into `site_perf_config`. Older agents may omit `rum_beacon_key_present`; omission
means unknown and does not clear the previously recorded key status.

## SSE events

The perf service publishes on the shared tenant SSE bus
(`GET /api/v1/sites/events`, ADR-038), filtered by `site_id` client-side:
`cache.enabled`, `cache.disabled`, `cache.purge.started`, `cache.purge.completed`,
`cache.preload.started`, `cache.preload.progress`, `cache.preload.completed`,
`cache.stats.updated`, `perf.config.updated`, `db.clean.completed`,
`rucss.queued`, `rucss.computing`, `rucss.completed`, `rucss.failed`,
`rum.rollup_updated` (throttled aggregate frame, at most one per few seconds for
the currently-open site; the dashboard invalidates and refreshes the RUM p75 and
distribution panels on receipt).
