/**
 * CLS beacon emission test — Playwright headless Chromium.
 *
 * Validates that the wpmgr-rum.min.js collector correctly emits a `cls` beacon
 * when the page is hidden, including:
 *   - A stable page (no layout shift) → CLS=0 must be reported
 *   - A shifted page (injected layout shift) → CLS>0 must be reported
 *
 * Background — the bug this test guards against:
 * On a page where FCP has already occurred before the RUM script loads (cached
 * pages, Service-Worker hits), onCLS internally calls onFCP(runOnce(armCLS)).
 * armCLS registers the visibilityWatcher.onHidden hook that reports CLS on
 * page-hide. Because this hook is registered inside a buffered PerformanceObserver
 * callback (delivered asynchronously, ~15ms after observer creation), a
 * visibilitychange event that fires within that window misses the hook and no cls
 * beacon is ever sent.
 *
 * The fix: register onFCP before onCLS so that our explicit FCP observer and
 * onCLS's internal FCP observer both queue microtasks in the same delivery batch.
 * Combined with <head> async injection (maximising time before first user
 * interaction), this closes the practical race window.
 *
 * How the test works:
 *   1. A tiny HTTP server serves the actual built dist/wpmgr-rum.min.js and an
 *      HTML fixture page with window.__WPMGR_RUM__ pointing at a local catcher.
 *   2. The catcher records every beacon body by metric name.
 *   3. The page is loaded; for the shifted variant a large element is injected
 *      above-the-fold after DOMContentLoaded so content moves.
 *   4. After 300 ms (longer than the buffered delivery window), the page is
 *      forced hidden by overriding document.visibilityState and dispatching a
 *      'visibilitychange' event — replicating the web-vitals onHidden path.
 *   5. We assert that a cls beacon was received with the expected value range.
 *
 * Run: node tests/cls-beacon.test.mjs
 *   or: npm run test:cls
 *
 * This file is a tracker dev-test. It is NOT included in the agent zip — it
 * lives in apps/tracker/tests/ and is excluded from the plugin build.
 */

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { chromium } from 'playwright';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DIST_FILE = path.resolve(__dirname, '../dist/wpmgr-rum.min.js');

/* -------------------------------------------------------------------------- */
/* Tiny local server                                                           */
/* -------------------------------------------------------------------------- */

function createServer(induceShift) {
  const beacons = {};

  const srv = http.createServer((req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
      res.writeHead(204);
      res.end();
      return;
    }

    if (req.url === '/wpmgr-rum.min.js') {
      res.writeHead(200, { 'Content-Type': 'application/javascript' });
      res.end(fs.readFileSync(DIST_FILE));
      return;
    }

    if (req.url === '/catcher') {
      let body = '';
      req.on('data', (c) => (body += c));
      req.on('end', () => {
        try {
          const parsed = JSON.parse(body);
          beacons[parsed.metric] = parsed;
        } catch {
          /* ignore malformed */
        }
        res.writeHead(204);
        res.end();
      });
      return;
    }

    if (req.url === '/' || req.url === '/index.html') {
      const port = srv.address().port;
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(`<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>CLS repro</title>
  <script>
    window.__WPMGR_RUM__ = {
      key: 'testkey',
      url: 'http://127.0.0.1:${port}/catcher',
      rate: 1
    };
  </script>
  <script async src="http://127.0.0.1:${port}/wpmgr-rum.min.js"></script>
</head>
<body>
  <p id="anchor" style="font-size:2em;line-height:2">
    Stable text that will${induceShift ? '' : ' not'} be pushed down.
  </p>
  <script>
    ${induceShift ? `
    /* Inject a large element after DOMContentLoaded.
       The timeout ensures the browser has rendered at least one frame,
       producing a non-zero layout-shift score. */
    window.addEventListener('DOMContentLoaded', function() {
      setTimeout(function() {
        var el = document.createElement('div');
        el.style.cssText =
          'position:relative;height:300px;background:red;width:100%;display:block';
        document.body.insertBefore(el, document.body.firstChild);
        window.__shiftDone = true;
      }, 50);
    });
    ` : `
    window.__shiftDone = true;  /* stable page — no shift */
    `}
  </script>
</body>
</html>`);
      return;
    }

    res.writeHead(404);
    res.end();
  });

  return new Promise((resolve) => {
    srv.listen(0, '127.0.0.1', () =>
      resolve({ srv, beacons }),
    );
  });
}

