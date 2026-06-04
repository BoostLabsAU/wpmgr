-- P3.8 — orphan-delete watchdog columns on site_perf_config.
-- Mirrors the active_db_clean_job_id / active_db_clean_started pattern (M39).
-- The DBOrphanDeleteWatchdogWorker (River periodic, every 2 minutes) checks
-- active_orphan_delete_started against a 5-minute threshold and emits
-- db.orphan.delete.failed SSE when a job stalls.
--
-- Forward-only: the in-house embed.FS migration runner executes the entire
-- file body in one transaction and has no down/rollback concept, so this file
-- contains only the forward ALTER (ADD COLUMN IF NOT EXISTS is idempotent).
-- ============================================================================

ALTER TABLE site_perf_config
    ADD COLUMN IF NOT EXISTS active_orphan_delete_job_id  text,
    ADD COLUMN IF NOT EXISTS active_orphan_delete_started timestamptz;
