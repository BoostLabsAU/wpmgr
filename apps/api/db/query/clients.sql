-- Clients Foundation queries (M63). Every statement is tenant-scoped both
-- explicitly (tenant_id in the WHERE/VALUES) and by RLS (app.tenant_id /
-- app.agent policies). The repo wraps each call in InTenantTx — these
-- queries never set GUCs themselves. updated_at is set by now() in mutations.

-- name: ListClients :many
-- Lists clients for the tenant, ordered by name.
-- When include_archived is false (the default) archived clients are excluded.
SELECT *, (
    SELECT COUNT(*)::bigint
    FROM sites s
    WHERE s.client_id = c.id
      AND s.tenant_id = c.tenant_id
      AND s.connection_state <> 'archived'
) AS site_count
FROM clients c
WHERE c.tenant_id = @tenant_id
  AND (
        sqlc.narg('include_archived')::boolean IS TRUE
        OR c.archived_at IS NULL
      )
ORDER BY c.name ASC, c.id ASC;

-- name: GetClient :one
SELECT *, (
    SELECT COUNT(*)::bigint
    FROM sites s
    WHERE s.client_id = c.id
      AND s.tenant_id = c.tenant_id
      AND s.connection_state <> 'archived'
) AS site_count
FROM clients c
WHERE c.id = @id AND c.tenant_id = @tenant_id;

-- name: CreateClient :one
INSERT INTO clients (
    tenant_id, name, contact_email, company, phone, notes, color, logo_url,
    timezone, updated_at
) VALUES (
    @tenant_id, @name, @contact_email, @company, @phone, @notes, @color, @logo_url,
    COALESCE(sqlc.narg('timezone')::text, 'UTC'), now()
)
RETURNING *;

-- name: UpdateClient :one
-- Partial update: each field uses COALESCE so an absent narg leaves the
-- stored value unchanged. updated_at is always refreshed.
UPDATE clients
SET name          = COALESCE(sqlc.narg('name')::text, name),
    contact_email = COALESCE(sqlc.narg('contact_email')::citext, contact_email),
    company       = COALESCE(sqlc.narg('company')::text, company),
    phone         = COALESCE(sqlc.narg('phone')::text, phone),
    notes         = COALESCE(sqlc.narg('notes')::text, notes),
    color         = COALESCE(sqlc.narg('color')::text, color),
    logo_url      = COALESCE(sqlc.narg('logo_url')::text, logo_url),
    timezone      = COALESCE(sqlc.narg('timezone')::text, timezone),
    updated_at    = now()
WHERE id = @id AND tenant_id = @tenant_id
RETURNING *;

-- name: ArchiveClient :one
-- Soft-delete: sets archived_at without removing the row. Sites retain their
-- client_id after archiving (they still show which client they were under).
UPDATE clients
SET archived_at = now(),
    updated_at  = now()
WHERE id = @id AND tenant_id = @tenant_id
RETURNING *;

-- name: HardDeleteClient :execrows
-- Permanently removes the client row. ON DELETE SET NULL on sites.client_id
-- handles the unassignment automatically in the same statement.
DELETE FROM clients
WHERE id = @id AND tenant_id = @tenant_id;

-- name: CountSitesForClient :one
-- How many non-archived sites are currently assigned to this client?
-- Used for the delete-confirmation dialog.
SELECT COUNT(*)::bigint AS site_count
FROM sites
WHERE client_id = @client_id
  AND tenant_id = @tenant_id
  AND connection_state <> 'archived';

-- name: AssignSitesClient :execrows
-- Bulk-assign (or unassign when @client_id is NULL) a set of sites to a client.
-- RLS + the composite FK guarantee cross-tenant assignment is impossible.
UPDATE sites
SET client_id  = sqlc.narg('client_id')::uuid,
    updated_at = now()
WHERE tenant_id = @tenant_id
  AND id = ANY(@site_ids::uuid[]);

-- name: GetClientBrandsByIDs :many
-- m66: portal branding — fetches client name + branding fields for the given
-- client IDs. Runs under RunTenantTx(p) (site-scope with portal principal).
-- The portal uses the earliest-created client for primary branding.
SELECT id, tenant_id, name, color, logo_url, archived_at
FROM clients
WHERE id = ANY(@ids::uuid[]) AND tenant_id = @tenant_id
ORDER BY created_at ASC, id ASC;
