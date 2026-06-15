-- M56 Real User Monitoring (RUM) queries.
-- All writes run under InRumIngestTx (app.rum_ingest='on').
-- Dashboard reads run under InTenantTx (app.tenant_id set).
-- Beacon-key lookup runs under InRumIngestLookupTx (app.rum_lookup='on').

-- ---------------------------------------------------------------------------
-- Beacon-key resolution (InRumIngestLookupTx)
-- ---------------------------------------------------------------------------

-- name: LookupRumBeaconKey :one
-- Resolves a sha256(beacon_key) to site_id, tenant_id, rum_enabled, and
-- rum_sample_rate. Runs under app.rum_lookup='on' (SELECT-only policy).
-- The caller computes sha256(presented_plaintext_key) before calling this.
-- Also returns beacon_key_hash_prev so the handler can accept the grace-window
-- previous key during rotation.
SELECT site_id, tenant_id, rum_enabled, rum_sample_rate,
       beacon_key_hash, beacon_key_hash_prev
FROM site_perf_config
WHERE beacon_key_hash = @beacon_key_hash
   OR beacon_key_hash_prev = @beacon_key_hash;

-- ---------------------------------------------------------------------------
-- Beacon-key generation / rotation (InTenantTx)
-- ---------------------------------------------------------------------------

-- name: SetBeaconKeyHash :exec
-- Writes a new beacon_key_hash, rotating the previous one into beacon_key_hash_prev
-- for the grace window. Operator write path (InTenantTx).
UPDATE site_perf_config
SET beacon_key_hash_prev = beacon_key_hash,
    beacon_key_hash      = @beacon_key_hash,
    updated_at           = now()
WHERE site_id = @site_id;

-- name: ClearBeaconKeyHashPrev :exec
-- Clears the grace-window previous hash once the rotation grace period expires.
UPDATE site_perf_config
SET beacon_key_hash_prev = NULL,
    updated_at           = now()
WHERE site_id = @site_id;

-- ---------------------------------------------------------------------------
-- rum_events_raw writes (InRumIngestTx)
-- ---------------------------------------------------------------------------

-- name: InsertRumEvent :exec
-- Inserts one raw RUM beacon event. tenant_id and site_id come from the
-- RESOLVED beacon key — never from the request body. received_at is always
-- set server-side (now()).
INSERT INTO rum_events_raw (
    tenant_id, site_id, url_pattern, metric, value_milli,
    device, country, conn, received_at
) VALUES (
    @tenant_id, @site_id, @url_pattern, @metric, @value_milli,
    @device, @country, @conn, now()
);

-- ---------------------------------------------------------------------------
-- rum_rollup_hourly writes (InRumIngestTx — idempotent additive upsert)
-- ---------------------------------------------------------------------------

-- name: UpsertRumRollupHourly :exec
-- Additive, idempotent upsert: on conflict, element-wise add bucket_counts and
-- accumulate sample_count/sum/min/max. Re-running within the raw-retention window
-- self-heals. The bucket_hour is truncated to the start of the hour by the caller.
INSERT INTO rum_rollup_hourly (
    tenant_id, site_id, url_pattern, metric, device, country,
    bucket_hour, sample_count, sample_rate,
    bucket_counts, sum_value, min_value, max_value
) VALUES (
    @tenant_id, @site_id, @url_pattern, @metric, @device, @country,
    @bucket_hour, @sample_count, @sample_rate,
    @bucket_counts, @sum_value, @min_value, @max_value
)
ON CONFLICT (site_id, url_pattern, metric, device, country, bucket_hour)
DO UPDATE SET
    sample_count  = rum_rollup_hourly.sample_count + EXCLUDED.sample_count,
    sum_value     = rum_rollup_hourly.sum_value     + EXCLUDED.sum_value,
    min_value     = LEAST(rum_rollup_hourly.min_value, EXCLUDED.min_value),
    max_value     = GREATEST(rum_rollup_hourly.max_value, EXCLUDED.max_value),
    -- Element-wise addition for bucket_counts arrays of equal length.
    bucket_counts = rum_add_int_arrays(rum_rollup_hourly.bucket_counts, EXCLUDED.bucket_counts);

-- ---------------------------------------------------------------------------
-- rum_rollup_daily writes (InRumIngestTx — idempotent additive upsert)
-- ---------------------------------------------------------------------------

