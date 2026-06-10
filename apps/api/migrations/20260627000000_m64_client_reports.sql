-- M64 — White-label Client Reports (WPMgr Agency Phase 2).
--
-- Adds:
--   clients.timezone           — client-level timezone governs report send time
--                               (decision 6; validated app-side with time.LoadLocation).
--   report_schedules           — singleton schedule per client: cadence, recipients,
--                               section flags, branding text, powered-by toggle.
--   generated_reports          — one row per rendered report: status, period,
--                               presigned blob keys, data snapshot.
--
-- Design notes:
--   * Timezone column on clients (no CHECK — IANA names validated app-side,
--     time.LoadLocation failure → UTC fallback per notify.go:258-261).
--   * report_schedules has NO timezone column — send-time tz = clients.timezone
--     via JOIN in the due-scan. One row per client via UNIQUE(client_id).
--   * report_schedules.next_run_at NULL means disabled/never-scheduled.
--   * generated_reports.schedule_id is nullable (ON DELETE SET NULL) so
--     deleting/recreating a schedule never destroys report history.
--   * generated_reports.client_id ON DELETE CASCADE — reports are only reachable
--     through the client detail page; rows about a deleted client are dead weight.
--     The DELETE endpoint deletes blobs best-effort; a lifecycle rule can sweep
--     stragglers later (html/pdf blobs are KB-MB scale, presigned URLs expire <=7d).
--   * Composite FK (client_id, tenant_id) REFERENCES clients(id, tenant_id) on
--     both new tables — cross-tenant-proof (mirrors sites_client_tenant_fkey m63).
--   * RLS mirrors m36 / m63 exactly: ENABLE+FORCE + tenant_isolation + agent.
--     NO *_site_scope RESTRICTIVE policy — org-level data gated in-app via
--     RequireOrgScope + PermClientRead/PermClientManage.
--   * Agent policy REQUIRED: the due-scanner and River GenerateWorker run cross-
--     tenant under InAgentTx, exactly like email's ListDueDigests (email/repo.go:310-323).
--   * updated_at set by now() in queries — no trigger (project convention, m36 comment).
--   * All statements are idempotent (IF NOT EXISTS + pg_policies DO-guarded).

-- ---------------------------------------------------------------------------
-- [1]  clients.timezone
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."clients"
    ADD COLUMN IF NOT EXISTS "timezone" text NOT NULL DEFAULT 'UTC';

-- ---------------------------------------------------------------------------
-- [2]  report_schedules
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."report_schedules" (
    "id"                 uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"          uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "client_id"          uuid        NOT NULL,
    "enabled"            boolean     NOT NULL DEFAULT false,
    "cadence"            text        NOT NULL DEFAULT 'monthly'
        CONSTRAINT "report_schedules_cadence" CHECK (cadence IN ('weekly','monthly')),
    -- weekly: 0=Sunday..6=Saturday; monthly: 1-28 (m62 semantics verbatim,
    -- migrations/20260625000000_m62_email_v1_completion.sql:146-152)
    "send_day"           integer     NOT NULL DEFAULT 1
        CONSTRAINT "report_schedules_send_day" CHECK (send_day BETWEEN 0 AND 28),
    "send_hour"          integer     NOT NULL DEFAULT 8
        CONSTRAINT "report_schedules_send_hour" CHECK (send_hour BETWEEN 0 AND 23),
    -- JSONB array of email strings; max 10 enforced in the service (cap directive).
    "recipients"         jsonb       NOT NULL DEFAULT '[]'::jsonb,
    -- Section on/off flags: {"overview":bool,"uptime":bool,"backups":bool,
    -- "updates":bool,"performance":bool,"email":bool}. Missing key = true.
    "sections"           jsonb       NOT NULL DEFAULT '{}'::jsonb,
    "intro_text"         text        NOT NULL DEFAULT '',
    "closing_text"       text        NOT NULL DEFAULT '',
    -- Decision 5: powered-by footer ON by default, FREE toggle to remove.
    "powered_by_removed" boolean     NOT NULL DEFAULT false,
    "next_run_at"        timestamptz,
    "last_run_at"        timestamptz,
    "created_at"         timestamptz NOT NULL DEFAULT now(),
    "updated_at"         timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "report_schedules_pkey" PRIMARY KEY ("id"),

    -- One schedule per client.
    CONSTRAINT "report_schedules_client_key" UNIQUE ("client_id"),

    -- Composite FK: cross-tenant-proof (m63 pattern, 20260626000000_m63_clients.sql).
    -- ON DELETE CASCADE: a schedule is meaningless without its client; deleting a
    -- client silently stops (removes) its report schedule. This deliberately
    -- DIFFERS from sites.client_id ON DELETE SET NULL (decision 4) because sites
    -- outlive clients but a per-client schedule does not.
    CONSTRAINT "report_schedules_client_tenant_fkey"
        FOREIGN KEY ("client_id","tenant_id")
        REFERENCES "public"."clients" ("id","tenant_id") ON DELETE CASCADE
);

