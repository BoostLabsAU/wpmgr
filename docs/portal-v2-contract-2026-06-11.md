# Portal v2 Build Contract — invite email fix + client-portal dashboard redesign

Date: 2026-06-11 · Branch: feat/performance-suite · Judge: architect-judge (all load-bearing claims re-verified against source)

This contract is **binding**. DTO field lists are **exhaustive** — any field shipped beyond what is listed here is a security-review finding, not a nice-to-have.

---

## 0. Judge's verification notes (conflicts resolved)

1. **Invite email root cause CONFIRMED by reading source.** `subjects` map (apps/api/internal/mailer/renderer.go:20-37) has 13 entries; `client_portal_invite` is absent. `Enqueuer.Enqueue` gates on that map and errors before `insertLog`/River insert (email_job.go:68-72). `CreateClientInvitation` discards the error with `_ =` (apps/api/internal/invitation/service.go:451-459). Template files exist and are embedded; enqueue data exactly matches template vars. Trace finding accepted in full.
2. **Series-buckets conflict RESOLVED against Lens A.** `seriesBuckets=0` does **not** skip the series: `uptime.Service.Uptime` always calls `QuerySeries` (uptime/service.go:101-105) and `pgStore.QuerySeries` defaults `buckets<=0` to **100** (metrics/postgres.go:172-174). Consequence: the cheap fill of `/portal/sites` must use `metricsStore.QueryAggregate` directly, **not** `uptime.Service.Uptime`, to avoid paying a 100-bucket series query per site.
3. **Report-aggregator reuse surface CONFIRMED.** `report.Sources` + `BuildReportData` (report/aggregator.go:44-192) compute per-site uptime pct + **Daily** day buckets + incidents + TLS expiry, backups count, updates by type incl. Failed, CWV p75+rating, and fleet `ReportTotals` — with per-section degrade-to-nil. The concrete `reportSources` adapters are already wired at cmd/wpmgr/main.go:994-1044 (range adapters end at `now`, which is exactly right for a rolling-30d dashboard).
4. **Contract gap CONFIRMED.** openapi.yaml declares `uptime_30d_pct` (line 11827) and `tls_expires_at` on PortalSite, and the web card renders both, but `portalSiteDTO` (portal/handler.go:116-123) has no uptime field and `listSites` never sets TLS. Dead-on-arrival fields; fixed in this contract.

---

## 1. INVITE EMAIL FIX (binding spec — minimal, mirrors the org-invitation pattern)

### 1.1 Core fix (the one-liner)

In `apps/api/internal/mailer/renderer.go` add to the `subjects` map (with an `// m66: client portal.` group comment, matching house style):

```go
"client_portal_invite": "You have been invited to a client portal",
```

Subject is static and brand-neutral (AgencyName is dynamic per-tenant; matches the legacy plaintext subject at invitation/service.go:462). This alone makes prod send the email. **Do not** relocate the enqueue call — it already runs after the invitation tx commits, mirroring the working org-invite flow (service.go:142-149).

### 1.2 Template data (unchanged — pinned)

The enqueue at service.go:452 keeps exactly these 6 keys (verified to match `client_portal_invite.{html,txt}.tmpl`): `Name: "there"`, `InviterName`, `ClientName`, `AgencyName`, `AcceptURL`, `ExpiresHours: "168"`.

### 1.3 Stop swallowing the error + `email_sent` honesty

1. `CreateClientInvitation` (invitation/service.go:399): capture the enqueue result instead of `_ =`. New signature:
   `(acceptLink string, invitationID uuid.UUID, expiresAt time.Time, emailSent bool, err error)`.
   `emailSent = s.enqueuer != nil && enqueuer.Enabled(ctx) && Enqueue(...) == nil`. On enqueue error: `slog.Warn`, **never** fail invitation creation. Legacy `s.mailer` fallback branch: `emailSent = Send(...) == nil`.
