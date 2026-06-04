-- M42 — Phase 3.4: DB Size History.
--
-- site_db_size_history is an append-only table that records one row per
-- successful db_scan execution. The CP writes it from UpsertDBScanResult's
-- operator path (InTenantTx). The agent NEVER writes this table directly.
--
-- Columns:
--   id           — surrogate PK (gen_random_uuid()).
--   site_id      — FK → sites.id (CASCADE DELETE so rows are cleaned up when
--                  a site is removed; no orphan accumulation).
--   tenant_id    — denormalised for efficient RLS + tenant-scoped queries.
--   db_size_bytes — raw DB size in bytes as reported by the agent.
--   table_count  — number of tables reported at scan time.
--   scanned_at   — the agent's own scan-completion timestamp (from the ACK),
--                  used as the canonical time axis for the trend chart.
--   created_at   — row insertion time (now()); used by the GC cutoff filter.
--
-- RLS mirrors site_cache_stats EXACTLY (m36 precedent):
--   tenant_isolation  — USING/WITH CHECK tenant_id = nullif(current_setting(
--                        'app.tenant_id', true), '')::uuid
--   agent             — USING current_setting('app.agent', true) = 'on'
--                        (no WITH CHECK — the GC prune worker deletes but
--                         never inserts via this policy; the insert path runs
--                         through InTenantTx / tenant_isolation).
--
-- Defense-in-depth note: the agent policy is intentionally cross-tenant so
-- the River GC worker can sweep the whole table in a single pass without
-- enumerating tenant IDs (same pattern as backup_retention_gc, php_errors
-- retention GC, site_events prune). The GC worker itself never constructs
-- user-visible output from rows it touches — it only deletes.
--
-- Indexes:
--   site_db_size_history_site_scanned_idx  (site_id, scanned_at DESC) — serves
--     the GET /perf/db/health ORDER BY + LIMIT query efficiently.
--   site_db_size_history_created_idx       (created_at)               — serves
--     the GC prune worker's WHERE created_at < cutoff scan.
--
-- The GC worker prunes rows older than ~120 days (leaving ample margin above
-- the 90-day query window). It is registered as a River periodic job in
-- cmd/wpmgr/main.go, runs once per day, and runs under InAgentTx.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_db_size_history" (
        "id"             uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
        "site_id"        uuid        NOT NULL,
        "tenant_id"      uuid        NOT NULL,
        "db_size_bytes"  bigint      NOT NULL DEFAULT 0,
        "table_count"    int         NOT NULL DEFAULT 0,
        "scanned_at"     timestamptz NOT NULL,
        "created_at"     timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT "site_db_size_history_site_id_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id")
            ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_db_size_history_tenant_id_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id")
            ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_db_size_history_site_scanned_uniq"
            UNIQUE ("site_id", "scanned_at")
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_db_size_history'
          AND indexname = 'site_db_size_history_site_scanned_idx'
    ) THEN
        CREATE INDEX "site_db_size_history_site_scanned_idx"
            ON "public"."site_db_size_history" ("site_id", "scanned_at" DESC);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_db_size_history'
          AND indexname = 'site_db_size_history_created_idx'
    ) THEN
        CREATE INDEX "site_db_size_history_created_idx"
            ON "public"."site_db_size_history" ("created_at");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."site_db_size_history" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_db_size_history" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_db_size_history'
          AND policyname = 'site_db_size_history_tenant_isolation'
    ) THEN
        CREATE POLICY "site_db_size_history_tenant_isolation"
            ON "public"."site_db_size_history"
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
        WHERE schemaname = 'public' AND tablename = 'site_db_size_history'
          AND policyname = 'site_db_size_history_agent'
    ) THEN
        CREATE POLICY "site_db_size_history_agent"
            ON "public"."site_db_size_history"
            USING (current_setting('app.agent', true) = 'on');
        -- No WITH CHECK: the GC path only deletes; inserts flow through
        -- the tenant_isolation policy via InTenantTx.
    END IF;
END;
$$;
