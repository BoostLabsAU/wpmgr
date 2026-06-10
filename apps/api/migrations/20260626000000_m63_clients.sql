-- M63 — Clients Foundation (WPMgr Agency Phase 1).
--
-- Adds:
--   clients        — per-tenant client records (name, contact, company, color,
--                    logo, notes, soft-delete via archived_at).
--   sites.client_id — nullable FK column so one site can be grouped under a
--                     client (ON DELETE SET NULL keeps unassign non-destructive).
--
-- Design notes:
--   * Cardinality: 1 site ↔ AT MOST 1 client (nullable FK, no join table).
--   * Deleting a client UNASSIGNS its sites via ON DELETE SET NULL (never CASCADE).
--   * Tags remain fully separate from clients (no migration path needed).
--   * UNIQUE(id, tenant_id) on clients backs the composite FK on sites
--     (same pattern as sites_id_tenant_key in m19/site_shares).
--   * RLS mirrors m36 exactly: ENABLE + FORCE + tenant_isolation + agent.
--   * No clients_site_scope RESTRICTIVE policy — a site-scoped collaborator must
--     never enumerate the client roster; org access gated in-app via
--     RequireOrgScope + PermClientRead/PermClientManage.
--   * updated_at is set by now() in queries (no trigger — project convention).
--   * citext already available (CREATE EXTENSION IF NOT EXISTS citext in m19).
--
-- All statements are idempotent (IF NOT EXISTS + pg_policies DO-guarded).

-- ---------------------------------------------------------------------------
-- [1]  clients table
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."clients" (
    "id"            uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"     uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "name"          text        NOT NULL,
    "contact_email" citext,
    "company"       text,
    "phone"         text,
    "notes"         text,
    "color"         text,
    "logo_url"      text,
    "archived_at"   timestamptz,
    "created_at"    timestamptz NOT NULL DEFAULT now(),
    "updated_at"    timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "clients_pkey" PRIMARY KEY ("id"),

    -- Backs the composite FK on sites (prevents tenant drift, mirrors
    -- sites_id_tenant_key used by site_shares in m19).
    CONSTRAINT "clients_id_tenant_key" UNIQUE ("id", "tenant_id")
);

-- Fast tenant-scoped list + assignment lookups.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'clients'
          AND indexname = 'clients_tenant_idx'
    ) THEN
        CREATE INDEX "clients_tenant_idx" ON "public"."clients" ("tenant_id");
    END IF;
END;
$$;

ALTER TABLE "public"."clients" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."clients" FORCE ROW LEVEL SECURITY;

-- Operator / API path: scoped to the current tenant via app.tenant_id GUC.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'clients'
          AND policyname = 'clients_tenant_isolation'
    ) THEN
        CREATE POLICY "clients_tenant_isolation"
            ON "public"."clients"
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
          AND tablename  = 'clients'
          AND policyname = 'clients_agent'
    ) THEN
        CREATE POLICY "clients_agent"
            ON "public"."clients"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [2]  sites.client_id — nullable FK column
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."sites"
    ADD COLUMN IF NOT EXISTS "client_id" uuid NULL;

-- Composite FK: cross-tenant-proof (mirrors site_shares in m19).
-- client_id alone cannot reference a client belonging to a different tenant
-- because the composite (client_id, tenant_id) must exist in clients.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name        = 'sites'
          AND constraint_name   = 'sites_client_tenant_fkey'
    ) THEN
        ALTER TABLE "public"."sites"
            ADD CONSTRAINT "sites_client_tenant_fkey"
            FOREIGN KEY ("client_id", "tenant_id")
            REFERENCES "public"."clients" ("id", "tenant_id")
            ON DELETE SET NULL;
    END IF;
END;
$$;

-- Partial index on client_id: only rows that have a client assigned benefit.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'sites'
          AND indexname = 'sites_client_idx'
    ) THEN
        CREATE INDEX "sites_client_idx"
            ON "public"."sites" ("client_id")
            WHERE "client_id" IS NOT NULL;
    END IF;
END;
$$;
