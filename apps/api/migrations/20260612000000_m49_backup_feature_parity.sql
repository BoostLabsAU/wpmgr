-- m49 — Backup feature-parity set (#187)
--
-- Three additive tracks; all columns have safe defaults so existing rows need
-- no backfill and every code path that predates this migration is unchanged.
--
-- TRACK C — LOCK: backup_snapshots.locked
--   Per-snapshot boolean that the retention GC MUST skip. A locked snapshot
--   is never auto-pruned regardless of retention_days / keep_last. An operator
--   must explicitly unlock before the row becomes eligible for GC.
--
-- TRACK B — BACKUP EMAIL: per-schedule notification settings
--   notify_on_completion — "always" | "on_failure" | "never". Default "never"
--     keeps existing silent behaviour for every pre-m49 schedule row.
--   notify_recipients   — JSONB array of email addresses. Empty array = no
--     recipients (which makes notify_on_completion a no-op regardless of value).
--
-- TRACK A — COMPOSITION / EXCLUSIONS: per-schedule backup scope
--   backup_components   — JSONB array of component names to include. NULL (the
--     default) means "all components" (full backup, same as today). When set,
--     only the listed components are archived.
--   exclude_paths       — JSONB array of relative path segments to pass to the
--     agent FilesArchiver as $excludes. NULL = none.
--   exclude_extensions  — JSONB array of lowercase extensions (without leading
--     dot, e.g. "log"). NULL = none.
--   exclude_file_size_mb — Integer. Files strictly larger than this value (in
--     MiB) are skipped. NULL = no size filter.
--   include_core        — Boolean. When true the agent archives the WordPress
--     core source root (ABSPATH: wp-admin, wp-includes, root PHP files including
--     wp-config.php). Default false preserves the current wp-content-only scope.

-- Track C
ALTER TABLE backup_snapshots
  ADD COLUMN locked boolean NOT NULL DEFAULT false;

CREATE INDEX backup_snapshots_locked_idx
  ON backup_snapshots (tenant_id, locked)
  WHERE locked = true;

-- Track B
ALTER TABLE backup_schedules
  ADD COLUMN notify_on_completion text NOT NULL DEFAULT 'never'
    CHECK (notify_on_completion IN ('always', 'on_failure', 'never')),
  ADD COLUMN notify_recipients    jsonb NOT NULL DEFAULT '[]'::jsonb;

-- Track A
ALTER TABLE backup_schedules
  ADD COLUMN backup_components     jsonb    NULL,
  ADD COLUMN exclude_paths         jsonb    NULL,
  ADD COLUMN exclude_extensions    jsonb    NULL,
  ADD COLUMN exclude_file_size_mb  integer  NULL CHECK (exclude_file_size_mb > 0),
  ADD COLUMN include_core          boolean  NOT NULL DEFAULT false;
