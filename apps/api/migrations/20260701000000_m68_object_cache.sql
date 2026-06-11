-- M68 -- Object Cache: site_object_cache_config + site_object_cache_stats_history.
--
-- site_object_cache_config: per-site connection config for the object cache.
--   Carries topology fields for v1 (single/socket/TLS), with schema columns
--   reserved for sentinel/replicated/cluster topologies so no future migration
--   churn is needed. Password stored age-encrypted (cryptbox / m59 precedent).
--   nil-sentinel pattern: NULL password_encrypted means "keep stored secret".
--
-- site_object_cache_stats_history: append-only hit-ratio + server-metric
--   time-series, mirroring m52 (site_cache_hit_ratio_history) exactly.
--   Retention: 7 days raw + 90 days daily downsample (River GC, D4).
--
-- RLS: both tables ship with ENABLE + FORCE + two policies (tenant_isolation +
--   agent) in this migration. m36 canonical template honored exactly.
--
-- Every DDL statement is idempotent (IF NOT EXISTS / DO $$ guard).

-- ---------------------------------------------------------------------------
-- 1. site_object_cache_config
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."site_object_cache_config" (
    -- One row per site; site_id is the natural PK (mirrors site_perf_config).
    "site_id"                   uuid        NOT NULL,
    "tenant_id"                 uuid        NOT NULL,

    -- Feature toggle. When false the agent ignores this config entirely.
    "enabled"                   boolean     NOT NULL DEFAULT false,

    -- v1 topology: tcp | unix | tls. Schema also reserves sentinel/replicated/
    -- cluster values for future topologies so no column migration is needed later.
    "scheme"                    text        NOT NULL DEFAULT 'tcp',

    -- TCP / TLS connection fields.
    "host"                      text        NOT NULL DEFAULT '',
    "port"                      integer     NOT NULL DEFAULT 6379,

    -- Unix socket path (used when scheme='unix'; host/port ignored).
    "socket_path"               text        NOT NULL DEFAULT '',

    -- Redis database index (SELECT n). Default 0 = database 0.
    "database"                  integer     NOT NULL DEFAULT 0,

    -- ACL username (empty = password-only AUTH, no ACL user).
    "username"                  text        NOT NULL DEFAULT '',

    -- age-encrypted Redis password / ACL secret. NULL = no secret configured
    -- (Unix socket setups with no auth). nil-sentinel: on PUT, a missing or
    -- null value in the request preserves whatever ciphertext is already stored.
    -- Only decrypted inside the service when rendering a signed agent command.
    "password_encrypted"        bytea,

    -- Key prefix applied to every Redis key written by this site.
    -- Defaults to a stable per-site value derived from site_id (set on first
    -- save by the service when the operator leaves it blank). Sanitized to
    -- 32 chars [a-z0-9_-] by the agent.
    "prefix"                    text        NOT NULL DEFAULT '',

    -- TTL knobs.
    -- maxttl: ceiling applied to every SET with expire=0 or expire>maxttl.
    --   Default 604800 = 7 days (D6).
    -- queryttl: TTL for *-queries cache groups. Default 86400 = 24h.
    "maxttl_seconds"            integer     NOT NULL DEFAULT 604800,
    "queryttl_seconds"          integer     NOT NULL DEFAULT 86400,

    -- Connection resilience knobs (operator-tunable; agent enforces bounds).
    -- connect_timeout_ms: max time to establish a TCP connection. Default 1000ms.
    -- read_timeout_ms: max time to wait for a Redis response. Default 1000ms.
    -- retry_count: max connect attempts (decorrelated-jitter backoff). Default 3.
    -- retry_interval_ms: backoff base interval for connect retries. Default 25ms.
    "connect_timeout_ms"        integer     NOT NULL DEFAULT 1000,
    "read_timeout_ms"           integer     NOT NULL DEFAULT 1000,
    "retry_count"               integer     NOT NULL DEFAULT 3,
    "retry_interval_ms"         integer     NOT NULL DEFAULT 25,

    -- Serializer: php | igbinary. igbinary availability is capability-probed at
    -- connection TEST time; the agent falls back to php if igbinary is absent.
    "serializer"                text        NOT NULL DEFAULT 'php',

    -- Compression: none | lzf | lz4 | zstd. Similarly probed at TEST time.
    "compression"               text        NOT NULL DEFAULT 'none',

    -- async_flush: use UNLINK (async delete) instead of DEL for individual ops
    -- and FLUSHDB ASYNC instead of FLUSHDB for full flushes. Default false.
    "async_flush"               boolean     NOT NULL DEFAULT false,

    -- flush_strategy: auto | flushdb | scan. auto = let the TEST probe decide
    -- (flushdb on confirmed-dedicated DB, scan on shared). Default 'auto'.
    "flush_strategy"            text        NOT NULL DEFAULT 'auto',

    -- shared: operator-declared hint. When true the flush strategy is always
    -- scan; when false and TEST confirms FLUSHDB is permitted, full flush is used.
    -- Default true (safe for managed-Redis shared instances, D3).
    "shared"                    boolean     NOT NULL DEFAULT true,

    -- flush_on_failback: when true the agent flushes the cache when Redis returns
    -- after a degraded/down window (ensures coherence, D5). Default true.
    "flush_on_failback"         boolean     NOT NULL DEFAULT true,

    -- analytics_enabled: when false the agent stops pushing the extended stats
    -- block (analytics disable switch, D4 interaction). Default true.
    "analytics_enabled"         boolean     NOT NULL DEFAULT true,

    -- Last passing test result hash-keyed to the current config. The enable
    -- handshake gate checks this: enable is rejected when NULL (no passing test).
    -- Cleared to NULL whenever the config changes (password/host/port/scheme/db).
    -- Stored as a short opaque token (sha256 hex of the config snapshot).
    "last_test_config_hash"     text,

    -- Human-readable result from the most recent objectcache.test command.
    -- Stored for display in the dashboard; not used for gate logic.
    "last_test_result_json"     jsonb       NOT NULL DEFAULT '{}'::jsonb,

    -- Timestamp of the last passing test (for display only).
    "last_tested_at"            timestamptz,

    -- Latest heartbeat-sourced status fields (stored so the CP can detect
    -- state transitions and publish SSE events without querying the agent).
    -- oc_state: connected | degraded | down | disabled (empty = disabled/unknown).
    "oc_state"                  text        NOT NULL DEFAULT '',
    -- oc_latency_ms: rolling median command wait time reported in the heartbeat.
    "oc_latency_ms"             integer     NOT NULL DEFAULT 0,
    -- oc_last_error_class: last error class string from the agent error journal.
    "oc_last_error_class"       text        NOT NULL DEFAULT '',
    -- oc_used_memory_bytes: server INFO used_memory field.
    "oc_used_memory_bytes"      bigint      NOT NULL DEFAULT 0,
    -- oc_hit_ratio_pct: rolling hit ratio from the latest heartbeat window.
    "oc_hit_ratio_pct"          numeric(5,2),

    "created_at"                timestamptz NOT NULL DEFAULT now(),
    "updated_at"                timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "site_object_cache_config_pkey" PRIMARY KEY ("site_id"),
    CONSTRAINT "site_object_cache_config_site_fkey"
        FOREIGN KEY ("site_id") REFERENCES "public"."sites" ("id") ON DELETE CASCADE,
    CONSTRAINT "site_object_cache_config_tenant_fkey"
        FOREIGN KEY ("tenant_id") REFERENCES "public"."tenants" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "site_object_cache_config_tenant_idx"
    ON "public"."site_object_cache_config" ("tenant_id");

ALTER TABLE "public"."site_object_cache_config" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."site_object_cache_config" FORCE ROW LEVEL SECURITY;

-- Operator / API path: tenant-scoped read/write.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_object_cache_config'
          AND policyname = 'site_object_cache_config_tenant_isolation'
    ) THEN
        CREATE POLICY "site_object_cache_config_tenant_isolation"
            ON "public"."site_object_cache_config"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / cross-tenant worker path.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_object_cache_config'
          AND policyname = 'site_object_cache_config_agent'
    ) THEN
        CREATE POLICY "site_object_cache_config_agent"
            ON "public"."site_object_cache_config"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 2. site_object_cache_stats_history
