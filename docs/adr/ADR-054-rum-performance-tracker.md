# ADR-054 -- Real User Monitoring (Performance Tracker)

Status: Accepted (2026-06-09)

## Context

WPMgr's Performance Suite can tell an operator whether caching, Remove Unused
CSS, or font transcoding is configured, but it cannot tell them whether those
optimisations actually moved the needle for real visitors. Lab scores from a
local Lighthouse run measure one synthetic pageload on one machine; they do not
reflect the p75 experience of a real visitor on a mobile device over a 4G
connection. Closing that gap requires field data collected from real page loads.

The existing perf stack has no browser-instrumentation path. All data today flows
from the agent (PHP, server-side) to the control plane over a signed Ed25519
channel. Visitor browsers never talk to the control plane.

Two collector approaches were evaluated.

**Option A -- adopt `@elastic/apm-rum` wholesale.**
The Elastic browser agent (`@elastic/apm-rum`) is MIT-licensed and captures a
wide signal set natively (navigation timing, resource timing, XHR/fetch spans,
SPA transitions, Long Tasks, Core Web Vitals, JS errors, W3C traceparent). The
disqualifier is bundle size: the Elastic team's own enforced build budget is
around 16 KB gzipped. Injecting 16 KB of monitoring JavaScript on every front-end
page adds main-thread parse and execute cost to exactly the pages whose LCP and
INP the Performance Suite is designed to improve. Rejected as the default.

**Option B -- tiny first-party collector on Google `web-vitals`.**
A V1 collector built on the open-source `web-vitals` library (Apache-2.0,
around 2.0 to 2.5 KB gzipped) covers LCP, INP, CLS, FCP, and TTFB using
standard PerformanceObserver and Navigation Timing L2 APIs. The resulting IIFE
bundle, built with esbuild, is around 5 to 8 KB gzipped: roughly one third of the
Elastic agent for the V1 signal set. The schema is owned end to end and feeds
directly into Postgres with no protocol emulation.

**Option C -- hybrid (chosen).**
Ship option B as the default and only production front-end snippet. Keep option A
available as an opt-in compatibility mode for operators already standardised on
Elastic, pointing `@elastic/apm-rum` at the same Go ingest endpoint (pin
`apiVersion:2`). The compatibility mode never loads for users who did not request
it, so it costs zero page weight by default. V1 ships option B only; option A
compatibility is deferred.

## Decisions

### 1. Collector

The default front-end collector (`apps/tracker`) is built on `web-vitals`
(Apache-2.0). V1 scope is the five Core Web Vitals metrics (LCP, INP, CLS, FCP,
TTFB) plus page-load timing from Navigation Timing L2. Full navigation/resource
waterfall, XHR/fetch instrumentation, SPA route tracking, Long Tasks, and JS
errors are deferred to later phases. The bundle is MIT-licensed (a copyleft
snippet embedded in third-party sites is a non-starter); `web-vitals` is recorded
as Apache-2.0 in `THIRD-PARTY-NOTICES`.

Transport is `navigator.sendBeacon` with a JSON Blob. Flush on `visibilitychange`
to hidden plus `pagehide`; never `unload`/`beforeunload`. INP and CLS accumulators
reset on `pageshow.persisted` for bfcache correctness.

### 2. Beacon injection

The collector script, the per-site public beacon key, and the ingest endpoint URL
are written into cached HTML at cache-write time via a new gated stage in
`Optimizer::run()`, mirroring how `class-js-delay.php` injects the delay runtime
before the body-close tag. The key and endpoint are per-site constants baked in
at cache-write time. There is no per-request PHP, no per-visitor variance, and
no `Vary` or `Set-Cookie` on cached HTML. This is the only design that works on
a disk-cached page, and the only reason a public, non-secret key is viable:
nothing sensitive survives on a cached page, so the key is identification, not
authentication.

RUM is enabled per site via the existing perf-config push by adding a `rum_enabled`
boolean (plus `rum_sample_rate` and the per-site beacon key plaintext) to
`PerfConfig`, mirroring how `fonts_subset` was added in m55. The agent picks up
the flag on the next render via `PerfConfig::load` and acks via the existing
`POST /agent/v1/perf/config-ack`. No new agent verb is required.

### 3. Public beacon key trust model

The core invariant: **the beacon key is public and cheap to spoof, so RUM is an
untrusted per-site aggregate signal, never a security-relevant or
per-user-attributable record.**

The server resolves `tenant_id` and `site_id` from the beacon key alone, via a
unique index point lookup (`sha256(presented_key)` against
`site_perf_config.beacon_key_hash`). Nothing in the request body is trusted for
tenant or site resolution. A spoofed beacon that presents a legitimate key still
resolves to exactly one legitimate site and can only pollute that site's own
aggregate performance numbers: it cannot read data, cannot cross tenant boundaries,
cannot inject code, and cannot DoS the platform.

Key lifecycle:

- Storage: `beacon_key_hash bytea NOT NULL` in `site_perf_config` (m56). Only
  the SHA-256 hash is stored; the plaintext exists only in served cached HTML
  and transiently in the perf-config push response.
