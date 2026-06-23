# File Manager for WPMgr — Insights + Caveats (Decision-Grade Research)

**Status:** Research synthesis, pre-build. **Date:** 2026-06-22.
**Author:** research workflow (reference-plugin teardown + integration architecture + library survey + security threat model).
**Recommendation:** Build **Option A** — granular signed `file_*` agent commands + a thin CP orchestrator + a custom React browser on the existing `media/` primitives. Ship **read-only first, off-by-default per site, owner/admin-only, fully audited, jailed paths**.

**Clean-room rule (binding):** This document lives in the repo, so it is vendor-neutral. The studied prior art is referred to only as *"the leading open-source WordPress file-manager plugin we studied (reference kept outside the repo)."* It is **not named**, and **no code is copied** from it — especially not its bundled elFinder connector or `LocalFileSystem` driver. We may freely name generic open-source libraries (elFinder, filebrowser, afero, pkg/sftp, Uppy, mholt/archives, etc.) and the public CVE id (CVE-2020-25213).

---

## 1. Feature overview and user value

**What it is.** An operator-facing remote file manager that lets a WPMgr dashboard user browse, preview, edit, upload, download, and delete files on a managed WordPress site — without SFTP credentials, without a cPanel login, and without installing anything beyond the existing WPMgr agent. The WordPress filesystem lives only on the PHP agent (which runs as `www-data` or the site owner). The Go control plane (CP) orchestrates and authorizes; the React web renders.

**Why it matters.** "Edit a file on a managed site" is a top-tier expectation for a WordPress fleet-management product. Today an operator who needs to drop a `must-use` plugin, fix a broken `functions.php`, inspect an `.htaccess`, or pull a log has to leave WPMgr entirely. A native file manager closes that gap and keeps every mutation inside WPMgr's signed-command channel, RLS tenant isolation, and tamper-evident audit log — turning an out-of-band, unaudited SFTP action into a first-class, attributable operation.

**Where it sits next to existing features.** WPMgr already handles files at scale for **backup/restore**: the agent streams large archives agent↔S3 via CP-minted presigned URLs (`BackupTransport`), reassembles with atomic staging/swap (`FilesRestorer`), and hardens write bases (`StoragePaths`). The file manager is the **interactive, single-file sibling** of that batch machinery — same transport, same containment guard, same staging primitives, but driven by ad-hoc operator intent instead of a scheduled job. It sits alongside the per-site tools (media cleaner, DB snapshots, search-replace), all of which already follow the exact shape we propose: a sync `CommandInterface` on the agent, issued by `agentcmd.Client`, behind a gated handler, recorded in the audit chain.

---

## 2. How the reference approach works (and its lessons) — vendor-neutral

The leading open-source WordPress file-manager plugin we studied (reference kept outside the repo) is a thin WordPress wrapper around the upstream **elFinder 2.x** JavaScript library (vendored whole). At a high level:

- **Frontend ↔ connector.** elFinder's JS browser mounts in an admin page and POSTs commands (`open`, `ls`, `tree`, `upload`, `rm`, `mkdir`, `rename`, `paste`, `get`/`put`, `archive`, `extract`, `zipdl`, …) to a PHP **connector**. Crucially, the connector is *not* a standalone public `connector.php` in the current build — it runs **inside an `admin-ajax.php` action**, gated by a capability + nonce check *before* any elFinder command executes.
- **Root / path model.** The default browse root is the **entire WordPress install (`ABSPATH`)**. A "public root path" preference can narrow it, but the path computation is a naive blacklist strip that always re-prefixes `ABSPATH`. Traversal *within* the volume is genuinely jailed by elFinder itself: opaque hash addressing (clients never send raw paths) plus a `realpath()`-must-start-with-root invariant on every resolution. Archive extraction goes through a quarantine dir, rejects archived symlinks, and enforces a max-uncompressed-size — a solid zip-slip/zip-bomb defense **that lives in the library, not the wrapper**.
- **AuthZ model.** A **single capability gate** (WordPress admin) plus a nonce. No per-role or per-operation restrictions in the free build (those are non-functional upsell stubs). Effective model: *any admin can do anything to the whole WP filesystem.*

### The lessons (especially the RCE history)

**CVE-2020-25213** ("unrestricted file upload → RCE", that plugin family, Sept 2020) was the canonical failure. Two root causes combined:

1. **A connector entry reachable with weak/no WordPress auth** (the old build let the elFinder dispatch be hit outside the capability check), and
2. **An upload allow/deny config that defaulted to *allow***. The wrapper set `uploadDeny => array()` (empty) with order `deny,allow`, which makes the mime/extension gate **always return true** — so `.php` uploads passed. Attackers POSTed `cmd=upload`, wrote a webshell into a web-served directory, and executed it as `www-data`.

