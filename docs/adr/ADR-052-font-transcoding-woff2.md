# ADR-052 — Font transcoding to WOFF2

Status: Accepted (2026-06-08)

## Context

WPMgr self-hosts Google Fonts and other web fonts by downloading and serving
them directly from the site's storage. Until this release, fonts were served in
whatever format was originally downloaded (TTF, OTF, or WOFF). WOFF2 is the
modern compressed web-font format, supported by every browser released since
2016, and it is substantially smaller: 50 to 65 percent smaller for TTF and OTF
files, and 20 to 30 percent smaller for already-compressed WOFF files. A
reference implementation does not transcode self-hosted fonts at all, leaving
them in their original format. Transcoding is therefore a clear differentiator
that improves page weight without any manual work from the site owner.

The transcoding workload belongs in the media-encoder service: it already runs
off the critical path for image re-encoding, it is the only component that
handles binary asset transformation, and keeping font work there avoids pulling
a CPU-heavy workload into the control plane or the PHP agent.

## Decision

When the per-site `fonts_transcode_woff2` setting is enabled (off by default),
the media-encoder worker transcodes every self-hosted font it has not already
processed:

**Transcoding library.** Use `github.com/tdewolff/font`, a pure-Go font parser
and WOFF2 encoder that requires no CGO. Brotli compression (required by WOFF2)
is provided by `github.com/andybalholm/brotli`, also pure Go. If the pure-Go
path is ever insufficient for a specific font variant, `google/woff2
woff2_compress` is documented as a fallback build option, but it is not the
default.

**Content addressing.** Each source font is identified by its BLAKE3 hash
(consistent with the media-optimizer pipeline). The object-storage key is
server-derived and tenant-scoped. The agent never supplies or presigns a storage
key.

**Serving.** The agent serves the WOFF2 variant when available and includes the
original format in a CSS format() fallback declaration. Until the WOFF2 is
ready, the original font is served unchanged. Any transcoding failure leaves the
original serving; a font never renders broken.

**Security model.** All object-storage keys are server-derived and
tenant-scoped; the agent never supplies or presigns a key. The agent-supplied
BLAKE3 hash is validated to 64-character hex before any storage lookup. The
encoder enforces an input size cap, a 64 MiB decoded-output ceiling, and panic
recovery around the transcoding call to resist malformed fonts and
decompression-bomb inputs.

**Scope.** Font subsetting is explicitly deferred to a later phase. Subsetting
will never be applied to icon fonts or variable fonts, where it is unsafe.

## Consequences

- Deploy the media-encoder image before enabling the feature on any site. The
  control plane and agent can ship in the same release; the encoder must be
  updated first or transcoding jobs will enqueue and never process.
- Sites with no self-hosted fonts are unaffected: the setting is opt-in and the
  encoder skips sites where there is nothing to transcode.
- No new dependencies in the control plane or agent. Two pure-Go libraries are
  added to the media-encoder module only.
- Page load time cannot regress: the original font is always the live fallback
  while transcoding is in progress or if it fails.
