-- M62 — Per-site Email v1 Completion (v0.36.0).
--
-- Merges the DDL from four design areas into one migration:
--   Area 2 — site_email_connection child table (multi-connection + failover)
--   Area 3 — attachments + connection_key columns on site_email_log
--   Area 4 — email_notify_settings and email_alert_state (alerts + digest)
--
-- All statements are idempotent (IF NOT EXISTS + pg_policies DO-guarded).
-- RLS mirrors m36/m59 EXACTLY: ENABLE + FORCE + two policies per table:
--   <t>_tenant_isolation  USING/WITH CHECK on app.tenant_id GUC
--   <t>_agent             USING/WITH CHECK on app.agent='on'
-- updated_at is set via now() in queries (no trigger — project convention).
-- Connection secret keys are stripped before wp-option writes; per-connection
-- secrets live in wpmgr_agent_email_conn_secrets option (AES-256-GCM JSON map).

-- ---------------------------------------------------------------------------
-- [1]  site_email_connection  — per-connection provider config child table
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."site_email_connection" (
    -- Surrogate PK.
    "id"                        uuid        NOT NULL DEFAULT gen_random_uuid(),

    -- Tenancy + parent config row.
    "tenant_id"                 uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "config_id"                 uuid        NOT NULL REFERENCES site_email_config (id) ON DELETE CASCADE,

    -- Operator-chosen slug key: ^[a-z0-9][a-z0-9_-]{0,31}$
    -- 'default' is RESERVED for the primary (the site_email_config row itself).
    "connection_key"            text        NOT NULL,

    -- Provider slug (smtp | ses | sendgrid | mailgun | postmark).
    "provider"                  text        NOT NULL DEFAULT 'smtp',

    -- Optional per-connection sender identity overrides.
    "from_address"              text        NOT NULL DEFAULT '',
    "from_name"                 text        NOT NULL DEFAULT '',

    -- Non-secret provider config (host/port/encryption/region/domain_name etc.).
    "config"                    jsonb       NOT NULL DEFAULT '{}'::jsonb,

    -- age-encrypted per-connection secret (API key or SMTP password).
    -- Never returned to clients; only secret_set: bool is surfaced.
    "provider_secret_encrypted" bytea,

    "created_at"                timestamptz NOT NULL DEFAULT now(),
    "updated_at"                timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "site_email_connection_pkey" PRIMARY KEY ("id"),

    -- Slug format: lowercase alphanumeric + hyphens/underscores, 1-32 chars.
    -- 'default' reserved: the primary row always owns that key.
    CONSTRAINT "site_email_connection_key_check"
        CHECK (connection_key ~ '^[a-z0-9][a-z0-9_-]{0,31}$' AND connection_key <> 'default')
);

-- Uniqueness: one connection_key per config row.
CREATE UNIQUE INDEX IF NOT EXISTS "site_email_connection_cfg_key_idx"
    ON "public"."site_email_connection" ("config_id", "connection_key");

-- Fast tenant-scoped list (RLS policy + list queries).
CREATE INDEX IF NOT EXISTS "site_email_connection_tenant_idx"
    ON "public"."site_email_connection" ("tenant_id");

ALTER TABLE "public"."site_email_connection" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."site_email_connection" FORCE ROW LEVEL SECURITY;

-- Operator / API path.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_email_connection'
          AND policyname = 'site_email_connection_tenant_isolation'
    ) THEN
        CREATE POLICY "site_email_connection_tenant_isolation"
            ON "public"."site_email_connection"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / cross-tenant worker path.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_email_connection'
          AND policyname = 'site_email_connection_agent'
    ) THEN
        CREATE POLICY "site_email_connection_agent"
            ON "public"."site_email_connection"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [2]  site_email_log  — add connection_key column
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."site_email_log"
    ADD COLUMN IF NOT EXISTS "connection_key" text NOT NULL DEFAULT '';

-- ---------------------------------------------------------------------------
-- [3]  site_email_log  — add attachments column
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."site_email_log"
    ADD COLUMN IF NOT EXISTS "attachments" jsonb NOT NULL DEFAULT '[]'::jsonb;

