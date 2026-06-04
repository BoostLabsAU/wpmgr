-- M38 — CP-owned database-cleanup scheduling.
--
-- Adds next_db_clean_at to site_perf_config so the CP's periodic River sweeper
-- (DBCleanScheduleWorker) can own the auto-clean schedule. The old model relied
-- on agent wp-cron and the db_auto_clean/db_auto_clean_interval flags; those
-- flags remain for display in the UI but scheduling is now entirely CP-driven.
--
--   next_db_clean_at  timestamptz  NULL  — when NULL, the site has no pending
--                                         auto-clean; the sweeper sets it on
--                                         first save when db_auto_clean=true.
--
-- Additive + idempotent (ADD COLUMN IF NOT EXISTS). No index needed: the sweeper
-- query is cross-tenant under app.agent policy; it scans the full table which is
-- small (one row per site). A partial index can be added if the table grows.

DO $$
BEGIN
    ALTER TABLE "public"."site_perf_config"
        ADD COLUMN IF NOT EXISTS "next_db_clean_at" timestamptz;
END;
$$;
