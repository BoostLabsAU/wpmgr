# WPMgr Marketing Site — Next.js Multipage Build Plan

Status: PLAN (not started) · Date: 2026-06-21 · Branch target: `feat/performance-suite` → `main`

Convert the single-page static landing (`apps/landing`, Vite SPA) into a new SSR/SSG, SEO-first, multipage Next.js marketing site (`apps/marketing`) at the apex `wpmgr.app`. Product-Hunt-ready, top-tier design + animation. Vendor-neutral; reuse the Impeccable brand; apply ui-ux-pro-max as a structure + QA layer only.

Locked decisions:
- New `apps/marketing` Next.js App Router app (v16). Do NOT convert `apps/landing`; keep it as instant rollback until LB cutover.
- SSG default · ISR for MDX/blog/changelog + live counters · no SSR.
- `motion` v12 (`motion/react`), IBM Plex + teal-195 OKLCH tokens, Tailwind v4 CSS-first.
- Cloud Run service `wpmgr-marketing` behind the apex LB via a serverless NEG + Cloud CDN; swap the apex backend from the GCS bucket via a single url-map host-route edit; `app.*`/`manage.*` untouched.
- Brand copy rules preserved: no em/en dashes, no competitor plugin names, Media-forward, open-source-first voice, WordPress trademark disclaimer in footer.

CONFIRMATIONS RESOLVED (2026-06-21, by the user):
- C1: page list = core ~45 + the `hosting-providers` solution. NO compare/alternatives hub in v1 (drop §1.4). `sites-grid` stays folded into Home + Uptime.
- C2: `migrations` is NOT a standalone page (thin/internal). Fold any mention into an existing feature page only if the inventory surfaces real copy.
- C3: `/pricing/` leads with free, open-source, self-host. Hosted tiers shown as "coming soon" with NO firm numbers (do not publish $15/$59/$169). No Offer price in JSON-LD yet.
- C4: extract shared tokens to `packages/tokens` (`@wpmgr/tokens`) now — third consumer justifies it.
- C5: direct apex flip when ready (skip the `next.wpmgr.app` staging host). Validate on the `*.run.app` URL, then the one-line apex url-map flip. Keep the GCS bucket as instant rollback.
- C6: contact form = form UI + a Route Handler POST stub that validates and returns success, STRUCTURED to drop in a CRM POST later (CRM choice TBD by the user). No email/Slack wiring in v1. PH thank-you variant `noindex`.

---

## 1. THE COMPLETE SITEMAP (the don't-miss-anything checklist)

Title pattern: `{H1 keyword} | WPMgr`. Every non-home page has a visible breadcrumb + matching `BreadcrumbList` JSON-LD. Every page carrying an FAQ emits `FAQPage`. Templates referenced below are defined in §3.

### 1.1 Core / top-level

| Page | Slug | Purpose | Primary keyword / intent | Template | Internal links (out) |
|---|---|---|---|---|---|
| Home | `/` | Category claim (open-source WordPress fleet management), 3 audience entry points, primary CTA (Get started / Star on GitHub). PH launch + apex landing target. | "WordPress fleet management", "manage multiple WordPress sites" — branded + category, transactional | `HomePage` | all 3 hubs, 3 audience solutions, pricing, GitHub, changelog |
| Pricing | `/pricing/` | Convert. Lead "free, open-source, self-host", then hosted tiers. Plan table + per-site FAQ. | "WordPress management pricing" — commercial/decision | `PricingPage` | features hub, all solutions, about, contact |
| About | `/about/` | Trust + mission: open-source story, AGPL+MIT licensing, the people, self-hostable. | "WPMgr", "who makes WPMgr" — brand/navigational | `ContentPage` | open-source, GitHub, contact, changelog |
| Contact | `/contact/` | Route sales / support / security reports / contributors. Route Handler POST. | branded/navigational | `ContactPage` | pricing, docs, security-policy |
| Changelog | `/changelog/` | Release feed (MDX/harvested from `CHANGELOG.md`). PH credibility + freshness signal + long-tail "WPMgr vX.Y.Z". | "WPMgr changelog", "release notes" — branded | `ChangelogPage` | features hub, relevant feature pages per entry |
| Product Hunt landing | `/product-hunt/` | Dedicated PH-day UTM target; launch offer, demo, "what shipped" proof. `noindex` the thank-you variant. | branded campaign | `HomePage` variant | home, pricing, GitHub, changelog |

