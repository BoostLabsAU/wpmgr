/**
 * WPMgr deferred-script runtime — human-readable source.
 *
 * This is the unminified source for assets/wpmgr-delay.min.js. The minified
 * file is produced by running the build command documented below.
 *
 * Build:
 *   cd apps/agent/assets
 *   npx terser wpmgr-delay.js --compress --mangle --output wpmgr-delay.min.js
 *   # Or equivalently from the repo root:
 *   cd apps/tracker && npm run build:delay
 *
 * What it does:
 *   On the first user interaction (mousemove, keydown, touchstart, wheel, or
 *   scroll) — or after a 3–5 second idle timeout via requestIdleCallback —
 *   the runtime swaps deferred links and scripts back to their real src/href
 *   attributes. The JS-delay optimizer renames href -> data-wpmgr-href and
 *   src -> data-wpmgr-src at cache-write time; this runtime restores them
 *   post-interaction so the assets load only when the visitor is active.
 *
 * License: GPLv2 or later (https://www.gnu.org/licenses/gpl-2.0.html)
 * Copyright: WPMgr contributors
 */
(function () {
    var d = document;
    var fired = false;

    /**
     * Swap all delayed links and scripts back to their real attributes.
     * Idempotent: the `fired` flag prevents double-execution.
     */
    function swap() {
        if (fired) {
            return;
        }
        fired = true;

        // Restore deferred <link rel="stylesheet" data-wpmgr-href="..."> elements.
        var links = d.querySelectorAll('link[data-wpmgr-href]');
        for (var i = 0; i < links.length; i++) {
            var l = links[i];
            l.setAttribute('href', l.getAttribute('data-wpmgr-href'));
            l.removeAttribute('data-wpmgr-href');
        }

        // Restore deferred <script data-wpmgr-src="..."> elements by replacing
        // each placeholder with a fresh <script> node so the browser fetches and
        // executes the script normally.
        var olds = d.querySelectorAll('script[data-wpmgr-src],script[data-wpmgr-method]');
        for (var j = 0; j < olds.length; j++) {
            (function (o) {
                var s = d.createElement('script');
                var src = o.getAttribute('data-wpmgr-src');

                // Copy all attributes except the placeholder ones and 'type'
                // (which on the deferred node may be a non-executable MIME type
                // used to prevent premature execution by the browser).
                for (var k = 0; k < o.attributes.length; k++) {
                    var a = o.attributes[k];
                    if (a.name === 'data-wpmgr-src'
                        || a.name === 'data-wpmgr-method'
                        || a.name === 'type'
                    ) {
                        continue;
                    }
                    s.setAttribute(a.name, a.value);
                }

                if (src) {
                    // External script.
                    s.src = src;
                } else {
                    // Inline script: copy the text content.
                    s.text = o.textContent || '';
                }

                o.parentNode.replaceChild(s, o);
            })(olds[j]);
        }

        cleanup();
    }

    /**
     * Remove all event listeners that were added to trigger the swap.
     */
    function cleanup() {
        EV.forEach(function (e) {
            window.removeEventListener(e, swap, OPT);
        });
    }

    // Events that signal the visitor is active and ready for non-critical assets.
    var EV = ['mousemove', 'keydown', 'touchstart', 'wheel', 'scroll'];
    var OPT = { passive: true };

    EV.forEach(function (e) {
        window.addEventListener(e, swap, OPT);
    });

    // Fallback: swap after a short idle period even without user interaction,
    // so assets load on passive/read-only sessions (e.g. the user scrolled past
    // the fold without moving the mouse on desktop).
    if ('requestIdleCallback' in window) {
        requestIdleCallback(
            function () {
                setTimeout(swap, 3000);
            },
            { timeout: 6000 }
        );
    } else {
        setTimeout(swap, 5000);
    }
})();
