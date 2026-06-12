-- M68 Object Cache queries. Every statement is tenant-scoped both explicitly
-- (tenant_id in WHERE/VALUES) and by RLS (the app.tenant_id / app.agent policy).
-- The repo wraps each call in InTenantTx or InAgentTx -- these queries never
-- set GUCs themselves. updated_at is set here via now().
-- password_encrypted is NEVER included in the base SELECT: the callers that need
-- the ciphertext for signed-command rendering use GetObjectCacheConfigWithSecret.

-- ---------------------------------------------------------------------------
-- site_object_cache_config
-- ---------------------------------------------------------------------------

-- name: GetObjectCacheConfig :one
-- Operator read path (InTenantTx). Excludes password_encrypted column; callers
-- that need the ciphertext use GetObjectCacheConfigWithSecret.
-- has_password is a derived boolean: true when a ciphertext is stored.
SELECT
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    created_at, updated_at,
    (password_encrypted IS NOT NULL)::boolean AS has_password
FROM site_object_cache_config
WHERE site_id = @site_id;

-- name: GetObjectCacheConfigWithSecret :one
-- Service-internal path: used ONLY when rendering a signed agent command
-- (apply_config / test). Never called from a handler directly.
SELECT * FROM site_object_cache_config
WHERE site_id = @site_id;

-- name: UpsertObjectCacheConfig :one
-- Insert-or-update the per-site object cache config. password_encrypted uses
-- nil-sentinel: when @password_encrypted is NULL the stored ciphertext is
-- preserved (COALESCE(EXCLUDED.password_encrypted, site_object_cache_config.password_encrypted)).
-- Clears last_test_config_hash whenever connection-critical fields change
-- (enforced by passing @clear_test_hash=true from the service layer).
-- updated_at is set via now() (no trigger, project convention).
INSERT INTO site_object_cache_config (
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, password_encrypted, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    updated_at
) VALUES (
    @site_id, @tenant_id, @enabled, @scheme, @host, @port, @socket_path,
    @database, @username, @password_encrypted, @prefix,
    @maxttl_seconds, @queryttl_seconds,
    @connect_timeout_ms, @read_timeout_ms, @retry_count, @retry_interval_ms,
    @serializer, @compression, @async_flush, @flush_strategy, @shared,
    @flush_on_failback, @analytics_enabled,
    @last_test_config_hash, @last_test_result_json, @last_tested_at,
    @oc_state, @oc_latency_ms, @oc_last_error_class,
    @oc_used_memory_bytes, @oc_hit_ratio_pct, @oc_config_drift,
    now()
)
ON CONFLICT (site_id) DO UPDATE SET
    enabled               = EXCLUDED.enabled,
    scheme                = EXCLUDED.scheme,
    host                  = EXCLUDED.host,
    port                  = EXCLUDED.port,
    socket_path           = EXCLUDED.socket_path,
    database              = EXCLUDED.database,
    username              = EXCLUDED.username,
    -- nil-sentinel: keep stored ciphertext when EXCLUDED value is NULL.
    password_encrypted    = COALESCE(EXCLUDED.password_encrypted, site_object_cache_config.password_encrypted),
    prefix                = EXCLUDED.prefix,
    maxttl_seconds        = EXCLUDED.maxttl_seconds,
    queryttl_seconds      = EXCLUDED.queryttl_seconds,
    connect_timeout_ms    = EXCLUDED.connect_timeout_ms,
    read_timeout_ms       = EXCLUDED.read_timeout_ms,
    retry_count           = EXCLUDED.retry_count,
    retry_interval_ms     = EXCLUDED.retry_interval_ms,
    serializer            = EXCLUDED.serializer,
    compression           = EXCLUDED.compression,
    async_flush           = EXCLUDED.async_flush,
    flush_strategy        = EXCLUDED.flush_strategy,
    shared                = EXCLUDED.shared,
    flush_on_failback     = EXCLUDED.flush_on_failback,
    analytics_enabled     = EXCLUDED.analytics_enabled,
    -- Clear the test hash when connection-critical fields changed (signaled by
    -- the service passing EXCLUDED.last_test_config_hash = NULL).
    last_test_config_hash = EXCLUDED.last_test_config_hash,
    last_test_result_json = EXCLUDED.last_test_result_json,
    last_tested_at        = EXCLUDED.last_tested_at,
    oc_state              = EXCLUDED.oc_state,
    oc_latency_ms         = EXCLUDED.oc_latency_ms,
    oc_last_error_class   = EXCLUDED.oc_last_error_class,
    oc_used_memory_bytes  = EXCLUDED.oc_used_memory_bytes,
    oc_hit_ratio_pct      = EXCLUDED.oc_hit_ratio_pct,
    oc_config_drift       = EXCLUDED.oc_config_drift,
    updated_at            = now()
RETURNING
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    created_at, updated_at,
    (password_encrypted IS NOT NULL)::boolean AS has_password;

-- name: UpdateObjectCacheTestResult :one
-- Record the outcome of an objectcache.test command. Stores the result JSON and
-- the config hash that was tested. When the test passed, last_tested_at is set;
-- the service passes NULL for a failed test to avoid advancing the timestamp.
-- Runs under InTenantTx (operator path -- the operator triggered the test).
UPDATE site_object_cache_config
SET last_test_config_hash = @last_test_config_hash,
    last_test_result_json = @last_test_result_json,
    last_tested_at        = @last_tested_at,
    updated_at            = now()
