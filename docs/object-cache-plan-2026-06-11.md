# WPMgr Object Cache: Complete Plan (2026-06-11)

Status: PROPOSED (awaiting user decisions in section 7)
Owner: architect synthesis from four reference deep-dives (Reference A: engine internals, Reference B: connection and configuration layer, Reference C: analytics, Reference D: lifecycle, admin surfaces, integrations) plus online phpredis/Redis operations research and repo grounding.

Scope: a clean-room, fleet-managed persistent object cache for WordPress on standard phpredis, fully driven by the WPMgr control plane. Nothing from the reference trees is copied; this plan describes techniques neutrally and assigns every observed capability an explicit phase so nothing is silently dropped.

Repo anchors used throughout:

- Agent drop-in lifecycle precedent: `apps/agent/includes/cache/class-dropin-installer.php` (template + placeholder render, signature line, install/verify/remove, host-quirk alternate filename).
- Agent stats push precedent: `apps/agent/includes/cache/class-perf-reporter.php` plus CP ingest `apps/api/internal/perf/agent_handler.go` (ReportCacheStats).
- Time-series precedent: migration `m52_cache_hit_ratio_history.sql` (append-only, tenant RLS + agent GC policy, River sweep).
- Credential encryption precedent: `apps/api/internal/cryptbox` + `m59_site_email.sql` (`*_encrypted bytea`, age, nil-sentinel upsert preserves stored secret).
- SSE bus precedent: `apps/api/internal/site/connection.go` event-type registry + `apps/web/src/features/sites/use-site-events.ts` SITE_EVENT_TYPES, with the 10s badge-debounce throttling pattern from the connection-state work.
- Perf config home: `apps/api/internal/perf/model.go` Config struct (per-site perf config, sqlc-backed; sqlc regen discipline applies).

House rules honored: neutral technique descriptions, no competitor product names, no em dashes.

---

## 1. Feature parity matrix

Legend for Phase:

- `v1` ships in the first release of the feature.
- `v2` fast-follow release (next 1 to 2 milestones).
- `later` on the roadmap, not scheduled.
- `replaced` the capability exists in v1 or v2 but lives in a different layer than the reference (almost always: wp-admin surface replaced by the CP dashboard). Nothing marked `replaced` is dropped; the Notes column says where it lives.
- `out` deliberately not built, with the reason in Notes.

Layers: `agent` (PHP plugin + drop-in), `cp` (Go control plane), `web` (React dashboard), combinations where split.

### 1.1 Drop-in and install lifecycle

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| object-cache.php drop-in stub | Thin locator file in wp-content that loads the real engine from the plugin directory | v1 | agent | Mirrors our advanced-cache.php pattern but inverted: stub is static, engine lives in the plugin so updates never rewrite wp-content except on stub changes |
| Stub bail-outs (PHP version, install mode) | Falls back to core cache.php during WP install or on unsupported PHP | v1 | agent | PHP >= 8.1 floor (our agent already requires it) |
| Kill-switch constant/env | Disables the drop-in entirely without file removal | v1 | agent | `WPMGR_OBJECT_CACHE_DISABLED` constant or env |
| Engine-directory probing | Stub searches plugin/mu-plugin paths for the engine entry file | v1 | agent | Probe our plugin dir + mu-plugin loader dir only (we control both) |
| Load-failure handling | error_log critical + global error journal + optional debug throw | v1 | agent | Also reported to CP via next stats push |
| mu-plugin loader variant | Loads the engine when the agent is installed as mu-plugin | v1 | agent | We already ship `mu-plugin-loader` |
| Install = filesystem copy of stub | Enable copies the stub into wp-content with opcache invalidation | v1 | agent | Reuse DropinInstaller technique: signature line, safe-overwrite checks |
| Disable = remove + flush | Removes drop-in, flushes via a standalone connection | v1 | agent | Flush uses a fresh connection so disable works even when the live cache is broken |
| Update drop-in without flush | Overwrite stub on agent upgrade, no flush | v1 | agent | Version header compare decides |
| Auto-update after agent upgrade | Re-validates and refreshes an outdated-but-ours stub post-upgrade | v1 | agent | Hook the agent self-updater completion |
| Foreign drop-in detection | A wp-content/object-cache.php not ours is flagged, never overwritten without explicit force | v1 | agent + cp + web | Signature/header identity check; CP shows "another object cache drop-in is installed" with a force-replace action |
| Drop-in version-mismatch detection | Stub version header vs plugin version; outdated surfaces as a warning + one-click update | v1 | agent + cp + web | Mirrors our advanced-cache verify/version lifecycle |
| wp-content writability probe | Full write test (copy temp file, verify, delete) before claiming the drop-in is manageable | v1 | agent | Same probe style as page-cache install preflight |
| File-mod permission context | Honors DISALLOW_FILE_MODS with a dedicated context | v1 | agent | |
| Block other auto-installed drop-ins | Prevents a performance module from swapping in its own object-cache drop-in | v2 | agent | Conflict-detect map extension (we already detect competing cache plugins) |
| Deactivate competing object-cache plugins on enable | Detect + surface (not auto-deactivate) other Redis object-cache plugins | v1 | agent + web | We surface a conflict and require the operator to resolve; auto-deactivation is out (too aggressive for a fleet tool) |
| Transient purge on enable | Deletes DB `_transient_%`/`_site_transient_%` rows once transients live in Redis | v1 | agent | At shutdown after enable; multisite sitemeta + per-site tables; skip flag |
| Activation redirect / admin pointer | Post-activate UX nudges | out | n/a | CP-first product; no wp-admin onboarding |

