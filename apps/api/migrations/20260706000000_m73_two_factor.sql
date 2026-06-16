-- m73: two-factor authentication foundation (ADR-056)
-- users columns, six new tables; all RLS under app.agent='on' (pre-tenant auth flow).
-- Mirror pattern: password_reset_tokens (m31) + email_verification_tokens.
-- All statements are idempotent (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS).

-- -----------------------------------------------------------------------
-- users: columns for TOTP state + replay protection
-- -----------------------------------------------------------------------
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "two_factor_enabled"    bool   NOT NULL DEFAULT false;
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "totp_secret_encrypted" bytea;
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "totp_confirmed_at"     timestamptz;
-- totp_last_step tracks the last accepted TOTP time-step (30-second window
-- counter). On each successful TOTP verification the current step is stored
-- here; subsequent verifications in the same step are rejected as replays.
-- NULL means no TOTP code has ever been accepted for this user.
-- The column was added in Phase 2 (service layer) alongside the replay-
-- protection logic; it is absent from the Phase 1 schema because Phase 1
-- had no verify logic yet. The column name is consistent with RFC 6238
-- terminology: a "step" is floor(unix_timestamp / period).
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "totp_last_step" bigint;

-- Provisional TOTP secret columns: hold the unconfirmed secret between
-- BeginRegistration and FinishRegistration. The secret is cleared as soon as
-- the user proves possession (FinishRegistration) or the TTL expires.
-- Storing as columns on users avoids a separate table + RLS policy; the
-- per-user uniqueness is already guaranteed by the PK.
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "totp_provisional_secret_encrypted" bytea;
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "totp_provisional_expires_at" timestamptz;

-- -----------------------------------------------------------------------
-- user_recovery_codes
-- 10 single-use account-level backup codes (argon2id hash; never plaintext).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."user_recovery_codes" (
    "id"         uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"    uuid        NOT NULL,
    "code_hash"  text        NOT NULL,
    "used_at"    timestamptz,
    "created_at" timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "user_recovery_codes_user_id_fkey"
        FOREIGN KEY ("user_id") REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "user_recovery_codes_user_idx"
    ON "public"."user_recovery_codes" ("user_id");

ALTER TABLE "public"."user_recovery_codes" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."user_recovery_codes" FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'user_recovery_codes'
          AND policyname = 'user_recovery_codes_agent'
    ) THEN
        CREATE POLICY "user_recovery_codes_agent" ON "public"."user_recovery_codes"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END $$;

-- -----------------------------------------------------------------------
-- webauthn_credentials
-- Registered passkeys / FIDO2 hardware keys per user.
-- sign_count enforces authenticator-clone detection (must increase on assertion).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."webauthn_credentials" (
    "id"               uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"          uuid        NOT NULL,
    "credential_id"    bytea       NOT NULL,
    "public_key"       bytea       NOT NULL,
    "attestation_type" text        NOT NULL DEFAULT '',
    "aaguid"           bytea       NOT NULL DEFAULT ''::bytea,
    "sign_count"       bigint      NOT NULL DEFAULT 0,
    "transports"       text[],
    "name"             text        NOT NULL DEFAULT '',
    "backup_eligible"  bool        NOT NULL DEFAULT false,
    "backup_state"     bool        NOT NULL DEFAULT false,
    "created_at"       timestamptz NOT NULL DEFAULT now(),
    "last_used_at"     timestamptz,
    PRIMARY KEY ("id"),
    UNIQUE ("credential_id"),
    CONSTRAINT "webauthn_credentials_user_id_fkey"
        FOREIGN KEY ("user_id") REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "webauthn_credentials_user_idx"
    ON "public"."webauthn_credentials" ("user_id");

ALTER TABLE "public"."webauthn_credentials" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."webauthn_credentials" FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'webauthn_credentials'
          AND policyname = 'webauthn_credentials_agent'
    ) THEN
        CREATE POLICY "webauthn_credentials_agent" ON "public"."webauthn_credentials"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END $$;

