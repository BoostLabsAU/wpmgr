# Real User Monitoring (RUM) / Performance Tracker: Research and Design

Status: Proposed (decision-grade research synthesis)
Date: 2026-06-09
Owner: Lead architect
Scope: WPMgr Performance Suite, the `apps/tracker` V1 build, a new public CP ingest path, RUM storage, and the operator dashboard.

This document synthesizes six research dimensions (FlyingPress teardown, Elastic APM RUM JS evaluation, the vendor-neutral web-vitals path, WPMgr codebase fit, public-ingest security/privacy, and storage/scale) into one buildable plan. It answers the user's two direct questions up front: is FlyingPress's approach proprietary or standard, and how do we build "track everything" bulletproof.

---

## 1. Executive summary and headline recommendation

The user wants to "track everything" like FlyingPress: page load, asset load, XHR/fetch, SPA navigations, user interactions, the full Core Web Vitals set, page info, network info, JS errors, distributed tracing, and per-resource breakdowns. The two framings the user offered were "use Elastic RUM JS" or "use the standard path."

Headline recommendation: build option (C), a hybrid, but weighted heavily toward the standard path. Ship a tiny first-party collector in `apps/tracker` built on the open-source `web-vitals` library plus raw browser timing APIs (around 5 to 8 KB gzipped), emitting a JSON payload whose field vocabulary is deliberately modeled on the documented Elastic RUM event schema. Keep the much larger `@elastic/apm-rum` agent (around 16 KB gzipped) as an opt-in compatibility mode only, never the default, because injecting 16 KB of monitoring JavaScript on every front-end page directly degrades the very LCP/INP/TBT metrics the Performance Suite sells.

The measurement layer is fully standard and freely reproducible. The genuine engineering work, and our differentiation, is entirely server-side and self-hosted: a public, anonymous, abuse-resistant CP ingest endpoint that derives tenant and site from a public beacon key (never from the request body), persists privacy-stripped events into a new RLS-protected Postgres table set, folds them into histogram-bucket rollups, and surfaces them in the operator dashboard. That is the part FlyingPress keeps closed in their cloud, and the part that lets us position on data residency: customer RUM data never leaves the operator's infrastructure.

Verdict in one line: FlyingPress's measurement is 100 percent standard tech with zero proprietary IP; the only thing they keep closed is the cloud aggregation, which is exactly the piece an open, self-hosted design should replace and own.

---

## 2. Is FlyingPress proprietary or standard? Definitive verdict

Short answer: standard. There is nothing in FlyingPress's Real User Monitoring worth avoiding on IP grounds, and nothing worth copying that is not already freely available.

### What FlyingPress actually ships

Their collector `assets/vitals.min.js` is a verbatim minified bundle of Google's open-source `web-vitals` library (the v5 attribution-less build). De-minified, every hallmark function is present and matches upstream: `onFCP`, `onCLS` (with the session-window CLS class doing the 1s-between / 5s-total windowing), `onINP` (with the 10-longest-interaction buffer and p98 selection), `onLCP`, `onTTFB`, plus `bindReporter`, `initMetric` generating `v5-` prefixed ids, bfcache handling, and the official rating thresholds baked in exactly as upstream (LCP 2500/4000, INP 200/500, CLS 0.1/0.25, FCP 1800/3000, TTFB 800/1800).

It collects exactly five metrics, each via the standard browser API: FCP and LCP and CLS and INP through `PerformanceObserver`, TTFB through Navigation Timing. It sends one beacon per pageview via `navigator.sendBeacon` to a hardcoded external host (`https://vitals.flyingpress.com`), as a JSON Blob, when at least five metrics have accumulated or on `visibilitychange` to hidden. The payload is `{ cls, fcp, inp, lcp, ttfb, site_id, domain, path, query_keys, device, connection, navigation }`, where `site_id` is `md5(host + license_key)` and query-string values are stripped (only param keys sent).

The WordPress plugin stores nothing locally: no custom table, no `wp_options` blob, no REST route for vitals. The admin dashboard (a React bundle) reads aggregated results straight back from the same cloud (`/{hostname}/summary` and `/{hostname}/data?device=&metric=&period=`). p75 rollups, per-URL/per-device/per-period slicing, retention, and percentile windows all live in their closed cloud and are unknowable from the plugin.

### Proprietary vs standard, component by component

| Component | Verdict |
| --- | --- |
| Measurement engine | STANDARD. Google `web-vitals` v5 plus standard browser timing APIs. Zero custom measurement IP. |
| Transport | STANDARD. `navigator.sendBeacon` with a JSON Blob, flush on hidden. |
| Payload envelope | STANDARD-ish and sensible (metric values plus path plus device plus connection plus navigation type plus query-param keys only). A neutral RUM envelope, nothing to attribute. |
| Rating thresholds | STANDARD. The official web-vitals constants, safe to reuse verbatim. |
| Back end (ingestion, p75 aggregation, query API, dashboard data) | FlyingPress-proprietary CLOUD service. Not in the plugin, not self-hosted. This is their only defensible piece. |

### What this means for us

The collector is trivially reproducible from the public library, so there is no reason to license, copy, or avoid anything. Our value-add must be the self-hosted back end FlyingPress keeps closed, plus two things they do not appear to do: route data to the operator's own control plane instead of a third-party cloud (data residency), and close the loop by correlating RUM field data with our existing perf actions (cache hit, RUCSS, WOFF2, image optimization) so the dashboard can show "optimization X moved LCP p75 from A to B."

Per house rules: in shipped code, comments, and docs we describe the technique neutrally (standard web-vitals plus PerformanceObserver) and do not name FlyingPress as a technique source. This section names them only because the user asked the direct comparison question.

---

## 3. Coverage table: the full "track everything" list mapped to standard APIs

Every item maps to a W3C or WHATWG standard browser API. The "web-vitals gives free" column marks what the library hands us correctly out of the box; everything else we hand-roll on raw APIs in `apps/tracker`.

| Tracked signal | Exact standard API | web-vitals gives free | We hand-roll |
| --- | --- | --- | --- |
| Page load metrics | Navigation Timing L2 via `PerformanceObserver({type:'navigation', buffered:true})` (requestStart, responseStart/End, domInteractive, domContentLoadedEventEnd, loadEventEnd, redirectCount, type) | TTFB only | Yes, the full navigation entry |
| Static-asset load (JS, CSS, images, fonts) | Resource Timing via `PerformanceObserver({type:'resource', buffered:true})` (initiatorType, transferSize, encoded/decodedBodySize, responseStatus, nextHopProtocol, full waterfall) | No | Yes |
| API requests (XHR + Fetch) | Resource Timing `resource` entries (initiatorType xmlhttprequest/fetch) for the passive waterfall, plus a thin `fetch` / `XMLHttpRequest` monkeypatch for app context and traceparent injection | No | Yes |
| SPA navigations (history API / route changes) | Wrap `history.pushState`/`replaceState` plus `popstate`; reset per-view INP/CLS accumulators on route change. No interoperable soft-navigation entry exists yet | No | Yes |
| User interactions (clicks that trigger network activity) | `PerformanceObserver({type:'event'})` and the click/fetch correlation in the monkeypatch | Partially (INP groups interactions) | Yes, the interaction-to-network correlation |
| Long Tasks | `PerformanceObserver({type:'longtask'})` (duration > 50 ms). Chromium only | No | Yes |
| FCP | `PerformanceObserver({type:'paint'})`, entry `first-contentful-paint` | Yes (`onFCP`) | No |
| LCP | `PerformanceObserver({type:'largest-contentful-paint'})` | Yes (`onLCP`) | No |
| INP | `PerformanceObserver({type:'event'})` grouped by interactionId | Yes (`onINP`) | No |
| FID (deprecated) | `PerformanceObserver({type:'first-input'})` | Removed in web-vitals v5 | Optional, only if needed for parity; recommend skip (INP supersedes it) |
| CLS | `PerformanceObserver({type:'layout-shift'})` with session windows. Chromium only | Yes (`onCLS`) | No |
| TTFB | Navigation Timing `responseStart - activationStart` | Yes (`onTTFB`) | No |
| Page information (URL visited, referrer) | `location.href`, `document.referrer`, navigation entry name (strip text-fragment and query string) | No | Yes |
| Network connection info (effectiveType, rtt, downlink) | `navigator.connection`. Chromium only, best-effort, feature-detect | No | Yes (and a custom field, not a standard RUM field anywhere) |
| JavaScript errors (error + unhandledrejection) | `window.addEventListener('error', ...)` and `'unhandledrejection'` | No | Yes |
| Distributed tracing | We inject a W3C Trace Context `traceparent` header on same-origin/allowlisted backend calls; Gin reads it and continues the trace. Cross-origin caveat below | No | Yes (same-origin/allowlisted only) |
| Breakdown metrics (time per resource/category) | Derived from the Resource Timing waterfall (DNS, TCP, TLS, TTFB, download per resource; grouped by initiatorType). LCP loading-phase breakdown from the web-vitals attribution build. Cross-origin TAO caveat below | LCP attribution sub-phases | Yes, per-resource and per-category sums (same-origin and TAO-permitted resources only) |