### 1.2 wp_cache_* API surface

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Full standard API | add/get/set/replace/delete/incr/decr/flush/switch_to_blog/add_global_groups/add_non_persistent_groups | v1 | agent | |
| Multi-key API | add_multiple/set_multiple/get_multiple/delete_multiple | v1 | agent | get_multiple = one MGET per call; writes pipelined |
| Modern API | flush_runtime, flush_group, get with &$found out-param, wp_cache_supports | v1 | agent | Advertise add/set/get/delete_multiple + flush_runtime + flush_group |
| remember/sear helpers | get-or-compute-and-set convenience wrappers | v2 | agent | Non-standard sugar; cheap, but not load-bearing |
| add_non_prefetchable_groups helper | Registers prefetch-excluded groups | v2 | agent | Ships with prefetching (1.6) |
| Key/group normalization | trim, default group, int casts | v1 | agent | |
| Flush veto filters | pre-flush short-circuit filters with small backtrace payload | v1 | agent | Host/agent-level veto + audit point |
| Flush log global | Every flush/group-flush recorded with type, group, caller summary | v1 | agent + cp | Agent records; pushed to CP with attribution (see 1.11) |
| wp_cache_close no-op + real shutdown close | Close work deferred to a shutdown function | v1 | agent | Required for prefetch manifest + stats persistence |
| Suspend-cache-addition guard | add() honors wp_suspend_cache_addition | v1 | agent | |
| Legacy compat magic props | hits/misses/cache_hits/cache_misses on the cache object for plugins that poke internals | v1 | agent | Cheap and avoids breakage with debug plugins |

### 1.3 Runtime (L1) cache, groups, keys

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Per-request in-memory L1 array | All persistent reads/writes go through a PHP array in front of Redis | v1 | agent | |
| Clone-on-store and clone-on-read | Objects cloned both directions to prevent by-reference mutation leaks | v1 | agent | Correctness-critical vs naive implementations |
| Global groups | Multisite-shared groups, no blog id in key | v1 | agent | |
| Non-persistent groups | Runtime-only groups that never touch Redis | v1 | agent | |
| Non-prefetchable groups | Persistent but excluded from prefetch | v2 | agent | Registered in v1 (group lists exist), consumed when prefetch ships |
| Wildcard group matching | fnmatch patterns in non-persistent/non-prefetchable lists, memoized | v1 | agent | Memo invalidation on late registration |
| Key shape prefix:[blog:]group:key | Sanitized, lowercased, human-readable ids, memoized builder | v1 | agent | Colons/spaces replaced, 32-char sanitized prefix |
| Cluster hash-tag key shape | {group} wrapped for slot affinity | later | agent | Ships with cluster topology |
| Invalid-key exception + journal | Non int/string keys logged, op returns false | v1 | agent | |
| has()/EXISTS helper | Runtime then Redis existence check | v1 | agent | |

### 1.4 Read/write mechanics and TTL

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| add via SET NX, replace via SET XX | Atomic conditional writes | v1 | agent | |
| get with stored-false disambiguation | Use value+metadata read when client supports it so stored `false` sets $found=true | v1 | agent | Capability-probed; fallback heuristic otherwise |
| incr/decr with TTL preservation | GET, clamp >= 0, SET KEEPTTL, plain-SET fallback for old servers | v1 | agent | Syntax-error detection drives the fallback |
| TTL clamping + maxttl ceiling | Negative TTLs clamped; maxttl caps forever and over-long writes | v1 | agent + cp | maxttl is CP-configurable per site; see decision D6 |
| queryttl for *-queries groups | Separate TTL (default 24h) for WP query-cache groups | v1 | agent | Cheap to enforce in write path |
| Stale query-cache pruning | Parses last_changed stamps out of *-queries keys, bulk-deletes stale ones | v2 | agent | CLI/CP-command triggered, chunked pipeline deletes |
| Pipelined multiwrite | add_multiple/set_multiple in one pipeline with per-key result mapping | v1 | agent | |
| Async deletes (UNLINK) | Destructive ops switch DEL to UNLINK when enabled | v1 | agent | `async_flush` config flag |
| Transaction/pipeline wrapper | pipeline/multi recorded and replayed with result-count validation | v1 | agent | Needed for multiwrite + chunked deletes |

### 1.5 Flushing

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Full flush via FLUSHDB | Wipe the database, rewrite integrity metadata | v1 | agent | Only when DB confirmed dedicated (see 3.7); read timeout suspended during sync flush |
| FLUSHDB ASYNC | Non-blocking server-side flush | v1 | agent | When async_flush + server >= 4.0 |
| Selective flush via SCAN+MATCH+UNLINK | Prefix-scoped flush safe on shared Redis | v1 | agent | Cursor batches (COUNT 500), rate-limited; default on shared instances |
| Group flush strategies | scan (atomic script), keys (atomic script), incremental (PHP SCAN loop), full | v1 partial | agent | v1: incremental PHP SCAN loop + full. Atomic server-side script variants v2 (script management + host compatibility testing) |
| Group flush across all blogs | Multisite non-global group patterns wildcard the blog segment | v1 | agent | Matches WP semantics |
| Network flush modes site/global/all | Multisite flush scoping | v1 | agent | site = current blog keys; global adds global groups; all = full |
| Per-site selective flush helper | Flush one blog's keys by pattern | v1 | agent | Drives CP per-site flush on multisite |
| Flush authorization + audit | Who/when/why for every flush | v1 | cp + web | CP-initiated flush is a signed command, permission-gated, audit-logged (see 5) |
| Flush survives analytics | Metrics snapshot preserved across a full flush | v1 | agent | Our analytics live CP-side (1.10), so only the local tally needs persist-then-push; no DUMP/RESTORE needed |
| Failback flush after outage | Optional flush when Redis returns after a degraded window | v1 | agent | Explicit, observable policy; see decision D5 |

### 1.6 Prefetching

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Per-URL prefetch manifests | Record persistent keys read during GET/HEAD, store manifest keyed by request hash at shutdown | v2 | agent | |
| Prefetch replay via grouped MGET | Next request warms L1 with one MGET per group before WP asks | v2 | agent | |
| Prefetch gating | GET/HEAD only; never CLI/REST/XML-RPC | v2 | agent | |
| Incomplete-class guard | Prefetched payloads containing not-yet-loaded classes are evicted + operator notified | v2 | agent + cp | Notice surfaces in CP as a per-group recommendation |
| Non-prefetchable exclusions at store + replay | Volatile groups never enter manifests | v2 | agent | Built-in exclusions: session-token group, commerce session/cache groups (1.13) |
| Delete-all-manifests helper | Pattern delete of stored manifests | v2 | agent + cp | CP action button |
| Prefetch metrics | Count of keys served by prefetch | v2 | agent + cp + web | Rides the stats push |

