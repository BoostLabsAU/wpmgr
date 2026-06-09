# RUM CLS root cause and dashboard redesign

Scope: two problems for the WPMgr Real User Monitoring (RUM) suite.

1. web-vitals `onCLS` is the only metric that does not collect. INP, LCP, FCP, and TTFB
   all accumulate samples; CLS stays at 0 samples on every site. This doc states the
   definitive reason, reconciles three independent investigations that all land on the
   same root cause, and gives the exact code change.
2. A dashboard redesign that presents the data the way Google's PageSpeed Insights and
   Search Console do (a p75 headline, a good / needs-improvement / poor distribution bar,
   and a 28-day p75 trend with the threshold lines drawn on it), built entirely from the
   histogram rollups we already store.

Grounding files (all paths absolute under the repo root):

- Tracker: `apps/tracker/src/vitals.ts`, `apps/tracker/src/index.ts`, `apps/tracker/package.json`
- Bundled lib (read-only reference): `apps/tracker/node_modules/.ignored/web-vitals/src/onCLS.ts`
- Agent injection: `apps/agent/includes/optimizer/class-rum-injector.php`
- CP read path: `apps/api/internal/perf/rum_results_handler.go`, `apps/api/internal/perf/dto.go`
- CP data model: `apps/api/internal/rum/store.go`, `apps/api/internal/rum/p75.go`, `apps/api/db/query/rum.sql`
- Web dashboard: `apps/web/src/features/perf/optimize/FleetRumPanel.tsx`, `apps/web/src/components/charts/cache-hit-ratio-chart.tsx`

---

## 1. CLS root cause

### The one-line reason

`onCLS` is the only metric whose entire measurement apparatus is wrapped inside
`onFCP(runOnce(...))`. That FCP gate is satisfied only when a buffered `PerformanceObserver('paint')`
callback runs, which happens on a later async task. Because our tracker is injected as a
`<script defer>` immediately before `</body>` into a fully page-cached document, the script
runs late, and a "view then leave" visitor can drive the page to hidden / unload BEFORE that
buffered FCP task fires. When the hide wins that race, `onCLS`'s inner body never executes, so it
never registers its `onHidden(() => report(true))` handler, and no CLS beacon is ever sent, not
even the value 0. INP, LCP, FCP, and TTFB register their own observers and hide handlers directly
at script-run time, with no FCP gate, so they finalize at hide regardless. That asymmetry is
exactly why four metrics climb and CLS stays at 0 samples.

### How the three investigations reconcile (they agree)

Three separate analyses fed this doc. They are not in conflict; they describe the same failure at
three altitudes, and the fix is the same for all three.

- web-vitals-semantics finding: the value 0 is NOT dropped. `onCLS` inits the metric to 0
  (`initMetric('CLS', 0)`), and `bindReporter` does fire the callback with 0 on a stable page
  (`0 >= 0` passes, `forceReport` is true, and `delta || prevValue === undefined` is
  `0 || true` = true). So "CLS=0 is silently dropped client-side" is ruled out. The real problem
  is one level up: the reporter is never registered in time, because it lives inside the
  FCP-gated `runOnce` body. Move registration earlier and 0 reports correctly.

- FlyingPress-teardown finding (comparison against a stock Google web-vitals bundle): `onCLS`
  usage is identical and stock in both bundles (both wrap `onFCP`, both observe `layout-shift`
  buffered, neither passes `reportAllChanges`, neither adds a manual observer). So the difference
  is NOT in how we call `onCLS`. It is two harness choices plus the library version: (a) our
  script loads late, and (b) we are on web-vitals 4.2.4 whose `firstHiddenTime` is a coarse boot
  snapshot, so a briefly-hidden page latches `firstHiddenTime` and the buffered FCP entry is then
  rejected by `onFCP`'s `entry.startTime < firstHiddenTime` guard, which never fires the gate.
  v5 seeds `firstHiddenTime` from the browser's visibility-state performance-entry history, so the
  gate resolves far more often on briefly-hidden pages.

