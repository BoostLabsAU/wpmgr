# Security Suite — Phase 2: Full File-Integrity Monitoring (build design)

> CP-first build design for backend-architect (CP), wp-agent-engineer (agent), frontend-architect (web).
> Clean-room. Techniques described neutrally; no competitor named.
> Status: design only. Extends the existing `internal/scan` domain + `checksums.go` + agent `ScanCommand` — **does not rewrite them.**

## 0. Goal (recap)

Extend WPMgr's existing **core-checksum integrity scan** into full **file-integrity monitoring**:

1. **Full-filesystem chunked/resumable baseline + diff** (Added / Changed / Removed) — for files with no public checksum (premium/custom plugins, themes, `wp-content`).
2. **Self-written expected-hash tracking** — WPMgr's own writes (config writers, perf suite) are recorded so they never show up as false positives.
3. **Plugin/theme integrity via the FREE WP.org plugin checksums** (`downloads.wordpress.org/plugin-checksums/{slug}/{version}.json`), cached in the control plane exactly like core checksums.
4. **File-change notifications + audit-log events.**
5. **Dashboard surface** on the existing site Security tab.

All feeds are free/public. No paid dependency. **Gate 0 does NOT apply to Phase 2.**

---

## 1. What we EXTEND vs ADD

### Reuse as-is (do NOT rewrite)

| Asset | Path | Reuse |
|---|---|---|
| Resumable DFS hash walker | `apps/agent/includes/support/class-file-scanner.php` | The cursor/DFS/`md5_file()` engine already supports `full`/`files` kinds. Phase 2 needs **zero new walker** — it already walks the whole FS. |
| Scan command + roots | `apps/agent/includes/commands/class-scan-command.php` | `kind=full` and `kind=files` already exist (`resolveRoots`, L220-248). Phase 2 drives these kinds, plus adds `plugins` (see §4). |
| River multi-step driver | `apps/api/internal/scan/worker.go` (`ScanRunWorker.Work`) | The partial→re-enqueue→cursor-persist loop (L102-214) is kind-agnostic. Reuse verbatim; only the **finish/diff** step branches by kind. |
| Checksum cache | `apps/api/internal/scan/checksums.go` + `wporg_core_checksums*` tables | Add a sibling `Plugin(slug,version)` method + sibling tables (§3). Same positive/negative TTL + SSRF-safe `HTTPDoer` pattern. |
| Findings model + dedup + ignore | `scan_findings` table, `Repo.UpsertFindings`, `IgnoreFinding`, `FetchFile` | Add new `finding_type` values + a baseline source. Dedup key + ignore-survives-rescan semantics reused unchanged. |
| Scan REST + hooks | `apps/api/internal/scan/handler.go`, `apps/web/src/features/security/use-scan.ts` | `kind` is already free-form on `POST /scans`. New kinds + finding types slot into existing routes/hooks. |
| Audit recorder | `apps/api/internal/audit` (worker already uses it) | Add `Action*` consts for baseline/file-change. |
| Operator alerts | `internal/uptime` Dispatcher `FireSecurityEvent` + `alert_configs.NotifySecurity` | Emit file-integrity findings as a security alert (reuse `AlertSecurity` or add `AlertFileIntegrity`). |
| SSE live push | `internal/site/events` Publisher (ULID contract) | Publish a new `Type:"scan.finding"` event so the dashboard refreshes live. |

### Add (new)

- **CP:** plugin/theme checksum fetch+cache (`ChecksumProvider.Plugin`), a **per-site baseline** of last-good hashes, the **A/C/R diff classifier** (`diffFiles`), a **self-written-hash registry** so WPMgr's own writes are pre-trusted, plus new finding types and a scheduled-scan trigger.
- **Agent:** **no new walker** — only (a) a `plugins` scan-kind helper to scope a walk to one plugin/theme dir, and (b) a tiny `record_managed_write` hook so the perf-suite/config writers report their own file hashes (self-written tracking). Plugin/theme inventory already exists.
- **Web:** a "File changes" section + a "Plugin / Theme integrity" section on the Security tab; extend the finding-type chip map; an optional expected-vs-actual presentation.

