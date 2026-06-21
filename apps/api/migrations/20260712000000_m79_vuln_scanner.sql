-- m79 — Security Suite Phase 4: vulnerability scanner.
--
-- Adds three tables that power the Wordfence Intelligence feed ingester and
-- the per-site vulnerability findings store:
--
--   wordfence_vuln_feed        — global public cache of vulnerability records
--                                ingested from the Wordfence Intelligence V3
--                                Scanner feed. No RLS (not tenant-scoped;
--                                public reference data, same rationale as
--                                wporg_plugin_checksums and hibp_breach_cache).
--
--   wordfence_vuln_software    — denormalized per-software index into the feed
--                                (one row per software[] entry per vuln). No
--                                RLS. Serves fast (kind, slug) lookups when
--                                matching a site's installed inventory.
--
--   wordfence_vuln_feed_meta   — single-row sentinel: freshness timestamp,
--                                feed-level attribution notices (Defiant
--                                copyright + license text, MITRE notice), and
--                                last_error for self-host diagnostics. No RLS.
--
--   site_vulnerabilities       — per-site matched findings (tenant-scoped).
--                                RLS mirrors the m76/m77 pattern exactly:
--                                ENABLE + FORCE ROW LEVEL SECURITY;
--                                _tenant_isolation (USING + WITH CHECK via
--                                app.tenant_id GUC); _agent policy (USING +
--                                WITH CHECK via app.agent = 'on').
--                                No _site_scope restrictive policy; collaborator
--                                gating stays in-app via RequireSiteAccess.
--
-- Attribution obligations (Wordfence Intelligence ToS, last updated 2026-01-26):
--   The Defiant copyright/license text is stored once per feed snapshot in
--   wordfence_vuln_feed_meta.defiant_notice / .defiant_license and surfaced in
--   the UI footer on any vulnerability view.
--   The MITRE copyright notice is stored in wordfence_vuln_feed_meta.mitre_notice
--   and rendered on any finding row that carries a CVE identifier.
--   Both are written by the ingester from the copyrights block present in any
--   feed record; they are attribution snapshots, not per-row duplicates.
--
-- updated_at is set by repo SQL (now()); there is no trigger (m36 comment).
--
-- Idempotency: every DDL statement uses IF NOT EXISTS / pg_policies guards;
-- re-running this migration is safe.

-- ===========================================================================
-- wordfence_vuln_feed — global public vulnerability record cache
-- ===========================================================================
--
-- No RLS. This table holds public reference data from the Wordfence
-- Intelligence feed. The application role reads and writes it directly via the
-- InAgentTx helper (app.agent='on') for the feed ingester, and reads it via
-- bare pool queries when matching site inventory. No tenant association.
--
-- vuln_id is the Wordfence-assigned UUID string (the key in the feed's root
-- JSON object). title/cve/cvss_score/cvss_rating/references come from the
-- Scanner or Production endpoint. raw stores the full record for audit and
-- future enrichment without requiring a schema migration.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."wordfence_vuln_feed" (
        "vuln_id"       text PRIMARY KEY,            -- Wordfence UUID (root key)
        "title"         text NOT NULL DEFAULT '',
        "cve"           text,                        -- nullable; Production-only
        "cve_link"      text,
        "cvss_score"    numeric(3,1),                -- nullable; Production-only
        "cvss_rating"   text,                        -- None/Low/Medium/High/Critical
        "cwe"           jsonb,                       -- {id,name,description} nullable
        "informational" boolean NOT NULL DEFAULT false,
        "references"    jsonb NOT NULL DEFAULT '[]', -- attribution link-back array
        "published"     timestamptz,
        "updated"       timestamptz,
        "raw"           jsonb NOT NULL DEFAULT '{}', -- full record inc. copyrights
        "created_at"    timestamptz NOT NULL DEFAULT now()
    );
END;
$$;

