-- m83 — File Manager P2: per-site write opt-in flag.
--
-- Adds a `files_write_enabled` column to `site_file_manager`.  Write
-- operations are a SEPARATE opt-in from read (files_enabled).  Both flags
-- must be true before a write/delete/chmod/mkdir/rename/upload command is
-- signed.  Default: false.
--
-- No new tables; no RLS change beyond the existing m82 policies (the
-- _tenant_isolation and _agent policies on site_file_manager already cover
-- any new columns via SELECT/UPDATE *.
--
-- updated_at is set by repo SQL (now()); there is no trigger.
--
-- Idempotency: ADD COLUMN is guarded with IF NOT EXISTS so re-running is safe.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name   = 'site_file_manager'
           AND column_name  = 'files_write_enabled'
    ) THEN
        ALTER TABLE "public"."site_file_manager"
            ADD COLUMN "files_write_enabled" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;
