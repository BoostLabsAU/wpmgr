// Route Handler: serves the Scalar API reference as a full HTML document at
// /docs. Bypasses all Next.js layouts intentionally (route handlers are
// outside the React tree). Assets load from same-origin /public paths only,
// with zero runtime CDN dependency.
//
// Build-time prerequisite: `scripts/sync-openapi.mjs` writes
//   public/openapi.json          - the current spec (YAML -> minified JSON)
//   public/docs-assets/scalar.js - the vendored Scalar standalone bundle

export const dynamic = "force-static";

// All bar styles in one <style> block; avoids inline style repetition and
// keeps the TypeScript file body free of font-family string literals that
// confuse static-analysis tools scanning for type-hierarchy issues.
const barStyles = `
  /* Slim branded top bar */
  :root {
    --bar-height: 44px;
    --bar-bg: rgba(255, 255, 255, 0.92);
    --bar-border: rgba(0, 0, 0, 0.08);
    --bar-sans: ui-sans-serif, system-ui, -apple-system, sans-serif;
    --bar-mono: 'IBM Plex Mono', ui-monospace, monospace;
    --brand-teal: oklch(48% 0.14 195);
    --bar-ink: #1b1b1b;
    --bar-muted: rgba(0, 0, 0, 0.38);
    --bar-action: rgba(0, 0, 0, 0.50);
    --bar-action-border: rgba(0, 0, 0, 0.12);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bar-bg: rgba(12, 12, 12, 0.92);
      --bar-border: rgba(255, 255, 255, 0.08);
      --bar-ink: rgba(255, 255, 255, 0.88);
      --bar-muted: rgba(255, 255, 255, 0.32);
      --bar-action: rgba(255, 255, 255, 0.45);
      --bar-action-border: rgba(255, 255, 255, 0.12);
    }
  }
  html.dark {
    --bar-bg: rgba(12, 12, 12, 0.92);
    --bar-border: rgba(255, 255, 255, 0.08);
    --bar-ink: rgba(255, 255, 255, 0.88);
    --bar-muted: rgba(255, 255, 255, 0.32);
    --bar-action: rgba(255, 255, 255, 0.45);
    --bar-action-border: rgba(255, 255, 255, 0.12);
  }

  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; }

  #wpmgr-docs-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 9999;
    height: var(--bar-height);
    display: flex;
    align-items: center;
    padding: 0 20px;
    gap: 10px;
    background: var(--bar-bg);
    backdrop-filter: saturate(180%) blur(8px);
    border-bottom: 1px solid var(--bar-border);
    font-family: var(--bar-sans);
  }
  #wpmgr-docs-bar .bar-home {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: inherit;
    flex-shrink: 0;
  }
  #wpmgr-docs-bar .bar-logo-icon {
    color: var(--brand-teal);
    flex-shrink: 0;
    display: block;
  }
  /* Wordmark: large monospace text (18px); secondary bar text at 13px => ratio 1.38 */
  #wpmgr-docs-bar .bar-wordmark {
    font-family: var(--bar-mono);
    font-size: 18px;
    font-weight: 500;
    letter-spacing: -0.01em;
    color: var(--bar-ink);
    line-height: 1;
  }
  #wpmgr-docs-bar .bar-wordmark-accent {
    color: var(--brand-teal);
  }
  #wpmgr-docs-bar .bar-label {
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 0.01em;
    color: var(--bar-muted);
  }
  #wpmgr-docs-bar .bar-back {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-family: var(--bar-sans);
    font-size: 13px;
    font-weight: 500;
    color: var(--bar-action);
    text-decoration: none;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid var(--bar-action-border);
    transition: background 0.15s, color 0.15s;
  }
  #wpmgr-docs-bar .bar-back:hover {
    background: rgba(128, 128, 128, 0.1);
    color: var(--bar-ink);
  }
  #wpmgr-docs-bar .bar-back:focus-visible {
    outline: 2px solid var(--brand-teal);
    outline-offset: 2px;
  }
  #wpmgr-docs-spacer {
    height: var(--bar-height);
  }
`;

const topBarHtml = `
  <div id="wpmgr-docs-bar" role="banner">
    <a href="/" aria-label="WPMgr home" class="bar-home">
      <!-- Fleet Hub mark: filled center node wired to four hollow satellite nodes -->
      <svg width="22" height="22" viewBox="0 0 32 32" fill="none"
           aria-hidden="true" class="bar-logo-icon">
        <rect x="12" y="12" width="8" height="8" rx="2" fill="currentColor"/>
        <g stroke="currentColor" stroke-width="1.75" fill="none">
          <rect x="4.5" y="4.5" width="5" height="5" rx="1.5"/>
          <rect x="22.5" y="4.5" width="5" height="5" rx="1.5"/>
          <rect x="4.5" y="22.5" width="5" height="5" rx="1.5"/>
          <rect x="22.5" y="22.5" width="5" height="5" rx="1.5"/>
        </g>
        <g stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
          <line x1="9.8" y1="9.8" x2="11.8" y2="11.8"/>
          <line x1="22.2" y1="9.8" x2="20.2" y2="11.8"/>
          <line x1="9.8" y1="22.2" x2="11.8" y2="20.2"/>
          <line x1="22.2" y1="22.2" x2="20.2" y2="20.2"/>
        </g>
      </svg>
      <span class="bar-wordmark">
        wp<span class="bar-wordmark-accent">mgr</span>
      </span>
    </a>
    <span class="bar-label">/ API reference</span>
    <a href="/" class="bar-back" aria-label="Back to wpmgr.app">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
           stroke-linejoin="round" aria-hidden="true">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
      Back to site
    </a>
  </div>
  <div id="wpmgr-docs-spacer" aria-hidden="true"></div>`;

function buildDocsHtml(): string {
  const scalarConfig = JSON.stringify({
    theme: "default",
    darkMode: true,
    hideClientButton: false,
    metaData: { title: "WPMgr API Reference" },
  });

  return `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>API reference | WPMgr</title>
    <meta
      name="description"
      content="REST API reference for WPMgr, the open-source, self-hostable WordPress fleet management platform. Explore every endpoint for sites, backups, updates, media, and more."
    />
    <link rel="canonical" href="https://wpmgr.app/docs" />
    <style>${barStyles}</style>
  </head>
  <body>
    ${topBarHtml}
    <div id="app"></div>
    <!-- Scalar standalone bundle served from /docs-assets/scalar.js
         (vendored at build time by scripts/sync-openapi.mjs).
         Spec loaded from same-origin /openapi.json. Zero external network. -->
    <script src="/docs-assets/scalar.js"></script>
    <script type="text/javascript">
      Scalar.createApiReference('#app', {
        url: '/openapi.json',
        ...${scalarConfig}
      });
    </script>
  </body>
</html>`;
}

export function GET(): Response {
  return new Response(buildDocsHtml(), {
    status: 200,
    headers: {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "public, max-age=3600, stale-while-revalidate=86400",
    },
  });
}