2. Extend the `InviteEnqueuer` interface (invitation/service.go) with `Enabled(ctx context.Context) bool`; add the trivial delegate on `*mailer.Enqueuer` → `e.svc.Enabled(ctx)` (mailer.go:95). This makes `email_sent=false` when SMTP is unconfigured even though enqueue would succeed.
3. `apps/api/internal/client/member_handler.go`: update the `InviteService` interface (line 46-48); add `EmailSent bool \`json:"email_sent"\`` to `inviteResultDTO` (line 70-78). Unknown-user branch sets it from the service; existing-user branch sets `email_sent=false` (no email is sent there by design).

### 1.4 Mailer-unconfigured behavior (pinned)

Keep the existing silent-skip semantics: still enqueue when the enqueuer is wired (Service.Deliver marks the log row failed "smtp not configured" **without** error/retry, mailer.go:125-127); `email_sent=false` (via the `Enabled` gate) drives the copy-link UI. The accept link is **always** returned (ADR-045 G7 pattern) — self-hosters unaffected.

### 1.5 OpenAPI + web

- Add `email_sent` (boolean, required) to `ClientMemberInviteResult` in packages/openapi/openapi.yaml; regen **both**: `go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate`.
- Web `routes/_authed/clients/$clientId.members.tsx` (toast at :173-186):
  - `email_sent=true` → "Invitation emailed to {email}" with copy link **secondary** ("or share the link directly").
  - `email_sent=false` → "Email is not configured — share this link with {email}" with CopyLink **primary**.
  - Regenerate toast (:335) unchanged — regenerate stays link-only in this release.

### 1.6 Tests (binding)

1. **Completeness test** in renderer_test.go: `fs.Glob(templateFS, "templates/*.html.tmpl")`, strip suffix, skip `_partials`; assert every base name has a `subjects` entry AND a matching `.txt.tmpl`; inversely every subjects key has both files. (This is the regression lock — TestRenderAllTemplates iterates the map only, which is why this bug shipped.)
2. Add `client_portal_invite` to `sampleData` (the 6 keys above) so render + plaintext-contains-AcceptURL tests cover it.
3. Invitation service test with a fake `InviteEnqueuer`: asserts template name `"client_portal_invite"`, recipient, exactly the 6 data keys; enqueue error → invitation still created + `email_sent=false`; nil enqueuer → `email_sent=false` + link still returned.
4. Handler test: addMember unknown-user response includes `email_sent` per fake.

Out of scope (follow-up candidate): re-sending the email on regenerate (member_handler.go:423 is link-only by design today).

---

## 2. DASHBOARD REDESIGN — judgment + binding spec

### 2.1 Verdict

**Winner: Lens A ("the monthly report, live") as the architecture**, with Lens B's best ideas grafted on.

Why A wins:
- **Total reuse.** `report.BuildReportData` + the already-wired `reportSources` (main.go:994-1044) produce every hero KPI, per-site daily uptime series, incidents, TLS, backup/update counts, and CWV ratings, with per-section degradation built in. One new endpoint, ~zero new aggregation code.
- **Narrative coherence.** The portal becomes the live twin of the v0.38 white-label report clients already receive; the report callout ties them together. Lens B's status-page framing is a weaker fit for an agency proof-of-work portal.
- **Data-inventory discipline.** Only ONE genuinely NEEDS-NEW-AGGREGATE item is admitted: the recent-work feed (2 sqlc queries). Everything else is EXPOSABLE-NOW or CHEAP. (Within the 2-3 budget.)
- Lens B's signature per-day cell bar rested partly on a wrong reading of `seriesBuckets=0` (§0.2) and would have required widening the portal UptimeService; the same information rides the aggregator's `UptimeSection.Daily` for free via Lens A's endpoint.

Grafted from Lens B: **PortalStatusBanner** (status-page "all systems" affordance), the **proof-of-work sentence** ("In the last 30 days, {agency} performed X updates and Y backups across your sites."), **CountUp** numerals (ported from landing), **letter avatars** (no favicon hotlinking), the **brand-color usage rules**, and the partial-degradation discipline.

Explicitly OUT of v2: per-day cell bars; favicons/screenshots; email deliverability stats in the portal (avoids the RLS-bypassing `GetFleetStatsBySite` path entirely); wp/php versions; pending-update counts; incident window lists; `/portal/sites/:id/uptime` series enrichment (its `incidents` DTO is a window list, but only counts are cheaply available — leave the endpoint untouched rather than ship imprecise windows); vitals 28d trend endpoint; regenerate-invite resend.

