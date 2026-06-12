-- M70 -- Object Cache per-site debug response header toggle.
--
-- Adds debug_header_enabled boolean to site_object_cache_config. When true, the
-- CP includes the field in the apply_config push payload so the drop-in emits a
-- per-request X-WPMgr-Cache debug response header. Default false (silent).
--
-- No new RLS policies needed: the column is on site_object_cache_config which
-- already has the tenant_isolation and agent dual-policy from M68.
-- The ALTER TABLE is idempotent via the DO $$ guard.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_object_cache_config'
          AND column_name  = 'debug_header_enabled'
    ) THEN
        ALTER TABLE "public"."site_object_cache_config"
            ADD COLUMN "debug_header_enabled" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;
