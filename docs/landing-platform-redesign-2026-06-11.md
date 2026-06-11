# Landing "The platform" section redesign: final build spec

Date: 2026-06-11
Scope: apps/landing only. No new dependencies, no new color systems, static content, single-page anchors.
Status: APPROVED DIRECTION (synthesized from three competing proposals; see Judgement below).

---

## 1. Judgement

Three directions were scored against (1) fixing alignment/wall-of-text, (2) richness of UI, (3) scalability to 25+ features, (4) fit with the existing Impeccable token language, (5) implementation risk on a static Vite page.

| Criterion | A: Capability Atlas | B: Flagship Bento | C: Spotlight Grid (dialogs) |
|---|---|---|---|
| Fixes the problem | 5 | 4 | 5 |
| Rich UI/UX | 3 | 5 | 3.5 |
| Scales to 25+ | 5 | 3 | 5 |
| Token/page fit | 4.5 | 4.5 | 3.5 |
| Implementation risk | 4 | 3.5 | 3.5 |
| **Total** | **21.5** | **20** | **20.5** |

**Winner: A (Capability Atlas)** — five named clusters of uniform index cards with a sticky chip rail. It is the only proposal that fixes the problem, scales without a contentious "which 4 features are flagship" decision every release, and reuses page language wholesale (IconChip, the RUM privacy-card Check-bullet pattern, the Badge pill, Reveal).

**Grafts taken from the losers:**

