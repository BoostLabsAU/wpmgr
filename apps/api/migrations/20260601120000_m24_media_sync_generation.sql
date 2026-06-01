-- M24 — Media Optimizer sync generations (ADR-043 follow-up).
--
-- A deleted WordPress attachment used to linger forever in site_media_assets:
-- the agent sync is upsert-only (Repo.UpsertAssetsAgent — INSERT … ON CONFLICT
-- DO UPDATE), so rows were created/refreshed but never removed. This migration
-- adds the sweep machinery for a sync-generation reconciliation:
--
--   site_media_assets.sync_generation       — stamped on every row the current
--                                             sync run upserts (the run's
--                                             generation = the sync job's UnixMicro
--                                             allocation). After the agent finishes
--                                             enumerating, the sync-finalize
--                                             callback sweeps every row whose
--                                             sync_generation is older than the run
--                                             (i.e. the attachment is gone in WP).
--   media_optimization_jobs.sync_generation — carries the run generation on the
--                                             sync job so finalize can read it back.
--
-- This is DISTINCT from site_media_assets.generation (the per-asset optimization
-- counter bumped by ApplyOptimizedAgent) — different meaning, different column.
--
-- No triggers (mirrors m23 — updated_at is set by repo code). Idempotency: every
-- statement is IF-NOT-EXISTS guarded; re-running is safe. RLS is unchanged: the
-- existing site_media_assets_agent / media_optimization_jobs_agent policies
-- (USING current_setting('app.agent',true)='on', ENABLE+FORCE) already authorize
-- DELETE under InAgentTx — no new policy.

-- ---------------------------------------------------------------------------
-- site_media_assets.sync_generation
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."site_media_assets"
    ADD COLUMN IF NOT EXISTS "sync_generation" bigint NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'site_media_assets'
          AND indexname = 'site_media_assets_site_syncgen_idx'
    ) THEN
        CREATE INDEX "site_media_assets_site_syncgen_idx"
            ON "public"."site_media_assets" ("site_id", "sync_generation");
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- media_optimization_jobs.sync_generation
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."media_optimization_jobs"
    ADD COLUMN IF NOT EXISTS "sync_generation" bigint;
