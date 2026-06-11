-- Client portal member queries (m66). Auth-time and agency roster operations.
-- GetClientAccessForUserTenant and FirstClientMemberTenant run under InUserTx
-- (app.user_id only); all other queries run under InTenantTx.

-- name: GetClientAccessForUserTenant :many
-- Runs under InUserTx (app.user_id). LEFT JOIN: a zero-site client still
-- yields a row (NULL site_id) so the principal gets portal access + branding
-- even when the client has no sites assigned yet.
SELECT cm.client_id, s.id AS site_id
FROM client_members cm
LEFT JOIN sites s
  ON s.client_id = cm.client_id AND s.tenant_id = cm.tenant_id
WHERE cm.user_id = $1 AND cm.tenant_id = $2;

-- name: FirstClientMemberTenant :one
-- Runs under InUserTx. Returns the tenant of the user's earliest client
-- membership, used at login to resolve an active tenant for portal-only users
-- (mirrors FirstActiveShareTenant in auth/repo.go).
SELECT tenant_id FROM client_members
WHERE user_id = $1 ORDER BY created_at ASC LIMIT 1;

-- name: ListMembersForClient :many
-- Agency roster: all members for a client, newest first.
SELECT cm.id, cm.user_id, cm.client_id, cm.tenant_id, cm.invited_by, cm.created_at
FROM client_members cm
WHERE cm.client_id = @client_id AND cm.tenant_id = @tenant_id
ORDER BY cm.created_at DESC, cm.id DESC;

-- name: CreateClientMember :one
-- Upsert: ON CONFLICT DO NOTHING so the caller detects "already a member" by
-- checking for zero RETURNING rows (pgx.ErrNoRows), matching the Conflict pattern.
INSERT INTO client_members (tenant_id, client_id, user_id, invited_by)
VALUES (@tenant_id, @client_id, @user_id, @invited_by)
ON CONFLICT (client_id, user_id) DO NOTHING
RETURNING *;

-- name: DeleteClientMember :execrows
-- Immediate revoke. Returns 0 rows when the member does not exist.
DELETE FROM client_members
WHERE client_id = @client_id AND user_id = @user_id AND tenant_id = @tenant_id;