- From B (Bento): the **mini data visuals**. A's one weakness is that its cards are text-only. B's visuals are built from motifs the page already owns (the RUM preview's TrendSparkline and DistributionBar, the Media before/after byte bars). We graft them onto exactly the three cards that carry a "See it in depth" link (Performance, RUM, Media Optimizer), all in one cluster (Accelerate), so row alignment within the cluster is preserved and the richest visuals sit on the marquee features. Rule codified: **a visual is only allowed on a card that has a deep-dive link**, so visuals cannot proliferate ad hoc.
- From B and C: **exact-count assertions in the build gate** (the budget regex must extract a declared number of strings or the build fails loudly, killing silent regex drift), and **structural row equality** via `auto-rows-fr` + `h-full` on the Reveal wrapper, not just default grid stretch.
- From C: per-cluster **stable anchor ids** so the NAV can finally deep-link "DB Cleaner" to its cluster, and the discipline note that the motion wrapper must carry `h-full` or equal heights silently break.

**Explicitly rejected:**

- B's 4-slot flagship band: forces a swap-only promotion ritual each release and demotes 13 features to single sentences with detail exiled to the FAQ.
- C's native `<dialog>`: the page has no modal idiom today, hash/history state adds JS complexity, and the richest copy would render inside `display:none` content on a marketing page.

---

## 2. Chosen information architecture

NOTE: `FEATURES.cards` in content.ts contains **17** features today (the brief said 16). All 17 are assigned. Two title renames for line length, one split (the 400-word Clients card becomes three honest cards: Client management / White-label reports / Client portal), and one added pointer card (Media Optimizer, today absent from the platform grid despite being the flagship and having its own spotlight section). Result: **20 uniform cards in 5 clusters.**

The platform grid is the INDEX. Any feature that deserves more than 4 bullets of story earns a dedicated deep-dive Section elsewhere on the page (the existing Performance/RUM/Media pattern) and its index card gets a `link` (+ optional `visual`). Card prose never grows.

| # | Cluster (id) | Card | Icon | Link | Visual |
|---|---|---|---|---|---|
| 1 | Operate (`platform-operate`) | Fleet connection | Network | | |
| 2 | Operate | Backups and restore | DatabaseBackup | | |
| 3 | Operate | Fleet updates | RefreshCw | | |
| 4 | Operate | Monitoring and health | Activity | | |
| 5 | Accelerate (`platform-accelerate`) | Performance and caching | Zap | `#performance` | cache-trend |
| 6 | Accelerate | Real User Monitoring | BarChart2 | `#rum` | rum-distribution |
| 7 | Accelerate | Media Optimizer (NEW pointer card) | ImageDown | `#media` | media-compare |
| 8 | Clean up (`platform-clean`) | Database Cleaner | DatabaseZap | | |
| 9 | Clean up | Database Snapshots | RotateCcw (was DatabaseBackup; kills the duplicate) | | |
| 10 | Clean up | Search and Replace | Replace | | |
| 11 | Clean up | Unused Image Cleaner | ImageOff | | |
| 12 | Serve clients (`platform-clients`) | Client management (split 1/3) | Briefcase | | |
| 13 | Serve clients | White-label reports (split 2/3) | ScrollText | | |
| 14 | Serve clients | Client portal (split 3/3) | LayoutDashboard | | |
| 15 | Serve clients | Per-site email and log (renamed) | MailCheck | | |
| 16 | Protect (`platform-protect`) | Security | ShieldCheck | | |
| 17 | Protect | Team and access | Users | | |
| 18 | Protect | Email in minutes | Mail | | |
| 19 | Protect | Account recovery (renamed) | KeyRound | | |
| 20 | Protect | Open sign up (renamed) | UserPlus | | |

Cluster chips/headers: Operate = ServerCog, Accelerate = Gauge, Clean up = Eraser (new registry entry), Serve clients = Handshake (new registry entry), Protect = LockKeyhole.

"Team and access" lives in Protect, not Serve clients: roles/sharing/SSO/audit answer "who can touch the fleet". "Email in minutes" (instance SMTP) lives in Protect beside the recovery/verification flows it powers.

NAV changes in content.ts: `{ label: "DB Cleaner", href: "#platform-clean" }` (was `#features`); add `{ label: "Clients", href: "#platform-clients" }` after "Media".

Where the cut prose already lives (nothing is lost from the page): Performance/RUM essays are covered by `#performance`, `#performance-how`, `#rum`; Media by `#media`, `#media-how`; backups detail by the "How do backups work?" FAQ; DB Cleaner detail by its FAQ. The Clients and Per-site email essays have no deep-dive home: **ship two new FAQ entries in the same PR** ("What do client reports and the portal include?" and "How does per-site email work?") carrying the portal-revocation, suppression, retention, and provider-failover specifics from the current desc strings.

---

## 3. Component tree (files under apps/landing/src)

```
src/
  sections.tsx                      EDIT: FeatureGrid -> PlatformSection (same Section id="features")
  data/content.ts                   EDIT: FEATURES reshape (clusters), all new copy, NAV hrefs
  components/
    cards.tsx                       EDIT: + ClusterFeatureCard; DELETE FeatureCard (only FeatureGrid used it)
    feature-visuals.tsx             NEW: CacheTrendMini, RumDistributionMini, MediaCompareMini + VISUALS registry
    sparkline.tsx                   NEW: TrendSparkline extracted from rum-preview.tsx (parameterized fill)
    distribution-bar.tsx            NEW: DistributionBar extracted from rum-preview.tsx
    rum-preview.tsx                 EDIT: imports the two extracted components, behavior unchanged
    icon.tsx                        EDIT: import + register Eraser, Handshake (lucide; HelpCircle fallback exists)
  lib/
    use-active-cluster.ts           NEW: ~20-line IntersectionObserver scrollspy hook (zero deps)
scripts/
  check-copy.mjs                    EDIT: add the copy-budget pass (section 7)
```

Render hierarchy inside `PlatformSection`:

```
<Section id="features">
  <Container className="flex flex-col gap-10">
    <Reveal><SectionHeading align="left" .../></Reveal>
    <ClusterChipRail clusters={FEATURES.clusters} active={useActiveCluster(ids)} />   // sticky
    {FEATURES.clusters.map(cluster =>
      <div id={cluster.id} className="scroll-mt-36 flex flex-col gap-6 border-t border-border pt-12 first:border-t-0 first:pt-0">
        <Reveal>{/* IconChip + h3 cluster name + tagline */}</Reveal>
        <div className="grid auto-rows-fr gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {cluster.features.map((f, i) =>
            <Reveal className="h-full" delay={(i % 3) * 0.05}>
              <ClusterFeatureCard {...f} />
            </Reveal>)}
        </div>
      </div>)}
  </Container>
</Section>
```

### ClusterFeatureCard anatomy (cards.tsx, all existing tokens)

1. Header row: existing `IconChip` (h-9 w-9, `bg-[var(--primary-subtle)] text-[var(--primary-pressed)]`, Icon size 18) + `h4` title `truncate text-base font-semibold text-foreground` (single line; truncate is a seatbelt, budget prevents it). Heading drops h3 -> h4 because clusters introduce an h3.
2. Summary: `p.text-sm.leading-relaxed.text-muted-foreground.line-clamp-2` (clamp = runtime seatbelt).
3. Bullets: `ul.flex.flex-1.flex-col.gap-1.5`; each `li.flex.items-start.gap-2.text-sm.text-muted-foreground` with leading `<Icon name="Check" size={14} className="mt-0.5 shrink-0 text-[var(--success)]" />` (the exact RUM-privacy-card pattern already shipped). `flex-1` absorbs height differences so footers align.
4. Visual (only when `visual` is set): fixed-height block `h-16` rendered from the VISUALS registry, plus one caption line `text-2xs text-muted-foreground` ending in "sample data" wherever figures appear. `aria-hidden` on the graphic; the caption carries the meaning.
5. Footer link (only when `link` is set): `a.mt-auto.inline-flex.items-center.gap-1.5.pt-2.text-sm.font-medium.text-primary hover:text-[var(--primary-hover)] transition-colors duration-[var(--duration-fast)]`, fixed label "See it in depth", trailing `Icon ArrowRight size={14}`. Anchors only.

Card surface: existing `Card` with `className="flex h-full flex-col gap-3 p-5"` (denser p-5 for 20 cards; single hairline surface, no nested cards per the Card doc comment).

### feature-visuals.tsx (pure divs + tokens + motion whileInView; transform-only, reduced-motion safe via global MotionConfig)

- `CacheTrendMini`: TrendSparkline (~18 bottom-aligned scaleY bars, fill `bg-[var(--success)]/70`, no threshold line). Caption: `94% cache hit ratio, 30 days, sample data`.
- `RumDistributionMini`: DistributionBar reused as-is (68/22/10) + one mono line `LCP p75 2.1s` with the existing Good badge styling. Caption: `Field data at p75, sample data`.
- `MediaCompareMini`: two stacked horizontal byte bars (original full width `bg-muted-foreground/30`, optimized ~29% width `bg-[var(--success)]/70`, scaleX whileInView), labels `2.4 MB JPEG` / `712 KB AVIF` in `font-mono text-2xs`. Caption: `A sample upload and its thumbnails, sample data`.

`export const VISUALS: Record<FeatureVisual, () => JSX.Element>` keyed registry; ClusterFeatureCard looks up by name.

### ClusterChipRail + scrollspy

- Rail: `div.sticky.top-16.z-30.bg-background/85.backdrop-blur-md` with gutter bleed `-mx-5 px-5 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8`; inner `div.flex.gap-2.overflow-x-auto.py-3` (add `[scrollbar-width:none]`).
- Chip: plain `<a href="#platform-*">` styled like the existing Badge pill (`rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground whitespace-nowrap`), leading cluster `Icon size={14}`. Active state swaps to `border-transparent bg-[var(--primary-subtle)] text-[var(--primary-pressed)]`.
- `use-active-cluster.ts`: IntersectionObserver over the cluster wrapper ids, `rootMargin: "-30% 0px -60% 0px"`, returns the active id. Chips are fully functional plain anchors without it (progressive enhancement; ship chips first, highlight second).

---

## 4. Content schema (content.ts)

```ts
// ---------------------------------------------------------------------------
// PLATFORM INDEX. HARD COPY BUDGETS, enforced by scripts/check-copy.mjs
// (build-failing). Formatting contract for the budget parser: every budgeted
// field below is a single-line, double-quoted string literal.
//
//   cluster.name      <= 16 chars. Clusters: min 4, max 6.
//   cluster.tagline   <= 90 chars, exactly one sentence.
//   feature.title     <= 26 chars, no terminal period.
//   feature.summary   <= 120 chars, exactly one sentence.
//   feature.bullets   2 to 4 items, each <= 64 chars, no terminal period.
//   features/cluster  <= 6. At 7 you split the cluster or promote a feature
//                     to a deep-dive section.
//   feature.link      optional; href MUST start with "#". The rendered label
//                     is always "See it in depth" (not in the data).
//   feature.visual    optional; ONLY allowed when link is present. Must be a
//                     key of VISUALS in components/feature-visuals.tsx.
//
// PROMOTION RULE: the grid is the index. A feature that deserves more than
// 4 bullets of story gets its own deep-dive Section (the Performance/RUM/
// Media pattern) and its card gains a link. The card itself never grows.
// ---------------------------------------------------------------------------

export type FeatureVisual = "cache-trend" | "rum-distribution" | "media-compare";

export type ClusterFeature = {
  icon: string;                 // key in the icon registry, unique within a cluster
  title: string;                // <= 26 chars
  summary: string;              // <= 120 chars
  bullets: string[];            // 2-4 x <= 64 chars
  link?: { href: `#${string}` };
  visual?: FeatureVisual;       // requires link
};

