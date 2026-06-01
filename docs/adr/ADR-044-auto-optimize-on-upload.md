# ADR-044 — Automatic image optimization on upload

**Status:** Proposed · **Date:** 2026-06-01
**Relates:** ADR-043 (Media Optimizer architecture, encode topology & transport), ADR-031 (CP→agent signed commands), ADR-013 (CP↔agent event callbacks).

## Context

The Media Optimizer (ADR-043) optimizes a site's existing library on demand: the
operator clicks **Optimize** in the dashboard, the control plane (CP) creates
jobs and dispatches a signed `media_optimize` command, the agent uploads source
variants, the `media-encoder` service encodes (JPEG/PNG/GIF → WebP/AVIF), and the
agent applies the results on disk.

Operators also want **new uploads optimized automatically** — when an editor adds
a JPEG/PNG/GIF to the WordPress media library, it should be optimized without a
manual click, if the site has opted in. The agent is already installed on every
managed site, so it can observe uploads directly.

The challenge: the existing pipeline is **CP-initiated** (dashboard → CP →
agent). An upload happens **WP-side**, so the trigger is inverted — the agent
must initiate. The design must reuse the existing job/encode/apply pipeline
verbatim (jobs, rate-limit, audit, RLS, SSE), must never block an upload
request, must survive bulk uploads, and must never loop on its own output.

This feature is per-site **opt-in** and off by default.

## Decisions

### 1. Trigger: the `wp_generate_attachment_metadata` filter

The agent hooks `wp_generate_attachment_metadata( $metadata, $attachment_id, $context )`
— the WordPress filter that runs **after** core has generated every registered
sub-size and assembled `_wp_attachment_metadata`. This is the correct trigger
because, by then, all variants exist (`full` + `$metadata['sizes']`), matching
the set the optimize flow enumerates.

Two non-negotiable rules:

- **It is a filter, not an action — the callback MUST return `$metadata`
  unchanged.** We hook to *observe and schedule*, never to mutate or to do work
  inline. Returning early/void corrupts the attachment metadata for every
  downstream consumer.
- **Act only on `$context === 'create'`** (a fresh upload), never `'update'`
  (thumbnail regeneration). This, plus the re-entrancy guard below, is the
  primary defense against the self-trigger loop (§6).

Hook at a late priority (`9999`) so any resize/regenerate plugins finish first.

Rejected: `add_attachment` (sub-sizes may not exist yet) and `wp_handle_upload`
(no attachment id, no sizes).

### 2. Non-blocking + bulk debounce via the existing async engine

An upload request (`async-upload.php`, REST, or programmatic) must return fast.
The filter does **zero** network/encode work. It only: (a) MIME-gates the
attachment (cheap), (b) appends the id to a small pending buffer, (c) schedules a
single debounced background drain.

WordPress posts each dropped file as its **own** request, so 50 files = 50 filter
invocations. Two-layer coalescing prevents 50 round-trips:

- **Append-only pending buffer** (a transient/option holding the id set) — the
  filter buffers, it does not dispatch per file.
- **One debounced, arg-less scheduled drain** — `wp_schedule_single_event(time()
  + DEBOUNCE, HOOK)` with a fixed hook so WP's built-in dedupe collapses repeated
  schedules into one pending event (DEBOUNCE ≈ 20–30s). The drain reads the whole
  buffer, dedupes, batches the ids into one agent→CP call, and clears the buffer.

The drain runs in a separate FPM cron worker under `set_time_limit(0)` +
`ignore_user_abort(true)`, reusing `MediaRunStore` (the same engine behind
`media_optimize`/`media_restore`/`media_delete_originals`). The upload ACKs in
milliseconds.

### 3. Trigger inversion: a new agent→CP enqueue endpoint, then reuse `StartOptimize`

The agent notifies the CP of new uploads; the CP runs the **unchanged** optimize
pipeline. This keeps the CP the single authority for job creation, rate-limit,
audit, RLS, and dispatch — and keeps the encode pipeline (which is keyed on a
CP-minted job id) intact. Having the agent self-mint presigns would fork the
pipeline and bypass the `media_optimization_jobs` rows the dashboard/SSE/
idempotency depend on.

There is an exact precedent: the `delete_attachment` hook ships a signed,
fixed-path callback (`/agent/v1/media/asset-deleted`) via the agent's own signer.
Auto-optimize is the symmetric "attachment created/ready" event.

**Agent → CP:**
```
POST {cp_base}/agent/v1/media/auto-optimize
Auth:  the agent's Ed25519 request signature (identical to /media/asset-deleted)
Body:  { "wp_attachment_ids": [<int>, ...] }   // the debounced, deduped set
Resp:  200 { "accepted": <int>, "skipped": <int> }
```
Sent over the fixed path via the agent's `shipPayload` primitive (the same one
diagnostics/errors/asset-deleted use), NOT the in-flight-command uploader.
Tenant + site come from the **verified Ed25519 identity** on the CP, never a
client header.