-- -----------------------------------------------------------------------
-- two_factor_challenges
-- Transient, factor-agnostic login challenges (consumed on verify or expiry).
-- kind = 'login'; 'recover' may be added in Phase 2.
-- webauthn_session holds go-webauthn SessionData JSON (nil for TOTP).
-- attempts tracks failed verifications; challenge locked after 5 failed attempts.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."two_factor_challenges" (
    "id"               uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"          uuid        NOT NULL,
    "challenge_nonce"  text        NOT NULL,
    "kind"             text        NOT NULL DEFAULT 'login',
    "webauthn_session" jsonb,
    "expires_at"       timestamptz NOT NULL,
    "used_at"          timestamptz,
    "attempts"         integer     NOT NULL DEFAULT 0,
    "requested_ip"     inet,
    "created_at"       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "two_factor_challenges_user_id_fkey"
        FOREIGN KEY ("user_id") REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS "two_factor_challenges_nonce_key"
    ON "public"."two_factor_challenges" ("challenge_nonce") WHERE used_at IS NULL;

CREATE INDEX IF NOT EXISTS "two_factor_challenges_user_active_idx"
    ON "public"."two_factor_challenges" ("user_id")
    WHERE used_at IS NULL;

ALTER TABLE "public"."two_factor_challenges" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."two_factor_challenges" FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'two_factor_challenges'
          AND policyname = 'two_factor_challenges_agent'
    ) THEN
        CREATE POLICY "two_factor_challenges_agent" ON "public"."two_factor_challenges"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END $$;

-- -----------------------------------------------------------------------
-- webauthn_registration_sessions
-- Stash go-webauthn SessionData between BeginRegistration and FinishRegistration.
-- TTL'd (expires_at). Cleaned up on FinishRegistration or expiry GC.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."webauthn_registration_sessions" (
    "id"         uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"    uuid        NOT NULL,
    "session"    jsonb       NOT NULL,
    "expires_at" timestamptz NOT NULL,
    "created_at" timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "webauthn_registration_sessions_user_id_fkey"
        FOREIGN KEY ("user_id") REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "webauthn_registration_sessions_user_idx"
    ON "public"."webauthn_registration_sessions" ("user_id");

ALTER TABLE "public"."webauthn_registration_sessions" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."webauthn_registration_sessions" FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'webauthn_registration_sessions'
          AND policyname = 'webauthn_registration_sessions_agent'
    ) THEN
        CREATE POLICY "webauthn_registration_sessions_agent" ON "public"."webauthn_registration_sessions"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END $$;

-- -----------------------------------------------------------------------
-- trusted_devices
-- "Remember this device" entries per user (30-day window by default).
-- token_hash: SHA-256 (hex) of the opaque 32-byte random cookie value.
--   The raw token is set as the cookie; only the SHA-256 digest is stored
--   so a DB dump cannot be used to forge device cookies (analogous to the
--   password_reset_tokens.token_hash design). argon2id is NOT used here
--   because trusted-device lookup is on a fast path (every 2FA login with
--   a returning device) and SHA-256 over a high-entropy (256-bit) random
--   token provides the same security guarantee without the argon2id cost.
-- challenge_nonce in two_factor_challenges: 256-bit random hex string;
--   not currently used as a second secret in the verification flow (the
--   challenge UUID is the bearer credential). Retained as vestigial for
--   a potential future HMAC-binding enhancement.
-- revoked_at: soft-delete (NULL = active).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "public"."trusted_devices" (
    "id"           uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"      uuid        NOT NULL,
    "token_hash"   text        NOT NULL,
    "label"        text        NOT NULL DEFAULT '',
    "user_agent"   text        NOT NULL DEFAULT '',
    "ip"           inet,
    "created_at"   timestamptz NOT NULL DEFAULT now(),
    "expires_at"   timestamptz NOT NULL,
    "last_used_at" timestamptz,
    "revoked_at"   timestamptz,
    PRIMARY KEY ("id"),
    UNIQUE ("token_hash"),
    CONSTRAINT "trusted_devices_user_id_fkey"
        FOREIGN KEY ("user_id") REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "trusted_devices_user_idx"
    ON "public"."trusted_devices" ("user_id");

ALTER TABLE "public"."trusted_devices" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."trusted_devices" FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'trusted_devices'
          AND policyname = 'trusted_devices_agent'
    ) THEN
        CREATE POLICY "trusted_devices_agent" ON "public"."trusted_devices"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END $$;