Two cross-origin browser limitations bound what the breakdown and tracing items can actually deliver, and both must be stated up front rather than discovered after these items are claimed as covered:

- Breakdown metrics and Timing-Allow-Origin (TAO). The Resource Timing waterfall only exposes the detailed phase timings (DNS, TCP, TLS, request, response, `transferSize`/`encodedBodySize`) for a cross-origin resource if that resource's server sends a `Timing-Allow-Origin` response header permitting our origin. Without it, the browser returns those fields as zero for every cross-origin resource. In practice the most interesting breakdown targets (third-party CDN assets, Google/Adobe fonts, analytics, embeds) are cross-origin and almost never send TAO, so their per-resource breakdown will be all-zero. The honest scope is therefore: full waterfall for same-origin and TAO-permitting resources; name-and-duration only (no sub-phase detail) for the rest. The dashboard must label cross-origin no-TAO resources as "timing not exposed by origin" rather than render them as instantaneous, and per-category sums must exclude or flag them so a wall of zeroes is not presented as fast loads.
- Distributed tracing and preflight. Injecting a `traceparent` header onto a cross-origin `fetch`/XHR makes it a non-simple request, which forces the browser to send a CORS preflight `OPTIONS` and requires the third-party server to allow the `traceparent` request header in `Access-Control-Allow-Headers`. Third-party backends will not, so the call either fails preflight or silently drops the header, and we have added a preflight round-trip cost to a call we cannot trace anyway. We therefore inject `traceparent` only on same-origin or explicitly operator-allowlisted backend origins (which we control and can configure to accept the header), never on arbitrary cross-origin calls. Same-origin requests stay simple and incur no preflight.

Library and licensing note: `web-vitals` is Apache License 2.0 (Copyright 2020 Google LLC), not MIT. Apache-2.0 is permissive and compatible with both an MIT-licensed tracker package and an AGPLv3 backend, but it carries NOTICE/attribution obligations that pure MIT does not. Record it correctly in `THIRD-PARTY-NOTICES` as Apache-2.0. The standard build is around 2.0 to 2.5 KB gzipped; the attribution build adds roughly 1.5 KB.

Browser-support reality that the dashboard must account for: FCP and TTFB are widely available across Chromium, Firefox, and Safari. LCP and INP (Event Timing) are Chromium and Firefox, and Safari only since Safari 26.2 (December 2025), so a large share of older iOS field traffic returns null LCP/INP. CLS, Long Tasks, and `navigator.connection` are Chromium only. The schema and p75 aggregation must treat these as missing-not-zero and segment by browser so Chromium-only metrics are not diluted.

---

## 4. Three-way build option comparison

### Option A: adopt `@elastic/apm-rum` wholesale, write a Go intake endpoint

The Elastic browser agent (`@elastic/apm-rum` plus `@elastic/apm-rum-core`) is MIT, actively maintained by Elastic (releases every few weeks), and captures around 10 of the 11 tracked signals natively: navigation/resource/paint/user timing, XHR and fetch spans, history-API SPA transactions, click user-interaction transactions, Long Tasks, the web vitals set, page URL and referrer, JS errors, W3C traceparent distributed tracing, and breakdown metrics. It requires an APM-Server-shaped ndjson intake, but `serverUrl`/`serverUrlPrefix` are configurable, so it will POST to a WPMgr-owned Go endpoint as long as that endpoint accepts the documented ndjson contract and returns 202. Writing that Go intake is medium effort (around 2 to 4 dev-days for a tolerant parser of the fields we care about; pin `apiVersion:2` to keep payloads uncompressed and parseable).

The disqualifier is bundle size. Elastic's own docs state the optimized bundle is around 16 KB gzipped and they enforce that as a build budget. On a product whose entire pitch is shaving kilobytes and improving LCP/INP, injecting around 16 KB of monitoring JavaScript on every front-end page is a self-own: it adds main-thread parse and execute cost and bytes to exactly the pages whose LCP/INP we optimize. License is fine (MIT); page weight is the problem.

Scoring (1 to 5): maintenance 5, performance 2, DX 4, ecosystem fit 3, license fit 5.

### Option B: hand-roll a tiny collector on Google web-vitals plus standard APIs

Build `apps/tracker` on `web-vitals` (around 2 KB) plus raw `PerformanceObserver`/Navigation Timing/Resource Timing, emitting our own JSON payload whose shape is modeled on the Elastic RUM event schema (transaction/span/experience{cls,fid,inp,lcp,tbt}/page{url,referer}/network). Total hand-rolled bundle covering the entire list lands around 5 to 8 KB gzipped, roughly one half to one third of the Elastic agent and around 8x smaller than option A for the vitals-only core. We own the schema end to end, feed it straight into ClickHouse-free Postgres and our React dashboard with zero protocol emulation, and there is no Elastic-stack dependency.

The cost is that we implement SPA, interaction, error, and breakdown logic ourselves (more code than a drop-in), and web-vitals coverage is Chromium-skewed (a browser reality that affects both options equally).

Scoring (1 to 5): maintenance 4, performance 5, DX 3, ecosystem fit 5, license fit 5.

### Option C: hybrid (recommended)

Ship option B as the default and only front-end snippet, and keep option A available as an opt-in "advanced/compatibility" mode behind a thin adapter for a customer already standardized on Elastic, pointing `@elastic/apm-rum` at the same Go ingest endpoint (pin `apiVersion:2`). The Go endpoint accepts both our slim native schema and a tolerant subset of the Elastic ndjson intake, reusing Elastic's documented field names as the canonical wire vocabulary so we never invent a bespoke contract. The compatibility mode never loads for users who did not ask for it, so it costs zero page weight by default while giving us a portable, future-proof schema and an interop story.

### Recommendation

Build option C, default-to-B. Unambiguously: the default front-end collector is the tiny first-party web-vitals-based snippet (around 5 to 8 KB), and the Elastic agent is opt-in only. This keeps page weight minimal (protecting the metric we sell), gives us full schema ownership, avoids an Elastic-stack runtime dependency, and still offers Elastic-agent interop for customers who want it. "Bulletproof/ownership" favors B/C decisively because the entire value and risk surface is the server-side ingest and storage, which we own outright either way, while the collector stays swappable behind a thin interface.

| Option | Maint | Perf | DX | Ecosystem | License | Bundle (gz) | Backend coupling |
| --- | --- | --- | --- | --- | --- | --- | --- |
| A Elastic wholesale | 5 | 2 | 4 | 3 | 5 (MIT) | ~16 KB | Emulate Elastic ndjson intake |
| B Tiny custom | 4 | 5 | 3 | 5 | 5 (MIT pkg, Apache-2.0 lib) | ~5 to 8 KB | Own slim schema, zero emulation |
| C Hybrid (default B) | 4 | 5 | 4 | 5 | 5 | ~5 to 8 KB default, opt-in 16 KB | Own schema plus tolerant Elastic subset |