**CP handler (`HandleAutoOptimize`)** — for each id: resolve (or minimally
upsert) the `site_media_assets` row; gate on `media.IsOptimizableMime`;
idempotency-gate (skip `optimized`/`optimizing`/`originals_deleted` or an
in-flight optimize job); read the site's auto-optimize config; then call the
existing `StartOptimize(..., format, quality, agentSystemPrincipal)`. Everything
downstream is unchanged. The only new server code is this thin handler + route.

### 4. Settings: CP-stored, pushed to the agent

The per-site toggle and defaults live on the CP (authoritative, survives agent
reinstall, dashboard-driven) and are pushed to the agent — mirroring the existing
`site_security_config` pattern.

- **Storage:** a new `site_media_settings` table (PK `site_id`, `tenant_id` FK,
  tenant + agent RLS policies like `site_security_config`):
  `auto_optimize_enabled bool`, `auto_target_format text` (avif|webp|original),
  `auto_target_quality text` (lossy|lossless).
- **Save + push:** an operator `PUT /api/v1/sites/{id}/media/settings` validates
  (`ValidTargetFormat`/`ValidTargetQuality`), upserts, and best-effort dispatches
  a new `sync_media_config` CP→agent command (mirror of `sync_security_config`).
- **Receive (agent):** a new `SyncMediaConfigCommand` writes the values to typed
  wp-options. The upload filter reads the local enable flag to decide whether to
  buffer at all; the **actual format/quality for the encode is re-read CP-side**
  in `HandleAutoOptimize`, so a stale agent option can never select an invalid
  format.

### 5. MIME gate (reuse, do not reinvent)

Only `image/jpeg`, `image/jpg`, `image/png`, `image/gif` trigger auto-optimize.
The agent filter gates on the shared `OPTIMIZABLE_MIMES` constant; the CP
re-gates on `media.IsOptimizableMime` (`domain.go`); `StartOptimize`→
`resolveAssets` skips non-optimizable ids. WebP/AVIF/SVG/HEIC are ignored — they
appear under the **Unsupported** count, never queued.

### 6. The self-trigger loop (must-fix invariant)

When `media_apply` writes optimized bytes it updates `_wp_attachment_metadata`.
If that update ever re-ran `wp_generate_attachment_metadata`, the filter would
re-fire and re-enqueue the same attachment → an infinite optimize loop. Four
stacked mitigations, **all applied**:

1. Apply uses the metadata **setter** (`wp_update_attachment_metadata`), NOT the
   **generation** path the filter hooks — the load-bearing invariant. Code review
   MUST confirm `applyOptimizedMetadata` never invokes the regenerate path.
2. A request-scoped **re-entrancy guard** set during any apply/restore/delete that
   the filter checks and bails on.
3. The **idempotency gate** (status/blob) makes any stray re-fire a no-op.
4. **`$context !== 'create'`** excludes the regenerate/update class entirely.

### 7. Guardrails

- **Rate-limit:** the auto path flows through `StartOptimize`'s per-site/tenant
  limiter for free; the debounced batch counts as one request. On rejection the
  agent keeps the ids buffered and retries.
- **Offline / CP unreachable:** `shipPayload` is best-effort; on non-2xx the agent
  **keeps** the ids buffered (does not clear) and reschedules with backoff. The
  periodic `media_sync` lands the asset rows; the operator's bulk "optimize
  pending" is the ultimate backstop.
- **Import storms:** the debounce window coalesces bursts; additionally skip
  auto-optimize while a `media_sync`/import is in progress and rely on the
  post-import "optimize pending" path.

## Consequences

- New surface is small: one agent hook + drain, one CP route + handler, one
  settings table + push command, one dashboard panel. The encode pipeline is
  untouched.
- The feature is opt-in and off by default; existing behavior is unchanged when
  disabled.
- The loop invariant (§6) is the single most important code-review item.

## Phased implementation

- **Phase A — Agent:** the `wp_generate_attachment_metadata` hook (gate +
  buffer + debounced schedule, always return `$metadata`); the `MediaRunStore`
  drain that POSTs the batched `auto-optimize` body via `shipPayload` (keep ids on
  failure); the `SyncMediaConfigCommand` + new typed option accessors.
- **Phase B — CP:** the `POST /agent/v1/media/auto-optimize` route + `HandleAutoOptimize`
  (resolve/upsert, MIME-gate, idempotency-gate, read settings, `StartOptimize`);
  the `site_media_settings` migration + repo; the operator `GET/PUT
  /media/settings` endpoints; the `sync_media_config` CP→agent command.
- **Phase C — Web:** an "Automatic optimization" panel on the Media tab — a toggle
  plus format/quality selects (reusing the optimize-dialog option components),
  wired to the settings endpoints. Copy stays generic ("auto-optimize new
  uploads").
