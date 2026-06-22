-- m82 — File Manager P1: per-site opt-in flag + download transfer bookkeeping.
--
-- Adds two tenant-scoped tables:
--
--   site_file_manager  — one row per site (PK = site_id). Holds the per-site
--     opt-in flag (default OFF) and the optional root-jail override.  The CP
--     is the source of truth; the agent reads the flag on every signed command.
--
--   file_transfers     — short-lived download/upload transfer records.  Created
--     when the CP mints presigned URLs for a staged file download; GC'd on
--     expiry. Mirrors the backup_snapshots RLS pattern.
--
-- RLS: mirrors the m76 / m78 security tables exactly:
--   ENABLE + FORCE row-level security on both tables.
--   _tenant_isolation policy: USING + WITH CHECK via app.tenant_id GUC.
--   _agent policy:            USING + WITH CHECK via app.agent = 'on'.
--   No _site_scope restrictive policy: collaborator gating is done in-app via
--   authz.RequireSiteAccess(:siteId) on the routes.
--
-- updated_at is set by repo SQL (now()); there is no trigger (no
-- set_updated_at() function in this schema — m36 comment).
--
-- Idempotency: every DDL statement is guarded with IF NOT EXISTS checks;
-- re-running this migration is safe.

-- ===========================================================================
-- site_file_manager
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_file_manager" (
        -- tenant / site keys
        "site_id"        uuid PRIMARY KEY,
        "tenant_id"      uuid NOT NULL,

        -- Per-site opt-in flag. Default OFF — the feature must be explicitly
        -- enabled by an owner or admin before any file_* command is signed.
        "files_enabled"  boolean NOT NULL DEFAULT false,

        -- Optional root-jail override. Empty string means the agent uses its
        -- own configured default (ABSPATH or the narrow configured subtree).
        -- When non-empty, the CP passes this to the agent on every signed
        -- command so the agent can narrow the accessible tree further.
        "root_jail"      text NOT NULL DEFAULT '',

        "created_at"     timestamptz NOT NULL DEFAULT now(),
        "updated_at"     timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "site_file_manager_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_file_manager_site_fkey"
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
           AND tablename  = 'site_file_manager'
           AND indexname  = 'site_file_manager_tenant_idx'
    ) THEN
        CREATE INDEX "site_file_manager_tenant_idx"
            ON "public"."site_file_manager" ("tenant_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_file_manager" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_file_manager" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_file_manager'
           AND policyname = 'site_file_manager_tenant_isolation'
    ) THEN
        CREATE POLICY "site_file_manager_tenant_isolation"
            ON "public"."site_file_manager"
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
           AND tablename  = 'site_file_manager'
           AND policyname = 'site_file_manager_agent'
    ) THEN
        CREATE POLICY "site_file_manager_agent"
            ON "public"."site_file_manager"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- file_transfers
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."file_transfers" (
        -- identity
        "id"           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"    uuid NOT NULL,
        "site_id"      uuid NOT NULL,

        -- direction: 'download' (P1) or 'upload' (P2, not yet implemented)
        "direction"    text NOT NULL
            CONSTRAINT "file_transfers_direction_chk"
            CHECK ("direction" IN ('download', 'upload')),

        -- rel_path: the site-relative path that was staged for transfer.
        "rel_path"     text NOT NULL,

        -- status lifecycle: staged → active → done | failed
        "status"       text NOT NULL DEFAULT 'done'
            CONSTRAINT "file_transfers_status_chk"
            CHECK ("status" IN ('staged', 'active', 'done', 'failed')),

        -- object_key: tenant-namespaced S3 staging key prefix,
        -- e.g. file-transfers/<tenant>/<transfer-id>.
        "object_key"   text NOT NULL DEFAULT '',

        -- size / chunks: bytes staged and number of S3 parts.
        "size_bytes"   bigint NOT NULL DEFAULT 0,
        "chunk_count"  integer NOT NULL DEFAULT 0,

        -- created_by: the user who initiated the transfer (uuid.Nil for system).
        "created_by"   uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',

        "created_at"   timestamptz NOT NULL DEFAULT now(),

        -- expires_at: after this time the staged object may be GC'd.
        -- The CP should clean up short-lived staging objects after TTL (≤5 min).
        "expires_at"   timestamptz NOT NULL,

        CONSTRAINT "file_transfers_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "file_transfers_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

-- Index: tenant + site + created_at DESC (for listing recent transfers).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'file_transfers'
           AND indexname  = 'file_transfers_tenant_site_idx'
    ) THEN
        CREATE INDEX "file_transfers_tenant_site_idx"
            ON "public"."file_transfers" ("tenant_id", "site_id", "created_at" DESC, "id" DESC);
    END IF;
END;
$$;

-- Index: GC sweep — find expired rows regardless of tenant.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'file_transfers'
           AND indexname  = 'file_transfers_expires_at_idx'
    ) THEN
        CREATE INDEX "file_transfers_expires_at_idx"
            ON "public"."file_transfers" ("expires_at")
            WHERE "status" IN ('staged', 'done');
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."file_transfers" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."file_transfers" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'file_transfers'
           AND policyname = 'file_transfers_tenant_isolation'
    ) THEN
        CREATE POLICY "file_transfers_tenant_isolation"
            ON "public"."file_transfers"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker path — GC sweep runs as agent)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'file_transfers'
           AND policyname = 'file_transfers_agent'
    ) THEN
        CREATE POLICY "file_transfers_agent"
            ON "public"."file_transfers"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