---

## 5. Architecture for the recommended approach

Four layers, mapping cleanly onto WPMgr's existing perf conventions for everything except the ingest principal (covered in section 6).

### 5.1 Agent beacon injection

The beacon JavaScript, the per-site public beacon key, and the ingest endpoint URL are written into the HTML at cache-write time as a per-site constant, mirroring how the existing JS-delay runtime is injected before the body-close tag (`apps/agent/includes/optimizer/class-js-delay.php` `injectRuntime`, around lines 166 to 197) and how speculation rules inject before head-close (`apps/agent/includes/optimizer/class-speculation-rules.php`). Concretely:

- Add a new gated stage to `Optimizer::run()` (`apps/agent/includes/optimizer/class-optimizer.php`, lines 97 to 214), wrapped in the existing `stage()` Throwable-guard so a beacon failure can never break the page. It injects the external `src` script tag plus the per-site key and endpoint before the body-close tag.
- The optimizer skips logged-in users and only runs on cacheable anonymous renders (line 109). If V1 must also measure logged-in or non-cacheable pages, add a parallel `wp_enqueue_script`/`wp_footer` path. Recommend V1 covers anonymous cacheable pages only and treats logged-in measurement as a later item.
- The collector is built from `apps/tracker` (currently a true stub: `src/index.ts` and `src/vitals.ts` are empty exports, `package.json` is `@wpmgr/tracker` MIT 0.0.0 with no-op scripts). The build implements `vitals.ts` on `web-vitals`, wires a real esbuild IIFE, and ships the minified artifact into the agent assets directory alongside the existing `assets/wpmgr-delay.min.js`. Keep the package MIT (a copyleft snippet embedded in third-party sites is a non-starter).

Enabling RUM for a site reuses the existing perf-config push with zero new agent verb. Add a `rumEnabled` boolean (plus `rumSampleRate`, the ingest endpoint URL, and the per-site beacon-key plaintext) to `PerfConfig` exactly like `fontsSubset` was added in m55: coerce in the constructor, add to `toArray`, include in `anyHtmlTransformEnabled()` so the pipeline runs when only RUM is on (`apps/agent/includes/optimizer/class-perf-config.php`, around lines 225 to 344), mirror the CP-side `Config` in `apps/api/internal/perf/model.go`, the DTO, the repo `UpsertConfig` SELECT/RETURNING, and the `site_perf_config` migration columns (`rum_enabled`, `rum_sample_rate`, plus the section 5.1a `beacon_key_hash` and the lookup index). The beacon-key plaintext is delivered to the agent only in the push payload (the CP stores only its hash, section 5.1a); the agent bakes it into cached HTML. The agent picks up the flag on next render via `PerfConfig::load` and acks via the existing `POST /agent/v1/perf/config-ack`.

Critical: after adding the column, run `sqlc generate` (the prebuilt binary) and never hand-edit `internal/db/sqlc/*.sql.go`. Hand-syncing the m55 `fonts_subset` columns is exactly what caused the v0.32.0 to 0.32.1 prod-down 500 (`GetPerfConfig`/`UpsertPerfConfig` drifted from `Scan`).

### 5.1a Beacon-key lifecycle (V1 deliverable, the security boundary)

The whole design rests on the server resolving tenant and site from a public beacon key, so the key is a V1 deliverable with a concrete schema, a defined lookup path, and generation/rotation, not an open question. (Open decision 1 in section 9 is only the policy default that consumes this; the mechanism below is fixed for V1.)

- Storage. Add a single column `beacon_key_hash bytea NOT NULL` to `site_perf_config` in the m56 migration (one beacon key per site, co-located with the rest of the per-site perf config; no separate join table is needed for V1). The key itself is a 128-bit (16-byte) random value, base32-encoded for embedding in the served HTML. We store only its hash, never the plaintext: `beacon_key_hash = sha256(beacon_key)`. SHA-256 (not bcrypt/argon2) is correct here because the key is high-entropy random, so there is no dictionary to defend against and the lookup must be a single fast indexed probe per beacon. The plaintext exists only twice: in the served cached HTML (by design, it is public identification) and transiently in the generate/rotate response that the agent bakes into the page. It is never persisted plaintext on the CP.
- Lookup index. A unique index `CREATE UNIQUE INDEX site_perf_config_beacon_key_hash_uniq ON site_perf_config (beacon_key_hash)` makes key-to-site resolution an indexed point lookup. The beacon presents the plaintext key; the handler computes `sha256(presented_key)` and probes this index. A unique constraint also guarantees one key resolves to exactly one site, which is what makes a spoofed key still land on exactly one legitimate site.
- RLS-exempt lookup scope. The resolution runs in its own narrow scope `InRumIngestLookupTx` (GUC `app.rum_lookup`), mirroring `InAPIKeyLookupTx` exactly: it enables a single SELECT-only policy `site_perf_config_rum_lookup` on `site_perf_config` (`USING (current_setting('app.rum_lookup', true) = 'on')`) that returns only `(site_id, tenant_id, rum_enabled, rum_sample_rate)` for the matched hash, and it runs BEFORE any tenant GUC is set. This is the one place a beacon's site/tenant may be read before its tenant is known, exactly as `InAPIKeyLookupTx` is for API keys. The subsequent write happens in a separate `InRumIngestTx` (GUC `app.rum_ingest`, section 5.2), so the lookup scope can never also write and the write scope can never read another site's config.
- Generation. A beacon key is generated CP-side the first time RUM is enabled for a site (and lazily for any site missing one). Generation writes the hash to `beacon_key_hash` and returns the plaintext to the agent in the perf-config push response so it can be baked into cached HTML; the plaintext is not stored.
- Rotation. Rotation overwrites `beacon_key_hash` with a fresh key's hash and returns the new plaintext on the next perf-config push, which the agent re-bakes at the next cache write. Old cached pages still carry the previous key until their cache entry is rewritten; to avoid a measurement gap, accept the previous hash for a short grace window via an optional second column `beacon_key_hash_prev bytea` carried in the same unique-lookup index strategy (a partial second unique index, or a two-row lookup), cleared after the grace window. Rotation never touches the agent Ed25519 signing key, so a leaked or abused beacon key is a one-site, one-rotate event.

### 5.2 Public CP ingest endpoint

This is the one genuinely novel piece (section 6 details the security model). The browser beacon is an anonymous, cross-origin, unauthenticated end-user POST with none of the three principals the codebase recognizes (operator JWT, agent Ed25519 signature, or a secret bootstrap token). A new route must mount on the root engine outside both `/api/v1/*` (RequireAuth plus RequireTenant) and `/agent/v1/*` (Ed25519 AgentAuth), with its own middleware chain (no session, no signature verify, no shared tenant GUC).

- Route: a new `POST /rum/ingest` (or `/beacon`) registered via a `RegisterPublic`-style hook on the root engine, alongside the existing `SiteH.RegisterPublic`/`InvitationH.RegisterPublic` (`apps/api/internal/server/server.go`, around lines 186 to 259), but in its own isolated group.
- Tenant resolution: two narrow GUC scopes, both mirroring the existing `InEnrollTx`/`InAgentTx`/`InAPIKeyLookupTx` pattern in `apps/api/internal/db/db.go` (lines 217 to 286). First `InRumIngestLookupTx` (GUC `app.rum_lookup`, section 5.1a) does a SELECT-only point lookup of `sha256(presented_key)` against the `beacon_key_hash` unique index to resolve the public beacon key to site and tenant, before any tenant scope. Then `InRumIngestTx` (GUC `app.rum_ingest`) writes the event under an INSERT-only RLS policy with the resolved `site_id`/`tenant_id`. Splitting lookup from write keeps each scope minimal: the lookup can only read one column set, the write can only insert. Unlike enroll (which validates a code hash), the beacon has no secret of its own beyond the public key, so the lookup-plus-INSERT pair is the security boundary.
- Net-new middleware: CORS and a rate limiter. Neither exists anywhere in the API today (a grep for cors/access-control/ratelimit/limiter/throttle across `internal/middleware` and `internal/server` returns zero hits; the global chain is only RequestID, otel, Logger, Recovery, Sessions, Authenticate). Both must be scoped only to the `/rum` route.

