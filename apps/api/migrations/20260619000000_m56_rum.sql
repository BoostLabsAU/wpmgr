-- M56 — Real User Monitoring (RUM) foundation (Phase 1 / ADR pending).
--
-- 1. Adds RUM columns to site_perf_config: rum_enabled, rum_sample_rate,
--    max_distinct_countries, min_sample_count, beacon_key_hash,
--    beacon_key_hash_prev. Adds a SELECT-only site_perf_config_rum_lookup RLS
--    policy (app.rum_lookup) for pre-tenant beacon-key resolution.
--
-- 2. Creates three tenant-scoped RUM tables with the RUM-specific RLS policy set
--    (NOT the m55 template verbatim):
--      tenant_isolation (app.tenant_id) — dashboard read path. KEEP.
--      rum_ingest       (app.rum_ingest) — INSERT-only, WITH CHECK, no USING.
--      agent_access                      — OMIT (agent never touches RUM data).
--
-- Idempotency: every DDL statement is guarded by IF NOT EXISTS or a
-- column-existence check so re-runs are safe. Forward-only.

-- ---------------------------------------------------------------------------
-- 1a. site_perf_config RUM columns
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'rum_enabled'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "rum_enabled" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'rum_sample_rate'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "rum_sample_rate" real NOT NULL DEFAULT 1.0;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'max_distinct_countries'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "max_distinct_countries" integer NOT NULL DEFAULT 8;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'min_sample_count'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "min_sample_count" integer NOT NULL DEFAULT 100;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'beacon_key_hash'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "beacon_key_hash" bytea;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'beacon_key_hash_prev'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "beacon_key_hash_prev" bytea;
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 1b. Unique index for beacon-key → site/tenant resolution.
-- ---------------------------------------------------------------------------
-- A single indexed point lookup: sha256(presented_key) finds exactly one site.
CREATE UNIQUE INDEX IF NOT EXISTS site_perf_config_beacon_key_hash_uniq
    ON "public"."site_perf_config" (beacon_key_hash)
    WHERE beacon_key_hash IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 1c. SELECT-only RLS policy for pre-tenant beacon-key resolution.
