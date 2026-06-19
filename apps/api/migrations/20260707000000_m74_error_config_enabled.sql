-- M74: add enabled flag to site_error_config.
--
-- The agent (0.48.4+) gates its early-boot error-trap mu-plugin on an `enabled`
-- bool sent inside the sync_error_config command payload. Without this column
-- the CP never sends the field and the agent defaults to enabled=false, silently
-- dropping the pre-plugins_loaded bootstrap-fatal trap on existing sites.
--
-- DEFAULT true preserves behaviour for all existing rows: sites that already
-- had error monitoring configured keep the mu-plugin installed. New rows also
-- default to true (monitoring on by default).
--
-- Idempotent: the IF NOT EXISTS guard on ADD COLUMN makes it safe to run twice.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_error_config'
          AND column_name  = 'enabled'
    ) THEN
        ALTER TABLE "public"."site_error_config"
            ADD COLUMN "enabled" boolean NOT NULL DEFAULT true;
    END IF;
END;
$$;
