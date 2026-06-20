-- m76 — Security Suite Phase 1: per-site hardening config + durable ban list.
--
-- Adds two tenant-scoped tables:
--
--   site_security_hardening_config  — one row per site (PK = site_id). Typed
--     boolean / text-enum columns for every Phase-1 hardening toggle. The CP
--     is the source of truth; the agent mirrors it via the signed
--     `sync_security_hardening` command on every save.
--
--   site_security_bans  — durable per-site ban entries (IP, CIDR, user-agent).
--     Created/deleted by the operator; included in every `sync_security_hardening`
--     push so the agent gets a consistent snapshot of config + bans together.
--
-- Column design:
--   All hardening toggles default OFF so enabling is opt-in (no breaking change
--   for sites that have not touched the panel). Enum columns use text + CHECK so
--   invalid values are rejected at the DB layer without a custom type.
--
-- RLS: mirrors the m13 / m36 pattern exactly.
--   ENABLE + FORCE row-level security on both tables.
--   _tenant_isolation policy: USING + WITH CHECK via app.tenant_id GUC.
--   _agent policy: USING + WITH CHECK via app.agent = 'on'.
--   No _site_scope restrictive policy: collaborator gating is done in-app via
--   authz.RequireSiteAccess(:siteId) on the routes (m13/m36 precedent).
--
-- updated_at is set by repo SQL (now()); there is no trigger (no set_updated_at()
-- function in this schema — m36 comment).
--
-- Idempotency: every DDL statement is guarded with IF NOT EXISTS / IF NOT EXISTS
-- checks; re-running this migration is safe.

