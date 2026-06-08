-- m51 — Store the River job ID on media_optimization_jobs (ADR-043 cancel fix)
--
-- When an operator cancels a media optimization job the corresponding River
-- media_encode job must also be cancelled so the media-encoder (scale-to-zero)
-- is never woken for work that has already been discarded.  Storing the River
-- job ID directly on the media_optimization_jobs row (Design A) is the most
-- precise approach: no coupling to River's internal table shape, O(1) lookup,
-- and a single additive nullable column that carries no RLS implications.
--
-- The column is nullable because:
--   - Only jobs of kind 'optimize' ever receive a River encode job.
--   - The River job is inserted AFTER the media row is created (encode-ready
--     callback → EnqueueEncode → store ID back).
--   - Rows created before this migration are NULL and the cancel path treats
--     NULL as "no River job to cancel" (no-op, log only).
--
-- RLS: the table already has ENABLE + FORCE ROW LEVEL SECURITY with both
-- tenant-isolation and agent policies.  A nullable column requires no new
-- policy — existing policies cover all rows regardless of the column value.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'media_optimization_jobs'
          AND column_name  = 'encode_river_job_id'
    ) THEN
        ALTER TABLE "public"."media_optimization_jobs"
            ADD COLUMN "encode_river_job_id" bigint NULL;

        COMMENT ON COLUMN "public"."media_optimization_jobs"."encode_river_job_id"
            IS 'River river_jobs.id for the media_encode job enqueued at encode-ready time. '
               'NULL for non-optimize jobs and for rows created before m51. '
               'Used by the cancel path to cancel the River job proactively.';
    END IF;
END;
$$;