---

## 2. Architecture: where the baseline lives

**Decision: the baseline lives in the CP (Postgres), not the agent.** Rationale:

- The CP already stages every file hash per run in `scan_run_hashes` and purges them on completion. A baseline is simply *the last completed run's hashes, promoted to a durable per-site table* instead of purged.
- Keeping the baseline CP-side keeps the agent **stateless** (consistent with the connection-lifecycle + backup-scheduling architecture: "agent is a stateless push-target"), avoids a second on-disk manifest the agent must protect/exclude, and lets the diff run in Go where the checksum cache + inventory already are.
- The agent stays a pure hash producer. The **only** agent-side state is the **self-written-hash report** (§5), which the agent emits as events, not a durable file.

Flow (one `kind=full`/`files` run):

```
operator/scheduler → POST /scans {kind:"full"}
   → ScanRunWorker loop (existing): agent walks FS in 12s chunks, streams hashes → scan_run_hashes
   → on status=done → finishFiles():
        load staged hashes (this run)
        load baseline hashes (site_file_baseline)   ← NEW durable table
        load known-good checksums:
            core  → ChecksumProvider.Core (existing)
            plugins/themes → ChecksumProvider.Plugin (NEW, wp.org plugin-checksums)
        load self-written registry (site_managed_files)  ← NEW
        diffFiles() → Added / Changed / Removed / known-good-mismatch findings
        UpsertFindings (existing dedup/ignore semantics)
        promoteBaseline(): replace site_file_baseline for this site with this run's hashes  ← NEW
        emit audit + alert + SSE
```

**Baseline bootstrapping:** the **first** full scan has no baseline → it produces **no Added/Changed/Removed findings** (only known-good checksum mismatches for core + wp.org plugins). It only *establishes* the baseline. The dashboard shows "Baseline established" for run #1; A/C/R diffs begin on run #2. This is the standard cold-start contract and avoids a first-scan flood.

---

## 3. Data model (m77)

Migration file: **`apps/api/migrations/20260710000000_m77_file_integrity.sql`** (next after m76; the runner auto-discovers `.sql` by timestamp via `//go:embed *.sql` in `migrations.go` — no registry to edit).

Follow the m15/m76 RLS template exactly: `ENABLE` + `FORCE ROW LEVEL SECURITY`, policies `<table>_tenant_isolation` (GUC `app.tenant_id`) and `<table>_agent` (GUC `app.agent = 'on'`), FKs to `tenants(id)` + `sites(id)` `ON DELETE CASCADE`, all DDL wrapped in idempotent `DO $$ ... IF NOT EXISTS ... $$`.

### 3.1 `site_file_baseline` — durable last-good per-site hash set (tenant-scoped, RLS)

The promoted snapshot the next run diffs against. One row per (site, path).

```
site_id      uuid    NOT NULL
tenant_id    uuid    NOT NULL
path         text    NOT NULL          -- site-relative, forward-slash
md5          text    NOT NULL
size         bigint  NOT NULL DEFAULT 0
mtime        bigint  NOT NULL DEFAULT 0
is_link      boolean NOT NULL DEFAULT false
source       text    NOT NULL DEFAULT 'baseline'
                     -- 'baseline' | 'wporg_core' | 'wporg_plugin' | 'managed'
                     CHECK (source IN ('baseline','wporg_core','wporg_plugin','managed'))
updated_run  uuid    NOT NULL          -- run that last wrote this row
updated_at   timestamptz NOT NULL DEFAULT now()
PRIMARY KEY (site_id, path)
INDEX (tenant_id, site_id)
```

Promotion is a single transaction per run: `DELETE FROM site_file_baseline WHERE site_id=$1; INSERT … SELECT FROM scan_run_hashes WHERE run_id=$run` (or upsert-then-prune-stale). Size-bounded by the same exclude logic the walk uses, so it stays a few hundred KB/site.

