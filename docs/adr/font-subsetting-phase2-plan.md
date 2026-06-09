# Font Subsetting Phase 2 + RUCSS-style Font Processing UI + WP Font Library Coverage — Build Plan

This builds on ADR-052 (WOFF2 transcoding, shipped v0.31.0, flag `fonts_transcode_woff2`, default-off). Three deliverables: (a) Phase-2 subsetting in the media-encoder, (b) a per-font results catalog + RUCSS-style processing/QA UI, (c) explicit WP Font Library coverage. The single biggest risk is **over-subsetting breaking glyphs** (tofu on dynamic/i18n content, broken icon/variable fonts) — the strategy below is built around mitigating exactly that.

---

## 1. wp-content/fonts answer (Font Library coverage)

**Are Font Library fonts caught today? Partially — by accident of how the agent discovers fonts, not by design.**

The agent discovers fonts *only* by regex over inline `<style>...</style>` blocks in the rendered HTML (`class-font.php:process()` → `transcodeWoff2InlineStyles($html)` → `rewriteFontFaceForWoff2()` on `/@font-face\s*\{([^}]*)\}/is`). There is **zero filesystem scan** and **zero external-stylesheet scan** in the transcode path.

WordPress 6.5+ Font Library prints its fonts via `wp_print_font_faces()` (hooked at `wp_head` priority 50), which on **block themes** emits an **inline `<style id='wp-fonts-local'>`** block carrying the merged theme.json + user-activated font `@font-face` rules. Verdict per layer:

- **CAUGHT today:** Font Library / theme.json fonts on **block themes**, *if* the `src` URL ends in `.ttf`/`.otf`/`.woff` and the file is fetchable over HTTP. The agent's existing inline-`<style>` scan already picks these up — no new code required for this case.
- **MISSED today, by design (acceptable):** Font Library fonts that already ship as `.woff2` — skipped because they're already optimal. Fine.
- **MISSED today (the real gap):** (a) fonts loaded by **classic themes or plugins via an enqueued external stylesheet** (`wp_enqueue_style`) — never scanned for transcoding at all, because Font runs before SelfHost/CssMinify in the pipeline and only scans inline `<style>`; (b) fonts **registered/installed but not yet activated or printed** on any page we render through the cache-miss path.

**What we add to cover them:**

1. **Extend the transcode pass to scan external enqueued stylesheets.** The building block already exists: `extractFontUrls()` (`class-font.php:412`) recognizes `ttf/otf/woff/woff2` in CSS, and `cachedGoogleCssUrl()` (lines 204-214) already downloads + rewrites an external CSS file's font URLs for Google self-hosting. Apply that same fetch-external-CSS → extract `url()`s → transcode → rewrite-to-local path to plugin/classic-theme enqueued stylesheets. **This is the highest-value addition** because it closes the classic-theme/plugin case, which inline-only discovery structurally cannot reach.
2. **Capture `font-family` at discovery** (in `tryRewriteFontFaceBlock`) — currently discarded — so the results catalog can label each row.

**Is a filesystem scan of `wp-content/fonts` warranted? NO — not for discovery; only as an optional fallback.**

Per WP core research, `wp_print_font_faces()` already emits activated fonts as inline `@font-face`, so a front-end scanner reading the rendered HTML discovers them without touching disk. A filesystem walk would only help with **registered-but-not-activated** fonts (which the page never references and the browser never loads — optimizing them is wasted work) or as a **src-URL → file-path resolver fallback**. Honor the `font_dir` filter if we ever add it (default `wp-content/fonts` is overridable). **Recommendation: do not build a filesystem scan in Phase 2.** Discovery stays HTML/CSS-driven (inline + external). Revisit only if we later add a "manage installed fonts" surface that needs to enumerate inactive fonts.

---

## 2. Subsetting strategy

### Recommended SAFE default: **fixed unicode-range (`latin-ext`)**, with **used-glyphs as an explicit opt-in "aggressive" mode**.