### 1.7 Serialization, compression, alloptions

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Serializer option (php/igbinary) | Delegated to phpredis client option | v1 | agent | igbinary availability probed at config time; surfaced to CP |
| Compression option (none/lzf/lz4/zstd) | Delegated to phpredis client option | v1 | agent | Availability probed; default per decision in 2.2 (igbinary + zstd level 3 when available, else none) |
| Capability probing for extensions | Compile-time support detection drives validation errors before enabling | v1 | agent + cp | Part of the connection TEST result (3.10) |
| Raw-bytes escape hatch | Temporarily strip serializer/compression for metadata and raw values | v1 | agent | |
| Format-change safety | Changing serializer/compression triggers integrity flush (1.8) | v1 | agent | |
| Split alloptions into a hash | alloptions stored as a Redis hash, diff-synced field-by-field | v2 | agent | Shrinks the big-key hazard and the alloptions race window |
| alloptions big-key diagnostics | Surface autoload blob size + big-key alerts | v1 | agent + cp + web | We already collect diagnostics; add autoload size to the panel |

### 1.8 Metadata integrity and failure behavior

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Metadata key with risky config | JSON blob recording db/prefix/serializer/compression/flags + WP version | v1 | agent | Written raw, maxttl-exempt, retried reads |
| Integrity-protection flush | Risky-option or WP-version change triggers automatic flush (strict mode) or notice | v1 | agent | Strict default ON; flush reason recorded in flush log |
| Init-failure failover to in-memory cache | Any boot Throwable swaps in a pure-array cache; site never goes down | v1 | agent | The cornerstone degradation guarantee |
| Per-op try/catch degradation | Mid-request Redis errors become misses/dropped writes, journaled, never fatal | v1 | agent | One reconnect attempt per request (3.5), then degrade |
| Error journal global | Accumulated errors readable by diagnostics | v1 | agent + cp | Last error class rides the heartbeat (4) |
| Strict/debug error page | Render a diagnostic page instead of silent failover | out | n/a | Fleet stance: never take the site down for a cache; CP alerting replaces it |
| Degraded-state reporting | Status connected/degraded/down with since-timestamp | v1 | agent + cp + web | See section 4 |

### 1.9 Connection, configuration, topologies

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Single-instance TCP | host:port connect | v1 | agent | |
| Unix socket | Socket path connect | v1 | agent | Preferred when available (throughput + permission isolation) |
| TLS | tls scheme + stream context (verify_peer, cafile, client cert) | v1 | agent | |
| Password auth + ACL user auth | AUTH [pass] or [user, pass] | v1 | agent | ACL recommended on shared Redis (5) |
| Database select | SELECT n, re-asserted on persistent handles | v1 | agent | pconnect leak fix baked in (3.1) |
| Persistent connections (pconnect) | Per-FPM-worker pooled sockets | v1 | agent | Explicit persistent_id derived from connection identity (3.1) |
| Connect timeout / read timeout | Finite defaults | v1 | agent | 1.0s connect, 1.0s read default (3.2) |
| Connect retries with decorrelated jitter | Bounded retry loop, jittered backoff | v1 | agent | AUTH/SELECT inside the retried section (3.3) |
| Native client command retries | OPT_MAX_RETRIES + jitter backoff options when extension supports | v1 | agent | Plus our own read-timeout retry-once (3.4) |
| URL-style config parsing | scheme://user:pass@host:port/db one-string spec | v1 | cp + agent | CP parses and normalizes; agent receives structured config |
| Sentinel topology | Named service discovery, role verification, runtime failover | v2 | agent + cp | Only topology with mid-request failover; gate behind explicit config |
| Replicated primary/replica topology | Read/write split with readonly-command routing, alloptions pinned to primary | later | agent | Rarely needed for a rebuildable cache |
| Cluster topology | Hash-slot sharding, per-master fan-out for flush/scan | later | agent | Forces db 0 + prefix isolation; almost never right for WP |
| In-process shared-memory cache tier | Cross-worker L1 invalidated by server push | out | n/a | Requires a commercial extension or RESP3 client-side caching, which stock phpredis does not support; revisit if the client ecosystem changes |
| Capability probe (supports()) | Turn compile-time extension variance into config-time validation | v1 | agent + cp | Feeds the TEST result |
| Connection test flow | CP-initiated signed command; agent dials, probes, returns structured report | v1 | agent + cp + web | Section 3.10 |
| Per-command instrumentation | wall-time + memory delta per Redis command | v1 | agent | Powers latency metrics + hit ratio buckets |
| Tracer hooks (APM) | Wrap client calls in APM spans | later | agent | Detect-and-integrate when an APM extension is present |
| Server-type fingerprinting | Detect Redis-compatible engines from INFO | v2 | agent + cp | Display only; tolerate INFO field variance in v1 |
| Eviction-policy awareness | Read maxmemory-policy, warn on noeviction, advise allkeys-lru | v1 | agent + cp + web | CONFIG GET with tolerated denial, INFO fallback |

### 1.10 Analytics and metrics

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| In-process request counters | hits, misses, hit ratio, store reads/writes, wait ms, bytes, per-group buckets | v1 | agent | Always on, approximate, near-zero cost |
| Server INFO sampling | keyspace hits/misses, ops/sec, evicted_keys, used_memory(+rss, fragmentation), connected_clients, rejected_connections | v1 | agent | Throttled to once per N seconds across workers via a last-sample key |
| Request measurements ring buffer in Redis | Per-request JSON samples in a sorted set with sample rate + retention + flush survival | replaced | cp | We do NOT store analytics in the site's Redis. The agent aggregates in-process counters and pushes deltas on the existing stats-report path; the CP persists the time series in Postgres (m52 pattern). Simpler, fleet-visible, survives flushes by construction |
| Interval aggregation (mean/median/p90/p95/p99) | Bucketed rollups for charts | v1 partial | cp | v1: per-report-cycle points + daily downsample (mirrors cache-history). Percentile rollups v2 if needed; we chart deltas and ratios first |
| Hit-ratio history + charts | Trend of cache effectiveness | v1 | cp + web | New `site_object_cache_stats_history` table, same RLS + GC as m52 |
| Memory/latency/ops charts | used_memory, avg command wait, ops/sec over time | v1 | cp + web | Same table, wide columns |
| Per-group breakdown (keys/memory/wait) | Which cache groups dominate | v2 | agent + cp + web | On-demand group scan command (next row) rather than continuous |
| Group scanner (keys + memory per group) | SCAN-driven group inventory with optional MEMORY USAGE | v2 | agent + cp + web | CP-triggered signed command; CSV export in web |
| Latency probe per node | Fresh-connection ping latency | v1 | agent + cp + web | Part of TEST + periodic heartbeat latency ms |
| Slow-commands view (SLOWLOG) | Server slow log surface + reset | v2 | agent + cp + web | Tolerate managed-Redis permission denials |
| Command statistics (INFO COMMANDSTATS) | Per-command calls/usec table + reset | v2 | agent + cp + web | Same denial tolerance |
| Flush log surface | Attribution log of flushes (who, when, reason, caller) | v1 | cp + web | CP-side audit + agent-recorded local flushes pushed with stats |
| Metrics snapshot for chart scaling | Rolling maxima | out | n/a | Not needed; Postgres queries derive axis bounds |
| HTML footnote comment with request metrics | Per-page metric comment | out | n/a | Debug-oriented page output; our observability is CP-side |
| Live watch/tail of measurements | Second-by-second live metrics view | later | cp + web | SSE-driven live panel could supersede; not v1 |
| Sample rate control | Percent of requests measured | v1 | agent + cp | Applies to the in-process tally push cadence, default 100 |
| Analytics disable switch | Turn analytics off entirely | v1 | cp | Per-site config flag; agent stops pushing the extended block |