### 5.3 Storage

A new m56 migration creates the RUM tables adapting (not copying) the m55 `font_results` RLS template (`apps/api/migrations/20260618000000_m55_font_results.sql`): denormalized `tenant_id`, FK to tenants and sites ON DELETE CASCADE, ENABLE plus FORCE ROW LEVEL SECURITY, and a `tenant_isolation` policy (`USING`/`WITH CHECK tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid`) for the dashboard read path. The actor set differs from m55, so the policy set differs: ADD an INSERT-only `rum_ingest` policy (`WITH CHECK` only, no `USING`) keyed on `app.rum_ingest` for the anonymous browser write path, and OMIT m55's `agent_access` policy entirely, because the agent never touches RUM data (the browser beacon writes it, the dashboard reads it), so an `agent_access` policy here would be dead, never-exercised attack surface. Unlike one-row-per-font, RUM needs a short-retention raw table plus periodic rollup tables (section 7). Aggregation reuses the existing River worker pattern (the GC/rollup workers in `apps/api/internal/perf/worker.go`, registered in `cmd/wpmgr/main.go`).

### 5.4 Operator dashboard

Conventional, mirroring m55. Add the read endpoint in three-way lock-step or the build fails:

- `apps/api/internal/perf/handler.go`: `g.GET("/perf/rum", authz.RequirePermission(authz.PermSiteRead), h.rumResults)` (and likely `/perf/rum/summary` for aggregated CWV percentiles), modeled on the `/perf/fonts` line at 120 and `/perf/rucss/results` at 115.
- `apps/api/internal/perf/routes_contract_test.go`: add the new path to `canonicalOperatorRoutes` (the fonts entry is line 63). The contract test requires `openapi.yaml`, `perf-paths.ts`, and this list to stay in lock-step.
- `packages/openapi/openapi.yaml`: add the path with `operationId listRumResults` plus the `RumResult`/`RumResultSummary` schemas (copy from the fonts entries), then regenerate the SDK.
- Read seam: a `RumResultsReader` struct with a `List` method wired via a `SetRumResultsReader` in `cmd/wpmgr/main.go`, repo method under `InTenantTx`.
- Web: `RumResultsTable.tsx` mirroring `apps/web/src/features/perf/optimize/FontResultsTable.tsx` and `RucssResultsTable.tsx`; `useRumResults.ts` mirroring `apps/web/src/features/perf/hooks/useFontResults.ts` (TanStack `useQuery` to the generated `listRumResults`, a `perfKeys.rum` query key). Fill the `apps/web/src/routes/_authed/performance.tsx` placeholder (which today renders a "fleet-wide Core Web Vitals, TTFB, and load trends are planned" Construction card) with a fleet CWV panel beside the existing DB-health panel. Dashboard liveness uses SSE in V1, consistent with the app-wide pattern (connection-state, backup progress, and the perf `font.*`/`rucss.*` frames all stream over the existing per-site event bus in `apps/api/internal/site/connection.go`). The one rule that keeps it volume-safe: RUM does NOT stream raw per-beacon events (that would be a firehose at telemetry volume). Instead the rollup worker emits a THROTTLED AGGREGATE frame, `rum.rollup_updated` for the currently-open site, coalesced to at most one frame every few seconds, on the same Postgres LISTEN/NOTIFY bus; the dashboard invalidates/refreshes the p75 panel on that frame (TanStack query invalidation triggered by the SSE event, the same way `usePerfEvents.ts` reacts to `font.*`). Add a `rum.*` event family to `connection.go` and to `SITE_EVENT_TYPES`/`usePerfEvents.ts` exactly as `font.*` was added in m55. So: SSE-driven liveness like the rest of the app, but the frame carries an aggregate-changed signal, not raw beacons.
- Read-path honesty rules, both enforced server-side and reflected in the UI: (a) the `min_sample_count` floor (section 7) means any slice below the floor renders "insufficient samples (N of M)" instead of a p75, so the dashboard never sells noise as a metric; (b) cross-origin resources without `Timing-Allow-Origin` (section 3) are labeled "timing not exposed by origin" in any breakdown view rather than shown as zero-duration, and per-category sums exclude or flag them.

---

## 6. The bulletproof section: public-ingest security and privacy/GDPR

The public RUM endpoint is categorically different from every other WPMgr endpoint. The industry has converged on one hard truth: there is no secret you can put in a browser, so the client-side key is identification, not authentication (Sentry's public DSN, Plausible's data-domain, Elastic APM's anonymous auth all agree; GA4's unvalidated Measurement Protocol is the cautionary tale of ghost-spam with no fix). "Bulletproof" therefore does not mean making spoofing impossible (it is not); it means making spoofing worthless and the blast radius zero. A spoofed beacon can only ever pollute one site's own aggregate perf numbers: it can never read data, never cross tenants, never inject code, never DoS the platform.

Prioritized checklist.

### P0 structural (these make spoofing worthless)

1. Server derives `tenant_id` and `site_id` from a per-site public beacon key looked up in a lookup table, never from the request body. Mirror `InAgentTx`/`InAPIKeyLookupTx`: a narrow lookup tx resolves key to site to tenant, then the write happens under the new `app.rum_ingest` GUC. Any `site`/`domain`/`tenant` field in the body is advisory only, used at most as an integrity tripwire (reject or flag if it disagrees with the key-resolved site).
2. New RUM tables with ENABLE plus FORCE ROW LEVEL SECURITY; a `tenant_isolation` policy for the dashboard read path and an INSERT-only `rum_ingest` policy (`WITH CHECK` pinning `tenant_id`/`site_id` to the resolved key, no `USING`) for the anonymous write path. Adapt the m55 template, do NOT copy it verbatim: keep `tenant_isolation`, add `rum_ingest`, and OMIT m55's `agent_access` policy (the agent never reads or writes RUM data, so that policy would be dead attack surface). This makes cross-tenant write structurally impossible even with a spoofed key, because a spoofed key still resolves to exactly one legitimate site.
3. Mount the ingest route in a separate root-engine group outside `/api/v1` and `/auth`, with its own middleware chain (no signature verify, no session, no shared tenant GUC). Write-only: never expose a SELECT on this path. Accidentally hanging it under the authenticated tree, or running it inside a tenant-GUC transaction, is the single most likely way to leak.

### P0 privacy (hard to retrofit, design in from the first write)

4. Never store the full client IP. Use it for rate-limiting and optional coarse country/ASN, then discard. If per-visitor dedup is ever needed, use a rotating daily-salted, non-reversible `hash(IP + UA + site)` and do not persist the raw inputs.
5. Strip query strings from the URL and the Referer before storage; store path and host only (query strings carry tokens, emails, order IDs).
6. No cookies, no localStorage, no cross-site identifier. A stateless beacon is what keeps RUM consent-light under GDPR/ePrivacy.
7. Per-site toggle off by default (like `fonts_subset`), plus a consent/opt-out/Do-Not-Track hook so consent-managed sites can gate injection.

### P1 abuse and DoS (net-new middleware on the beacon route only)

Sampling and rate-limiting are two different mechanisms with two different jobs and they must never be conflated. Random sampling (item 13) is the statistically-unbiased mechanism that shapes the stored distribution; it drops beacons with a fixed per-site probability independent of which page, IP, or time they came from, so the surviving sample is a faithful scale model of real traffic. Rate limits (items 8 and 9) are an abuse ceiling only: their thresholds are set high enough that legitimate visitor traffic never reaches them, so on a real site they never fire and therefore never touch the distribution. The failure to avoid is letting a rate limit shed the busiest pages, because that preferentially drops exactly the high-traffic URLs whose p75 the product sells, biasing the metric toward quiet pages. Hence: shape the distribution with random sampling, bound abuse with rate limits sized to never fire on real traffic.

