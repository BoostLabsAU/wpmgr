-- m80 — Instance-level settings store.
--
-- Adds a generic key/value table for INSTANCE-GLOBAL (non-tenant-scoped) settings
-- that require encrypted-at-rest storage. The first consumer is the Wordfence
-- Intelligence API key, which moves from env-only to UI-configurable via the
-- superadmin area.
--
-- Design rationale for no RLS:
--   This table has NO tenant_id column — it is intentionally instance-global.
--   RLS policies gate by tenant_id or app.agent; with neither tenant column nor
--   the tenant GUC path, the only sensible RLS policy would be app.agent='on',
--   mirroring smtp_settings. We therefore ENABLE + FORCE RLS and add a single
--   _agent policy, exactly matching the smtp_settings precedent (m30). The real
--   access control is the HTTP-layer requireSuperadmin middleware; the agent RLS
--   policy is the defence-in-depth DB layer.
--
-- updated_at is set in SQL (now()); no trigger (m36 comment convention).
--
-- Idempotency: every DDL uses IF NOT EXISTS or pg_policies guards; re-running
-- this migration is safe.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."instance_settings" (
        "key"        text PRIMARY KEY,
        "value_enc"  bytea,            -- age-encrypted ciphertext; NULL = unset
        "updated_at" timestamptz NOT NULL DEFAULT now()
    );
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."instance_settings" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."instance_settings" FORCE ROW LEVEL SECURITY;
END;
$$;

-- Instance-global infra row: readable/writable only under app.agent='on'
-- (Pool.InAgentTx). HTTP-layer requireSuperadmin gating is the real access
-- control. Mirrors the smtp_settings_agent policy (m30).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'instance_settings'
           AND policyname = 'instance_settings_agent'
    ) THEN
        CREATE POLICY "instance_settings_agent"
            ON "public"."instance_settings"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
