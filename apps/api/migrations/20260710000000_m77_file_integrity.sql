-- m77 — Security Suite Phase 2: file-integrity monitoring tables.
--
-- Adds four tables that together power the full-filesystem diff + plugin/theme
-- integrity checks introduced in Phase 2:
--
--   site_file_baseline          — durable per-site last-good hash snapshot.
--                                 Promoted from scan_run_hashes at run completion;
--                                 the next run diffs against this table.
--
--   site_managed_files          — self-written-hash registry for paths WPMgr
--                                 itself writes (perf suite, config writers,
--                                 hardening). A path here never produces a
--                                 false-positive Added/Changed finding.
--
--   wporg_plugin_checksums      — plugin/theme checksum cache (public wp.org
--                                 endpoint). No RLS (public reference data).
--                                 md5 is in the PK because wp.org may list
--                                 multiple accepted variants per file.
--
--   wporg_plugin_checksums_meta — freshness/negative-cache sentinel per
--                                 (kind, slug, version). No RLS.
--
-- RLS on tenant-scoped tables mirrors the m76 pattern exactly:
--   ENABLE + FORCE ROW LEVEL SECURITY.
--   _tenant_isolation policy: USING + WITH CHECK via app.tenant_id GUC.
--   _agent policy:            USING + WITH CHECK via app.agent = 'on'.
--   No _site_scope restrictive policy; collaborator gating is in-app via
--   authz.RequireSiteAccess(:siteId) (m76 precedent).
--
-- updated_at is set by repo SQL (now()); no trigger exists (m36 comment).
--
-- Idempotency: every DDL statement uses IF NOT EXISTS / pg_policies checks;
-- re-running this migration is safe.

-- ===========================================================================
-- site_file_baseline — durable last-good per-site hash set (tenant-scoped)
-- ===========================================================================

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_file_baseline" (
        -- Primary key: one row per (site, relative path).
        "site_id"     uuid NOT NULL,
        "tenant_id"   uuid NOT NULL,
        "path"        text NOT NULL,     -- site-relative, forward-slash

        -- Hash captured by the agent.
        "md5"         text NOT NULL,
        "size"        bigint NOT NULL DEFAULT 0,
        "mtime"       bigint NOT NULL DEFAULT 0,
        "is_link"     boolean NOT NULL DEFAULT false,

        -- Source classification persisted with the row so readers know which
        -- authority blessed this hash (informational; not used in diff logic).
        -- 'baseline'     — promoted from a full/files scan run.
        -- 'wporg_core'   — replaced by the known-good core checksum.
        -- 'wporg_plugin' — replaced by the known-good plugin/theme checksum.
        -- 'managed'      — written by the self-managed-file registry.
        "source"      text NOT NULL DEFAULT 'baseline'
            CONSTRAINT "site_file_baseline_source_chk"
            CHECK ("source" IN ('baseline', 'wporg_core', 'wporg_plugin', 'managed')),

        -- The scan run that last promoted / updated this row.
        "updated_run" uuid NOT NULL,
        "updated_at"  timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "site_file_baseline_pkey"
            PRIMARY KEY ("site_id", "path"),

        CONSTRAINT "site_file_baseline_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_file_baseline_site_fkey"
            FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

-- Tenant query index: serves the baseline load in diff (tenant_id + site_id).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_file_baseline'
           AND indexname  = 'site_file_baseline_tenant_idx'
    ) THEN
        CREATE INDEX "site_file_baseline_tenant_idx"
            ON "public"."site_file_baseline" ("tenant_id", "site_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_file_baseline" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_file_baseline" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_file_baseline'
           AND policyname = 'site_file_baseline_tenant_isolation'
    ) THEN
        CREATE POLICY "site_file_baseline_tenant_isolation"
            ON "public"."site_file_baseline"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant worker / promotion path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_file_baseline'
           AND policyname = 'site_file_baseline_agent'
    ) THEN
        CREATE POLICY "site_file_baseline_agent"
            ON "public"."site_file_baseline"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- site_managed_files — self-written expected-hash registry (tenant-scoped)