-- ===========================================================================
-- wordfence_vuln_software — per-software index (one row per software[] entry)
-- ===========================================================================
--
-- No RLS. Exploded from each feed record's software[] array. Serves the
-- (kind, slug) lookup when matching a site's installed inventory against the
-- feed. Cascade-deletes when the parent vuln_id is pruned.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."wordfence_vuln_software" (
        "vuln_id"           text NOT NULL
            REFERENCES "public"."wordfence_vuln_feed" ("vuln_id")
            ON DELETE CASCADE,
        "kind"              text NOT NULL,    -- 'core' | 'plugin' | 'theme'
        "slug"              text NOT NULL,
        "affected_versions" jsonb NOT NULL,   -- array of range objects (range test input)
        "patched"           boolean NOT NULL DEFAULT false,
        "patched_versions"  jsonb NOT NULL DEFAULT '[]',

        CONSTRAINT "wordfence_vuln_software_pkey"
            PRIMARY KEY ("vuln_id", "kind", "slug")
    );
END;
$$;

-- Index for fast (kind, slug) lookup during site inventory matching.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'wordfence_vuln_software'
           AND indexname  = 'idx_wf_vuln_software_lookup'
    ) THEN
        CREATE INDEX "idx_wf_vuln_software_lookup"
            ON "public"."wordfence_vuln_software" ("kind", "slug");
    END IF;
END;
$$;

-- ===========================================================================
-- wordfence_vuln_feed_meta — freshness + attribution sentinel (single row)
-- ===========================================================================
--
-- No RLS. One row (id=1 enforced by CHECK). Stores the last successful fetch
-- timestamp, record count, the Defiant copyright/license text (attribution
-- obligation, surfaced in the UI), the MITRE notice (displayed per CVE row),
-- and the last error string for self-host diagnostics.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."wordfence_vuln_feed_meta" (
        "id"             integer PRIMARY KEY DEFAULT 1
            CONSTRAINT "wordfence_vuln_feed_meta_singleton_chk" CHECK ("id" = 1),
        "fetched_at"     timestamptz,
        "ok"             boolean NOT NULL DEFAULT false,
        "record_count"   integer NOT NULL DEFAULT 0,
        "defiant_notice" text,   -- copyrights.defiant.notice (display in UI footer)
        "defiant_license" text,  -- copyrights.defiant.license (full text stored once)
        "mitre_notice"   text,   -- copyrights.mitre.notice (display on CVE rows)
        "last_error"     text
    );

    -- Ensure the sentinel row exists so the ingester can UPDATE rather than
    -- INSERT-or-UPDATE (simpler code, avoids a race on first run).
    INSERT INTO "public"."wordfence_vuln_feed_meta" ("id") VALUES (1)
    ON CONFLICT ("id") DO NOTHING;
END;
$$;