### 3.2 `site_managed_files` — self-written expected-hash registry (tenant-scoped, RLS)

When WPMgr writes a file (perf-suite `.htaccess`/`object-cache.php`/minified assets, config writers, Phase 1 wp-config block), the agent reports the resulting hash here so the diff never flags WPMgr's own writes as Changed/Added.

```
site_id      uuid    NOT NULL
tenant_id    uuid    NOT NULL
path         text    NOT NULL
md5          text    NOT NULL          -- '' = "managed, ignore any content" (e.g. cache dir churn)
managed_by   text    NOT NULL          -- 'perf_cache' | 'config_writer' | 'object_cache' | 'hardening' | ...
updated_at   timestamptz NOT NULL DEFAULT now()
PRIMARY KEY (site_id, path)
INDEX (tenant_id, site_id)
```

`md5=''` means "this path is WPMgr-managed; suppress ALL findings for it regardless of content" (used for churning dirs). A specific `md5` means "expected exactly this" — a *different* hash there is still a real Changed finding (tamper of a managed file).

### 3.3 `wporg_plugin_checksums` + `wporg_plugin_checksums_meta` — plugin/theme checksum cache (NO RLS, public reference)

Mirror `wporg_core_checksums` exactly. Keyed on slug+version (plugins and themes share the table; `kind` column disambiguates).

```
wporg_plugin_checksums
  kind        text NOT NULL CHECK (kind IN ('plugin','theme'))
  slug        text NOT NULL
  version     text NOT NULL
  path        text NOT NULL          -- plugin-relative, e.g. "akismet.php"
  md5         text NOT NULL          -- one of the accepted md5 variants (see note)
  fetched_at  timestamptz NOT NULL DEFAULT now()
  PRIMARY KEY (kind, slug, version, path, md5)   -- md5 in PK: wp.org allows multiple md5 variants per file

wporg_plugin_checksums_meta
  kind        text NOT NULL CHECK (kind IN ('plugin','theme'))
  slug        text NOT NULL
  version     text NOT NULL
  fetched_at  timestamptz NOT NULL DEFAULT now()
  ok          boolean NOT NULL DEFAULT true
  PRIMARY KEY (kind, slug, version)
```

> **Edge case from the live endpoint:** wp.org plugin-checksums JSON has `files: { "<path>": { "md5": <string|array>, "sha256": <string|array> } }`. A file may carry **multiple accepted md5 variants** (line-ending / build variants). The cache must store **all** variants (md5 in the PK) and the diff treats a file as known-good if its hash matches **any** stored variant. We store md5 only (matches our `md5_file()` agent hashing); sha256 is ignored for now.

### 3.4 No change to `scan_runs` / `scan_run_hashes` / `scan_findings` schema

- `scan_runs.kind` is already free-form text — add `plugins` as an accepted value in `service.go` `StartRun` validation; `full`/`files` already exist.
- `scan_findings.finding_type` is free-form text — add the new types below. No DDL.

### 3.5 New finding-type constants (`internal/scan/model.go`)

```
FindingFileAdded     = "file_added"      // in this run, not in baseline, not known-good, not managed
FindingFileChanged   = "file_changed"    // in baseline, hash differs, not known-good, not managed
FindingFileRemoved   = "file_removed"    // in baseline, absent this run
FindingPluginModified = "plugin_modified" // wp.org-hosted plugin/theme file ≠ official checksum
FindingPluginUnknown  = "plugin_unknown"  // file inside a wp.org plugin/theme dir not in its manifest
```
Severity: `file_changed`/`plugin_modified`/`plugin_unknown` = `high`; `file_added` = `medium`; `file_removed` = `low` (new `SeverityLow` const — the web `SeverityChip` already supports `low`).

---

## 4. Agent design

### 4.1 Full-FS walk — already done