### 1.11 Operator surfaces (dashboard, tools)

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Status overview (status, drop-in state, memory, eviction warning) | At-a-glance health | v1 | web | Object Cache panel on the perf dashboard; live pill via SSE (4) |
| Enable/disable/update drop-in actions | One-click lifecycle | v1 | web + cp + agent | Signed commands; confirm dialog on disable |
| Flush cache button | Full (or selective) flush | v1 | web + cp + agent | Permission-gated + audited; label reflects flush scope |
| Config editor | Connection settings, prefix, db, TTLs, serializer/compression, flags | v1 | web + cp | Secrets write-only (masked), nil-sentinel preserves stored secret (email precedent) |
| Test connection button | Runs the structured probe before save | v1 | web + cp + agent | Blocks enabling on failed handshake |
| Hit ratio + memory + latency charts | Trends | v1 | web | Recharts on the perf dashboard, m52 chart pattern |
| Contextual error explainers | Config missing, handshake failed, recent errors | v1 | web | Driven by heartbeat status + last error class |
| Group inventory + per-group flush | Tools surface | v2 | web + cp + agent | |
| Slowlog + commandstats widgets | Tools surface | v2 | web + cp + agent | |
| Diagnostics report download | Plaintext dump of full object-cache diagnostics | v2 | web + cp | Extend existing diagnostics export |
| wp-admin dashboard widget | Status + actions inside wp-admin | out | n/a | CP-first; the agent's existing minimal admin page may show a one-line status (read-only) |
| wp-admin settings screens (3 subpages) | Full wp-admin UI | replaced | web | The entire surface is the CP Object Cache panel |
| Admin pointer / foreign-notice stripping / footer branding | wp-admin chrome | out | n/a | No wp-admin UI |
| Network-admin per-site flush row action | Multisite per-blog flush | replaced | web + cp | CP per-site flush covers it; multisite blogs surface as flush scope options |
| Custom capability + role-editor integrations | wp-admin permission mapping | replaced | cp | CP RBAC (PermSiteWrite / site sharing RLS) replaces WP capabilities |
| REST API namespace for the plugin | Site-local REST endpoints for the UI | replaced | cp | All reads/writes go through CP API + signed agent commands; no new public site REST surface |

### 1.12 WP-CLI

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| enable/disable (with force, skip-flush, skip-transients) | Lifecycle from CLI | v2 | agent | Handshake-gated enable |
| status / diagnostics | Colorized health summary | v2 | agent | |
| flush / flush-group / per-site flush | Flush from CLI | v2 | agent | |
| reset (wipe db incl metadata) | Full reset | v2 | agent | |
| cli/shell passthrough to redis-cli | Launch redis-cli with site config | later | agent | Secrets-on-argv concerns; needs care |
| watch (digest/log/aggregate live tails) | Live metrics in terminal | later | agent | CP live panel is the primary; CLI tail later |
| analytics / analytics-count JSON | Scriptable metrics | later | agent | CP API is the scriptable surface |
| groups / prune-queries / slowlog / commands | Tools from CLI | v2 | agent | Reuse the same engine paths as CP commands |

### 1.13 Platform integrations and compatibility

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Commerce-platform session safety | Session group + volatile cache groups excluded from prefetch; session group constant honored | v2 (with prefetch); group registration v1 | agent | Built-in non-prefetchable defaults: session-token group, commerce session group (constant-aware), commerce invalidation-stamp wildcard group |
| Site Health debug panel | Full diagnostics in WP Site Health info | v1 | agent | We already ship Site Health integration; add an object-cache section |
| Site Health status tests | Drop-in missing/foreign/outdated, connection ping, eviction policy, server/client version ladder, errors | v1 partial | agent + cp | v1: the checks run agent-side and push into our existing health/diagnostics pipeline (CP surfaces them). Native wp-admin Site Health test registration v2 |
| Query Monitor cache panel replacement | Hit ratio + per-group stats in the debug plugin | v2 | agent | Detect-and-integrate; low effort, high developer goodwill |
| Query Monitor command-log panel | Full Redis command log with backtraces | later | agent | Requires opt-in command logging |
| Debug Bar panel | Legacy debug plugin surface | out | n/a | Ecosystem moved on |
| Early-load page-cache compatibility | Cache loadable from advanced-cache.php context | v1 | agent | Our own page-cache drop-in coexistence is a hard requirement; load order tested in CI |
| Performance-module drop-in guard | Prevent core performance module from replacing the drop-in | v2 | agent | Filter-based |
| Update-confusion guard on plugin headers | Prevent update hijack via header | v1 | agent | Already our stance (self-updater + wp.org dual build) |
| Multisite support (key isolation, switch_to_blog, ms_loaded prefetch deferral, network flush) | Full multisite semantics | v1 | agent | Prefetch deferral lands with prefetch in v2 |

### 1.14 Logging and scheduled work

