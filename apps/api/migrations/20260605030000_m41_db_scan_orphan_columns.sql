-- M41 — Phase 3.3 DB Cleaner orphan-scan columns on site_db_scan_results.
--
-- Adds three additive JSONB columns to hold the orphan-enumeration output
-- produced by agents >= 0.16.0 (db_scan Phase 3.3).
--
-- orphaned_options_json   — []OrphanedOptionItem: wp_options rows attributable
--                           to no installed plugin (agent-capped at 500 items).
-- orphaned_cron_json      — []OrphanedCronItem: WP-Cron events attributable to
--                           no installed plugin or WP core.
-- installed_plugins_json  — []InstalledPluginItem: full installed-set snapshot
--                           (active + inactive regular plugins, mu-plugins,
--                           dropins, and network-activated plugins on multisite)
--                           captured at scan time. Foundation for the P3.8
--                           safety gate: an installed (even inactive) plugin
--                           owns its options/cron; only uninstalled-plugin
--                           artefacts are candidates for deletion.
--
-- DEFAULT '[]' on every column ensures rows written by agents < 0.16.0 return
-- an empty array rather than NULL when callers SELECT the column.
--
-- RLS: site_db_scan_results already has ENABLE ROW LEVEL SECURITY + FORCE ROW
-- LEVEL SECURITY with tenant_isolation and agent write policies. The three new
-- columns inherit those policies automatically — no new policy DDL is required.
--
-- No new index at M41: all three columns are read as a unit per site_id
-- (primary key). A GIN index on installed_plugins_json is deferred to the M43
-- P3.8 migration when containment queries will be needed.

ALTER TABLE site_db_scan_results
    ADD COLUMN IF NOT EXISTS orphaned_options_json  jsonb NOT NULL DEFAULT '[]',
    ADD COLUMN IF NOT EXISTS orphaned_cron_json     jsonb NOT NULL DEFAULT '[]',
    ADD COLUMN IF NOT EXISTS installed_plugins_json jsonb NOT NULL DEFAULT '[]';
