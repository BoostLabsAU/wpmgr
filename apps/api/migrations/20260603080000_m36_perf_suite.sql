-- M36 — Performance Suite (ADR-046). Agent-side page cache + asset optimization +
-- pure-Go Remove Unused CSS (RUCSS) on the control plane.
--
-- Adds five tenant-scoped tables:
--   site_perf_config   — one row per site (PK = site_id). The full performance
--                        configuration the agent reads on the request fast-path
--                        (caching, minify, RUCSS, font/lazy-load, CDN, DB clean,
--                        bloat removal). The CP is the source of truth; the agent
--                        mirrors it locally via a sync_perf_config command.
--   site_cache_stats   — one row per site (PK = site_id). The latest cache gauges
--                        the agent reports (page count, on-disk bytes, last purge
--                        / preload). Overwritten in place; no history.
--   cache_purge_audit  — append-style log of every purge (operator or system),
--                        which URLs, who initiated. Read-mostly per-site history.
--   rucss_results      — per (site, structure_hash) Used-CSS computation result;
--                        the used CSS itself lives in object storage (s3_key), this
--                        table holds only the metadata + reduction stats.
--                        UNIQUE(site_id, structure_hash).
--   rucss_jobs         — one RUCSS compute job (id is a ULID, TEXT). Tracks the
--                        queued→running→done|failed lifecycle and links the result.
--
-- No CSS/image bytes are stored here (ADR-046 §3): used CSS moves via object
-- storage keyed by structure-hash. These tables hold config + metadata + stats.
--
-- RLS: every table is ENABLE + FORCE ROW LEVEL SECURITY with a tenant-isolation
-- policy (operator/API path, app.tenant_id GUC) AND an app.agent policy
-- (cross-tenant worker/agent path). Mirrors the m23 media migration exactly.
-- No _site_scope RESTRICTIVE policy: collaborator gating is done in-app via
-- authz.RequireSiteAccess(:siteId) on the routes (m23 precedent).
-- updated_at is set by repo code (no trigger — there is no set_updated_at()).
-- Idempotency: every statement is IF-NOT-EXISTS guarded; re-running is safe.

