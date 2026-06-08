/**
 * WPMgr WooCommerce cart-fragments compatibility shim.
 *
 * When our JS-delay transform is active, jQuery and WooCommerce's cart-fragments
 * script are deferred until user interaction or idle time. The native
 * cart-fragments script calls jQuery(function($){...}) (the document-ready
 * shorthand) and relies on jQuery.holdReady to gate its AJAX request. If jQuery
 * loads after the DOM-ready event has already fired, the ready callbacks still
 * run correctly because jQuery replays them — BUT the DOMContentLoaded and load
 * events that cart-fragments also listens to may have already fired and been
 * missed.
 *
 * This shim:
 *   1. Buffers DOMContentLoaded, load, and readystatechange events that fire
 *      before jQuery/cart-fragments load.
 *   2. When jQuery becomes available (after delayed-script swap), replays the
 *      events on the jQuery event bus so cart-fragments' handlers fire.
 *   3. Releases jQuery.holdReady so any pending ready queue drains.
 *
 * It is dependency-free (no jQuery), tiny, and is injected only when BOTH our
 * JS-delay is active AND woo_cacheable_session is ON (see WooFragmentsRuntime).
 *
 * No polling loops, no timers beyond a one-shot MutationObserver sentinel.
 * Safe-fallback: if anything fails the page degrades gracefully — the mini-cart
 * may show stale content until the next natural fragment refresh.
 */
(function () {
    'use strict';

    // Already injected (e.g. by a second Optimizer pass) — bail out.
    if (window._wpmgrWooFragsInit) {
        return;
    }
    window._wpmgrWooFragsInit = true;

    var buffered = [];
    var released = false;

    // Buffer events that fire before jQuery/fragments load.
    function capture(evt) {
        if (!released) {
            buffered.push(evt.type);
        }
    }

    document.addEventListener('DOMContentLoaded', capture, true);
    window.addEventListener('load', capture, true);
    document.addEventListener('readystatechange', capture, true);

    // When jQuery becomes available, replay buffered events and release holdReady.
    function release() {
        if (released) {
            return;
        }
        released = true;

        // Stop buffering new events.
        document.removeEventListener('DOMContentLoaded', capture, true);
        window.removeEventListener('load', capture, true);
        document.removeEventListener('readystatechange', capture, true);

        if (typeof window.jQuery !== 'function') {
            return;
        }

        var $ = window.jQuery;

        // Release any holdReady holds our own delay runtime may have placed.
        if (typeof $.holdReady === 'function') {
            try {
                $.holdReady(false);
            } catch (e) { /* ignored */ }
        }

        // Replay buffered events on the jQuery bus so fragment handlers fire.
        for (var i = 0; i < buffered.length; i++) {
            try {
                $(document).trigger(buffered[i]);
            } catch (e) { /* ignored — never break the page */ }
        }
        buffered = [];
    }

    // Sentinel: watch for jQuery to appear on window (set by the delay runtime
    // when it swaps the delayed jQuery script back in).
    if (typeof window.jQuery === 'function') {
        // jQuery already present (e.g. not actually delayed this request).
        release();
    } else {
        // Use a MutationObserver on <head>/<body> to detect script insertion, then
        // poll for jQuery exactly once per rAF until it appears. One-shot.
        var sentinel = null;
        function checkJQuery() {
            if (typeof window.jQuery === 'function') {
                if (sentinel) {
                    sentinel.disconnect();
                    sentinel = null;
                }
                release();
                return true;
            }
            return false;
        }

        if (!checkJQuery()) {
            sentinel = new MutationObserver(function () {
                if (checkJQuery()) {
                    sentinel.disconnect();
                    sentinel = null;
                }
            });
            var target = document.head || document.documentElement;
            if (target) {
                sentinel.observe(target, { childList: true, subtree: true });
            }
            // Hard fallback: if MutationObserver never fires (e.g. jQuery is already
            // in a script that finished before this ran), check once at load time.
            window.addEventListener('load', function () {
                checkJQuery();
            });
        }
    }
})();