The current build closes the *unauthenticated* path (the connector now sits behind the capability + nonce gate). But three load-bearing weaknesses **remain by design** and are exactly what a clean-room re-implementation must not inherit:

- **The upload mime gate is still a no-op** (`uploadDeny => array()`), so an authenticated admin still has trivial RCE, and *any future auth bypass instantly re-opens full RCE* because the second line of defense is inert. **Lesson: never rely solely on the auth gate — keep a real server-side executable deny-list so an auth bug degrades to file-write, not code-exec.**
- **Default root = `ABSPATH`** means `wp-config.php` (DB creds, salts) is fully readable/downloadable by anyone who passes the gate; the `attributes` deny-list only hides `.tmb`/quarantine, not secrets. **Lesson: default to a narrow root; deny-list config/secret files explicitly.**
- **Registration-time-only capability checks and `permission_callback => __return_true` on file-download REST routes.** The riskiest construct in the plugin — `__return_true` permission callbacks on file routes are exactly the shape that produces disclosure bugs. **Lesson: gate every request, never just route registration; never `__return_true` on a file route.**
- **Dead filename validator** (the configured `validName` is a JS-only option; the PHP path falls through to `return true`). **Lesson: validate name *and* content, server-side, every time.**

These four lessons drive the entire WPMgr security posture in §5.

---

## 3. Recommended WPMgr architecture

### The load-bearing finding: WPMgr is ~70% of the way there already

The agent ships a **hardened, REST-signed file primitive** and the CP ships a **turnkey command-issuance path**. We extend, we do not reinvent:

- **Agent already has `get_file`** → `FileScanner::getFileContent()` (`apps/agent/includes/support/class-file-scanner.php:284`): leading-slash strip, per-segment `.`/`..` rejection, NUL rejection, `lstat`, symlink guard, dir guard, **`realpath()` + `strncmp` containment against root** (`OUTSIDE_ROOT`), size cap, base64 body. **This is exactly the file-read primitive a file manager needs, and the jail is already correct.**
- **Agent already has `scan`** → `FileScanner::scan()`: a resumable DFS directory walker with cursor, time budget, and batch caps. A directory *listing* is a one-level, non-recursive projection of this same walker.
- **CP command transport is one seam.** `agentcmd.Client.Do(ctx, siteID, siteURL, command, body, out)` (`apps/api/internal/agentcmd/client.go:629`) mints a fresh single-use-`jti` Ed25519 JWT bound to `aud=siteID` + `cmd=<command>`, POSTs over the SSRF-hardened client via `DoOnce` (no HTTP retry — jti is single-use; River retries with a fresh mint). Adding commands needs **zero new transport**.
- **Large-file transfer is already solved** by `BackupTransport` (presigned PUT/GET to S3, bounded `curl_multi` concurrency, retry classification). Large bytes go agent↔S3, **never through the CP** (`maxRespBody` caps agent responses at 4 MiB).
- **Write/reassembly primitives exist** in `FilesRestorer` (stage/swap) and `StoragePaths::ensureHardenedPath()` (atomic-ish staging + `.htaccess`/`index.php` hardening + uploads-first base).
- **CP authz + audit are turnkey:** `authz.RequireSiteAccess("siteId")` + `RequirePermission(...)` on a `r.Group("/sites/:siteId")`, m36 RLS underneath, and the hash-chained `audit.Recorder` with the established destructive-consent pattern (`confirm="DELETE"`, recorded with `ActorUser`).
- **Web has the exact UI analog:** `apps/web/src/features/media/` (AssetsTable, AssetDetailDrawer, BulkActionBar, EmptyState, hooks/).

### The three options

**Option A — Granular signed `file_*` commands + CP orchestration + custom React browser. ✅ RECOMMENDED.**
The agent exposes a small family of synchronous, signed file operations. The CP is a thin orchestrator (authz + audit + presign minting) issuing them via `Client.Do`. The web is a custom two-pane browser built on the `media/` primitives. Browse + small-file ops are inline JSON; large transfer rides the agent↔S3 presigned path exactly like backups.
*Reuse: maximal and direct. Complexity: low-medium (write-side agent ops are the only genuinely new code). Fit: native — every existing tool is built this way.*

**Option B — Embedded elFinder frontend + a Go connector proxying to agent commands.**
Reuses the agent commands but discards the `media/` + Impeccable design system for a foreign jQuery widget that won't theme, bypasses TanStack Query/SSE, and adds net-new protocol-translation glue with its own attack surface. elFinder carries a CVE history. *Complexity: medium-high. Fit: poor. **Rejected.***

