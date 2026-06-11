-- M69 -- Object Cache config-hash drift indicator.
--
-- Adds oc_config_drift boolean to site_object_cache_config. The CP sets this
-- to true when the agent's heartbeat config_hash (the hash of the config
-- file the drop-in is actually reading) differs from computeConfigHash of the
-- stored CP config. The dashboard surfaces the drift indicator so the operator
-- knows to re-apply the config.
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
          AND column_name  = 'oc_config_drift'
    ) THEN
        ALTER TABLE "public"."site_object_cache_config"
            ADD COLUMN "oc_config_drift" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;