### 2.2 API changes (GET-only · no `:clientId` in any route · same `RequireClientPortal` gating)

#### (1) NEW: `GET /api/v1/portal/summary` (optional `?range=30d`; only `30d` valid in v2, else 400)

Mounted in the existing portal group (handler.go:84-96). Implementation: pass the existing `reportSources` into `portal.NewHandler` (from main.go; nil-tolerant) **with the email source disabled** (`Sources.GetFleetStatsBySite = nil` — email stats are not exposed in the portal). For each `clientID` in `p.ClientIDs`, call `report.BuildReportData` with `BuildInput{TenantID, ClientID, Client (branding the handler already loads), AgencyName, PeriodStart: now-30d, PeriodEnd: now, Schedule: nil}`; then **filter `rd.Sites` to `p.AllowedSiteIDs`** (defense in depth) and recompute totals over the filtered set. Join `status` + `last_backup_at` from the handler's existing sites query (same source `listSites` uses — `ListClientSites`/`siteSvc.List` already returns Status + LastBackupAt; `SiteReport` carries only ID/Name/URL). `latest_report` = first row of the existing `ListCompletedReportsForClients` (already created_at DESC).

**PortalSummary DTO (exhaustive):**

```jsonc
{
  "generated_at": "RFC3339",
  "period_start": "RFC3339", "period_end": "RFC3339", "period_label": "12 May – 10 Jun 2026",
  "totals": {
    "site_count": 0,
    "avg_uptime_pct": 99.97,        // null when no site has checks — never invent zeros
    "incidents": 0,
    "backups_count": 0,
    "updates_applied": 0,
    "updates_failed": 0
  },
  "vitals_overall": "good|needs-improvement|poor|null",   // worst rating across sites with samples
  "vitals_distribution": {                                 // site COUNTS per rating; no-sample sites excluded
    "lcp": {"good":0,"needs_improvement":0,"poor":0},
    "inp": {"good":0,"needs_improvement":0,"poor":0},
    "cls": {"good":0,"needs_improvement":0,"poor":0}
  },
  "uptime_daily": [ {"day":"2026-05-12","uptime_pct":100.0} ],  // fleet day-wise average across sites with data
  "sites": [{
    "id":"uuid", "name":"", "url":"", "status":"",         // status = SAME vocabulary as /portal/sites
    "uptime_pct": 99.98,                                    // null if no checks (UptimeSection nil)
    "uptime_daily": [ {"day":"2026-05-12","uptime_pct":100.0} ],  // ≤30 pts ← UptimeSection.Daily
    "incidents": 0,                                         // ← UptimeSection.Incidents (countIncidents)
    "last_backup_at": "RFC3339|null",
    "backups_in_period": 0,                                 // ← BackupSection.CompletedInPeriod
    "updates_in_period": 0,                                 // ← UpdateSection.Total
    "vitals_rating": "good|needs-improvement|poor|null",    // worst of LCP/INP/CLS PerfMetric ratings
    "tls_expires_at": "RFC3339|null"                        // ← UptimeSection.TLSExpiry
  }],
  "latest_report": { "id":"uuid", "period_start":"", "period_end":"", "completed_at":"" }, // or null
  "recent_work": [{                                          // max 20, SUCCESSES ONLY, desc by occurred_at
    "type": "update|backup",
    "site_id": "uuid", "site_name": "",
    "label": "WooCommerce 9.1.2 → 9.2.0",                    // update: name + from→to; backup: kind + human size
    "occurred_at": "RFC3339"
  }]
}
```

Notes: per-site `avg_latency_ms` deliberately omitted (not used by the chosen UI; keep the surface minimal). `UptimeDay.AvgLatencyMs` exists server-side but is not mapped.

#### (2) CHANGED: `GET /api/v1/portal/sites` — populate the already-declared fields