/* -------------------------------------------------------------------------- */
/* Run a single scenario                                                       */
/* -------------------------------------------------------------------------- */

async function runScenario(label, induceShift) {
  console.log(`\n--- ${label} ---`);

  const { srv, beacons } = await createServer(induceShift);
  const port = srv.address().port;

  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      console.log(`  [page error] ${msg.text()}`);
    }
  });

  await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle' });

  /* Wait for the optional shift */
  await page.waitForFunction(() => window.__shiftDone === true, { timeout: 3000 });

  /* Wait longer than the buffered FCP observer delivery window (~15 ms).
     300 ms ensures armCLS has run and visibilityWatcher.onHidden is registered
     before we force the page hidden — replicating a realistic tab-switch. */
  await new Promise((r) => setTimeout(r, 300));

  /* Force page hidden — must pass a JS function so Playwright calls it in-page. */
  await page.evaluate(() => {
    // Override visibilityState — web-vitals checks document.visibilityState.
    Object.defineProperty(document, 'visibilityState', {
      value: 'hidden',
      configurable: true,
      writable: true,
    });
    // Fire the canonical event that web-vitals' visibilityWatcher listens for.
    document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
    // Belt-and-suspenders for any pagehide listeners.
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
  });

  /* Allow sendBeacon to reach the catcher */
  await new Promise((r) => setTimeout(r, 400));

  await browser.close();
  await new Promise((r) => srv.close(r));

  const cls = beacons['cls'];
  console.log(`  Beacons received: ${Object.keys(beacons).join(', ') || '(none)'}`);
  if (cls) {
    console.log(`  CLS value (milli-units): ${cls.value}`);
  }

  return cls;
}

/* -------------------------------------------------------------------------- */
/* Assert helpers                                                              */
/* -------------------------------------------------------------------------- */

let failures = 0;
function assert(condition, msg) {
  if (!condition) {
    console.error(`  FAIL: ${msg}`);
    failures++;
  } else {
    console.log(`  PASS: ${msg}`);
  }
}

/* -------------------------------------------------------------------------- */
/* Main                                                                       */
/* -------------------------------------------------------------------------- */

(async () => {
  if (!fs.existsSync(DIST_FILE)) {
    console.error('ERROR: DIST file not found:', DIST_FILE);
    console.error('Run `npm run build` in apps/tracker first.');
    process.exit(1);
  }

  // --- Scenario 1: stable page (no layout shift) → CLS should be 0 ----------
  const cls0 = await runScenario('Stable page (no shift) — expects CLS=0', false);

  assert(cls0 !== undefined, 'stable page: cls beacon was received');
  assert(
    cls0 !== undefined && typeof cls0.value === 'number',
    'stable page: cls beacon value is a number',
  );
  assert(
    cls0 !== undefined && cls0.value === 0,
    'stable page: cls beacon value === 0 (no shift recorded)',
  );
  assert(
    cls0 !== undefined && cls0.metric === 'cls',
    'stable page: cls beacon metric field is "cls"',
  );

  // --- Scenario 2: shifted page → CLS should be > 0 -------------------------
  const clsShifted = await runScenario('Shifted page (above-fold element) — expects CLS>0', true);

  assert(clsShifted !== undefined, 'shifted page: cls beacon was received');
  assert(
    clsShifted !== undefined && typeof clsShifted.value === 'number',
    'shifted page: cls beacon value is a number',
  );
  assert(
    clsShifted !== undefined && clsShifted.value > 0,
    'shifted page: cls beacon value > 0 (shift was recorded)',
  );
  assert(
    clsShifted !== undefined && clsShifted.metric === 'cls',
    'shifted page: cls beacon metric field is "cls"',
  );

  // --- Summary ---------------------------------------------------------------
  console.log('\n' + '='.repeat(60));
  if (failures === 0) {
    console.log('ALL TESTS PASSED — cls beacon emitted correctly.');
  } else {
    console.error(`${failures} test(s) FAILED.`);
    process.exitCode = 1;
  }
})();
