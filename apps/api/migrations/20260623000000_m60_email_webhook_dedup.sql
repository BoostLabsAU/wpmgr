-- M60 — Email webhook idempotency / replay-defence table (Phase 4a).
--
-- Stores one row per (provider, provider_event_id) pair so that webhook events
-- delivered more than once by a provider (or replayed by an attacker) are silently
-- deduplicated on INSERT rather than applied twice. The table also records the
-- resolved (tenant_id, site_id) so the same row acts as an audit trail.
--
-- NOT tenant-scoped in the strict sense — a single row covers a provider event that
-- arrived at the CP BEFORE we knew which tenant it belonged to (fan-out happens
-- after dedup). tenant_id / site_id may remain NULL for events that could not be
-- resolved. RLS uses the _agent policy only (webhooks are not operator-path; the
-- webhook handler runs as a special non-tenant context equivalent to InAgentTx).
--
-- Retention: rows older than 7 days are pruned by the EmailWebhookDedupGCWorker.
-- 7 days is intentionally shorter than any provider's maximum retry window so the
-- dedup table stays small.
--
-- All DDL is IF-NOT-EXISTS / policy-existence-guarded (idempotent).

CREATE TABLE IF NOT EXISTS "public"."email_webhook_events" (
    "id"               uuid        NOT NULL DEFAULT gen_random_uuid(),

    -- Provider-assigned stable event identifier (used for dedup).
    -- For SNS: Message.MessageId
    -- For SendGrid: event.sg_event_id
    -- For Mailgun: event.id (or derived from token)
    -- For Postmark: RecordID (cast to text)
    "provider_event_id" text       NOT NULL,
    "provider"          text       NOT NULL,

    -- Resolved fan-out target (NULL when metadata was absent / unparseable).
    "tenant_id"         uuid,
    "site_id"           uuid,

    -- Normalised email address that generated the event (lower-cased before storing).
    "email"             text,
    "event_type"        text        NOT NULL DEFAULT '',   -- hard_bounce | complaint | etc.

    -- Suppression row created for this event (NULL = no suppression row written,
    -- e.g. non-suppression event type like click/open).
    "suppression_id"    uuid,

    "created_at"        timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "email_webhook_events_pkey" PRIMARY KEY ("id")
);

-- Unique dedup constraint: one row per (provider, provider_event_id).
CREATE UNIQUE INDEX IF NOT EXISTS "email_webhook_events_dedup_idx"
    ON "public"."email_webhook_events" ("provider", "provider_event_id");

-- Pruning index: fast scan for rows older than the retention window.
CREATE INDEX IF NOT EXISTS "email_webhook_events_created_idx"
    ON "public"."email_webhook_events" ("created_at");

-- Lookup by resolved tenant (for debugging / audit).
CREATE INDEX IF NOT EXISTS "email_webhook_events_tenant_idx"
    ON "public"."email_webhook_events" ("tenant_id")
    WHERE "tenant_id" IS NOT NULL;

ALTER TABLE "public"."email_webhook_events" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."email_webhook_events" FORCE ROW LEVEL SECURITY;

-- Webhook handler runs under InAgentTx (app.agent='on'): full read/write.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_webhook_events'
          AND policyname = 'email_webhook_events_agent'
    ) THEN
        CREATE POLICY "email_webhook_events_agent" ON "public"."email_webhook_events"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- Tenant-isolation policy for future operator-facing audit reads.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'email_webhook_events'
          AND policyname = 'email_webhook_events_tenant_isolation'
    ) THEN
        CREATE POLICY "email_webhook_events_tenant_isolation" ON "public"."email_webhook_events"
            USING      (
                tenant_id IS NULL
                OR tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                tenant_id IS NULL
                OR tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
            );
    END IF;
END;
$$;