| Feature | What it does | Phase | Layer | Notes |
|---|---|---|---|---|
| Leveled logger with level filtering | error_log lines namespaced with levels | v1 | agent | Reuse agent logging conventions |
| In-memory command log (debug) | Per-command log feeding debug panels | later | agent | Opt-in, debug only |
| Hourly analytics prune cron | Retention enforcement | replaced | cp | Postgres GC via River worker (m52 sweep pattern); nothing to prune in site Redis |
| 5-minute metrics snapshot cron | Rolling maxima | out | n/a | Not needed CP-side |
| Stale query-cache prune job | Scheduled *-queries cleanup | v2 | agent + cp | CP-scheduled command (our scheduling lives CP-side per standing architecture) |

Matrix totals: 155 rows. v1: 99 (96 full + 3 explicitly partial), v2: 31 (30 full + 1 split row whose group-registration half lands in v1), later: 10, replaced: 6 (capability kept, relocated to CP/web), out: 9 (each with a reason above).

---

## 2. Architecture

### 2.1 Overview

Three layers, CP-first, mirroring the page-cache suite:

```
web (React perf dashboard)
  Object Cache panel: status pill (SSE), charts, config editor, actions
        |
cp (Go API)
  perf module extension: object-cache config CRUD (secrets age-encrypted),
  signed commands (apply-config, test, flush, enable, disable, scan),
  stats ingest on the existing agent stats-report path,
  site_object_cache_stats_history time series + River GC,
  SSE events on the site event bus
        |  Ed25519-signed commands / agent HTTPS pushes
agent (PHP plugin + drop-in)
  object-cache.php stub -> engine in plugin dir
  L1 runtime array cache, group semantics, full wp_cache_* API
  phpredis connection layer (timeouts, retries, pconnect, TLS, ACL)
  graceful degradation (array fallback, per-op catch, reconnect-once)
  metadata integrity, flush strategies, transient purge
  heartbeat enrichment + stats push + structured probe results
```

### 2.2 Agent engine (clean-room, standard phpredis)

- Drop-in: `wp-content/object-cache.php` is a static locator stub with our signature line and Version header, generated and managed exactly like the advanced-cache.php lifecycle in `class-dropin-installer.php` (install renders nothing into the stub; unlike the page-cache drop-in, config does NOT live in the stub, see 5.2). The stub locates the engine entry file inside the agent plugin (or mu-plugin loader path) and includes it. Bail-outs: PHP floor, WP install mode, kill-switch constant.
- Engine composition root: build config, validate, connect, instantiate the cache class, register group lists, multisite wiring, then a catch-all Throwable failover to a pure in-memory array cache implementing the same interface. Shutdown function persists the stats tally (and prefetch manifest in v2).
- L1 runtime cache: `$cache[group][id]` with clone-on-store and clone-on-read. Non-persistent groups short-circuit before Redis on every op. Wildcard group matching with memoization.
- Key builder: `prefix:[blogId:]group:key`, colon/space sanitization, lowercased, memoized; prefix sanitized to 32 chars `[\w-]`. Prefix defaults to a per-site value derived from the site's CP identity (stable across re-enrollment), giving shared-Redis isolation for free.
- Write path: SET with NX/XX/EX as appropriate; maxttl ceiling applies when expire is 0 or exceeds it; *-queries groups take min(queryttl, maxttl). incr/decr preserve TTL via KEEPTTL with a detected-syntax-error plain-SET fallback.
- Read path: L1 first unless forced; value+metadata read when the client supports it so stored false still reports found; get_multiple serves L1 partials and MGETs the rest, backfilling L1.
- Flush: strategy chosen by provisioning shape (3.7). Group flush v1 = runtime drop + PHP SCAN loop pattern delete (UNLINK when async); atomic server-side script strategies in v2. Multisite network-flush site/global/all with per-blog pattern deletes.
- Metadata integrity: JSON metadata key (raw bytes, maxttl-exempt) recording db, prefix, serializer, compression, split flags, WP version; mismatch on risky options triggers an automatic integrity flush in strict mode (default ON) with the reason recorded.
- Serialization/compression: phpredis client options; igbinary and zstd/lz4/lzf availability probed via extension constants and reported in the TEST result. Recommended default: igbinary + zstd (conservative level) when both available, else php serializer + none. Any change to these options is a risky-option change (integrity flush).
- Degradation: boot failure = array cache + journal + error_log + CP visibility on next push/heartbeat. Runtime failure = per-op catch, journal, miss semantics, at most one reconnect per request, then degraded for the rest of the request. Failback behavior per decision D5.
- Multisite: blog-id key segmentation, switch_to_blog swaps the builder's blog id, global groups unaffected, prefetch deferral to ms_loaded when prefetch ships.
- Instrumentation: every command wrapped with wall-time and memory delta; counters for hits/misses/store reads/writes/wait; per-group buckets. Tally persisted via persist-then-ack into an option/transient-safe store and pushed on the PerfReporter cadence (existing stats-report path), then zeroed.

### 2.3 Control plane

- Config model: extend the per-site perf config domain with an `object_cache` config (new table `site_object_cache_config` rather than widening `perf_config`, because it carries secrets and a different write path: nil-sentinel secret preservation, age-encrypted bytea columns). Columns: enabled, scheme (tcp/unix/tls), host, port, socket_path, database, username, prefix, maxttl, queryttl, serializer, compression, async_flush, flush_strategy (auto/flushdb/scan), shared (bool), timeouts/retries knobs, analytics flags, and `password_encrypted bytea` via cryptbox (m59 email precedent: encrypt-on-write, decrypt only when rendering a signed command, never returned by GET, nil-sentinel upsert preserves stored secret).
- Commands (signed, existing agent command channel): `objectcache.apply_config`, `objectcache.test`, `objectcache.enable` (install drop-in, handshake-gated), `objectcache.disable`, `objectcache.flush` (scope: all/site/group), `objectcache.scan_groups` (v2), `objectcache.prune_queries` (v2). All by-id routes gate with RequireSiteAccess + PermSiteWrite per the per-site sharing RLS rules.
- Stats ingest: extend the existing agent stats-report handler (ReportCacheStats path in `internal/perf/agent_handler.go`) with an optional `object_cache` block. Tolerant ingest (email-log lesson: never reject the whole report over an optional block). Writes one row per cycle into `site_object_cache_stats_history` when the delta is non-zero.
- Time series: `site_object_cache_stats_history` mirrors m52 exactly: append-only, site_id + tenant_id, hit/miss deltas, ratio_pct, used_memory_bytes, avg_wait_ms, ops_per_sec, evicted_keys_delta, connected_clients, sampled_at, created_at; tenant_isolation RLS + agent GC policy; River retention sweep; daily downsample for long ranges (cache-history downsample precedent, regenerated through sqlc, never hand-edited).
- SSE: new event types in the `internal/site/connection.go` registry: `objectcache.status_changed`, `objectcache.stats_updated`, `objectcache.flushed`, `objectcache.config_applied`, `objectcache.test_completed`. Published on the existing Postgres LISTEN/NOTIFY tenant bus, filtered by site_id.
- OpenAPI: all new endpoints specified up front (P3 release-gate lesson: no hand-written unspecced routes); `make gen` stub caveat applies, run the real generators.

