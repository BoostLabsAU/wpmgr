-- m82 File Manager queries. All statements are tenant-scoped both explicitly
-- (tenant_id in WHERE/VALUES) and via RLS (app.tenant_id / app.agent GUC).
-- The repo wraps each call in InTenantTx — these queries never set GUCs.
-- updated_at is set via now() in mutations; there is no trigger.

-- ---------------------------------------------------------------------------
-- site_file_manager
-- ---------------------------------------------------------------------------

-- name: GetSiteFileManager :one
SELECT * FROM site_file_manager
WHERE site_id = @site_id;

-- name: UpsertSiteFileManager :exec
-- Insert-or-update the per-site file manager flag. tenant_id is always the
-- principal's tenant (belt-and-braces alongside the RLS policy).
INSERT INTO site_file_manager (
    site_id, tenant_id, files_enabled, updated_at
) VALUES (
    @site_id, @tenant_id, @files_enabled, now()
) ON CONFLICT (site_id) DO UPDATE SET
    files_enabled = EXCLUDED.files_enabled,
    updated_at    = now();

-- ---------------------------------------------------------------------------
-- file_transfers
-- ---------------------------------------------------------------------------

-- name: InsertFileTransfer :exec
INSERT INTO file_transfers (
    id, tenant_id, site_id,
    direction, rel_path, status,
    object_key, size_bytes, chunk_count,
    created_by, expires_at
) VALUES (
    @id, @tenant_id, @site_id,
    @direction, @rel_path, @status,
    @object_key, @size_bytes, @chunk_count,
    @created_by, @expires_at
);

-- name: ListFileTransfers :many
-- Returns the most-recent transfers for a site (newest first).
SELECT * FROM file_transfers
WHERE tenant_id = @tenant_id
  AND site_id   = @site_id
ORDER BY created_at DESC, id DESC
LIMIT  @row_limit
OFFSET @row_offset;