- our-pipeline finding: in our injected bundle `onCLS = onFCP wrapping clsSetup`; the
  layout-shift observer and the visibilitychange-to-hidden reporter exist only inside `clsSetup`,
  which runs only when `onFCP` fires; `onFCP` needs FCP `startTime < firstHiddenTime`; a
  late-loading deferred script means any transient hide near init poisons `firstHiddenTime`,
  `onFCP` never fires, and CLS never registers a reporter, while `onINP` (no gate) keeps
  collecting.

Reconciliation: all three name the FCP gate plus late, deferred-before-`</body>` injection as the
cause. The value-0 path is a red herring (it works once registered). The fix common to all three
is to register web-vitals as early as possible so the buffered paint entry is observed and the CLS
hide handler is wired before the visitor can leave. Upgrading to v5 makes the gate more forgiving
and is the single highest-leverage change; early injection removes the race directly. Both should
ship.

### Evidence

From the bundled `onCLS.ts` (v4.2.4, identical shape in our `wpmgr-rum.min.js`):

```js
export const onCLS = (onReport, opts) => {
  // Start monitoring FCP so we can only report CLS if FCP is also reported.
  onFCP(runOnce(() => {
    let metric = initMetric('CLS', 0);          // inits to 0, not -1
    ...
    const po = observe('layout-shift', handleEntries);
    if (po) {
      report = bindReporter(onReport, metric, CLSThresholds, opts.reportAllChanges);
      onHidden(() => {                            // <-- registered ONLY inside the FCP gate
        handleEntries(po.takeRecords());
        report(true);
      });
      ...
    }
  }));
};
```

Contrast with the tracker's registration block in `apps/tracker/src/vitals.ts` (lines 189 to 193),
where all five are registered in one tick but only `onCLS` is internally FCP-gated:

```ts
onLCP(send);
onINP(send);
onCLS(send);   // the only one whose hide handler is deferred behind onFCP(runOnce(...))
onFCP(send);
onTTFB(send);
```

And the injection that makes the script load late, in
`apps/agent/includes/optimizer/class-rum-injector.php` (the collector tag):

```php
$collector = '<script defer src="' . esc_url($scriptUrl) . '"></script>';
// ... inserted immediately before </body>:
preg_replace('/<\/body>(?![\s\S]*<\/body>)/i', $snippet . '</body>', $html, 1);
```

`apps/tracker/src/index.ts` confirms the script self-bootstraps on load and relies on `defer`,
so on a cached document it runs after the page is already painted and shifts already happened, with
FCP and all layout-shift entries living only in the observer buffer. The server side is innocent:
`apps/api/internal/rum/handler.go` clamps and stores the value faithfully, and
`apps/api/db/query/rum.sql` writes whatever arrives, so a CLS=0 beacon would be recorded if one
were sent. The problem is that none is sent.

---

## 2. The CLS fix

Goal: CLS collects on every pageview, including stable pages that legitimately have CLS=0,
matching how INP / LCP / FCP / TTFB already behave.

### Primary change (do both parts)

1. Upgrade web-vitals from 4.2.4 to v5.x in `apps/tracker/package.json`, then rebuild the bundle
   (`node build.mjs`) and ship the new `wpmgr-rum.min.js` into `apps/agent/assets/`. v5 seeds
   `firstHiddenTime` from the visibility-state performance-entry history rather than a coarse boot
   snapshot, so the `onFCP` gate (and therefore `onCLS`) resolves on briefly-hidden and
   backgrounded pages, which is the exact condition currently zeroing CLS. This is the single
   change most likely to make CLS start collecting.

   Verify v5 changes nothing the Go ingest parses: the contract is `metric` (lowercased name) plus
   integer `value` only (see `apps/tracker/src/vitals.ts` `makeSender` and
   `apps/api/internal/rum/handler.go`). v5 keeps the same `Metric` shape and lowercase names, so
   no server change is required.