-- No new indexes on site_email_log: m59 row-level policies already cover the
-- new columns (column-level ADD is covered by table-level RLS automatically).

-- ---------------------------------------------------------------------------
-- [4]  email_notify_settings  — org-level alert + digest preferences
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."email_notify_settings" (
    -- One row per tenant (tenant_id IS the PK — org-level, not per-site).
    "tenant_id"             uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,

    -- Master kill-switch.
    "enabled"               boolean     NOT NULL DEFAULT false,

    -- Recipients: JSONB array of email strings, max 20 per application logic.
    "recipients"            jsonb       NOT NULL DEFAULT '[]'::jsonb,

    -- Per-failure alert (agent-ingested status=failed only).
    "alert_on_failure"      boolean     NOT NULL DEFAULT true,
    -- Minimum minutes between consecutive failure alerts for the same site.
    -- Range [15, 1440] (15 min – 24 h); default 60 min.
    "alert_throttle_minutes" integer    NOT NULL DEFAULT 60
        CONSTRAINT "email_notify_settings_throttle_range"
        CHECK (alert_throttle_minutes BETWEEN 15 AND 1440),

    -- Hourly digest scheduler.
    "digest_enabled"        boolean     NOT NULL DEFAULT false,
    "digest_cadence"        text        NOT NULL DEFAULT 'weekly'
        CONSTRAINT "email_notify_settings_digest_cadence"
        CHECK (digest_cadence IN ('weekly', 'monthly')),
    -- For weekly: 0=Sunday … 6=Saturday.  For monthly: 1-28.
    "digest_day"            integer     NOT NULL DEFAULT 1
        CONSTRAINT "email_notify_settings_digest_day"
        CHECK (digest_day BETWEEN 0 AND 28),
    "digest_hour"           integer     NOT NULL DEFAULT 8
        CONSTRAINT "email_notify_settings_digest_hour"
        CHECK (digest_hour BETWEEN 0 AND 23),
    "timezone"              text        NOT NULL DEFAULT 'UTC',

    -- Next scheduled digest timestamp (computed by the service on PUT).
    -- NULL = never scheduled (digest_enabled = false or no tz loaded).
    "next_digest_at"        timestamptz,

    "created_at"            timestamptz NOT NULL DEFAULT now(),
    "updated_at"            timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "email_notify_settings_pkey" PRIMARY KEY ("tenant_id")
);

-- Partial index: only rows due for a digest need to be scanned by the hourly worker.
CREATE INDEX IF NOT EXISTS "email_notify_settings_due_idx"
    ON "public"."email_notify_settings" ("next_digest_at")
    WHERE "digest_enabled" AND "enabled";

ALTER TABLE "public"."email_notify_settings" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_notify_settings" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_notify_settings'
          AND policyname = 'email_notify_settings_tenant_isolation'
    ) THEN
        CREATE POLICY "email_notify_settings_tenant_isolation"
            ON "public"."email_notify_settings"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_notify_settings'
          AND policyname = 'email_notify_settings_agent'
    ) THEN
        CREATE POLICY "email_notify_settings_agent"
            ON "public"."email_notify_settings"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [5]  email_alert_state  — per-site durable alert throttle state
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."email_alert_state" (
    -- Composite PK: one row per (tenant, site).
    "tenant_id"             uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "site_id"               uuid        NOT NULL REFERENCES sites  (id) ON DELETE CASCADE,

    -- Timestamp of the last alert email sent for this site.
    -- NULL = no alert has been sent yet.
    "last_alert_at"         timestamptz,

    -- Failures accumulated since the last alert was sent (reset on claim).
    "failures_since_alert"  bigint      NOT NULL DEFAULT 0,

    "updated_at"            timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "email_alert_state_pkey" PRIMARY KEY ("tenant_id", "site_id")
);

ALTER TABLE "public"."email_alert_state" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_alert_state" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_alert_state'
          AND policyname = 'email_alert_state_tenant_isolation'
    ) THEN
        CREATE POLICY "email_alert_state_tenant_isolation"
            ON "public"."email_alert_state"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_alert_state'
          AND policyname = 'email_alert_state_agent'
    ) THEN
        CREATE POLICY "email_alert_state_agent"
            ON "public"."email_alert_state"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
