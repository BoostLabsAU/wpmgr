-- M61 — Email webhook security hardening (Phase 4a security review).
--
-- Three concerns fixed in one migration:
--
-- 1. MUST-FIX: cross-tenant webhook forgery.
--    The old design verified the request against an instance-wide key and read
--    (tenant, site) from attacker-controllable event metadata.  Fix: add a
--    per-config-row opaque route token and per-config-row webhook signing key.
--    The webhook URL becomes /webhooks/email/{provider}/{routeToken}.  The
--    routeToken resolves the config row → tenant without trusting event metadata.
--
--    New columns on site_email_config:
--      webhook_route_token_hash  bytea  — SHA-256 of the random route token
--                                          (token itself never stored at rest).
--      webhook_signing_key_enc   bytea  — age-encrypted per-provider key
--                                          (SendGrid ECDSA public key / Mailgun
--                                          HMAC signing key / Postmark secret).
--      ses_topic_arns            text[] — allowlist of SNS TopicArns this config
--                                          accepts; NULL = SES not used.
--      webhook_signing_key_set   boolean (view column — managed in app layer).
--
-- 2. SHOULD-FIX #2: email_webhook_events.email stores plaintext recipient PII.
--    Replace with email_hash (sha-256 of lower-cased email) so dedup rows are
--    hashed at rest. The old email column is dropped.  The dedup INSERT writes
--    email_hash instead.
--
-- 3. SHOULD-FIX #3: MarkEmailLogBounced was not site-scoped, so a forged
--    message_id from another site in the same tenant could flip a log row.
--    The query fix is in site_email.sql (@site_id added); this migration adds
--    nothing to the DB for that fix (it is a query-layer change only).
--
-- All DDL is IF-NOT-EXISTS / column-existence guarded (idempotent).

-- ---------------------------------------------------------------------------
-- 1.  Add webhook security columns to site_email_config
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    -- webhook_route_token_hash: SHA-256(random 32-byte token) for constant-time
    -- lookup.  Unique across the table so each URL resolves exactly one config row.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_email_config'
          AND column_name  = 'webhook_route_token_hash'
    ) THEN
        ALTER TABLE site_email_config
            ADD COLUMN webhook_route_token_hash bytea;
    END IF;

    -- webhook_signing_key_enc: age-encrypted per-provider webhook signing key.
    -- For SendGrid: ECDSA public key PEM
    -- For Mailgun:  HMAC webhook signing key (NOT the Private API Key)
    -- For Postmark: per-server secret (embedded in the URL path)
    -- For SES:      unused (SES uses cert-pinned RSA; ses_topic_arns is the guard)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_email_config'
          AND column_name  = 'webhook_signing_key_enc'
    ) THEN
        ALTER TABLE site_email_config
            ADD COLUMN webhook_signing_key_enc bytea;
    END IF;

    -- ses_topic_arns: allowlist of SNS TopicArns this config row accepts.
    -- For SES users: at least one ARN must be present; events arriving on any
    -- other TopicArn are rejected even if the SNS signature is valid.
    -- NULL for non-SES providers.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_email_config'
          AND column_name  = 'ses_topic_arns'
    ) THEN
        ALTER TABLE site_email_config
            ADD COLUMN ses_topic_arns text[];
    END IF;
END;
$$;

-- Unique index on webhook_route_token_hash so a lookup always resolves exactly
-- one config row (constant-time lookup by hash; no sequential scan).
CREATE UNIQUE INDEX IF NOT EXISTS site_email_config_route_token_hash_idx
    ON site_email_config (webhook_route_token_hash)
    WHERE webhook_route_token_hash IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 2.  Replace email_webhook_events.email with email_hash
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    -- Add email_hash column if absent.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'email_webhook_events'
          AND column_name  = 'email_hash'
    ) THEN
        ALTER TABLE email_webhook_events
            ADD COLUMN email_hash bytea;
    END IF;

    -- Drop plaintext email column if still present.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'email_webhook_events'
          AND column_name  = 'email'
    ) THEN
        ALTER TABLE email_webhook_events DROP COLUMN email;
    END IF;
END;
$$;
