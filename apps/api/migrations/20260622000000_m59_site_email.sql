-- M59 — Per-site Email / SMTP Management (Phase 0 + Phase 1 foundation).
--
-- Creates three tables for the per-site email feature (ADR pending):
--   1. site_email_config  — per-site (and org-wide-default) outgoing mail config;
--      age-encrypted provider secret; nil-sentinel upsert preserves stored creds.
--   2. site_email_log     — per-site outgoing email audit trail (queried Phase 3).
--   3. email_suppression  — org-wide and per-site bounce/complaint/unsubscribe list
--      (queried Phase 4).
--
-- Design notes:
--   - site_email_config uses a surrogate `id` PK with a conditional UNIQUE index to
--     support BOTH a per-site row (site_id IS NOT NULL) and exactly ONE org-wide
--     default row per tenant (site_id IS NULL).  The partial-index approach is the
--     clean standard (no magic NULL-sentinel UUID needed in the application layer).
--   - RLS mirrors m36 EXACTLY: ENABLE + FORCE + two policies:
--       <t>_tenant_isolation  USING/WITH CHECK on app.tenant_id
--       <t>_agent             USING/WITH CHECK on app.agent='on'
--   - updated_at is set via now() in the query (no trigger — project convention).
--   - Every DDL statement is IF-NOT-EXISTS or column-existence guarded (idempotent).

-- ---------------------------------------------------------------------------
-- 1.  site_email_config
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."site_email_config" (
    -- Surrogate PK; surrogate is necessary because site_id may be NULL (org-wide
    -- default rows have no site_id).
    "id"                        uuid        NOT NULL DEFAULT gen_random_uuid(),

    -- Tenancy + site scope. tenant_id is always present; site_id is NULL for the
    -- org-wide default row and non-NULL for per-site overrides.
    "tenant_id"                 uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "site_id"                   uuid        REFERENCES sites (id) ON DELETE CASCADE,

    -- Provider slug: smtp | ses | sendgrid | mailgun | postmark (v1).
    "provider"                  text        NOT NULL DEFAULT 'smtp',

    -- Sender identity.
    "from_address"              text        NOT NULL DEFAULT '',
    "from_name"                 text        NOT NULL DEFAULT '',
    -- When true the agent overrides the WP-generated From address/name with these.
    "force_from_email"          boolean     NOT NULL DEFAULT false,
    "force_from_name"           boolean     NOT NULL DEFAULT false,
    -- When true the provider sets the Return-Path / bounce address.
    "return_path"               boolean     NOT NULL DEFAULT false,

    -- Non-secret provider config (host/port/encryption/auth for SMTP;
    -- region/domain/message_stream for API providers).
    "config"                    jsonb       NOT NULL DEFAULT '{}'::jsonb,

    -- age-encrypted provider secret (API key or SMTP password).
    -- Never returned to clients; only a secret_set: bool is surfaced.
    "provider_secret_encrypted" bytea,

    -- OAuth refresh/access tokens (Phase 3 — Gmail/Outlook; NULL in Phase 1).
    "oauth_refresh_encrypted"   bytea,
    "oauth_access_encrypted"    bytea,
    "oauth_expires_at"          timestamptz,

    -- Routing: per-FROM-address routing map (JSON object: email→connection_key),
    -- and the name of the default + optional fallback connections.
    "mappings"                  jsonb       NOT NULL DEFAULT '{}'::jsonb,
    "default_connection"        text,
    "fallback_connection"       text,

    -- Log policy (per-site opt-in overrides).
    "log_emails"                boolean     NOT NULL DEFAULT true,
    "store_body"                boolean     NOT NULL DEFAULT false,
    "retention_days"            integer     NOT NULL DEFAULT 14,

    "created_at"                timestamptz NOT NULL DEFAULT now(),
    "updated_at"                timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "site_email_config_pkey" PRIMARY KEY ("id")
);

-- Enforce: at most one org-wide-default row per tenant (site_id IS NULL) and at
-- most one per-site row per (tenant_id, site_id) pair.  Two partial unique indexes
-- are the clean idiomatic approach for this "one NULL + many non-NULL" pattern.
CREATE UNIQUE INDEX IF NOT EXISTS "site_email_config_per_site_idx"
    ON "public"."site_email_config" ("tenant_id", "site_id")
    WHERE "site_id" IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "site_email_config_org_default_idx"
    ON "public"."site_email_config" ("tenant_id")
    WHERE "site_id" IS NULL;

-- Fast tenant-scoped list (used by ListSiteEmailConfigs and RLS policy).
CREATE INDEX IF NOT EXISTS "site_email_config_tenant_idx"
    ON "public"."site_email_config" ("tenant_id");

ALTER TABLE "public"."site_email_config" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."site_email_config" FORCE ROW LEVEL SECURITY;

