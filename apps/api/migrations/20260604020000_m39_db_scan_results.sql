-- M39 — db_scan results store + watchdog columns.
--
-- Part A: site_db_scan_results
--   Holds the latest per-site database scan output (read-only COUNT + bytes
--   preview). Only the most-recent scan per site is needed for the UI preview;
--   we use UPSERT ON CONFLICT (site_id) DO UPDATE so the table has at most one
--   row per site and never grows unboundedly.
--
--   categories_json  JSONB — the full per-category {count, bytes, tables?} map
--                           returned by the agent's db_scan ACK.
--   db_size_bytes    BIGINT — total database size bytes at scan time.
--   table_count      INT    — number of tables at scan time.
--   job_id           TEXT   — the job_id that produced this row (for correlation).
--   scanned_at       TIMESTAMPTZ — when the agent performed the scan.
--   created_at       TIMESTAMPTZ — when CP persisted this row.
--
-- Part B: watchdog columns on site_perf_config
--   Four nullable columns track in-flight db_clean/db_scan jobs so the periodic
--   watchdog (DBCleanWatchdogWorker, every 2 min) can detect stalled jobs and
--   emit db.clean.failed / db.scan.failed SSE to un-stick the UI.

CREATE TABLE IF NOT EXISTS site_db_scan_results (
    site_id         uuid        NOT NULL,
    tenant_id       uuid        NOT NULL,
    job_id          text        NOT NULL,
    categories_json jsonb       NOT NULL DEFAULT '{}',
    db_size_bytes   bigint      NOT NULL DEFAULT 0,
    table_count     int         NOT NULL DEFAULT 0,
    scanned_at      timestamptz NOT NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT site_db_scan_results_pkey PRIMARY KEY (site_id)
);

-- Tenant isolation index (supports RLS policy scans).
CREATE INDEX IF NOT EXISTS site_db_scan_results_tenant_idx
    ON site_db_scan_results (tenant_id);

ALTER TABLE site_db_scan_results ENABLE ROW LEVEL SECURITY;
ALTER TABLE site_db_scan_results FORCE ROW LEVEL SECURITY;

-- Tenant read/write isolation. Mirrors site_cache_stats EXACTLY: nullif(...,'')
-- so an UNSET or EMPTY app.tenant_id GUC yields NULL (matches no rows) instead of
-- erroring or leaking, and a WITH CHECK so inserts/updates are tenant-scoped too.
CREATE POLICY site_db_scan_results_tenant_isolation ON site_db_scan_results
    USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);

-- Agent write policy. Mirrors site_cache_stats: gated on the app.agent='on' GUC
-- (set only inside InAgentTx after the Ed25519 identity is verified and the
-- tenant/site re-asserted from that identity), NOT a PERMISSIVE-FOR-ALL/site_id
-- check. The agent tx already pins the correct site_id on the row it writes.
CREATE POLICY site_db_scan_results_agent ON site_db_scan_results
    USING (current_setting('app.agent', true) = 'on')
    WITH CHECK (current_setting('app.agent', true) = 'on');

-- Part B: watchdog columns on site_perf_config.
DO $$
BEGIN
    ALTER TABLE "public"."site_perf_config"
        ADD COLUMN IF NOT EXISTS "active_db_clean_job_id"  text,
        ADD COLUMN IF NOT EXISTS "active_db_clean_started"  timestamptz,
        ADD COLUMN IF NOT EXISTS "active_db_scan_job_id"   text,
        ADD COLUMN IF NOT EXISTS "active_db_scan_started"  timestamptz;
END;
$$;
