-- m34 — read-only cross-tenant SELECT on memberships for the app.agent scope
-- (set by Pool.InAgentTx), mirroring sites_agent. Enables the superadmin
-- orphaned-org cleanup on user delete to count members per tenant across tenants
-- and remove orgs that become memberless + siteless. SELECT-only: no
-- cross-tenant writes are granted.

CREATE POLICY "memberships_agent" ON "public"."memberships"
    FOR SELECT
    USING (current_setting('app.agent', true) = 'on');