export type FeatureCluster = {
  id: `platform-${string}`;     // anchor target for the chip rail and NAV
  icon: string;
  name: string;                 // <= 16 chars
  tagline: string;              // <= 90 chars
  features: ClusterFeature[];   // 2-6
};

export const FEATURES: {
  eyebrow: string;
  heading: string;
  subhead: string;
  clusters: FeatureCluster[];
} = { ... };  // full copy in section 5
```

The old `FEATURES.cards` array and its `desc` field are **deleted**, so there is nowhere to put an essay. `FEATURES.body` is deleted (unused after the rewrite). `eyebrow`/`heading` stay as-is; `subhead` is trimmed to: `"One dashboard, no add-on sprawl. Five capability areas, every line of code open to read and extend."`

---

## 5. Rewritten copy, all 20 cards (paste-ready; every string within budget; no em/en dashes; provider names SES/SendGrid/Mailgun/Postmark are integrations already in shipped copy, not competitors)

### Cluster: Operate (`platform-operate`, icon ServerCog)
Tagline: `Connect a site in under a minute, then run the whole fleet from one screen.`

| Card | Summary | Bullets |
|---|---|---|
| **Fleet connection** (Network) | Sites enroll with a one-time code and stay verifiably connected. | Live flip from Awaiting to Connected, no refresh / One-click wp-admin login, no shared passwords / Stable status badges plus a manual Re-check |
| **Backups and restore** (DatabaseBackup) | Scheduled full and incremental backups with point-in-time restore. | Increments pack only files that changed / Base plus increments in one expandable chain / Restore to any snapshot, site stays online |
| **Fleet updates** (RefreshCw) | Preview version changes, then update with an automatic safety net. | Snapshot first, auto-revert on failed health check / Bulk runs by group or tag / Live per-site progress |
| **Monitoring and health** (Activity) | Uptime, response time, and fleet-wide status at a glance. | 7, 30, and 90 day charts / Down and recovery alerts by email or webhook / TLS expiry warnings and PHP fatal tracking |

### Cluster: Accelerate (`platform-accelerate`, icon Gauge)
Tagline: `Make every page faster, then prove it with real-visitor data.`

| Card | Summary | Bullets | Link / Visual |
|---|---|---|---|
| **Performance and caching** (Zap) | Full-page caching and asset optimization, per site or fleet-wide. | Pre-gzipped pages served straight from disk / Unused CSS removal, minify, defer, lazy-load / WOFF2 font transcoding and subsetting / WooCommerce cart-session caching | `#performance` / cache-trend |
| **Real User Monitoring** (BarChart2) | Core Web Vitals from real visitors at the p75 Google uses. | LCP, INP, CLS, FCP, TTFB distributions / 28-day trends with threshold lines drawn on / Per-URL and per-device breakdowns / Anonymous, off by default, no cookies | `#rum` / rum-distribution |
| **Media Optimizer** (ImageDown) | Re-encode the media library to AVIF and WebP, fully reversible. | Originals archived on the site / Right format per browser, automatic fallback / No image bytes touch the control plane | `#media` / media-compare |