- Lookup index: `CREATE UNIQUE INDEX site_perf_config_beacon_key_hash_uniq ON
  site_perf_config (beacon_key_hash)` -- a unique constraint ensures one key
  resolves to exactly one site.
- Generation: key generated CP-side the first time RUM is enabled; plaintext
  returned to the agent in the perf-config push and baked into cached HTML.
- Rotation: overwrites `beacon_key_hash`; a grace-window second column
  (`beacon_key_hash_prev`) prevents a measurement gap while old cached pages
  age out. Rotation never touches the agent Ed25519 signing key.

### 4. Public ingest endpoint

Route: `POST /rum/ingest` registered on the root engine in its own isolated
group, outside both `/api/v1/*` (RequireAuth + RequireTenant) and
`/agent/v1/*` (Ed25519 AgentAuth). The route carries its own middleware chain
(no session, no signature verify, no shared tenant GUC).

Tenant resolution uses two narrow GUC scopes mirroring the existing
`InAPIKeyLookupTx` pattern:

1. `InRumIngestLookupTx` (GUC `app.rum_lookup`) -- SELECT-only. Resolves
   `sha256(presented_key)` to `(site_id, tenant_id, rum_enabled, rum_sample_rate)`
   before any tenant GUC is set.
2. `InRumIngestTx` (GUC `app.rum_ingest`) -- INSERT-only under the resolved
   tenant. Write happens in a separate scope so the lookup scope can never write
   and the write scope can never read another site's config.

Net-new middleware scoped to `/rum` only (neither exists elsewhere in the API
today): CORS, and a per-IP plus per-site token-bucket rate limiter. Rate limits
are sized as abuse ceilings only -- well above any plausible real-visitor beacon
rate -- so they never fire on legitimate traffic and never shape the stored
distribution. Server-side random sampling (re-applied at ingest regardless of any
client-side sample rate) is the only mechanism allowed to shape the distribution.

#### POST /rum/ingest -- beacon payload contract

This endpoint is intentionally not in `openapi.yaml` (it is a public, anonymous
browser-facing route, not an operator API). This ADR is the source-of-truth for
its contract.

**Request**

```
POST /rum/ingest
Content-Type: text/plain   (sendBeacon simple-request, no preflight)

{
  "key":     "<beacon-key-plaintext>",
  "url":     "<page path, query string stripped by client>",
  "metric":  "<metric name>",
  "value":   <number>,
  "device":  "<device class>",
  "country": "<ISO-2 code>",
  "conn":    "<connection type>"
}
```

One metric per beacon. Field allow-lists enforced server-side:

| Field    | Allowed values                                       | Notes                                               |
|----------|------------------------------------------------------|-----------------------------------------------------|
| `metric` | `lcp`, `inp`, `cls`, `fcp`, `ttfb`                   | Unknown values: 400                                 |
| `device` | `mobile`, `tablet`, `desktop`                        | Derived from UA server-side; client value advisory  |
| `conn`   | `4g`, `3g`, `2g`, `slow-2g`, `unknown`               | From `navigator.connection.effectiveType`           |
| `value`  | Non-negative number within metric-specific range     | CLS stored as milli-units (value x 1000)            |

Additional server-side enforcement:

- Body size cap: 4 KB. Larger bodies: 413.
- `key` is resolved to site/tenant via the `InRumIngestLookupTx` scope. Unknown
  key or `rum_enabled = false`: silent 204 (no information leakage).
- `url` is re-stripped of query string at ingest regardless of client-side strip.
  Path is normalised (numeric segments to `{id}`, UUIDs to `{uuid}`).
- Receive timestamp is server-assigned; body timestamps older than 5 minutes or
  in the future are rejected.
- `country` is resolved server-side from IP (offline DB-IP ASN lookup, same DB
  used for host-provider detection); client-supplied country is advisory. IP is
  discarded after the lookup; it is never written.
- Server-side random sampling is re-applied at ingest for the site's
  `rum_sample_rate`. The effective sample rate is persisted on every rollup row
  so counts can be scaled back to true volume at read time.

**Response**

```
HTTP/1.1 204 No Content
```

Always 204 for accepted or silently dropped requests. Validation errors return
400 or 413. The endpoint never returns 401, 403, or tenant-identifying error
bodies.

### 5. Storage backend -- Postgres default, ClickHouse at scale

Postgres is the default and the self-host floor. ClickHouse is an opt-in scale
tier, boot-selected by `WPMGR_CLICKHOUSE_ADDR`, using the identical pattern as
the existing `apps/api/internal/metrics` store (`metrics.New()` vs
`metrics.NewPostgres()`). Everything above the store (agent beacon, ingest
endpoint, dashboard) speaks the `RumStore` interface and is unaffected by which
backend is active.

**Postgres path.** Three tables in m56, using the m55 `font_results` RLS template
as a starting point but adapted for the different actor set:

- `rum_events_raw` -- 48h rolling drill-down buffer, RANGE-partitioned by day on
  `received_at`. Dropped by partition, never by DELETE.
