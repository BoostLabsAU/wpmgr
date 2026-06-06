-- m45: ADR-049 Incremental Restore V1
--
-- No new tables or columns are required: all data needed by the chain-restore
-- planner already exists in backup_snapshots (chain_id, generation columns
-- added in m44) and backup_file_index (file_path, chunk_hashes, is_tombstone).
--
-- This migration adds a composite index to make ListChainSnapshots fast. The
-- query pattern is:
--   WHERE tenant_id=$1 AND chain_id=$2 AND generation<=$3 ORDER BY generation ASC
-- The index covers the (chain_id, generation) predicate; tenant_id is enforced
-- by RLS and is always the leading filter in the transaction, so the planner
-- uses RLS + this index for efficient chain walks.

CREATE INDEX IF NOT EXISTS backup_snapshots_chain_gen_idx
    ON backup_snapshots (chain_id, generation)
    WHERE chain_id IS NOT NULL;