### 1.2 Features hub + per-feature pages (13 pages, all `FeaturePage` template)

Hub `/features/` (`FeaturesHubPage`): scannable card grid mirroring the 5 `content.ts` clusters (Operate / Accelerate / Clean up / Serve clients / Protect). Targets "WordPress management features". Links down to every feature page; reuse `FEATURES.clusters` as source of truth.

| Feature page | Slug | Primary keyword | Cross-links (siblings + solution) |
|---|---|---|---|
| Backups & restore | `/features/backups/` | "WordPress backup plugin", "incremental WordPress backups" | database-cleaner; → solutions/wordpress-backups |
| Safe fleet updates | `/features/updates/` | "bulk update WordPress plugins safely" | backups, uptime-monitoring; → solutions/manage-multiple-sites |
| Uptime & health monitoring | `/features/uptime-monitoring/` | "WordPress uptime monitoring" | updates, real-user-monitoring; → solutions/manage-multiple-sites |
| Performance & page caching | `/features/performance/` | "WordPress caching", "speed up WordPress" | object-cache, media-optimizer, real-user-monitoring; → solutions/wordpress-performance |
| Redis object cache | `/features/object-cache/` | "Redis object cache WordPress" | performance, real-user-monitoring; → solutions/wordpress-performance |
| Real User Monitoring | `/features/real-user-monitoring/` | "WordPress Core Web Vitals monitoring" | performance, media-optimizer; → solutions/wordpress-performance |
| Media Optimizer (FLAGSHIP) | `/features/media-optimizer/` | "WordPress AVIF WebP optimization", "convert images to WebP WordPress" | performance, object-cache; → solutions/wordpress-performance |
| Database cleaner & snapshots | `/features/database-cleaner/` | "WordPress database cleanup", "clean wp_options" | backups, performance; → solutions/manage-multiple-sites |
| Security suite | `/features/security/` | "WordPress security hardening", "WordPress vulnerability scanner" | two-factor-auth, team-access; → solutions/wordpress-security |
| Two-factor authentication | `/features/two-factor-auth/` | "WordPress two-factor authentication", "WordPress 2FA" | security, team-access; → solutions/wordpress-security |
| White-label reports & client portal | `/features/client-reports/` | "white-label WordPress reports", "client maintenance reports" | email-deliverability, team-access; → solutions/agencies |
| Per-site email & deliverability | `/features/email-deliverability/` | "WordPress SMTP per site", "WordPress email log" | client-reports, uptime-monitoring; → solutions/agencies |
| Team & access control | `/features/team-access/` | "WordPress team access control", "WordPress audit log" | security, client-reports; → solutions/agencies |

Note: Sites grid & screenshots folds into Home + the Uptime page (not a standalone page) unless C1 says otherwise.

### 1.3 Solutions hub + use-case pages (`SolutionPage` template)

Hub `/solutions/` (`SolutionsHubPage`): index of audiences + jobs. Solution → Feature is the money path (each solution links to the 3 to 5 feature pages that prove it; features link back to 1 to 2 solutions).

Audience solutions:

| Page | Slug | Primary keyword | Links down to features |
|---|---|---|---|
| For agencies | `/solutions/agencies/` | "WordPress management for agencies" | client-reports, email-deliverability, team-access, backups |
| For freelancers | `/solutions/freelancers/` | "WordPress tools for freelancers" | backups, updates, uptime-monitoring, security |
| For hosting providers (optional v2) | `/solutions/hosting-providers/` | "managed WordPress tooling" | team-access, security, uptime-monitoring |

