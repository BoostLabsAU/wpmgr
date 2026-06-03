-- m30 — UI-configured instance SMTP (smtp_settings) + transactional email audit
-- ledger (email_log). ADR-045 Phase 1.
--
-- smtp_settings is a single instance-level row (singleton UNIQUE) holding the
-- SMTP relay an owner configures in the UI; the relay password is age-encrypted
-- in password_enc (internal/cryptbox, same pattern as site destinations) and is
-- never echoed by the API. Both tables are reached under app.agent='on' (the
-- settings handler + mailer use Pool.InAgentTx); HTTP-layer PermSMTPManage is
-- the real access control for the settings row.

CREATE TABLE IF NOT EXISTS "public"."smtp_settings" (
    "id"                 uuid        NOT NULL DEFAULT gen_random_uuid(),
    "singleton"          boolean     NOT NULL DEFAULT true,
    "enabled"            boolean     NOT NULL DEFAULT false,
    "host"               text        NOT NULL DEFAULT '',
    "port"               integer     NOT NULL DEFAULT 587,
    "username"           text        NOT NULL DEFAULT '',
    "password_enc"       bytea,
    "from_address"       text        NOT NULL DEFAULT '',
    "from_name"          text        NOT NULL DEFAULT '',
    "tls_mode"           text        NOT NULL DEFAULT 'starttls',
    "allow_insecure_tls" boolean     NOT NULL DEFAULT false,
    "updated_by"         uuid,
    "created_at"         timestamptz NOT NULL DEFAULT now(),
    "updated_at"         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "smtp_settings_tls_mode_check" CHECK (tls_mode IN ('starttls', 'tls', 'none')),
    CONSTRAINT "smtp_settings_updated_by_fkey" FOREIGN KEY ("updated_by")
        REFERENCES "public"."users" ("id") ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS "smtp_settings_singleton_key"
    ON "public"."smtp_settings" ("singleton");

ALTER TABLE "public"."smtp_settings" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."smtp_settings" FORCE ROW LEVEL SECURITY;

CREATE POLICY "smtp_settings_agent" ON "public"."smtp_settings"
    USING (current_setting('app.agent', true) = 'on')
    WITH CHECK (current_setting('app.agent', true) = 'on');

CREATE TABLE IF NOT EXISTS "public"."email_log" (
    "id"           uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"    uuid,
    "to_addresses" text[]      NOT NULL,
    "subject"      text        NOT NULL,
    "template"     text        NOT NULL,
    "status"       text        NOT NULL DEFAULT 'pending',
    "error"        text,
    "attempts"     integer     NOT NULL DEFAULT 0,
    "created_at"   timestamptz NOT NULL DEFAULT now(),
    "sent_at"      timestamptz,
    PRIMARY KEY ("id"),
    CONSTRAINT "email_log_status_check" CHECK (status IN ('pending', 'sent', 'failed')),
    CONSTRAINT "email_log_tenant_id_fkey" FOREIGN KEY ("tenant_id")
        REFERENCES "public"."tenants" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "email_log_tenant_created_idx"
    ON "public"."email_log" ("tenant_id", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "email_log_status_failed_idx"
    ON "public"."email_log" ("status") WHERE status = 'failed';

ALTER TABLE "public"."email_log" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_log" FORCE ROW LEVEL SECURITY;

CREATE POLICY "email_log_tenant_isolation" ON "public"."email_log"
    USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
    WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);

CREATE POLICY "email_log_agent" ON "public"."email_log"
    USING (current_setting('app.agent', true) = 'on')
    WITH CHECK (current_setting('app.agent', true) = 'on');
