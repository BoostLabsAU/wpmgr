# Security Suite — Phase 4: Vulnerability Scanner — Build Plan

Status: DESIGN (not started)
Data source (locked): Wordfence Intelligence FREE vulnerability data feed (V3).
Migration: **m79** (next free; last shipped is `20260711000000_m78_site_security_policy.sql`).
Vendor-neutrality: the reference plugin is NEVER named in any shipped artifact. "Wordfence Intelligence" IS an acceptable data-source/integration name (same class as host/CDN names per the no-defensive-comments rule).

---

## 1. GATE 0 — Wordfence Intelligence licensing / ToS

**Verdict: PASS — commercial use in WPMgr (open-core SaaS) is explicitly permitted, royalty-free and irrevocable. Build may proceed.** No user decision is blocked, but the build MUST honor the attribution conditions below — they are binding, not optional.

Authoritative basis (T&C "Last Updated January 26, 2026", https://www.wordfence.com/wordfence-intelligence-terms-and-conditions/):
- "WORDFENCE INTELLIGENCE IS OFFERED AT NO FEE." Docs: "publicly available for free for personal and **commercial use**."
- License grant: "perpetual, worldwide, non-exclusive, no-charge, royalty-free, irrevocable license to reproduce, prepare derivative works of, publicly display, publicly perform, **sublicense, and distribute the Service**." Broad enough for open-core SaaS + redistributing matched data to tenants.

**Binding obligations the build MUST satisfy (DoD gates):**
1. **Carry the Defiant copyright + license** with any stored/redistributed data. We store `copyrights.defiant.notice` + license text once per feed snapshot (not per row) and surface it in the UI footer/attribution of the vuln view.
2. **Display MITRE copyright claims** for any CVE-bearing record shown to an end user. When a finding renders a CVE, the UI MUST show `copyrights.mitre.notice`.
3. **Link back** to the Wordfence vulnerability record (`references[]` / `cve_link`) wherever a vuln detail is displayed.
4. **API key is private, non-transferable, one-per-direct-caller.** Hosted SaaS: the **control plane holds ONE key** (env, cryptbox-backed like other secrets) and serves matched results to tenants — permitted (data may be sublicensed; the *key* may not). Self-hosted WPMgr that calls Wordfence directly needs the operator's **own** free key → new optional env `WPMGR_WORDFENCE_API_KEY`; if unset, the vuln scanner degrades gracefully (feature shows "not configured", no scan).

**Hard constraints carried into the build:**
- **V3 only.** V1/V2 no-auth endpoints disabled 2026-03-09. V3 requires `Authorization: Bearer <key>`.
- **Full-dump only.** No `since`, no pagination, no query params. Each poll re-downloads the entire dataset (multi-MB, ~13k+ records). Cache + diff locally.
- **Rate-limited (HTTP 429).** Poll on a modest interval (hourly convention), never tightly; honor 429 with backoff.

Attribution copy + key handling are explicit checklist items for security-reviewer sign-off (§7).

---

## 2. Architecture — the core insight

**Vulnerability detection is a pure CP-side join. The agent needs NO change.**

The control plane already holds, per site, the canonical installed inventory of every plugin/theme/core + version:
- `apps/api/internal/site/model.go:37` — `Site.Components []byte` JSONB on the `sites` row: `{plugins:[], themes:[], core_update:{}}`.
- `Site.ParsedComponents()` (`site/model.go:188-200`) → `([]Component, []Component)`; `Component{Slug, Name, Version, ...}` (`model.go:153-166`) — `slug` + `version` + `name` are exactly the keys a feed lookup needs.
- Core version: `Site.WPVersion` (`model.go:24`) + optional `Site.ParsedCoreUpdate()` (`model.go:205-216`).
- This data already flows: agent `class-metadata-command.php:80-87` produces it, `class-enrollment.php:217 pushMetadata()` pushes it, `refresh_inventory` re-polls on demand. The `update` domain already reads this exact path (`update/model.go:78-82`).

**Where matching happens:** entirely in a new CP domain `apps/api/internal/vuln`. For a site, we (a) load `Site.ParsedComponents()` + core version, (b) for each `(type, slug)` look up the vuln-feed cache index, (c) run the WP `version_compare` range test against `affected_versions`, (d) persist matched findings. No agent round-trip, no file hashes, no River multi-step driver (unlike `internal/scan`, which is fundamentally agent-filesystem-bound — see audit §2; we do NOT ride `scan_runs`).

**Agent change: none.** P4 is read-only against already-pushed inventory. (Minor known limit: items with `version: 'unknown'` — `class-metadata-command.php:323` fallback — are unmatchable and skipped.)

**Refresh triggers (both CP-side, §6):** (1) feed refreshes (new vulns) → re-match all sites; (2) a site's inventory changes (metadata push / `refresh_inventory` / completed update) → re-match that one site.

---

## 3. Data model (m79)

Two tables. One global public cache (no RLS), one tenant-scoped findings table (RLS).

### 3a. Global feed cache — mirror `wporg_plugin_checksums(_meta)` (NO tenant RLS, public reference data)

Precedent: `wporg_plugin_checksums` + `_meta` in `migrations/20260710000000_m77_file_integrity.sql:237-306` (no RLS, `_meta` sibling holds `fetched_at` + `ok` for freshness/negative-cache); also `hibp_breach_cache` (m78), `wporg_core_checksums` (m15).

```sql
-- public reference data, NO row level security
CREATE TABLE IF NOT EXISTS wordfence_vuln_feed (
    vuln_id      text PRIMARY KEY,         -- Wordfence UUID (record `id`)
    title        text NOT NULL,
    cve          text,                     -- nullable (Production-only)
    cve_link     text,
    cvss_score   numeric(3,1),             -- nullable (Production-only)
    cvss_rating  text,                     -- None/Low/Medium/High/Critical
    cwe          jsonb,                    -- {id,name,description} nullable
    informational boolean NOT NULL DEFAULT false,
    references   jsonb NOT NULL DEFAULT '[]',  -- references[] (attribution link-back)
    published    timestamptz,
    updated      timestamptz,
    raw          jsonb NOT NULL,           -- full record incl. copyrights (attribution source)
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- denormalized per-software index for fast (type,slug) lookup; one row per software[] entry
CREATE TABLE IF NOT EXISTS wordfence_vuln_software (
    vuln_id           text NOT NULL REFERENCES wordfence_vuln_feed(vuln_id) ON DELETE CASCADE,
    kind              text NOT NULL,        -- 'core' | 'plugin' | 'theme'
    slug              text NOT NULL,
    affected_versions jsonb NOT NULL,       -- the affected_versions object (range test input)
    patched           boolean NOT NULL DEFAULT false,
    patched_versions  jsonb NOT NULL DEFAULT '[]',
    PRIMARY KEY (vuln_id, kind, slug)
);
CREATE INDEX IF NOT EXISTS idx_wf_vuln_software_lookup ON wordfence_vuln_software (kind, slug);

-- freshness + attribution snapshot (single-row sentinel), mirrors *_meta pattern
CREATE TABLE IF NOT EXISTS wordfence_vuln_feed_meta (
    id              integer PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    fetched_at      timestamptz,
    ok              boolean NOT NULL DEFAULT false,
    record_count    integer NOT NULL DEFAULT 0,
    defiant_notice  text,                   -- copyrights.defiant.notice (display)
    defiant_license text,                   -- copyrights.defiant.license (attribution)
    mitre_notice    text,                   -- copyrights.mitre.notice (display for CVE rows)
    last_error      text
);
```
No RLS (public, no tenant association) — same justification comment style as `m77:18,238` / `m78:15,29`.

### 3b. Per-site findings — NEW table, tenant-scoped RLS (do NOT reuse `scan_findings`)

**Decision: new table `site_vulnerabilities`, not a new `scan_findings` kind.** Justification (audit §2): `scan_findings` is hash-oriented (`ExpectedMD5/ActualMD5/Path/DeduKey`) and lives under the agent-driven River `scan_runs` loop. A vuln finding has none of those — it has `(vuln_id, kind, slug, installed_version, fixed_version, severity)` and no run/hash lineage. Forcing it in means a no-op worker + empty columns. The vuln finding lifecycle (first/last-seen, resolved-by) also differs.

RLS pattern: mirror `site_file_baseline` (`m77:39-135`) — `ENABLE` + `FORCE ROW LEVEL SECURITY`; `_tenant_isolation` (`USING + WITH CHECK tenant_id = current_setting('app.tenant_id')`) + `_agent` policy; **no `_site_scope` restrictive policy** — collaborator gating stays in-app via `authz.RequireSiteAccess(:siteId)` (m76 precedent).

```sql
CREATE TABLE IF NOT EXISTS site_vulnerabilities (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id         uuid NOT NULL,
    site_id           uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    vuln_id           text NOT NULL,        -- -> wordfence_vuln_feed.vuln_id (no FK; cache may purge)
    kind              text NOT NULL,        -- core|plugin|theme
    slug              text NOT NULL,
    name              text NOT NULL,
    installed_version text NOT NULL,
    fixed_version     text,                 -- derived from patched_versions / range upper bound
    severity          text NOT NULL,        -- critical|high|medium|low (scan severity vocab)
    cvss_score        numeric(3,1),
    cve               text,
    title             text NOT NULL,
    status            text NOT NULL DEFAULT 'open',  -- open|dismissed|resolved
    first_seen        timestamptz NOT NULL DEFAULT now(),
    last_seen         timestamptz NOT NULL DEFAULT now(),
    resolved_at       timestamptz,
    dismissed_at      timestamptz,
    dismissed_by      uuid,
    UNIQUE (site_id, vuln_id, kind, slug)
);
CREATE INDEX IF NOT EXISTS idx_site_vuln_site_open ON site_vulnerabilities (site_id) WHERE status = 'open';
CREATE INDEX IF NOT EXISTS idx_site_vuln_tenant_sev ON site_vulnerabilities (tenant_id, severity) WHERE status = 'open';
-- + ENABLE/FORCE RLS, _tenant_isolation, _agent policies (idempotent pg_policies guards)
```

`severity` reuses the scan vocabulary plus `critical` (the feed's `cvss.rating` already buckets None/Low/Medium/High/Critical → map directly; `<3 low / <7 medium / <9 high / else critical` as a fallback when only a numeric score exists).

---

## 4. CP build (`apps/api/internal/vuln`, hand-written Gin)

Follow the security/scan sibling pattern: `model.go` / `repo.go` (sqlc) / `service.go` / `handler.go` (`Register(r *gin.RouterGroup)` with manual `authz` gating). NOT OpenAPI codegen.

### 4a. Feed ingester (Go River worker)

Mirror the fetch-and-cache pattern of `scan/worker.go:469 fetchAllPluginChecksums` (load-from-cache → on-miss fetch → upsert + meta), but here it's a scheduled full-feed refresh, not per-request.

- **Fetch:** `GET https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner` (detection source — minimal, freshest) with `Authorization: Bearer <WPMGR_WORDFENCE_API_KEY>`. Optionally also pull `.../production` to enrich UI (CVSS/CWE/CVE/description/remediation + copyrights). Streaming-decode the JSON object (root keyed by UUID); do not load the whole multi-MB blob into one giant struct if avoidable.
- **Upsert:** transactional replace — upsert `wordfence_vuln_feed` + explode each record's `software[]` into `wordfence_vuln_software (kind, slug, affected_versions, patched, patched_versions)`. Delete rows whose `vuln_id` vanished from the feed. Write `wordfence_vuln_feed_meta` (`fetched_at`, `ok`, `record_count`, defiant/mitre notices from any record's `copyrights`).
- **Resilience:** on 429 or 5xx, keep the last-good cache, set `last_error`, backoff. Honor a modest poll (hourly). If `WPMGR_WORDFENCE_API_KEY` unset → no-op, `ok=false`, feature reports "not configured".
- **Trigger:** River periodic job (see §6) + a manual admin "refresh feed now" enqueue.

### 4b. Version-range matcher (WP `version_compare` semantics)

Per audit §5 (report 2): do NOT use a generic semver lib. Replicate PHP `version_compare`:
- Split on `. _ - +` and on digit/non-digit boundaries; order special tokens `dev < alpha/a < beta/b < RC/rc < (release) < pl/p`.
- For each `affected_versions` range: vulnerable iff
  `(from == "*" OR cmp(installed, from) {>= if from_inclusive else >}) AND (to == "*" OR cmp(installed, to) {<= if to_inclusive else <})`.
- `*` = unbounded that side. A bare single-version key = exact match (from==to inclusive).
- `fixed_version` for remediation = min of `patched_versions[]` that is `> installed`, else the range upper bound's next patched version. `patched`/`patched_versions` are advisory; the range test is authoritative.

Provide as `vuln.IsVulnerable(installed string, affected AffectedVersions) bool` + a small `wpversion` compare package (unit-tested against PHP fixtures, including `-RC`, 2-segment, `-beta1`). This is net-new, high-risk logic → dedicated test table.

### 4c. Matcher service (the join)

- `RescanSite(siteID)`: load `Site.ParsedComponents()` + `WPVersion`; for each `(kind, slug, version)` query `wordfence_vuln_software` by `(kind, slug)`; run the range test; upsert `site_vulnerabilities` (set `last_seen`, derive `severity`/`fixed_version`/`cve`/`title` from `wordfence_vuln_feed`). Mark previously-open findings no longer matched as `resolved` (`resolved_at = now()`).
- `RescanAll()`: fan out `RescanSite` across `site.ListAllSiteIDs` (`site/repo.go:674`) — used after a feed refresh.

### 4d. Endpoints (hand-written Gin, `Register(r *gin.RouterGroup)`)

Per-site group gated by `authz.RequirePermission(PermSiteRead)` + `authz.RequireSiteAccess("siteId")` (scan/security precedent):
- `GET  /api/v1/sites/:siteId/vulnerabilities` — open findings for a site (severity-sorted), incl. attribution notices from feed_meta.
- `POST /api/v1/sites/:siteId/vulnerabilities/rescan` (`PermSiteWrite`) — enqueue `RescanSite`.
- `POST /api/v1/sites/:siteId/vulnerabilities/:id/dismiss` + `/restore` (`PermSecurityManage`) — set/clear `dismissed`.
- `POST /api/v1/sites/:siteId/vulnerabilities/:id/remediate` (`PermSiteWrite`) — map finding → `update.Item{Type: kind, Slug: slug, Version: fixed_version || "latest"}` and hand to `update.CreateRun` for the single site. Do NOT reimplement update execution (audit §4); `update/model.go:89` version-pin charset already accepts `X.Y.Z` / `"latest"`.

Tenant fleet rollup (gated `PermSiteRead`, tenant-scoped, not per-site):
- `GET /api/v1/vulnerabilities` — cross-site aggregation: counts by severity + a flat prioritized list (join `site_vulnerabilities` open rows across the tenant, ordered critical→low then `cvss_score` desc). Fills the global `/vulnerabilities` page.

`PermSecurityManage` already exists — reuse it for dismiss/restore.

---

## 5. Web build (`apps/web`)

Match the `features/security/` patterns. Net-new files mirror existing siblings.

### 5a. Global `/vulnerabilities` page — replace the stub
- `apps/web/src/routes/_authed/vulnerabilities.tsx` is today a `<PlannedFeature>` stub (lines 5-24). Replace with a fleet rollup: a 4-tile severity header reusing the `security-overview.tsx` tile pattern (Critical/High/Medium/Total, colored `red/amber/.../muted`, click-to-filter), then a prioritized, severity-grouped list reusing the `scan-findings-table.tsx` / `FindingCountsSummary` chip pattern (`scan-panel.tsx:308-311`). Each row: site, plugin/theme/core name, severity badge, installed → fixed version, CVE link-out, "Remediate" CTA. Sidebar/top-bar entries already wired (`sidebar.tsx:141`, `top-bar.tsx:168`).

### 5b. Per-site Vulnerabilities card + overview tile
- Add a `VulnerabilitiesCard` into the card sequence in `sites/$siteId.security.tsx` (slot after Card 4 File integrity, `data-card-id="card-vulnerabilities"`), and a 5th tile into the security-overview header (count of open vulns, red if any critical/high).
- Card body: list of vulnerable items (name, severity, installed/affected → patched version, CVE + Wordfence link, MITRE notice when CVE present), "Rescan" button (POST rescan), per-row "Update to X" (remediate) + "Dismiss".

### 5c. Query hooks + remediation wiring
- `apps/web/src/features/security/use-vuln.ts` mirroring `use-scan.ts`: `useSiteVulnerabilities(siteId)`, `useFleetVulnerabilities()`, `useRescanVulns`, `useDismissVuln`, `useRemediateVuln`.
- Remediation CTA reuses `features/updates/use-row-update.ts` / the update wizard — the "Remediate" action either calls the new `/remediate` endpoint (server maps to `update.CreateRun`) or, for fleet, opens the existing update wizard pre-filled with `{Type, Slug, Version: fixed_version}`.

### 5d. Attribution (GATE 0 DoD)
- Vuln views render an attribution footer: Defiant copyright/license (from feed_meta), and **MITRE notice on any row that shows a CVE**. CVE + Wordfence `references` link-out per finding.

---

## 6. Scheduling + refresh cadence

- **Feed refresh:** River periodic job, **hourly** poll of the Scanner feed (per WPMgr convention + Wordfence rate-limit guidance; never tighter). On a successful refresh that changed records, enqueue `RescanAll` (fan-out, throttled). Optional later: subscribe to the free Wordfence **webhook** (HMAC-SHA256) for near-real-time pushes instead of polling — out of scope for v1, noted as a fast-follow.
- **Inventory-change re-match:** whenever a site's inventory changes, enqueue `RescanSite(siteID)`. Hook points (all already exist): metadata ingest (`pushMetadata` write path), `refresh_inventory` completion, and successful `update` run completion (so a remediated vuln flips to `resolved` immediately). This keeps findings consistent without waiting for the hourly cycle.
- **Findings lifecycle:** `RescanSite` upserts (refresh `last_seen`) matched, and resolves (set `resolved_at`) findings no longer matched (item updated/removed). Dismissed findings stay dismissed across rescans unless the underlying item version changes.
- **Self-host degrade:** if `WPMGR_WORDFENCE_API_KEY` is unset, the feed worker no-ops, feed_meta `ok=false`, and the UI shows a "configure your free Wordfence Intelligence key" state instead of empty findings.

---

## 7. Prioritized build plan (by layer, dependency order)

**GATE 0 checkpoint — already PASS (§1).** Carry the 4 attribution/key obligations as DoD gates; security-reviewer signs them off in step 6. No user decision required to start.

| # | Step | Owner | Reuse vs net-new |
|---|------|-------|------------------|
| 0 | **GATE 0 attribution/key obligations recorded as DoD checklist** (Defiant + MITRE notices, link-back, private one-key-per-caller, `WPMGR_WORDFENCE_API_KEY` env + cryptbox for SaaS). | backend-architect + security-reviewer | net-new policy doc lines |
| 1 | **m79 migration** — `wordfence_vuln_feed` + `wordfence_vuln_software` (+ index) + `wordfence_vuln_feed_meta` (public, NO RLS); `site_vulnerabilities` (RLS: tenant + agent policies, FORCE, idempotent). | backend-architect | reuse `m77` cache pattern + `m77` `site_file_baseline` RLS template; net-new tables |
| 2 | **`wpversion` compare pkg + matcher** — PHP `version_compare` semantics + `IsVulnerable(installed, affected)`; PHP-fixture unit tests (`-RC`, 2-seg, `-beta`). | backend-architect | net-new (high-risk, dedicated tests) |
| 3 | **Feed ingester worker** — V3 Scanner (+Production enrich) fetch, transactional upsert/prune, meta + copyrights, 429/backoff, key-unset no-op. | backend-architect | reuse `scan/worker.go:469` fetch-cache pattern; net-new River periodic job |
| 4 | **`internal/vuln` domain** — model/repo(sqlc)/service (`RescanSite`/`RescanAll`) + hand-written-Gin handler (per-site list/rescan/dismiss/remediate + fleet rollup), `authz` gating, `update.CreateRun` remediation mapper. Wire inventory-change + post-update + post-feed-refresh rescan triggers. | backend-architect | reuse `update` domain (remediation), `site.ParsedComponents`/`ListAllSiteIDs`, scan/security handler pattern, `PermSecurityManage`; net-new domain |
| 5 | **Web** — replace `/vulnerabilities` stub (fleet rollup tiles + prioritized list); per-site `VulnerabilitiesCard` + 5th overview tile in `$siteId.security.tsx`; `use-vuln.ts`; remediation CTA → `use-row-update.ts`/update wizard; Defiant+MITRE attribution + CVE link-out. | frontend-architect | reuse `security-overview`/`scan-findings-table`/`features/updates`; net-new page + card + hooks |
| 6 | **Security review** — RLS isolation on `site_vulnerabilities`, fleet-rollup tenant scoping, remediate-endpoint can't be abused to update arbitrary slug/version (lean on `update` validateItems), **GATE 0 attribution rendered + key never leaked to client/logs**. | security-reviewer | review |
| 7 | **Docs + changelog + landing** — CHANGELOG, landing content.ts (Security suite vuln scanner), this design doc finalized; mention Wordfence Intelligence as the data source (neutral, allowed). | docs-writer | DoD gate |

**Agent (wp-agent-engineer): no work.** P4 is read-only against existing inventory — explicitly out of scope unless a future "scan only specific site on demand" needs a new signed command (it does not; `refresh_inventory` already covers re-poll).

**Reuse summary:** inventory pipeline (agent + `Site.Components`), `update` domain (remediation engine), `m77`/`m78` public-cache + RLS migration templates, `scan/worker.go` fetch-cache pattern, hand-written-Gin + `authz` routing, `PermSecurityManage`, severity vocabulary, `security-overview`/`scan-findings-table`/`features/updates` web primitives, `site.ListAllSiteIDs` fan-out.
**Net-new:** `internal/vuln` domain, `wpversion` compare pkg + range matcher, feed ingester worker, m79 tables, `/vulnerabilities` fleet page + per-site card + `use-vuln.ts`.

**Deploy (full-stack checklist):** api image → wpmgr-api, web image → wpmgr-web, m79 auto-on-boot. No agent release, no media-encoder. CP before web. Set `WPMGR_WORDFENCE_API_KEY` on prod api before first feed poll.