-- ---------------------------------------------------------------------------
-- Append-only time-series mirroring site_cache_hit_ratio_history (m52) exactly.
-- One row per agent stats-report cycle when the delta is non-zero.
-- Retention: 7 days raw + 90 days daily downsample (River GC sweep, D4).
-- RLS mirrors m52: tenant_isolation (WITH CHECK) + agent (no WITH CHECK,
-- GC path only deletes).

CREATE TABLE IF NOT EXISTS "public"."site_object_cache_stats_history" (
    "id"                    uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id"               uuid        NOT NULL,
    "tenant_id"             uuid        NOT NULL,

    -- Window-delta hit/miss counts since the agent's last emission.
    "hit_count"             bigint      NOT NULL DEFAULT 0,
    "miss_count"            bigint      NOT NULL DEFAULT 0,
    -- Derived hit ratio percentage (CP-computed at ingest).
    -- NULL when both counts are zero.
    "ratio_pct"             numeric(5,2),

    -- Server INFO snapshot fields sampled at report time.
    "used_memory_bytes"     bigint      NOT NULL DEFAULT 0,
    "avg_wait_ms"           numeric(8,3) NOT NULL DEFAULT 0,
    "ops_per_sec"           integer     NOT NULL DEFAULT 0,
    "evicted_keys_delta"    bigint      NOT NULL DEFAULT 0,
    "connected_clients"     integer     NOT NULL DEFAULT 0,

    -- CP-assigned timestamp (canonical time axis for trend charts).
    "sampled_at"            timestamptz NOT NULL,
    "created_at"            timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "site_object_cache_stats_history_site_fkey"
        FOREIGN KEY ("site_id") REFERENCES "public"."sites" ("id") ON DELETE CASCADE,
    CONSTRAINT "site_object_cache_stats_history_tenant_fkey"
        FOREIGN KEY ("tenant_id") REFERENCES "public"."tenants" ("id") ON DELETE CASCADE,
    CONSTRAINT "site_object_cache_stats_history_site_sampled_uniq"
        UNIQUE ("site_id", "sampled_at")
);

CREATE INDEX IF NOT EXISTS "site_object_cache_stats_history_site_sampled_idx"
    ON "public"."site_object_cache_stats_history" ("site_id", "sampled_at" DESC);

CREATE INDEX IF NOT EXISTS "site_object_cache_stats_history_created_idx"
    ON "public"."site_object_cache_stats_history" ("created_at");

ALTER TABLE "public"."site_object_cache_stats_history" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."site_object_cache_stats_history" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_object_cache_stats_history'
          AND policyname = 'site_object_cache_stats_history_tenant_isolation'
    ) THEN
        CREATE POLICY "site_object_cache_stats_history_tenant_isolation"
            ON "public"."site_object_cache_stats_history"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_object_cache_stats_history'
          AND policyname = 'site_object_cache_stats_history_agent'
    ) THEN
        CREATE POLICY "site_object_cache_stats_history_agent"
            ON "public"."site_object_cache_stats_history"
            USING (current_setting('app.agent', true) = 'on');
        -- No WITH CHECK: the GC path only deletes; inserts flow through
        -- the tenant_isolation policy via InTenantTx.
    END IF;
END;
$$;
