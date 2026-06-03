# NOTICE — WPMgr WordPress agent

The WPMgr agent is MIT-licensed (see the repository `LICENSE`).

## Third-party attribution

### Image-optimization orchestration patterns (inspiration only)

The Media Optimizer's image-optimization **orchestration patterns** are inspired
by conventions observed in leading image-optimization plugins. Specifically:

- the **original-file rename pattern** (archiving a re-compressed original to a
  double-extension name and reversing it on restore),
- the **postmeta blob shape** (a per-attachment record holding optimization
  status, per-size optimized/unoptimized maps, and a verbatim pre-optimization
  `_wp_attachment_metadata` snapshot as the restore anchor), and
- the **Accept-header `.htaccess` fallback** (serving a legacy twin when the
  client does not advertise AVIF/WebP support and the twin exists on disk).

**No third-party plugin source code is included or copied** into WPMgr. These are
re-implementations of the *patterns* under WPMgr's own naming and architecture.

The actual image optimization runs on **WPMgr's own Go control plane** using
Discord's **`lilliput`** (MIT) — not a third-party or managed optimization API.

### lilliput (Discord)

`github.com/discord/lilliput` is MIT-licensed and used by the control-plane
`media-encoder` service for image decoding/encoding. It is not bundled with this
agent plugin (the agent performs no encoding).

## Performance Suite (caching + optimization)

### Page caching and database cleanup (technique)

The agent's page cache uses the **standard WordPress disk-cache pattern** — a
`WP_CACHE` advanced-cache drop-in serving pre-gzipped HTML from disk, an
`.htaccess` / nginx fast-path, the managed-block marker convention, the
cacheability exclusion set, the disk purge, and the preloader. This is the same
widely-used technique implemented by **WP Super Cache** and **Cache Enabler**
(GPLv2). Minification and caching follow standard, widely-used WordPress
techniques; the implementation is original WPMgr code and **no third-party plugin
source is included or copied.**

### matthiasmullie/minify (MIT)

CSS and JS minification uses
[`matthiasmullie/minify`](https://github.com/matthiasmullie/minify) (^1.3, MIT), a
small pure-PHP library in the agent's Composer dependencies.

### Remove Unused CSS (RUCSS)

RUCSS is computed by an **original WPMgr pure-Go engine** on the control plane (no
headless browser, no third-party service). The agent only POSTs the rendered page
to the control plane and inlines the result, degrading to full CSS on any
failure. See the repository-root [`NOTICE.md`](../../NOTICE.md).