- `rum_rollup_hourly` -- histogram-bucket rollup, PK
  `(site_id, url_pattern, metric, device, country, bucket_hour)`.
- `rum_rollup_daily` -- same shape, `bucket_day date`.

RLS policies on all three tables:

- `tenant_isolation` (`app.tenant_id`) -- dashboard read path. Kept from m55.
- `rum_ingest` (`app.rum_ingest`) -- INSERT-only, `WITH CHECK` only, no `USING`.
  The anonymous browser write path.
- `agent_access` -- **omitted**. The agent never reads or writes RUM data.
  Including it would be dead, never-exercised attack surface. This is a
  deliberate divergence from the m55 template.

p75 is computed at read time by linear interpolation across `bucket_counts int[]`
(CrUX-aligned fixed boundaries per metric). No `percentile_cont` over raw rows,
no `tdigest` extension, no per-query sort. p75 interpolation error is under 5
percent -- acceptable because Google itself reports field Core Web Vitals as p75
over distributions.

A config-driven `min_sample_count` floor enforced at read time at the displayed
grain: any slice whose scaled summed count is below the floor returns an explicit
"insufficient samples (N of M)" state rather than a p75. Default is around 100
for SaaS, around 30 for self-host.

Cardinality is bounded at ingest before any write:

- URL segments normalised (numeric/UUID/slug to templates).
- `device` and `conn` collapsed to allow-list values.
- `country` capped to a per-site top-N set (`max_distinct_countries`, default 8
  for SaaS, 1 for self-host), all other codes folded into `__other__`.
- Per-site `max_distinct_urls` cap; new patterns fold into `__other__` once
  exceeded.

**ClickHouse path.** A `rum_events` MergeTree (short TTL) feeding an
`AggregatingMergeTree` rollup with `quantilesTDigestState(0.75)` per dimension
bucket, finalized with `quantilesTDigestMerge(0.75)` at read time. Near-exact
p75 at high volume without the Postgres cardinality caps needing to be as tight.
No Elasticsearch dependency -- ClickHouse is the Go-native scale backend already
in `go.mod`.

### 6. Privacy posture

- IP: used transiently for rate-limiting and coarse country lookup, then
  discarded. Never stored.
- URL: query string stripped at client and re-stripped at ingest. Path only is
  stored, normalised to URL templates.
- No cookies, no localStorage, no cross-site identifier.
- The per-site toggle defaults OFF. The site owner enables it per site.
- Site owner is the data controller for visitor RUM data and must disclose it in
  their own site privacy policy.
- Self-host: all RUM data stays on the operator's own infrastructure.
- Hosted service: RUM data is processed on the site owner's behalf; it is not
  used to identify individual visitors.
- `docs/legal/privacy.md` is updated to: (a) name visitors as a new data subject,
  (b) name the site owner as their controller, (c) reconcile the "agent is the
  only transmitter" framing by marking RUM as the one browser-sourced, opt-in,
  off-by-default exception, and (d) describe exactly what the visitor's browser
  sends.

### 7. Dashboard and SSE liveness

Read endpoints `GET /perf/rum` and `GET /perf/rum/summary` are added in the
standard three-way lock-step: handler, `canonicalOperatorRoutes` contract test,
and `openapi.yaml` (operationIds `listRumResults`, `getRumSummary`), then SDK
regen.

Live updates use the existing Postgres LISTEN/NOTIFY SSE bus. The rollup River
worker emits a throttled `rum.rollup_updated` aggregate frame (at most one frame
every few seconds) for the currently-open site. The dashboard invalidates and
refreshes the p75 panel on that frame via TanStack Query invalidation -- the same
pattern as `font.*` frames in m55. Raw per-beacon streaming is explicitly out of
scope: it would be a telemetry firehose over SSE and would not carry additional
value over the throttled aggregate signal.

## Consequences

- The public `POST /rum/ingest` route is a new attack surface. A mandatory
  security-reviewer pass is required before ship. All controls (rate limits,
  sampling, allow-lists, country cap) are config-driven so self-hosters get the
  same protections as the hosted service.
- `web-vitals` (Apache-2.0) must be recorded in `THIRD-PARTY-NOTICES`.
- After adding the m56 columns, run `sqlc generate` (the prebuilt binary). Never
  hand-edit `internal/db/sqlc/*.sql.go` -- hand-syncing the m55 `fonts_subset`
  columns caused the v0.32.0 prod-down 500 (ADR-053 / hotfix v0.32.1).
- RUM data cannot be attributed to an individual visitor by design. Operators
  who need per-user session analytics should use a dedicated analytics tool.
- V1 covers anonymous cacheable pages only. Logged-in and non-cacheable page
  measurement via `wp_enqueue_script`/`wp_footer` is deferred.
- The later phases (full navigation/resource waterfall, SPA tracking, XHR/fetch
  spans, JS errors, Long Tasks, LCP attribution, the "optimization X moved LCP
  p75 from A to B" correlation panel, and opt-in Elastic-agent compatibility
  mode) are deferred and do not change this ADR's decisions.