-- name: UpsertRumRollupDaily :exec
-- Same additive-upsert pattern as hourly but bucket_day is a date.
INSERT INTO rum_rollup_daily (
    tenant_id, site_id, url_pattern, metric, device, country,
    bucket_day, sample_count, sample_rate,
    bucket_counts, sum_value, min_value, max_value
) VALUES (
    @tenant_id, @site_id, @url_pattern, @metric, @device, @country,
    @bucket_day, @sample_count, @sample_rate,
    @bucket_counts, @sum_value, @min_value, @max_value
)
ON CONFLICT (site_id, url_pattern, metric, device, country, bucket_day)
DO UPDATE SET
    sample_count  = rum_rollup_daily.sample_count + EXCLUDED.sample_count,
    sum_value     = rum_rollup_daily.sum_value     + EXCLUDED.sum_value,
    min_value     = LEAST(rum_rollup_daily.min_value, EXCLUDED.min_value),
    max_value     = GREATEST(rum_rollup_daily.max_value, EXCLUDED.max_value),
    bucket_counts = rum_add_int_arrays(rum_rollup_daily.bucket_counts, EXCLUDED.bucket_counts);

-- ---------------------------------------------------------------------------
-- rum_rollup_hourly reads (InTenantTx — dashboard)
-- ---------------------------------------------------------------------------

-- name: GetRumRollupHourly :many
-- Returns hourly rollup rows for a site within a time window, ordered by
-- bucket_hour ascending. Used by the read-time p75 interpolation. The caller
-- sums bucket_counts across the result set.
SELECT tenant_id, site_id, url_pattern, metric, device, country,
       bucket_hour, sample_count, sample_rate,
       bucket_counts, sum_value, min_value, max_value
FROM rum_rollup_hourly
WHERE site_id    = @site_id
  AND tenant_id  = @tenant_id
  AND bucket_hour >= @since
ORDER BY bucket_hour ASC, url_pattern ASC, metric ASC, device ASC, country ASC;

-- name: GetRumRollupDaily :many
-- Returns daily rollup rows for a site within a date range, ordered by
-- bucket_day ascending. Used for longer-window trend reads.
SELECT tenant_id, site_id, url_pattern, metric, device, country,
       bucket_day, sample_count, sample_rate,
       bucket_counts, sum_value, min_value, max_value
FROM rum_rollup_daily
WHERE site_id    = @site_id
  AND tenant_id  = @tenant_id
  AND bucket_day >= @since_day
ORDER BY bucket_day ASC, url_pattern ASC, metric ASC, device ASC, country ASC;

-- ---------------------------------------------------------------------------
-- Fleet RUM aggregate (InTenantTx — tenant-scoped)
-- ---------------------------------------------------------------------------

-- name: GetRumRollupHourlyForSites :many
-- Returns hourly rollup rows across a set of sites in one tenant, within a
-- time window. Used by the fleet RUM aggregate endpoint to compute cross-site
-- p75 without N+1 DB round-trips. site_ids is always filtered to the
-- principal's AllowedSiteIDs (site-scoped) or all tenant sites (org-scoped).
-- The `, id` tiebreaker is not applicable here (no ORDER BY on primary key
-- for aggregation reads), but ORDER BY bucket_hour ASC ensures deterministic
-- streaming for the in-Go accumulator.
SELECT tenant_id, site_id, url_pattern, metric, device, country,
       bucket_hour, sample_count, sample_rate,
       bucket_counts, sum_value, min_value, max_value
FROM rum_rollup_hourly
WHERE tenant_id  = @tenant_id
  AND site_id    = ANY(@site_ids::uuid[])
  AND bucket_hour >= @since
ORDER BY bucket_hour ASC, site_id ASC, metric ASC, device ASC;

-- ---------------------------------------------------------------------------
-- Retention GC (InAgentTx — cross-tenant)
-- ---------------------------------------------------------------------------

-- name: DeleteOldRumEvents :execrows
-- Deletes raw RUM events older than @cutoff across ALL tenants.
-- LIMIT 5000 keeps each GC transaction short; the job runs frequently enough
-- that this cap is never hit at typical ingest rates.
DELETE FROM rum_events_raw
WHERE id IN (
    SELECT r.id FROM rum_events_raw AS r
    WHERE r.received_at < @cutoff
    LIMIT 5000
);

-- name: DeleteOldRumHourlyRollups :execrows
-- Prunes hourly rollup rows older than @cutoff across ALL tenants.
DELETE FROM rum_rollup_hourly
WHERE bucket_hour < @cutoff;

-- name: DeleteOldRumDailyRollups :execrows
-- Prunes daily rollup rows older than @since_day across ALL tenants.
DELETE FROM rum_rollup_daily
WHERE bucket_day < @since_day;