### Cluster: Clean up (`platform-clean`, icon Eraser)
Tagline: `Database and media hygiene that previews first and reverses cleanly.`

| Card | Summary | Bullets |
|---|---|---|
| **Database Cleaner** (DatabaseZap) | Scan first, then clean revisions, transients, and orphans in batches. | Per-table inventory with owner labels / Orphans classified against a signature corpus / 90-day health trend and a fleet-wide view |
| **Database Snapshots** (RotateCcw) | A quick local snapshot before a risky change, instant revert after. | Faster and lighter than a full backup / No remote storage required |
| **Search and Replace** (Replace) | Serialization-safe find and replace across the whole database. | PHP-serialized data survives intact / Preview matches before committing |
| **Unused Image Cleaner** (ImageOff) | Finds media nothing references, with proof of where used images appear. | Reversible quarantine before any delete / Ambiguous references count as in-use / Per-image usage report |

### Cluster: Serve clients (`platform-clients`, icon Handshake)
Tagline: `Group sites by customer and put your brand on everything they see.`

| Card | Summary | Bullets |
|---|---|---|
| **Client management** (Briefcase) | Group any number of sites under named client records. | Brand color, logo, contacts, and notes / Bulk-assign sites from the fleet view / Filter the fleet or jump to a client page |
| **White-label reports** (ScrollText) | Branded maintenance reports on a schedule or on demand. | Uptime, backups, updates, vitals, email health / HTML email, print page, and vector-chart PDF / Per-section toggles, custom intro and closing / Powered-by footer removable on any plan |
| **Client portal** (LayoutDashboard) | A read-only branded portal where clients see their own sites. | Email invites on the same login page / Uptime, backups, vitals, report downloads / Access revoked instantly on removal |
| **Per-site email and log** (MailCheck) | Per-site outgoing email with a central, searchable delivery log. | SES, SendGrid, Mailgun, Postmark, any SMTP / Named connections with automatic failover / Webhook bounce and complaint suppression / Fleet-wide deliverability view and digests |

