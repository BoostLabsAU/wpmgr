-- m78 — Security Suite Phase 3: per-site user 2FA + password policy.
--
-- Adds three tables:
--
--   site_security_policy       — one row per site (PK = site_id). Typed columns
--     for every Phase-3 policy knob (2FA, password, hide-backend). The CP is the
--     source of truth; the agent mirrors it via the signed `sync_security_policy`
--     command on every save. All knobs default OFF so enabling is opt-in.
--
--   site_security_policy_groups — per-role policy overrides. One row per
--     (site_id, role). Each row may override a subset of the site-level knobs.
--     Membership is resolved by WP role on the site; the CP stores only the
--     group→override mapping.
--
--   hibp_breach_cache          — global CP-side cache for the HIBP Pwned
--     Passwords range API (prefix → SUFFIX:COUNT body). This is public breach
--     data with no tenant association. No RLS; written only by the CP on cache
--     miss. ~30-day TTL.
--
-- Column design:
--   All 2FA / password / hide-backend toggles default OFF (non-breaking; opt-in).
--   text[] for role/method lists; int with CHECK ranges for score/count/day fields.
--   password_min_zxcvbn_score CHECK 0-4 mirrors the zxcvbn score range.
--
-- RLS mirrors the m76 pattern exactly:
--   ENABLE + FORCE on site_security_policy + site_security_policy_groups.
--   _tenant_isolation policy: USING + WITH CHECK via app.tenant_id GUC.
--   _agent policy: USING + WITH CHECK via app.agent = 'on'.
--   hibp_breach_cache has no RLS (public data, no tenant association).
--
-- updated_at is set in repo SQL (now()); there is no trigger.
--
-- Idempotency: every DDL statement is guarded with IF NOT EXISTS checks;
-- re-running this migration is safe.

-- ===========================================================================
-- site_security_policy
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_security_policy" (
        -- tenant / site keys
        "site_id"                         uuid PRIMARY KEY,
        "tenant_id"                       uuid NOT NULL,

        -- ---------------------------------------------------------------
        -- Two-factor authentication policy knobs (all default OFF)
        -- ---------------------------------------------------------------

        -- Master switch for the site-user 2FA subsystem. When false, all 2FA
        -- enforcement is inert regardless of other knobs.
        "two_factor_enabled"              boolean NOT NULL DEFAULT false,

        -- Allowed 2FA providers for this site. The agent enforces that a user
        -- may only enroll methods in this set. Default: all three methods allowed.
        "two_factor_methods"              text[] NOT NULL DEFAULT '{totp,email,backup}',

        -- WP roles that must use 2FA. An empty array means 2FA is optional for
        -- all roles (the master switch still controls whether any prompting occurs).
        "two_factor_required_roles"       text[] NOT NULL DEFAULT '{}',

        -- Number of allowed logins before a required-but-unenrolled user is
        -- forced into the enrollment onboarding interstitial. 0 = force immediately.
        "two_factor_grace_logins"         int NOT NULL DEFAULT 3
            CONSTRAINT "site_security_policy_grace_logins_chk"
            CHECK ("two_factor_grace_logins" >= 0 AND "two_factor_grace_logins" <= 100),

        -- Trusted-device TTL in days. 0 = remember-device feature is disabled.
        "two_factor_remember_device_days" int NOT NULL DEFAULT 30
            CONSTRAINT "site_security_policy_remember_device_days_chk"
            CHECK ("two_factor_remember_device_days" >= 0 AND "two_factor_remember_device_days" <= 365),

        -- When true, reject password-based XML-RPC requests for any user who has
        -- 2FA configured. XML-RPC has no second-factor challenge channel.
        "block_xmlrpc_for_2fa_users"      boolean NOT NULL DEFAULT true,

        -- ---------------------------------------------------------------
        -- Password policy knobs (all default OFF / 0)
        -- ---------------------------------------------------------------

        -- Minimum zxcvbn score required on password set / change / reset.
        -- 0 = disabled; 1-4 = score threshold (4 = very strong).
        "password_min_zxcvbn_score"       int NOT NULL DEFAULT 0
            CONSTRAINT "site_security_policy_zxcvbn_score_chk"
            CHECK ("password_min_zxcvbn_score" >= 0 AND "password_min_zxcvbn_score" <= 4),

        -- WP roles the strength rule applies to. Empty array = applies to all roles.
        "password_min_zxcvbn_roles"       text[] NOT NULL DEFAULT '{}',

        -- When true, reject passwords whose SHA-1 5-char prefix appears in the
        -- HIBP Pwned Passwords corpus (checked via CP proxy, fail-open).
        "password_block_compromised"      boolean NOT NULL DEFAULT false,

        -- Number of previous password hashes to retain for reuse detection.
        -- 0 = reuse blocking is disabled.
        "password_reuse_block_count"      int NOT NULL DEFAULT 0
            CONSTRAINT "site_security_policy_reuse_block_count_chk"
            CHECK ("password_reuse_block_count" >= 0 AND "password_reuse_block_count" <= 50),

        -- Force a password change after this many days since the last change.
        -- 0 = expiry is disabled.
        "password_max_age_days"           int NOT NULL DEFAULT 0
            CONSTRAINT "site_security_policy_max_age_days_chk"
            CHECK ("password_max_age_days" >= 0 AND "password_max_age_days" <= 3650),

        -- WP roles the expiry rule applies to. Empty array = applies to all roles.
        "password_expiry_roles"           text[] NOT NULL DEFAULT '{}',

        -- ---------------------------------------------------------------
        -- Hide-backend (secret login slug) policy knobs
        -- ---------------------------------------------------------------

        -- Master switch for the secret login slug feature.
        "hide_backend_enabled"            boolean NOT NULL DEFAULT false,

        -- Secret login slug (e.g. "my-login"). Validated ^[a-z0-9-]{4,64}$ in the
        -- service layer before storing. Empty string = no slug configured.
        "hide_backend_slug"               text NOT NULL DEFAULT '',

        -- Where to redirect logged-out visitors who hit the canonical wp-login /
        -- wp-admin paths when hide_backend_enabled is true. Empty = 404.
        "hide_backend_redirect"           text NOT NULL DEFAULT '',

        -- ---------------------------------------------------------------
        -- Audit fields
        -- ---------------------------------------------------------------
        "updated_at"  timestamptz NOT NULL DEFAULT now(),
        "actor_type"  text,
        "actor_id"    text,

        CONSTRAINT "site_security_policy_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_security_policy_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

