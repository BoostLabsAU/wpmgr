-- M53 — WooCommerce cacheable-session flag (#169).
--
-- Adds two columns to site_perf_config:
--   woo_cacheable_session          — operator-writable boolean (default false).
--                                    When true the CP includes this flag in the
--                                    perf-config payload pushed to the agent so
--                                    the agent can cache the WooCommerce catalog
--                                    shell for anonymous shoppers with a cart.
--   woo_theme_fragments_supported  — agent-reported boolean (read-only; the
--                                    agent writes it via the perf/config-ack
--                                    endpoint after probing its own theme and
--                                    WooCommerce hooks). Default false; the CP
--                                    never sets this from operator input.
--
-- Both columns default to false. Existing rows are unaffected (false = no
-- change in behaviour). Re-running this file is safe (IF NOT EXISTS guards).

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'woo_cacheable_session'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "woo_cacheable_session" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'woo_theme_fragments_supported'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "woo_theme_fragments_supported" boolean NOT NULL DEFAULT false;
    END IF;
END;
$$;
