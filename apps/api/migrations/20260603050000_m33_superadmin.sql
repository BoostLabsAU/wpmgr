-- m33 — superadmin flag on users. is_superadmin is instance-level and additive:
-- it is set only by the boot seeder reading WPMGR_SUPERADMIN_EMAILS; NO API
-- may ever write it. Default false preserves all existing rows.
ALTER TABLE "public"."users"
    ADD COLUMN IF NOT EXISTS "is_superadmin" boolean NOT NULL DEFAULT false;