Job-to-be-done solutions (high-intent SEO workhorses):

| Page | Slug | Primary keyword | Links down to features |
|---|---|---|---|
| WordPress security | `/solutions/wordpress-security/` | "WordPress security" | security, two-factor-auth, team-access |
| WordPress backups | `/solutions/wordpress-backups/` | "WordPress backup" | backups, database-cleaner |
| Speed up WordPress | `/solutions/wordpress-performance/` | "speed up WordPress", "improve Core Web Vitals" | performance, object-cache, media-optimizer, real-user-monitoring |
| Manage multiple WordPress sites | `/solutions/manage-multiple-sites/` | "manage multiple WordPress sites" | nearly every feature |

Hold the line: feature pages own product-noun terms (transactional); solution pages own job/audience terms (commercial-investigation). Distinct H1 intent, generous cross-linking, no cannibalization.

### 1.4 Compare hub (optional v2, `ComparePage` template) — vendor-neutral, NO competitor plugin names

| Page | Slug | Angle |
|---|---|---|
| Self-hosted vs SaaS management | `/compare/self-hosted-wordpress-management/` | honest trade-offs, decision-stage |
| Open-source vs proprietary tooling | `/compare/open-source-vs-proprietary/` | category framing |
| Alternatives (category) | `/alternatives/wordpress-management-tools/` | "alternatives" intent, no named products |

### 1.5 Resources / blog (cluster-correct starter, `BlogIndexPage` / `BlogPostPage` / `GuidePage`)

```
/resources/                hub: links Blog, Guides, Changelog, Docs
/blog/                     index
  /blog/wordpress-security/    cluster (mini-pillar → solutions/wordpress-security)
  /blog/wordpress-performance/ cluster (→ solutions/wordpress-performance)
  /blog/wordpress-backups/     cluster (→ solutions/wordpress-backups)
  /blog/agency-operations/     cluster (→ solutions/agencies)
  /blog/{post-slug}/           article (Article JSON-LD, dynamic OG)
/guides/                   long-form pillars (3000+ words)
  /guides/wordpress-maintenance/
  /guides/core-web-vitals/
```
Starter content: 2 cornerstone guides + 2 to 3 posts per cluster. Funnel: post → cluster → solution pillar → feature page → CTA. Never dead-end a post.

### 1.6 Legal + docs

| Page | Slug | Notes |
|---|---|---|
| Legal hub | `/legal/` | links to dashboard-hosted terms/privacy + on-site security policy |
| Security policy | `/legal/security-policy/` | responsible disclosure + posture (Ed25519, redacted diagnostics, client-side-encrypted backups); real ranking page |
| Terms / Privacy | (live at `manage.wpmgr.app/{terms,privacy}`) | link only, do not duplicate |
| Docs / API reference | `manage.wpmgr.app/docs/` | keep on dashboard host; add a marketing `Docs` nav link |

Plus file-convention routes: `/sitemap.xml`, `/robots.txt`, default `/opengraph-image`, per-blog-post `opengraph-image`.

Page count v1 (excluding optional): 6 core + 1 features hub + 13 feature + 1 solutions hub + 6 solutions + 1 resources hub + 1 blog index + 4 cluster + ~6 starter posts + 2 guides + 1 changelog + 1 legal hub + 1 security policy ≈ 45 pages.

---

## 2. APP ARCHITECTURE — `apps/marketing` (Next.js App Router, v16)

### 2.1 Route tree

