# Remove Unused CSS (RUCSS)

Replace a page's render-blocking stylesheets with only the CSS rules its DOM
actually uses. WPMgr computes the used-CSS **on the control plane in pure Go** —
no headless browser, no JavaScript execution, no third-party SaaS — so the
feature is fully open-source and self-hostable.

Design: [ADR-046 §3](../adr/ADR-046-performance-suite-architecture.md). Engine:
`apps/api/internal/rucss/engine/`. Architecture:
[architecture/perf-suite.md](../architecture/perf-suite.md).

> The RUCSS engine is an original WPMgr Go implementation built on
> `golang.org/x/net/html`, `github.com/tdewolff/parse`, and
> `github.com/andybalholm/cascadia`. No third-party plugin or browser is used.
> See [NOTICE.md](../../NOTICE.md).

## What it does

The agent sends the rendered page HTML plus its concatenated stylesheet CSS to
the control plane. The CP returns the minimal subset of CSS that styles that
page; the agent inlines it in `<head>` and defers the original stylesheets to
load non-blocking on interaction (the full CSS still arrives, just off the
critical path). Print stylesheets are left alone.

Enable per site with the `css_rucss` toggle (see
[features/optimization.md](./optimization.md)).

## The pure-Go engine

For each request the engine:

1. **Parses the HTML** into a DOM with `golang.org/x/net/html` (extremely lenient;
   it always yields a tree).
2. **Tokenizes each stylesheet** with `github.com/tdewolff/parse` into rulesets
   and at-rules.
3. **Matches each selector** against the static DOM with
   `github.com/andybalholm/cascadia`. A ruleset is kept if at least one of its
   comma-separated selector parts matches (or is always-kept, below); the rest are
   dropped.

There is **no inline-script scan and no runtime evaluation** — the engine is a
static analyser over one HTML snapshot. Two safety rails compensate (below).

### Runtime-state pseudos are always kept

A static DOM never exhibits a `:hover` or a `:checked` state, so a literal match
would always fail and wrongly strip the rule that styles that state. The engine
**always keeps** any selector involving a runtime-state pseudo-class or a
pseudo-element:

- runtime-state pseudo-classes: `:hover`, `:focus`, `:focus-within`,
  `:focus-visible`, `:active`, `:visited`, `:checked`, `:disabled`, `:enabled`,
  `:required`, `:optional`, `:valid`, `:invalid`, `:target`, `:placeholder-shown`,
  `:default`, `:indeterminate`, `:read-only`, `:read-write`, `:autofill`
- pseudo-elements: `::before`, `::after`, `::placeholder`, `::selection`,
  `::first-line`, `::first-letter`, `::marker`, `::backdrop`,
  `::file-selector-button`

The rule: strip the runtime pseudos from a selector, then keep it if the
remaining host element exists in the DOM; a **bare** runtime pseudo (for example
a standalone `:hover` or `*:hover`) is kept unconditionally because it cannot be
proven dead.

At-rules that carry meaning independent of selector matching are preserved:
`@charset`, `@import`, `@namespace` are always kept; `@media` / `@supports` /
`@container` are emitted only if at least one inner rule survives; `@keyframes`
survive iff a surviving rule animates their name; `@font-face` survive iff a
surviving rule references their font-family; custom properties (`--x`) are kept
iff transitively referenced via `var()` by a surviving declaration.

### The safelist

The per-site safelist `css_rucss_include_selectors` force-retains any selector it
matches. Use it when a dynamic widget loses its styling because a script adds its
class after page load (which the static analyser cannot see). Two forms:

- a plain string is a **case-sensitive substring** match (for example `swiper-`),
- a value wrapped in slashes is a **regex** (for example `/^is-(open|active)$/`).

A malformed regex entry never disables the whole safelist; it falls back to a
literal substring match for that one entry.

## Never blocks render

RUCSS is a progressive enhancement. The engine **never panics**: malformed HTML,
a fatal CSS parse error, an empty result, or a recovered panic all fall back to
returning the **full input CSS unchanged** (`FellBack = true` with a note
explaining why). A keep-all result can only fail to shrink, never break the page.

End to end, the agent always has working CSS:

- **Cache hit (HTTP 200):** the CP returns the used-CSS content; the agent inlines
  it and defers the originals.
- **Cache miss / still processing (HTTP 202):** the agent serves the **full,
  unmodified CSS** for that render and the used CSS becomes available on a later
  request once the compute job runs.
- **CP unreachable / timeout / any error:** the agent returns the page HTML
  unchanged with full CSS, logs a short operational line, and moves on.

The agent's RUCSS client wraps everything in a try/catch and is guaranteed
side-effect-free on failure, so the page is always served with working CSS.

## Structure-hash caching

Computing used-CSS per URL would be wasteful: every post in a template family has
the same structure and therefore the same used-CSS. WPMgr caches the result
against a **structure hash**, not the URL.

The agent computes the hash from the page's structural signature — distinct tag
names, class/id tokens with **trailing digits stripped** (so `post-123` and
`post-456` collapse), distinct stylesheet/script sources with `?ver=` stripped,
plus the configured include-selectors (so a safelist change invalidates the
cache). A whole template family collapses to one key.

On the CP, the used-CSS bytes are gzipped and stored in **object storage**
(`rucss_results.used_css_s3_key`); Postgres holds only the metadata and reduction
stats (`rucss_results`, `UNIQUE(site_id, structure_hash)`). A `rucss_jobs` row
(ULID) tracks each compute's `queued → running → done | failed` lifecycle.
Concurrent identical requests for the same `(site, structure_hash)` collapse to a
single computation (singleflight), so a burst of requests computes the purge
once.

Operators can list cached results, see the per-structure reduction percentage,
clear the cache for a site (which forces a recompute on the next request), and
trigger on-demand computation:

```bash
# List cached RUCSS results
curl https://manage.wpmgr.app/api/v1/sites/$SITE_ID/rucss/results \
  -H "Authorization: Bearer $TOKEN"

# Clear the cache for the site (operator, site.perf.config)
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/rucss/clear \
  -H "Authorization: Bearer $TOKEN"

# Trigger on-demand computation for specific URLs (operator, site.perf.config)
curl -X POST https://manage.wpmgr.app/api/v1/sites/$SITE_ID/perf/rucss/compute \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"urls": ["https://blog.example.com/hello-world/"]}'
```

## Operator-triggered computation ("Compute now")

In addition to the passive visitor-driven path, operators can trigger RUCSS
computation on demand from the dashboard or via the API. This is useful after a
theme or plugin update, when you want fresh results without waiting for real
visitor traffic.

**Live stream.** The job emits `rucss.*` SSE events on the shared tenant bus
while it runs:

| Event | Payload |
|-------|---------|
| `rucss.queued` | `{"job_id":"…","url":"…"}` |
| `rucss.computing` | `{"job_id":"…","url":"…"}` |
| `rucss.completed` | `{"job_id":"…","url":"…","reduction_pct":88.6,"used_css_bytes":21044}` |
| `rucss.failed` | `{"job_id":"…","url":"…","reason":"…"}` |

The dashboard shows the live queued to computing to reduced-N% sequence. Passive
background computation continues unaffected alongside operator-triggered jobs.

## Limitations

Pure-DOM RUCSS cannot see classes a script adds at runtime. The always-keep
runtime-state pseudos cover the common interactive cases; for highly dynamic
widgets, extend the per-site safelist. If a component ever looks unstyled after
turning RUCSS on, add its class to `css_rucss_include_selectors` and clear the
RUCSS cache for the site.