### Cluster: Protect (`platform-protect`, icon LockKeyhole)
Tagline: `Hardening, access control, and account flows that cannot lock you out.`

| Card | Summary | Bullets |
|---|---|---|
| **Security** (ShieldCheck) | Integrity scanning, brute-force protection, and an IP firewall. | Core files checked against official checksums / Escalating login blocks, admins never locked out / IP rules with a safety rail for your own IP |
| **Team and access** (Users) | Four roles, per-site sharing, SSO, and a tamper-evident audit log. | Owner to viewer, least privilege by default / Share one site without exposing the fleet / Email sign-in or company OIDC |
| **Email in minutes** (Mail) | Point the control plane at your own SMTP server in Settings. | Send a test message before saving / Credentials encrypted at rest / All transactional mail routes through it |
| **Account recovery** (KeyRound) | Self-serve password reset and change, no support ticket. | Works from any device, no admin involved / A change signs out every other session |
| **Open sign up** (UserPlus) | Email-verified self-registration with a configurable gate. | Verification link before any access / No manual provisioning / Lock it down when onboarding is done |

---

## 6. Layout and breakpoint spec

- Section shell unchanged: `Section id="features"` (all existing `#features` anchors keep working) > `Container` (max-w-6xl, px-5/6/8) > `flex flex-col gap-10`.
- Heading: existing `SectionHeading align="left"`, eyebrow "The platform", heading unchanged, trimmed subhead (section 4).
- **Chip rail**: sticky `top-16 z-30` (navbar is sticky h-16 z-50; rail tucks under it), `bg-background/85 backdrop-blur-md`, negative-margin bleed to the container gutters, horizontally scrollable at all widths, never wraps. Identical sticky behavior on mobile.
- **Cluster wrappers**: vertical stack; `id="platform-*" className="scroll-mt-36 flex flex-col gap-6 border-t border-border pt-12 first:border-t-0 first:pt-0"`. `scroll-mt-36` (144px) clears navbar 64px + rail ~52px from every chip and NAV anchor. Cluster header: IconChip + `h3.text-xl.font-semibold.text-foreground`, tagline `p.text-sm.text-muted-foreground.max-w-2xl`.
- **Card grid**: `grid auto-rows-fr gap-5 sm:grid-cols-2 lg:grid-cols-3`. Breakpoints: <640px = 1 column; 640-1023px = 2 columns; >=1024px = 3 columns.
- **Alignment guarantee is structural, not copy-dependent**: `auto-rows-fr` equalizes rows; the Reveal wrapper carries `className="h-full"` (REQUIRED, comment-guard it: forgetting it silently breaks equal heights); the card is `h-full flex-col`; the bullet `ul` is `flex-1`; the footer link is `mt-auto`; title truncates to 1 line and summary line-clamps to 2. Within any row, surfaces, visuals, and footers align to the pixel regardless of 2 vs 4 bullets. Budgets keep row heights ~230-280px.
- Ragged last rows (4- and 5-card clusters on lg) are accepted; uniform heights make them read as intentional. Never center or pad with filler cards.
- Heading hierarchy stays sound: section h2 -> cluster h3 -> card h4.
- Motion: existing `Reveal` per card with `delay={(i % 3) * 0.05}`, one Reveal per cluster header; visuals animate transform-only (scaleX/scaleY whileInView) exactly like the shipped TrendSparkline/DistributionBar; nothing new, global MotionConfig honors prefers-reduced-motion.

