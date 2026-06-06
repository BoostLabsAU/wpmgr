-- m48 — ADR-048 P5: expose incremental backups on the per-site schedule.
--
-- Additive, forward-only. Two new columns on backup_schedules:
--
--   incremental_enabled — the opt-in toggle. DEFAULT false reproduces today's
--     full-backup behaviour for every existing and new schedule, so there is
--     zero regression: when false the scheduled and run-now paths take the
--     existing full-base path byte-for-byte. When true the control plane
--     consults the ADR-048 auto-base rule (resolveChainForSite) to decide
--     whether the next run is a full base or an increment.
--
--   base_window_days — optional per-schedule override of the BackupBaseWindowDays
--     constant (7). NULL means "use the constant". Bounded 1..365 to match the
--     domain validation.
--
-- No backfill is required: the safe defaults (false / NULL) preserve current
-- behaviour for all existing rows.

ALTER TABLE backup_schedules
  ADD COLUMN incremental_enabled boolean NOT NULL DEFAULT false;

ALTER TABLE backup_schedules
  ADD COLUMN base_window_days integer NULL
    CHECK (base_window_days IS NULL OR base_window_days BETWEEN 1 AND 365);
