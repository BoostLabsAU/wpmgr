-- name: InsertAuditEntry :one
INSERT INTO audit_log (
    tenant_id, actor_type, actor_id, action, target_type, target_id, metadata, prev_hash, hash, created_at
)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
RETURNING *;

-- name: GetLastAuditHash :one
SELECT hash FROM audit_log
WHERE tenant_id = $1
ORDER BY created_at DESC, id DESC
LIMIT 1;

-- name: ListAuditEntries :many
SELECT * FROM audit_log
WHERE tenant_id = $1
ORDER BY created_at ASC, id ASC
LIMIT $2 OFFSET $3;

-- name: ListAuditEntriesFiltered :many
-- Optional filters: action prefix (LIKE 'prefix%'), site_id (target_type='site'
-- AND target_id = site_id::text). Passing an empty string for action_prefix or
-- a zero UUID for site_id disables those filters respectively. RLS is still the
-- primary tenant-isolation gate; the explicit tenant_id is defense-in-depth.
SELECT * FROM audit_log
WHERE tenant_id = @tenant_id
  AND (@action_prefix = '' OR action LIKE @action_prefix || '%')
  AND (@site_id::text = '00000000-0000-0000-0000-000000000000' OR (target_type = 'site' AND target_id = @site_id::text))
ORDER BY created_at ASC, id ASC
LIMIT  @row_limit
OFFSET @row_offset;

-- name: ListAuditEntriesForVerify :many
SELECT * FROM audit_log
WHERE tenant_id = $1
ORDER BY created_at ASC, id ASC;