**Option C — SFTP/WebDAV-style streaming gateway.**
Contradicts the architecture's hardest rule: the agent is a **stateless request/response signed-command target** with no persistent channel and a 4 MiB response cap; streaming multi-GB through the CP blows the scale-to-zero/cost model. *Complexity: high; new long-lived transport + auth. Fit: wrong. **Rejected.*** (Its one good idea — never stream large bytes through the CP — is already in Option A's presigned path.)

**Why A wins:** it is the only option that respects the signed-command, stateless-agent, presigned-large-transfer, RLS+audit invariants the codebase already enforces, and it reuses the existing containment guard rather than writing a new one.

### Proposed command set (agent `file_*` family)

All are `CommandInterface` (sync, signed, `aud=siteId` + `cmd=<name>`), registered in `class-plugin.php::commands()`. Every path is site-relative, forward-slash, run through the `FileScanner` containment guard. A configurable **root jail** bounds every op (default narrow — see §5).

| Command | Sync | Purpose | Built on |
|---|---|---|---|
| `file_list` | ✓ | One-level dir listing (name, size, mtime, mode, is_dir, is_link, is_writable) + total + truncation flag | one-level wrapper over `FileScanner` walker |
| `file_read` | ✓ | base64 body of one file ≤ `max_bytes` (preview/edit) | `FileScanner::getFileContent` (= existing `get_file`) |
| `file_write` | ✓ | Create/overwrite small text file (≤256 KiB) via temp-write→`rename` atomic swap | `StoragePaths` hardening + `FilesRestorer` swap |
| `file_mkdir` | ✓ | Create directory (hardened) | `StoragePaths::ensureHardenedPath` |
| `file_rename` | ✓ | Rename/move within jail (containment on BOTH src+dst) | guard ×2 |
| `file_delete` | ✓ | Delete file/empty-dir (recursive behind explicit flag + confirm) | guard |
| `file_chmod` | ✓ | Set mode within a safe allowlist (no setuid/world-write) | guard |
| `file_download_prepare` | ✓ | Large file: agent presigned-PUTs to S3 (optionally zip a dir), returns chunk manifest/key | `BackupTransport` presign/multi-PUT + `FilesArchiver` |
| `file_upload_apply` | ✓ | Agent presigned-GETs CP-staged upload chunks, reassembles, atomic-swaps into jail | `BackupTransport::getChunk` + `FilesRestorer` reassembly |

Design rules: inline JSON for browse + ≤256 KiB read/write; **anything larger rides the presigned S3 path** (never through the CP, never past the 4 MiB cap). Write/delete/rename are the only net-new agent logic; read/list/transfer are projections of existing code.

### Data model (largely stateless)

The agent is the source of truth for the live filesystem — there is **no file-index table**. Persist only audit + transfer bookkeeping (new migration, e.g. `m76_file_manager`):

- **No file-tree table.** Listings are live agent calls, cached only in TanStack Query.
- **`file_transfers`** (`tenant_id, site_id, id, direction upload|download, rel_path, status staged|active|done|failed, object_key, size_bytes, chunk_count, created_by, created_at, expires_at`). RLS-scoped like `backup_snapshots`; rows are short-lived and GC'd; reuses the tenant-namespaced chunk-key scheme.
- **Audit only** for mutations — the hash-chained `audit_log` is the record of truth (no separate file-ops table).

New audit actions: `site.files.read/write/upload/download/mkdir/rename/delete` (delete recorded with `ActorUser` + the type-to-confirm token, like `ActionMediaCleanDelete`).

New permissions (following `PermMediaClean*`): `PermSiteFilesRead` (read/list/download), `PermSiteFilesWrite` (write/mkdir/rename/upload), `PermSiteFilesDelete` (destructive). Route gate = Write; body gate for delete = Delete + `confirm="DELETE"` (the `dbTableAction` drop/empty pattern).

### Endpoint list (new `internal/files` domain module)

New module `apps/api/internal/files/` (handler.go, service.go, contract.go), mounted on the per-site group (same shape as `perf/handler.go:66`):

```
g := r.Group("/sites/:siteId", authz.RequireSiteAccess("siteId"))

GET    /sites/:siteId/files            PermSiteFilesRead    # list dir (?path=&cursor=)
GET    /sites/:siteId/files/content    PermSiteFilesRead    # read small file (?path=)
PUT    /sites/:siteId/files/content    PermSiteFilesWrite   # write small text file
POST   /sites/:siteId/files/mkdir      PermSiteFilesWrite
POST   /sites/:siteId/files/rename     PermSiteFilesWrite
POST   /sites/:siteId/files/delete     PermSiteFilesWrite   # +PermSiteFilesDelete +confirm in body
POST   /sites/:siteId/files/chmod      PermSiteFilesWrite
POST   /sites/:siteId/files/download   PermSiteFilesRead    # -> file_download_prepare, returns presigned GETs
POST   /sites/:siteId/files/upload     PermSiteFilesWrite   # CP stages chunks, mints presigned, -> file_upload_apply
```