---

## 7. Build gate: copy budgets in scripts/check-copy.mjs

Extend the existing gate (it already runs first in `pnpm build` and bans em dashes, en dashes, competitor names across src/). Add a `checkPlatformBudgets()` pass:

1. Read `src/data/content.ts` as text; slice from `export const FEATURES` to the next `export const`.
2. Regex-extract single-line double-quoted values for `name:`, `tagline:`, `title:`, `summary:`, and the `bullets: [...]` arrays (formatting contract documented in the content.ts header comment).
3. **Assert exact counts against a declared constant** (graft from B/C, kills silent regex drift):
   `const EXPECTED = { clusters: 5, features: 20, links: 3, visuals: 3 };` (bump when adding a feature).
4. Assert budgets: name <= 16; tagline <= 90 and exactly one terminal sentence; title <= 26, no terminal period; summary <= 120, exactly one sentence; bullets 2-4 per feature, each <= 64, no terminal period; features per cluster <= 6; clusters 4-6; every `href` inside FEATURES starts with `#`; every `visual` value has a `link` in the same object and is one of the VISUALS keys.
5. On failure print field, offending string, length, and limit; exit 1.

Runtime seatbelts (truncate/line-clamp) mean a missed budget degrades to clipped text, never to misaligned rows.

---

## 8. Interaction and accessibility notes

