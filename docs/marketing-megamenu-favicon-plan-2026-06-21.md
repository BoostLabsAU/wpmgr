# Marketing megamenu + favicon/PWA plan (apps/marketing)

Date: 2026-06-21. Target: `apps/marketing` (Next.js 16 App Router, LIVE at wpmgr.app on Cloud Run service `wpmgr-marketing`). NOT `apps/landing` (the Vite app, untouched).

Two deliverables, shippable independently:
- A. Desktop disclosure-button megamenu + mobile accordion drawer for the site header.
- B. Favicon / app-icon / PWA-manifest set using the REAL FleetHub geometry.

---

## A. MEGAMENU

### A.1 Component architecture

Files to create (under `components/nav/`):

| File | Role | Server/Client |
|---|---|---|
| `components/nav/mega-menu.tsx` | Desktop disclosure-button megamenu (triggers + portaled panels). Imports registries itself, receives nothing. | `"use client"` |
| `components/nav/mega-menu-panel.tsx` | Presentational panel body (columns/rows/footer link). Pure, props-driven. | client (no boundary needed; whole header is client) |
| `components/nav/mobile-nav.tsx` | Hamburger + full-height drawer + accordion. | `"use client"` |
| `components/nav/nav-data.ts` | Build-time adapters: maps `HUB_CLUSTERS` + `SOLUTION_HUB_CARDS` into the menu shape; declares the panel/simple-link nav model. | plain TS module (no `"use client"`, tree-shakes into client tree) |

Edit `components/sections/header.tsx`: replace the desktop `<nav>` link map and the (absent) mobile menu with `<MegaMenu />` and `<MobileNav />`. Header stays `"use client"` (already is, owns `ThemeToggle`). Keep the Logo anchor and the existing `link.external` handling.

### A.2 Data shape (import, do not invent)

```ts
// nav-data.ts
import { HUB_CLUSTERS } from "@/lib/content/features";        // FeatureHubCluster[] {id,icon,name,tagline,features:{slug,icon,title,summary}[]}
import { SOLUTION_HUB_CARDS } from "@/lib/content/solutions";  // SolutionHubCard[] {slug,icon,title,summary,group:"audience"|"jtbd"}
import { HEADER_NAV, SITE_CONFIG } from "@/lib/site";
```

Nav model (5 top-level items):
1. **Features** — panel trigger. 5 columns = the 5 clusters. Column head = `cluster.icon` + `cluster.name` (+ `cluster.tagline` as subhead). Row = `Icon name={f.icon}` + `f.title` + `f.summary`, href `` `/features/${f.slug}/` `` (trailing slash). Panel footer: "View all features" -> `/features/`. (13 features: Operate 3 / Accelerate 4 / Clean up 1 / Serve clients 2 / Protect 3.)
2. **Solutions** — panel trigger. 2 columns partitioned by `card.group`: "By audience" (3) and "By job" (4). Author the two column headers in the component (not in data). Row href `` `/solutions/${card.slug}/` ``. Footer: "View all solutions" -> `/solutions/`.
3. **Pricing** — simple link `/pricing/` (from `HEADER_NAV[2]`).
4. **Resources** — simple link `/resources/` (`HEADER_NAV[3]`).
5. **Docs** — simple link `SITE_CONFIG.docs`, `external` -> `target="_blank" rel="noreferrer noopener"` (`HEADER_NAV[4]`).

Use `HUB_CLUSTERS` / `SOLUTION_HUB_CARDS`, NOT `FEATURE_REGISTRY`/`getFeature` (those carry full page data and bloat the client bundle). One featured callout cell is allowed per panel (e.g. a flagship "Security suite" card in the Features panel right rail) — one only.

### A.3 Where the panel mounts (stacking/overflow trap fix)

Header is `sticky top-0 z-40 ... backdrop-blur-md` with `<Container>` as immediate child. `backdrop-filter` establishes a new stacking context AND clips descendants; `<Container>` adds a max-width boundary. A panel rendered inside `<header>` is hazy, clipped, and confined.

Fix: render each open panel via `createPortal(..., document.body)` as a `position: fixed` sibling outside the blurred subtree:

