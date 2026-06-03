-- m31 — password reset + session invalidation (ADR-045 Phase 2).
-- users.password_changed_at backs the reject-stale-sessions check in the
-- Authenticator; password_reset_tokens holds single-use, TTL'd, sha256-hashed
-- reset links consumed under app.agent='on'.

ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "password_changed_at" timestamptz;

CREATE TABLE IF NOT EXISTS "public"."password_reset_tokens" (
    "id"           uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"      uuid        NOT NULL,
    "token_hash"   bytea       NOT NULL,
    "expires_at"   timestamptz NOT NULL,
    "used_at"      timestamptz,
    "attempts"     integer     NOT NULL DEFAULT 0,
    "requested_ip" inet,
    "created_at"   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "password_reset_tokens_user_id_fkey" FOREIGN KEY ("user_id")
        REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS "password_reset_tokens_token_hash_key"
    ON "public"."password_reset_tokens" ("token_hash");
CREATE INDEX IF NOT EXISTS "password_reset_tokens_user_active_idx"
    ON "public"."password_reset_tokens" ("user_id") WHERE used_at IS NULL;

ALTER TABLE "public"."password_reset_tokens" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."password_reset_tokens" FORCE ROW LEVEL SECURITY;

CREATE POLICY "password_reset_tokens_agent" ON "public"."password_reset_tokens"
    USING (current_setting('app.agent', true) = 'on')
    WITH CHECK (current_setting('app.agent', true) = 'on');