-- Tenant query index.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy'
           AND indexname  = 'site_security_policy_tenant_idx'
    ) THEN
        CREATE INDEX "site_security_policy_tenant_idx"
            ON "public"."site_security_policy" ("tenant_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_security_policy" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_security_policy" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy'
           AND policyname = 'site_security_policy_tenant_isolation'
    ) THEN
        CREATE POLICY "site_security_policy_tenant_isolation"
            ON "public"."site_security_policy"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker / agent callback path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy'
           AND policyname = 'site_security_policy_agent'
    ) THEN
        CREATE POLICY "site_security_policy_agent"
            ON "public"."site_security_policy"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- site_security_policy_groups
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_security_policy_groups" (
        "id"               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"        uuid NOT NULL,
        "site_id"          uuid NOT NULL,

        -- WP role slug this group override applies to (e.g. "administrator").
        "role"             text NOT NULL,

        -- Nullable override columns. NULL means "inherit from site-level policy".
        "require_2fa"      boolean,
        "allowed_methods"  text[],
        "min_zxcvbn_score" int
            CONSTRAINT "site_security_policy_groups_zxcvbn_score_chk"
            CHECK ("min_zxcvbn_score" IS NULL OR ("min_zxcvbn_score" >= 0 AND "min_zxcvbn_score" <= 4)),
        "block_compromised" boolean,
        "max_age_days"     int
            CONSTRAINT "site_security_policy_groups_max_age_days_chk"
            CHECK ("max_age_days" IS NULL OR ("max_age_days" >= 0 AND "max_age_days" <= 3650)),

        "created_at"       timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "site_security_policy_groups_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_security_policy_groups_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

-- Unique: one row per (site_id, role).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy_groups'
           AND indexname  = 'site_security_policy_groups_site_role_idx'
    ) THEN
        CREATE UNIQUE INDEX "site_security_policy_groups_site_role_idx"
            ON "public"."site_security_policy_groups" ("site_id", "role");
    END IF;
END;
$$;

-- Tenant + site list index.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy_groups'
           AND indexname  = 'site_security_policy_groups_tenant_site_idx'
    ) THEN
        CREATE INDEX "site_security_policy_groups_tenant_site_idx"
            ON "public"."site_security_policy_groups" ("tenant_id", "site_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_security_policy_groups" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_security_policy_groups" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy_groups'
           AND policyname = 'site_security_policy_groups_tenant_isolation'
    ) THEN
        CREATE POLICY "site_security_policy_groups_tenant_isolation"
            ON "public"."site_security_policy_groups"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker / agent callback path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_security_policy_groups'
           AND policyname = 'site_security_policy_groups_agent'
    ) THEN
        CREATE POLICY "site_security_policy_groups_agent"
            ON "public"."site_security_policy_groups"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- hibp_breach_cache
-- ===========================================================================
-- Global CP-side cache for HIBP Pwned Passwords range responses. This table
-- holds public breach data (not tenant-scoped) so it carries NO row-level
-- security. The app role reads/writes it directly (no GUC required).
--
-- prefix: exactly 5 uppercase hex characters (the first 5 of a SHA-1 hash).
-- body:   the raw "SUFFIX:COUNT\n..." response from the HIBP range API.
-- fetched_at: when this prefix was last fetched; used for TTL expiry (~30 days).

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."hibp_breach_cache" (
        "prefix"     char(5) PRIMARY KEY,
        "body"       text    NOT NULL,
        "fetched_at" timestamptz NOT NULL DEFAULT now()
    );
END;
$$;

-- Index on fetched_at for TTL-based cleanup queries.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'hibp_breach_cache'
           AND indexname  = 'hibp_breach_cache_fetched_at_idx'
    ) THEN
        CREATE INDEX "hibp_breach_cache_fetched_at_idx"
            ON "public"."hibp_breach_cache" ("fetched_at");
    END IF;
END;
$$;
