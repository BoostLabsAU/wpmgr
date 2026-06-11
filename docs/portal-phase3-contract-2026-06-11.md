# Clients Phase 3 — Read-Only Client Portal: Build Contract (v0.39.0)

Architect contract, 2026-06-11. Binding on backend-architect (Go CP), frontend-architect (React web), and security-reviewer. Vocabulary and shapes below are pinned; deviations require architect sign-off. Repo paths are relative to repo root.

Locked decisions honored throughout: `/portal` subpath; `RoleClient` below viewer with zero write perms; `client_members` resolution in auth producing `Scope=site + AllowedSiteIDs`; existing RESTRICTIVE RLS unchanged plus exactly one PERMISSIVE `sites_client_read` policy; branding reuses `clients.logo_url`/`clients.color`; read-only everywhere; deleting a client revokes portal access via CASCADE; client branding never pushed to the agent; release v0.39.0; migration m66; sqlc discipline; OpenAPI spec plus codegen for all new endpoints; no em dashes and no competitor names in shipped prose.

---

## 1. m66 migration (DDL verbatim)

File: `apps/api/migrations/20260629000000_m66_client_portal.sql` (next timestamp after `20260628000000_m65_generated_reports_updated_at.sql`). All statements idempotent (IF NOT EXISTS / pg_policies DO-guards), matching m63 house style.

```sql
-- m66 — Client portal (Clients Phase 3).
--
-- Adds:
--   client_members            — portal user roster per client (user_id has NO
--                               tenant membership; access resolved at auth time).
--   sites_client_read         — PERMISSIVE SELECT-only policy on sites so the
--                               auth-time lookup (InUserTx, app.user_id only)
--                               can expand a client membership to site IDs.
--                               Mirrors m22 sites_shared_read. Still AND-gated
--                               by the RESTRICTIVE sites_site_scope policy.
--   invitations scope='client' — reuse the m19 tokenized invite flow for
--                               portal users (client_id column + CHECK widen).
--
-- Deleting a client CASCADEs client_members and pending client invitations:
-- portal access is revoked instantly (locked decision).

-- ---------------------------------------------------------------------------
-- [1] client_members
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "public"."client_members" (
    "id"         uuid        NOT NULL DEFAULT gen_random_uuid(),
    "tenant_id"  uuid        NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
    "client_id"  uuid        NOT NULL,
    "user_id"    uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    "invited_by" uuid        NULL     REFERENCES users (id) ON DELETE SET NULL,
    "created_at" timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT "client_members_pkey" PRIMARY KEY ("id"),

    -- One roster row per (client, user); upserts target this pair.
    CONSTRAINT "client_members_client_user_key" UNIQUE ("client_id", "user_id"),

    -- Composite FK: cross-tenant-proof (mirrors sites_client_tenant_fkey in
    -- m63). ON DELETE CASCADE: deleting a client revokes portal access.
    CONSTRAINT "client_members_client_tenant_fkey"
        FOREIGN KEY ("client_id", "tenant_id")
        REFERENCES "public"."clients" ("id", "tenant_id")
        ON DELETE CASCADE
);

-- Auth-time lookup: (user_id, tenant_id) on every portal request.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND indexname = 'client_members_user_tenant_idx'
    ) THEN
        CREATE INDEX "client_members_user_tenant_idx"
            ON "public"."client_members" ("user_id", "tenant_id");
    END IF;
END;
$$;

-- Roster listing per client (agency UI).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND indexname = 'client_members_client_idx'
    ) THEN
        CREATE INDEX "client_members_client_idx"
            ON "public"."client_members" ("client_id");
    END IF;
END;
$$;

ALTER TABLE "public"."client_members" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."client_members" FORCE ROW LEVEL SECURITY;

-- Operator / API path (m63 verbatim shape).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_tenant_isolation'
    ) THEN
        CREATE POLICY "client_members_tenant_isolation"
            ON "public"."client_members"
            USING      (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid);
    END IF;
END;
$$;

-- Agent / worker path (m63 verbatim shape).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_agent'
    ) THEN
        CREATE POLICY "client_members_agent"
            ON "public"."client_members"
            USING      (current_setting('app.agent', true) = 'on')
            WITH CHECK (current_setting('app.agent', true) = 'on');
    END IF;
END;
$$;

-- Self-read: the auth-time lookup runs under InUserTx where ONLY app.user_id
-- is set (no app.tenant_id), so tenant_isolation cannot match. Mirrors
-- site_shares_self_read (m19). SELECT-only.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'client_members'
          AND policyname = 'client_members_self_read'
    ) THEN
        CREATE POLICY "client_members_self_read"
            ON "public"."client_members"
            FOR SELECT
            USING (user_id = nullif(current_setting('app.user_id', true), '')::uuid);
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [2] sites_client_read — PERMISSIVE SELECT-only policy on sites
-- ---------------------------------------------------------------------------
-- Purpose: under InUserTx (auth-time expansion of client membership to site
-- IDs) there is no app.tenant_id, so sites_tenant_isolation hides every row.
-- This policy lets a client member read site rows of their own client only.
-- It is OR-combined with the permissive policies but AND-gated by the
-- RESTRICTIVE sites_site_scope policy (m19), so it cannot widen a site-scoped
-- read. archived_at gate: members of an archived client lose access instantly.
-- NOTE: the EXISTS subquery against client_members is itself subject to that
-- table's RLS; client_members_self_read (user_id = app.user_id) is exactly
-- what admits the needed rows.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE schemaname = 'public' AND tablename = 'sites'
          AND policyname = 'sites_client_read'
    ) THEN
        CREATE POLICY "sites_client_read" ON "public"."sites"
            FOR SELECT
            USING (EXISTS (
                SELECT 1
                FROM client_members cm
                JOIN clients cl
                  ON cl.id = cm.client_id AND cl.tenant_id = cm.tenant_id
                WHERE cm.client_id = sites.client_id
                  AND cm.tenant_id = sites.tenant_id
                  AND cm.user_id   = nullif(current_setting('app.user_id', true), '')::uuid
                  AND cl.archived_at IS NULL
            ));
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- [3] invitations — scope='client'
-- ---------------------------------------------------------------------------

ALTER TABLE "public"."invitations"
    ADD COLUMN IF NOT EXISTS "client_id" uuid NULL;

-- Widen the inline scope CHECK (auto-named invitations_scope_check by m19).
ALTER TABLE "public"."invitations" DROP CONSTRAINT IF EXISTS "invitations_scope_check";
ALTER TABLE "public"."invitations"
    ADD CONSTRAINT "invitations_scope_check" CHECK (scope IN ('org', 'site', 'client'));

-- Composite FK, ON DELETE CASCADE: deleting a client kills pending invites.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name        = 'invitations'
          AND constraint_name   = 'invitations_client_tenant_fkey'
    ) THEN
        ALTER TABLE "public"."invitations"
            ADD CONSTRAINT "invitations_client_tenant_fkey"
            FOREIGN KEY ("client_id", "tenant_id")
            REFERENCES "public"."clients" ("id", "tenant_id")
            ON DELETE CASCADE;
    END IF;
END;
$$;

-- Pending-invite listing per client (mirrors invitations_site_id_idx).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public' AND tablename = 'invitations'
          AND indexname = 'invitations_client_id_idx'
    ) THEN
        CREATE INDEX "invitations_client_id_idx"
            ON "public"."invitations" ("client_id", "created_at" DESC)
            WHERE scope = 'client';
    END IF;
END;
$$;
```