2. Register web-vitals as early as possible so the buffered `first-contentful-paint` entry is
   observed while `firstHiddenTime` is still `Infinity`, well before a view-then-leave visitor can
   hide the page. Concretely, change the agent injection so the collector is NOT a
   `defer`-before-`</body>` external script. Move it into `<head>` and load it without `defer`
   (render-time), or inline a tiny bootstrap in `<head>` that imports the bundle and calls
   `init()` at head-parse time. Either way, `onFCP` registers its `paint` observer and the
   visibility watcher before the document finishes painting, so `onCLS`'s inner `runOnce` body
   executes, arms the `layout-shift` observer and the `onHidden(() => report(true))` handler, and
   a stable page reliably emits CLS=0 at hide.

   The change in `apps/agent/includes/optimizer/class-rum-injector.php` is in `buildSnippet` and
   `process`: today it builds `<script defer src=...>` and splices it before `</body>`. Target
   state is to emit the inline config plus the collector tag into `<head>` (e.g. splice before
   `</head>` with the same single-injection guard and the same CSP conflict check). Keep the
   collector external (the existing reason: it avoids violating a strict no-unsafe-inline CSP on
   the main document); just load it from `<head>`. Also exclude this script from any
   JS-delay-until-interaction optimization so the delay layer does not re-defer it.

   Note on the tracker entry point: `apps/tracker/src/index.ts` calls `init()` immediately on load,
   so no tracker code change is needed for early load. The only lever is WHERE and HOW the agent
   injects the tag.

### What NOT to do

- Do not add a synthetic CLS=0 fallback beacon. It would corrupt data for pages that genuinely
  shifted but lost the registration race (they would report 0 instead of their real shift), and it
  duplicates the value web-vitals already reports correctly once armed in time.
- Do not rely on `reportAllChanges`. It only changes how often a CHANGED metric is emitted; it
  still runs inside the FCP-gated body, so it cannot un-skip an apparatus that was never armed.
- Do not move to a batched flush-on-visibilitychange queue. The current per-metric immediate
  beacon (the change that fixed INP) is correct and must stay. A batched flush attaching its own
  `document` `visibilitychange` listener reintroduces the documented race where the flush runs and
  sets its flushed guard before web-vitals pushes CLS / INP into the queue, dropping those two
  metrics.

### Optional defensive backstop (secondary, ship only after 1 and 2)

If desired, add an independent `visibilitychange`-to-hidden plus `pagehide` flush as a backstop,
keeping per-metric immediate send as the primary path. Attach this backstop listener to `window`,
not `document`, so it bubbles and runs AFTER web-vitals' internal `onHidden` listener (per the
known listener-ordering issue). This backstop alone will NOT fix CLS, because there is nothing to
flush if `onCLS` never fired; it is strictly secondary to the version upgrade and early injection.

### Server changes needed

None for the fix. `apps/api/internal/rum/handler.go` already accepts and stores CLS (in
milli-units, `Math.round(value * 1000)`), and the rollup writes in `apps/api/db/query/rum.sql` are
metric-agnostic. Once the client sends the beacon, the existing pipeline records it.

---

## 3. Did we miss anything?

Items checked against the stock Google reference and our pipeline:

- Other metrics: INP, LCP, FCP, TTFB are NOT gated and already collect; no change needed for
  them. Only CLS is affected by the FCP-gate-plus-late-load interaction.
- Injection timing is the shared root cause, not a CLS-only quirk. Moving the script to `<head>`
  helps the accuracy of FCP and LCP too (buffered entries are observed sooner), so the early-load
  change is a net win beyond CLS. This is the one cross-cutting gap to fix.
- Value-handling edge: CLS is stored in milli-units (value times 1000) on both the client
  (`metricValue` in `apps/tracker/src/vitals.ts`) and the server rating (`cwvRating` in
  `apps/api/internal/perf/dto.go` treats CLS thresholds as 100 and 250 milli-units). This is
  internally consistent; no edge to fix. The distribution-bar fold in section 4 must respect that
  CLS is milli-units, not ms.
- Library version drift: the `onFCP`-gating of CLS is a web-vitals implementation detail that can
  change between minor versions. After upgrading, pin to a specific v5.x and snapshot the behavior;
  re-verify the gate on a minor bump.
- Open verification items (confirm on the live store, e.g. the WooCommerce test site):
  - Confirm `onFCP` actually fires after the fix (if FCP collects but CLS still does not, the
    failure is downstream of the gate and needs a separate look).
  - Confirm the tracker is not further delayed by a JS-delay-until-interaction layer.
  - Validate against both a fully-cached render and a non-cached render.

No other metric or contract gap was found versus the reference. The collector logic is otherwise
stock and correct.

---

