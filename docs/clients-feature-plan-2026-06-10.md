# Agency "Clients" Feature — Implementation Plan (research-backed, 2026-06-10)

Source: 6-investigator research workflow (tenancy/RLS data-model · portal-access reuse · white-label reports · competitor patterns · frontend surface · synthesis). Grounded against the live repo, not memory.

## Why
The web already anticipates this feature but it is **unbuilt**: `sites-toolbar.tsx` has a `Client ▾` filter + a "Set client on N sites" bulk action, and `routes/_authed/sites/index.tsx:369` stubs it with `toast.info("Setting client on N sites lands in Sprint 4")`. There is **no backend** (no `clients` table, no `site.client_id`). This plan builds it for real.

## Scope (LOCKED with user, 2026-06-10)
Three capabilities, **Foundation-first**. Per-client **billing is OUT of scope**.
1. **Foundation** — per-tenant clients; assign/group/filter sites by client (wires up the stubbed toolbar).
2. **White-label client reports** — branded periodic + on-demand maintenance reports.
3. **Client portal** — read-only, branded, scoped view of only a client's sites.

## Core insight: reuse, don't rebuild
A "client" is **one nullable FK column on sites** (`sites.client_id`) plus a tenant-scoped `clients` table. A report is an aggregation+presentation layer over five **existing** read services (uptime, backups, updates, RUM, email). A portal user is the existing `Scope=="site"` principal whose `AllowedSiteIDs` is expanded from the client's assigned sites, with a new read-only `RoleClient`. **Filtering sites by client and scoping a portal user both ride existing RLS unchanged — zero new per-table policy on `sites`.**

## Data model (migration m62, mirrors m36 dual-policy)
- **clients** (Foundation): `id`, `tenant_id` (FK tenants ON DELETE CASCADE), `name`, `contact_email` (citext), `company`, `phone`, `notes`, `color`, `logo_url`, `archived_at` (soft-delete), `created_at/updated_at`. `UNIQUE(id, tenant_id)` to back the composite FK. RLS: ENABLE+FORCE + `clients_tenant_isolation` + `clients_agent` (m36 verbatim). **No `clients_site_scope` policy** — a site-scoped collaborator must never enumerate the client roster; org access gated in-app by `RequireOrgScope + PermClientRead`.
- **sites.client_id** (Foundation, additive nullable): composite FK `(client_id, tenant_id) REFERENCES clients(id, tenant_id) ON DELETE SET NULL` (cross-tenant-proof, non-destructive). Partial index `WHERE client_id IS NOT NULL`.
- Phase 2 adds `report_schedules` + `generated_reports` (+ recipient list); Phase 3 adds `client_members` + `client_brand`. Every new tenant table gets tenant-isolation RLS at creation (standing rule).
- `sqlc generate` after (never hand-edit `*.sql.go` — the m55 prod-down lesson). OpenAPI via `go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate`.
- New perms in `authz/role.go`: `PermClientRead` (RoleViewer) + `PermClientManage` (RoleOperator tier).

## Phase 1 — Foundation
**CP (backend-architect):** m62 migration; new `internal/client` package (model/repo/service/handler mirroring `internal/org`) → `POST/GET/PATCH/DELETE /api/v1/clients` gated `RequireOrgScope + PermClientManage`; extend `ListSites` with a `client_id` narg; add `client_id/client_name` to the Site DTO; audit `client.created/updated/deleted`; OpenAPI regen.
**Web (frontend-architect):** `features/clients/` (use-clients hook, clients-list, client-form — clone `destinations`); **replace the `sites/index.tsx:369` toast stub** with a real `set-client-dialog.tsx` (single-select picker + "No client" + Apply); **wire the Client filter** to real `useClients()` data (today it aliases `tagOptions`); add a real client Badge column to the sites table (the current "Client" header mislabeled-renders `site.tags` — split it back); `/clients/$clientId` detail route (Sites tab + Reports placeholder); Clients leaf in the sidebar.
**Security (security-reviewer):** cross-tenant client RLS test; `RequireOrgScope` blocks site-scoped collaborators; composite FK cross-tenant-proof; `ON DELETE SET NULL` (not CASCADE).
**DoD:** create/assign/filter/bulk-set works end-to-end; the Sprint-4 stub is gone; RLS test green; Impeccable clean; CP-then-web deploy + CHANGELOG + landing card.

