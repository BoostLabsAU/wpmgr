-- m47: ADR-050 mark-and-sweep data-loss fix — track when a chunk was last
-- referenced (touch-on-dedup), so the sweep's grace floor protects an OLD chunk
-- that an IN-FLIGHT backup re-references via tenant-global dedup.
--
-- THE HOLE this closes: PresignChunks/ExistingChunkHashes tells the agent "this
-- chunk is already stored, skip the upload" WITHOUT re-uploading, so the chunk's
-- created_at stays ancient (<< the grace floor). The in-flight snapshot is
-- status='running', so it is NOT in the mark (live) set, and its file_index rows
-- are written only at completion — the reference exists ONLY as a presign-time
-- dedup decision. If the chunk's last COMPLETED referrer expires on the same GC
-- run, a created_at-only floor deletes it; the in-flight backup then completes
-- referencing a now-deleted chunk -> the resulting snapshot is unrestorable.
--
-- The fix: stamp last_referenced_at = now() (DB clock) atomically inside the
-- dedup oracle whenever a chunk is reported as already-stored, and again for
-- every referenced chunk at completion. The sweep then deletes a chunk only when
-- GREATEST(created_at, last_referenced_at) < floor, so any chunk a concurrent
-- in-flight backup just touched has a fresh last_referenced_at >= the in-flight
-- snapshot's start >= inflightFloor >= effectiveFloor and survives.
--
-- Forward-only, bounded: one ADD COLUMN with a now() default plus a single
-- backfill UPDATE seeding existing rows from created_at (their original
-- liveness boundary). No destructive operation.
ALTER TABLE "public"."backup_chunks"
    ADD COLUMN "last_referenced_at" timestamptz NOT NULL DEFAULT now();

UPDATE "public"."backup_chunks"
   SET "last_referenced_at" = "created_at";