## 4. Dashboard redesign spec

### Intent

Present RUM data the way Google's PageSpeed Insights and Search Console do, because that visual
language is what operators already recognize for Core Web Vitals:

- a p75 headline per metric (we already render this in `FleetRumPanel.tsx`),
- a good / needs-improvement / poor distribution bar per metric (green / amber / red, summing to
  100 percent of pageviews), and
- a 28-day p75 trend per metric, with the "good" and "needs-improvement" threshold lines drawn on
  it.

FlyingPress has a Vitals tab but publishes no chart spec, so the visuals are sourced from Google's
presentation, not from any competitor plugin. Standard libraries named below (web-vitals, CrUX,
Recharts) are fine to name.

### Build from existing data, no new tables

The daily rollup already stores everything both visuals need:

- `rum_rollup_daily` (and `rum_rollup_hourly`) carry a per-slice `bucket_counts` array of exactly
  `NumBuckets` (24) integers, on the CrUX-anchored boundaries in `apps/api/internal/rum/store.go`
  (`CrUXBuckets`). The distribution bar is a sum-and-fold of those 24 buckets into three bands at
  the metric's good/NI thresholds.
- The same rollup rows, one p75 per day per metric (`ComputeP75` in `apps/api/internal/rum/p75.go`),
  are the trend series.

So no schema change. We add one field to the existing summary response and one new trend endpoint.

#### Distribution: folding 24 buckets into 3 bands

For a metric, the band boundaries are the metric's good and needs-improvement thresholds (the same
values `cwvRating` uses in `apps/api/internal/perf/dto.go`):

| Metric | good upper | NI upper | unit |
| --- | --- | --- | --- |
| LCP | 2500 | 4000 | ms |
| INP | 200 | 500 | ms |
| CLS | 100 | 250 | milli-units (value times 1000) |
| FCP | 1800 | 3000 | ms |
| TTFB | 800 | 1800 | ms |

`CrUXBuckets` boundaries (ms) are `200,300,400,500,600,800,1000,1200,1400,1600,1800,2000,2500,3000,3500,4000,4500,5000,6000,7000,8000,9000,10000`,
giving 24 buckets `[0,200) ... [10000, +inf)`. Note the boundaries are stored in the same integer
unit as the metric value, so for CLS the same array means milli-unit boundaries; the CLS good
threshold of 100 and NI of 250 both fall inside the first three boundary positions, which is fine
because the fold is by value, not by fixed index.

Fold rule (compute server-side so the client never re-derives thresholds):

- `good`  = sum of bucket counts whose value range is entirely `<=` the good threshold.
- `needs_improvement` = sum of bucket counts in `(good, NI]`.
- `poor`  = sum of bucket counts `>` NI.
- A boundary that straddles a threshold (rare given the CrUX boundaries align with the CWV
  thresholds for LCP/FCP/TTFB/INP) is assigned to the lower band; document this so the percentages
  are reproducible. For the metrics above, the thresholds coincide with bucket boundaries, so no
  bucket straddles for LCP (2500, 4000), FCP (1800, 3000), TTFB (800, 1800), and INP (200, 500);
  the fold is exact.

Return the three counts and their percentages of the slice total.

### CP changes

1. Extend the summary endpoint `GET /api/v1/sites/:siteId/perf/rum/summary` (handler
   `rumSummary` in `apps/api/internal/perf/rum_results_handler.go`). Add a `distribution`
   object to each `RumMetricSummary` in `apps/api/internal/perf/dto.go`:

   ```go
   type RumDistribution struct {
       Good             int64 `json:"good"`               // pageviews in the good band
       NeedsImprovement int64 `json:"needs_improvement"`
       Poor             int64 `json:"poor"`
       GoodPct          int   `json:"good_pct"`           // rounded, summing to 100
       NeedsImprovementPct int `json:"needs_improvement_pct"`
       PoorPct          int   `json:"poor_pct"`
   }
   ```

   Populate it by folding the slice's summed `bucket_counts` with the threshold table above. The
   handler already sums rollups via `ComputeP75`; add a sibling that returns the summed
   `bucket_counts` per `(metric, device, country)` so the fold has the histogram to work on
   (`GetRumRollupHourly` / `GetRumRollupDaily` already return `bucket_counts`; only the in-Go
   aggregation needs the extra sum). Keep the suppression rule: when
   `sample_count < min_sample_count`, omit the distribution (or return nulls) and let the UI render
   "insufficient samples", exactly as the p75 is suppressed today.