- Add `Uptime30dPct *float64 \`json:"uptime_30d_pct,omitempty"\`` to `portalSiteDTO`; fill it + `TLSExpiresAt` in `listSites` (handler.go:281-295). **No OpenAPI change** — both fields are already declared (openapi.yaml:11827; generated TS type already has them; the existing card renders them).
- Implementation: per allowed site, `metricsStore.QueryAggregate(ctx, tenant, siteID, 30*24h)` for pct (do **not** use `uptime.Service.Uptime` — see §0.2) and `QueryLatest` for TLS expiry. Sites with `Checks==0` → field omitted. The metrics store runs `InAgentTx` (RLS-bypassing) — IDs must come only from the already-RLS-scoped sites list.

#### (3) NEW sqlc queries (recent_work — the single admitted NEEDS-NEW-AGGREGATE item)

- `ListAppliedTasksForSites(tenant_id, site_ids uuid[], since, limit)` — modeled on `ListAppliedTasksForSite` (updates.sql:76-87; `status='succeeded'`); returns site_id, target_type, name/slug, from_version, to_version, finished_at.
- `ListRecentCompletedSnapshotsForSites(tenant_id, site_ids uuid[], since, limit)` — backup_snapshots `status='completed'`; returns site_id, kind, total_size, finished_at.
- Merge + sort desc in Go, cap 20; site_name joined in Go from the sites list. Run under `RunTenantTx` with the portal principal so the m19 `app.site_scope` restrictive policies **double-gate** (update_tasks + backup_snapshots both carry the policy); `site_ids` param is still `p.AllowedSiteIDs` only.
- **sqlc discipline**: `sqlc generate` with the prebuilt binary, never hand-edit `*.sql.go`; verify re-running generate is a no-op.

#### (4) OpenAPI

Add `PortalSummary` schema + `/api/v1/portal/summary` path; regen both clients (`go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate` — `make gen` is a stub).

### 2.3 Web component tree (binding)

```
routes/portal/index.tsx                       (REWRITE — page makes exactly 2 requests: overview + summary)
└─ PortalIndexPage
   ├─ PortalStatusBanner     features/portal/portal-status-banner.tsx   [NEW]
   │    ← derived CLIENT-SIDE from summary.sites[].status + generated_at ("Checked X ago")
   ├─ PortalHero             features/portal/portal-hero.tsx            [NEW]
   │    heading + period label ("Last 30 days, at a glance") + 5 KPI tiles w/ CountUp:
   │    sites · uptime % · backups completed · updates applied (subline "{n} failed" only when >0) · site speed
   ├─ PortalMonthGlance      features/portal/portal-month-glance.tsx    [NEW]  (md:grid-cols-2)
   │    ├─ UptimeTrendCard → shared UptimeChart (components/charts/uptime-chart.tsx) ← summary.uptime_daily
   │    └─ VitalsBandCard  → 3 × shared RumDistributionBar (token-patched, §2.6) ← summary.vitals_distribution
   │         rows labeled Loading speed / Responsiveness / Visual stability; legend "{n} of {m} sites"
   ├─ PortalReportCallout    features/portal/portal-report-callout.tsx  [NEW]
   │    ← summary.latest_report; buttons reuse fetchPortalReportDownload + portal-reports-table spinner pattern
   ├─ "Your sites" grid → PortalSiteCard v2 (EXTEND features/portal/portal-site-card.tsx) ← summary.sites
   │    + letter avatar (initial on --color-primary/10) + uptime % + shared Sparkline of uptime_daily
   │    + vitals chip (Good/Fair/Poor dot; omitted when null); existing TLS + last-backup rows now actually fire;
   │    KEEP the locked soft-status helper verbatim ("Monitoring active" / "Needs attention")
   └─ PortalRecentWork       features/portal/portal-recent-work.tsx     [NEW]
        lede = proof-of-work sentence (hidden when both counts are 0); day-grouped rows (Today/Yesterday/date);
        icons RefreshCw / DatabaseBackup; copy: "Updated {name} {a} → {b} on {site}", "Completed {kind} backup of {site} ({size})"
+ PORT apps/landing/src/components/count-up.tsx → apps/web/src/components/ui/count-up.tsx (zero-dep, reduced-motion safe)
+ NEW hook usePortalSummary() in features/portal/use-portal.ts (house key pattern, staleTime 5 min)
```

This page stops calling `/portal/sites` (cards fed from `summary.sites`); the detail route `sites.$siteId.tsx` keeps using it for siteId validation. Cards keep linking to the existing `/portal/sites/$siteId` detail page (already security-reviewed) — no inline expansion.