### 2.4 Web

- Object Cache panel on the per-site perf dashboard (peer of the Cache/Optimize tabs): live status pill (connected/degraded/down + latency ms), drop-in state line, used memory, eviction-policy warning chip, hit-ratio sparkline, flush button (confirm + scope), and a config editor drawer (connection form with masked secret, capability/test results, advanced TTL + serializer/compression selects gated by probed availability).
- Charts: hit ratio trend, memory, avg latency, ops/sec; same Recharts + range-picker pattern as the cache hit-ratio history card.
- Test-connection UX: button runs `objectcache.test`, renders the structured report (reachability, latency, server version, eviction policy, maxmemory, extension capabilities, flush capability class, ACL denials) and blocks Enable until a passing test exists for the current config hash.
- All dialogs on the Radix-based dialog primitives; Impeccable gate before ship.

---

## 3. Improved Redis connection flow

Synthesis of the online research plus the weaknesses observed in the reference connection layer. These are design commitments, not options.

1. pconnect with explicit identity. Persistent connections by default under FPM. persistent_id = short hash of (host, port/socket, database, tls flag, username, prefix-format-version). This prevents the classic pooled-socket leak where a handle SELECTed to one database is reused by a different config. Additionally re-assert SELECT at acquire time whenever database != 0 or the handle is persistent (cheap, defensive).
2. Finite timeouts always. connect_timeout 1.0s, read_timeout 1.0s defaults (CP-tunable 0.1 to 5.0). Never 0/unlimited: a hung Redis must degrade to DB reads, not pile up FPM workers. Read timeout is suspended only for known-long ops (sync FLUSHDB, SCAN loops) and restored in finally.
3. One coherent retry policy. Connect path: up to `retries` (default 3) total attempts with decorrelated-jitter backoff, base = retry_interval (25ms), cap = connect_timeout in ms (one cap, used consistently; the reference used two different caps). AUTH and SELECT execute inside the retried section so transient failures there are retried too (reference weakness: they were outside the loop). No delay computed before the first failure.
4. Command-level resilience. Set the native client retry options (max retries + decorrelated jitter, base/cap aligned with ours) when the extension supports them. Because the extension does not transparently retry command read-timeouts, the engine adds its own: catch read-timeout/broken-pipe, reconnect once per request, replay only idempotent reads (GET/MGET/EXISTS), never replay writes, then degrade for the remainder of the request.
5. Stale-connection hygiene. Heartbeat-driven detection is not available inside FPM, so: optional PING-on-acquire when a persistent handle has been idle beyond a threshold; operator guidance to set server tcp-keepalive 60s when behind load balancers and keep server `timeout 0` for persistent clients; the reconnect-once path (3.4) is the recovery for silent middlebox drops.
6. TLS and ACL first-class. tls scheme + full stream-context options (verify_peer, verify_peer_name, cafile, local_cert/local_pk for mutual TLS). Auth accepts password-only or ACL user+password. Recommended ACL for shared Redis is generated and shown to the operator: `on ~<prefix>:* +@all -@admin -@dangerous` (denies FLUSHALL/FLUSHDB/KEYS/CONFIG/SHUTDOWN), turning prefix isolation from advisory into enforced.
7. Per-site prefix isolation + flush strategy selection. Every site gets a stable unique prefix. Flush strategy is `auto` by default: if the TEST probe confirms a dedicated database (operator-declared `shared=false` AND FLUSHDB permitted), full flush uses FLUSHDB (ASYNC when supported). Otherwise selective flush: SCAN cursor + MATCH prefix:* + batched UNLINK (COUNT 500, inter-batch sleep to bound instance impact). The web flush button always states which strategy will run. FLUSHALL is never issued.
8. maxttl + eviction-policy guidance. maxttl applied to every write (default per D6, suggested 7 days) so a shared instance can always reclaim our keys. The TEST probe reads maxmemory-policy (CONFIG GET, tolerating denial, falling back to INFO where possible) and the panel renders guidance: dedicated instance => allkeys-lru recommended; volatile-lru => acceptable because we TTL everything; noeviction => warning chip with sizing advice.
9. Topology phasing. v1: single instance over TCP, unix socket (preferred when a socket path is detected or configured), TLS endpoints. v2: sentinel (service-name discovery, post-discovery role verification, runtime re-discovery + single retry on primary failure). Later: replicated read/write split (alloptions reads pinned to primary, readonly-command routing) and cluster (hash-tag key shape, per-master fan-out). The config schema reserves fields for all four from day one so no migration churn later.
10. Connection TEST flow (CP-initiated, signed). `objectcache.test` carries the candidate config (including the decrypted secret) over the signed command channel. The agent dials with the candidate config WITHOUT persisting it, then runs a structured probe: PING (latency, 3 samples), INFO server/memory/stats snapshot, CONFIG GET maxmemory-policy (denial tolerated and recorded), SETEX/GET/UNLINK round-trip under the configured prefix, extension capability report (phpredis version, igbinary, lzf/lz4/zstd, TLS support, value+metadata reads, retry/backoff options), ACL denial detection (SCAN/CONFIG/FLUSH capability classes), and a flush-capability classification (flushdb-safe vs scan-only). Result returns as a structured payload; CP stores the latest test result hash-keyed to the config and publishes `objectcache.test_completed` on the SSE bus. Enable is handshake-gated on a passing test.

---

