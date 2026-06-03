-- m35 — SECURITY DEFINER helper so the superadmin orphaned-org cleanup can
-- delete an empty tenant whose ON DELETE CASCADE reaches the append-only
-- audit_log.
--
-- VERIFIED root cause (Cloud SQL Postgres log, 2026-06-03): #151(a)'s
-- DeleteEmptyTenant ran "DELETE FROM tenants" as wpmgr_app; the FK cascade
-- emitted the internal RI delete
--   DELETE FROM ONLY "public"."audit_log" WHERE $1 = "tenant_id"
-- which failed with "permission denied for table audit_log" (42501). audit_log
-- is insert-only for wpmgr_app — m1 (auth_multitenancy) runs
-- "REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM wpmgr_app" so the trail is
-- tamper-evident — and every tenant has >=1 audit row (its register event). In
-- this environment the cascade child-table DELETE is privilege-checked against
-- the calling role, so the whole delete rolled back and orphaned orgs survived.
--
-- Rather than grant wpmgr_app a standing DELETE on audit_log (which would defeat
-- the append-only guarantee), this SECURITY DEFINER function runs as its OWNER
-- (the migration role, which retains DELETE on audit_log) and removes the
-- tenant's audit rows EXPLICITLY before deleting the tenant — so the tenant
-- delete's cascade never has to touch audit_log. That makes the fix correct
-- whether the FK cascade is privilege-checked against the caller or the owner.
--
-- Safety properties:
--   * deletes a tenant ONLY when it has zero memberships and zero sites;
--   * app.agent='on' is set IN-BODY via set_config (which needs no special
--     privilege — InAgentTx sets it the same way), NOT via a function SET clause:
--     a SET clause on the custom app.agent placeholder GUC requires superuser
--     ownership or GRANT SET ON PARAMETER, which the prod Cloud SQL non-superuser
--     owner lacks, and would abort this CREATE FUNCTION and roll back the
--     migration. It lets the emptiness checks see rows under FORCE RLS
--     (memberships_agent + sites_agent);
--   * app.tenant_id is scoped locally around the explicit audit_log delete so the
--     FORCE-RLS audit_log_tenant_isolation USING clause matches the target rows
--     when the owner is itself subject to FORCE RLS, then reset;
--   * search_path is pinned (public, pg_temp) to block search-path hijacking;
--   * EXECUTE is revoked from PUBLIC and granted only to wpmgr_app, which can
--     reach it solely through the requireSuperadmin-gated user-delete path.
-- Note: deleting an orphaned org also discards any pending invitations to it
-- (invitations cascades from tenants) — acceptable, as the org's only member was
-- just deleted.
--
-- Prereq: the function owner (the role running this migration via MigrateDSN)
-- must be the audit_log owner that retains DELETE on it; in this project that is
-- the same owner/superuser DSN that created every table.

CREATE OR REPLACE FUNCTION "public"."admin_delete_empty_tenant"(p_tenant_id uuid)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_count integer;
BEGIN
    PERFORM set_config('app.agent', 'on', true);
    IF EXISTS (SELECT 1 FROM memberships m WHERE m.tenant_id = p_tenant_id)
       OR EXISTS (SELECT 1 FROM sites s WHERE s.tenant_id = p_tenant_id) THEN
        RETURN false;
    END IF;
    PERFORM set_config('app.tenant_id', p_tenant_id::text, true);
    DELETE FROM audit_log WHERE tenant_id = p_tenant_id;
    PERFORM set_config('app.tenant_id', '', true);
    DELETE FROM tenants t WHERE t.id = p_tenant_id;
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count > 0;
END;
$$;

REVOKE ALL ON FUNCTION "public"."admin_delete_empty_tenant"(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION "public"."admin_delete_empty_tenant"(uuid) TO "wpmgr_app";