### 2.4 Per-block data sources

| Block | Source | Tier |
|---|---|---|
| Banner counts + "Checked X ago" | summary.sites[].status (client-side) + generated_at | NEW endpoint |
| Hero KPIs | summary.totals + vitals_overall | CHEAP (aggregator reuse) |
| Uptime trend chart | summary.uptime_daily | CHEAP (aggregator reuse) |
| Vitals band | summary.vitals_distribution | CHEAP (aggregator reuse) |
| Report callout | summary.latest_report (existing query) | EXPOSABLE-NOW |
| Site cards | summary.sites (status/last_backup from sites rows; rest from sections) | EXPOSABLE-NOW + CHEAP |
| Recent work | summary.recent_work | NEEDS-NEW-AGGREGATE (2 sqlc queries) |
| /portal/sites uptime + TLS badges (detail-page entry list) | QueryAggregate + QueryLatest fill | EXPOSABLE-NOW (contract-gap fix) |

### 2.5 Brand color rules (pinned)

- Brand hex enters **only** via the portal-shell's validated, scoped `--color-primary` override (portal-shell.tsx:87-92). The shell invariant stays locked: brand NEVER recolors status/success/warning/destructive semantics.
- Brand-tinted: hero accent rule (the live twin of the report's accent band), KPI numerals, letter avatars, report "View report" button, "View reports" links.
- Everything else: Impeccable tokens from globals.css only (`--color-card/-border/-muted-foreground/-success(-subtle)/-warning(-subtle)/-destructive/-chart-*`). No raw hex/Tailwind palette classes anywhere new.
- Numerals: `font-mono tabular-nums` (house style). Motion (CountUp, banner one-shot pulse, any stagger) wrapped in `prefers-reduced-motion` guards.

### 2.6 Shared-component reuse (pinned)

- `Sparkline` (components/charts/sparkline.tsx) on site cards — its <2-point spacer handles new sites.
- `UptimeChart` (components/charts/uptime-chart.tsx) for the fleet trend — SLA line + ChartEmpty come free.
- `RumDistributionBar` — **must be patched** from hard-coded `bg-green-500/amber-400/red-500` to `--color-success/-warning/-destructive` tokens as part of this build (it currently fails the token rule), then fed site-count percentages.

### 2.7 Empty / loading / error states (binding)

- **Loading**: skeletons mirroring final layout (heading, 5 KPI tiles h-16, two chart cards h-[200px], card grid, 4 timeline rows).
- **Summary endpoint fails** (rare — sections degrade server-side to nil): preserve today's thin header + render an inline error card with retry below it; full PageError only if `/portal/overview` itself fails.
- **Per-tile null** → "—" with muted "No data yet". Never invent zeros (a 0% uptime tile is a lie).
- **Charts**: UptimeChart's ChartEmpty for <2 points; vitals band all-null → single ChartEmpty "Speed data appears once visitors browse your sites."
- **Report callout**: render **nothing** when latest_report is null (no empty promises).
- **Zero sites**: keep the existing Globe empty state verbatim; suppress banner/KPIs/timeline.
- **Recent work empty**: muted "Work performed on your sites will appear here."; proof-of-work sentence hidden when both counts are 0 (never brag about zero; phrase only the non-zero one if mixed).

### 2.8 Mobile

- Hero KPIs `grid-cols-2 sm:grid-cols-3 lg:grid-cols-5`, uptime tile first.
- Order: hero → report callout → sites → charts → timeline (desktop: hero → charts → callout → sites → timeline).
- Glance cards stack; chart heights drop to ~140; sites grid keeps the existing 1/2/3-col breakpoints; timeline uses relative timestamps only.

### 2.9 Copy rules

Soft status wording is **locked**: "Monitoring active" / "Needs attention" on cards (keep the helper in portal-site-card.tsx:24-30). Banner: "All {n} sites operating normally" (success-subtle) / "{n} site(s) need attention — we're on it." (warning-subtle, names listed when ≤2), `role="status" aria-live="polite"`. Client vocabulary throughout (Loading speed, not LCP). Recent work shows completed-work verbs, successes only. No competitor names, no em dashes in landing copy.

---

## 3. SECURITY CONSTRAINTS (restated for security-reviewer)

1. **Field lists in §1.3/§2.2 are exhaustive.** Any extra response field is a finding.
2. **No `:clientId` in any portal route** (IDOR doctrine, handler.go header comment). Identity derives solely from the principal: `p.ClientIDs` + `p.AllowedSiteIDs` (domain/principal.go).
3. `/portal/summary` sits inside the `RequireClientPortal` group; `BuildReportData`'s site list is filtered to `p.AllowedSiteIDs` before mapping; totals recomputed post-filter.
4. **Two RLS-bypassing paths**: the metrics store (`InAgentTx`) and `GetFleetStatsBySite`. The first may only ever be called with site IDs taken from the RLS-scoped sites list / AllowedSiteIDs; the second is **not used by the portal at all** in v2 (email source nilled).
5. recent_work queries run under `RunTenantTx` with the portal principal → m19 `app.site_scope` restrictive policies double-gate `update_tasks` + `backup_snapshots`; `site_ids` params are still AllowedSiteIDs only.
6. `generated_reports` has **no** site_scope policy — `latest_report` keeps the in-app `client_id = ANY(p.ClientIDs)` predicate; report downloads stay on the existing `/portal/reports/:reportId/download` (re-checks client ownership). **Blob keys/URLs are never exposed.**
7. Never expose (unchanged never-show list): backup internals (blob keys, destinations, failure reasons, restore runs, schedule config), email log detail (recipients/subjects/errors/bodies, suppression), PENDING-update slugs or plugin/theme inventory, PHP error logs, diagnostics payloads, host provider/egress IPs, agent version/keys/heartbeat internals, uptime probe error_text, scan finding detail, anything billing/cost. Applied-update display names + versions ARE allowed (they are the proof-of-work feed).
8. Per-site portal routes keep `RequireSiteAccess("siteId")` unchanged (handler.go:91).
9. Email fix: `email_sent` is a bare boolean; SMTP config/state/errors are never echoed to the agency UI beyond it; the accept link stays a single-use token; an enqueue failure must never fail invitation creation (availability of the copy-link path).

---

## 4. BUILD ORDER + GATES

**Phase 1 — backend-architect** (one branch, two commits OK): §1 invite email fix (subjects entry, email_sent plumbing, OpenAPI, tests) + §2.2 (`/portal/summary`, `/portal/sites` field fill, 2 sqlc queries, OpenAPI).
Gates: `go build ./... && go test ./...` (apps/api); sqlc regen is a true no-op after committing (`/tmp/sqlc generate && git diff --stat internal/db/sqlc` empty); both codegens run and committed (`go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate`) and re-running them is a no-op.

**Phase 2 — frontend-architect**: §2.3-§2.9 dashboard rewrite + §1.5 invite toast + CountUp port + RumDistributionBar token patch.
Gates: `pnpm -C apps/web typecheck && lint && build`; `npx impeccable detect` on the portal tree + every touched file (hard gate); the existing **no-useMutation grep gate stays** (no new `useMutation` usage may appear in apps/web/src).

**Phase 3 — security-reviewer** (blocking): checklist = §3 verbatim; specifically verify DTO field exhaustiveness against §1.3/§2.2, AllowedSiteIDs-only derivation in the summary handler and both RLS-bypass paths, no `:clientId`, blob keys absent, email_sent honesty (false on unconfigured SMTP).

**Phase 4 — docs-writer** (DoD): CHANGELOG entry + landing content.ts update per the standing docs SOP.

---

## 5. OPEN PRODUCT DECISIONS

**None are user-blocking.** Defaults locked by this contract (overridable post-ship):
- Rolling 30-day window labeled "last 30 days" (calendar-month framing lives only in the report callout).
- Recent work shows successes only; `updates_failed` appears only as the hero subline count.
- Email deliverability stats stay OUT of the portal.
- Letter avatars (no favicons/screenshots); agent `get_site_icon_url()` metadata is a future option.
- Regenerate-invitation stays link-only (email resend = follow-up).
- wp/php versions + pending-update counts stay hidden from clients.