-- ===========================================================================
-- site_security_hardening_config
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_security_hardening_config" (
        -- tenant / site keys
        "site_id"                      uuid PRIMARY KEY,
        "tenant_id"                    uuid NOT NULL,

        -- WordPress hardening toggles (all default OFF — opt-in, non-breaking)

        -- Adds DISALLOW_FILE_EDIT to wp-config so the built-in template/plugin
        -- editor is not available from the wp-admin dashboard.
        "disable_file_editor"          boolean NOT NULL DEFAULT false,

        -- Three-state XML-RPC control:
        --   'on'      — XML-RPC is left as WordPress ships it (default).
        --   'off'     — all XML-RPC requests are rejected at the mu-plugin layer.
        --   'limited' — XML-RPC is allowed but system.multicall is blocked.
        "xmlrpc_mode"                  text NOT NULL DEFAULT 'on'
            CONSTRAINT "site_security_hardening_config_xmlrpc_mode_chk"
            CHECK ("xmlrpc_mode" IN ('on', 'off', 'limited')),

        -- Two-state REST API gating:
        --   'default'    — REST API is left as WordPress ships it (default).
        --   'restricted' — anonymous access to sensitive REST routes
        --                  (users, comments listing) is blocked.
        "restrict_rest_api"            text NOT NULL DEFAULT 'default'
            CONSTRAINT "site_security_hardening_config_restrict_rest_api_chk"
            CHECK ("restrict_rest_api" IN ('default', 'restricted')),

        -- Controls which credential type the WordPress login form accepts:
        --   'username' — only usernames are accepted (WP default).
        --   'email'    — only email addresses are accepted.
        --   'both'     — either username or email is accepted.
        "restrict_login_identifier"    text NOT NULL DEFAULT 'both'
            CONSTRAINT "site_security_hardening_config_login_id_chk"
            CHECK ("restrict_login_identifier" IN ('username', 'email', 'both')),

        -- Forces each user's display_name to differ from their user_login to
        -- prevent username enumeration via public author profiles.
        "force_unique_nickname"        boolean NOT NULL DEFAULT false,

        -- Returns 404 for author archive URLs when the author has zero published
        -- posts, preventing username enumeration via /?author=N probing.
        "disable_author_archive_enum"  boolean NOT NULL DEFAULT false,

        -- Adds FORCE_SSL_ADMIN to wp-config and ensures WordPress redirects
        -- the admin panel over HTTPS.
        "force_ssl"                    boolean NOT NULL DEFAULT false,

        -- Adds Options -Indexes to the .htaccess / nginx deny rule so the web
        -- server does not render directory listings.
        "disable_directory_browsing"   boolean NOT NULL DEFAULT false,

        -- Adds per-directory rules (RewriteRule / deny from all) to block direct
        -- PHP execution from the uploads, plugins, and themes directories.
        "disable_php_in_uploads"       boolean NOT NULL DEFAULT false,

        -- Adds deny rules for sensitive files: readme.html, readme.txt,
        -- wp-config.php, wp-admin/install.php, .git/, xmlrpc.php header deny.
        "protect_system_files"         boolean NOT NULL DEFAULT false,

        -- Audit / actor fields
        "updated_at"                   timestamptz NOT NULL DEFAULT now(),
        "actor_type"                   text,
        "actor_id"                     text,

        CONSTRAINT "site_security_hardening_config_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_security_hardening_config_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_hardening_config'
           AND indexname  = 'site_security_hardening_config_tenant_idx'
    ) THEN
        CREATE INDEX "site_security_hardening_config_tenant_idx"
            ON "public"."site_security_hardening_config" ("tenant_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_security_hardening_config" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_security_hardening_config" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_hardening_config'
           AND policyname = 'site_security_hardening_config_tenant_isolation'
    ) THEN
        CREATE POLICY "site_security_hardening_config_tenant_isolation"
            ON "public"."site_security_hardening_config"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker / agent path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_hardening_config'
           AND policyname = 'site_security_hardening_config_agent'
    ) THEN
        CREATE POLICY "site_security_hardening_config_agent"
            ON "public"."site_security_hardening_config"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- site_security_bans
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_security_bans" (
        "id"         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"  uuid NOT NULL,
        "site_id"    uuid NOT NULL,

        -- type: the kind of ban entry.
        --   'ip'         — exact IPv4 or IPv6 address.
        --   'range'      — IPv4 or IPv6 CIDR block.
        --   'user_agent' — user-agent string (exact match; agent may also support
        --                  substring / glob matching in a later phase).
        "type"       text NOT NULL
            CONSTRAINT "site_security_bans_type_chk"
            CHECK ("type" IN ('ip', 'range', 'user_agent')),

        -- value: the banned value. For type='ip' this is a dotted-decimal /
        -- RFC5952 address; for type='range' a valid CIDR; for type='user_agent'
        -- a plain string. Validated at write time in the service layer.
        "value"      text NOT NULL,

        -- comment: optional operator note explaining why this ban was added.
        "comment"    text NOT NULL DEFAULT '',

        -- actor tracking (operator or API key that created the ban).
        "actor_type" text NOT NULL DEFAULT '',
        "actor_id"   text NOT NULL DEFAULT '',

        "created_at" timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "site_security_bans_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_security_bans_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

-- Unique constraint: one entry per (site_id, type, value).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_bans'
           AND indexname  = 'site_security_bans_unique_entry_idx'
    ) THEN
        CREATE UNIQUE INDEX "site_security_bans_unique_entry_idx"
            ON "public"."site_security_bans" ("site_id", "type", "value");
    END IF;
END;
$$;

-- Tenant query index: serves the ban list ordered by created_at DESC.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_bans'
           AND indexname  = 'site_security_bans_tenant_site_idx'
    ) THEN
        CREATE INDEX "site_security_bans_tenant_site_idx"
            ON "public"."site_security_bans" ("tenant_id", "site_id", "created_at" DESC);
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_security_bans" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_security_bans" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_bans'
           AND policyname = 'site_security_bans_tenant_isolation'
    ) THEN
        CREATE POLICY "site_security_bans_tenant_isolation"
            ON "public"."site_security_bans"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker / agent path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_bans'
           AND policyname = 'site_security_bans_agent'
    ) THEN
        CREATE POLICY "site_security_bans_agent"
            ON "public"."site_security_bans"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
