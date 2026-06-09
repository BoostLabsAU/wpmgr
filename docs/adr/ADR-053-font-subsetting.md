# ADR-053 -- Font subsetting (Phase 2)

Status: Accepted (2026-06-09)

## Context

ADR-052 (shipped v0.31.0) added WOFF2 transcoding via `github.com/tdewolff/font`
in the media-encoder. That release explicitly deferred subsetting. The full
WOFF2 of a typical body font still carries Cyrillic, Greek, and Vietnamese
blocks that a European-language WordPress site never renders. Subsetting removes
those unused blocks and typically yields a further 60 to 90 percent reduction on
top of the WOFF2 gain, with no quality trade-off when the range is chosen
conservatively.

Three gaps remain after ADR-052:

1. No per-font status is pushed to the control plane. The only per-font
   knowledge is a local WP option on the agent. The web shows only an on/off
   toggle, with no visibility into which fonts succeeded, failed, or were
   skipped.

2. Font discovery is limited to inline `<style>` blocks in the rendered HTML.
   Fonts loaded by classic themes or plugins via an enqueued external stylesheet
   (`wp_enqueue_style`) are never scanned.

3. Subsetting is not implemented anywhere.

This ADR covers all three: Phase-2 subsetting in the media-encoder, external-
stylesheet discovery in the agent, and a per-font results catalog with a
processing UI that mirrors the existing RUCSS surface.

## Decision

### Subsetting strategy

Fixed unicode-range (`latin-ext`) is the default subsetting mode when subsetting
is enabled. It is deterministic, content-addressable, safe on dynamic content,
and cacheable across all sites sharing the same font file. The built-in
`latin-ext` range covers U+0000 to 00FF, U+0100 to 024F, and U+1E00 to 1EFF --
the full alphabet, punctuation, and extended Latin characters used by most
European-language WordPress sites. Glyph IDs are built by iterating the range
and keeping every codepoint where `GlyphIndex(r) != 0`, so codepoints the font
lacks are skipped without widening the output.

A `used` mode (subset to exactly the codepoints rendered across the warmed page
set, unioned with printable ASCII U+0020 to 007E as a baseline) exists as an
explicit opt-in. It is never the default because any unseen codepoint --
comments, i18n strings, dates, currency, future posts -- produces tofu or
flash-of-invisible-text on a cache miss. It is gated behind a per-site toggle
with a UI warning.

**The flag `fonts_subset` defaults to false (off).** Subsetting is experimental.
The control plane accepts `fonts_subset=true` independently of
`fonts_transcode_woff2`; the agent hard-gates subsetting on WOFF2 being
enabled. The UI surfaces the subset toggle as dependent on the WOFF2 toggle.

### Safety guards (non-negotiable)

1. **Variable fonts are never subsetted.** After `ParseSFNT`, if the `fvar` or
   `gvar` table is present, the job is marked with a permanent `ErrVariableFont`
   sentinel. `tdewolff/font` `Subset()` silently drops those tables, producing a
   broken static font. The full WOFF2 is served instead.

2. **Icon fonts are never subsetted.** Heuristic: the cmap maps predominantly
   into the Private Use Area (U+E000 to F8FF, U+F0000 and above), or
   `GlyphIndex` over the Latin baseline returns mostly zero, or the family name
   matches known icon-font patterns. When the verdict is uncertain, the font is
   not subsetted. Conservative bias is intentional.

3. **The full WOFF2 is always the primary `@font-face` src.** The subset is
   added via a `unicode-range` descriptor so the browser fetches it only for
   in-range codepoints and falls back to the full font for anything outside that
   range. Subsetting is purely additive bandwidth optimization, never a
   replacement. A page that references a codepoint outside the subset range is
   never broken.

4. **Any subset failure produces a permanent negative marker for that
   `(source_hash, subset_spec)` pair.** The full-font row is unaffected and
   continues serving. Negative rows are never retried.

5. **Content addressing includes the subset spec.** The asset key is
   `fonts/<tenant>/<source_hash>.<range>.woff2` for range mode and
   `fonts/<tenant>/<source_hash>.s<spec_hash12>.woff2` for used mode, so
   identical inputs are computed exactly once and a spec change never overwrites
   an existing full WOFF2.

6. **Existing ceilings apply unchanged.** `MaxFontBytes`, `MaxDecodedFontBytes`,
   and the `safeTranscode` panic wrapper all cover the subset path.

### GPOS/GSUB shaping caveat

