-- m50 — Decouple backup-settings from backup_schedules (#188)
--
-- Creates site_backup_settings as a standalone 1:1 table keyed by site_id,
-- backfills from any existing backup_schedules rows that have non-default
-- Track-A or Track-B values (safe even if m49 staging rows exist),
-- then drops those columns from backup_schedules.
--
-- Deploy ordering: this migration MUST run before the new
-- /backup-settings/* endpoints are published and before the web
-- build that removes Track-A/B fields from the backup-schedule PUT body.
-- The GET /backup-schedule endpoint continues to work during the window
-- between migration and web deploy — it simply no longer returns the
-- moved fields (they are now absent from the Schedule struct).

-- 1. Create the new table with tenant isolation (mirrors backup_schedules).
--    tenant_id is mandatory: every settings row belongs to exactly one tenant,
--    and the RLS tenant-isolation policy enforces it at the DB layer.
CREATE TABLE site_backup_settings (
  tenant_id            uuid        NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  site_id              uuid        PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
  backup_components    jsonb       NULL,
  include_core         boolean     NOT NULL DEFAULT false,
  exclude_paths        jsonb       NULL,
  exclude_extensions   jsonb       NULL,
  exclude_file_size_mb integer     NULL CHECK (exclude_file_size_mb > 0),
  notify_on_completion text        NOT NULL DEFAULT 'never'
                         CHECK (notify_on_completion IN ('always','on_failure','never')),
  notify_recipients    jsonb       NOT NULL DEFAULT '[]'::jsonb,
  created_at           timestamptz NOT NULL DEFAULT now(),
  updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX backup_settings_tenant_id_idx ON site_backup_settings (tenant_id);

-- 2. Backfill from any staging rows in backup_schedules that carry non-default
--    Track-A or Track-B values. tenant_id is resolved via the sites FK so the
--    backfill is safe even in environments where the rows already exist.
--    ON CONFLICT DO NOTHING is idempotent in case the migration is re-run.
--    In production m49 is not yet deployed so there are no rows to protect;
--    this guard is for staging environments only.
INSERT INTO site_backup_settings (
  tenant_id,
  site_id,
  backup_components,
  include_core,
  exclude_paths,
  exclude_extensions,
  exclude_file_size_mb,
  notify_on_completion,
  notify_recipients
)
SELECT
  s.tenant_id,
  bs.site_id,
  bs.backup_components,
  bs.include_core,
  bs.exclude_paths,
  bs.exclude_extensions,
  bs.exclude_file_size_mb,
  bs.notify_on_completion,
  bs.notify_recipients
FROM backup_schedules bs
JOIN sites s ON s.id = bs.site_id
WHERE bs.backup_components    IS NOT NULL
   OR bs.include_core         = true
   OR bs.exclude_paths        IS NOT NULL
   OR bs.exclude_extensions   IS NOT NULL
   OR bs.exclude_file_size_mb IS NOT NULL
   OR bs.notify_on_completion  != 'never'
   OR bs.notify_recipients     != '[]'::jsonb
ON CONFLICT (site_id) DO NOTHING;

-- 3. Drop the Track-A and Track-B columns from backup_schedules.
--    backup_schedules retains ONLY scheduling-timing and retention columns.
ALTER TABLE backup_schedules
  DROP COLUMN notify_on_completion,
  DROP COLUMN notify_recipients,
  DROP COLUMN backup_components,
  DROP COLUMN exclude_paths,
  DROP COLUMN exclude_extensions,
  DROP COLUMN exclude_file_size_mb,
  DROP COLUMN include_core;

-- 4. Row-Level Security — mirrors the pattern on backup_schedules exactly.
--    (Hand-appended; Atlas CE cannot diff policies — ADR-002.)

-- 4a. Tenant-isolation policy: every tenant-scoped query (InTenantTx) sets
--     app.tenant_id; this policy ensures rows from other tenants are invisible
--     even if a query omits a tenant_id WHERE clause.
ALTER TABLE site_backup_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE site_backup_settings FORCE ROW LEVEL SECURITY;
CREATE POLICY "backup_settings_tenant_isolation" ON "public"."site_backup_settings"
  USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);

-- 4b. Site-scope RESTRICTIVE policy: when InScopedTenantTx activates
--     app.site_scope='on' (site-scoped outside collaborators, M5.7) this
--     policy further limits visibility to the sites in app.allowed_site_ids.
--     When app.site_scope is absent or not 'on' the USING clause is a no-op
--     tautology — regular org members are unaffected.
CREATE POLICY "backup_settings_site_scope" ON "public"."site_backup_settings"
  AS RESTRICTIVE FOR ALL
  USING (
    coalesce(current_setting('app.site_scope', true), '') <> 'on'
    OR "site_id" = ANY (
        string_to_array(
            nullif(current_setting('app.allowed_site_ids', true), ''), ','
        )::uuid[]
    )
  )
  WITH CHECK (
    coalesce(current_setting('app.site_scope', true), '') <> 'on'
    OR "site_id" = ANY (
        string_to_array(
            nullif(current_setting('app.allowed_site_ids', true), ''), ','
        )::uuid[]
    )
  );
