-- M52 — #162: Cache Hit-Ratio History.
--
-- site_cache_hit_ratio_history is an append-only table that records one row
-- per cache-stats report cycle when the agent supplies a non-zero delta
-- (hit_count + miss_count > 0). The CP writes it from ReportCacheStats's
-- InTenantTx path. The agent NEVER writes this table directly.
--
-- Columns:
--   id         — surrogate PK (gen_random_uuid()).
--   site_id    — FK → sites.id (CASCADE DELETE so rows are cleaned up when
--                a site is removed; no orphan accumulation).
--   tenant_id  — denormalised for efficient RLS + tenant-scoped queries.
--   hit_count  — delta hit count since the agent's last emission window.
--   miss_count — delta miss count since the agent's last emission window.
--   ratio_pct  — derived hit ratio percentage = round(100*hit/(hit+miss),2);
--                nullable (NULL when both counts are zero, defensive only).
--   sampled_at — the timestamp the CP assigned at ingest (now()), used as
--                the canonical time axis for the trend chart.
--   created_at — row insertion time (now()); used by the GC cutoff filter.
--
-- RLS mirrors site_db_size_history EXACTLY (m42 precedent):
--   tenant_isolation  — USING/WITH CHECK tenant_id = nullif(current_setting(
--                        'app.tenant_id', true), '')::uuid
--   agent             — USING current_setting('app.agent', true) = 'on'
--                        (no WITH CHECK — the GC path only deletes; inserts
--                         flow through InTenantTx / tenant_isolation).
--
-- Defense-in-depth note: the agent policy is intentionally cross-tenant so
-- the River GC worker can sweep the whole table in a single pass without
-- enumerating tenant IDs (same pattern as site_db_size_history, php_errors
-- retention GC, backup_retention_gc). The GC worker never constructs
-- user-visible output from rows it touches — it only deletes.
--
-- No site_scope RESTRICTIVE policy: the perf module deliberately omits it
-- (collaborator gating is in-app via authz.RequireSiteAccess), matching m42.
--
-- Indexes:
--   site_cache_hit_ratio_history_site_sampled_idx  (site_id, sampled_at DESC)
--     — serves the GET /perf/cache/health ORDER BY + LIMIT query efficiently.
--   site_cache_hit_ratio_history_created_idx       (created_at)
--     — serves the GC prune worker's WHERE created_at < cutoff scan.
--
-- The GC worker prunes rows older than ~120 days (leaving ample margin above
-- the 90-day query window). It is registered as a River periodic job in
-- cmd/wpmgr/main.go, runs once per day, and runs under InAgentTx.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_cache_hit_ratio_history" (
        "id"         uuid           PRIMARY KEY DEFAULT gen_random_uuid(),
        "site_id"    uuid           NOT NULL,
        "tenant_id"  uuid           NOT NULL,
        "hit_count"  bigint         NOT NULL DEFAULT 0,
        "miss_count" bigint         NOT NULL DEFAULT 0,
        "ratio_pct"  numeric(5,2),
        "sampled_at" timestamptz    NOT NULL,
        "created_at" timestamptz    NOT NULL DEFAULT now(),
        CONSTRAINT "site_cache_hit_ratio_history_site_id_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id")
            ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_cache_hit_ratio_history_tenant_id_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id")
            ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_cache_hit_ratio_history_site_sampled_uniq"
            UNIQUE ("site_id", "sampled_at")
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_cache_hit_ratio_history'
          AND indexname = 'site_cache_hit_ratio_history_site_sampled_idx'
    ) THEN
        CREATE INDEX "site_cache_hit_ratio_history_site_sampled_idx"
            ON "public"."site_cache_hit_ratio_history" ("site_id", "sampled_at" DESC);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_cache_hit_ratio_history'
          AND indexname = 'site_cache_hit_ratio_history_created_idx'
    ) THEN
        CREATE INDEX "site_cache_hit_ratio_history_created_idx"
            ON "public"."site_cache_hit_ratio_history" ("created_at");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."site_cache_hit_ratio_history" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_cache_hit_ratio_history" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_cache_hit_ratio_history'
          AND policyname = 'site_cache_hit_ratio_history_tenant_isolation'
    ) THEN
        CREATE POLICY "site_cache_hit_ratio_history_tenant_isolation"
            ON "public"."site_cache_hit_ratio_history"
            USING (
                "tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                "tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid
            );
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_cache_hit_ratio_history'
          AND policyname = 'site_cache_hit_ratio_history_agent'
    ) THEN
        CREATE POLICY "site_cache_hit_ratio_history_agent"
            ON "public"."site_cache_hit_ratio_history"
            USING (current_setting('app.agent', true) = 'on');
        -- No WITH CHECK: the GC path only deletes; inserts flow through
        -- the tenant_isolation policy via InTenantTx.
    END IF;
END;
$$;