-- Fast tenant-scoped list + due-scan lookups.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'report_schedules'
          AND indexname = 'report_schedules_tenant_idx'
    ) THEN
        CREATE INDEX "report_schedules_tenant_idx"
            ON "public"."report_schedules" ("tenant_id");
    END IF;
END;
$$;

-- Partial due-scan index: only rows that need scanning (enabled + has next_run_at).
-- Mirrors email_notify_settings_due_idx (m62:166-169).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'report_schedules'
          AND indexname = 'report_schedules_due_idx'
    ) THEN
        CREATE INDEX "report_schedules_due_idx"
            ON "public"."report_schedules" ("next_run_at")
            WHERE "enabled";
    END IF;
END;
$$;

ALTER TABLE "public"."report_schedules" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."report_schedules" FORCE ROW LEVEL SECURITY;

-- Operator / API path: scoped to the current tenant via app.tenant_id GUC.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'report_schedules'
          AND policyname = 'report_schedules_tenant_isolation'
    ) THEN
        CREATE POLICY "report_schedules_tenant_isolation"
            ON "public"."report_schedules"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / cross-tenant worker path (app.agent = 'on').
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'report_schedules'
          AND policyname = 'report_schedules_agent'
    ) THEN
        CREATE POLICY "report_schedules_agent"
            ON "public"."report_schedules"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [3]  generated_reports
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."generated_reports" (
    "id"             uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"      uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "client_id"      uuid        NOT NULL,
    -- NULL = on-demand. SET NULL (not CASCADE): toggling/recreating a schedule
    -- must never destroy report history.
    "schedule_id"    uuid        REFERENCES "public"."report_schedules" ("id") ON DELETE SET NULL,
    "period_start"   timestamptz NOT NULL,
    "period_end"     timestamptz NOT NULL,
    "status"         text        NOT NULL DEFAULT 'pending'
        CONSTRAINT "generated_reports_status"
        CHECK (status IN ('pending','generating','completed','failed')),
    "data_snapshot"  jsonb       NOT NULL DEFAULT '{}'::jsonb,
    "html_blob_key"  text        NOT NULL DEFAULT '',
    "pdf_blob_key"   text        NOT NULL DEFAULT '',
    "error"          text        NOT NULL DEFAULT '',
    "created_at"     timestamptz NOT NULL DEFAULT now(),
    "completed_at"   timestamptz,

    CONSTRAINT "generated_reports_pkey" PRIMARY KEY ("id"),

    -- ON DELETE CASCADE: reports are only reachable through the client detail page;
    -- rows about a deleted client are dead UI weight. Cost accepted + documented:
    -- client-delete orphans the html/pdf blobs (presigned URLs expire <=7d; objects
    -- are KB-MB scale). The DELETE endpoint deletes blobs best-effort; a lifecycle
    -- rule can sweep stragglers later.
    CONSTRAINT "generated_reports_client_tenant_fkey"
        FOREIGN KEY ("client_id","tenant_id")
        REFERENCES "public"."clients" ("id","tenant_id") ON DELETE CASCADE
);

-- Keyset cursor + client-scoped list index.
-- List queries MUST use composite predicate (created_at, id) < ($cursor_at, $cursor_id)
-- because batch inserts share created_at and a bare compare skips co-timestamped rows.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'generated_reports'
          AND indexname = 'generated_reports_list_idx'
    ) THEN
        CREATE INDEX "generated_reports_list_idx"
            ON "public"."generated_reports" ("tenant_id", "client_id", "created_at" DESC, "id" DESC);
    END IF;
END;
$$;

ALTER TABLE "public"."generated_reports" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."generated_reports" FORCE ROW LEVEL SECURITY;

-- Operator / API path.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'generated_reports'
          AND policyname = 'generated_reports_tenant_isolation'
    ) THEN
        CREATE POLICY "generated_reports_tenant_isolation"
            ON "public"."generated_reports"
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
          AND tablename  = 'generated_reports'
          AND policyname = 'generated_reports_agent'
    ) THEN
        CREATE POLICY "generated_reports_agent"
            ON "public"."generated_reports"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