## 4. Live SSE Redis connectivity

Goal: the Object Cache panel shows live connectivity without refresh, and a Redis outage is visible in the dashboard within one heartbeat.

- Agent side: the existing heartbeat payload gains an optional compact `object_cache` block: `{state: connected|degraded|down|disabled, latency_ms, last_error_class, used_memory_bytes, hit_ratio_window_pct}`. State derivation: `connected` = last command cycle clean; `degraded` = array-fallback active or reconnect-once fired this window; `down` = boot failover engaged. latency is the rolling median command wait from the in-process tally. Zero extra Redis traffic: everything is already measured.
- CP ingest: the heartbeat handler (connection_service path) compares the incoming block to the last stored state (new columns on the agent metadata/liveness record). On any state transition it publishes `objectcache.status_changed` immediately on the tenant SSE bus (site_id filtered), including from/to states and last_error_class. This is the "connected -> down" instant signal.
- Steady-state metrics: non-transition updates (latency/memory drift) publish `objectcache.stats_updated` at most once per heartbeat and the web applies the existing throttled-SSE precedent: the status pill re-render is debounced 10s (same as the connection badge debounce shipped in the flap-bulletproofing work) so a flapping site does not strobe the UI, while transition events bypass the debounce.
- Web: `use-site-events.ts` adds the new event types to SITE_EVENT_TYPES; the Object Cache panel keeps a local state machine seeded by the GET status endpoint and patched by SSE. The pill shows state + latency; a `down` transition also raises the standard site-level toast/alert affordance.
- Missing-block semantics: if heartbeats arrive without the block (old agent or feature disabled) the CP leaves state `disabled` and the panel renders the setup card. If heartbeats stop entirely, the existing connection-state machine (missed_heartbeats counter) already covers it; the object-cache pill defers to site connectivity (no false "Redis down" when the whole site is unreachable).
- Flush/config/test lifecycle events (`objectcache.flushed`, `objectcache.config_applied`, `objectcache.test_completed`) publish on completion so open panels update without polling, mirroring the cache/perf-config lifecycle events already in the registry.

---

## 5. Security

### 5.1 Credential flow end to end

1. Operator enters connection settings in the web config editor. The secret field is write-only and masked; an empty submit is a nil-sentinel meaning "keep stored secret" (email-creds precedent).
2. CP encrypts the secret with cryptbox (age, X25519 identity from the server key) into `password_encrypted bytea`. Plaintext never lands in logs, DTOs, or GET responses. The GET config endpoint returns `has_password: true` only.
3. CP decrypts only at the moment it renders a signed command (`objectcache.apply_config` or `objectcache.test`) to the agent. Commands ride the existing Ed25519-signed, replay-protected channel over HTTPS.
4. Agent persists connection settings to a dedicated PHP config file `wp-content/wpmgr-object-cache-config.php` (`<?php defined('ABSPATH') || exit; return [...];`), chmod 0600, atomic write (tmp + rename), owner-only. Rationale for file over alternatives, see 5.2. The secret is stored only there.
5. The agent never echoes the secret back: status, diagnostics, TEST results, and heartbeats carry a redacted config (scheme, host, port, db, prefix, username, tls flag, and a config hash for drift detection).

### 5.2 Where connection params live on the site (decision + reasoning)

- wp-config constants: rejected as the default. Editing wp-config is the highest-risk file operation we do (we accept it only for the WP_CACHE constant, which is boolean and reversible); a malformed write takes the site down, and credentials in wp-config leak into every wp-config backup/copy paste.
- Drop-in-embedded (the advanced-cache.php approach): rejected for credentials. The drop-in should be a static, versioned, signature-verifiable artifact; embedding secrets means every config change rewrites the drop-in (defeating tamper/version checking) and puts secrets in a file that other tooling commonly diffs and copies.
- DB option: rejected. Readable by any plugin with DB access, included in DB exports/backups and search-replace operations, and creates a circular dependency flavor (the cache config living in the store the cache fronts).
- Chosen: dedicated PHP config file in wp-content, 0600, returning an array, loaded by the engine with a file-permission check. Secrets stay out of the DB and out of versioned/verifiable artifacts; the file is excluded from our backup file-set by default (same mechanism as our existing sensitive-path exclusions) and listed in diagnostics as present/absent only. Unix-socket setups frequently need no secret at all, which the form encourages.

### 5.3 What never leaves the site

- The Redis password/ACL secret after first delivery (CP keeps only the encrypted copy; agent never sends it back).
- Cached values. No cache payloads, keys, or key samples are pushed to the CP in v1. Group scans (v2) return group names + counts + byte totals only.
- INFO output is reduced agent-side to the whitelisted numeric fields in the stats block; raw INFO text is not shipped.

### 5.4 Drop-in integrity

- Signature line + Version header on the stub (DropinInstaller pattern). Verify-on-report: the agent's install-state report includes dropin_state (ours-current / ours-outdated / foreign / missing) and the config hash; CP renders warnings and one-click repair.
- Foreign object-cache.php is never overwritten without an explicit operator force action (audited).
- Writability proven by a real temp-file write probe before any lifecycle action is offered.

### 5.5 CP-side authorization and RLS

- New tables (`site_object_cache_config`, `site_object_cache_stats_history`) ship with tenant_isolation RLS in the same migration (feature-build pitfall rule: RLS on new tenant tables at creation). History table mirrors m52 exactly including the cross-tenant agent GC policy that only deletes.
- Every by-id route gates with RequireSiteAccess (per-site sharing RLS memory). Config write + flush + enable/disable require PermSiteWrite. Flush is additionally rate-limited per site (the recheck/autologin MemoryLimiter pattern, 4/min) and writes an audit row (actor, scope, strategy, reason).
- Stats ingest rides the agent-authenticated report path; the optional block is size-capped and schema-validated; malformed blocks are dropped without failing the report (tolerant-ingest lesson).

### 5.6 Reviewer checklist (security-reviewer gate)

