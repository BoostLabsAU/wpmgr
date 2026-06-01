-- M26 — Media variant metrics: per-asset image-count + true all-variant savings.
--
-- The dashboard now reports two things the single full-file size cannot express:
--   1. "Images (incl. thumbnails)" — every WordPress upload is 1 full image + N
--      generated sub-sizes, and the optimizer processes ALL of them. The library
--      headline should reflect that image-file count, not just the attachment count.
--   2. "Bytes saved" across ALL optimized variants (full + every thumbnail). After
--      the m25-era full-file size fix, original_size_bytes/current_size_bytes are
--      the FULL file only (the figure users expect per image), so the old
--      (original - current) rollup no longer captures thumbnail savings.
--
-- Two additive columns on site_media_assets carry the agent-computed truth:
--   variant_count — 1 (full) + count of generated sub-sizes for this attachment.
--                   Reported at sync AND auto-optimize time (the shared row builder).
--   saved_bytes   — sum over every optimized variant of (original - optimized) bytes,
--                   computed by the agent from the wpmgr_image_optimization blob.
--                   Reported at apply (fresh optimize) AND re-reported at sync for
--                   already-optimized assets, so a re-sync self-heals existing rows.
--
-- Both default 0 and are EXACT-SET by the agent (not GREATEST/accumulate), mirroring
-- original_size_bytes, so a corrected re-sync can heal a stale value down or up.
-- Backfill is intentionally NOT attempted: the CP never stored per-variant bytes, so
-- the only source of truth is the agent's blob. The summary query floors total_images
-- at the optimized-variant count so it can never read below "optimized" before a
-- re-sync populates variant_count.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS — safe to run twice. No RLS change (the
-- table's policies already cover every column).

DO $$
BEGIN
    ALTER TABLE "public"."site_media_assets"
        ADD COLUMN IF NOT EXISTS "variant_count" integer NOT NULL DEFAULT 0;
    ALTER TABLE "public"."site_media_assets"
        ADD COLUMN IF NOT EXISTS "saved_bytes" bigint NOT NULL DEFAULT 0;
END;
$$;