-- ---------------------------------------------------------------------------
-- Mirrors api_keys_prefix_lookup exactly. Enabled ONLY when app.rum_lookup='on'
-- (set transaction-locally by InRumIngestLookupTx). No tenant GUC is set yet.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_perf_config'
          AND policyname = 'site_perf_config_rum_lookup'
    ) THEN
        CREATE POLICY "site_perf_config_rum_lookup" ON "public"."site_perf_config"
            FOR SELECT
            USING (current_setting('app.rum_lookup', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 1d. rum_add_int_arrays helper function for element-wise array addition.
-- ---------------------------------------------------------------------------
-- Used by the UpsertRumRollup* ON CONFLICT DO UPDATE clauses. Creating it as a
-- stable, immutable SQL function lets sqlc's analyzer resolve it like any built-in.
CREATE OR REPLACE FUNCTION rum_add_int_arrays(a integer[], b integer[])
RETURNS integer[]
LANGUAGE sql
IMMUTABLE STRICT PARALLEL SAFE
AS $$
    SELECT array_agg(ai + bi ORDER BY idx)
    FROM unnest(a, b) WITH ORDINALITY AS t(ai, bi, idx)
$$;

-- ---------------------------------------------------------------------------
-- 2. rum_events_raw
-- ---------------------------------------------------------------------------
-- 48h (SaaS) / 24h (self-host) rolling drill-down buffer for re-aggregation.
-- Phase 1 uses a non-partitioned table; RANGE partitioning by day is a later
-- migration once volume justifies it.
CREATE TABLE IF NOT EXISTS "public"."rum_events_raw" (
    "id"          uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"   uuid        NOT NULL,
    "site_id"     uuid        NOT NULL,
    "url_pattern" text        NOT NULL DEFAULT '',
    "metric"      text        NOT NULL CHECK (metric IN ('lcp','inp','cls','ttfb','fcp')),
    "value_milli" integer     NOT NULL DEFAULT 0,
    "device"      text        NOT NULL DEFAULT 'desktop',
    "country"     text        NOT NULL DEFAULT '__other__',
    "conn"        text        NOT NULL DEFAULT 'unknown',
    "received_at" timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT "rum_events_raw_pkey" PRIMARY KEY (id),
    CONSTRAINT "rum_events_raw_tenant_fk" FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT "rum_events_raw_site_fk" FOREIGN KEY (site_id)
        REFERENCES sites (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS rum_events_raw_received_at_brin
    ON "public"."rum_events_raw" USING BRIN (received_at);

CREATE INDEX IF NOT EXISTS rum_events_raw_site_received_idx
    ON "public"."rum_events_raw" (site_id, received_at);

CREATE INDEX IF NOT EXISTS rum_events_raw_tenant_idx
    ON "public"."rum_events_raw" (tenant_id);

ALTER TABLE "public"."rum_events_raw" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."rum_events_raw" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_events_raw'
          AND policyname = 'rum_events_raw_tenant_isolation'
    ) THEN
        CREATE POLICY "rum_events_raw_tenant_isolation" ON "public"."rum_events_raw"
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- INSERT-only: anonymous browser beacons write under the app.rum_ingest GUC.
-- WITH CHECK only (no USING) — this policy cannot be used to SELECT rows.
-- M3: also pins tenant_id and site_id to the GUC-resolved values so a Go-layer
-- bug that passes wrong IDs is caught by the DB rather than writing cross-tenant.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_events_raw'
          AND policyname = 'rum_events_raw_rum_ingest'
    ) THEN
        CREATE POLICY "rum_events_raw_rum_ingest" ON "public"."rum_events_raw"
            FOR INSERT
            WITH CHECK (
                current_setting('app.rum_ingest', true) = 'on'
                AND tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                AND site_id   = nullif(current_setting('app.site_id',   true), '')::uuid
            );
    END IF;
END;
$$;

-- agent_access OMITTED intentionally: the agent never reads or writes RUM data.

-- ---------------------------------------------------------------------------
-- 3. rum_rollup_hourly
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."rum_rollup_hourly" (
    "tenant_id"    uuid        NOT NULL,
    "site_id"      uuid        NOT NULL,
    "url_pattern"  text        NOT NULL,
    "metric"       text        NOT NULL,
    "device"       text        NOT NULL,
    "country"      text        NOT NULL,
    "bucket_hour"  timestamptz NOT NULL,
    "sample_count" bigint      NOT NULL DEFAULT 0,
    "sample_rate"  real        NOT NULL DEFAULT 1.0,
    "bucket_counts" integer[]  NOT NULL DEFAULT '{}',
    "sum_value"    bigint      NOT NULL DEFAULT 0,
    "min_value"    integer     NOT NULL DEFAULT 0,
    "max_value"    integer     NOT NULL DEFAULT 0,
    CONSTRAINT "rum_rollup_hourly_pkey"
        PRIMARY KEY (site_id, url_pattern, metric, device, country, bucket_hour),
    CONSTRAINT "rum_rollup_hourly_tenant_fk" FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT "rum_rollup_hourly_site_fk" FOREIGN KEY (site_id)
        REFERENCES sites (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS rum_rollup_hourly_tenant_idx
    ON "public"."rum_rollup_hourly" (tenant_id);

ALTER TABLE "public"."rum_rollup_hourly" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."rum_rollup_hourly" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_rollup_hourly'
          AND policyname = 'rum_rollup_hourly_tenant_isolation'
    ) THEN
        CREATE POLICY "rum_rollup_hourly_tenant_isolation" ON "public"."rum_rollup_hourly"
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_rollup_hourly'
          AND policyname = 'rum_rollup_hourly_rum_ingest'
    ) THEN
        CREATE POLICY "rum_rollup_hourly_rum_ingest" ON "public"."rum_rollup_hourly"
            FOR INSERT
            WITH CHECK (
                current_setting('app.rum_ingest', true) = 'on'
                AND tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                AND site_id   = nullif(current_setting('app.site_id',   true), '')::uuid
            );
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 4. rum_rollup_daily
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."rum_rollup_daily" (
    "tenant_id"    uuid        NOT NULL,
    "site_id"      uuid        NOT NULL,
    "url_pattern"  text        NOT NULL,
    "metric"       text        NOT NULL,
    "device"       text        NOT NULL,
    "country"      text        NOT NULL,
    "bucket_day"   date        NOT NULL,
    "sample_count" bigint      NOT NULL DEFAULT 0,
    "sample_rate"  real        NOT NULL DEFAULT 1.0,
    "bucket_counts" integer[]  NOT NULL DEFAULT '{}',
    "sum_value"    bigint      NOT NULL DEFAULT 0,
    "min_value"    integer     NOT NULL DEFAULT 0,
    "max_value"    integer     NOT NULL DEFAULT 0,
    CONSTRAINT "rum_rollup_daily_pkey"
        PRIMARY KEY (site_id, url_pattern, metric, device, country, bucket_day),
    CONSTRAINT "rum_rollup_daily_tenant_fk" FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT "rum_rollup_daily_site_fk" FOREIGN KEY (site_id)
        REFERENCES sites (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS rum_rollup_daily_tenant_idx
    ON "public"."rum_rollup_daily" (tenant_id);

ALTER TABLE "public"."rum_rollup_daily" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."rum_rollup_daily" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_rollup_daily'
          AND policyname = 'rum_rollup_daily_tenant_isolation'
    ) THEN
        CREATE POLICY "rum_rollup_daily_tenant_isolation" ON "public"."rum_rollup_daily"
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'rum_rollup_daily'
          AND policyname = 'rum_rollup_daily_rum_ingest'
    ) THEN
        CREATE POLICY "rum_rollup_daily_rum_ingest" ON "public"."rum_rollup_daily"
            FOR INSERT
            WITH CHECK (
                current_setting('app.rum_ingest', true) = 'on'
                AND tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                AND site_id   = nullif(current_setting('app.site_id',   true), '')::uuid
            );
    END IF;
END;
$$;