`kind=full` (ABSPATH, excludes agent scratch) and `kind=files` (wp-content, excludes `['wpmgr-snapshots','wpmgr-agent','cache','upgrade']`) already walk the whole tree resumably via `FileScanner::scan()`. **No new walker.** The CP simply starts a `full` (or `files`) run; the existing partial/cursor loop streams every hash.

### 4.2 Exclusions — reuse the backup set verbatim (false-positive control)

The canonical exclude set is `BackupSource::EXCLUDE_DIRS = ['wpmgr-snapshots','wpmgr-agent','cache','upgrade']` (`class-backup-source.php:38`), mirrored in `FileScanner::EXCLUDE_DIRS` (`class-file-scanner.php:54`). `ScanCommand` already applies these per kind (`EXCLUDE_FULL` L63 for ABSPATH; the four-element list L226 for wp-content). **Keep them in sync — this is the existing "must stay in sync" contract.** This is what stops `cache/` + `upgrade/` churn from producing endless Added/Changed noise.

> **One addition:** if any self-written manifest dir is ever placed on disk, it must be added to the exclude set. With the CP-side-baseline decision (§2) there is **no** agent-side manifest, so no new exclusion is required. (If a future agent-local cache is added under `uploads/wpmgr-integrity/` via `StoragePaths::ensureHardened('integrity')`, add `wpmgr-integrity` to the exclude lists explicitly — the top-component check won't catch an uploads-nested dir automatically.)

### 4.3 `plugins` scan-kind (scope a walk to plugin/theme dirs)

To verify wp.org plugin/theme files efficiently we don't need a new walker — we need `resolveRoots()` to accept a `plugins` kind that walks `WP_CONTENT_DIR/plugins` + `/themes` (excluding `mu-plugins`, drop-ins). The CP then diffs each plugin/theme subtree against its wp.org manifest by slug. Alternatively the simplest v1: **reuse `kind=files`** (already walks all of wp-content) and let the CP classifier route any path under `plugins/<slug>/…` or `themes/<slug>/…` to the plugin-checksum comparison. **Recommended v1: no new agent kind — `kind=files` already produces every plugin/theme file hash; the CP classifier owns the plugin/theme routing.** Add the `plugins` kind only if a faster targeted re-scan of a single updated plugin is wanted later.

### 4.4 Self-written-hash reporting (the false-positive killer)

When WPMgr's own writers touch a file, the agent should register that file's resulting hash so the diff trusts it. Two parts:

1. **A new signed command `record_managed_files`** (follows the `sync_security_hardening` template exactly — `class-sync-security-hardening-command.php`): CP can push "these paths are WPMgr-managed" (e.g. after a perf-config save the CP knows it wrote `object-cache.php`). Agent responds with the current `md5_file()` of each path; CP upserts `site_managed_files`.
   - New command class `final class RecordManagedFilesCommand implements CommandInterface` in `apps/agent/includes/commands/`; `name()='record_managed_files'`; register one line in `class-plugin.php::commands()` (L1168+). Router + `Connector::verifyCommand` (Ed25519 + `aud` + `cmd` hash_equals, ≤60s exp, jti replay) gate it automatically — no route wiring.
2. **Inline at write time (preferred for perf-suite writes):** wherever the agent already writes managed files (the config-writer the perf suite uses), have it append the path+hash to its `metadata`/diagnostics push (or call back via an existing report channel) so `site_managed_files` is updated the moment the file changes — not just on the next CP push. v1 can rely on the command pull; v2 wires the inline hook.

All path resolution must honor the **agent-empty-base-path guard**: every `StoragePaths`/`WP_CONTENT_DIR` accessor returns `''` when unresolvable and the caller bails before any write — never `WP_CONTENT_DIR ?? ''` (that writes at FS root). Reuse `BackupSource::contentRoot()`-style resolve-or-`''`-then-throw discipline.

### 4.5 Plugin/theme inventory — already shipped

`MetadataCommand::plugins()` (L291-337) + `themes()` (L348-379) already emit `{slug, version, active, …}` on a 30-min cron and on `refresh_inventory`. The CP already stores this in `sites.inventory` (JSONB `Components`; `site.Metadata` / `ParsedComponents` in `internal/site/model.go`). **No new agent enumeration.**

> Gotcha for the CP: a plugin's `slug` in the inventory is the **plugin file path** (`akismet/akismet.php`), but the wp.org checksum endpoint keys on the **directory slug** (`akismet`). The CP derives `dirname(slug)` / first path segment before fetching. Themes already report the stylesheet dir = the wp.org theme slug.

---

## 5. CP design

### 5.1 Extend `ChecksumProvider` → plugin/theme checksums (`checksums.go`)

Add a sibling method mirroring `Core` exactly (same `HTTPDoer`, same positive 30d / negative 6h TTL, same SSRF-safe client, same never-hard-fail behavior):

```go
// Plugin returns md5 variants per file for a wp.org-hosted plugin/theme.
// kind ∈ {"plugin","theme"}. Returns map[path][]md5 (multiple accepted variants).
func (p *ChecksumProvider) Plugin(ctx, kind, slug, version string) (map[string][]string, error)
```

- URL: `https://downloads.wordpress.org/plugin-checksums/%s/%s.json` (slug, version). (Themes: confirm the theme endpoint at build time; if wp.org has no theme-checksums service, themes fall back to **baseline-only** detection — see §6. v1 ships plugin checksums for certain; theme checksums best-effort.)
- JSON: `{plugin, version, source, zip, files:{path:{md5:<string|[]string>, sha256:…}}}`. Decode `md5` as `json.RawMessage` and accept both a string and an array (the multiple-variant edge case).
- Cache into `wporg_plugin_checksums(_meta)` (§3.3) via new `Repo` methods cloned from `GetChecksums`/`UpsertChecksums`/`*Meta`.
- A plugin/theme that is **not** on wp.org (premium/custom) → 404 → negative-cache → the diff falls through to **baseline** detection for that slug (no public checksum exists; this is exactly the gap the self-written-baseline approach covers). State this limitation precisely in the UI (§7).

### 5.2 The diff classifier (`diffFiles`, new in `worker.go` alongside `diffCore`)

`diffCore` (L373-433) stays untouched for `kind=core`. Add `diffFiles(run, baseline, hashes, coreChecksums, pluginChecksums, managed, inventory)` for `kind=full`/`files`. Pure function (no I/O) for unit testing, same as `diffCore`. Classification order (first match wins):

```
for each path in this run's hashes:
    if managed[path] exists:
        if managed.md5 == '' : skip (WPMgr-managed, churn-tolerant)
        if managed.md5 == hash : skip (WPMgr wrote exactly this)
        else: file_changed (HIGH)  -- managed file tampered
        continue
    if path is core (isCorePath): handled by core checksums (reuse diffCore logic / coreChecksums)
        if mismatch → core_modified ; if missing → core_missing ; etc.
        continue
    if path under plugins/<slug>/ or themes/<slug>/ AND slug is wp.org-hosted (inventory + checksum cache has it):
        if hash ∈ pluginChecksums[relpath] (any variant): skip (official)
        else if relpath in manifest: plugin_modified (HIGH)
        else: plugin_unknown (HIGH)        -- file not in official manifest
        continue
    -- no known-good source → baseline diff
    if path in baseline:
        if baseline.md5 != hash : file_changed (HIGH)
        else: skip (unchanged)
    else:
        file_added (MEDIUM)

for each path in baseline NOT in this run's hashes:
    if managed[path] exists: skip
    else: file_removed (LOW)
```

Dedup key = `md5(siteID:finding_type:path:tenantID)` (reuse `makeFinding`). Ignore-survives-rescan reuses `UpsertFindings` ON CONFLICT semantics unchanged.

**Baseline promotion** runs only on a successful `done` finish, after findings are written, in the same `finishFiles` step. On the cold-start run (no baseline) `diffFiles` emits only known-good (core/plugin) findings; A/C/R begins next run.

### 5.3 Findings persistence

Reuse `scan_findings` + `Repo.UpsertFindings` verbatim. The only new repo work is `PromoteBaseline(tenantID, siteID, runID)`, `GetBaseline`, `GetManagedFiles`, `UpsertManagedFiles`, plus the plugin-checksum cache methods — all clones of existing patterns.

### 5.4 Scheduled scans (extend the existing River loop)

There is no recurring trigger today — scans are operator-initiated (`StartRun`). Phase 2 adds a **periodic River worker** mirroring `HashGCWorker` (`worker.go:506`): a `FileIntegrityScheduleWorker` that, on a cron (River periodic job), enumerates enrolled sites with file-integrity enabled and calls `Service.StartRun(kind:"full")`. Cadence is a per-site policy column (reuse / extend `site_security_hardening_config` or a small `site_scan_schedule` table — recommend a `scan_schedule` enum on the hardening config: `off|daily|weekly`). Retry/back-off is inherent to River. The existing partial/resume loop means a huge-FS scan that can't finish in one River job simply re-enqueues — no scheduler change needed for resumability.

### 5.5 Audit + alerts + SSE (plug into existing channels)

- **Audit** (always, CP fact): add `internal/audit` consts `ActionFileBaselineEstablished`, `ActionFileChangeDetected`. The worker already holds `*audit.Recorder` and records `scan.completed`; add a per-finding-summary record (counts of added/changed/removed) — `ActorType: ActorSystem`, `TargetType:"scan_run"`.
- **Operator alert** (high-severity findings only): on a `done` run with any HIGH finding, resolve the tenant `alert_config`, gate on `Enabled && NotifySecurity`, and call `uptime.Dispatcher.FireSecurityEvent` with `uptime.SecurityEvent{EventType:"file_integrity", Severity:"high", Summary:"N changed / M added files on <site>"}`. Mirror `cmd/wpmgr/siteadapter.go:394-415`. Optionally add `AlertFileIntegrity = "file_integrity"` to `uptime.AlertKind` (`model.go:148`) to distinguish it from login `AlertSecurity` in audit/webhook payloads; reusing `AlertSecurity` is also fine for v1.
- **Live push:** publish `site.ConnectionEvent{TenantID, SiteID, Type:"scan.finding", Data:{run_id, counts}}` via `internal/site/events` Publisher. **Do not set `ev.ID`** — let Publish mint the ULID (hard rule from the SSE ULID contract). Honor "push is a hint, pull is the truth": the dashboard already polls `useScanRun` every 2s while live, so SSE is purely an accelerator.

### 5.6 Service validation change

`internal/scan/service.go` `StartRun` switch (L40-48): add `KindPlugins` if §4.3's optional targeted kind is built; otherwise no change — `full`/`files` already validate. Add `KindPlugins = "plugins"` to `model.go` only if built.

---

## 6. Premium plugins / theme checksum gaps — precise limitation

- **wp.org-hosted plugins:** full official-checksum verification via `downloads.wordpress.org/plugin-checksums/{slug}/{version}.json` (free, public, no auth). Any file ≠ manifest → `plugin_modified`; any extra file in the dir → `plugin_unknown`.
- **Premium / custom / not-on-wp.org plugins:** **no public checksum exists.** These are covered by the **baseline diff only** — i.e. WPMgr detects when such a plugin's files *change between scans* (`file_changed`/`file_added`/`file_removed`), but cannot assert an absolute "is this the genuine vendor file" verdict on the first scan. The UI must say this plainly: "Official checksums available for X of Y plugins; the rest are monitored for changes against the last-good baseline."
- **Themes:** plugin-checksums service definitely covers plugins; **theme** coverage on wp.org is not guaranteed at the same endpoint. v1: attempt theme checksums; on 404, themes fall through to baseline-only (same as premium plugins). Confirm the theme endpoint during the CP build; do not block Phase 2 on it.
- **Multiple md5 variants per file** (line-ending/build variants in the wp.org JSON) → store all variants, match any (§3.3).

---

## 7. Dashboard design (extend the Security tab)

The Security tab (`apps/web/src/routes/_authed/sites/$siteId.security.tsx`) is already a multi-section area (Hardening, Bans, Login protection, **Vulnerabilities stub**, **Integrity scan**). Phase 2 lights up the existing surfaces:

1. **Extend the finding-type chip map** in `apps/web/src/features/security/scan-findings-table.tsx` (`TYPE_LABEL` + `TYPE_CLASSES`, L60-83): add `file_added` (amber/medium), `file_changed` (destructive), `file_removed` (muted/low), `plugin_modified`, `plugin_unknown`. Extend the `ScanFindingType` union in `use-scan.ts:35-38`. Add `low` is already supported by `SeverityChip`. The table, ignore button, polling, and file-viewer need **no other change**.
2. **Scan kind selector:** `useStartScan` already posts `{kind}` (`use-scan.ts:206`); add a small control (Core / Full files / Plugins) on `ScanPanel`. All existing hooks (`useScanRuns`, `useScanRun`, `useScanFindings`) work unchanged for the new kinds.
3. **Plugin/Theme integrity section:** fill the **reserved Vulnerabilities-stub slot** (`security.tsx:104-128`, whose own comment says to swap the empty state for a real table) OR add a sibling `<section>`. Reuse `ScanFindingsTable` filtered to `plugin_*` types. Surface the coverage caveat from §6 ("Official checksums: X/Y plugins").
4. **File-content view:** `FindingFileModal` already shows current content for `core_modified`/`core_unknown_injected`; extend `canViewFile` (findings-table.tsx:251-254) to include `file_changed`/`file_added`/`plugin_modified`. A true expected-vs-actual diff is **out of scope for v1** (the modal already receives `expected_md5`/`actual_md5`; a diff viewer is a v2 nicety).
5. **Live:** existing polling covers it; the new SSE event just accelerates refresh.

Frontend-architect: run `npx impeccable detect` per the landing/UI gate; reuse `SeverityChip`, shadcn `Table`/`Badge`/`Dialog`. No new design-system primitives.

---

## 8. Phasing (CP-first, ordered, with specialist owners)

Every step ships its named test + docs DoD; deploy all touched layers (api image → wpmgr-api, web image → wpmgr-web, agent → make agent-release, migration auto-on-boot).

| # | Step | Owner | Deps |
|---|---|---|---|
| **2.0** | **Migration m77** (`site_file_baseline`, `site_managed_files`, `wporg_plugin_checksums(_meta)`) + RLS + `model.go` finding-type/severity consts. `sqlc generate` discipline (verify no-op). | backend-architect | none |
| **2.1** | **CP plugin/theme checksum cache:** `ChecksumProvider.Plugin` + repo methods + multi-md5-variant decode. Unit test against a fixture JSON. | backend-architect | 2.0 |
| **2.2** | **CP diff classifier `diffFiles` + baseline promotion + repo (`GetBaseline`/`PromoteBaseline`/`GetManagedFiles`).** Wire into `ScanRunWorker.finish` branching by kind. Pure-function unit tests covering A/C/R, known-good, managed-suppression, cold-start. | backend-architect | 2.0, 2.1 |
| **2.3** | **Agent `record_managed_files` command** (sync-template) + register in `commands()`; optionally inline-hook the perf/config writers to report managed hashes. PHPUnit (Brain Monkey, stubs in `tests/wp-stubs.php`). | wp-agent-engineer | 2.0 |
| **2.4** | **CP scheduled-scan River worker** + per-site `scan_schedule` policy column (extend hardening config) + StartRun `full` cadence. | backend-architect | 2.2 |
| **2.5** | **CP audit consts + alert (`FireSecurityEvent`) + SSE publish** for file-change findings. | backend-architect | 2.2 |
| **2.6** | **Web:** finding-type chips, kind selector, plugin/theme integrity section (fill the reserved stub), `canViewFile` extension, coverage caveat copy. | frontend-architect | 2.2 (API live) |
| **2.7** | **security-reviewer pass:** the `get_file` exfil gate already exists; review new finding types don't widen file-fetch scope, baseline/managed tables RLS, plugin-checksum SSRF (reuse SSRF-safe `HTTPDoer`), and that `plugin_unknown` can't be weaponized to fetch arbitrary paths. | security-reviewer | 2.6 |
| **2.8** | **docs-writer:** CHANGELOG + landing content + `docs/security/` update (DoD gate). | docs-writer | all |

**Free-feeds-only confirmation:** the sole external dependencies are `api.wordpress.org/core/checksums` (already wired) and `downloads.wordpress.org/plugin-checksums` — both free, public, no auth, no key, SSRF-fetched through the existing `httpclient`. **No Gate-0 decision, no paid feed, no operator key.** Phase 2 is fully buildable today.

---

## 9. Risks / edge cases

| Risk | Mitigation |
|---|---|
| **Huge filesystems / scan never finishes in one job** | Already solved: the resumable cursor + River re-enqueue loop streams 12s / 4000-path chunks (`ScanRequest` defaults). Baseline diff runs once at `done`. No change needed. The `HashGCWorker` already reaps abandoned mid-flight staging hashes after 24h. |
| **Cache/upload churn → false-positive flood** | Reuse the backup exclude set (`cache`, `upgrade`, agent scratch) verbatim — the existing "must stay in sync" contract. Self-written registry (`site_managed_files` with `md5=''`) suppresses WPMgr-managed churn dirs. |
| **Legit plugin update → every file "changed"** | After a plugin update the new files match the new wp.org manifest → no `plugin_modified`. For premium plugins, baseline-only means an update *will* show as `file_changed` until the baseline re-promotes (next scan) — acceptable and informative; operator can ignore-with-survives-rescan. Optionally suppress when the inventory `version` changed since baseline (v2). |
| **Premium plugins have no public checksum** | Explicit: baseline-only monitoring; UI states coverage X/Y. Documented in §6. |
| **Baseline bootstrapping flood on first scan** | Cold-start run establishes baseline only, emits no A/C/R (§2). Dashboard shows "Baseline established." |
| **wp.org multiple md5 variants** | Store all variants (md5 in PK); match any (§3.3). |
| **Plugin slug = file path, not dir slug** | CP derives `dirname(slug)` before fetching wp.org (§4.5). |
| **`get_file` exfiltration** | Unchanged existing guard: `FetchFile` requires the path to already be a stored finding + `CanAccessSite` (service.go:149-204) + agent-side realpath-containment (`FileScanner::getFileContent` L284-384). New finding types ride the same gate; security-reviewer confirms no widening. |
| **Baseline table growth** | One row/file/site, bounded by excludes (~few hundred KB/site). `ON DELETE CASCADE` from sites. Promotion is replace-in-tx. |
| **Theme checksums may not exist on wp.org** | Best-effort; 404 → baseline-only for themes. Doesn't block Phase 2. |
| **Self-written race (WPMgr writes file between hash + diff)** | Managed-file registry is upserted on write; a narrow race just yields one transient `file_changed` that clears next scan. Acceptable. |

---

## 10. One-paragraph summary for the specialists

Phase 2 is an **extension, not a rewrite**: the agent's resumable `FileScanner` already walks the entire filesystem (`kind=full`/`files`) and the CP's `ScanRunWorker` already drives the chunked partial/resume loop and persists findings with dedup + ignore-survives-rescan. The new work is four small CP additions — a **plugin/theme checksum cache** cloned from `checksums.go`, a **durable per-site baseline** (`site_file_baseline`, promoted from the run's staged hashes), a **self-written-hash registry** (`site_managed_files`) so WPMgr's own writes never false-positive, and a pure **`diffFiles` classifier** that emits Added/Changed/Removed plus official-checksum verdicts — wired through the *existing* audit/alert/SSE channels and surfaced on the *existing* Security tab by extending one chip map. All feeds are free and public; no Gate-0 decision applies. m77 is the next migration.
