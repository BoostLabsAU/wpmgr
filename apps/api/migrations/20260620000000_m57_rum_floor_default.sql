-- M57 — Lower min_sample_count column default from 100 to 30.
--
-- The original default of 100 is too strict for new or low-traffic sites: a
-- site must collect 100 real-visitor samples before any p75 is displayed.
-- 30 samples is sufficient for a statistically meaningful p75 estimate while
-- still being achievable within hours on a typical low-traffic site.
--
-- Scope: column DEFAULT only. Existing rows are NOT backfilled — operators who
-- set or accepted the previous default keep their current value; the new
-- default applies only to rows inserted after this migration runs.
--
-- Idempotency: ALTER COLUMN … SET DEFAULT is always safe to re-run.

ALTER TABLE "public"."site_perf_config"
    ALTER COLUMN "min_sample_count" SET DEFAULT 30;