8. Per-IP token-bucket rate limit returning 429. Abuse ceiling only: size the bucket far above any plausible single-visitor beacon rate (a real visitor emits roughly one beacon set per pageview), so a legitimate human never hits it and the limit never shapes the distribution.
9. Per-site events-per-minute and events-per-day budget returning 429 or silent drop, sized as a runaway/spoof-volume ceiling, not a sampler. Set it well above the site's expected post-sampling beacon volume so it never fires on legitimate traffic. If a high-traffic site approaches it, lower that site's `sample_rate` (item 13) rather than letting the per-site limit clip beacons, because the limit clips by arrival order (favoring whichever pages beacon first) and would bias p75, whereas lowering the random sample rate shrinks volume without bias.
10. Body size cap (around 4 to 8 KB) returning 413; max events per beacon (around 10).
11. Strict schema and scope validation: a fixed allow-list of metric names (LCP, INP, CLS, FCP, TTFB, navigation timings) and numeric ranges; drop unknown fields so the body cannot smuggle arbitrary keys into storage.
12. Server-assigned receive timestamp; reject body timestamps in the future or older than a few minutes (a spoofer cannot backfill or forward-fill history).
13. Authoritative, statistically-unbiased server-side random sampling per site to bound stored volume (a spoofer can ignore a client sampleRate; the server re-applies the same decision so the kept sample is uniform random over arriving beacons). This is the only mechanism allowed to shape the stored distribution. Persist the effective `sample_rate` alongside the rollup so counts can be scaled back up to true volume at read time (a stored count of N at sample_rate s estimates N/s real events); never display raw post-sampled counts as if they were full traffic.

### P1 origin and bot (cheap speed-bumps, explicitly not security boundaries)

14. Validate `Origin` (fallback `Referer` host) against the resolved site's known hostnames from `sites.url` plus aliases; on mismatch reject that beacon (silent 202/204 or 403) or flag "unverified origin." Never 500, never block the platform. Origin/Referer are browser-supplied and trivially forged, so this stops casual reuse and honest misconfiguration only.
15. CORS reflects only the site origin, or design the beacon as a CORS-simple request (`sendBeacon` with `text/plain` or form-encoded) to dodge the preflight entirely. Add an OPTIONS handler only if a JSON content-type is required.
16. Drop known-bot/crawler/headless User-Agents at ingest (synthetic agents have unrepresentative timings that pollute CWV); optionally down-weight datacenter/cloud egress IPs, reusing the existing offline DB-IP ASN database already shipped for host-provider detection.

### P2 cache and CSP compatibility (so it actually works on real cached sites)

17. Inject the beacon JS plus the per-site key plus the endpoint URL at cache-write time as a per-site constant (reuse the JS-delay inject-before-body-close model). The served-from-disk cached HTML must already contain everything: no per-request PHP, no per-visitor variance, no `Vary`, no `Set-Cookie`. This is exactly why a public, non-secret key is the only option (CSRF tokens and nonces cannot survive page caching).
18. `navigator.sendBeacon` is governed by the CSP `connect-src` directive and the bootstrap by `script-src` (the Matomo v4 CSP-breakage precedent: switching from an img beacon to sendBeacon broke CSP sites). Load the beacon as an external `src` script (not a large inline block) so strict no-unsafe-inline CSP sites are not broken, or support a CSP nonce passthrough. Detect a conflicting strict CSP and skip injection rather than break the page, using the agent's existing conflict-detect map. Document the exact `connect-src` and `script-src` entries operators must add, with a single stable ingest host.

### Disclosure (definition-of-done gate)

19. Amend `docs/legal/privacy.md` itself (not just the agent README), because RUM is a genuinely new data flow that the current policy does not cover and in fact contradicts. Today privacy.md frames the agent as the sole transmitter ("what the agent sends, and only to your control plane"); RUM transmits from the site visitor's browser directly to the control plane, so the policy must be reconciled. Required privacy.md edits: (a) add the new data subject, the site's visitors; (b) state the site owner is the data controller for visitor RUM data and must disclose RUM in their own site privacy policy; (c) add a forward note on the "agent is the only transmitter" framing marking RUM as the one browser-sourced, opt-in, off-by-default exception; (d) describe what the visitor's browser sends (anonymous Web Vitals plus timings; page path with query string stripped; coarse country and connection type; truncated/discarded IP; UA-derived browser/device only; no cookies, no cross-site ID); (e) note self-host keeps it on the operator's infra while hosted processes it on the owner's behalf. Then add the matching RUM "what we collect" section to the agent README aligned with the amended privacy.md, and update landing `content.ts` and `CHANGELOG.md` per the docs-changelog SOP. Capture the trust model in one quotable ADR sentence: the beacon key is public and cheap to spoof, so RUM is an untrusted per-site aggregate signal, never a security-relevant or per-user-attributable record.

A mandatory security-reviewer pass before ship is required: the public, anonymous attack surface is exactly the high-risk case. All controls (rate limits, sampling, salt, allow-lists) must be config-driven so self-hosters get the same protections in a single-binary deployment.

---

## 7. Data model and aggregation

Store fixed-boundary histogram-bucket rollups, not raw beacons. This is the aggregate-by-default model adapted to Core Web Vitals. Postgres is the DEFAULT store and the correctness floor (user-locked decision, 2026-06-09: Postgres now, ClickHouse when data grows, Postgres fallback when ClickHouse is absent; the dual-backend scale tier is specified below). The Postgres path avoids the `tdigest` C extension, which is unavailable on a default self-host box and most managed Postgres, by interpolating p75 over the stored histogram instead.

### Proposed m56 migration: three tables, m55 RLS pattern

```
-- m56 RUM tables. All three: denormalized tenant_id, FK to tenants+sites ON DELETE CASCADE,
-- ENABLE + FORCE ROW LEVEL SECURITY.
--
-- RLS for RUM is NOT the m55 template verbatim. RUM has a different actor set:
--   * tenant_isolation (app.tenant_id) — the dashboard read path. KEEP (same as m55).
--   * rum_ingest (app.rum_ingest)      — INSERT-only, WITH CHECK only, no USING.
--                                        The anonymous browser write path. ADD.
--   * agent_access (app.agent)         — OMIT. The agent never reads or writes RUM
--                                        data (the browser beacon does), so copying
--                                        m55's agent_access here is dead, never-exercised
--                                        attack surface. Do not include it.
-- (site_perf_config still keeps its own m55-shaped policies plus the new SELECT-only
--  rum_lookup policy from section 5.1a; only the three new RUM tables drop agent_access.)

rum_events_raw (
  id uuid pk, tenant_id uuid NOT NULL, site_id uuid NOT NULL,
  url_pattern text, metric text CHECK (metric IN ('lcp','inp','cls','ttfb','fcp')),
  value_milli integer,            -- CLS stored as milli-units (value x 1000) to share integer machinery
  device text, country char(2), conn text,
  received_at timestamptz NOT NULL DEFAULT now()
)  -- RANGE-partition by day on received_at; BRIN on received_at; index (site_id, received_at).
   -- 48h retention; drill-down + re-aggregation only; drop old partitions wholesale (cheaper than DELETE).

rum_rollup_hourly (
  tenant_id uuid, site_id uuid, url_pattern text, metric text, device char,
  country text,                   -- top-N-per-site code OR the literal '__other__'; see grain note
  bucket_hour timestamptz, sample_count bigint, sample_rate real,  -- sample_rate to scale counts back up
  bucket_counts integer[],        -- fixed CrUX-anchored boundaries; cumulative histogram
  sum_value bigint, min_value integer, max_value integer,
  PRIMARY KEY (site_id, url_pattern, metric, device, country, bucket_hour)
)  -- un-partitioned (small). country is NOT raw ISO-2: it is capped to a per-site
   -- top-N set (default 8) with everything else folded into '__other__' at ingest,
   -- so the dimension product is bounded and independent of the ~249 ISO codes.

rum_rollup_daily ( ... identical but bucket_day date ... )
```

### Raw vs rollup