```
<header className="sticky top-0 z-40 ... backdrop-blur-md">  // triggers (buttons) only
{open && mounted && createPortal(
  <>
    <div className="fixed inset-0 z-40" aria-hidden onClick={close} />            // dismiss scrim
    <div id="megamenu-panel-features" className="fixed left-0 right-0 top-16 z-50
         border-b border-[var(--border)] bg-card shadow-[var(--shadow-lg)]">      // opaque bg-card, not translucent
      <Container>...columns...</Container>
    </div>
  </>, document.body)}
```

- `top-16` = the `h-16` (64px) header height.
- z-index: header `z-40`; scrim `z-40`; panel `z-50` (no `--z-*` token scale exists in `globals.css` — use numeric utilities consistent with the existing `z-40`).
- Surface is OPAQUE `bg-card` (never the header's translucent `bg-[var(--background)]/85`) so text stays readable over page content.
- SSR guard: gate `createPortal`/`document` behind a `mounted` flag set in `useEffect`.

### A.4 Data gap (the only required content change)

`components/ui/icon.tsx` is MISSING `Laptop` (freelancers) and `Server` (hosting-providers) — confirmed absent from both the lucide import block and `REGISTRY`. The `Icon` fallback renders `HelpCircle`, so those two Solutions rows would show a question mark. Fix: add `Laptop` and `Server` to the import + `REGISTRY` (two entries; `ServerCog` already exists and is a different glyph — do not reuse it). All 13 feature icons + remaining 5 solution icons already resolve. No copy to write (every card has `title` + one-line `summary`).

---

## A.5 A11Y + KEYBOARD + HOVER-INTENT CONTRACT (implement exactly)

W3C APG Disclosure Navigation pattern. Do NOT use `role="menu"`/`role="menuitem"` (traps arrow users, removes links from the SR link rotor).

Markup:
- Top-level trigger = `<button type="button">`, with `aria-expanded` ("false" -> "true" on open) and `aria-controls="megamenu-panel-<id>"`. OMIT `aria-haspopup` (it implies a true menu widget).
- Panel = plain `<div>`/`<ul>` with NO `role`. Links stay plain `<a>`. Mark the current section with `aria-current="page"`.
- Simple links (Pricing/Resources/Docs) stay plain `<a>`.

Keyboard:
- `Tab` / `Shift+Tab`: move across top-level buttons; when a panel is open, Tab moves INTO and THROUGH the panel links in DOM order, then out to the next button. NO focus trap on desktop.
- `Enter` / `Space`: on button toggles open/closed; on link activates/navigates.
- `Escape`: closes the open panel AND returns focus to its trigger button (mandatory).
- Arrow keys: OPTIONAL (Left/Right between buttons, Down enters panel). Tab must always work; do not make arrows the only path. Do not over-engineer.

Focus / SR:
- Opening on HOVER must NOT steal focus. Opening on click/Enter may leave focus on the button and let Tab walk in (simplest correct behavior).
- `focus-within` keeps the panel open while a descendant is focused; closing while focus is inside must first return focus to the button.
- Closing restores `aria-expanded="false"`.
- Visible `:focus-visible` ring on every button and link: reuse the header pattern `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]` (>=2px, >=3:1 contrast). Never `outline:none` without a replacement.

Hover-intent (desktop pointer only — a pre-open optimization that never changes click/keyboard semantics):
- Open delay ~100-150ms (confirm intent, avoid pass-through flicker; 500ms feels laggy).
- Close delay ~250-300ms (closing instantly is rage-inducing).
- Safe triangle: while the cursor moves diagonally toward the panel, keep it open even if it briefly exits the trigger box; compute the triangle from cursor to the panel's two near corners; close when the pointer leaves it or after the close delay.

---

## A.6 MOBILE ACCORDION

Below the `md` nav breakpoint: hamburger button -> full-height drawer (modal-ish overlay). The megamenu becomes an accordion inside the drawer.
- Each top-level (Features, Solutions) = an accordion header `<button aria-expanded aria-controls>` that expands its groups in place; desktop columns STACK into a single vertical scroll. NO hover dependency — tap to expand/collapse. Same disclosure ARIA contract reused.
- Pricing/Resources/Docs render as plain links in the drawer.
- Tap targets >=44x44px (48px comfortable); generous vertical padding; full-width hit areas.
- Trap focus WITHIN the open drawer; Escape / close button returns focus to the hamburger.

---

## A.7 MOTION SPEC

- Open/close: `opacity 0->1` + small `translateY` (4-8px) OR `scale` (0.98->1), `ease-out`, 150-250ms. NEVER animate layout (no height/width/top) — animate ONLY `opacity` and `transform` (compositor-friendly, no reflow).
- `prefers-reduced-motion: reduce`: drop the translate/scale, keep an instant or ~0-80ms opacity change; panel still appears/disappears. Honor the global `MotionConfig`.
- Implementation: use `motion/react` directly with `initial`/`animate`/`exit` inside `AnimatePresence`. Do NOT reuse `Reveal`/`Stagger` (`components/motion/`) — those are `whileInView` scroll-triggered (animate once) and wrong for repeated toggle UI. REUSE their conventions/values: `y: 8`, `duration ~0.24`, easing `[0.22, 1, 0.36, 1]` (`--ease-out-quint`).
- Tokens to reuse (`packages/tokens/globals.css`): `bg-card`, `border-[var(--border)]`, `text-foreground` / `text-[var(--muted-foreground)]` (titles vs summaries), icon chips = `--primary-subtle` fill + `text-[var(--primary)]`, `rounded-[var(--radius)]` / `rounded-md` rows, `shadow-[var(--shadow-lg)]` (NO glow), `duration-[var(--duration-fast)]` (180ms hover) / `--duration-base` (240ms reveal). Chevron: `Icon name="ChevronDown"` rotate 180deg on open with a `--duration-fast` transition.

---

## B. FAVICON / APP-ICON / PWA (Next 16 file conventions)

Next auto-emits the `<link>` tags from files in `app/` — do NOT hand-add `<link rel="icon|apple-touch-icon|manifest">` in `layout.tsx`.

Files to add (all under `apps/marketing/`):

| Path | Contents |
|---|---|
| `app/icon.svg` | Primary scalable mark, legible at 16px, dark-mode-aware via in-file `prefers-color-scheme`. REAL FleetHub geometry (below). |
| `app/apple-icon.png` | 180x180 PNG, solid bg (iOS ignores SVG/transparency/manifest for home screen). Teal `#1791A6` mark on near-white `#F8FAFA`, ~16% safe-zone. Rasterized from SVG. |
| `app/favicon.ico` | Multi-res 16+32+48 legacy fallback. Rasterized. |
| `app/manifest.ts` | `MetadataRoute.Manifest` (below). |
| `public/icon-192.png` | 192x192 PWA icon, `purpose:"any"`. |
| `public/icon-512.png` | 512x512 PWA icon, `purpose:"any"`. |
| `public/icon-maskable-512.png` | 512x512 maskable: mark at ~80% on a full-bleed solid bg (no transparency) so Android's mask never clips. |

### B.1 `app/icon.svg` — REAL FleetHub geometry (NOT the invented ServerCog/W)

Reuse `apps/landing/public/favicon.svg`: center rounded square (dashboard node) + 4 satellites as SOLID DOTS (so the one-to-many shape survives at 16px) + 4 spokes. `currentColor` is unusable in a standalone favicon, so color via internal `<style>` with a dark-mode rule.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none">
  <style>
    :root { --fh: #1791A6; }
    @media (prefers-color-scheme: dark) { :root { --fh: #2CB6CC; } }
    .fh { fill: var(--fh); stroke: var(--fh); }
  </style>
  <rect class="fh" x="11.5" y="11.5" width="9" height="9" rx="2.5"/>
  <g class="fh">
    <circle cx="6.5" cy="6.5" r="2.6"/><circle cx="25.5" cy="6.5" r="2.6"/>
    <circle cx="6.5" cy="25.5" r="2.6"/><circle cx="25.5" cy="25.5" r="2.6"/>
  </g>
  <g class="fh" stroke-width="2" stroke-linecap="round" fill="none">
    <line x1="9" y1="9" x2="11.6" y2="11.6"/><line x1="23" y1="9" x2="20.4" y2="11.6"/>
    <line x1="9" y1="23" x2="11.6" y2="20.4"/><line x1="23" y1="23" x2="20.4" y2="20.4"/>
  </g>
</svg>
```

The hollow-satellite + thin-spoke `logo.tsx` variant muddies below ~24px; the dot + thicker-spoke variant above is the correct (already battle-tested) simplification.

### B.2 `app/manifest.ts`

```ts
import type { MetadataRoute } from "next";
import { SITE_CONFIG } from "@/lib/site";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "WPMgr — WordPress Fleet Management",
    short_name: "WPMgr",
    description: SITE_CONFIG.description,
    start_url: "/",
    display: "standalone",
    background_color: "#FFFFFF",
    theme_color: "#1791A6",
    icons: [
      { src: "/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
```

### B.3 `app/layout.tsx` — one addition only

Add a `viewport` export for the address-bar tint (Next 16 wants `themeColor` in `viewport`, not `metadata`); manifest `theme_color` alone does not cover Safari across schemes. Nothing else — all icon `<link>`s auto-inject.

```ts
import type { Viewport } from "next";
export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#1791A6" },
    { media: "(prefers-color-scheme: dark)",  color: "#0E5E6B" },
  ],
};
```

### B.4 Rasterize the binaries (do not hand-author)

Requires `brew install librsvg imagemagick`. Build `icon-source-light.svg` (the markup above with `<style>`/media-query stripped, `#1791A6` on `#F8FAFA`, ~2px extra padding) and `icon-source-maskable.svg` (mark at ~80% on full-bleed solid bg). Run from `apps/marketing/`:

```bash
rsvg-convert -w 180 -h 180 icon-source-light.svg -o app/apple-icon.png
rsvg-convert -w 192 -h 192 icon-source-light.svg -o public/icon-192.png
rsvg-convert -w 512 -h 512 icon-source-light.svg -o public/icon-512.png
rsvg-convert -w 512 -h 512 icon-source-maskable.svg -o public/icon-maskable-512.png
rsvg-convert -w 32 -h 32 icon-source-light.svg -o /tmp/i32.png
rsvg-convert -w 16 -h 16 icon-source-light.svg -o /tmp/i16.png
rsvg-convert -w 48 -h 48 icon-source-light.svg -o /tmp/i48.png
magick /tmp/i16.png /tmp/i32.png /tmp/i48.png app/favicon.ico
```

`apps/landing` is unchanged (Vite hard-codes `<link rel="icon">` in `index.html` — correct for that app).

---

## C. Verification + redeploy

Local, from `apps/marketing/` (the SAME commands CI runs — see [[wpmgr-ci-green-gate]]):
1. `pnpm check-copy` (runs before build via `scripts/check-copy.mjs`; catches em/en dashes + banned vocab).
2. `pnpm typecheck` (`tsc --noEmit`).
3. `pnpm lint` (`eslint .`); workspace-wide `pnpm -w run lint`.
4. `pnpm build` (`check-copy && next build`).
5. `npx impeccable detect` on the changed header/menu surface (Impeccable gate).
6. Manual a11y pass: keyboard-only open/Tab-through/Escape-returns-focus, SR link rotor lists panel links, hover-intent + safe triangle, reduced-motion, mobile drawer focus trap, favicon shows in tab + iOS add-to-home + Android install prompt.

Redeploy (marketing only; traffic already on `wpmgr-marketing`, NO apex flip needed — see [[wpmgr-prod-deploy-sop]]): Cloud Build the marketing image, then `gcloud run deploy wpmgr-marketing --image <registry>/wpmgr-marketing:<tag> --region asia-south1` (image-only deploy preserves config). Verify the live tab favicon, `/manifest.webmanifest`, and the megamenu at wpmgr.app.

Definition of done also includes the docs/landing/CHANGELOG gate (see [[wpmgr-docs-changelog-sop]]) if this counts as a shipped feature.

---

## D. Brand guardrails

- NO em dashes, NO en dashes anywhere (copy, comments, JSX) — `check-copy` enforces.
- Vendor-neutral: never name a competitor plugin/source in shipped code or comments (see [[wpmgr-no-defensive-comments]]); no "not copied from X" disclaimers.
- Impeccable: reuse ONLY the existing token scale (`packages/tokens/globals.css`) — surface/border/muted-foreground/`--primary-subtle`/radius/shadow/duration/easing. NO glow (tokens are glow-free). Calm motion, opacity + transform only.
- Use the REAL FleetHub mark (dashboard-node + 4 satellites + spokes), never the invented ServerCog/W placeholder.
- Reuse existing primitives (`Icon`, `Logo`, `Button`, `Container`) and the header's exact focus-ring pattern; do not introduce a new dependency (`motion/react`, `react-dom` `createPortal` already available).