-- ---------------------------------------------------------------------------
-- site_perf_config
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_perf_config" (
        "site_id"                        uuid PRIMARY KEY,
        "tenant_id"                      uuid NOT NULL,
        -- Caching
        "cache_enabled"                  boolean NOT NULL DEFAULT false,
        "cache_logged_in"                boolean NOT NULL DEFAULT false,
        "cache_mobile"                   boolean NOT NULL DEFAULT false,
        "cache_refresh"                  boolean NOT NULL DEFAULT false,
        "cache_refresh_interval"         text NOT NULL DEFAULT '2hours',
        "cache_link_prefetch"            boolean NOT NULL DEFAULT true,
        "cache_bypass_urls"              text[] NOT NULL DEFAULT '{}',
        "cache_bypass_cookies"           text[] NOT NULL DEFAULT '{}',
        "cache_include_queries"          text[] NOT NULL DEFAULT '{}',
        "cache_include_cookies"          text[] NOT NULL DEFAULT '{}',
        -- CSS / JS
        "css_js_minify"                  boolean NOT NULL DEFAULT true,
        "css_rucss"                      boolean NOT NULL DEFAULT false,
        "css_rucss_include_selectors"    text[] NOT NULL DEFAULT '{}',
        "css_js_self_host_third_party"   boolean NOT NULL DEFAULT false,
        "js_delay"                       boolean NOT NULL DEFAULT false,
        "js_delay_method"                text NOT NULL DEFAULT 'defer',
        "js_delay_excludes"              text[] NOT NULL DEFAULT '{}',
        "js_delay_third_party"           boolean NOT NULL DEFAULT false,
        "js_delay_third_party_excludes"  text[] NOT NULL DEFAULT '{}',
        -- Fonts
        "fonts_display_swap"             boolean NOT NULL DEFAULT true,
        "fonts_optimize_google"          boolean NOT NULL DEFAULT false,
        "fonts_preload"                  boolean NOT NULL DEFAULT false,
        -- Media / lazy-load
        "lazy_load"                      boolean NOT NULL DEFAULT true,
        "lazy_load_exclusions"           text[] NOT NULL DEFAULT '{}',
        "properly_size_images"           boolean NOT NULL DEFAULT true,
        "youtube_placeholder"            boolean NOT NULL DEFAULT false,
        "self_host_gravatars"            boolean NOT NULL DEFAULT false,
        -- CDN
        "cdn_enabled"                    boolean NOT NULL DEFAULT false,
        "cdn_url"                        text,
        "cdn_file_types"                 text NOT NULL DEFAULT 'all',
        "cdn_provider"                   text,
        "cdn_credentials_encrypted"      bytea,
        -- Database cleanup
        "db_auto_clean"                  boolean NOT NULL DEFAULT false,
        "db_auto_clean_interval"         text NOT NULL DEFAULT 'weekly',
        "db_post_revisions"              boolean NOT NULL DEFAULT false,
        "db_post_auto_drafts"            boolean NOT NULL DEFAULT false,
        "db_post_trashed"                boolean NOT NULL DEFAULT false,
        "db_comments_spam"               boolean NOT NULL DEFAULT false,
        "db_comments_trashed"            boolean NOT NULL DEFAULT false,
        "db_transients_expired"          boolean NOT NULL DEFAULT false,
        "db_optimize_tables"             boolean NOT NULL DEFAULT false,
        -- Bloat removal
        "bloat_disable_block_css"        boolean NOT NULL DEFAULT false,
        "bloat_disable_dashicons"        boolean NOT NULL DEFAULT false,
        "bloat_disable_emojis"           boolean NOT NULL DEFAULT false,
        "bloat_disable_jquery_migrate"   boolean NOT NULL DEFAULT false,
        "bloat_disable_xml_rpc"          boolean NOT NULL DEFAULT false,
        "bloat_disable_rss_feed"         boolean NOT NULL DEFAULT false,
        "bloat_disable_oembeds"          boolean NOT NULL DEFAULT false,
        "bloat_heartbeat_control"        boolean NOT NULL DEFAULT false,
        "bloat_post_revisions_control"   boolean NOT NULL DEFAULT false,
        -- Server / install state (agent-reported)
        "server_software"                text,
        "dropin_installed"               boolean NOT NULL DEFAULT false,
        "wp_cache_constant_set"          boolean NOT NULL DEFAULT false,
        "htaccess_managed"               boolean NOT NULL DEFAULT false,
        "config_version"                 int NOT NULL DEFAULT 1,
        "created_at"                     timestamptz NOT NULL DEFAULT now(),
        "updated_at"                     timestamptz NOT NULL DEFAULT now(),  -- set by app code, NOT a trigger
        CONSTRAINT "site_perf_config_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_perf_config_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_perf_config'
          AND indexname = 'site_perf_config_tenant_idx'
    ) THEN
        CREATE INDEX "site_perf_config_tenant_idx"
            ON "public"."site_perf_config" ("tenant_id");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."site_perf_config" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_perf_config" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_perf_config'
          AND policyname = 'site_perf_config_tenant_isolation'
    ) THEN
        CREATE POLICY "site_perf_config_tenant_isolation" ON "public"."site_perf_config"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_perf_config'
          AND policyname = 'site_perf_config_agent'
    ) THEN
        CREATE POLICY "site_perf_config_agent" ON "public"."site_perf_config"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- site_cache_stats
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."site_cache_stats" (
        "site_id"            uuid PRIMARY KEY,
        "tenant_id"          uuid NOT NULL,
        "cached_pages_count" int NOT NULL DEFAULT 0,
        "cache_size_bytes"   bigint NOT NULL DEFAULT 0,
        "last_purged_at"     timestamptz,
        "last_purge_kind"    text,
        "last_preload_at"    timestamptz,
        "preload_pending"    int NOT NULL DEFAULT 0,
        "preload_total"      int NOT NULL DEFAULT 0,
        "reported_at"        timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT "site_cache_stats_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "site_cache_stats_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_cache_stats'
          AND indexname = 'site_cache_stats_tenant_idx'
    ) THEN
        CREATE INDEX "site_cache_stats_tenant_idx"
            ON "public"."site_cache_stats" ("tenant_id");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."site_cache_stats" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."site_cache_stats" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_cache_stats'
          AND policyname = 'site_cache_stats_tenant_isolation'
    ) THEN
        CREATE POLICY "site_cache_stats_tenant_isolation" ON "public"."site_cache_stats"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'site_cache_stats'
          AND policyname = 'site_cache_stats_agent'
    ) THEN
        CREATE POLICY "site_cache_stats_agent" ON "public"."site_cache_stats"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- cache_purge_audit
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."cache_purge_audit" (
        "id"                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"         uuid NOT NULL,
        "site_id"           uuid NOT NULL,
        "kind"              text NOT NULL,  -- 'all'|'url'|'post'|'preload'|'auto'
        "initiator_user_id" uuid,
        "target_urls"       text[] NOT NULL DEFAULT '{}',
        "urls_count"        int NOT NULL DEFAULT 0,
        "created_at"        timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT "cache_purge_audit_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "cache_purge_audit_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "cache_purge_audit_initiator_fkey" FOREIGN KEY ("initiator_user_id")
            REFERENCES "public"."users" ("id") ON UPDATE NO ACTION ON DELETE SET NULL
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'cache_purge_audit'
          AND indexname = 'idx_cache_purge_site'
    ) THEN
        CREATE INDEX "idx_cache_purge_site"
            ON "public"."cache_purge_audit" ("site_id", "created_at" DESC);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'cache_purge_audit'
          AND indexname = 'cache_purge_audit_tenant_idx'
    ) THEN
        CREATE INDEX "cache_purge_audit_tenant_idx"
            ON "public"."cache_purge_audit" ("tenant_id");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."cache_purge_audit" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."cache_purge_audit" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'cache_purge_audit'
          AND policyname = 'cache_purge_audit_tenant_isolation'
    ) THEN
        CREATE POLICY "cache_purge_audit_tenant_isolation" ON "public"."cache_purge_audit"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'cache_purge_audit'
          AND policyname = 'cache_purge_audit_agent'
    ) THEN
        CREATE POLICY "cache_purge_audit_agent" ON "public"."cache_purge_audit"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- rucss_results
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."rucss_results" (
        "id"                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        "tenant_id"           uuid NOT NULL,
        "site_id"             uuid NOT NULL,
        "structure_hash"      text NOT NULL,  -- hash of the page's structural signature
        "url"                 text,           -- a representative URL that produced this structure
        "original_css_bytes"  int,
        "used_css_bytes"      int,
        "reduction_pct"       numeric(5,2),
        "used_css_s3_key"     text NOT NULL,  -- object-storage key of the computed used CSS
        "selectors_total"     int,
        "selectors_kept"      int,
        "selectors_dropped"   int,
        "compute_ms"          int,
        "created_at"          timestamptz NOT NULL DEFAULT now(),
        "last_used_at"        timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT "rucss_results_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "rucss_results_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "rucss_results_site_hash_uniq" UNIQUE ("site_id", "structure_hash")
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'rucss_results'
          AND indexname = 'idx_rucss_results_site'
    ) THEN
        CREATE INDEX "idx_rucss_results_site"
            ON "public"."rucss_results" ("site_id", "last_used_at" DESC);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'rucss_results'
          AND indexname = 'rucss_results_tenant_idx'
    ) THEN
        CREATE INDEX "rucss_results_tenant_idx"
            ON "public"."rucss_results" ("tenant_id");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."rucss_results" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."rucss_results" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'rucss_results'
          AND policyname = 'rucss_results_tenant_isolation'
    ) THEN
        CREATE POLICY "rucss_results_tenant_isolation" ON "public"."rucss_results"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'rucss_results'
          AND policyname = 'rucss_results_agent'
    ) THEN
        CREATE POLICY "rucss_results_agent" ON "public"."rucss_results"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- rucss_jobs
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    CREATE TABLE IF NOT EXISTS "public"."rucss_jobs" (
        "id"             text PRIMARY KEY,  -- ULID
        "tenant_id"      uuid NOT NULL,
        "site_id"        uuid NOT NULL,
        "structure_hash" text,
        "url"            text,
        -- queued|running|done|failed
        "state"          text NOT NULL DEFAULT 'queued',
        "error_reason"   text,
        "result_id"      uuid,
        "created_at"     timestamptz NOT NULL DEFAULT now(),
        "completed_at"   timestamptz,
        CONSTRAINT "rucss_jobs_tenant_id_fkey" FOREIGN KEY ("tenant_id")
            REFERENCES "public"."tenants" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "rucss_jobs_site_id_fkey" FOREIGN KEY ("site_id")
            REFERENCES "public"."sites" ("id") ON UPDATE NO ACTION ON DELETE CASCADE,
        CONSTRAINT "rucss_jobs_result_id_fkey" FOREIGN KEY ("result_id")
            REFERENCES "public"."rucss_results" ("id") ON UPDATE NO ACTION ON DELETE SET NULL
    );
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'rucss_jobs'
          AND indexname = 'idx_rucss_jobs_site_state'
    ) THEN
        CREATE INDEX "idx_rucss_jobs_site_state"
            ON "public"."rucss_jobs" ("site_id", "state");
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'rucss_jobs'
          AND indexname = 'rucss_jobs_tenant_idx'
    ) THEN
        CREATE INDEX "rucss_jobs_tenant_idx"
            ON "public"."rucss_jobs" ("tenant_id");
    END IF;
END;
$$;

DO $$
BEGIN
    ALTER TABLE "public"."rucss_jobs" ENABLE ROW LEVEL SECURITY;
    ALTER TABLE "public"."rucss_jobs" FORCE ROW LEVEL SECURITY;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'rucss_jobs'
          AND policyname = 'rucss_jobs_tenant_isolation'
    ) THEN
        CREATE POLICY "rucss_jobs_tenant_isolation" ON "public"."rucss_jobs"
            USING ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK ("tenant_id" = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'rucss_jobs'
          AND policyname = 'rucss_jobs_agent'
    ) THEN
        CREATE POLICY "rucss_jobs_agent" ON "public"."rucss_jobs"
            USING (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;