Do not store raw beacons durably. `rum_events_raw` is a 48-hour rolling drill-down and re-aggregation buffer; River workers fold it into hourly then daily rollups keyed by `(site_id, url_pattern, metric, device, country, bucket_start)`, each carrying a `bucket_counts int[]` over CrUX-aligned boundaries. Rollup UPSERTs are additive and idempotent (`sample_count += excluded`, element-wise `bucket_counts` add) so re-running within the raw-retention window self-heals.

### p75 percentile strategy

Compute p75/p95 at read time by linear interpolation across the cumulative histogram (sum the `int[]` arrays, then interpolate). No per-query sort, no `tdigest` extension, no `percentile_cont` over raw (the OOM vector that forced other tools onto ClickHouse). With around 16 to 32 metric-specific sub-buckets, p75 interpolation error is under around 5 percent, acceptable because Google itself reports field CWV as p75 over distributions. Boundaries are metric-specific and CrUX-anchored: LCP 2500/4000 ms, INP 200/500 ms, CLS 100/250 milli-units. Assign buckets with `width_bucket()` at rollup time.

### Minimum sample-count floor (CrUX-style suppression, mandatory)

A percentile over a handful of samples is noise, not a metric, and a dashboard that renders a confident p75 over three beacons from a long-tail URL undermines the exact 'optimization X moved LCP p75 from A to B' differentiator the product sells. So, mirroring how CrUX suppresses low-traffic origins/URLs, no percentile is ever displayed for a slice whose summed `sample_count` (already scaled by `sample_rate`) is below a `min_sample_count` floor. The floor is config-driven: default around 100 for SaaS and around 30 for self-host (lower because self-host sites have less traffic but the operator owns the trust trade-off). The rule applies at read time, at the exact grain being shown: when the dashboard sums the histograms for a chosen `(site, url_pattern, device, country, window)` slice, it first checks the summed count, and below the floor it returns an explicit 'insufficient samples (N of M needed)' state instead of a number. Coarser views (all-URLs, all-countries) sum more histograms and therefore clear the floor sooner, which is the correct behavior: show the confident aggregate, suppress the noisy drill-down. The floor is independent of, and additional to, the optional ingest-time pruning of sparse rollup tuples; even a stored tuple is not shown until its slice clears the floor.

### Cardinality control (the make-or-break, applied server-side at ingest before any write)

