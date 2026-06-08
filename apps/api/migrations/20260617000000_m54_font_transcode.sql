-- M54 — Font Transcode to WOFF2 (Phase 1 foundation).
--
-- 1. Adds fonts_transcode_woff2 boolean to site_perf_config (default false).
--    The agent reads this flag from the synced perf config; when true it
--    requests WOFF2 transcoding from the control plane for self-hosted fonts.
--    Follows the identical additive pattern as M53 (woo_cacheable_session).
--
-- 2. Creates font_transcode_results — one row per content-addressed source
--    font hash. Stores either the produced woff2 asset key (success) or a
--    negative-result marker (negative=true, woff2_key=NULL) so the agent
--    can distinguish "not yet transcoded" from "transcoding is impossible for
--    this font". Tenant-scoped + RLS mirrors the media optimizer tables.
--
-- RLS: ENABLE + FORCE ROW LEVEL SECURITY with tenant-isolation AND agent
-- policies, matching the m36 perf suite and m23 media optimizer patterns.
-- No triggers; updated_at is set by repo code (now()).
-- Idempotency: every statement is IF-NOT-EXISTS or column-existence guarded.

-- ---------------------------------------------------------------------------
-- 1. site_perf_config: fonts_transcode_woff2 column
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'fonts_transcode_woff2'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "fonts_transcode_woff2" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 2. font_transcode_results
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."font_transcode_results" (
    -- source_hash is the hex-encoded BLAKE3 hash of the raw source font bytes.
    -- It is the content-address key: the same font file always maps to the
    -- same hash regardless of tenant/site, so a hash collision across sites
    -- would land in the same row per tenant (tenant_id prevents cross-tenant
    -- sharing). The asset key derives from it: "<source_hash>.woff2".
    "source_hash"  text        NOT NULL,
    "tenant_id"    uuid        NOT NULL,
    "site_id"      uuid        NOT NULL,

    -- river_job_id is the River job ID for the in-flight font_transcode job.
    -- NULL when the job has not yet been inserted or has already finished.
    "river_job_id" bigint,

    -- woff2_key is the object-storage key of the produced WOFF2 file.
    -- NULL until transcoding completes successfully.
    "woff2_key"    text,

    -- negative is true when transcoding was attempted but permanently failed
    -- (unsupported font, malformed data, or repeated transient errors).
    -- When negative=true the agent must serve the original font forever;
    -- the CP will never retry this content hash.
    "negative"     boolean     NOT NULL DEFAULT false,

    -- error_detail is a short human-readable explanation for negative=true
    -- rows. NULL on success rows.
    "error_detail" text,

    "created_at"   timestamptz NOT NULL DEFAULT now(),
    "updated_at"   timestamptz NOT NULL DEFAULT now(),

    PRIMARY KEY (source_hash, tenant_id)
);

-- Index so the agent can fetch all results for a site quickly.
CREATE INDEX IF NOT EXISTS "font_transcode_results_site_id_idx"
    ON "public"."font_transcode_results" (tenant_id, site_id);

-- RLS: row-level security mirrors the m36 perf suite tables.
ALTER TABLE "public"."font_transcode_results" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."font_transcode_results" FORCE ROW LEVEL SECURITY;

-- Operator path: tenant isolation via app.tenant_id GUC (set by InTenantTx).
-- Mirrors site_perf_config_tenant_isolation in m36: nullif guard prevents a cast
-- error when the GUC is unset (returns NULL -> no rows instead of a runtime error).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'font_transcode_results'
          AND policyname = 'tenant_isolation'
    ) THEN
        CREATE POLICY "tenant_isolation" ON "public"."font_transcode_results"
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / worker path: cross-tenant actor (app.agent GUC, set by InAgentTx).
-- GUC value is 'on' (matches InAgentTx in db.go and all 98 sibling policies).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'font_transcode_results'
          AND policyname = 'agent_access'
    ) THEN
        CREATE POLICY "agent_access" ON "public"."font_transcode_results"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