| Mode | Behavior | Risk | Default |
|---|---|---|---|
| `none` | Phase-1 full WOFF2 (unchanged) | none | — |
| `range` (`latin` / `latin-ext`) | Subset to a static, well-known unicode range | **None within range** — whole alphabet+punctuation present; no tofu on dynamic content | **`latin-ext` = DEFAULT when subsetting is on** |
| `used` | Subset to exactly the codepoints rendered on the crawled page set | **High** — any unseen codepoint (comments, i18n, dates, currency, future posts) → tofu/FOIT | Opt-in only, behind a UI warning |

**Why fixed-range is the safe default:** it is deterministic, content-addressable, cacheable across all sites sharing the font, and **never produces tofu on dynamic content within the range**. Most web fonts ship Cyrillic/Greek/Vietnamese that a European-language WP site never uses, so `latin-ext` (U+0000–00FF + U+0100–024F + U+1E00–1EFF) still typically yields **60–90% reduction** without any correctness risk. `latin` is the more aggressive built-in range option. Build `glyphIDs` by iterating the range and keeping `GlyphIndex(r) != 0` (skip codepoints the font lacks).

**Used-glyphs (`used`) is opt-in only.** The agent unions all runes actually rendered in text nodes resolving to each `@font-face` family across the warmed page set, **always unioned with printable-ASCII baseline (U+0020–007E)** so basic typing never tofus, and re-subsets (new content hash) when the union grows on a later crawl. Maximal savings (DejaVu 380KB→6.7KB for A–Z in upstream docs) but gated behind an explicit per-site toggle with a UI warning that dynamic/i18n content may need the full font. **Never the default.**

### Per-font safety rules (non-negotiable guards)

1. **NEVER subset variable fonts.** After `ParseSFNT`, if `sfnt.Tables["fvar"]` (and/or `gvar`) present → `ErrVariableFont` (permanent negative for that spec). `Subset()` silently drops `fvar/gvar`, producing a broken static font. Serve the full WOFF2.
2. **NEVER subset icon fonts.** Heuristic: cmap maps predominantly into PUA (U+E000–F8FF, U+F0000+), and/or `GlyphIndex` over the Latin baseline returns mostly 0, and/or family matches `fa`/`icon`/`dashicons` → `ErrIconFont` (permanent negative). **Conservative bias: when uncertain, do NOT subset.**
3. **ALWAYS keep the full WOFF2 as fallback.** The agent's `@font-face` lists the full WOFF2 (spec `''`, from Phase 1) **first/as canonical src**, with the subset added via a `unicode-range` descriptor so the browser fetches the subset only for in-range codepoints and falls back to the full font otherwise. Subsetting is **purely additive bandwidth optimization, never a replacement.**
4. **NEGATIVE marker on any subset failure** (variable, icon, empty glyph set, `Subset()` error, panic, oversize output) → mark **that `(source_hash, subset_spec)` row** negative with `error_detail`; never retried. **Per-spec** — the full-font row stays `ready`.
5. **Content-addressed idempotency on `(source_hash + subset_spec)`.** Canonical spec is part of the content address (key + PK) → identical inputs computed exactly once and cached; changing range/glyph-set yields a new address, never an overwrite of the full WOFF2.
6. **Keep existing ceilings** (`MaxFontBytes`, `MaxDecodedFontBytes`, `safeTranscode` panic→permanent) unchanged on the subset path.

### Layout/kerning honesty (a real caveat to surface)

`tdewolff/font` `SFNT.Subset(glyphIDs, opts)` **rebuilds cmap** (correctly addressable by kept codepoints) and **remaps `kern`** (legacy kerning preserved), but **DROPS GPOS/GSUB/GDEF** (upstream `// TODO`). So **OpenType shaping — ligatures, contextual kerning, complex-script shaping, small-caps features — is LOST after subsetting.** For body-text Latin web fonts (which rely on `kern` + `glyf`) this is usually acceptable, and it's precisely why the fixed-range default must stay conservative and the full WOFF2 fallback is mandatory. **`KeepMinTables` is the only correct `SubsetOptions`** — `KeepAllTables` copies GPOS/GSUB with *stale* glyph IDs (worse). This GPOS/GSUB-drop is a real correctness limitation we should note in the UI/docs, and it's an argument for keeping subsetting **default-off** for now.

