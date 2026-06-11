-- m66 — Client portal (Clients Phase 3).
--
-- Adds:
--   client_members            — portal user roster per client (user_id has NO
--                               tenant membership; access resolved at auth time).
--   sites_client_read         — PERMISSIVE SELECT-only policy on sites so the
--                               auth-time lookup (InUserTx, app.user_id only)
--                               can expand a client membership to site IDs.
--                               Mirrors m22 sites_shared_read. Still AND-gated
--                               by the RESTRICTIVE sites_site_scope policy.
--   invitations scope='client' — reuse the m19 tokenized invite flow for
--                               portal users (client_id column + CHECK widen).
--
-- Deleting a client CASCADEs client_members and pending client invitations:
-- portal access is revoked instantly (locked decision).

-- ---------------------------------------------------------------------------
-- [1] client_members
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."client_members" (
    "id"         uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"  uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "client_id"  uuid        NOT NULL,
    "user_id"    uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    "invited_by" uuid        NULL     REFERENCES users (id) ON DELETE SET NULL,
    "created_at" timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "client_members_pkey" PRIMARY KEY ("id"),

    -- One roster row per (client, user); upserts target this pair.
    CONSTRAINT "client_members_client_user_key" UNIQUE ("client_id", "user_id"),

    -- Composite FK: cross-tenant-proof (mirrors sites_client_tenant_fkey in
    -- m63). ON DELETE CASCADE: deleting a client revokes portal access.
    CONSTRAINT "client_members_client_tenant_fkey"
        FOREIGN KEY ("client_id", "tenant_id")
        REFERENCES "public"."clients" ("id", "tenant_id")
        ON DELETE CASCADE
);

-- Auth-time lookup: (user_id, tenant_id) on every portal request.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND indexname = 'client_members_user_tenant_idx'
    ) THEN
        CREATE INDEX "client_members_user_tenant_idx"
            ON "public"."client_members" ("user_id", "tenant_id");
    END IF;
END;
$$;

-- Roster listing per client (agency UI).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND indexname = 'client_members_client_idx'
    ) THEN
        CREATE INDEX "client_members_client_idx"
            ON "public"."client_members" ("client_id");
    END IF;
END;
$$;

ALTER TABLE "public"."client_members" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."client_members" FORCE ROW LEVEL SECURITY;

-- Operator / API path (m63 verbatim shape).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_tenant_isolation'
    ) THEN
        CREATE POLICY "client_members_tenant_isolation"
            ON "public"."client_members"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / worker path (m63 verbatim shape).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_agent'
    ) THEN
        CREATE POLICY "client_members_agent"
            ON "public"."client_members"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- Self-read: the auth-time lookup runs under InUserTx where ONLY app.user_id
-- is set (no app.tenant_id), so tenant_isolation cannot match. Mirrors
-- site_shares_self_read (m19). SELECT-only.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_self_read'
    ) THEN
        CREATE POLICY "client_members_self_read"
            ON "public"."client_members"
            FOR SELECT
            USING (user_id = nullif(current_setting('app.user_id', true), '')::uuid);
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [2] sites_client_read — PERMISSIVE SELECT-only policy on sites
-- ---------------------------------------------------------------------------
-- Purpose: under InUserTx (auth-time expansion of client membership to site
-- IDs) there is no app.tenant_id, so sites_tenant_isolation hides every row.
-- This policy lets a client member read site rows of their own client only.
-- It is OR-combined with the permissive policies but AND-gated by the
-- RESTRICTIVE sites_site_scope policy (m19), so it cannot widen a site-scoped
-- read. archived_at gate: members of an archived client lose access instantly.
-- NOTE: the EXISTS subquery against client_members is itself subject to that
-- table's RLS; client_members_self_read (user_id = app.user_id) is exactly
-- what admits the needed rows.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'sites'
          AND policyname = 'sites_client_read'
    ) THEN
        CREATE POLICY "sites_client_read" ON "public"."sites"
            FOR SELECT
            USING (EXISTS (
                SELECT 1
                FROM client_members cm
                JOIN clients cl
                  ON cl.id = cm.client_id AND cl.tenant_id = cm.tenant_id
                WHERE cm.client_id = sites.client_id
                  AND cm.tenant_id = sites.tenant_id
                  AND cm.user_id   = nullif(current_setting('app.user_id', true), '')::uuid
                  AND cl.archived_at IS NULL
            ));
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [3] invitations — scope='client'
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."invitations"
    ADD COLUMN IF NOT EXISTS "client_id" uuid NULL;

-- Widen the inline scope CHECK (auto-named invitations_scope_check by m19).
ALTER TABLE "public"."invitations" DROP CONSTRAINT IF EXISTS "invitations_scope_check";
ALTER TABLE "public"."invitations"
    ADD CONSTRAINT "invitations_scope_check" CHECK (scope IN ('org', 'site', 'client'));

-- Composite FK, ON DELETE CASCADE: deleting a client kills pending invites.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name        = 'invitations'
          AND constraint_name   = 'invitations_client_tenant_fkey'
    ) THEN
        ALTER TABLE "public"."invitations"
            ADD CONSTRAINT "invitations_client_tenant_fkey"
            FOREIGN KEY ("client_id", "tenant_id")
            REFERENCES "public"."clients" ("id", "tenant_id")
            ON DELETE CASCADE;
    END IF;
END;
$$;

-- Pending-invite listing per client (mirrors invitations_site_id_idx).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'invitations'
          AND indexname = 'invitations_client_id_idx'
    ) THEN
        CREATE INDEX "invitations_client_id_idx"
            ON "public"."invitations" ("client_id", "created_at" DESC)
            WHERE scope = 'client';
    END IF;
END;
$$;
