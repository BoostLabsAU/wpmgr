-- M67 — WooCommerce fragments tri-state (Bug 1 fix, feat/performance-suite).
--
-- The woo_theme_fragments_supported column was boolean NOT NULL DEFAULT false,
-- making "never probed" indistinguishable from "probed: unsupported". Every
-- stored false was untrustworthy because the agent's detectFromScriptRegistry()
-- probe structurally cannot succeed in cron/REST contexts (WC never registers
-- wc-cart-fragments outside a front-end render). This migration:
--
--   1. Drops NOT NULL so NULL represents "not yet probed" (the honest unknown).
--   2. Resets all existing false rows to NULL (every stored false is untrustworthy;
--      true rows, if any, are kept as-is because a true can only come from a
--      genuine detection).
--   3. Drops the DEFAULT false (new rows come in as NULL until probed).
--   4. Adds woo_fragments_probed_at timestamptz NULL — stamped by
--      UpdateWooThemeFragmentsSupported so the operator UI can see when the probe
--      last ran, and future agents can surface "probed N minutes ago".
--
-- Re-running this file is safe: all statements are idempotent.

-- Step 1: drop NOT NULL and default (idempotent — ALTER COLUMN ... DROP NOT NULL
-- is safe to run when the column is already nullable).
DO $$
BEGIN
    -- Drop NOT NULL constraint if it still exists.
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'woo_theme_fragments_supported'
          AND is_nullable  = 'NO'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ALTER COLUMN "woo_theme_fragments_supported" DROP NOT NULL;
    END IF;
END;
$$;

-- Step 2: drop the DEFAULT false (idempotent — safe on a column that already has
-- no default or a different default).
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema  = 'public'
          AND table_name    = 'site_perf_config'
          AND column_name   = 'woo_theme_fragments_supported'
          AND column_default IS NOT NULL
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ALTER COLUMN "woo_theme_fragments_supported" DROP DEFAULT;
    END IF;
END;
$$;

-- Step 3: reset untrustworthy false rows to NULL (every pre-M67 false was
-- produced by the broken detectFromScriptRegistry(); true rows are preserved
-- because they required a genuine positive detection).
UPDATE "public"."site_perf_config"
SET woo_theme_fragments_supported = NULL
WHERE woo_theme_fragments_supported = false;

-- Step 4: add woo_fragments_probed_at (idempotent — IF NOT EXISTS guard).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'site_perf_config'
          AND column_name  = 'woo_fragments_probed_at'
    ) THEN
        ALTER TABLE "public"."site_perf_config"
            ADD COLUMN "woo_fragments_probed_at" timestamptz;
    END IF;
END;
$$;