2. Add a trend endpoint `GET /api/v1/sites/:siteId/perf/rum/trend?window_days=28` returning a
   per-metric daily p75 series. Use `GetRumRollupDaily` (already in `apps/api/db/query/rum.sql`),
   run `ComputeP75` once per day per metric (the rollup already has one histogram per day), and
   shape:

   ```json
   {
     "window_days": 28,
     "min_sample_count": 30,
     "metrics": {
       "lcp": [ { "day": "2026-05-13", "p75_ms": 2310, "sample_count": 812, "rating": "good" }, ... ],
       "inp": [ ... ], "cls": [ ... ], "fcp": [ ... ], "ttfb": [ ... ]
     }
   }
   ```

   Suppress (omit, or `p75_ms: 0` plus `suppressed: true`) any day whose `sample_count` is below
   the floor, so the trend line has gaps rather than misleading zeros, consistent with the existing
   suppression contract. CLS `p75_ms` stays in milli-units; the client divides by 1000 for display
   (same as `formatP75` in `FleetRumPanel.tsx`).

   These two routes are hand-written Gin like the rest of perf (they are not in the generated SDK),
   so add the web-side types to `apps/web/src/features/perf/types.ts` and call them via the raw
   client, matching the existing RUM hooks.

### Web changes (matching the existing stack)

Use Recharts, the existing chart primitives, and the existing color tokens. The template to mirror
is `apps/web/src/components/charts/cache-hit-ratio-chart.tsx` (it sets the conventions: a
`<2`-points empty-state via `ChartEmpty`, `ResponsiveContainer`, `var(--color-chart-*)` stroke and
fill, `ChartTooltip`, short-date X ticks targeting about 6 labels, `isAnimationActive={false}`).
The rating colors already exist in `FleetRumPanel.tsx` (`RATING_COLOR_CLASS` / `RATING_BG_CLASS`:
green for good, amber for needs_improvement, red for poor).

Two new components under `apps/web/src/components/charts/` (or `features/perf/optimize/`):

1. Distribution bar `RumDistributionBar.tsx`. A single horizontal stacked bar per metric, three
   segments (good green, NI amber, poor red) sized by the `*_pct` fields, with the percentage
   labels and accessible `title`/`aria-label`. This is a plain flex/`div` bar (no Recharts needed),
   reusing the rating color tokens already in `FleetRumPanel.tsx`. Render it under each of the
   three core p75 cards (LCP, INP, CLS) and optionally for FCP/TTFB. Suppressed slices render the
   existing "insufficient samples" affordance instead of a bar.

2. Trend chart `RumTrendChart.tsx`. A Recharts `LineChart` (or `AreaChart` to match the cache-hit
   template) of daily p75 over 28 days per metric. Draw two horizontal `ReferenceLine`s at the
   metric's good and NI thresholds (green and amber), so the operator sees where the series sits
   relative to passing, exactly like PageSpeed Insights. X axis is `day` formatted to "Mon D"
   (reuse the `shortDate` helper pattern from the cache-hit chart). Y axis is the metric value
   (ms, or CLS as a unitless 3-decimal after dividing by 1000). Empty state below 2 points via
   `ChartEmpty`. One chart per metric; respect the existing device tabs in `FleetRumPanel.tsx` by
   passing the selected device into both the summary and trend queries.

Layout: keep the three big p75 cards as the headline (already built). Beneath each core card, add
the distribution bar. Below the cards, add a per-metric trend chart (a small multiple or a metric
toggle, see open questions). Power both from the summary `distribution` field and the new trend
endpoint, both off the existing daily rollup histograms, with no new tables and no agent change.

### Open questions (carry over)

- Daily only, or also hourly? (The hourly rollup exists; daily is the PSI/GSC default and the
  cheaper read.)
- Per device only, or also per page? (`rumResults` already returns per-URL p75; a per-page
  distribution is a later addition.)
- One trend chart per metric (small multiples), or one chart with a metric toggle?