```
apps/marketing/
  next.config.ts            # output:'standalone', outputFileTracingRoot=repo root, @next/mdx
  mdx-components.tsx        # global MDX element → token-component map
  app/
    layout.tsx             # <html>, next/font IBM Plex, metadataBase, Org+WebSite JSON-LD, <MotionConfig reducedMotion="user">
    page.tsx               # / (SSG)
    opengraph-image.tsx    # default site OG (ImageResponse, Node runtime)
    sitemap.ts             # → /sitemap.xml (static routes + globbed MDX slugs)
    robots.ts              # → /robots.txt
    product-hunt/page.tsx
    (marketing)/           # route group — shared header/footer chrome, no URL segment
      layout.tsx
      pricing/page.tsx
      about/page.tsx
      contact/page.tsx
      features/
        page.tsx                       # hub
        [slug]/page.tsx                # per-feature (generateStaticParams from content modules/MDX)
      solutions/
        page.tsx                       # hub
        [slug]/page.tsx
      compare/[slug]/page.tsx          # optional v2
      changelog/page.tsx
      resources/page.tsx
      legal/
        page.tsx
        security-policy/page.tsx
    blog/
      page.tsx
      [category]/page.tsx              # cluster pages
      [slug]/
        page.tsx
        opengraph-image.tsx            # dynamic per-post OG
    guides/[slug]/page.tsx
  content/                  # MDX: blog/*.mdx, guides/*.mdx, changelog
  components/
    ui/                     # primitives ported from apps/landing (Button, Card, primitives)
    sections/               # Hero, FeatureGrid, BentoGrid, OpsStatus, ProofStrip, PricingTable, FAQ, CTABand, LogoCloud, Steps
    motion/                 # 'use client' leaves: Reveal, Stagger, ScrollProgress
    templates/              # FeaturePage, SolutionPage, ComparePage compositors (server)
  lib/
    content/                # typed content modules (see §2.3) + MDX frontmatter loader
    seo.ts                  # buildMetadata() + JSON-LD builders (schema-dts typed)
    site.ts                 # canonical base URL, nav config, social links
  styles/globals.css        # @import "@wpmgr/tokens/globals.css"; @import "tailwindcss";
```

Conventions: Server Components by default; only motion/interactive leaves get `'use client'`. Co-located metadata files need no wiring. Route group `(marketing)` shares chrome.

### 2.2 Rendering strategy

| Page type | Strategy | How |
|---|---|---|
| Home, pricing, features, solutions, about, legal, PH | SSG | default, no dynamic fetch; `generateStaticParams` for `[slug]` |
| Blog / guides / changelog (MDX) | SSG + ISR | `export const revalidate = 3600` (or on-demand `revalidateTag` later) |
| Live counters (sites managed, GitHub stars) | ISR island | static page, revalidate the data tag only — never SSR the whole page |
| OG images | static build or dynamic `ImageResponse` | Node runtime |

Not a static export (`output:'export'`) — that forfeits `next/og`, ISR, and Route Handlers (contact POST). SSG+ISR on Cloud Run + Cloud CDN absorbs the PH spike as edge-cached static HTML while letting content refresh without redeploy. Next 16 `fetch` defaults uncached — set `cache:'force-cache'` for any static marketing fetch.

### 2.3 Where copy lives — typed content modules + MDX (hybrid)

- Structured page copy (hero, feature bullets, FAQ, steps, pricing) → typed TS content modules under `lib/content/`, seeded by splitting `apps/landing/src/data/content.ts` (~80% of copy already exists). Keeps the no-em-dash / no-competitor sweep grep-able and the FaturePage template data-driven.
- Long-form (blog, guides, changelog) → MDX under `content/`, mapped to token components via `mdx-components.tsx`. `CHANGELOG.md` (113KB) feeds the changelog route.
- Port `apps/landing/scripts/check-copy.mjs` into `apps/marketing` and run it in CI (no em/en dashes, banned competitor list) — make it cover both TS content modules and MDX.

### 2.4 Tokens shared via `packages/tokens` (C4)

Today the OKLCH/IBM Plex block is copied verbatim between `apps/web` and `apps/landing`. With a third consumer, promote it: create `packages/tokens` (name `@wpmgr/tokens`) exporting one `globals.css` (`:root`/`.dark` OKLCH vars + `@theme inline` map + IBM Plex stack + `.dot-field`, motion vars, shadow scale). `apps/marketing/styles/globals.css` imports it then `tailwindcss`. Optionally migrate web/landing later to kill the drift. v1-safe fallback: copy the block as landing did, refactor to the package after. Recommend the package now.