Each handler: resolve `TenantID`/`siteID` from principal → call service → `agentcmd.Client.Do(ctx, siteID, siteURL, "file_<op>", req, &out)` → record audit → return DTO. Contract structs live in `internal/agentcmd/file_contract.go`, mirrored in the agent (the `scan_contract.go` convention). OpenAPI: add to the spec and regen (`go generate ./internal/api/gen/...` + `pnpm -C packages/openapi-client generate`).

### Web (new `apps/web/src/features/files/`)

Mount a **"Files" sub-tab** under the site shell (alongside `$siteId.tools.tsx`) or a new `$siteId.files.tsx` route. Build on `media/` primitives: `FileBrowser.tsx` (breadcrumb nav + virtualized list, from `AssetsTable.tsx`), `FileDetailDrawer.tsx` (from `AssetDetailDrawer.tsx`), reused `BulkActionBar.tsx` / `EmptyState.tsx`, `CodeEditorDialog.tsx` (with a "back up first" advisory header, like search-replace), `DeleteDialog.tsx` (type-to-confirm `DELETE`, from `DeleteOriginalsDialog.tsx`), hooks `useFiles.ts` / `useFileMutations.ts` / `useFileTransfer.ts` mirroring `useMediaAssets.ts`.

### Reuse map (build on these exact files)

| Concern | Reuse | Path |
|---|---|---|
| Path containment / traversal guard | `FileScanner::getFileContent` + segment/realpath checks | `apps/agent/includes/support/class-file-scanner.php:284` |
| Directory walk (→ one-level list) | `FileScanner::scan` + `ScanCommand` | `apps/agent/includes/.../class-scan-command.php` |
| Read primitive (template) | `GetFileCommand` | `apps/agent/includes/commands/class-get-file-command.php` |
| Command interface + dispatch | `CommandInterface`, `Router` | `interface-command-interface.php`, `class-router.php` |
| Command registration | `Plugin::commands()` array | `apps/agent/includes/class-plugin.php` |
| Large file transfer (presign/multi-PUT/GET+retry) | `BackupTransport` | `apps/agent/includes/support/class-backup-transport.php` |
| Dir zip for download | `FilesArchiver` | `apps/agent/includes/backup/class-files-archiver.php` |
| Atomic write / reassembly / staging | `FilesRestorer` (stage/swap) | `apps/agent/includes/backup/class-files-restorer.php` |
| Hardened write base + uploads-first + `.htaccess` guard | `StoragePaths::ensureHardenedPath` | `apps/agent/includes/support/class-storage-paths.php` |
| Empty-base-path guard (`$basePathResolved`) | `MediaQuarantine` | `apps/agent/includes/media/class-media-quarantine.php` |
| Outbound agent signing (write-side callbacks) | `Signer` | `apps/agent/includes/class-signer.php` |
| CP→agent command issuance (jti JWT, DoOnce, SSRF) | `agentcmd.Client.Do` | `apps/api/internal/agentcmd/client.go:629` |
| CP→agent contract pattern | `scan_contract.go` | `apps/api/internal/agentcmd/scan_contract.go` |
| Per-site RLS + authz gate | `authz.RequireSiteAccess` + `RequirePermission` | `apps/api/internal/perf/handler.go:66` |
| Permission constants pattern | `PermMediaClean*` | `apps/api/internal/authz/role.go` |
| Tamper-evident audit + confirm-token destructive pattern | `audit.Recorder` + `ActionMediaCleanDelete` | `apps/api/internal/audit/audit.go` |
| Presigned-URL minting + tenant-namespaced chunk keys | `backup/agent_handler.go` + `backup/model.go` | `apps/api/internal/backup/` |
| Web browser UI shell + drawer + bulk bar + hooks | `features/media/*` | `apps/web/src/features/media/` |
| Web route mount point | `$siteId.tools.tsx` / new `$siteId.files.tsx` | `apps/web/src/routes/_authed/sites/` |

---

## 4. Ready-made library recommendations (per layer)

**Architecture reality that drives every pick:** the WP filesystem lives only on the PHP agent. The Go CP never mounts or touches it — it orchestrates, authorizes (tenant/RLS), streams progress (SSE), and proxies bytes. The React web only renders. **This single fact disqualifies most "ready-made full file managers," which assume the server they run on *owns* the disk.**

### Frontend (apps/web, React) — RANKED

