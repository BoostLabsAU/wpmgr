-- M71 — DB-clean result store.
--
-- Mirrors the site_db_scan_results pattern (M39): one row per site, upserted
-- on every completed clean run. Holds the structured per-category result from
-- the final progress push so GET /perf/db/clean can serve the last-run summary
-- without relying on SSE delivery.
--
-- result_json  JSONB — per-category {rows_deleted, bytes_freed, state} map
--                      assembled from the final done=true progress push.
-- rows_deleted BIGINT — total rows deleted across all categories.
-- bytes_freed  BIGINT — total bytes freed across all categories.
-- job_id       TEXT   — the job_id that produced this row (for correlation).
-- cleaned_at   TIMESTAMPTZ — when the agent reported the final push.
-- created_at   TIMESTAMPTZ — when the CP persisted this row (updated on upsert).
--
-- RLS mirrors site_db_scan_results exactly:
--   tenant_isolation policy — operator read/write (InTenantTx sets app.tenant_id).
--   agent policy            — agent write (InAgentTx sets app.agent='on').
--
-- The clean flow calls InTenantTx for the persist (the final progress push
-- carries a verified tenant_id from the agent identity that HandleDBCleanProgress
-- forwards), so the tenant_isolation policy is the primary write policy.
-- The _agent policy allows the agent worker's scheduled-clean path.

CREATE TABLE IF NOT EXISTS site_db_clean_results (
    site_id      uuid        NOT NULL,
    tenant_id    uuid        NOT NULL,
    job_id       text        NOT NULL,
    result_json  jsonb       NOT NULL DEFAULT '{}',
    rows_deleted bigint      NOT NULL DEFAULT 0,
    bytes_freed  bigint      NOT NULL DEFAULT 0,
    cleaned_at   timestamptz NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT site_db_clean_results_pkey PRIMARY KEY (site_id)
);

-- Tenant isolation index (supports the RLS policy predicate).
CREATE INDEX IF NOT EXISTS site_db_clean_results_tenant_idx
    ON site_db_clean_results (tenant_id);

ALTER TABLE site_db_clean_results ENABLE ROW LEVEL SECURITY;
ALTER TABLE site_db_clean_results FORCE ROW LEVEL SECURITY;

-- Tenant read/write isolation. Mirrors site_db_scan_results exactly: nullif(...,'')
-- so an UNSET or EMPTY app.tenant_id GUC yields NULL (matches no rows) instead of
-- erroring or leaking, and WITH CHECK so inserts/updates are tenant-scoped too.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'site_db_clean_results'
          AND policyname = 'site_db_clean_results_tenant_isolation'
    ) THEN
        CREATE POLICY site_db_clean_results_tenant_isolation ON site_db_clean_results
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent write policy. Mirrors site_db_scan_results: gated on app.agent='on' GUC
-- (set only inside InAgentTx after Ed25519 identity is verified).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'site_db_clean_results'
          AND policyname = 'site_db_clean_results_agent'
    ) THEN
        CREATE POLICY site_db_clean_results_agent ON site_db_clean_results
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
