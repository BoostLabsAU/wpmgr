-- m32 — open self-serve signup + email verification (ADR-045 Phase 3).
-- users.status gates login (pending = unverified, cannot sign in); existing
-- users are backfilled active + verified (trusted, pre-feature).

ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "status" text NOT NULL DEFAULT 'active';
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_status_check'
    ) THEN
        ALTER TABLE "public"."users"
            ADD CONSTRAINT "users_status_check" CHECK (status IN ('active', 'pending', 'disabled'));
    END IF;
END$$;
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "email_verified_at" timestamptz;

-- Backfill: every pre-existing account is trusted + already using the product.
UPDATE "public"."users" SET email_verified_at = now() WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS "public"."email_verification_tokens" (
    "id"          uuid        NOT NULL DEFAULT gen_random_uuid(),
    "user_id"     uuid        NOT NULL,
    "token_hash"  bytea       NOT NULL,
    "expires_at"  timestamptz NOT NULL,
    "used_at"     timestamptz,
    "created_at"  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY ("id"),
    CONSTRAINT "email_verification_tokens_user_id_fkey" FOREIGN KEY ("user_id")
        REFERENCES "public"."users" ("id") ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS "email_verification_tokens_token_hash_key"
    ON "public"."email_verification_tokens" ("token_hash");
CREATE INDEX IF NOT EXISTS "email_verification_tokens_user_active_idx"
    ON "public"."email_verification_tokens" ("user_id") WHERE used_at IS NULL;

ALTER TABLE "public"."email_verification_tokens" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_verification_tokens" FORCE ROW LEVEL SECURITY;
CREATE POLICY "email_verification_tokens_agent" ON "public"."email_verification_tokens"
    USING (current_setting('app.agent', true) = 'on')
    WITH CHECK (current_setting('app.agent', true) = 'on');