WHERE site_id = @site_id AND tenant_id = @tenant_id
RETURNING
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    created_at, updated_at,
    (password_encrypted IS NOT NULL)::boolean AS has_password;

-- name: UpdateObjectCacheHeartbeatState :one
-- Heartbeat ingest path: update the live status fields and return the updated
-- values so the service can detect state transitions (for SSE publishing).
-- tenant_id is required in the WHERE clause for defence-in-depth: even though
-- InAgentTx sets app.agent='on' (RLS agent policy), the explicit predicate
-- prevents a cross-tenant write when the agent identity is mis-issued.
UPDATE site_object_cache_config
SET oc_state            = @oc_state,
    oc_latency_ms       = @oc_latency_ms,
    oc_last_error_class = @oc_last_error_class,
    oc_used_memory_bytes = @oc_used_memory_bytes,
    oc_hit_ratio_pct    = @oc_hit_ratio_pct,
    updated_at          = now()
WHERE site_id = @site_id AND tenant_id = @tenant_id
RETURNING
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    created_at, updated_at,
    (password_encrypted IS NOT NULL)::boolean AS has_password;

-- name: UpdateObjectCacheEnabled :one
-- Enable/disable the object cache feature flag. enable=true is handshake-gated
-- in the service layer (requires a non-NULL last_test_config_hash matching the
-- current config). Runs under InTenantTx (operator path).
UPDATE site_object_cache_config
SET enabled    = @enabled,
    updated_at = now()
WHERE site_id = @site_id AND tenant_id = @tenant_id
RETURNING
    site_id, tenant_id, enabled, scheme, host, port, socket_path,
    database, username, prefix,
    maxttl_seconds, queryttl_seconds,
    connect_timeout_ms, read_timeout_ms, retry_count, retry_interval_ms,
    serializer, compression, async_flush, flush_strategy, shared,
    flush_on_failback, analytics_enabled,
    last_test_config_hash, last_test_result_json, last_tested_at,
    oc_state, oc_latency_ms, oc_last_error_class,
    oc_used_memory_bytes, oc_hit_ratio_pct, oc_config_drift,
    created_at, updated_at,
    (password_encrypted IS NOT NULL)::boolean AS has_password;

-- ---------------------------------------------------------------------------
-- site_object_cache_stats_history
-- ---------------------------------------------------------------------------

-- name: InsertObjectCacheStatsHistory :one
-- Appends one stats data point. ON CONFLICT DO NOTHING on (site_id, sampled_at)
-- makes it idempotent within the same second (mirrors InsertCacheHitRatioHistory).
-- Runs under InTenantTx (agent stats-report path forwards tenant context via
-- the perf service; the optional object_cache block is ingested in the same tx).
INSERT INTO site_object_cache_stats_history (
    site_id, tenant_id, hit_count, miss_count, ratio_pct,
    used_memory_bytes, avg_wait_ms, ops_per_sec, evicted_keys_delta,
    connected_clients, sampled_at
) VALUES (
    @site_id, @tenant_id, @hit_count, @miss_count, @ratio_pct,
    @used_memory_bytes, @avg_wait_ms, @ops_per_sec, @evicted_keys_delta,
    @connected_clients, @sampled_at
)
ON CONFLICT DO NOTHING
RETURNING *;

-- name: GetObjectCacheStatsHistory :many
-- Returns up to 366 daily-aggregated data points for the trend chart since
-- @since, ordered oldest-first. Each point is one calendar day (UTC) of data.
-- Daily downsampling matches the cache-hit-ratio precedent.
-- Tenant-scoped via RLS (InTenantTx sets app.tenant_id).
SELECT
    date_trunc('day', sampled_at)::timestamptz           AS sampled_at,
    avg(ratio_pct)::numeric                              AS ratio_pct,
    sum(hit_count)::bigint                               AS hit_count,
    sum(miss_count)::bigint                              AS miss_count,
    avg(used_memory_bytes)::bigint                       AS used_memory_bytes,
    avg(avg_wait_ms)::numeric                            AS avg_wait_ms,
    avg(ops_per_sec)::integer                            AS ops_per_sec,
    sum(evicted_keys_delta)::bigint                      AS evicted_keys_delta
FROM site_object_cache_stats_history
WHERE site_id   = @site_id
  AND tenant_id = @tenant_id
  AND sampled_at >= @since
GROUP BY date_trunc('day', sampled_at)
ORDER BY date_trunc('day', sampled_at) DESC
LIMIT 366;

-- name: PruneObjectCacheStatsHistory :execrows
-- Deletes rows older than the cutoff across ALL tenants (InAgentTx / app.agent).
-- LIMIT 2000 keeps each GC transaction short. Mirrors PruneCacheHitRatioHistory.
DELETE FROM site_object_cache_stats_history
WHERE id IN (
    SELECT h.id FROM site_object_cache_stats_history h
    WHERE h.created_at < @cutoff
    LIMIT 2000
);

-- name: UpdateObjectCacheDrift :exec
-- M69 -- set or clear the oc_config_drift indicator from a heartbeat ingest.
-- Runs under InAgentTx (agent path). tenant_id in WHERE is defence-in-depth.
UPDATE site_object_cache_config
SET oc_config_drift = @oc_config_drift,
    updated_at      = now()
WHERE site_id = @site_id AND tenant_id = @tenant_id;