-- ===========================================================================
--
-- When WPMgr writes a file (object-cache.php, .htaccess, minified assets,
-- wp-config blocks) the agent reports the resulting hash here so the diff
-- classifier never flags WPMgr's own writes as Changed or Added.
--
-- md5 = '' means "WPMgr-managed, suppress ALL findings for this path
-- regardless of content" — used for churning directories (e.g. cache dirs).
-- A specific md5 means "expected exactly this hash"; a different hash is
-- still a real finding (managed-file tampering).

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_managed_files" (
        "site_id"    uuid NOT NULL,
        "tenant_id"  uuid NOT NULL,
        "path"       text NOT NULL,
        "md5"        text NOT NULL DEFAULT '', -- '' = suppress all findings

        -- managed_by identifies the WPMgr subsystem that owns this path:
        --   'perf_cache'     — page-cache rules files (.htaccess, advanced-cache.php)
        --   'object_cache'   — object-cache.php drop-in
        --   'config_writer'  — wp-config.php block additions
        --   'hardening'      — security-hardening file writes
        --   'cp_command'     — generic CP-pushed write via record_managed_files
        "managed_by" text NOT NULL DEFAULT 'cp_command',
        "updated_at" timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "site_managed_files_pkey"
            PRIMARY KEY ("site_id", "path"),

        CONSTRAINT "site_managed_files_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_managed_files_site_fkey"
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
           AND tablename  = 'site_managed_files'
           AND indexname  = 'site_managed_files_tenant_idx'
    ) THEN
        CREATE INDEX "site_managed_files_tenant_idx"
            ON "public"."site_managed_files" ("tenant_id", "site_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_managed_files" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_managed_files" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_managed_files'
           AND policyname = 'site_managed_files_tenant_isolation'
    ) THEN
        CREATE POLICY "site_managed_files_tenant_isolation"
            ON "public"."site_managed_files"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_managed_files'
           AND policyname = 'site_managed_files_agent'
    ) THEN
        CREATE POLICY "site_managed_files_agent"
            ON "public"."site_managed_files"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ===========================================================================
-- wporg_plugin_checksums — plugin/theme checksum cache (no RLS, public data)
-- ===========================================================================
--
-- Mirrors wporg_core_checksums; keyed on (kind, slug, version, path, md5).
-- md5 is in the PRIMARY KEY because wp.org plugin-checksums JSON may list
-- multiple accepted md5 variants per file (line-ending / build variants).
-- Storing all variants lets the diff treat a file as known-good when its
-- actual md5 matches ANY stored variant.
--
-- kind: 'plugin' | 'theme' — shares the table; kind column disambiguates.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."wporg_plugin_checksums" (
        "kind"       text NOT NULL
            CONSTRAINT "wporg_plugin_checksums_kind_chk"
            CHECK ("kind" IN ('plugin', 'theme')),
        "slug"       text NOT NULL,
        "version"    text NOT NULL,
        "path"       text NOT NULL,   -- plugin-relative path, e.g. "akismet.php"
        "md5"        text NOT NULL,   -- one accepted md5 variant (lowercase hex)
        "fetched_at" timestamptz NOT NULL DEFAULT now(),

        CONSTRAINT "wporg_plugin_checksums_pkey"
            PRIMARY KEY ("kind", "slug", "version", "path", "md5")
    );
END;
$$;

-- Index for bulk-load by (kind, slug, version) — the common query shape.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'wporg_plugin_checksums'
           AND indexname  = 'wporg_plugin_checksums_lookup_idx'
    ) THEN
        CREATE INDEX "wporg_plugin_checksums_lookup_idx"
            ON "public"."wporg_plugin_checksums" ("kind", "slug", "version", "path");
    END IF;
END;
$$;

-- ===========================================================================
-- wporg_plugin_checksums_meta — freshness / negative-cache sentinel
-- ===========================================================================
--
-- One row per (kind, slug, version). ok=false means a fetch attempt failed
-- (e.g. 404 for a premium/unknown plugin) and is negative-cached until the
-- negativeCacheTTL expires (6h). Mirrors wporg_core_checksums_meta exactly.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."wporg_plugin_checksums_meta" (
        "kind"       text NOT NULL
            CONSTRAINT "wporg_plugin_checksums_meta_kind_chk"
            CHECK ("kind" IN ('plugin', 'theme')),
        "slug"       text NOT NULL,
        "version"    text NOT NULL,
        "fetched_at" timestamptz NOT NULL DEFAULT now(),
        "ok"         boolean NOT NULL DEFAULT true,

        CONSTRAINT "wporg_plugin_checksums_meta_pkey"
            PRIMARY KEY ("kind", "slug", "version")
    );
END;
$$;