- [ ] Secret never appears in: GET responses, logs, SSE payloads, TEST results, heartbeats, OpenAPI examples, frontend state after save.
- [ ] cryptbox encrypt-on-write verified; nil-sentinel update preserves secret; secret column excluded from sqlc row types used in DTOs.
- [ ] Signed-command payloads for apply_config/test are replay-protected and single-target (existing channel invariants hold).
- [ ] Agent config file written 0600 atomic; permission check on load; excluded from backups; path not user-controllable.
- [ ] Drop-in lifecycle cannot overwrite a foreign drop-in without force; force action audited.
- [ ] FLUSHALL unreachable; FLUSHDB only on confirmed-dedicated DBs; SCAN-flush bounded and rate-limited.
- [ ] RLS on both new tables; by-id routes gated; flush rate-limited + audited.
- [ ] Degradation paths cannot fatal the site (boot failover + per-op catch covered by tests).
- [ ] TEST command does not persist candidate config on failure and cannot be used to port-scan (host allow-shapes validated CP-side, private-range rules same as our webhook/url validation posture).
- [ ] No cache payload exfiltration paths (group scan returns aggregates only).

---

## 6. Phased build plan

Standing rules applied: specialists only, CP-first, deploy all touched layers, CP deploys before agent, sqlc regen discipline, OpenAPI specced before handlers, docs-writer DoD (CHANGELOG + landing), Impeccable gate on web, security review before ship.

### Phase 0: Contract (backend-architect, 0.5 milestone)

- Migration m68: `site_object_cache_config` + `site_object_cache_stats_history` (+ heartbeat state columns), RLS in-migration, `sqlc generate` true no-op verified.
- OpenAPI: config CRUD, status, test, flush, history endpoints; command payload schemas; SSE event-type registry entries documented.
- Gate: migration applies on boot in dev; codegen clean (Go + openapi-client).

### Phase 1: CP backend (backend-architect, 1 milestone)

- Config service + handlers (cryptbox secrets, nil-sentinel), signed commands (apply_config, test, enable, disable, flush), stats-report ingest extension, history writes + River GC + daily downsample, heartbeat ingest + SSE publication (status_changed transition logic + throttle), audit + rate limit on flush.
- Gate: unit + httptest contract tests, routes-contract test extended, RLS tests for both tables, tolerant-ingest test (malformed block does not fail report).

### Phase 2: Agent (wp-agent-engineer, 2 milestones, can start after Phase 0 contract freeze)

- 2a Engine: drop-in stub + locator, composition root with array-cache failover, L1 + groups + key builder, full wp_cache_* API incl. multi-key + flush_group + supports, TTL/maxttl/queryttl, NX/XX/KEEPTTL mechanics, pipelines, flush strategies (flushdb/scan/incremental group flush), multisite, metadata integrity flush, error journal + per-op degradation + reconnect-once.
- 2b Connection + lifecycle: connection layer per section 3 (pconnect identity, timeouts, jitter retries, TLS, ACL, capability probe), config file persistence (0600 atomic), DropinInstaller-pattern lifecycle (install/verify/version/remove + writability probe + foreign detection), transient purge on enable, TEST probe implementation, heartbeat block + PerfReporter stats block (persist-then-ack tally), Site Health info section, conflict surfacing.
- Gate: phpunit suite (engine semantics incl. clone-on-read, found-disambiguation, TTL clamps, group flush patterns, multisite keys), phpcs 0, integration test against a real Redis in CI, page-cache coexistence test (advanced-cache + object-cache both active).

### Phase 3: Web (frontend-architect, 1 milestone, parallel with late Phase 2)

- Object Cache panel: status pill + SSE wiring (SITE_EVENT_TYPES additions, 10s debounce, transition bypass), config editor drawer with masked secret + test flow + capability-gated selects, flush button with strategy disclosure + confirm, charts (hit ratio, memory, latency, ops) on the m52 chart pattern, error explainers, eviction-policy guidance chip.
- Gate: typecheck/lint/build, Impeccable detect clean, empty/degraded/foreign-drop-in states designed.

### Phase 4: Ship v1 (security-reviewer then docs-writer then devops)

- Security review against the 5.6 checklist (blocking). Docs-writer: CHANGELOG + landing card + feature doc. Deploy order: api image, web image, then `make agent-release` (CP before agent so old agents hit tolerant endpoints); OSS tag + GHCR + curated release notes per the release routine.
- Live QA on a real site: enable on a host with local Redis, TEST flow, flush, kill Redis and watch the pill go degraded/down via SSE, restore and verify failback policy.

### Phase 5: v2 (next release line)

- Prefetching (manifests, replay, incomplete-class guard, non-prefetchable defaults incl. commerce sessions), split alloptions, atomic script group-flush strategies, WP-CLI command set, sentinel topology, group scanner + slowlog + commandstats surfaces, Query Monitor panel, stale query-cache prune command, Site Health native status tests, server-type fingerprinting.

### Later

- Replicated and cluster topologies, command-log panel, live tail, redis-cli passthrough, APM tracers, percentile rollups if charts need them.

---

## 7. Open decisions (user-blocking, with recommended defaults)

| # | Decision | Options | Recommendation |
|---|---|---|---|
| D1 | v1 topology scope | single TCP + unix socket + TLS only; or also sentinel | Single + socket + TLS only. Sentinel v2. Schema reserves fields now |
| D2 | Where connection credentials persist on the site | dedicated 0600 PHP config file in wp-content; wp-config constants; DB option | Dedicated config file (reasoning in 5.2) |
| D3 | Shared-Redis stance | support shared instances via prefix + SCAN-flush + ACL guidance; or require a dedicated database/instance in v1 | Support shared (most managed hosts hand you shared Redis); auto flush-strategy selection per 3.7 |
| D4 | Analytics retention | raw report-cycle rows 7d + daily downsample 90d; or 30d/365d | 7d raw + 90d daily (matches cache-history footprint; River GC) |
| D5 | Failback after a Redis outage | flush-on-failback ON by default (coherence) vs resume (keep possibly stale keys) | Flush-on-failback ON, per-site toggle, event logged |
| D6 | Default maxttl | 7 days; 24h; unlimited | 7 days (bounded shared-instance footprint; WP regenerates cheaply) |

Everything else in this plan proceeds on the stated defaults unless overridden.

---

## 8. Release mapping

- v1 (one release line, agent + api + web together): Phases 0 to 4. Suggested next minor after current head.
- v2: Phase 5 items, split across one or two minors (prefetch + alloptions first, tooling surfaces second).
- The agent self-updater only bumps when the agent layer changes (standing practice); v1 bumps all three layers.
