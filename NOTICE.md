# NOTICE — WPMgr

WPMgr's control plane is AGPL-3.0; the WordPress agent is MIT-licensed. See the
repository `LICENSE` files. This file records third-party attribution and the
provenance of techniques used in the Performance Suite. The agent also carries its
own attribution at [`apps/agent/NOTICE.md`](apps/agent/NOTICE.md).

## Performance Suite

### Page caching and database cleanup (technique)

WPMgr's page cache uses the **standard WordPress disk-cache pattern**: a
`WP_CACHE` advanced-cache drop-in serves pre-gzipped HTML from disk on a hit, with
an `.htaccess` / nginx fast-path and a managed-block marker convention. This is
the same widely-used, well-understood mechanism implemented by
**WP Super Cache** and **Cache Enabler** (GPLv2). The drop-in, the cacheability
exclusion set, the disk purge, and the preloader follow that standard technique.

**Minification and caching follow standard, widely-used WordPress techniques.**
WPMgr's implementation is original code under WPMgr's own naming and
architecture; **no third-party plugin source code is included or copied.**

### matthiasmullie/minify (MIT)

CSS and JS minification uses
[`matthiasmullie/minify`](https://github.com/matthiasmullie/minify) (^1.3, MIT), a
small pure-PHP library bundled with the agent's Composer dependencies.

### Remove Unused CSS (RUCSS) — original Go implementation

WPMgr's RUCSS engine is an **original WPMgr implementation written in pure Go** on
the control plane. There is no headless browser and no third-party RUCSS service.
It is built on these open-source Go libraries:

- [`golang.org/x/net/html`](https://pkg.go.dev/golang.org/x/net/html) (BSD-3-Clause)
  — parse the page HTML into a DOM.
- [`github.com/tdewolff/parse`](https://github.com/tdewolff/parse) (MIT) — tokenize
  each stylesheet.
- [`github.com/andybalholm/cascadia`](https://github.com/andybalholm/cascadia)
  (BSD-2-Clause) — compile CSS selectors and test them against the parsed DOM.

The selector-matching logic, the always-keep set for runtime-state pseudo-classes
and pseudo-elements, the at-rule liveness rules, the structure-hash caching, and
the graceful keep-all degradation are all original WPMgr code.

## Media Optimizer

The Media Optimizer's attribution (image-optimization orchestration patterns as
inspiration only, and Discord's `lilliput` MIT for encoding) is recorded in
[`apps/agent/NOTICE.md`](apps/agent/NOTICE.md). No third-party plugin source is
included or copied.
