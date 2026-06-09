-- M55 — Font Results Catalog + fonts_subset config flag (Phase 2 foundation).
--
-- 1. Adds fonts_subset boolean (+ mode/range columns) to site_perf_config.
--    The agent reads these from the synced perf config; when fonts_subset=true
--    it requests subset jobs from the CP. Default false (opt-in, experimental).
--    Follows the identical additive pattern as M54 (fonts_transcode_woff2).
--
-- 2. Creates font_results — per-(site, source_hash) dashboard catalog table.
--    Distinct from font_transcode_results (job control): this is the read-model
--    the dashboard lists. State: pending|ready|subset|negative.
--    savings_pct is CP-derived at upsert from original_size / best output size.
--
-- RLS: ENABLE + FORCE ROW LEVEL SECURITY with tenant_isolation AND agent_access
-- policies, USING + WITH CHECK, mirroring m36/m54 EXACTLY.
-- No triggers; updated_at is set by repo code (now()).
-- Idempotency: every statement is IF-NOT-EXISTS or column-existence guarded.

-- ---------------------------------------------------------------------------
-- 1. site_perf_config: fonts_subset + subset_mode + subset_range columns
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'fonts_subset'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "fonts_subset" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'fonts_subset_mode'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "fonts_subset_mode" text NOT NULL DEFAULT 'range';
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'fonts_subset_range'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "fonts_subset_range" text NOT NULL DEFAULT 'latin-ext';
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 2. font_results catalog table
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."font_results" (
    "id"            uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"     uuid        NOT NULL,
    "site_id"       uuid        NOT NULL,

    -- source_hash is the hex-encoded BLAKE3 hash of the raw source font bytes.
    -- Joins to font_transcode_results for job-control details.
    "source_hash"   text        NOT NULL,

    -- family is the CSS font-family name reported by the agent at discovery.
    -- NULL until the agent reports it (pre-M55 or first push).
    "family"        text,

    -- source_file is the basename of the original font URL (e.g. "inter.woff").
    "source_file"   text,

    -- original_ext is the source format: ttf | otf | woff.
    "original_ext"  text,

    -- original_size is the byte length of the source font file.
    "original_size" integer,

    -- woff2_size is the byte length of the full WOFF2 output.
    -- NULL until the full transcode completes.
    "woff2_size"    integer,

    -- subset_size is the byte length of the subset WOFF2 output.
    -- NULL unless a subset was produced.
    "subset_size"   integer,

    -- unicode_range is the CSS unicode-range descriptor for the subset
    -- (e.g. "U+0000-00FF,U+0100-024F,U+1E00-1EFF"). NULL unless subset.
    "unicode_range" text,

    -- state is the agent-reported lifecycle:
    --   pending    = job enqueued, output not yet available.
    --   ready      = full WOFF2 produced (woff2_size set, subset_size NULL).
    --   subset     = subset WOFF2 also produced (both sizes set). Superset of ready.
    --   negative   = permanent failure; serve the original font forever.
    "state"         text        NOT NULL DEFAULT 'pending',

    -- error_detail is a short human-readable explanation for negative state.
    -- NULL for non-negative rows.
    "error_detail"  text,

    -- savings_pct is CP-derived at upsert: 1 - (best_size / original_size).
    -- Uses min(woff2_size, subset_size) as best_size. NULL when sizes unknown.
    "savings_pct"   numeric(5,2),

    "created_at"    timestamptz NOT NULL DEFAULT now(),
    "updated_at"    timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "font_results_pkey" PRIMARY KEY (id),
    CONSTRAINT "font_results_site_hash_uniq" UNIQUE (site_id, source_hash),
    CONSTRAINT "font_results_state_check" CHECK (state IN ('pending','ready','subset','negative')),
    CONSTRAINT "font_results_tenant_fk" FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT "font_results_site_fk" FOREIGN KEY (site_id)
        REFERENCES sites (id) ON DELETE CASCADE
);

-- Dashboard list: order by updated_at DESC for a site.
CREATE INDEX IF NOT EXISTS "idx_font_results_site"
    ON "public"."font_results" (site_id, updated_at DESC);

-- RLS scans: tenant_id index keeps the tenant_isolation predicate cheap.
CREATE INDEX IF NOT EXISTS "font_results_tenant_idx"
    ON "public"."font_results" (tenant_id);

-- RLS: row-level security mirrors m36/m54 EXACTLY.
ALTER TABLE "public"."font_results" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."font_results" FORCE ROW LEVEL SECURITY;

-- Operator path: tenant isolation via app.tenant_id GUC (set by InTenantTx).
-- nullif guard: a missing GUC returns '' via missing_ok=true; nullif turns ''
-- into NULL so the equality fails (zero rows) rather than erroring on cast.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'font_results'
          AND policyname = 'font_results_tenant_isolation'
    ) THEN
        CREATE POLICY "font_results_tenant_isolation" ON "public"."font_results"
            USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / worker path: cross-tenant actor (app.agent GUC = 'on', set by InAgentTx).
-- Value is 'on' (not 'true') — matches InAgentTx in db.go and all sibling policies.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public'
          AND tablename  = 'font_results'
          AND policyname = 'font_results_agent_access'
    ) THEN
        CREATE POLICY "font_results_agent_access" ON "public"."font_results"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