-- Operator / API path: tenant-scoped read/write.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_email_config'
          AND policyname = 'site_email_config_tenant_isolation'
    ) THEN
        CREATE POLICY "site_email_config_tenant_isolation" ON "public"."site_email_config"
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
          AND tablename  = 'site_email_config'
          AND policyname = 'site_email_config_agent'
    ) THEN
        CREATE POLICY "site_email_config_agent" ON "public"."site_email_config"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 2.  site_email_log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."site_email_log" (
    "id"                uuid        NOT NULL DEFAULT gen_random_uuid(),

    "tenant_id"         uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "site_id"           uuid        NOT NULL REFERENCES sites   (id) ON DELETE CASCADE,

    -- agent_seq is the agent-local monotonic counter for keyset-cursor pagination.
    -- UNIQUE (tenant_id, site_id, agent_seq) enforces idempotent ingest.
    "agent_seq"         bigint,

    "message_id"        text,
    "to_addresses"      text[]      NOT NULL DEFAULT '{}',
    "from_address"      text        NOT NULL DEFAULT '',
    "subject"           text        NOT NULL DEFAULT '',
    "provider"          text        NOT NULL DEFAULT '',

    -- status: pending | sent | failed | bounced | complained
    "status"            text        NOT NULL DEFAULT 'pending',

    "response"          jsonb       NOT NULL DEFAULT '{}'::jsonb,
    "error"             text        NOT NULL DEFAULT '',
    "retries"           integer     NOT NULL DEFAULT 0,
    "resent_count"      integer     NOT NULL DEFAULT 0,

    -- body_stored=true means body column is populated; false by default (privacy).
    "body_stored"       boolean     NOT NULL DEFAULT false,
    "body"              text,

    "created_at"        timestamptz NOT NULL DEFAULT now(),
    "updated_at"        timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "site_email_log_pkey" PRIMARY KEY ("id")
);

-- Per-site log list (primary dashboard query: keyset ORDER BY created_at DESC, id DESC).
CREATE INDEX IF NOT EXISTS "site_email_log_site_time_idx"
    ON "public"."site_email_log" ("tenant_id", "site_id", "created_at" DESC);

-- Fleet-wide cross-site log (agency dashboard).
CREATE INDEX IF NOT EXISTS "site_email_log_tenant_time_idx"
    ON "public"."site_email_log" ("tenant_id", "created_at" DESC);

-- Partial index for failed-only queries (failure trend / resend queues).
CREATE INDEX IF NOT EXISTS "site_email_log_failed_idx"
    ON "public"."site_email_log" ("tenant_id", "created_at" DESC)
    WHERE "status" = 'failed';

-- Idempotent ingest constraint: (tenant_id, site_id, agent_seq).
CREATE UNIQUE INDEX IF NOT EXISTS "site_email_log_seq_idx"
    ON "public"."site_email_log" ("tenant_id", "site_id", "agent_seq")
    WHERE "agent_seq" IS NOT NULL;

ALTER TABLE "public"."site_email_log" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."site_email_log" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'site_email_log'
          AND policyname = 'site_email_log_tenant_isolation'
    ) THEN
        CREATE POLICY "site_email_log_tenant_isolation" ON "public"."site_email_log"
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
          AND tablename  = 'site_email_log'
          AND policyname = 'site_email_log_agent'
    ) THEN
        CREATE POLICY "site_email_log_agent" ON "public"."site_email_log"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 3.  email_suppression
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."email_suppression" (
    "id"                uuid        NOT NULL DEFAULT gen_random_uuid(),

    "tenant_id"         uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    -- site_id NULL = fleet-wide suppression across the whole org/tenant.
    "site_id"           uuid        REFERENCES sites (id) ON DELETE CASCADE,

    -- email_hash is the HMAC-SHA256 of the normalised email address (lowercase)
    -- keyed with the tenant's age-derived HMAC secret — so raw emails are never
    -- stored when store_body=false. email column may be populated for opt-in body
    -- storage or when the reason requires the original (hard_bounce/complaint).
    "email_hash"        bytea       NOT NULL,
    "email"             text,

    -- reason: hard_bounce | complaint | unsubscribe | manual
    "reason"            text        NOT NULL DEFAULT 'manual',
    "provider"          text        NOT NULL DEFAULT '',
    "event_at"          timestamptz,
    "source_message_id" text,

    "created_at"        timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "email_suppression_pkey" PRIMARY KEY ("id")
);

-- One entry per (tenant, site-or-null, email_hash): partial indexes for the two
-- disjoint cases (per-site and fleet-wide).
CREATE UNIQUE INDEX IF NOT EXISTS "email_suppression_site_hash_idx"
    ON "public"."email_suppression" ("tenant_id", "site_id", "email_hash")
    WHERE "site_id" IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "email_suppression_fleet_hash_idx"
    ON "public"."email_suppression" ("tenant_id", "email_hash")
    WHERE "site_id" IS NULL;

CREATE INDEX IF NOT EXISTS "email_suppression_tenant_idx"
    ON "public"."email_suppression" ("tenant_id");

ALTER TABLE "public"."email_suppression" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_suppression" FORCE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_suppression'
          AND policyname = 'email_suppression_tenant_isolation'
    ) THEN
        CREATE POLICY "email_suppression_tenant_isolation" ON "public"."email_suppression"
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
          AND tablename  = 'email_suppression'
          AND policyname = 'email_suppression_agent'
    ) THEN
        CREATE POLICY "email_suppression_agent" ON "public"."email_suppression"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