`tdewolff/font` `Subset()` with `KeepMinTables` rebuilds the cmap and remaps
the legacy `kern` table, but drops GPOS, GSUB, and GDEF (upstream TODO).
OpenType shaping -- ligatures, contextual kerning, complex-script features,
small-caps -- is lost after subsetting. For body-text Latin web fonts relying on
`kern` and `glyf`, this is usually acceptable. It is precisely why:

- the fixed-range default is conservative (full Latin alphabet present, no
  tofu risk),
- the full WOFF2 fallback via `unicode-range` is mandatory, and
- `fonts_subset` defaults to off.

`KeepAllTables` is the wrong option: it copies GPOS and GSUB with stale glyph
IDs, which is worse than dropping them. `KeepMinTables` is the only correct
`SubsetOptions`.

### Media-encoder changes

`args.go` gains an optional `SubsetSpec` value type with fields `Mode`
(`none|range|used`), `Range` (`latin|latin-ext`), and `Codepoints` (strict hex
range list for `used` mode). `Mode` defaults to `none` so all existing Phase-1
enqueues deserialize unchanged. `Kind()` stays `"font_transcode"`, no new kind.

`transcode.go` branches in `sfntToWOFF2` on the spec mode. `none` takes the
existing path. `range` and `used` call `isVariableFont`/`isIconFont` detectors
first, build `glyphIDs`, and call `sfnt.Subset(glyphIDs, SubsetOptions{Tables:
KeepMinTables})` before `WriteWOFF2()`. `ErrVariableFont`, `ErrIconFont`,
`ErrSubsetEmpty`, and `ErrSubsetFailed` are added to `isPermanent()` in
`worker.go`.

### Agent changes: external-stylesheet discovery and family capture

The agent's existing transcode pass scans only inline `<style>` blocks. Phase 2
extends it to also scan enqueued external stylesheets: fetch external CSS, run
`extractFontUrls()` over it, transcode any `ttf/otf/woff` URLs found, and
rewrite them to the WOFF2 local path. This closes the classic-theme and plugin
case, which inline-only discovery cannot reach.

`tryRewriteFontFaceBlock` is updated to capture `font-family` at discovery
(currently discarded) so the results catalog can label each row.

A filesystem scan of `wp-content/fonts` is explicitly not built in Phase 2.
`wp_print_font_faces()` already emits activated Font Library fonts as inline
`@font-face` on block themes; an inline scan covers that case without touching
disk. Registered-but-inactive fonts are never referenced by a page and
optimizing them is wasted work.

The agent reports a `SubsetSpec` field when enqueuing subset jobs (after the CP
and media-encoder are deployed, per deploy order below). On receiving the
`fonts_subset` config flag, the agent includes a `unicode-range` descriptor
alongside the full WOFF2 `src` in the rewritten `@font-face` rule.

### Data model: `font_results` (migration m55)

A new `font_results` table serves as the per-site dashboard read-model, separate
from `font_transcode_results` (the job-control layer), mirroring how
`rucss_results` and `rucss_jobs` are separate:

```sql
id            uuid        PRIMARY KEY DEFAULT gen_random_uuid()
tenant_id     uuid        NOT NULL REFERENCES tenants(id) ON DELETE CASCADE
site_id       uuid        NOT NULL REFERENCES sites(id)   ON DELETE CASCADE
source_hash   text        NOT NULL
family        text
source_file   text
original_ext  text
original_size integer
woff2_size    integer
subset_size   integer
unicode_range text
state         text        NOT NULL DEFAULT 'pending'
savings_pct   numeric(5,2)
created_at    timestamptz NOT NULL DEFAULT now()
updated_at    timestamptz NOT NULL DEFAULT now()

CONSTRAINT font_results_site_hash_uniq UNIQUE (site_id, source_hash)
CHECK (state IN ('pending','ready','subset','negative'))
```

State progression: `pending -> ready -> subset`; `negative` is terminal.
`subset` is a superset of `ready`: a subset WOFF2 was produced and is smaller.
`savings_pct` is derived CP-side from `original_size` over
`min(woff2_size, subset_size)` at upsert -- never trusted from the agent body.

Indexes: `idx_font_results_site (site_id, updated_at DESC)` for dashboard
list reads; `font_results_tenant_idx (tenant_id)` for RLS scans.

RLS mirrors m54 exactly: `ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL
SECURITY`, two policies each with both `USING` and `WITH CHECK`:

- `tenant_isolation`: `USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)` -- the `nullif` guard is load-bearing.
- `agent_access`: `USING (current_setting('app.agent', true) = 'on')` -- the GUC value is the string `'on'`, not `'true'`.

Missing `WITH CHECK` would allow a cross-tenant INSERT from a hostile agent. The
RLS-presence assertion in the perf routes-contract test must cover this table.

`site_perf_config` gains three new columns (DO-block `ADD COLUMN IF NOT EXISTS`,
also in `db/schema.sql`): `fonts_subset boolean NOT NULL DEFAULT false`,
`fonts_subset_mode text`, and `fonts_subset_range text`.

### API

`POST /agent/v1/fonts/results` -- new `FontAgentHandler` method, security
`AgentSignature`. `tenant_id` and `site_id` are taken from the verified agent
identity (`agent.IdentityFromContext`), never from the request body.

`GET /api/v1/sites/{siteId}/perf/fonts` (operationId `listFontResults`) --
paginated list returning `FontResultDTO` items, on the existing `PerfH` handler
with `PermSiteRead` + per-site `canReadSite` scoping.

OpenAPI spec adds `FontResult` and `FontResultList` schemas (alongside
`RucssResult`), the new paths, and `fonts_subset`/`fonts_subset_mode`/
`fonts_subset_range` fields on `PerfConfig`. Codegen:

```
go generate ./internal/api/gen/...
pnpm -C packages/openapi-client generate
```

The new `/agent/v1/fonts/results` route must appear in `perf/routes_contract_test.go`.

### Processing UI

Six pieces mirroring the RUCSS UI:

1. `useFontResults(siteId, page)` -- TanStack Query, `queryKey` includes page,
   `placeholderData:(prev)=>prev`.
2. `fonts-store.ts` -- Zustand `bySite` map; phases `null|queued|converting|done|failed`; `done` auto-clears after 8 s; 120 s stale backstop.
3. `FontsLiveIndicator.tsx` -- SSE-driven aggregate progress ("Converting fonts
   (3/7)..."); on `done`: "Converted 7 fonts, saved 58%".
4. `FontResultsTable.tsx` -- columns: Font, Format to WOFF2, Original to WOFF2,
   Subset size, Savings, State. Per-row state badges: `pending` = grey clock;
   `converting` = blue spinner; `ready` = green check; `subset` = teal scissors;
   `skipped` = amber (icon/variable font); `failed` = red X with `error_detail`
   in title. Empty state: "No fonts discovered yet. They appear here once a page
   with self-hosted fonts is built with WOFF2 conversion on."
5. SSE reducer -- `font.*` event cases in `perfEventReducer`, routing to
   `fonts-store.setPhase` and invalidating `perfKeys.fonts` on completion.
6. `perfKeys.fonts(siteId)` added alongside the existing perf query keys.

`FontResultsTable` renders in `OptimizeTab` immediately after `RucssResultsTable`
(line 81). `FontsSection` (the toggle card) gains the `fonts_subset` toggle with
mode/range selector gated visually on `fonts_transcode_woff2`.

Discovery is passive (fonts surface on page-build), so the table is observe-only
in v1. A "Re-scan fonts" warm-a-page action is deferred.

## Consequences

- **Deploy ordering is strict.** The media-encoder image must ship before any CP
  or agent release that enqueues subset jobs, or jobs enqueue and never run. See
  ADR-043 and the media-encoder-deploy-ordering memory entry.
- **GPOS/GSUB shaping is dropped on subsetted fonts.** Ligatures, contextual
  kerning, and complex-script shaping are silently absent. The full WOFF2
  fallback via `unicode-range` is the safety net, which is why subsetting is
  default-off and the per-font QA table makes every skip and failure visible.
- **Icon and variable fonts produce a permanent negative.** They are not retried.
  The full WOFF2 continues serving for those fonts.
- **Two new object-storage key shapes.** `GuardStorageKey` and `extractHex64`
  must accept the new suffixed patterns; tests cover both shapes.
- **`font_results` is site-scoped** (UNIQUE on `site_id, source_hash`), unlike
  the tenant-scoped `font_transcode_results`. The same font on two sites shows
  each site's savings independently.
- **No new dependencies.** `tdewolff/font` `Subset()` is already in the
  media-encoder module from Phase 1. Zero new libraries.
- **Web image must be rebuilt** whenever the UI is updated. It is easy to forget
  because no Go compile error signals a missing frontend deploy.