-- ===========================================================================
-- site_vulnerabilities — per-site matched vulnerability findings (tenant-RLS)
-- ===========================================================================
--
-- One row per (site_id, vuln_id, kind, slug) — the unique finding identity.
-- Findings are upserted on each rescan: last_seen is refreshed on match;
-- findings no longer matched are resolved (resolved_at = now(), status =
-- 'resolved'). Dismissed findings (status = 'dismissed') survive rescans until
-- the underlying item's version changes, at which point they are re-evaluated.
--
-- vuln_id is a soft reference (no FK) to wordfence_vuln_feed because the feed
-- cache may be pruned independently of findings. The finding row carries its
-- own title/cve/severity snapshot so it remains meaningful even if the feed
-- record is later purged.
--
-- RLS: ENABLE + FORCE. Two permissive policies (both must allow):
--   _tenant_isolation — operator / API path via app.tenant_id GUC.
--   _agent            — cross-tenant worker / RescanAll path via app.agent GUC.
-- No _site_scope restrictive policy; collaborator gating is via RequireSiteAccess.

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_vulnerabilities" (
        "id"                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"         uuid NOT NULL,
        "site_id"           uuid NOT NULL
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,

        -- Feed reference (soft; no FK so feed prune does not cascade to findings).
        "vuln_id"           text NOT NULL,
        "kind"              text NOT NULL,    -- 'core' | 'plugin' | 'theme'
        "slug"              text NOT NULL,
        "name"              text NOT NULL,    -- human-readable component name

        -- Snapshot of installed state at detection time.
        "installed_version" text NOT NULL,

        -- Remediation target derived from patched_versions / range upper bound.
        -- Null when no patched version is known (unpatched 0-day).
        "fixed_version"     text,

        -- Severity bucket: critical | high | medium | low.
        -- Derived from cvss_score or cvss_rating; stored for fast severity-sorted
        -- list queries and the fleet rollup count.
        "severity"          text NOT NULL
            CONSTRAINT "site_vulnerabilities_severity_chk"
            CHECK ("severity" IN ('critical', 'high', 'medium', 'low')),

        "cvss_score"        numeric(3,1),
        "cve"               text,
        "title"             text NOT NULL,

        -- Lifecycle status.
        "status"            text NOT NULL DEFAULT 'open'
            CONSTRAINT "site_vulnerabilities_status_chk"
            CHECK ("status" IN ('open', 'dismissed', 'resolved')),

        "first_seen"        timestamptz NOT NULL DEFAULT now(),
        "last_seen"         timestamptz NOT NULL DEFAULT now(),
        "resolved_at"       timestamptz,
        "dismissed_at"      timestamptz,
        "dismissed_by"      uuid,    -- user ID who dismissed; null for system dismiss

        CONSTRAINT "site_vulnerabilities_tenant_fkey"
            FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,

        -- Unique finding per (site, vuln, component) — a site can only have one
        -- active finding per (vuln_id, kind, slug) combination.
        CONSTRAINT "site_vulnerabilities_uq"
            UNIQUE ("site_id", "vuln_id", "kind", "slug")
    );
END;
$$;

-- Index for per-site open-findings queries (the primary list endpoint).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_vulnerabilities'
           AND indexname  = 'idx_site_vuln_site_open'
    ) THEN
        CREATE INDEX "idx_site_vuln_site_open"
            ON "public"."site_vulnerabilities" ("site_id")
            WHERE "status" = 'open';
    END IF;
END;
$$;

-- Index for tenant-level severity rollup (fleet summary endpoint).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_vulnerabilities'
           AND indexname  = 'idx_site_vuln_tenant_sev'
    ) THEN
        CREATE INDEX "idx_site_vuln_tenant_sev"
            ON "public"."site_vulnerabilities" ("tenant_id", "severity")
            WHERE "status" = 'open';
    END IF;
END;
$$;

-- Tenant query index (supports RLS isolation policy lookups).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public'
           AND tablename  = 'site_vulnerabilities'
           AND indexname  = 'site_vulnerabilities_tenant_idx'
    ) THEN
        CREATE INDEX "site_vulnerabilities_tenant_idx"
            ON "public"."site_vulnerabilities" ("tenant_id", "site_id");
    END IF;
END;
$$;

-- RLS — ENABLE + FORCE
DO $$
BEGIN
    ALTER TABLE "public"."site_vulnerabilities" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_vulnerabilities" FORCE ROW LEVEL SECURITY;
END;
$$;

-- RLS — tenant isolation policy (operator / API path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_vulnerabilities'
           AND policyname = 'site_vulnerabilities_tenant_isolation'
    ) THEN
        CREATE POLICY "site_vulnerabilities_tenant_isolation"
            ON "public"."site_vulnerabilities"
            USING  ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- RLS — agent policy (cross-tenant RescanAll worker path)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
         WHERE schemaname = 'public'
           AND tablename  = 'site_vulnerabilities'
           AND policyname = 'site_vulnerabilities_agent'
    ) THEN
        CREATE POLICY "site_vulnerabilities_agent"
            ON "public"."site_vulnerabilities"
            USING  (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
