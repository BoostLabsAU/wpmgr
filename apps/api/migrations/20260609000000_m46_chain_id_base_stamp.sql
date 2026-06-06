-- m46: ADR-050 incremental-GC prerequisite — stamp chain_id on full-base snapshots.
--
-- Generation-0 full bases were created with chain_id=NULL: the CreateSnapshot
-- chain-stamp path (repo.go) only ran for incremental rows, so a base anchor's
-- chain_id was never set despite the ListChainSnapshots doc claiming otherwise.
-- A NULL chain_id makes the base invisible to ListChainSnapshots' `chain_id = $2`
-- filter and breaks planRestoreChain's `len == targetGen+1` integrity gate — so
-- generation 0 cannot be resolved, which breaks both incremental chain RESTORE
-- and the incremental retention GC's reachability (mark) walk for every chain.
--
-- A base anchors its OWN chain, so chain_id = id. Increments already carry the
-- base's chain_id explicitly. This is restore-correctness-neutral for the base
-- itself: planRestoreChain + the GC mark phase classify a base by
-- (generation = 0 AND is_incremental = false) and route it through the
-- manifest-entry path regardless of chain_id, so stamping the base cannot
-- misroute it to the file-index walk.
--
-- Forward-only, idempotent (IS NULL guard), bounded single UPDATE — no schema
-- change, no destructive operation. The forward code path (repo.go CreateSnapshot)
-- is fixed in the same change so newly created bases self-stamp chain_id = id.
UPDATE "public"."backup_snapshots"
   SET "chain_id" = "id"
 WHERE "chain_id" IS NULL
   AND "is_incremental" = false
   AND "generation" = 0
   AND "parent_snapshot_id" IS NULL;
