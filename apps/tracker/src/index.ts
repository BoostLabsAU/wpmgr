/**
 * @wpmgr/tracker entry point.
 *
 * Immediately calls init() so the IIFE bundle self-bootstraps on load.
 * The script is injected as an external <script defer src="..."> tag, so
 * execution is already deferred past HTML parsing — no further deferral needed.
 */

import { init } from './vitals.js';

init();