- Chips are plain `<a>` anchors; scrollspy highlighting is progressive enhancement (ship anchors first). Keyboard users tab through chips; `:focus-visible` ring comes from the global styles.
- The card's only interactive element is the optional footer link (real `<a>`, descriptive context via the card h4 preceding it; label "See it in depth" + ArrowRight). Cards themselves are not clickable.
- Visuals are `aria-hidden` decorative blocks; the adjacent visible caption carries the information, and every figure is labelled "sample data" (house rule from HeroPreview/RumPreview).
- Sticky stacking: rail `z-30` under navbar `z-50`, above Reveal-animated cards. Test at 360px width and short viewports; verify `scroll-mt-36` lands cluster headers below both sticky layers from every chip and NAV anchor.
- Reduced motion: all entrances are opacity/transform under the global MotionConfig + the `prefers-reduced-motion` CSS kill switch already in globals.css.
- Color: no new colors; teal `--primary-*` family for chips/links, `--success` for Check bullets and bars, all existing tokens.

---

## 9. Future features rule (codified as the header comment in content.ts, enforced by the gate)

1. **Feature 21 (next release)**: docs-writer adds ONE `ClusterFeature` object to the right cluster and bumps `EXPECTED.features`. check-copy fails the build if the summary tops 120 chars, any bullet tops 64, or bullets exceed 4. The grid absorbs it with zero layout work.
2. **Cluster full (7th feature)**: the gate fails; either split the cluster or promote a feature out (rule 3). A 6th cluster is allowed (rail holds 6 chips on one desktop line).
3. **Promotion**: a feature that deserves more than 4 bullets of story gets its own deep-dive Section elsewhere on the page (the Performance/RUM/Media pattern) and its index card gains `link` (and optionally a `visual` registered in feature-visuals.tsx). The card itself never grows.
4. **Visuals**: only on cards with a deep-dive link. New visuals are new entries in the VISUALS registry, fixed h-16, tokens only, sample-data captioned.
5. At 25+ features: 5-6 clusters of 4-5 uniform cards, rail still fits, page grows by uniform rows only. The failure mode that produced today's 400-word cards (appending prose to desc strings) is mechanically impossible: the field no longer exists and the gate rejects regrowth.

---

## 10. Implementation order and verification

1. `scripts/check-copy.mjs` budget pass (land in the SAME commit as the data reshape, not as a follow-up).
2. `content.ts`: FEATURES reshape + all copy from section 5 + NAV hrefs + header-comment contract.
3. `icon.tsx`: register Eraser, Handshake.
4. Extract `sparkline.tsx` + `distribution-bar.tsx` from rum-preview.tsx; re-import there; build `feature-visuals.tsx`.
5. `cards.tsx`: ClusterFeatureCard; delete FeatureCard.
6. `sections.tsx`: PlatformSection + ClusterChipRail; `lib/use-active-cluster.ts`.
7. Verify: `pnpm -C apps/landing check-copy && pnpm -C apps/landing typecheck && pnpm -C apps/landing build`, then `npx impeccable detect src/` (per the landing SOP), then a visual pass at 360 / 768 / 1280 px including sticky-rail stacking and anchor offsets.
8. Before shipping, grep apps/landing for the old titles ("Clients, white-label reports, and client portal", "Per-site email delivery and log", "Self-serve account recovery", "Open sign up with verification") for stray references (meta description and FAQ were checked and are clean).
9. Same PR: the two new FAQ entries (Clients report/portal contents; per-site email failover/suppression/retention) so the compressed essays keep a home on the page.

## 11. Residual risks

- Regex budget parser is brittle if content.ts formatting changes; mitigated by the exact-count assertion + formatting contract comment + runtime clamp seatbelts.
- Scrollspy can flicker between short adjacent clusters; it is a highlight-only enhancement, chips work as plain anchors regardless.
- Eraser/Handshake assume the installed lucide-react exports them; the registry falls back to HelpCircle (worst case a placeholder glyph; substitutes: Scissors, Briefcase).
- Compressing 150-400 word descs removes long-tail SEO prose from the grid; deep-dive sections + the two new FAQ entries retain the substance.
- impeccable detect has not seen the new h2>h3>h4 hierarchy, p-5 density, or the sticky rail; run it and resolve findings before ship.