### Media-encoder job changes (tdewolff Subset — no new deps)

- **`transcode.go`:** in `sfntToWOFF2`, after `ParseSFNT`, branch on spec. `Mode==none` → current path. Else: detect variable/icon (reject per rules 1–2) → build `glyphIDs` (range iter or codepoint list, drop `.notdef`) → `sfnt.Subset(glyphIDs, SubsetOptions{Tables: KeepMinTables})` → `WriteWOFF2()` on the subset. Keep `MaxDecodedFontBytes` on output. Add detectors `isVariableFont`/`isIconFont` + sentinels.
- **`args.go`:** add optional `SubsetSpec` (pure-Go value type, no font-package import): `{ Mode string("none"|"range"|"used"); Range string("latin"|"latin-ext"); Codepoints string("20-7E,A0-FF,...") }`. Canonicalize (sort, lowercase, fixed enums) → short stable `specTag`. Add `ValidSubsetSpec()` validator (Mode enum; Range enum; Codepoints strict regex `^([0-9A-Fa-f]{1,6})(-[0-9A-Fa-f]{1,6})?(,...)*$`, ≤5000 ranges) checked at enqueue (400) AND in worker. **`Mode` defaults to `none` → existing Phase-1 enqueues deserialize unchanged; `Kind()` stays `"font_transcode"`, no new Kind.**
- **`worker.go`:** derive output key from `(source_hash + canonical spec)`; call `TranscodeToWOFF2WithSubset(src, spec)` inside the existing `safeTranscode` panic wrapper (inherits panic→negative for free); add `ErrVariableFont/ErrIconFont/ErrSubsetEmpty/ErrSubsetFailed` to `isPermanent()`. On subset failure where `Mode != none`: **mark THIS spec negative and rely on the already-present full WOFF2** (single-purpose; don't re-emit the full font from a subset job).
- **Asset key (`DeriveSubsetWoff2Key`):** `none` → `fonts/<tenant>/<source_hash>.woff2` (**unchanged** — existing assets stay valid); `range` → `fonts/<tenant>/<source_hash>.<range>.woff2`; `used` → `fonts/<tenant>/<source_hash>.s<spec_hash12>.woff2` (first 12 hex of BLAKE3 of canonical codepoints). **Verify `GuardStorageKey`/`extractHex64` still accept these** — the 64-hex source hash is intact and the suffix is non-hex or a 12-hex run (not 64) → add test cases for both shapes.

---

## 3. Data model + status reporting

**Today's gap:** there is **no per-font status pushed to the CP**. The agent's only per-font knowledge is the local WP option `wpmgr_font_transcode_cache` (`{hash => {state, woff2_name}}` — no family/sizes). `font_transcode_results` (m54) is a bare **job-control** table (`source_hash, tenant_id, site_id, river_job_id, woff2_key, negative, error_detail, timestamps`) — no family, no sizes, no subset state. `PerfReporter` sends **zero** font fields. There is no list endpoint and the web shows only the on/off toggle.

**Decision: add a NEW sibling `font_results` catalog table (mirrors how rucss_results [catalog] and rucss_jobs [control] are separate).** Leave `font_transcode_results` as the job-control layer. This is the clean dashboard read-model and avoids overloading the content-addressed job tracker.

### `font_results` table — new migration `m55` (`20260618000000_m55_font_results.sql`)

```
id            uuid PK DEFAULT gen_random_uuid()
tenant_id     uuid NOT NULL  → tenants(id) ON DELETE CASCADE
site_id       uuid NOT NULL  → sites(id)   ON DELETE CASCADE
source_hash   text NOT NULL              -- BLAKE3 hex; joins to font_transcode_results
family        text                       -- agent-reported, informational
source_file   text                       -- basename of original url
original_ext  text                       -- ttf|otf|woff
original_size integer
woff2_size    integer                    -- NULL until ready
subset_size   integer                    -- NULL unless subset produced
unicode_range text                       -- NULL unless subset
state         text NOT NULL DEFAULT 'pending'  -- pending|ready|subset|negative
savings_pct   numeric(5,2)               -- CP-derived from best output
created_at    timestamptz NOT NULL DEFAULT now()
updated_at    timestamptz NOT NULL DEFAULT now()
CONSTRAINT font_results_site_hash_uniq UNIQUE (site_id, source_hash)
CHECK (state IN ('pending','ready','subset','negative'))
```

- **SITE-scoped** (`UNIQUE(site_id, source_hash)`), unlike the tenant-scoped content-shared `font_transcode_results` — because this is a per-site dashboard catalog (same font on two sites shows each site's savings).
- **State semantics:** `subset` is a superset of `ready` (a subset WOFF2 was produced and is smaller). Progression `pending → ready → subset`, `negative` terminal. **`savings_pct` is CP-derived at upsert** from `original_size` over `min(woff2_size, subset_size)` — one source of truth, don't trust an agent-supplied number (matches how rucss `reduction_pct` is derived).
- **Indexes:** `idx_font_results_site (site_id, updated_at DESC)` (dashboard list); `font_results_tenant_idx (tenant_id)` (RLS scans).
- Append the same DDL to `db/schema.sql` (canonical snapshot — both must agree). All statements `IF NOT EXISTS`/guarded, m54 style.

### RLS — mirror m36/m54 EXACTLY (hard correctness gate)

```
ALTER TABLE public.font_results ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.font_results FORCE ROW LEVEL SECURITY;
```
Two policies, each with **USING + WITH CHECK**, via the `IF NOT EXISTS`-in-`pg_policies` DO-block guard:
- **tenant_isolation:** `USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)` + same `WITH CHECK`. The `nullif` guard is load-bearing (returns NULL/no-rows instead of a cast error when GUC unset).
- **agent_access:** `USING (current_setting('app.agent', true) = 'on')` + same `WITH CHECK`. The GUC value is the **string `'on'`** (set by `InAgentTx`), NOT `'true'`.

Missing `WITH CHECK` would let an agent INSERT cross-tenant rows. Add an RLS-presence assertion to the perf routes-contract/RLS test (mirror m54). Copy the exact template from `m54` lines 80-116.

### Agent → CP per-font upsert (Option B: dedicated endpoint — recommended)

`POST /agent/v1/fonts/results` (security `AgentSignature`), new `FontAgentHandler` method writing via new repo `UpsertFontResult` under `InAgentTx` with `INSERT ... ON CONFLICT (site_id, source_hash) DO UPDATE SET state, sizes, savings_pct, updated_at=now() RETURNING *`. **tenant_id + site_id taken from the VERIFIED agent identity (`agent.IdentityFromContext`), NEVER the body** (same as `transcodeRequest` lines 115-119). Clean separation: transcode endpoint = job control; results endpoint = catalog. Register on `agentGroup` in `server.go` (~line 244 alongside `PerfAgentH`). Apply the same strict `font.ValidSourceHash` (64 lowercase hex) + size-guard + a daily cap (reuse `CountTodayFontTranscodeEnqueues`-style) so a hostile agent can't mint unlimited rows.

Agent side: a fire-and-forget `PerfReporter`-style push carrying `[{source_hash, family, source_file, original_ext, original_size, woff2_size, subset_size?, unicode_range?, state}]`. Family/size are already in hand at discovery (`@font-face` CSS + `strlen(sourceBytes)`); woff2/subset size from `strlen` of the fetched bytes. The media-encoder writes the woff2/subset output byte size on the ready transition (so the CP can derive `savings_pct` authoritatively even on Option B if we pass sizes through the worker).

### Dashboard list endpoint (mirror `listRucssResults`)

`GET /api/v1/sites/{siteId}/perf/fonts` (operationId `listFontResults`), on the existing **PerfH handler** (reuses `PermSiteRead` + the per-site `canReadSite` scoping already applied to all `/perf/*` routes). Add a `FontResultsReader{ List func(ctx, tenantID, siteID, limit, offset) ([]FontResultDTO, error) }` field on `Handler` (mirror `RucssResultsReader`), populate in `main.go` (~lines 1002-1030 where `perfRucssReader` is built). Method `h.fontResults` mirrors `h.rucssResults`: parse siteID, `pageParams(c)`, nil-reader → empty items, else `reader.List`, `c.JSON(200, gin.H{items})`. Repo `ListFontResultsForSite` under `InTenantTx`: `WHERE tenant_id=$1 AND site_id=$2 ORDER BY updated_at DESC, id DESC LIMIT $3 OFFSET $4`.

`FontResultDTO` (alongside `RucssResultDTO`): `{ ID, SourceHash, Family, SourceFile, OriginalExt, OriginalSize int, Woff2Size *int, SubsetSize *int, State string, UnicodeRange string omitempty, SavingsPct float64, UpdatedAt string omitempty }`.

### `fonts_subset` config flag

Add `fonts_subset boolean NOT NULL DEFAULT false` to `site_perf_config`, exactly like m54 added `fonts_transcode_woff2`. Plus subset-mode columns (`fonts_subset_mode`, `fonts_subset_range`) so the operator can pick `range`/`used` and the range. Three touch points: (1) migration DO-block `ADD COLUMN IF NOT EXISTS` + schema.sql; (2) Go `PerfConfig` in `model.go` (after `FontsTranscodeWOFF2` line 64) + `dto.go` `toDTO`/`fromDTO`; (3) ensure the perf-config push to the agent carries it. **Default OFF, operator-writable.** The CP should **ACCEPT `fonts_subset=true` independently** of `fonts_transcode_woff2` (per the `woo_cacheable_session` precedent — let the agent hard-gate, allow pre-enabling); the UI shows it as dependent but the API doesn't hard-reject.

### OpenAPI + codegen (the REAL commands — NOT `make gen`)

OpenAPI (`packages/openapi/openapi.yaml`): add `fonts_subset` (+ subset mode/range) to `PerfConfig` (~7280); `FontResult`/`FontResultList` schemas near `RucssResult` (~7629, copy that shape); path `/api/v1/sites/{siteId}/perf/fonts` GET `listFontResults` (copy `listRucssResults` 4014-4033); `/agent/v1/fonts/results` POST `agentFontsResults` (copy `agentFontsTranscode` 2025-2099). **Pin field names to Go json tags (snake_case) + DB columns** (`source_hash/woff2_size/subset_size/unicode_range/savings_pct`) — contract-vocab-pinning rule. Note perf endpoints are hand-rolled Gin, so no ogen server interface is generated — spec is for the TS client + contract docs.

Codegen, two steps after editing the spec:
1. `go generate ./internal/api/gen/...` (from `apps/api` — ogen, regenerates shared schema types)
2. `pnpm -C packages/openapi-client generate` (openapi-ts — gives the web typed `FontResult`/`FontResultList` + `listFontResults`)

Then `go build ./...` + `go test ./internal/perf/...` + the routes-contract test (`perf/routes_contract_test.go`, asserts every registered route is in the spec — **add the new `/agent/v1/fonts/results` route to its expected list**). Forgetting the TS regen is the classic "web image forgotten" trap.

---

## 4. The processing UI ("like RUCSS")

RUCSS has a fully-built 6-piece pipeline; fonts get a near-verbatim clone. The **per-row state badge is the main UX delta** the user wants (RUCSS has only a job-level live indicator; fonts get per-font state).

### Mirror these 6 RUCSS pieces

1. **Results query** `useFontResults(siteId, page)` — clone `useRucssResults`; `queryKey [...perfKeys.fonts(siteId), page]`, `placeholderData:(prev)=>prev`. `perfKeys.fonts(siteId)=['perf','fonts',siteId]`.
2. **Store** `fonts-store.ts` — clone `rucss-store.ts` (Zustand `bySite` map), but **combine the rucss phase machine with the preload-store processed/total counter** → `{phase, processed, total, savings_pct?, updatedAt}`. Phases `null|queued|converting|done|failed`; auto-clear `done` after 8s; 120s stale backstop. Convention: ephemeral phase in Zustand, authoritative results in TanStack Query.
3. **Live indicator** `FontsLiveIndicator.tsx` — clone `RucssLiveIndicator`, SSE-driven (NOT polling). Shows aggregate progress `Converting fonts (3/7)...` from `processed/total`; `done` shows `Converted 7 fonts, saved 58%`. Reuse the exact lucide icons + color tokens.
4. **Results table** `FontResultsTable.tsx` — clone `RucssResultsTable`. Columns:

   | Font | Format → WOFF2 | Original → WOFF2 | Subset | Savings | State |
   |---|---|---|---|---|---|
   | `family ?? source_file` (mono truncate, `title=full path`) | `source_ext` badge → `woff2` | `formatBytes(original)` → `formatBytes(woff2)` | `formatBytes(subset)` or `-` | `savings_pct.toFixed(0)+'%'` | badge |

   **Per-row state badge** (the QA visibility): `pending`=grey clock; `converting`=blue spinner (`Loader2`); `ready`=green `CheckCircle2`; `subset`=teal `Scissors`; `skipped`=amber "Skipped (icon/variable font)"; `failed`=red `XCircle` (`title=error_detail`). Reuse `formatBytes`/`formatWhen` verbatim. Table-level states copy 1:1 (4 skeleton rows `role=status`, `role=alert` error, centered empty: **"No fonts discovered yet. They appear here once a page with self-hosted fonts is built with WOFF2 conversion on."**). Pagination optional (font counts are small).
5. **SSE reducer** — add `font.*` cases to `perfEventReducer` in `usePerfEvents.ts` routing to `fonts-store.setPhase`/counter + invalidating `perfKeys.fonts` on completion. CP emits `font.queued` on enqueue, `font.converting`/`font.ready` per font, `font.failed` on negative, aggregate `font.completed` at page-build end (`savings % = 1 - sum(woff2)/sum(original)`). Register the new types in `SITE_EVENT_TYPES` (`use-site-events.ts`).
6. **Keys** — add `perfKeys.fonts(siteId)`.

### Where it plugs in

`usePerfEvents(siteId)` is already called once at the top of `OptimizeTab` (line 28) — no extra SSE wiring needed. Mirror RUCSS exactly: render `<FontResultsTable siteId hostname canOperate/>` in `OptimizeTab.tsx` **immediately after `<RucssResultsTable>`** (line 81), with `<FontsLiveIndicator siteId/>` in that card's header. `FontsSection.tsx` (the toggle card, `fonts_transcode_woff2` at lines 50-57) stays a pure config card — add the `fonts_subset` toggle (+ mode/range selector, gated visually on `fonts_transcode_woff2`) there, and optionally drop a small inline `<FontsLiveIndicator>` next to the toggle so the operator sees activity where they flipped it.

**Observe-only vs Compute-now:** fonts are discovered **passively on page-build** (unlike RUCSS Compute-now), so the table is **observe-only** by default — OR add a **"Re-scan fonts"** action that warms a page to trigger discovery. Recommend shipping observe-only first; add Re-scan if QA friction warrants.

---

## 5. Build order + specialist routing

**Strict deploy ordering (media-encoder image FIRST):** the encoder is the *producer* of the new subset output and runs scale-to-zero as a pull worker. Per the media-encoder-deploy-ordering memory, **ship the media-encoder image before any CP/agent that enqueues or requests subsets**, or jobs enqueue-and-never-run / dangle. Then CP, then agent, then web.

```
1. media-encoder image   — Subset path (transcode.go/args.go/worker.go), new asset-key shape, sentinels. Deploy FIRST.
2. CP (wpmgr-api)         — m55 migration (auto-on-boot) + font_results + RLS + UpsertFontResult + listFontResults + fonts_subset config + font.* SSE + OpenAPI + codegen. Deploy BEFORE agent.
3. Agent (make agent-release) — external-stylesheet scan, family/file capture, subset-spec in enqueue, used-glyph union, results push, @font-face unicode-range + full-WOFF2 fallback, honor fonts_subset/mode/range.
4. Web (wpmgr-web)        — the 6-piece RUCSS clone + fonts_subset toggle. Easy to forget the web image — it has UI.
```

**Specialist routing (route ALL build work to specialists; general-purpose only for read-only):**

| Slice | Owner |
|---|---|
| m55 migration + `font_results` + repo + `listFontResults` + `fonts_subset` config + `font.*` SSE + OpenAPI/codegen | **backend-architect** |
| media-encoder Subset path (tdewolff `Subset`, detectors, sentinels, asset-key, key-guard tests) | **backend-architect** (Go media domain) |
| Agent: external-CSS scan, family/file capture, subset spec + used-glyph union, results push, `@font-face` rewrite with `unicode-range` + full-WOFF2 fallback, Font Library coverage | **wp-agent-engineer** |
| RLS (`tenant_isolation`+`agent_access`, both USING+WITH CHECK) + agent-identity scoping (tenant/site from verified identity, never body) + daily-cap/size guards | **security-reviewer** |
| Web: 6-piece RUCSS clone, results table + per-row badges, live indicator, toggle/mode UI | **frontend-architect** (run Impeccable gate) |
| Landing `content.ts` + root `CHANGELOG.md` | **docs-writer** |

**Per-layer Definition-of-Done gates:**

- **Migration:** auto-on-boot; idempotent (`IF NOT EXISTS`); appears in both the migration file AND `db/schema.sql`; RLS enabled+forced with both policies; RLS-presence test passes.
- **CP:** `go build ./...` + `go test ./internal/perf/...` green; routes-contract test passes (new route in spec + expected list); both codegen steps run; `savings_pct` derived CP-side.
- **Agent:** `make agent-release`; full WOFF2 always remains the `@font-face` fallback; variable/icon fonts skipped; results push fire-and-forget (never blocks the page); Font Library inline + external-CSS cases verified.
- **Web:** web image rebuilt; Impeccable `npx impeccable detect` clean; per-row state badges render all 6 states; empty/error/loading states present.
- **Cross-cutting DoD (docs-changelog-sop):** landing `content.ts` + root `CHANGELOG.md` updated — definition-of-done gate, not optional. Release to BOTH prod GCP and OSS GHCR. No competitor-plugin names / defensive disclaimers in shipped comments.

---

## 6. Open decisions for the user (confirm before building)

1. **Subset default mode — fixed `latin-ext` (recommended) vs `used-glyphs`?** I strongly recommend **fixed `latin-ext` as the only default**, with `used` behind an explicit "aggressive" toggle + warning. Used-glyphs tofus on dynamic/i18n content. **Confirm you accept fixed-range as the shipped default** (and whether we even expose `used` in v1, or defer it).

2. **Subsetting opt-in default OFF?** Given GPOS/GSUB is dropped on subset (ligatures/complex-script shaping lost) and the full-WOFF2 fallback is the safety net, I recommend `fonts_subset` **default OFF**, gated behind `fonts_transcode_woff2`, surfaced as "experimental." Confirm.

3. **Filesystem scan of `wp-content/fonts` — build it or not?** My recommendation: **NO** for Phase 2 — discovery stays HTML/CSS-driven (inline + new external-stylesheet scan), which already covers activated Font Library fonts. Confirm you're OK *not* enumerating registered-but-inactive fonts on disk (they're never rendered, so optimizing them is wasted work).

4. **UI placement + observe-only vs Re-scan.** Recommended: `FontResultsTable` as a separate card right after `RucssResultsTable` in OptimizeTab, **observe-only** (no Compute-now, since discovery is passive). Confirm — or do you want a "Re-scan fonts" warm-a-page action in v1?

5. *(Minor)* **Daily enqueue cap.** Each font can now spawn `full + subset` jobs, so `CountTodayFontTranscodeEnqueues` (cap 500) now counts variants. Confirm whether to raise/segment the cap so subsetting doesn't starve full-WOFF2 transcoding.

**Risk callout:** the dominant risk is over-subsetting breaking glyphs — tofu on unexpected codepoints, and silent loss of OpenType shaping (GPOS/GSUB drop). The plan mitigates this with: fixed-range default, mandatory full-WOFF2 fallback via `unicode-range`, hard skips for icon/variable fonts, conservative-when-uncertain bias, per-spec negative markers, and default-OFF. The per-font QA UI is itself a mitigation — it makes every skip/subset/failure visible so a bad subset is caught in QA, not by an end user.