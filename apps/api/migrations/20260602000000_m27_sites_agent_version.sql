-- M27 — record the current WPMgr agent plugin version on each site.
--
-- Surfaced in the sites table ("Agent" column). The agent reports its plugin
-- version (WPMGR_AGENT_VERSION) on every metadata push; the CP exact-sets it on
-- the site row. Additive on the wire — older agents simply don't send it and the
-- column stays ''. Default '' keeps existing rows valid until their next sync.
--
-- updates_available (derived from the existing sites.components JSONB) and
-- last_backup (joined from backup_snapshots) need NO column — only agent_version
-- is persisted here.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS — safe to run twice.

DO $$
BEGIN
    ALTER TABLE "public"."sites"
        ADD COLUMN IF NOT EXISTS "agent_version" text NOT NULL DEFAULT '';
END;
$$;
