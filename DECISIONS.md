RESTORE-REQUIRED: This file was accidentally overwritten by an earlier research
agent run. The committed copy on `main` is intact and ends at ADR-043 (ADRs
044-053 live as docs/adr/ADR-0NN-*.md and are referenced from migrations/code
but were never appended here). DO NOT trust the lines below as the real file.

Recover the real file, then append the new ADR:

    git checkout -- DECISIONS.md

Then append the ADR-054 draft below (next-free number: ADR-050..ADR-053 are
already taken by docs/adr/ADR-050..053 — incremental backup, archive-delta,
WOFF2 transcoding, font subsetting). Confirm the highest number in the restored
file before committing.

------------------------------------------------------------------------------
APPEND THIS AFTER ADR-043 IN THE RESTORED FILE:
------------------------------------------------------------------------------

## ADR-054: Real User Monitoring (RUM) storage & scale — Postgres rollups, not raw beacons

- **Status:** Proposed
- **Date:** 2026-06-09
- **Context:** The Performance Suite (Phase 6) ships an MIT tracker (`apps/tracker`,
  web-vitals) that beacons Core Web Vitals from every visitor of every managed site.
  RUM is high-cardinality / high-volume (potentially every pageview). We run Postgres,
  NOT ClickHouse (ADR-028's ClickHouse is uptime-metrics-only and we have since chosen
  Postgres for time-series history — see m52 cache-hit-ratio-history). The same schema
  must serve multi-tenant SaaS AND a 1 GB single-box self-host without OOM, keep
  per-site cost ≈ $0, and stay inside the ~$470/mo infra floor. Web-Vitals reporting is
  p75/p95 (a percentile, not a mean) so we must preserve a *distribution*, not an average.

- **Options considered:**

  Storage model:

  | Model | Maint | Perf | DX | Fit | Cost | Notes |
  |---|---|---|---|---|---|---|
  | **Histogram-bucket rollups (CHOSEN)** | 5 | 5 | 4 | 5 | 5 | per-(site,url,metric,device,country,bucket) counts; raw kept ≤48 h for drill-down only. Mirrors GoatCounter (aggregate-by-default) + CrUX histograms. Pure SQL, no extension. |
  | Raw events forever + percentile_cont | 5 | 1 | 4 | 2 | 1 | exact p75 but full scan/sort per query; OOM risk on self-host; Plausible needed ClickHouse for exactly this. |
  | t-digest aggregate (tvondra/tdigest) | 3 | 5 | 3 | 2 | 4 | best percentile accuracy & mergeable, but a C extension — unavailable on managed PG / a default self-host box. DISQUALIFIED on portability. |
  | Reservoir-sample raw + percentile_cont | 4 | 3 | 3 | 3 | 4 | bounded storage but lossy at the tail and still needs sort-per-query. |

  Sources: GoatCounter aggregate-by-default (https://github.com/arp242/goatcounter),
  Plausible chose ClickHouse for raw (https://github.com/plausible/analytics),
  CrUX histogram thresholds (https://web.dev/articles/defining-core-web-vitals-thresholds),
  tdigest extension portability (https://github.com/tvondra/tdigest),
  Postgres width_bucket (https://pgpedia.info/w/width_bucket.html),
  PG tuple overhead ~28 B/row (https://rjuju.github.io/postgresql/2016/09/16/minimizing-tuple-overhead.html).

- **Decision:** Store **fixed-boundary histogram-bucket rollups** in Postgres, not raw beacons.
  - Ingest path: tracker (MIT) → CP `POST /perf/rum` (NOT the agent; the agent never
    sees visitor traffic) → short-retention `rum_events_raw` (48 h, drill-down + late
    re-aggregation only) → River rollup worker folds into `rum_rollup_hourly` →
    daily worker folds hourly → `rum_rollup_daily`.
  - Each rollup row is per `(site_id,url_pattern,metric,device,country,bucket_start)`
    and carries: `sample_count`, `bucket_counts` (int[] over CrUX-derived boundaries),
    `sum_value`, `min/max`. p75/p95 is computed at read time by linear interpolation
    across the cumulative histogram (no extension, no per-query sort).
  - Histogram boundaries are metric-specific and fixed (LCP/INP in ms, CLS ×1000):
    aligned to CrUX good/needs-improvement/poor thresholds (LCP 2500/4000, INP 200/500,
    CLS 100/250) with ~16-32 sub-buckets so interpolated p75 error is < ~5 %.
  - Cardinality control at ingest: URL normalization (strip query, template-ize numeric/
    UUID/slug path segments), `device` bucketed to {mobile,tablet,desktop}, `country` to
    ISO-2, a per-site `max_distinct_urls` cap (overflow → `__other__`), and a per-site
    `sample_rate` (1.0 self-host default; SaaS scales down for high-traffic sites).
  - RLS mirrors m55/m42 EXACTLY: `tenant_id` denormalized, FORCE ROW LEVEL SECURITY,
    `*_tenant_isolation` (USING+WITH CHECK on app.tenant_id) + `*_agent` (cross-tenant,
    for the River rollup/GC workers under InAgentTx). Time-range partition the raw table
    BRIN-or-native by day; rollups stay un-partitioned (small).
  - Retention: raw 48 h (SaaS) / 24 h (self-host); hourly 14 d / 7 d; daily 13 mo / 90 d.
    Self-host caps: `sample_rate` honored, `max_distinct_urls` lower, raw drill-down
    optional (GoatCounter "individual pageviews" opt-in pattern).

- **Consequences:**
  - Aggregation collapses storage to roughly the low-teens-% of raw (per-row overhead
    dominates narrow rows; aggregated rowset is bounded by distinct dimension tuples,
    not pageviews). 100 sites × 10k pv/day fits in well under ~1 GB/yr of daily rollups
    and a few hundred MB of rolling raw — inside the existing $470/mo floor, no new line item.
  - p75/p95 is approximate (histogram interpolation), not exact. Acceptable: Google itself
    reports field CWV as p75 over distributions, and our bucket error is < ~5 %.
  - No new dependency and no ClickHouse: pure SQL + River workers we already run for
    db-size/cache-hit-ratio GC. Self-host portability preserved (no `tdigest` C extension).
  - Re-aggregation window is bounded by raw retention; a rollup bug is only fixable for
    the last 48 h. Mitigation: rollups are additive and idempotent (UPSERT add).