## Phase 2 — White-label reports
**Approach (chosen):** Go `html/template` → one inlined-CSS HTML report, delivered as **(a) an HTML email digest (primary)** + **(b) a print-optimized HTML page** (browser print-to-PDF). **No headless Chrome** (chromedp/rod adds ~300MB Chromium and breaks the lean min-instance posture). Charts = inline SVG sparklines from existing series. If a binary PDF download becomes a hard requirement later, add pure-Go `go-pdf/fpdf` behind a flag rendering the same `ReportData` struct — not headless Chrome.
**Data sources (collect nothing new):** `uptime.Service` (uptime %, latency, TLS expiry), `backup` (snapshots taken/restored), `update` (runs/tasks applied), perf RUM (p75 Core Web Vitals), `email.Repo` (sent/failed/bounced). All already tenant-scoped.
**Delivery + schedule:** render → store via `blobstore.Put` + presigned download (longer TTL) → snapshot numbers into `generated_reports.data_snapshot`; `report_schedules` + a River `NewPeriodicJob` worker (mirror backup `ScheduleWorker`) → enqueue per-client → deliver through the v0.35.0 mailer (suppression-aware, agency provider) with **brand-stripped** templates. On-demand "generate now" endpoint.
**Web (frontend-architect):** `features/reports` — report builder (sections on/off, date range, recipients, branding logo/color/intro/closing), schedule config, generated-reports list with download; wire the Reports tab on client detail.
**Security:** recipient validate/dedupe/cap + suppression honored; RLS so a tenant can't schedule over another tenant's sites; signed-URL TTL; no third-party/"WPMgr" string leakage.

## Phase 3 — Client portal
**Access model:** a thin specialization of the **existing site-scope sharing machinery** — no new GUC, no new RLS family. A client portal user is a `users` row with no tenant membership; extend `middleware/auth.go` Authenticate (no-membership branch) to resolve `client_members` for `(userID, tenant)`, expand to that client's site IDs, and set `Scope=ScopeSite + AllowedSiteIDs=<client sites> + Role=RoleClient`. This feeds the **same Principal shape** the 21 RESTRICTIVE `*_site_scope` policies + `RequireSiteAccess` already enforce → zero new per-table RLS. One added PERMISSIVE `sites_client_read` policy (EXISTS client_members JOIN) lets the client read site metadata, still AND-gated by the RESTRICTIVE site_scope policy.
**Read-only:** new `RoleClient` ranked below RoleViewer with no write perm; belt-and-suspenders effective-role clamp in auth.
**Web:** a **separate `/portal` route tree + app shell** (not inside `_authed`), read-only subset of dashboards, branded login from `client_brand`.
**Security (hardest gate):** prove no cross-client leakage (AllowedSiteIDs = union of only that client's sites; RESTRICTIVE policies AND-gate; RequireSiteAccess 404s unlisted sites); prove writes-nothing; audit every path-less route (must keep `RequireOrgScope` or `CanAccessSite` after resolving site_id); `client_brand` never pushed to the agent; deleting a client CASCADEs `client_members` (revokes access).

## Competitor parity (+ differentiators)
**Table stakes (covered):** client as a first-class record with auto-default report recipient; auto-compiled white-label report (Updates + Uptime + Backups + Security/Health + Performance + free-text "work performed"), logo + intro/closing, section toggles, date range, email + download, recurring schedule; "remove powered-by".
**Differentiators (parity-plus):** real **Core Web Vitals field data** in the Performance section (most tools only show synthetic); the existing **secure backup/restore + media optimization** surfaced per client; Ed25519-signed agent telemetry behind it.

## Decisions — LOCKED (user, 2026-06-10)
1. Report delivery floor — HTML email digest + print-optimized page **AND binary PDF download required in v1** (pure-Go `go-pdf/fpdf` rendering the same aggregated ReportData; still no headless Chrome).
2. Client↔site cardinality — **1:1 nullable FK (`sites.client_id`)**, no join table.
3. Tags vs clients — **fully separate**; the mislabeled "Client" UI column (which renders tags) is fixed in Foundation; no tag-migration path.
4. `sites.client_id` ON DELETE — **SET NULL** (deleting a client unassigns its sites, never deletes them).
5. "Remove powered-by" — **free everywhere** (reports default to a small powered-by footer with a free toggle to remove; OSS stays full-featured, hosted monetizes elsewhere).
6. Report schedule defaults — **monthly cadence default** (weekly selectable) + a **client-level timezone field** (defaulted from the agency) governing send time.
7. Portal routing — **`/portal` subpath** on the main app gated on the client role (no DNS/TLS/cookie rework; subdomains revisitable later).
8. Portal role — **new `RoleClient` ranked below viewer**, zero write perms (a home for future client-only perms).

## Roadmap slot
Sits **after** per-site email v1 reaches functional-QA-clean (Phase 2 reports send through the v0.35.0 mailer + suppression + central log, so it depends on that infra). **Foundation (Phase 1) can start in parallel** with email QA (no email dependency). Also competes for time with the in-flight font-subsetting Phase 2.