### schema.sql mirror (lockstep, same migration commit)
- `apps/api/db/schema.sql`: append a `-- m66 — client_members (Client portal Phase 3)` section at file tail with the `client_members` table, both indexes, ENABLE/FORCE, and all three policies in plain (non-DO-guarded) form, matching how m63/m64 sections are mirrored.
- Add `CREATE POLICY sites_client_read ...` immediately after `sites_shared_read` (currently `schema.sql:156`), with the rationale comment.
- Edit the `invitations` block in place (`schema.sql:1073-1104`): widen the scope CHECK to `('org','site','client')`, add the `client_id` column + `invitations_client_tenant_fkey`, add `invitations_client_id_idx`, each tagged `-- m66`.
- sqlc discipline (standing rule): after the mirror, run the prebuilt sqlc binary and verify regen, then eyeball every UPDATE SET column against the mirrored DDL (the m65 lesson: sqlc does not validate UPDATE SET column names).

---

## 2. Auth change (exact)

**File:** `apps/api/internal/middleware/auth.go`, function `(*Authenticator).Authenticate()`, the no-membership branch (currently lines 117-153).

**Pinned semantics:**
1. The existing `resolveActiveShares` path is UNCHANGED. site_shares win and are EXCLUSIVE: when the user has one or more active shares in the tenant, client_members is NOT consulted. Rationale (binding): merging would let a share role (up to operator) apply to the union including the client's sites, escalating a portal grant to operator on sites the share never covered. No role mixing, ever.
2. New fallback, only when `shareErr != nil || len(shares) == 0`: call a new `resolveClientAccess(ctx, userID, activeTenant)` running under `a.pool.InUserTx` (mirrors `resolveActiveShares`; the `client_members_self_read` + `sites_client_read` policies are what admit the rows). It executes one sqlc query (see below) returning `(clientIDs []uuid.UUID, siteIDs []uuid.UUID)`.
3. Branch outcome:
   - lookup error or zero client memberships: `p.TenantID = uuid.Nil` (existing fail-closed behavior, unchanged).
   - one or more memberships: `p.Scope = domain.ScopeSite`, `p.TenantID = activeTenant`, `p.AllowedSiteIDs = siteIDs` (deduped union across ALL of the user's clients in this tenant; may be empty for a zero-site client, which fails closed at RLS because `string_to_array(nullif('', ''), ...)` yields NULL), `p.ClientIDs = clientIDs`, and `p.Role = string(authz.RoleClient)` UNCONDITIONALLY. That hard assignment IS the effective-role clamp: client_members has no role column, nothing stored can ever raise it, and the existing share-branch operator clamp is untouched.

**Principal change:** `apps/api/internal/domain/principal.go` — add `ClientIDs []uuid.UUID` to `Principal` (empty for every non-portal principal). Do not touch `GetScope`/`GetAllowedSiteIDs`/`CanAccessSite`; `RunTenantTx` dispatch (`db/db.go:453-466`) already routes `Scope==site` into `InScopedTenantTx` and needs no change.

**Multi-client / multi-tenant resolution rule (pinned):**
- Same tenant, multiple clients: AllowedSiteIDs is the union; ClientIDs carries all; portal branding uses the client with the EARLIEST `client_members.created_at` (deterministic).
- Multiple tenants: one active tenant per session, as today. `apps/api/internal/auth/service.go` `resolveActiveTenant` (line ~103) gains a third fallback after `FirstActiveShareTenant`: `FirstClientMemberTenant(userID)` (new repo method + sqlc query under InUserTx, ordered by `created_at` ascending) so a portal-only user's login lands in their client's tenant instead of `uuid.Nil`. The `X-Tenant-ID` override keeps working (membership/share/client re-verified per request); the portal UI does not expose a tenant switcher in v1.
- A user who is an org member in tenant A and a client member in tenant B: works as-is per tenant (membership wins inside A at line 112-116; client branch fires inside B).

**Session/password semantics:** unchanged. Portal users are normal `users` rows; the `password_changed_at` session invalidation gate (lines 94-98) applies before the client branch, intentionally.

**`/auth/me` change:** `apps/api/internal/auth/handler.go` `me()` (line 188) and `toMe`: add three fields to the Me DTO (and OpenAPI `Me` schema at `packages/openapi/openapi.yaml:6878`):
- `scope` string, `"org" | "site" | ""` from `p.Scope`;
- `role` string (the principal's effective role; new value `"client"` possible) — a NEW standalone enum in the spec, NOT an extension of the existing `Role` enum (~line 6834), which stays `[owner, admin, operator, viewer]` so member/API-key validation never loosens;
- `portal` object, present only when `Role == client`: `{ client_id, client_name, logo_url, color, agency_name }`, resolved via `pool.RunTenantTx(p)` from `clients WHERE id = ANY(p.ClientIDs)` (earliest-created client) plus the tenant name. login/register/accept responses keep using `toMe` and may leave the new fields empty; the web refetches `/auth/me` before branching (section 5).

**Audit:** `recordLogin` (`auth/service.go:113`) currently early-returns for zero-membership users; extend it to best-effort record `auth.login` under the resolved fallback tenant when the user is a client member, so portal logins reach the audit log.

**sqlc (new file `apps/api/db/query/client_members.sql`):**
```sql
-- name: GetClientAccessForUserTenant :many
-- Runs under InUserTx (app.user_id). LEFT JOIN: a zero-site client still
-- yields a row (NULL site_id) so the principal gets portal access + branding.
SELECT cm.client_id, s.id AS site_id
FROM client_members cm
LEFT JOIN sites s
  ON s.client_id = cm.client_id AND s.tenant_id = cm.tenant_id
WHERE cm.user_id = $1 AND cm.tenant_id = $2;

-- name: FirstClientMemberTenant :one
SELECT tenant_id FROM client_members
WHERE user_id = $1 ORDER BY created_at ASC LIMIT 1;

-- name: ListMembersForClient :many
-- name: CreateClientMember :one      (INSERT ... ON CONFLICT (client_id, user_id) DO NOTHING RETURNING *; zero rows = already a member -> Conflict)
-- name: DeleteClientMember :execrows (WHERE client_id = $1 AND user_id = $2)
```
Plus: `db/query/invitations.sql` `CreateInvitation` gains a `client_id` param (org/site call sites pass `pgtype.UUID{Valid:false}`); add `ListInvitationsForClient`. Branding query (`GetClientBrandsByIDs`) goes in `db/query/clients.sql`. After any of this: `sqlc generate`, verify true no-op on second run, never hand-edit `*.sql.go`.

---

## 3. RoleClient wiring (every enumeration site)

**`apps/api/internal/authz/role.go`:**
1. Add `RoleClient Role = "client"` with doc comment "read-only client portal principal; ranked below viewer; holds zero permissions".
2. Re-rank the `rank` map: `RoleClient: 1, RoleViewer: 2, RoleOperator: 3, RoleAdmin: 4, RoleOwner: 5`. Rationale (binding): a missing-key lookup yields 0, so RoleClient must NOT sit at rank 0 or an invalid role string would satisfy `AtLeast(RoleClient)`. Comparisons are relative; nothing else changes.
3. `minRoleFor`: RoleClient appears in ZERO entries. `Allows(RoleClient, p) == false` for every Permission, including `PermSiteRead` and `PermClientRead`. Portal routes do not use `RequirePermission` at all (section 4). Add the explanatory comment so a future permission grant is a deliberate act.
4. New middleware in `apps/api/internal/authz/middleware.go`:
```go
// RequireClientPortal admits ONLY client-portal principals: a session user
// resolved through client_members (Scope=site, Role=client, >=1 client).
// Everything else 403s, including org members and site-share collaborators.
func RequireClientPortal() gin.HandlerFunc
// p.Type == PrincipalUser && p.Scope == ScopeSite &&
// Role(p.Role) == RoleClient && len(p.ClientIDs) > 0
```
5. Tests: extend `role_test.go` with a table-driven assertion that `Allows(RoleClient, p)` is false for the FULL permission list (reflect or hand-enumerate all Perm consts) and that `RoleClient.AtLeast(RoleViewer) == false`, `Role("bogus").AtLeast(RoleClient) == false`. Extend `middleware_test.go` for `RequireClientPortal`.

**Member-management MUST REJECT RoleClient (client is portal-only, never an org membership, share, or API-key role):**
- `apps/api/internal/invitation/service.go` `CreateOrgInvitation` (line 79): after `targetRole.Valid()`, add `if targetRole == authz.RoleClient { return Validation("role_invalid", ...) }` (RoleClient.Valid() is now true, so this explicit check is required).
- `apps/api/internal/auth/members_handler.go`: `invite` legacy path (`roleOrDefault`, line ~143) and `patchRole` (line ~173 role parse) both reject `"client"` with the same validation error.
- `apps/api/internal/sharing/service.go` `Grant` (line 138): the switch already allows only viewer/operator/admin; add `// RoleClient intentionally absent — portal access is granted via client members, not site shares.`
- `apps/api/internal/apikey/apikey.go` (line 112): `role.Valid()` would now accept "client"; add an explicit RoleClient rejection (`apikey_role_invalid`).
- DB belt: the m19 memberships role CHECK (`role IN ('owner','admin','operator','viewer')`) must NOT be widened. Same for the site_shares role CHECK. m66 touches neither.
- OpenAPI: the existing `Role` enum stays `[owner, admin, operator, viewer]`. The `invitations.role` value for client invites is the literal string `client` stored in the existing text column (no CHECK on invitations.role exists; no schema change needed).

---

## 4. API surface

### 4.1 Why portal-specific endpoints (pinned, with proof)

Portal principals consume existing endpoints AS-IS: **none.** Proof chain the builders must preserve:
- Every existing read endpoint a portal could want is gated `RequirePermission(PermSiteRead)` (min RoleViewer, rank 2) or `RequireOrgScope`; `rank[client]=1 < 2` so `Allows` fails and `RequireOrgScope` 403s `Scope=site`. This is intentional: reusing `/sites/:id/activity`, `/diagnostics`, `/errors` etc. would over-expose agency operational surfaces to clients. The portal exposes only the v1 parity set through dedicated DTOs.
- Therefore all portal reads live under `/api/v1/portal/*`, gated by `RequireClientPortal()`, and every handler delegates to EXISTING read services (uptime, backup, update, perf/rum, report) — no new data collection.

New package: `apps/api/internal/portal` (handler + service), registered in `server/server.go` next to `ReportH.Register(v1)` (~line 431). Group: `v1.Group("/portal", authz.RequireClientPortal())`; the per-site subgroup additionally applies `authz.RequireSiteAccess("siteId")`. The client identity is ALWAYS derived from `p.ClientIDs`; no portal route takes a `clientId` path parameter (eliminates the IDOR class outright).

### 4.2 Portal endpoints (all GET; gating = RequireAuth + RequireTenant + RequireClientPortal, plus RequireSiteAccess where a `:siteId` exists)

| Method+Path | DTO (exhaustive field list, binding) | Delegates to |
|---|---|---|
| `GET /api/v1/portal/overview` | `{ client: {id, name, logo_url, color}, agency_name, site_count, report_count }` (earliest client when multiple) | clients + tenants lookups under `RunTenantTx(p)` |
| `GET /api/v1/portal/sites` | `{ items: [{ id, name, url, status, last_backup_at, uptime_30d_pct, tls_expires_at }] }` | sites list under `RunTenantTx(p)` (tenant_isolation AND site_scope restrict to the client's sites); last backup from backup repo; uptime from uptime service |
| `GET /api/v1/portal/sites/:siteId/uptime?range=24h\|7d\|30d\|90d` | `{ range, uptime_pct, avg_latency_ms, tls_expires_at, incidents: [{started_at, ended_at, duration_seconds}] }` | `uptime.Service` summary (same data as the operator uptime tab) |
| `GET /api/v1/portal/sites/:siteId/backups?limit<=20` | `{ items: [{ id, kind, status, size_bytes, created_at, completed_at }] }` — status filter `completed` server-side; NO blob keys, NO destination, NO manifest, NO download/restore | backup repo list |
| `GET /api/v1/portal/sites/:siteId/updates?limit<=50` | `{ items: [{ type, name, from_version, to_version, status, finished_at }] }` — successfully applied tasks only | update_tasks repo |
| `GET /api/v1/portal/sites/:siteId/vitals?range=28d` | `{ range, metrics: [{ metric: lcp\|inp\|cls, p75, rating, samples }] }` — all-devices aggregate (the 0.33.5 aggregate) | rum read service |
| `GET /api/v1/portal/reports` | `{ items: [{ id, client_id, period_start, period_end, created_at, completed_at }] }` — `status='completed'` AND `client_id = ANY(p.ClientIDs)` enforced IN THE QUERY (generated_reports has no site_scope restrictive policy, so this WHERE clause is the cross-client gate; security checklist item 6.3) | new sqlc query `ListCompletedReportsForClients` |
| `GET /api/v1/portal/reports/:reportId/download?format=html\|pdf` | `{ url, expires_at }` | loads the report, 404 (not 403) unless `report.client_id ∈ p.ClientIDs && status == 'completed'`, then reuses the existing `PresignReportURLs` machinery |

`/auth/me` extension (section 2) is the portal's identity+branding source; there is no separate `/portal/me`.

### 4.3 Client-member management for agencies (org side)

Mounted inside the existing `client` handler group (`apps/api/internal/client/handler.go`, group already `authz.RequireOrgScope()`):

| Method+Path | Gate | Behavior + DTO |
|---|---|---|
| `GET /api/v1/clients/:clientId/members` | `RequirePermission(PermClientRead)` | `{ items: [{ user_id, email, name, created_at }] }` — identities batch-resolved exactly like `sharing.attachUserIdentities` |
| `POST /api/v1/clients/:clientId/members` | `RequirePermission(PermClientManage)` | body `{ email }`. Mirrors `sharing.Service.Grant` (service.go:136): existing user → `CreateClientMember` upsert, respond `201 { member }`, enqueue branded notification; unknown email → invitation row `scope='client', client_id, role='client'`, 7-day TTL, `token.go` 32-byte token, respond `201 { invited: true, email, accept_link, invitation_id, expires_at }`. `accept_link` is ALWAYS returned (copyable-link fallback when SMTP is unconfigured — the ADR-045 G7 precedent) |
| `DELETE /api/v1/clients/:clientId/members/:userId` | `RequirePermission(PermClientManage)` | immediate revoke (`DeleteClientMember`; 404 when absent), mirrors `sharing.Revoke` |
| `GET /api/v1/clients/:clientId/invitations` | `RequirePermission(PermClientRead)` | pending client-scope invites: `{ items: [{ id, email, created_at, expires_at, status }] }` (status derived pending/expired, m20 convention) |
| `DELETE /api/v1/clients/:clientId/invitations/:invitationId` | `RequirePermission(PermClientManage)` | soft revoke (`revoked_at`/`revoked_by`), race-safe pattern from `sharing/service.go:392-423` |
| `POST /api/v1/clients/:clientId/invitations/:invitationId/regenerate` | `RequirePermission(PermClientManage)` | fresh token + reset expiry/attempts + clear revoked, pattern from `sharing/service.go:430-479` |

Every handler must verify the `:clientId` belongs to the tenant (load client under `InTenantTx` first; RLS backs it) and that referenced invitations have `scope='client'` and matching `client_id`.

### 4.4 Invite accept flow (reuse, named)

`apps/api/internal/invitation/service.go Accept()` gains a third `switch inv.Scope` case `"client"` (alongside `"org"`/`"site"`, line 286): validate `inv.ClientID` valid, `CreateClientMember` upsert, audit `client_member.accepted` (target_type `client`). EVERY existing protection is inherited unchanged because the case sits after them: SHA-256 token lookup under `InInviteLookupTx`, single-use atomic claim (`MarkInvitationAccepted ... WHERE accepted_at IS NULL RETURNING`), revoked → opaque not-found, expiry check, `subtle.ConstantTimeCompare` email binding, 10-attempt rate limit, existing-user password re-entry, new-user password set, then `sessions.Login(u.ID, tenantID)`. `AcceptResult` already carries `Scope`; the web accept page branches on `scope === "client"` → `/portal` (section 5). New mailer template pair `client_portal_invite.html.tmpl` + plaintext fallback in `internal/mailer/templates/`, modeled on `site_invite` (fields: Name, InviterName, ClientName, AgencyName, AcceptURL, ExpiresHours). No raw tokens in logs or email_log (existing mailer rule).

**Audit events (new vocabulary, binding):** `client_member.invited`, `client_member.added`, `client_member.removed`, `client_member.accepted`, `client_member.invite_revoked`, `client_member.invite_regenerated` — all `target_type: "client"`, metadata carrying `client_id`, `email`/`grantee_id`, `invitation_id` where applicable.

### 4.5 OpenAPI + codegen

All section 4.2/4.3 endpoints plus the `Me` extension go into `packages/openapi/openapi.yaml` (new tags `portal`, extend `clients`): schemas `MePortal`, `PortalOverview`, `PortalSite(+List)`, `PortalUptimeSummary`, `PortalBackupList`, `PortalUpdateList`, `PortalVitalsSummary`, `PortalReportList`, `PortalReportDownload`, `ClientMember(+List)`, `ClientMemberCreateRequest`, `ClientMemberInviteResult`, `ClientInvitationList`. Then regen BOTH layers per the standing pipeline: `go generate ./internal/api/gen/...` and `pnpm -C packages/openapi-client generate`; export the new SDK functions from `packages/openapi-client/src/index.ts` (the clients/reports precedent). Spec prose: no em dashes, no competitor names.

---

## 5. Web `/portal` tree

### 5.1 Route files (TanStack file-based, NOT under `_authed`)

```
apps/web/src/routes/portal/route.tsx        guard layout + PortalShell
apps/web/src/routes/portal/index.tsx        overview (client header + sites grid)
apps/web/src/routes/portal/sites.$siteId.tsx  site detail (uptime, vitals, backups, updates cards)
apps/web/src/routes/portal/reports.tsx      completed reports + download
```

`routes/portal/route.tsx` guard (mirror `_authed.tsx:12-67`): `beforeLoad` → `ensureMe(queryClient)`; no session → `redirect({ to: "/login", search: { redirect: location.pathname } })`; session but `me.role !== "client"` → `redirect({ to: "/sites" })`. The reverse gate goes into `routes/_authed.tsx`: before the `NoOrgScreen` zero-access check (line ~44), `me.role === "client"` → `redirect({ to: "/portal" })`.

### 5.2 Shell + branding

New `apps/web/src/components/layout/portal-shell.tsx` (do NOT reuse `AppShell`/`Sidebar`/`TopBar` — they carry org-switcher, command palette, bulk actions, write-oriented nav). Composition: top header bar with client logo (`me.portal.logo_url`, `img` with safe fallback to client name text; reuse the existing `safeImgSrc` guard), client name, two-item nav (Sites, Reports), right side theme toggle + user menu (profile name, Logout only); footer line `Managed by {agency_name}`. No sidebar, no BUILD_VERSION badge, no org switcher.

Branding mechanism (binding): apply `me.portal.color` as a scoped CSS variable override on the shell root only, e.g. `style={{ "--color-primary": color }}` after validating it is a safe hex value (regex `^#[0-9a-fA-F]{6}$`, else ignore); semantic tokens (destructive, warning, chart-*) stay untouched so status colors never shift. Dark mode: keep the override but never restyle foreground tokens (contrast safety; Impeccable gate applies).

Login: `/login` stays agency-generic in v1 (pre-auth the server cannot know which client is visiting a shared subpath; per-client pre-auth branding needs custom domains, a later phase — see section 9, decision 1). Post-login branding appears the moment PortalShell mounts from `me.portal`.

### 5.3 Login + accept branching

`routes/login.tsx`:
- `beforeLoad` (line 34-39): when `me` exists, `throw redirect({ to: me.role === "client" ? "/portal" : (search.redirect ?? "/sites") })`.
- `onSubmit` onSuccess (line 75-77): replace the direct navigate with `const me = await queryClient.fetchQuery(<me query>)` (force a fresh `/auth/me` so the middleware-resolved role/scope/portal fields are present — the login response's `Me` may not carry them), then `navigate({ to: me?.role === "client" ? "/portal" : (search.redirect ?? "/sites") })`.

`routes/accept.tsx` (line 203-209): add the third branch — `result.scope === "client"` → `navigate({ to: "/portal" })`. Extend the local `scope` union type to `"org" | "site" | "client"`.

Session expiry inside the portal redirects to `/login?redirect=/portal/...` via the route.tsx guard (subpath preserved automatically by passing `location.pathname`).

### 5.4 Pages + data sources

New `apps/web/src/features/portal/`: `use-portal.ts` with `portalKeys = { all: ["portal"], sites: ..., site: (id), uptime: (id, range), backups: (id), updates: (id), vitals: (id), reports: ... }` (house queryKey convention) wrapping the new generated SDK functions; presentational components `portal-site-card.tsx`, `portal-uptime-card.tsx`, `portal-vitals-card.tsx`, `portal-backups-card.tsx`, `portal-updates-card.tsx`, `portal-reports-table.tsx`.

| Page | Data |
|---|---|
| `/portal` (index) | `GET /portal/overview` + `GET /portal/sites` |
| `/portal/sites/$siteId` | the four per-site portal endpoints; loader validates the siteId is in the sites list (else `notFound()`) before fetching detail |
| `/portal/reports` | `GET /portal/reports`; row download buttons call the download endpoint and open `url` with `target="_blank" rel="noopener"` |

### 5.5 Shared components: safe vs forbidden

Safe to reuse (presentational, zero mutations): `components/shared/page-header.tsx`, `definition-list.tsx`, `severity-chip.tsx`, all `components/ui/*` primitives (Card, Table, Badge, Button, Skeleton, Progress, the Radix Dialog), chart primitives used by uptime/vitals if they take plain props. FORBIDDEN to import into portal: anything under `features/sites`, `features/backups`, `features/uptime`, `features/updates`, `features/reports` feature components (they embed mutation hooks, `/_authed`-rooted links, and write affordances). Portal cards are thin read-only rebuilds over the pinned DTOs. The portal tree must contain zero mutation hooks except logout; CI-able check: `grep -r "useMutation" apps/web/src/routes/portal apps/web/src/features/portal` returns only the logout usage (or nothing, if logout reuses `use-auth`).

### 5.6 Agency-side UI (members management)

`routes/_authed/clients/$clientId.tsx` tab nav (line 30-137) gains a third tab "Portal access" → new `routes/_authed/clients/$clientId.members.tsx`: members list (email, name, added date, Revoke), pending invitations list (status chip, Revoke, Regenerate), invite form (email only; role is implicitly client and not selectable), and a copy-link affordance on every invite result (accept_link is always present). Hook file `features/clients/use-client-members.ts`. The org Members page role picker (`features/orgs`/members UI) must NOT list "client" as an option.

---

## 6. Security checklist (for security-reviewer)

1. **Cross-client leakage proofs (same tenant, two clients, one portal user each):**
   - portal user of client A: `/portal/sites` returns only A's sites (AllowedSiteIDs built solely from `cm.client_id = sites.client_id` join; RESTRICTIVE `sites_site_scope` AND-gates every site-keyed read under `InScopedTenantTx`).
   - `/portal/sites/:siteId/*` with B's siteId → 404 from `RequireSiteAccess` (and RLS would return empty regardless).
   - `/portal/reports` and `/portal/reports/:reportId/download` for B's report → empty / 404: `generated_reports` and `report_schedules` have NO site_scope restrictive policy, so the `client_id = ANY(p.ClientIDs)` predicate in the new sqlc queries is the ONLY cross-client gate at this layer — review those two queries character by character and add an integration test (extend `authz/rls_isolation_test.go`).
   - `sites_client_read` widening check: confirm it is `FOR SELECT` only, references `app.user_id` only via the db.go GUC helpers, joins `clients ... archived_at IS NULL`, and remains AND-gated by `sites_site_scope` (write a test proving an org-scoped principal's visibility is unchanged and a share collaborator cannot see client-only sites).
   - archived client: archive (set `archived_at`) → portal user's next auth resolves zero sites via the policy; verify access drops without deleting rows.
   - deleted client: CASCADE removes `client_members` + pending invitations; next request resolves `TenantID = uuid.Nil` → 403 `tenant_required`; verify the web lands on a sane screen.
2. **Writes-nothing proof:** (a) table-driven test `Allows(RoleClient, p) == false` for the complete Permission list; (b) the `/portal` group registers only GET routes (route-table assertion test, mirror the perf routes-contract test); (c) `RequireClientPortal` rejects org principals, API keys, and share collaborators; (d) every org/member/API-key/SMTP route stays behind `orgLevelPerms` + `RequireOrgScope` untouched. Note honestly: DB-level WITH CHECK does not block tenant-scoped writes for a portal principal on non-site-keyed tables; the app layer (no perms + GET-only surface) is the enforcement, same as for viewers today.
3. **By-id gate audit list (exact routes flagged by the read-surfaces pass)** — verify each denies RoleClient at the permission gate AND retains its `canReadSite`/`CanAccessSite` belt: `backup/handler.go:126,240,300,509,553,583,1131`; `backup/sqlinspect_handler.go:125`; `backup/schedule_run_handler.go:239`; `backup/restore_run_handler.go:231,260`; `scan/handler.go:227` + `scan/service.go:115,163`; `site/events/sse_handler.go:116` (SSE stream: confirm a portal principal either passes only own-site events through `CanAccessSite` or is perm-blocked — pin which); `perf/handler.go` db-snapshot routes `:113-114` (confirmed nested under the `RequireSiteAccess("siteId")` group at line 66, plus PermSiteWrite); `report/handler.go:37` group `RequireOrgScope` (portal blocked); `client/handler.go:40` likewise; `uptime/handler.go:120` summary filtering via `CanAccessSite`.
4. **Member-management rejection:** attempts to set role "client" via `POST /members`, `PATCH /members/:userId`, org invitations, site shares, and API-key creation all return validation errors; memberships/site_shares role CHECK constraints unchanged.
5. **Invite-token enumeration resistance (client scope inherits all of it — verify the new case sits AFTER each guard in `Accept()`):** 32-byte `crypto/rand` token, SHA-256-only storage, unique `token_hash`, `InInviteLookupTx` GUC-gated lookup, constant-time email compare, 10-attempt counter, atomic single-use claim, revoked → opaque `invitation_not_found`, 7-day TTL. Plus: `accept_link` never written to logs or email_log; regenerate resets attempts and rotates the hash.
6. **Branding never reaches the agent:** grep agent command construction (`SyncPerfConfig`, metadata pushes, every `internal/command`/agent payload builder) for `logo_url`/`color`/`client` — zero hits; clients fields appear only in `/auth/me`, portal DTOs, and the existing org-scoped client/report DTOs. Also confirm no agent keys, age recipients, destination configs, or blob keys appear in any portal DTO (field lists in section 4.2 are exhaustive — anything extra is a finding).
7. **Auth-branch hygiene:** shares-first exclusivity (no role/site merging — escalation rationale in section 2); `p.Role` hard-set to client in the branch; lookup failure fail-closed (`TenantID = uuid.Nil`); `X-Tenant-ID` override re-verified per request for client members like everyone else.
8. **Presigned report URLs:** TTL unchanged from m64; document (runbook note) that a URL presigned before revocation lives until expiry; download endpoint 404s post-revocation.

---

## 7. Build order, layer split, verification gates

**Phase A — backend-architect (apps/api + packages/openapi only; does not touch apps/web):**
1. m66 migration + schema.sql mirror (section 1).
2. sqlc queries + `sqlc generate` (section 2/4) — gate: second `generate` run is a true no-op (`git diff --stat apps/api/internal/db/sqlc` empty); eyeball every UPDATE SET column against the mirrored schema.
3. authz: RoleClient + rank shift + `RequireClientPortal` + rejection guards (section 3) + tests.
4. middleware/auth.go branch + Principal.ClientIDs + `resolveActiveTenant` fallback + `/auth/me` extension + recordLogin fallback (section 2).
5. invitation `client` scope case + mailer template + client-member CRUD + portal package + audit events (section 4).
6. OpenAPI spec + `go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate`, committing the regenerated client (the frontend's contract artifact).
- Gates: `go build ./... && go test ./...` in apps/api; sqlc no-op gate; openapi-client regen committed and `pnpm -C packages/openapi-client typecheck` (or build) clean; routes-contract test green; rls_isolation tests green.

**Phase B — frontend-architect (apps/web only, starts after Phase A's openapi-client commit):**
1. `/portal` route tree + PortalShell + branding (section 5.1-5.2).
2. login/accept/_authed branching (5.3).
3. portal pages + features/portal hooks (5.4-5.5).
4. clients detail Members tab + invite/copy-link UI (5.6).
- Gates: `pnpm -C apps/web typecheck && pnpm -C apps/web lint && pnpm -C apps/web build`; `npx impeccable detect` clean; the section 5.5 no-mutation grep; manual check of the redirect matrix (org user → /portal bounces to /sites; client user → /sites bounces to /portal; logged-out /portal → /login?redirect=/portal).

**Phase C — security-reviewer:** section 6 checklist; any must-fix blocks ship.

**Definition of done (standing SOPs):** CHANGELOG.md `[0.39.0]` entry + landing `content.ts` card via docs-writer (no em dashes, no competitor names); full-stack deploy checklist applies (api image, web image; NO agent release — zero agent delta, self-updater stays 0.36.0); release tag `v0.39.0` after PR merge to main.

---

## 8. v1 scope (from parity findings)

**IN (table stakes + differentiators):** portal login with standard email+password and the existing reset flow; branded portal shell (client logo + color, agency attribution); sites overview with status, last backup, 30d uptime, TLS expiry; per-site uptime summary + incident history; read-only backup inventory; applied-updates log; Core Web Vitals p75 field data (the differentiator most tools lack); completed white-label reports list + HTML/PDF download; agency-side member invite/revoke/regenerate with copyable link fallback.

**OUT (explicit, do not build any of it):** magic-link/passwordless login; custom portal domains and per-client pre-auth login branding; client-triggered report generation; visibility of pending/generating/failed reports (completed only); backup download/restore initiation; activity log, diagnostics, PHP errors, plugin/theme inventory, email log, security-scan surfaces; file manager; support tickets; billing; client self-registration or change-email; per-client report branding beyond the existing m64 fields; pushing any branding to the agent; portal tenant switcher; client_members expiry column (membership is perpetual until revoked; revoke is instant).

---

## 9. Open product decisions (genuinely for the user; everything else is pinned)

1. **Pre-auth branding cut:** v1 keeps `/login` and `/accept` agency-generic; client branding appears only inside `/portal` after sign-in (a shared subpath cannot know the client before auth; true pre-auth branding needs custom domains, a later phase). Confirm this is acceptable for v1.
2. **Portal attribution:** the shell footer reads "Managed by {agency name}". Should it also carry a small product powered-by line, mirroring the report toggle (`powered_by_removed`), or stay agency-only? Default if unanswered: agency-only, no product mention.
3. **Site status pill wording:** the portal shows site connection state (table stakes). Confirm showing the real state (connected/degraded/disconnected) versus a softer "monitoring active/attention" vocabulary that hides agency ops detail. Default if unanswered: softer vocabulary.

## Decisions locked by the user (2026-06-11, supplements section 9)

1. Login branding: /login and /accept stay agency-generic in v1. Client branding (logo + color) appears only inside /portal after sign-in. Per-client pre-auth branding waits for custom domains.
2. Portal footer: agency attribution only ("Managed by {agency_name}"). No product powered-by line in the portal.
3. Site status wording for clients: softer copy. Map connected -> "Monitoring active", degraded/disconnected -> "Needs attention". The portal DTO carries the real status value; the web layer owns the soft labels.
