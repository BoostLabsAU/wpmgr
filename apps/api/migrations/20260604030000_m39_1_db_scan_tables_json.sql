-- M39.1 — add tables_json column to site_db_scan_results (Phase 2.1).
--
-- The per-table inventory (name, rows, size_bytes, engine, overhead_bytes,
-- belongs_to, owner_type) is stored alongside categories_json so the operator
-- GET endpoint can return it without a re-scan. Client-side pagination and
-- filtering in the browser; no server-side pagination is needed (88 tables ≈ 18 KB).
--
-- DEFAULT '[]' ensures the column is safe to read on rows written by the prior
-- agent version that did not yet include the tables array.

ALTER TABLE site_db_scan_results
    ADD COLUMN IF NOT EXISTS tables_json jsonb NOT NULL DEFAULT '[]';