Use `next/font` to self-host IBM Plex Sans (400/500/600/700) + Mono (400/500) variable on `<body>` in `layout.tsx` (zero CLS), with `font-feature-settings: "cv11","ss01","ss03"`.

### 2.5 Animation system

- `motion` v12 from `motion/react` — same package as web/landing. No framer-motion legacy.
- Motion touches the DOM → Client Components only. Put `'use client'` on small leaf wrappers in `components/motion/` (`<Reveal>` using `whileInView` + `viewport={{ once:true, margin:'-100px' }}`; `<Stagger>` 30 to 50ms per child; `<ScrollProgress>` via `useScroll`+`useTransform` on native ScrollTimeline). Pages/sections stay Server Components that compose these leaves — keeps the client JS boundary tiny.
- Reduced motion: `<MotionConfig reducedMotion="user">` in root layout. Do not hand-roll.
- LCP safety: never JS-animate the hero headline/image entrance (don't fade hero from opacity 0). Animate only `transform`/`opacity` on below-the-fold sections; reserve layout space so reveals cause no CLS. Micro 150 to 300ms, complex ≤400ms, never >500ms; ease-out enter / ease-in exit (~65% of enter); 1 to 2 animated elements per view.

---

## 3. DESIGN SYSTEM — Impeccable (visual truth) + ui-ux-pro-max (structure + QA)

Composition rule: ui-ux-pro-max answers "what sections, in what order, CTA strategy, motion timings, a11y/perf lint"; Impeccable answers "what it looks like". Different layers, compose cleanly — provided the skill's `colors.csv` / `typography.csv` / React-Native rules are deliberately NOT adopted (they would override the locked teal-195 OKLCH + IBM Plex brand).

### 3.1 Tokens (from Impeccable, unchanged)

OKLCH single brand hue 195 (teal), theme-color `#1791A6`. Semantic tokens via Tailwind v4 `@theme inline`: `--background/foreground/card/muted/accent/border/ring`, `--primary` (+`-hover/-pressed/-subtle/-foreground`), `--eyebrow`, subtle success(155)/warning(75)/info(235) families. Full light + `.dark` (class on `<html>`, persisted `localStorage["wpmgr-landing-theme"]`, applied pre-paint). Type scale `--text-2xs`→`--text-5xl` with paired line-heights; type 12/14/16/18/24/32, headings 600/700, body 400, labels 500, min 16px body mobile; body line-length 60 to 75ch, line-height 1.5 to 1.75. Radius base 0.5rem. Shadows `--shadow-sm/md/lg/xl` (low-opacity neutral, no glow). Motion `--duration-fast/base/slow` (180/240/340ms) + ease-out-quint/expo. `.dot-field` signature texture. 4/8px spacing rhythm; section rhythm 16/24/32/48; container `max-w-6xl`/`7xl`; `min-h-dvh` not `100vh`; z-index scale 10/20/40/100/1000; tabular figures for stats. NO glow, gradient text, or neon (keeps Impeccable detector happy).

### 3.2 Section archetypes (from ui-ux-pro-max `landing.csv`, rendered with Impeccable tokens)

- Home: blend of "Real-Time / Operations Landing" (row 34 — hero with live status → metrics tiles → how-it-works → CTA, using `--success/--warning/--info` subtle for up/degraded/down + subtle status pulse; maps 1:1 to the fleet/uptime story) and "Feature-Rich Showcase" (row 31) + "Trust & Authority" proof strip (row 33 — logos/stat counters/testimonial BEFORE the conversion CTA).
- Features hub / platform sections: "Bento Grid Showcase" (row 28) — bento cards, hover scale ~1.02, staggered reveal, card bg `--card`/`--muted` (substitute the skill's `#F5F5F7`/glass), high info density, mobile-stack.
- Per-feature: "Hero + Features + CTA" (row 1) with the 7-block template below.
- Pricing: pricing archetype (rows 8/14); compare pages: comparison-table archetype (rows 6/13).

### 3.3 Reusable templates (the component contracts)

- `HomePage`: sticky-nav CTA (contrast ≥7:1) + deep CTA; hero 60 to 80% above fold; one primary CTA per screen; repeat CTA after social proof.
- `FeaturePage` (7 blocks): (1) Breadcrumb + JSON-LD; (2) Hero — H1 = keyword phrase, one-sentence value prop, primary CTA + secondary "See it live" / "Read the code"; (3) Problem → Solution; (4) How it works — 3 to 4 numbered steps (generalize the existing `MEDIA_STEPS`/`PERFORMANCE_STEPS`/`ENROLL.steps` "Under the hood" pattern); (5) Screenshots/visual — real dashboard shot or the live demo widgets (`cache-trend`, `rum-distribution`, `media-compare`, `BeforeAfterCard`, `RumPreview`); (6) Sub-features bullet grid with sibling + solution links; (7) FAQ (3 to 5, FAQPage JSON-LD) + closing CTA band.
- `SolutionPage`: problem-framed hero → outcome narrative → the 3 to 5 proving feature cards (links down) → audience proof → CTA.
- `ComparePage`: honest trade-off table, vendor-neutral, no named products.
- Shared sections: `Hero`, `FeatureGrid`, `BentoGrid`, `OpsStatus`, `ProofStrip`, `Steps`, `PricingTable`, `FAQ`, `CTABand`, `LogoCloud`.

### 3.4 Brand copy rules (preserved, CI-enforced)

No em dashes (—) or en dashes (–) — use "to" for ranges. No competitor plugin names (banned: ManageWP, MainWP, WPvivid, FlyingPress, InfiniteWP, WP Remote; allowed: Wordfence Intelligence, SES/SendGrid/Mailgun/Postmark, WooCommerce, Redis). Media-forward (Media Optimizer = flagship; meta description leads with it). Open-source-first voice (AGPL CP + MIT agent, Ed25519-signed agent, self-host, "read every line", reversible by design, privacy-first/off-by-default). Footer WordPress trademark disclaimer kept. Lucide icons, one family, no emoji.

### 3.5 Pre-ship QA gate (ui-ux-pro-max 10-tier Quick Reference, Web rows)

Run the CRITICAL/HIGH web-tagged rows as a lint pass before publish: accessibility (4.5:1 text, focus rings, semantic), CLS/perf (transform/opacity only, reserved space, LCP not JS-animated), layout, one-primary-CTA, reduced-motion. Analogous to the existing `npx impeccable detect` gate — run both.

---

## 4. SEO IMPLEMENTATION

- Metadata API: `lib/seo.ts` `buildMetadata()`; `metadataBase: new URL('https://wpmgr.app')` once in root layout; `title.template` `%s · WPMgr`; per page unique `title`/`description`, `alternates.canonical`, `openGraph`, `twitter` (`summary_large_image`); `robots` noindex on PH thank-you. `generateMetadata()` for `[slug]`.
- `app/sitemap.ts`: static routes + globbed MDX slugs (blog/features/solutions) with `lastModified`/`changeFrequency`/`priority`. `generateSitemaps` only if >50k URLs (not soon).
- `app/robots.ts`: allow all, `sitemap:` → `https://wpmgr.app/sitemap.xml`; disallow any leaked private paths.
- JSON-LD (via `schema-dts` types, rendered as `<script type="application/ld+json">`): `Organization`+`WebSite` (with `SearchAction`) in root layout; `SoftwareApplication`/`Product`+`Offer` on home + pricing; `FAQPage` on every FAQ-bearing page; `BreadcrumbList` everywhere except home; `Article` on blog posts; `ItemList` on hubs; `ContactPage` on contact.
- OG images: `next/og` `ImageResponse` (1200×630) on the Node runtime (Cloud Run target; edge unavailable). Default site OG + dynamic per-blog-post OG pulling title/frontmatter into a branded teal card with IBM Plex loaded via `readFile` from a bundled ttf (≤500KB). Real dashboard screenshots double as per-feature OG — a PH/social differentiator.
- CWV targets: LCP < 2.5s (priority `next/image` hero, explicit dims), CLS < 0.1 (`next/font`, reserved aspect-ratios, transform/opacity-only motion), INP < 200ms (tiny client JS boundary). Static HTML from SSG/ISR served via Cloud CDN.

---

## 5. DEPLOY PLAN

### 5.1 Build — Next standalone Dockerfile (mirror `infra/Dockerfile.web`)

`next.config.ts`: `output: 'standalone'`, `outputFileTracingRoot: path.join(__dirname, '../../')` (CRITICAL monorepo gotcha — without it the trace roots at `apps/marketing` and drops hoisted workspace deps).

`infra/Dockerfile.marketing` (build context = repo root):
- build stage `node:22-alpine` + corepack pnpm; copy workspace manifests + `packages/` for layer caching; `pnpm install --frozen-lockfile --filter @wpmgr/marketing...`; `pnpm --filter @wpmgr/marketing build`.
- runner stage `node:22-alpine`, non-root: copy `.next/standalone`, then `.next/static` → `standalone/.next/static` and `public` → `standalone/public` (server.js does NOT copy these by default). `EXPOSE 8080`, `ENV PORT=8080 HOSTNAME=0.0.0.0`, `CMD ["node","server.js"]`.

`infra/cloudbuild.marketing.yaml` (clone `cloudbuild.web.yaml`, `_IMAGE: marketing`, `DOCKER_BUILDKIT=1`). Deploy via the prod-deploy SOP flow to `gcloud run deploy wpmgr-marketing` (project wpmgr-prod, registry asia-south1-docker.pkg.dev/wpmgr-prod/wpmgr, region asia-south1).

### 5.2 Apex LB swap (low-risk, staged; `app.*`/`manage.*` untouched)

1. Deploy `wpmgr-marketing` to Cloud Run (asia-south1). Verify on its `*.run.app` URL.
2. Create a serverless NEG → `wpmgr-marketing`; wrap in a new backend service with Cloud CDN enabled (edge-caches ISR/static for the PH spike).
3. In the existing url-map, apex `wpmgr.app` currently routes `/` → GCS backend-bucket; `app.*`/`manage.*` are separate path-matchers — do not touch them.
4. Cutover: first add the marketing backend under a staging host `next.wpmgr.app`, validate end-to-end (all SEO routes, sitemap, robots, OG, ISR, breadcrumbs). Then flip the apex default route's `service` from the backend-bucket to the marketing backend service in one `gcloud compute url-maps` edit. Rollback = flip the one route back to the bucket. No cert work (apex managed cert already covers `wpmgr.app`).
5. Keep the GCS bucket + `apps/landing` artifact as the instant rollback target for a release or two before decommissioning.

### 5.3 CI / Turbo / workspace wiring

- Add `apps/marketing` to `pnpm-workspace.yaml` (`packages:` list) as a first-class member (NOT the standalone nested-workspace pattern `apps/landing` uses) so it shares `@wpmgr/tsconfig` + `@wpmgr/eslint-config` + `@wpmgr/tokens`.
- `turbo.json` `build` outputs include Next: `"outputs": ["dist/**", ".next/**", "!.next/cache/**"]`. `dev` (persistent, cache:false) slots `next dev` in. Add `lint`/`typecheck`/`test` package scripts mirroring `apps/web`.
- CI (`ci.yml`): extend the JS job or add a "JS (marketing)" check so it joins the green-CI gate. Run the copy-sweep there. Never merge through red CI (main is branch-protected: PR + 4 green checks).

---

## 6. PHASED BUILD ORDER (specialist owners)

Route all build work to dedicated specialists (per standing routing rule), CP/scaffold-first, split by layer. General-purpose only for read-only research.

Phase 0 — Confirmations (BLOCKING). Resolve C1 to C6 above with the user before writing code. Specifically lock: final page list (C1/C2), public pricing numbers (C3), tokens-package vs copy (C4), cutover host + window (C5), contact-form backend + PH page (C6).

Phase 1 — Scaffold + design system + Home (frontend-architect + docs-writer).
- frontend-architect: create `apps/marketing` Next 16 App Router; `packages/tokens` (or copy for v1); `next/font` IBM Plex; `<MotionConfig>`; root layout with metadataBase + Org/WebSite JSON-LD; `lib/seo.ts` + `lib/site.ts`; the motion leaves; shared sections; `HomePage` (Real-Time Ops + Feature Showcase + Proof strip); port `check-copy.mjs`; wire pnpm workspace + turbo + CI.
- docs-writer: split `content.ts` into typed content modules; home copy; preserve brand voice + copy rules.
- Gate: `npx impeccable detect` + the ui-ux-pro-max QA pass + green CI.

Phase 2 — Features hub + all 13 per-feature pages (frontend-architect + docs-writer).
- frontend-architect: `FeaturesHubPage`, `FeaturePage` 7-block template, `[slug]` route + `generateStaticParams`, port the live demo widgets as feature visuals, per-page metadata + FAQPage/BreadcrumbList JSON-LD.
- docs-writer: harvest the flagship sections (Backups, Performance, Media, RUM, Security) verbatim; write the remaining 8 to template depth; keyword-targeted H1/title/description per §1.2.

Phase 3 — Solutions + pricing + about + contact + resources/blog + changelog + legal (frontend-architect + docs-writer + backend-architect).
- frontend-architect: `SolutionsHubPage` + `SolutionPage` `[slug]`; `PricingPage` + Product/Offer JSON-LD; `ContentPage` (about); `ContactPage` + Route Handler POST; `ChangelogPage` (MDX from CHANGELOG.md); blog index + cluster + post routes + `GuidePage` + dynamic per-post OG; legal hub + security-policy.
- docs-writer: solution narratives (audience + JTBD), pricing copy, about/mission, 2 cornerstone guides + starter posts per cluster, security-policy.
- backend-architect: contact Route Handler backend (per C6).

Phase 4 — SEO polish + OG + deploy (frontend-architect + devops-engineer + docs-writer).
- frontend-architect: `sitemap.ts`, `robots.ts`, default `opengraph-image.tsx`, all JSON-LD audited, canonical per page, CWV pass (LCP/CLS/INP targets), internal-link mesh + footer hub mesh complete.
- devops-engineer: `infra/Dockerfile.marketing` + `cloudbuild.marketing.yaml`; deploy `wpmgr-marketing` to Cloud Run; serverless NEG + backend service + Cloud CDN; staging host `next.wpmgr.app` validation; apex url-map flip (C5 timing); keep bucket as rollback.
- docs-writer: landing-decommission note, CHANGELOG entry, update any stale `/docs`/footer refs.

Definition of done: all pages live + indexed (sitemap submitted to Search Console), green CI, Impeccable + ui-ux-pro-max QA passed, apex serves Next with bucket retained as rollback, PH landing + dynamic OG verified.

---

## Source files to harvest (repo-grounded)
- `apps/landing/src/data/content.ts` — copy source-of-truth (HERO, FEATURES.clusters, MEDIA, PERFORMANCE, RUM, SECURITY, FAQ, STATS, MEDIA_STEPS/PERFORMANCE_STEPS/ENROLL.steps).
- `apps/landing/src/sections.tsx` — section components to refactor into templates.
- `apps/landing/src/components/` — live demo visuals (before-after, rum-preview, distribution-bar, feature-visuals) = direct feature-page hero visuals.
- `apps/landing/src/styles/globals.css` — token block → `packages/tokens`.
- `apps/landing/scripts/check-copy.mjs` — copy-rule gate to port.
- `infra/Dockerfile.web` + `infra/cloudbuild.web.yaml` — templates for marketing equivalents.
- `CHANGELOG.md` — changelog route content.
- `packages/tsconfig` (`@wpmgr/tsconfig`), `packages/eslint-config` (`@wpmgr/eslint-config`) — workspace deps to share.