| Lib | License | Maint. | Fit |
|---|---|---|---|
| **TanStack Table + TanStack Virtual** (list/grid) | MIT | Very active, already in-stack | **Recommended** — native Impeccable, virtualizes 1000s of rows |
| **react-arborist** (folder tree) | MIT | Healthy, v3.10.x (2026-06), React 19 | **Recommended** — virtualized, headless render props |
| react-complex-tree (tree alt) | MIT | Active, React 19 | Tree fallback (best WAI-ARIA) |
| **Radix Context Menu / Dialog** | MIT | Active, already in-stack (shadcn) | **Recommended** — a11y baked in |
| **Uppy (`@uppy/react`)** | MIT | Very active (v5, Transloadit) | **Recommended** for uploads (v1.1), AwsS3 presigned |
| @cubone/react-file-manager | MIT | Active (v1.35.0) | Viable drop-in, NOT recommended (no virtualization, fights Impeccable theming) |
| react-dropzone | MIT | Active | Drop-zone primitive only |
| SVAR React File Manager | MIT | New (2025) | Vendor look, foreign theme |
| Chonky / react-chonky | MIT | **ARCHIVED 2025-12** | Do not adopt |
| elFinder (jQuery — the reference's UI) | 3-clause BSD | Low | Legacy jQuery; avoid |

**Recommendation (amended 2026-06-22 after a focused UI-library evaluation): build the browser as a custom headless assembly, NOT a ready-made manager.** Use **TanStack Table + TanStack Virtual** for the file list/grid (virtualizes thousands of files, native Impeccable tokens), **react-arborist** for the folder tree (MIT, React 19, virtualized; swap to react-complex-tree if WAI-ARIA is a hard gate — keep the tree behind a thin interface), **Radix Context Menu + Dialog** for right-click/preview, **TanStack Query** for the async data layer against the `file_*` CP endpoints, all themed natively with Impeccable and reusing `apps/web/src/features/media/` as the structural template. Uploads (v1.1) use **Uppy `@uppy/react` + AwsS3** (presigned multipart/resumable, WCAG-AA). `@cubone/react-file-manager` was the original tentative drop-in but is demoted: it has **no virtualization** (our hard requirement is large directories) and exposes only `primaryColor`/`fontFamily`/`className` over baked-in CSS, so it fights the OKLCH/IBM-Plex/dark-mode design system. **elFinder is the wrong runtime for a React/TanStack app; Chonky is archived; SVAR is a foreign theme.** The added cost of the headless build is owning the list's ARIA (`role="grid"`), a fair trade for full design control + scale; the tree + menu a11y come free from react-arborist/Radix. The custom build is the lower-total-cost, better-looking path for an Impeccable, fleet-scale product.

### Go CP (apps/api) — RANKED for the proxy/validate/stream role

| Lib | License | Use in CP? |
|---|---|---|
| **stdlib** (`net/http`, `io.Copy`, `path`, `mime`) | BSD | **Primary** — proxy bytes, no dep |
| pkg/sftp + `x/crypto/ssh` | BSD-2 / BSD | Only if a non-agent SSH path is added |
| mholt/**archives** (successor to archiver) | MIT | Only if CP ever builds/extracts archives |
| spf13/afero | Apache-2.0 | **Not needed** (CP owns no FS) |
| go-git/go-billy | Apache-2.0 | Not applicable |
| **mholt/archiver v3** | MIT | **BANNED — deprecated + CVE-2025-3445 Zip-Slip, no fix** |

**Recommendation: keep the CP thin — stdlib only.** The CP's job is auth + RLS + SSE + a byte relay; `io.Copy` over `net/http` with `path.Clean`-style validation covers it. **afero / go-billy presuppose the process owns a filesystem — the CP doesn't, so they abstract over nothing.** If archive zip/unzip ever runs CP-side use **mholt/archives**, never **archiver v3**.

### Wire protocol (elFinder server connector in Go) — REJECTED

Every Go elFinder connector is unmaintained and tiny; the most "complete" (LeeEirc/elfinder) is **GPL-3.0**, incompatible with our open-core posture, and adopting the protocol chains us to the archived jQuery frontend. **Do NOT adopt the elFinder protocol.** Define our own small house JSON contract (list/stat/read/write/move/delete/mkdir/upload) consumed by the React browser — which is exactly the `file_*` command set in §3.

### PHP agent (apps/agent) — hand-roll, no library

There is **no general-purpose WP file-manager engine worth pulling in** — the surface is small and security-critical, so a third-party dep is more attack surface than help. The standard audited pattern (which the agent already implements in `FileScanner`):

- **`realpath()`-confine:** resolve `$base = realpath($root)`, resolve `$target = realpath($base.'/'.$rel)`, reject unless `str_starts_with($target, $base.DIRECTORY_SEPARATOR)` — resolves symlinks + `..` in one shot.
- **WP primitives:** `sanitize_file_name()`, `wp_basename()`, extension **allowlist** (block `.php`/executable on write), `current_user_can`, and `WP_Filesystem` for writes (respects host FS-method/ownership — the curva chown bug class).
- **Streaming:** `fopen`/`fread`/`fpassthru` with range support; never whole-file into memory.
- **Negative tests as a merge gate:** `../../wp-config.php`, encoded traversal, null-byte, symlink-escape.

### Full file managers explicitly NOT a fit (and why)

**filebrowser/filebrowser** (Apache-2.0, ~35k stars, maintenance-only), **SVAR/OpusCapita/KendoReact** server-bundled managers, and **Chonky** (archived) all assume *the server process owns the filesystem it serves*. Our CP deliberately does not — the disk is two hops away on the agent — so their integrated backends are dead weight and their frontends can't be cleanly severed from server assumptions. **elFinder + its Go connectors** force the jQuery frontend *and* an abandoned GPL server half. **The only ready-made pieces that survive contact with our topology are UI-only (cubone/Uppy) and single-purpose Go/PHP primitives — never an end-to-end manager.**

---

## 5. Security

**Architecture advantage:** WordPress mounts **no connector**. The CP holds the only signing key; the agent executes narrow, individually-signed commands. This eliminates the entire "unauthenticated AJAX connector reachable by anon" attack surface that caused the reference CVE.

### Prioritized threat table

Severity: **CRIT** / HIGH / MED / LOW. Tier: **MUST** (blocks ship) / SHOULD / NICE.

| # | Threat | Sev | Mitigation | Tier |
|---|---|---|---|---|
| T1 | **RCE via executable upload/write into web-served dir** (CVE-2020-25213 class) | CRIT | **Default read-only in v1.** When write enabled: deny-by-default executable extensions (`php php3 php4 php5 php7 phps phtml pht phar shtml asp aspx jsp cgi pl py htaccess htpasswd ini`), **double-extension** detection (`x.php.jpg`, trailing-dot, case-fold, `.pHp`), **content sniff** (reject `<?php`/`<?=` regardless of extension), MIME allowlist for true uploads, and **block any write whose resolved target is under a web-served, PHP-executable dir** unless owner passes `confirm_executable_write` AND holds `site.files.write_code`. A `.htaccess` no-exec marker alone is NOT sufficient (operator could delete it) — enforce at the agent. | **MUST** |
| T2 | **Path traversal / symlink escape / zip-slip** | CRIT | Reuse FileScanner jail on **all** ops: reject `..`/`.`/NUL, `lstat`+`is_link` reject, `realpath`+`strncmp` to jail root. Extract: canonicalize each entry against the jail, refuse escaping/symlink/hardlink/absolute entries. Jail root = ABSPATH (or configured subtree); never `WP_CONTENT_DIR ?? ''`. | **MUST** |
| T3 | **Empty/unresolved base → write at FS root** | HIGH | Port the `$basePathResolved` guard: every write/delete/mkdir/extract resolves a non-empty base or **throws before any FS mutation**. No `?? ''` fallbacks. | **MUST** |
| T4 | **AuthZ — wrong tier uses the manager** | HIGH | `site.files.read` (admin+), `site.files.write` (admin+, default OFF per site), `site.files.delete` (owner, or admin behind per-site capability), `site.files.write_code` (owner only). `viewer`/`client`/`operator` get **no** file access in v1. Feature **opt-in per site** (off by default), toggled by owner/admin. | **MUST** |
| T5 | **Tenant isolation breach** | CRIT | `canReadSite` + `app.site_scope` RLS **before** minting any token; token `aud` = target site UUID; no shared/global scratch — per-command, per-site temp under the jail, cleaned in `finally`. | **MUST** |
| T6 | **Sensitive-file exfiltration** | HIGH | Default **deny read/download** of `wp-config.php`, `.env*`, `*.pem`/`*.key`/`id_rsa*`, `.git/`, `.htpasswd`, `auth.json`. Reads of these require `confirm_sensitive` + owner perm, audited at elevated severity with full path. Never auto-include secrets in directory previews. | **MUST** |
| T7 | **Transport replay / token repurposing** | HIGH | JWT contract already covers: `exp ≤ 60s`, single-use `jti`, `aud`-bound, `cmd`-bound. **Each file op is a distinct `cmd`** so a captured `file_read` token can never perform `file_write`/`file_delete`. No wildcard/multi-op command. | **MUST** |
| T8 | **Presigned-URL becomes an unauth file oracle** | HIGH | Presigned URLs only ever point at **CP-owned object storage** (staging), never an agent file endpoint. Short TTL (≤5 min), single key, GET-only download / PUT-only-to-staging upload, key namespaced by tenant+site. Agent fetches staged upload via its own signed command, then validates (T1/T2) before placing. The signed command — not the URL — is the authorization. | **MUST** |
| T9 | **Audit gap / repudiation** | HIGH | Every op (actor, role, tenant, site, op, path, bytes, result, jti, ts) appended to the tamper-evident activity hash-chain. Log **attempts and denials** too (esp. T1/T6 confirms). Append-only; chain-break detection already exists. | **MUST** |
| T10 | **DoS / resource exhaustion** | MED | Per-op byte caps (inline read 256 KiB; transfers chunked with hard total cap), listing depth/entry caps, pre-flight free-disk check before write/extract, **zip-bomb guard** (uncompressed-total + ratio + entry-count), enforce PHP `memory_limit`/`max_execution_time`, stream never whole-file. | **MUST** (caps) / SHOULD (ratio) |
| T11 | **Edit-in-place corrupts a live site** | MED | Pre-write backup of the target file to staging + size/encoding sanity; integrate with snapshot tooling; "restore previous version" affordance. | SHOULD |
| T12 | **CSRF / stolen dashboard session drives file ops** | MED | Destructive ops (delete, code-write, sensitive-read) require step-up re-auth (operator 2FA re-prompt, ADR-056) + typed confirm. SameSite + existing CSRF protections. | SHOULD |
| T13 | **Mass/bulk destructive op** | MED | Bulk delete behind typed confirm + count preview + owner perm; refuse delete of protected roots (`wp-admin`, `wp-includes`, plugin/theme roots) without explicit override. | SHOULD |
| T14 | **MIME/preview parser exploitation** | LOW | No server-side image transforms in v1; previews are byte-range text only; thumbnails (if any) go through the existing SSRF/decompression-guarded media-encoder, never an in-WP GD/Imagick call. | NICE |
| T15 | **Clean-room leakage** | LOW | No elFinder/`LocalFileSystem`/connector code; vendor-neutral migration/command names; no competitor name in committed/shipped files (standing rule). | **MUST** |

### Top 5 BLOCKING controls — must NOT ship without these

1. **Path jail with realpath containment on every op** — reject `..`/`.`/NUL, refuse symlink follow, `realpath`+`strncmp`-to-jail-root, plus the **empty/unresolved-base guard that throws before any write** (T2, T3). Reuse `class-file-scanner.php` + the `$basePathResolved` pattern.
2. **Executable-write prevention** — read-only in v1; for v1.1 write, deny-by-default executable extensions + double-extension + `<?php` content sniff + block writes into PHP-executable web dirs, gated behind owner-only `site.files.write_code` + explicit confirm (T1).
3. **AuthZ + tenant isolation on every route** — `canReadSite` + `app.site_scope` RLS before minting any token; `aud`-bound + `cmd`-bound single-use Ed25519 JWT (≤60s, `jti`) per op; owner/admin-only; off-by-default per site (T4, T5, T7).
4. **Sensitive-file deny + audited reads** — default-deny `wp-config.php`/`.env`/keys/`.git`; any read/download of a sensitive path requires owner + explicit confirm and writes a high-severity audit entry; every file op logged including denials (T6, T9).
5. **Resource/DoS bounds** — hard byte caps on read/transfer (streamed, never whole-file into memory), listing depth/entry caps, pre-write free-disk check, and a **zip-bomb guard** (uncompressed-total + ratio + entry-count) on extract, within `www-data` PHP memory/time limits (T10).

### Recommended v1 posture

**Read-only-first, executable-write-blocked, owner/admin-only, off-by-default per site, fully audited, jailed paths.**

- **Ship v1 read-only** (browse + byte-capped read + download). Write/upload/delete land in **v1.1** behind the controls above, after the read primitive is proven. The reference's entire CVE history is in the *write/upload* path; deferring it removes the CRIT surface from the first release.
- **Off by default, per-site opt-in** — disabled until an owner/admin explicitly enables it for that site (mirrors how cache/destructive features are gated).
- **AuthZ tiers:** read → admin+; write → admin+, per-site flag default OFF (v1.1); delete → owner (or admin behind per-site capability) (v1.1); `write_code` (executable/`wp-config`/sensitive write) → owner only, explicit confirm (v1.1); `viewer`/`operator`/`client` → no file access in v1.
- **Mandatory security-reviewer pass before each phase ships** (the suite's standing gate).

---

## 6. Caveats

1. **The agent does the work, not the CP.** The WordPress filesystem is *only* reachable from the PHP agent. The CP never reads the disk; it authorizes, mints tokens/presigned URLs, audits, and (for small payloads) relays JSON. Any design that assumes the CP can `os.Open` a site file is wrong (this is why afero/go-billy and full-stack file managers are out).
2. **PHP exec/memory/time limits on the `www-data` agent are a hard ceiling.** The agent runs inside WordPress under the host's `memory_limit` and `max_execution_time`. Never `file_get_contents` a whole large file; stream with `fopen`/`fread`/`fpassthru` and range support. Large reads must be byte-capped (256 KiB inline) and anything bigger must go via the presigned S3 path. Extraction and directory zipping must respect time/memory budgets and resume where the existing backup machinery does.
3. **Large-file streaming bypasses the CP entirely.** Agent responses are capped at 4 MiB (`maxRespBody`); the agent is a stateless request/response target with no persistent channel. Large download/upload must ride agent↔S3 via CP-minted presigned URLs (the `BackupTransport` path), bookkept in `file_transfers`, with presigned URLs never logged and short TTLs (≤5 min). This is the same rule backups already enforce.
4. **Scale-to-zero / transport limits.** The media-encoder runs `min=0` and is a pull worker; if directory-zip or image preview ever routes through it, it needs the existing CP wake/drain handshake or jobs enqueue-and-never-run. File commands use single-use `jti` and `DoOnce` (no HTTP retry) — retries belong at the River/job layer with a fresh mint, never an HTTP-level retry.
5. **Clean-room constraints (binding).** No code copied from the studied reference plugin or its bundled elFinder connector/`LocalFileSystem`. The plugin is never named in committed/shipped files; migration and command names are vendor-neutral; no "not copied from X" disclaimers. Generic library names (elFinder, filebrowser, afero, pkg/sftp, Uppy, mholt/archives) and the public CVE id are fine.
6. **The auth gate is necessary but not sufficient.** The reference's defining failure was treating the capability check as the only line of defense while the executable-upload deny-list was inert. WPMgr must keep a *real* server-side executable deny-list so any future auth bug degrades to file-write, not code-exec.
7. **Edit-in-place can brick a live site.** Editing `wp-config.php`/`functions.php`/`.htaccess` is the most dangerous operation; v1.1 must snapshot the target before overwrite and offer "restore previous version."
8. **Banned/avoid dependencies.** `mholt/archiver` v3 (CVE-2025-3445 Zip-Slip, no fix) and the GPL-3.0 Go elFinder connectors (open-core license conflict). Chonky is archived/unmaintained.

---

## 7. Phased build plan (security-review gate per phase)

Mirrors how the WPMgr security suite was built: CP-first, specialist-routed, a **mandatory security-reviewer pass gating every phase**, deploy all layers, docs/CHANGELOG/landing as the definition-of-done.

**P1 — Read-only browser (v1).**
- Agent: `file_list` (one-level wrapper over `FileScanner::scan`), `file_read` (≈ `get_file`), `file_download_prepare` (presigned GET via `BackupTransport`). Containment guard reused unchanged; sensitive-path deny-list; root-jail config (default narrow).
- CP: `internal/files` module (handler/service/contract), `PermSiteFilesRead`, per-site opt-in flag (default OFF), `canReadSite`+RLS, audit actions, presigned-URL minting, `file_transfers` table (download rows), OpenAPI regen.
- Web: `features/files/` browser + detail drawer + download, on `media/` primitives.
- **Security gate:** path-escape, sensitive-file deny, tenant isolation, presigned-URL oracle (T2/T5/T6/T8), DoS caps (T10).

**P2 — Safe write/upload (v1.1).**
- Agent: `file_write`, `file_mkdir`, `file_rename`, `file_delete`, `file_chmod`, `file_upload_apply` (presigned GET of staged chunks + atomic swap). Executable-write prevention (T1), `$basePathResolved` throw-before-write (T3), zip-bomb/extract guards, pre-write backup (T11).
- CP: `PermSiteFilesWrite`/`Delete`/`write_code`, per-site write flag (default OFF), destructive confirm-token pattern, step-up re-auth on destructive ops (T12), protected-root refusal (T13), upload staging + presigned PUT.
- Web: code editor dialog (with "back up first" advisory), upload pane (Uppy), type-to-confirm delete dialog, bulk-action bar.
- **Security gate (the heaviest):** executable-write/double-extension/content-sniff, write-path escape, upload-reassembly swap, empty-base guard, bulk-destructive (T1/T2/T3/T13).

**P3 — Advanced ops.**
- Archive create/extract (quarantine→reject-symlinks→max-size→atomic move, per the library pattern), directory zip-download, search within the jail, version history / restore-previous, optional `wp-content`-scoped root presets, per-role op restrictions as a first-class feature.
- **Security gate:** zip-slip/zip-bomb on extract, search traversal, any new path surface.

**Deploy ordering each phase** (per the full-stack-deploy + CP-first memories): agent `file_*` commands + tests → CP `internal/files` + contract + OpenAPI regen → web `features/files` → security review → deploy api → web → `make agent-release` → docs/CHANGELOG + landing DoD.
