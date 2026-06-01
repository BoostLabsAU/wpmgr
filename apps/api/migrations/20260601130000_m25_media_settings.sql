-- M25 — Auto-optimize on upload: site_media_settings (ADR-044 Phase B).
--
-- Adds the per-site opt-in table for the "auto-optimize new uploads" feature.
-- When a WordPress attachment upload fires the agent's filter hook, the agent
-- POSTs the id(s) to POST /agent/v1/media/auto-optimize. The CP reads this
-- table to check the opt-in flag and the preferred format/quality before
-- calling the existing StartOptimize pipeline.
--
-- Table: site_media_settings
--   site_id PK — one row per site.
--   tenant_id — FK to sites/tenants; ON DELETE CASCADE so site teardown is clean.
--   auto_optimize_enabled — the operator-controlled toggle (default false = off).
--   auto_target_format — avif | webp | original (default webp).
--   auto_target_quality — lossy | lossless (default lossy).
--   created_at / updated_at — timestamptz; set by repo code (no DB trigger).
--
-- RLS mirrors site_security_config EXACTLY:
--   tenant isolation policy — operator path (InTenantTx, app.tenant_id GUC).
--   agent policy           — agent callback path (InAgentTx, app.agent GUC).
-- The table is read under BOTH GUCs:
--   - The operator GET/PUT /media/settings routes use the tenant GUC.
--   - The HandleAutoOptimize callback reads the setting under the agent GUC
--     (GetMediaSettingsAgent) as an additional defense-in-depth gate.
--
-- Idempotency: every statement is guarded with IF NOT EXISTS / pg_policies
-- checks so running this migration twice is safe. No triggers (mirrors m23).

-- ---------------------------------------------------------------------------
-- site_media_settings
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_media_settings" (
        "tenant_id"              uuid NOT NULL,
        "site_id"                uuid NOT NULL,
        -- auto_optimize_enabled: opt-in toggle. Off by default — no existing
        -- behaviour changes until an operator explicitly enables it.
        "auto_optimize_enabled"  boolean NOT NULL DEFAULT false,
        -- auto_target_format: avif | webp | original.
        "auto_target_format"     text NOT NULL DEFAULT 'webp',
        -- auto_target_quality: lossy | lossless.
        "auto_target_quality"    text NOT NULL DEFAULT 'lossy',
        "created_at"             timestamptz NOT NULL DEFAULT now(),
        "updated_at"             timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY ("site_id"),
        CONSTRAINT "site_media_settings_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_media_settings_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public'
          AND tablename  = 'site_media_settings'
          AND indexname  = 'site_media_settings_tenant_idx'
    ) THEN
        CREATE INDEX "site_media_settings_tenant_idx"
            ON "public"."site_media_settings" ("tenant_id");
    END IF;
END;
$$;

-- Row-Level Security for site_media_settings.
DO $$
BEGIN
    ALTER TABLE "public"."site_media_settings" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_media_settings" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_media_settings'
          AND policyname = 'site_media_settings_tenant_isolation'
    ) THEN
        CREATE POLICY "site_media_settings_tenant_isolation" ON "public"."site_media_settings"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_media_settings'
          AND policyname = 'site_media_settings_agent'
    ) THEN
        CREATE POLICY "site_media_settings_agent" ON "public"."site_media_settings"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