- URL normalization: strip the query string, then template-ize path segments (numeric IDs to `{id}`, UUIDs to `{uuid}`, long slugs to `{slug}`), so `/product/12345` and `/product/67890` both become `/product/{id}`.
- `device` to `{mobile, tablet, desktop}`; `conn` to `{4g, 3g, 2g, slow, unknown}`.
- `country`: this dimension is the cardinality trap, because raw ISO-2 has up to ~249 values and the rollup PK includes it, so left ungoverned it multiplies every other dimension by ~249 and the storage conclusions below would not hold. Cap it: keep only a per-site top-N set of country codes (default `max_distinct_countries = 8`, the codes that actually dominate that site's traffic) and fold every other code into the literal `__other__` at ingest before the write. This makes the country factor a small constant (around 9, the cap plus `__other__`) instead of ~249, exactly as `max_distinct_urls` does for paths. Self-host may set it to 1 (a single `__other__`, dropping the geo dimension entirely) when the box is tight.
- Per-site `max_distinct_urls` cap; once exceeded, new patterns fold into a single `__other__` pattern (keep the head, bucket the long tail).
- Per-site `sample_rate` in `site_perf_config`, synced like the rest of perf config and applied client-side in the tracker so we do not even pay ingress, defended again at the endpoint, and persisted on every rollup row so counts can be scaled back to true volume.

These caps bound the rollup rowset by distinct dimension tuples, independent of pageview count. The bounded dimension product per site is `max_distinct_urls x metrics x devices x (max_distinct_countries + 1)`, every factor a small constant.

### Sampling, retention, self-host vs SaaS caps

Same schema, different defaults only.

| Knob | Self-host (1 GB box) | SaaS |
| --- | --- | --- |
| sample_rate default | 1.0 | auto-tuned per site for high-traffic sites |
| max_distinct_urls | around 300 | around 2000 |
| max_distinct_countries | 1 (geo collapsed to `__other__`) | 8 plus `__other__` |
| min_sample_count (display floor) | around 30 | around 100 |
| raw drill-down | off by default (saves the whole raw table) | on, 48h |
| raw retention | 24h | 48h |
| hourly retention | 7d | 14d |
| daily retention | 90d | 13 months (YoY CWV trends) |

p75 never runs `percentile_cont` over raw; it only ever sums `int[]` from the rollup tables, so the read path never OOMs regardless of profile. The durable-storage ceiling is governed separately by the cardinality caps (notably `max_distinct_countries`, which is what keeps the 1 GB self-host box safe); see the cost model below for the per-profile row math.

### Cost vs the ~$470/mo floor

At 100 sites x 10k pageviews/day (1M pageviews/day), around 3 vitals beaconed per pageview is around 3M raw inserts/day, around 35 inserts/sec average, trivial for Postgres and batchable. Raw storage at around 90 bytes/row x 48h is around 0.5 to 0.7 GB rolling, dropped by partition daily. The durable cost is the rollups, bounded by distinct dimension tuples not pageviews. The math must use the real, capped dimension product, not 'a handful' of countries: with the SaaS caps (around 2000 distinct URLs, 5 metrics, 3 devices, 8 countries plus `__other__` = 9), the worst-case distinct tuples per site is 2000 x 5 x 3 x 9 = 270k. In practice most tuples never appear (a given URL is not hit from all 9 countries on all 3 devices for all 5 metrics every hour), and the sample-count floor (below) drops sparse tuples, so realized rows are well under that ceiling, but the ceiling is what must not OOM. Daily rollups: 100 sites x up to 270k tuples = up to 27M distinct daily rows worst-case, x 365 days x around 150 bytes is on the order of 1.5 TB/year at the absolute ceiling, which does NOT fit a 1 GB box. Two things bring it back to the stated conclusion. First, the self-host profile uses much tighter caps (around 300 URLs, and `max_distinct_countries = 1`, i.e. geo collapsed to `__other__`): 300 x 5 x 3 x 1 = 4500 tuples per site, x the self-host site count (single-digit to low-tens, not 100) x 90 days daily retention is low-millions of rows total at around 150 bytes, well under around 1 GB of daily rollups plus a few hundred MB of hourly, so the 1 GB self-host box never OOMs. Second, the SaaS profile is explicitly NOT a 1 GB box: its ceiling is bounded and shardable, the sample-count floor prunes the long tail before it is ever stored durably, and it still rides the existing Postgres instance without ClickHouse or a new managed service. The honest statement is therefore split: the 'low-millions of rows/year, 1 GB box never OOMs, no new line item' claim holds for the self-host profile (tight caps, few sites, geo collapsed); the SaaS profile has a higher but bounded ceiling that the caps plus the sample-count floor keep on the existing instance. The ~$470/mo floor (dominated by the always-on media-encoder) is unchanged under both, because no new managed service is introduced either way.

### Scale tier: ClickHouse when present (dual-backend, behind the metrics-store precedent)

User-locked decision (2026-06-09): Postgres is the default; ClickHouse is the opt-in scale tier when data grows; Postgres is the fallback whenever ClickHouse is absent. This is not a new pattern to invent: WPMgr already ships exactly this shape for time-series in `apps/api/internal/metrics`, where `metrics.New()` (ClickHouse, via `clickhouse-go/v2`) and `metrics.NewPostgres()` sit behind one interface and `cmd/wpmgr/main.go` boot-selects on whether `WPMGR_CLICKHOUSE_ADDR` is set (uptime metrics use it today). RUM rides the same idea:

- Define one `RumStore` interface (ingest-write plus rollup-read) with two implementations: `RumStorePostgres` (the section 7 histogram-rollup design, the default and the self-host floor) and `RumStoreClickhouse`, boot-selected by `WPMGR_CLICKHOUSE_ADDR` exactly like the metrics store. ClickHouse here is NOT Elasticsearch and introduces no Elastic dependency; it is the sanctioned Go-native scale backend already in `go.mod` and the compose base profile.
- The ClickHouse implementation replaces the manual histogram with a `rum_events` MergeTree (short TTL) feeding an `AggregatingMergeTree` rollup that holds `quantilesTDigestState(0.75)` per `(tenant, site, url_pattern, metric, device, country, bucket)`, finalized with `quantilesTDigestMerge(0.75)` at read time. This gives near-exact p75 at very high volume without the Postgres per-site cardinality caps having to be as tight. Retention is TTL-driven (raw days, daily 13 months), matching the SaaS profile.
- Everything ABOVE the store is engine-agnostic and unchanged: the agent beacon, the public `POST /rum/ingest` endpoint and its security model (section 6), the per-site beacon key, the operator read endpoints and dashboard all speak the `RumStore` interface. Switching a tenant or the whole instance from Postgres to ClickHouse is a backend swap with no agent redeploy and no API surface change.
- Migration path: a tenant that outgrows the Postgres rollups is moved to the ClickHouse backend; the histogram model and the CrUX-anchored buckets carry over conceptually, and because both stores are append-only aggregates there is no destructive cutover. Self-host stays Postgres-only forever unless the operator opts into ClickHouse.

The Postgres histogram path remains the bulletproof default that every deployment gets; ClickHouse is the lever pulled only when a deployment's volume justifies it, with zero impact on the client, the ingest security boundary, or the dashboard.

---

## 8. Phased build plan

Ordering principle here is CP-first then agent then web, with the data model authored from day one with RLS, per the standing specialist-routing and deploy-all-layers conventions. (There is no media-encoder involvement; the media-encoder-first rule does not apply to RUM.) Route every build slice to a dedicated specialist; general-purpose only for read-only review.

### V1 minimal (ship this first)

1. CP data model and ingest (backend-architect). m56 migration: `rum_events_raw` plus `rum_rollup_hourly`/`daily` (each with `tenant_isolation` + INSERT-only `rum_ingest`, and NO `agent_access`) plus the `app.rum_ingest` and `app.rum_lookup` GUCs plus the `site_perf_config` additions: `rum_enabled`, `rum_sample_rate`, `max_distinct_countries`, `min_sample_count`, the `beacon_key_hash` column with its unique lookup index, and the SELECT-only `site_perf_config_rum_lookup` policy. Run `sqlc generate` (never hand-edit). The two narrow scopes from section 5.1a/5.2: `InRumIngestLookupTx` (key-to-site/tenant resolution before any tenant GUC) then `InRumIngestTx` (the write). Beacon-key generation on first enable plus rotation returning plaintext on the perf-config push. The public `POST /rum/ingest` route in its own isolated root-engine group; new CORS and per-IP/per-site rate-limit middleware scoped to that route only and sized as abuse ceilings (never as the sampler); zero-trust ingest handler (key to site/tenant resolution, body caps, schema allow-list, origin soft-check, unbiased server-side random sampling, country top-N cap). Wire the River rollup and retention-GC workers.
2. Tracker JS (wp-agent-engineer for injection, plus the tracker build). Implement `apps/tracker` `vitals.ts` on `web-vitals` for LCP/INP/CLS/FCP/TTFB, esbuild IIFE, ship the minified artifact into agent assets. Cache-write-time injection stage in `Optimizer::run()` plus the `rumEnabled` PerfConfig flag and CP mirror. CSP-safe external script, `sendBeacon` flush on `visibilitychange` to hidden plus `pagehide`.
3. Operator read and dashboard (backend-architect for the read endpoint, frontend-architect for the UI). `GET /perf/rum` plus `/perf/rum/summary` in three-way lock-step (handler plus `canonicalOperatorRoutes` plus `openapi.yaml`, then regen SDK). The read path enforces the `min_sample_count` floor: any slice whose scaled summed count is below the floor returns an explicit "insufficient samples" state, never a p75. `RumResultsTable.tsx` plus `useRumResults.ts`; fill the `performance.tsx` fleet placeholder with a CWV panel (p75 LCP/INP/CLS with good/needs-improvement/poor coloring on the standard thresholds, per-URL and per-device breakdown, and an explicit "insufficient samples" rendering for sub-floor slices rather than a confident number over a handful of beacons).
4. Security and docs (security-reviewer mandatory pass; docs-writer). Pre-ship security review of the anonymous surface; amend `docs/legal/privacy.md` itself (new visitor data subject, site-owner-as-controller, reconcile the agent-is-sole-transmitter framing) plus the aligned RUM disclosure in the agent README; landing `content.ts` and `CHANGELOG.md` per the docs-changelog SOP; the ADR.

V1 data scope: Core Web Vitals plus TTFB/FCP only (cheap, same schema), anonymous cacheable pages only, per-site toggle off by default, periodic refetch dashboard.

### Later

- The remaining "track everything" signals on the hand-rolled collector: full navigation/resource timing, XHR/fetch instrumentation plus the breakdown waterfall (cross-origin resources limited to name-and-duration without `Timing-Allow-Origin`; see section 3), SPA route-change accounting, user-interaction-to-network correlation, Long Tasks, JS errors, `navigator.connection` enrichment.
- Distributed tracing: inject W3C `traceparent` on same-origin/allowlisted calls and wire Gin to continue the trace (fits the existing OTel backend).
- The opt-in Elastic-agent compatibility mode behind a thin adapter (Go endpoint accepts a tolerant Elastic ndjson subset; pin `apiVersion:2`).
- Logged-in / non-cacheable page measurement via a `wp_enqueue_script`/`wp_footer` path.
- LCP/CLS/INP attribution (which element caused it) via the web-vitals attribution build on a sampled slice.
- The "optimization X moved LCP p75 from A to B" correlation panel tying RUM to cache/RUCSS/WOFF2/image actions (the differentiator FlyingPress does not close).
- (Live `rum.*` SSE dashboard liveness is now V1, see section 5.4. A later option is finer-grained per-URL live drill-down streaming.)

---

## 9. Open decisions for the user

**LOCKED (user, 2026-06-09):** (1) Storage = Postgres default, ClickHouse opt-in scale tier, Postgres fallback (section 7 dual-backend). (2) V1 scope = Core Web Vitals + TTFB/FCP only; the rest phased. (3) Tiering = sampled RUM in Free, higher caps/retention on paid. (4) Default per-site state = OFF, one-click enable (matches `fonts_subset`). (5) Retention = SaaS raw 48h / hourly 14d / daily 13mo, self-host raw 24h / 7d / 90d, raw per-pageview drill-down OFF by default. (6) Dashboard = SSE live in V1 (throttled aggregate `rum.rollup_updated` frames on the existing per-site event bus, NOT raw per-beacon streaming), consistent with the app-wide SSE usage. Build proceeds CP-first per section 8.

1. Beacon authentication posture. RESOLVED for V1, not open: the mechanism is the per-site public beacon key with the full lifecycle specified in section 5.1a (high-entropy random key, stored only as `sha256` in `site_perf_config.beacon_key_hash`, resolved via the SELECT-only `InRumIngestLookupTx` scope before any tenant GUC, generated on first enable, rotatable without touching the agent signing key). The only remaining knob is the rate-limit posture layered on top: whether the per-IP/per-site abuse ceilings are tightened on the hosted tier. Recommended: ship the section 5.1a key as the fixed V1 mechanism; treat the abuse-ceiling thresholds as an ops-tunable, not a design open question.
2. V1 data scope. Core Web Vitals plus TTFB/FCP only, versus also shipping navigation/resource timing, XHR/fetch, SPA nav, and JS errors in V1. Recommended: CWV plus TTFB/FCP in V1; the rest in a later phase (this changes the table schema and body cap, so pin it now).
3. Storage and retention numbers. Confirm raw 48h / hourly 14d / daily 13 months (SaaS) and 24h / 7d / 90d (self-host), and whether raw per-pageview drill-down is a product feature at all. Recommended: those windows, raw drill-down off by default (simplifies the 1 GB self-host story).
4. Tiering. Is RUM a paid-tier gate (it has real ingest/storage cost at scale) or included in Free. Recommended: include a sampled RUM in Free and raise caps/retention on paid tiers.
5. Default-on or default-off per site. FlyingPress defaults RUM on; our `fonts_subset` precedent and the privacy posture argue default-off. Recommended: default-off with a one-click enable.
6. Trusted-proxy chain for per-IP limiting. How we derive a trustworthy client IP behind the GCP LB (hosted) and behind self-host reverse proxies, without trusting attacker-set `X-Forwarded-For`. Recommended: a config-driven trusted-proxy list, same value used for rate-limiting and coarse geo.
7. Dashboard liveness. Periodic TanStack refetch versus live `rum.*` SSE frames. Recommended: periodic refetch in V1 (lower risk at telemetry volume), SSE later if needed.

---

## 10. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Body-trust regression: reading site/domain/tenant from the JSON body (the GA4/data-domain antipattern) re-enables cross-site spoofing. | Server derives tenant and site from the resolved beacon key only; add a test that submits a body claiming a different site than the key and asserts it lands on the key's site or is rejected. Security-reviewer pass required. |
| Route-mounting leak: hanging the public ingest under `/api/v1` or inside a tenant-GUC tx exposes authenticated capabilities. | Mount in a separate isolated root-engine group with its own middleware and the narrow `app.rum_ingest` GUC; write-only, no SELECT. |
| CORS absence: a cross-origin JSON beacon is silently blocked by the browser preflight, so the feature passes server-side tests yet fails for every real end-user. | Use `sendBeacon` simple-request content-types to dodge preflight, or add a `/rum`-scoped OPTIONS handler; validate in a real cross-origin browser, not just curl/httptest. |
| Bundle weight self-own (option A): 16 KB of monitoring JS degrades the LCP/INP we sell. | Default to the around 5 to 8 KB first-party collector; keep the Elastic agent opt-in only. |
| sqlc-regen trap: hand-editing `*.sql.go` for the new `rum_enabled` column caused a prod-down 500 once before; regen is currently blocked until a `db/schema.sql` drift (backups `chain_id`) is fixed. | Clear the schema drift first, then run `sqlc generate`; never hand-edit. |
| Unbounded telemetry into Postgres bloats the DB. | Histogram rollups not raw beacons; 48h raw with partition-drop; cardinality caps and per-site sampling at ingest. |
| CSP breakage on hardened sites (Matomo v4 precedent). | External `src` beacon or CSP nonce passthrough; detect a conflicting strict CSP and skip injection; document the exact `connect-src`/`script-src` entries. |
| Cache hit-ratio damage: per-visitor variance, cookies, or `Vary` on cached HTML destroys the page cache. | Inject the key and endpoint as a per-site constant at cache-write time; no `Vary`, no `Set-Cookie`, no PII in cached HTML. |
| Privacy/PII retrofit cost (storing full IP or un-stripped query strings). | IP truncated/discarded, query strings stripped, daily-salted hash for any dedup, all designed in from the first write. |
| Browser skew: Safari/Firefox return null CLS/INP/Long Tasks, biasing p75. | Treat missing-not-zero; segment p75 by browser so Chromium-only metrics are not diluted; set dashboard expectations honestly. |
| p75 from histograms is approximate (under around 5 percent). | Acceptable since field CWV is reported as p75 over distributions; re-evaluate only if product needs exact tail latency per arbitrary slice. |
| Spoof-driven data-integrity FUD: a determined spoofer can skew one site's own aggregate. | Per-site caps plus server-side sampling plus bot filtering bound it; the README and ADR state plainly that RUM is a signal, not an audit record. |
| Self-host divergence: cloud-only rate limiting/sampling leaves self-hosters with a weaker endpoint. | All controls config-driven and functional in a single-binary deploy. |
| Rate-limit-biased p75: sizing a per-IP/per-site limit low enough to fire on real traffic sheds the busiest pages first, biasing p75 toward quiet pages, the exact metric the product sells. | Separate the two mechanisms: only unbiased random sampling shapes the stored distribution (and the sample rate is persisted to scale counts back up); rate limits are abuse ceilings sized to never fire on legitimate visitor traffic; reduce a hot site's sample rate, never let the limit clip it. |
| Country cardinality blowup: raw ISO-2 country in the rollup PK multiplies every other dimension by up to ~249, breaking the row-count and 1 GB-box conclusions. | Cap country to a per-site top-N (`max_distinct_countries`, default 8, self-host 1) with the rest folded into `__other__` at ingest; the cost model is re-run on the capped product, split by profile. |
| Long-tail noise sold as a metric: a confident p75 over a handful of long-tail-URL beacons undermines the "optimization X moved LCP p75" differentiator. | A config-driven `min_sample_count` floor (CrUX-style suppression) enforced at read time at the displayed grain; sub-floor slices render "insufficient samples", never a number. |
| Beacon-key hand-waving: leaving key storage/lookup/rotation as an open question leaves the design's security boundary undefined. | Specified as a V1 deliverable (section 5.1a): `sha256` hash in `site_perf_config.beacon_key_hash`, unique-index point lookup via the SELECT-only `InRumIngestLookupTx` scope before any tenant GUC, generation on enable, rotation without touching the agent signing key. |
| RUM RLS copy-paste: copying m55 verbatim ships an `agent_access` policy on RUM tables that the agent never exercises, i.e. dead attack surface. | Adapt, do not copy: keep `tenant_isolation`, add INSERT-only `rum_ingest`, OMIT `agent_access` on the three RUM tables. |
| Privacy gap: RUM is a new browser-to-CP data flow that contradicts privacy.md's "the agent sends, and only to your control plane" framing and adds a new data subject. | Amend `docs/legal/privacy.md` itself (not just the agent README): add the visitor data subject, name the site owner as their controller, reconcile the sole-transmitter framing as off-by-default RUM exception. |
| Cross-origin blind spots presented as covered: third-party resource breakdowns read all-zero without `Timing-Allow-Origin`, and a `traceparent` header makes a cross-origin call non-simple (preflight the third party will reject). | State both limits up front (section 3); scope breakdowns to same-origin/TAO-permitting resources (label the rest), inject `traceparent` only on same-origin/allowlisted origins. |
| Process note from the research: a prior research run reported overwriting `DECISIONS.md` with a placeholder. | Restore with `git checkout -- DECISIONS.md` before committing; the correct next ADR number is the next free above the existing sequence (verify against the restored file; the long-form ADR also lives under `docs/adr/`). |

---

## Appendix: standard library and threshold reference

- Collector library: Google `web-vitals` (Apache-2.0). Standard build around 2.0 to 2.5 KB gzipped (LCP, INP, CLS, FCP, TTFB); attribution build adds around 1.5 KB. FID removed in v5. Record as Apache-2.0 in `THIRD-PARTY-NOTICES`.
- Official rating thresholds (reuse verbatim, these are the web-vitals constants): LCP good 2500 / poor 4000 ms; INP 200 / 500 ms; CLS 0.1 / 0.25; FCP 1800 / 3000 ms; TTFB 800 / 1800 ms.
- Transport: `navigator.sendBeacon` with a Blob by default; `fetch({keepalive:true})` when a header (traceparent) or oversized payload is needed. Flush on `visibilitychange` to hidden plus `pagehide`; never `unload`/`beforeunload`. Reset INP/CLS accumulators on `pageshow.persisted` (bfcache).
- Distributed tracing: W3C Trace Context `traceparent` (version 00), injected only on same-origin or operator-allowlisted backend calls. Injecting it on an arbitrary cross-origin call turns the request non-simple (forces a CORS preflight and an `Access-Control-Allow-Headers: traceparent` the third party will not grant), so cross-origin tracing is out of scope by design, not omission.
- Breakdown metrics: cross-origin resource phase timings require the resource's server to send `Timing-Allow-Origin`; without it those fields read zero. Same-origin and TAO-permitting resources get the full waterfall; everything else is name-and-duration only and must be labeled as such, never rendered as instantaneous.
