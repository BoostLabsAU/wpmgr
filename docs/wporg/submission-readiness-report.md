# WPMgr Agent — WordPress.org Submission Readiness & Fix Report

**Plugin Check baseline:** 477 ERRORs + 539 WARNINGs across 89 files (1016 findings). **Triage verdict:** the overwhelming majority are coding-standard noise correctly justified for a backup/migration plugin; **zero genuine security vulnerabilities were found** across all 8 clusters. The submission is gated by a small set of human-decision blockers (slug rename + self-updater removal), not by the finding count.

---

## 1. Bottom line / go decision

**GO — conditional.** This plugin class is **explicitly permitted** on WordPress.org. Remote-management agents that link a site to an external or self-hosted control plane are named verbatim in Guideline 8 ("Management services that interact with and push software down to a site ARE permitted, provided the service handles the interaction on its own domain and not within the WordPress dashboard"). Live precedents: **MainWP Child** (self-hosted dashboard — closest analog), **ManageWP Worker** (SaaS), **Jetpack** (SaaS). WPMgr Agent fits cleanly: opt-in connection (default endpoint = none), signed-API command dispatch (no iframed admin pages), no `eval`/remote-PHP execution.

There are **4 HARD blockers that need your decision before any code is touched**:

| # | Blocker | Why it blocks | Decision owner |
|---|---------|---------------|----------------|
| **B1** | **Slug/name contains leading "wp"** (`wpmgr-agent` / "WPMgr Agent") | wp.org rejects any slug/display-name that **begins with** "wp". Slug is **permanent** once approved. `trademarked_term` ×3 in PCP. | **You** — pick the new brand name |
| **B2** | **CP-driven self-updater** (`class-update-checker.php` hooks `site_transient_update_plugins`) | Guideline 8 (G8) HARD BLOCKER: wp.org builds must update **only via wp.org**. PCP flags `plugin_updater_detected` + `update_modification_detected`. | **You** — approve the build-variant strategy |
| **B3** | **External-service / privacy disclosure missing** from readme.txt | Guideline 7: a plugin contacting an external service must disclose what/when/where + live ToS + Privacy links (reviewers click them). | **You** — confirm live `/terms` + `/privacy` URLs exist |
| **B4** | **readme metadata is broken/stale** | `Stable tag: 0.19.1` ≠ header `0.31.1`; `Tested up to` stale; `Requires PHP` mismatch (readme 8.0 vs header 8.1); 5 tags at the spam limit. | Engineer, but **you** confirm the new brand for all the cascading keys |

Everything else (the ~1000 PHPCS findings) is mechanical and is laid out in §6. **No security fix is required to ship** (§7).

---

## 2. Decisions the user must make NOW

### (a) The "wp" trademark slug/name problem — **DECIDE THE NEW BRAND**

**The exact rule:** wp.org bans "wp" only as the **LEADING/INITIAL term** of the slug or display name — **not** anywhere in it. Guideline 17 (Trademarks): *"The use of trademarks … as the sole or initial term of a plugin slug is prohibited"* and *"we will not permit a slug to begin with another product's term."* The review team extended this to the abbreviation "wp" (added ~Aug 2021). So "wp" is fine **mid-name or as a suffix** but blocked when it **leads**.

- `wpmgr-agent` → **REJECTED** (begins with "wp"). The slug is auto-derived from the `Plugin Name:` header and is **permanent** post-approval.
- "WPMgr Agent" → **REJECTED** as display name (begins with "WP"); the team also polices the "clean-slug-then-rename-to-WP" loophole, so the display name is checked too.
- **Brand is preserved by moving "WPMgr" to a suffix** — the canonical sanctioned pattern ("Product Addons for WooCommerce" is allowed; "WooCommerce – Product Addons" is not).

**QUESTION:** *Which slug + display name do we lock in (permanent)?*

| Rank | Slug | Display name | Pro / Con |
|------|------|--------------|-----------|
| **#1 (recommended)** | `fleet-agent-for-wpmgr` | **Fleet Agent for WPMgr** | Canonical "for `<Brand>`" pattern; keeps full WPMgr brand visible; ties to your already-shipped **Fleet Hub** brand mark (favicon commits). Con: longer slug. |
| #2 (fallback) | `fleet-hub-agent` | Fleet Hub Agent (for WPMgr) | Matches the shipped Fleet Hub mark; short clean slug. Con: WPMgr demoted to parenthetical. |
| #3 | `site-fleet-agent` | Site Fleet Agent for WPMgr | Descriptive, self-explanatory to reviewers. Con: "site fleet" slightly generic. |
| #4 | `mgr-agent` | Mgr Agent for WPMgr | Shortest, closest to the literal "WPMgr" minus "wp". Con: cryptic; reviewers may read it as an obvious "wp" dodge. |
| #5 | `manage-agent` | Manage Agent for WPMgr | Maps to `manage.wpmgr.app`. Con: generic, possible slug collision. |

**AVOID:** anything starting `wp…`, and any slug containing `wordpress` or `plugin` (separate FAQ rule).
**Recommended call:** **#1 `fleet-agent-for-wpmgr` / "Fleet Agent for WPMgr."** Confirm the slug is free on wp.org before locking the folder name, and **verify the slug in the submission confirmation email before approval** (only correctable pre-approval).

**Cascade once chosen** (B1 cross-cuts the tree): text-domain, folder/slug, `PLUGIN_KEY`/`PLUGIN_SLUG` constants in `class-update-checker.php` (including the manifest `slug` `hash_equals` at line 371 + 67/70 — part of the CP self-update contract), all `wpmgr_*` option/transient prefixes, the Makefile staging folder `wpmgr-agent/`, and the CP manifest `"slug"` claim. Coordinate the rename with the CP so manifest verification doesn't break self-update on existing fleets.

### (b) The self-updater — **APPROVE THE BUILD-VARIANT STRATEGY**

`includes/support/class-update-checker.php` is a full CP-driven self-updater: `install()` (line 166–188, called from `class-plugin.php:522`) registers `site_transient_update_plugins` + `plugins_api` + `upgrader_pre_download` + `upgrader_source_selection`, fetches a CP-signed Ed25519 manifest, and injects an update entry. This is **exactly** what G8 forbids for the directory build. The engine is otherwise excellent (full signature/replay/rollback/SSRF chain) and **must be kept for the self-hosted/SaaS GHCR build.**

**QUESTION:** *Do we ship two zips from one tree via a build constant + file-exclude?*

**Recommended: YES — two-layer constant-guard + file-exclude.**
1. Guard the boot hook so the updater never binds in the wp.org build:
   ```php
   if (!defined('WPMGR_WPORG_BUILD') || !WPMGR_WPORG_BUILD) {
       $this->updateChecker->install();
   }
   ```
   Also hide the Admin "Check for updates" affordance (`class-admin.php:724 checkNow()`) behind the same constant.
2. To physically exclude the file (PCP static-matches the file's `_site_transient_update_plugins` content for `update_modification_detected`): refactor `private UpdateChecker $updateChecker;` → `?UpdateChecker`, make construction (`class-plugin.php:240`) conditional, pass `null` to Admin (Admin null-checks before `checkNow()`), then add `--exclude 'includes/support/class-update-checker.php'` to the rsync.
3. New Makefile target `agent-zip-wporg` that `sed`-injects `define('WPMGR_WPORG_BUILD', true);` into the staged main file (mirroring the existing VERSION sed block) and adds the exclude. The existing `agent-zip` target stays as the self-hosted/SaaS build (self-update intact).

**Critical default:** `WPMGR_WPORG_BUILD` must default to **absent/false** so self-host + GHCR keep auto-update; flipping it true anywhere outside the wporg target silently kills self-update fleet-wide.

### (c) Other human calls

- **Privacy/ToS pages (B3):** the disclosure copy in §4 links `https://manage.wpmgr.app/terms` and `/privacy`. **Confirm these resolve to live pages before submission** — reviewers click them. Create them if absent.
- **License declaration (§3):** MIT is accepted, but **switch the declared license to `GPLv2 or later`** to avoid any future conflict with a bundled GPL-only dependency. Confirm you're OK relicensing the *declaration* (the code stays compatible). Recommended: yes.
- **NOTICE.md attribution:** it must be excluded from the zip (wp.org markdown rule) but the `matthiasmullie/minify` MIT attribution must be **relocated into a `== Third-party / Credits ==` readme section** to stay MIT-compliant.

---

## 3. License verdict

**MIT is acceptable** — it is on the GNU GPL-Compatible list. **Recommended: declare GPLv2-or-later** (MIT can conflict with a bundled GPL-only dependency; GPLv2-or-later is the safe directory posture). The `License:` line **is** the declaration — no separate GPL statement block is needed. Use **identical** lines in `readme.txt` header **and** the main plugin PHP header:

```
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

(If you insist on staying MIT: `License: MIT` / `License URI: https://opensource.org/licenses/MIT` — but GPLv2-or-later is recommended.)

---

## 4. readme.txt rewrite plan

### Header (top of file, `Field: value` per line)

| Field | Exact value | Note |
|-------|-------------|------|
| Plugin Name (=== title ===) | **Fleet Agent for WPMgr** | Must agree with PHP `Plugin Name:` header; must NOT lead with "wp". |
| Contributors | *(your wp.org usernames only)* | e.g. `mosamlife` — wp.org usernames, not emails. |
| Tags | `backup, restore, performance, cache, security` | Max 12, only first 5 display; keep **≤5** to clear the G12 spam flag. |
| Requires at least | `6.6` *(confirm against actual floor)* | |
| Tested up to | **Current stable WordPress at submit time** — verify the exact latest; never above current stable. (PCP flagged the stale value; bump to the live release.) | `outdated_tested_upto_header` fix. |
| Requires PHP | **8.1** | Fix the mismatch (readme said 8.0, header says 8.1; code uses `declare(strict_types=1)` + 8.1 features). Align README.md + composer platform too. |
| Stable tag | **must EXACTLY equal the plugin header `Version`** (currently 0.31.1) | `stable_tag_mismatch` fix; must point to a real `tags/<version>/` subdir, never trunk. **Automate**: add a sed line to the Makefile VERSION block to stamp `Stable tag` from the same `$$_v`. |
| License | `GPLv2 or later` | §3 |
| License URI | `https://www.gnu.org/licenses/gpl-2.0.html` | §3 |

### Required sections (double-equals markers)

`== Description ==` (state plainly: connects a site to a **user-chosen** WPMgr control plane over a **signed API**; opt-in, default endpoint = none; closed allow-list of named commands; **no eval/remote-PHP**; core/plugin/theme updates use WordPress's native Upgrader against wp.org packages), `== Installation ==`, `== Frequently Asked Questions ==`, `== Screenshots ==` (numbered list; line N captions `screenshot-N`), `== Changelog ==` (refresh — it is stale; reconcile with the real version), `== Upgrade Notice ==` (≤300 chars per entry), `== Third-party / Credits ==` (fold in the relocated `matthiasmullie/minify` MIT attribution from NOTICE.md), and the mandatory disclosure section below.

### External-service / Privacy disclosure (MANDATORY — paste verbatim, then swap the two URLs for live pages)

```
== Privacy / What data is sent and where ==

Fleet Agent for WPMgr does not contact any external service until you connect it to a WPMgr control plane that you choose. There is NO default endpoint; the agent is inert until you supply a control-plane URL and complete a one-time, signed enrollment from that control plane. The control plane is software you point the agent at — either a WPMgr instance you self-host, or the hosted WPMgr service at https://manage.wpmgr.app.

Once connected, the agent communicates only with the control-plane URL you configured. It sends the following data, only to that endpoint, and only for the management actions you (or your schedules) initiate:

- Site & environment metadata — site URL, WordPress/PHP/server versions, active theme and plugins, and Site Health diagnostics. Sent on connect, on a periodic heartbeat, and when you click Re-run checks. Used to display your site's status in the dashboard.
- Update inventory — the list of available core, plugin, and theme updates. Sent when inventory is refreshed. Used to show and apply updates.
- Backup archives (encrypted) — when you run or schedule a backup, the agent creates an archive of your database and/or files, encrypts it, and uploads it to the storage destination configured by your control plane. Archive contents may include your site's content and personal data; they are encrypted before leaving the server.
- Rendered HTML — for CSS optimization (used-CSS generation), the agent submits rendered HTML of selected pages so unused CSS can be computed. Used only to produce optimized stylesheets.
- Diagnostics & activity logs — error logs, performance/cache statistics, and a record of management actions, sent so they can be surfaced in the dashboard.

The agent does not sell or share this data with third parties. It receives signed, allow-listed commands (backup, restore, update, cache operations) from your control plane; it does NOT download or execute arbitrary remote PHP code.

If you connect to the hosted WPMgr service, that service's Terms of Service (https://manage.wpmgr.app/terms) and Privacy Policy (https://manage.wpmgr.app/privacy) apply. If you self-host the control plane, you operate the receiving service and your own policies apply. You can stop all data transmission at any time by disconnecting the agent (Disconnect in the agent admin screen) or deactivating the plugin.
```

**Also add a short "How it works / security" readme paragraph** (pre-empts the RCE red flag reviewers look for in this class — Jetpack has had RCE CVEs): closed allow-list of named commands, every command Ed25519-signature-verified against the enrollment key, no `eval`/remote `include`/remote PHP, updates via WordPress's own Upgrader against wp.org packages. **Audit the command dispatcher to confirm there is no generic "run arbitrary code/SQL/PHP" command before zipping.**

---

## 5. Required assets

All assets go in a **top-level `assets/` SVN directory** (sibling of `trunk/` and `tags/`). They are **CDN-served for the listing only and are NOT included in the plugin zip** — never put them in `trunk/`.

| Asset | Filenames | Specs |
|-------|-----------|-------|
| **Icon** | `icon-128x128.png`, `icon-256x256.png`, `icon.svg` (optional, also ship a PNG fallback) | max 1MB each. Reuse the shipped **Fleet Hub** brand mark. |
| **Banner** | `banner-772x250.png`, `banner-1544x500.png` (retina 2×) | max 4MB each. Optional localized variants append `-rtl`/`-es`/`-es_ES` before the extension. |
| **Screenshots** | `screenshot-1.png`, `screenshot-2.png`, … (png or jpg, **lowercase** filenames) | max 10MB each. Captions come from the numbered `== Screenshots ==` list (line N captions `screenshot-N`). |

**Layout reminder:** `assets/` holds media; `trunk/` holds code + `readme.txt` (this is what gets zipped); `tags/<version>/` matches `Stable tag`.

---

## 6. The code-fix playbook

**Triage principle applied:** triage by sniff family, not raw count. Only (a) real injection/escaping vulns and (b) Security-category ERRORs block — and **none of those exist here**. DirectDatabaseQuery / NoCaching / AlternativeFunctions are JUSTIFIED-IGNORE families for a backup plugin; the file_system + parse_url families get partially REAL-FIXED via clean WP swaps first. **Canonical ignore syntax** (PHPCS 3.2.0+, the ` -- reason` is mandatory-in-practice; never a bare `phpcs:ignore`; always pin the exact sniff code; keep reasons vendor-neutral per the "no defensive comments" rule):

```
// phpcs:ignore <Sniff.Code>[,<Sniff.Code>] -- <neutral reason: own table / streaming-seek / archive size / pre-boot drop-in>
```

The codebase already uses this style (5 existing usages, e.g. `class-files-archiver.php:1080`), so house style matches.

### C1 — Filesystem (211 findings) — **REAL_FIX the cheap swaps, JUSTIFIED_IGNORE the streaming/perm/atomic ops**
Context: headless agent, **never** initializes `WP_Filesystem` (would prompt for FTP creds and hard-fail non-interactively). No security bugs.

| Sniff | Count | Verdict | Recipe / canonical ignore | Effort |
|-------|------:|---------|---------------------------|:--:|
| `unlink_unlink` | 50 | **MIXED → mostly REAL_FIX** | `@unlink($p)` → `wp_delete_file($p)` (drop the `@`) for all server-derived single-file deletes. **Exception (ignore):** the pre-boot advanced-cache drop-in and atomic-write rollback temp-cleanup that pair with `rename()` (`class-disk-writer.php:69,75,102`) → `-- runs in the advanced-cache drop-in before WordPress (wp_delete_file unavailable)`. | M |
| `file_system_operations_mkdir` | 38 | **MIXED → REAL_FIX 0755 only** | `!@mkdir($d,0755,true)` → `!wp_mkdir_p($d)`. **DO NOT swap the 0700 secret/scratch dirs** (`class-keystore.php:553`, `class-db-snapshot-command.php:135,382`, `class-local-destination.php:83,224`, `class-restore-runner.php:489`) — `wp_mkdir_p` applies `FS_CHMOD_DIR` (0755) and would **widen perms on key/snapshot dirs on shared hosts**. Ignore those: `-- explicit 0700 perms on secret/scratch dir; wp_mkdir_p would apply the wider FS_CHMOD_DIR`. Also ignore drop-in mkdirs (pre-boot). | M (MEDIUM risk if applied blindly) |
| `rename_rename` | 22 | **JUSTIFIED_IGNORE** | Keep native `rename()`. `-- atomic same-filesystem swap; WP_Filesystem::move() is copy+delete (non-atomic) and breaks crash/watchdog-resume safety`. **Never "fix" to `WP_Filesystem::move()`** — would tear wp-config/cache files and half-promote restores. | S |
| `..._is_writable` | 21 | JUSTIFIED_IGNORE | `-- headless agent; WP_Filesystem never initialized; direct writability probe is the only option`. Read-only, no side effects. | S |
| `..._chmod` | 18 | JUSTIFIED_IGNORE | Every chmod **hardens** to 0600/0700. `-- explicit security perms (0600/0700); WP_Filesystem would coerce to wider FS_CHMOD_*`. | S |
| `..._fclose` | 17 | JUSTIFIED_IGNORE | Pairs 1:1 with streaming fopen. `-- closes a streaming handle over multi-GB archives; WP_Filesystem has no streaming API`. | S |
| `..._fopen` | 14 | JUSTIFIED_IGNORE | `-- streaming handle for chunked fread/fwrite over multi-GB archives; WP_Filesystem exposes only whole-file get/put which would OOM`. | S |
| `..._rmdir` | 12 | JUSTIFIED_IGNORE | No WP wrapper. `-- removes an empty server-derived scratch/snapshot dir; WP_Filesystem not initialized`. | S |
| `..._fwrite` | 8 | JUSTIFIED_IGNORE | `-- incremental write into a streaming handle; WP_Filesystem put_contents is whole-buffer only`. | S |
| `WriteFile.PluginDirectoryWrite` | 4 | JUSTIFIED_IGNORE (FP) | Writes to `wp-content/cache` + `wp-content/mu-plugins` (intended install targets). `-- writes to wp-content/{cache,mu-plugins}, a persistent location outside the plugin folder; intended install target`. | S |
| `..._fread` | 3 | JUSTIFIED_IGNORE | `-- chunked read over a multi-GB artifact; WP_Filesystem get_contents reads whole file into memory`. | S |
| `WriteFile.ABSPATHDetected` | 3 | JUSTIFIED_IGNORE (FP) | Restore/quarantine writes to the live WP tree **are the purpose**. `-- restore/quarantine engine intentionally writes under ABSPATH; relocating to uploads/ would defeat the restore`. | S |
| `..._readfile` | 1 | JUSTIFIED_IGNORE | `assets/wpmgr-advanced-cache.php:253`. `-- advanced-cache drop-in streams the cached body before WordPress is loaded; readfile is the canonical low-memory emit`. | S |

### C2 — Database (415 findings) — **100% JUSTIFIED_IGNORE, annotate-only, zero behavior change**
Every interpolated identifier comes from one of three trusted sources only: (1) `$wpdb->prefix . Schema::CONST` / `self::TABLE`, (2) a core `$wpdb` property, or (3) an `information_schema.TABLES` round-trip (`validateTableName()`) + backtick-strip for the destructive table-action/orphan-delete/OPTIMIZE paths. Every value is bound via `prepare()` `%s/%d` or typed `$wpdb->insert/update`.

| Sniff | Count | Verdict | Canonical ignore | Effort |
|-------|------:|---------|------------------|:--:|
| `DirectDatabaseQuery.DirectQuery` | 127 | JUSTIFIED_IGNORE | `-- direct query on plugin-owned table; no core $wpdb helper exists`. Combine with NoCaching on the same line where both fire. | M |
| `DirectDatabaseQuery.NoCaching` | 119 | JUSTIFIED_IGNORE | `-- correctness requires a live read on owned table (anti-replay / locking / sliding-window count)`. **Adding `wp_cache_*` here is an ACTIVE regression** (defeats fail2ban window + replay shield). | M |
| `PreparedSQL.NotPrepared` | 55 | JUSTIFIED_IGNORE | `-- already prepared on the preceding line / static catalog query`. For OPTIMIZE/REPAIR/DROP/TRUNCATE identifier sites: `-- table identifier validated against information_schema; values N/A`. **Preserve `validateTableName()` + backtick-strip — that is the real injection guard.** | L |
| `PreparedSQL.InterpolatedNotPrepared` | 52 | JUSTIFIED_IGNORE | `-- interpolated identifier is prefix+constant (trusted); values bound via placeholders`. | M |
| `Security.DirectDB.UnescapedDBParameter` | 52 | JUSTIFIED_IGNORE (highest-signal — audit each) | `-- value is the output of $wpdb->prepare()` OR `-- identifier validated via information_schema + backtick-escaped`. All 52 reviewed; none attacker-controlled. | M |
| `RestrictedClasses.mysql__mysqli` | 3 | JUSTIFIED_IGNORE | Separate streaming `\mysqli` for dump/restore. `-- dedicated streaming connection for backup DB dump/restore; $wpdb buffers the full result set (OOM risk)`. | S |
| `DirectDatabaseQuery.SchemaChange` | 2 | JUSTIFIED_IGNORE | Triple-gated DROP + read-only SHOW CREATE. `-- intentional DROP of an orphaned non-core table, triple-gated; identifier validated`. | S |
| `PreparedSQLPlaceholders.UnfinishedPrepare` | 2 | JUSTIFIED_IGNORE | IN()-list placeholders built via `array_fill`. `-- placeholders are in the dynamically-built IN() list, filled via argument spread`. | S |
| `RestrictedFunctions.mysql_mysqli_report` | 1 | JUSTIFIED_IGNORE | `-- error mode for the dedicated streaming dump connection`. | S |
| `PreparedSQLPlaceholders.LikeWildcardsInQuery` | 1 | JUSTIFIED_IGNORE | Static literal `NOT LIKE 'wpmgr\_%'`. `-- static literal pattern, no bound value`. | S |
| `SlowDBQuery.slow_db_query_meta_value` | 1 | JUSTIFIED_IGNORE | `-- bounded migration/rewrite batch, not a request-path query`. | S |

> **Do-not-touch guardrail:** never remove the `information_schema` validation on the DROP/TRUNCATE/OPTIMIZE path, and never introduce `wp_cache_*` on replay-cache/login-protection reads. Add the destructive DB-cleaner DROP/TRUNCATE path to the human security-review checklist as defense-in-depth.

### C3 — Escape output (103 findings) — **MIXED, mechanical**

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `EscapeOutput.ExceptionNotEscaped` | 93 | REAL_FIX (preferred) | `throw new \RuntimeException(...)` messages go to log/SSE, not the browser — not XSS. Repo-wide: wrap interpolated values in `esc_html()` (e.g. `… . esc_html($absPath)`). **Exception:** in any early-boot/no-WP class where `esc_html()` may be undefined, annotate instead: `-- thrown exception; message goes to server log/SSE, not browser output`. | M |
| `EscapeOutput.OutputNotEscaped` | 10 | MIXED | `class-admin.php` ×8: inline `esc_url($actionUrl)` at each echo (already esc_url'd + nonce-gated forms; idempotent). `class-login-brand.php:216`: inline `esc_url($safeUrl)` in the CSS `url('…')`. `class-login-protection.php:748`: `$html` to `wp_die()` is pre-escaped via `htmlspecialchars()` on non-user values — **annotate, do not esc_html the markup blob**: `-- $html is pre-escaped via htmlspecialchars() on non-user-controlled values; outer markup is static`. | S |

### C4 — error_log (102 findings) — **REAL_FIX the sweep, 4 annotations**

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `DevelopmentFunctions.error_log_error_log` | 98 | **REAL_FIX (don't strip)** | Route all 98 calls through one debug-gated helper `includes/support/class-debug-log.php::write()` (writes only under `WP_DEBUG_LOG` or `WPMGR_DEBUG`); the single `error_log()` lives there behind one ignore: `-- gated diagnostic channel; only writes under WP_DEBUG_LOG or WPMGR_DEBUG`. Sweep: `error_log(` → `\WPMgr\Support\Debug_Log::write(`. 98 edits / 23 files in hot paths — assert `grep -c 'error_log('` drops to 0 outside the helper and re-run the restore/task-runner PHPUnit suite. | M (high touch) |
| `error_log_var_export` | 1 | JUSTIFIED_IGNORE (FP) | `class-dropin-installer.php:123` generates PHP source for the drop-in. `-- generating PHP source for the advanced-cache.php drop-in, not debug output`. | S |
| `error_log_set_error_handler` | 2 | JUSTIFIED_IGNORE (feature) | error-monitor / mu-trap. `-- error-monitor feature (Health tab), config-gated; not debug code`. | S |
| `error_log_debug_backtrace` | 1 | JUSTIFIED_IGNORE (feature) | `class-error-monitor.php:698`, `IGNORE_ARGS` + depth-cap 12. `-- error-monitor stack capture (IGNORE_ARGS, depth-capped); intentional feature`. | S |

> Secret hygiene already correct: `class-update-checker.php:259-265` never logs the presigned `package_url` bearer credential.

### C5 — Alt-funcs / misc (68 findings) — **REAL_FIX the swaps, JUSTIFIED_IGNORE the curl_multi uploader**

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `parse_url_parse_url` | 40 | **REAL_FIX** | Mechanical find/replace `parse_url(` → `wp_parse_url(` across 26 files (same signature, safe superset). **Watch:** `hostOf()/pathOf()` in `class-backup-transport.php` feed the canonical request signer — `wp_parse_url` returns identical path, so signatures still match. | S |
| `curl_*` (init/setopt/exec/getinfo/close) | 25 | **MIXED** | **`class-task-runner.php` `fetchUrl()` (single presigned GET, L689–705):** REAL_FIX → `wp_remote_get($url, ['timeout'=>60,'sslverify'=>true])` + `wp_remote_retrieve_response_code/body`; **keep the `file://` stream fallback** (ADR-051 e2e drives `file://` URLs). **`class-backup-transport.php` curl_multi presigned-PUT pool (L192–288):** JUSTIFIED_IGNORE — wrap the whole `uploadChunks()` method in one `// phpcs:disable WordPress.WP.AlternativeFunctions.curl_*` / `// phpcs:enable` pair: `-- concurrent presigned-PUT chunk uploader; WP_Http has no multi-handle concurrency for streamed multi-GB uploads`. **Do not serialize the multi-handle uploader.** | M |
| `rand_mt_rand` | 2 | REAL_FIX | `mt_rand()` → `wp_rand()` (temp-filename uniqueness; `class-cache-writer.php:438`, `class-asset-cache.php:123`). | S |
| `strip_tags_strip_tags` | 1 | REAL_FIX | `class-login-brand.php:395` → `wp_strip_all_tags($message)` (strictly safer). | S |

### C6 — Input / nonce (77 findings) — **REAL_FIX the unslash/sanitize hygiene, JUSTIFIED_IGNORE the nonce FPs**
Architecture confirmed: the entire machine API (`/wpmgr/v1/*`) is Ed25519-signed in `permission_callback` (`class-router.php`) — nonces correctly absent there and not in this cluster. The only browser-facing handlers (`class-admin.php` admin-post forms; admin-bar purge) already gate on `current_user_can('manage_options')` + `check_admin_referer()` via a `guard()` helper PHPCS can't trace.

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `NonceVerification.Missing` | 6 | JUSTIFIED_IGNORE (FP) | `class-admin.php` handlers gate via `guard()`. `-- nonce + capability verified in guard()/check_admin_referer() at top of handler`. Optional long-term: inline an explicit `check_admin_referer(self::ACTION_*)` before the `$_POST` read. | S |
| `NonceVerification.Recommended` | 5 | JUSTIFIED_IGNORE (FP) | admin-bar-purge (guarded + `safeSameHostUrl()`); cache-writer output-path read; advanced-cache drop-in pre-boot read. Per-site reasons in triage. | S |
| `ValidatedSanitizedInput.MissingUnslash` | 32 | **REAL_FIX** | Wrap each `$_SERVER`/`$_COOKIE` read in `wp_unslash()` before the existing cast/sanitize (`sanitize_text_field( wp_unslash( … ) )`). **CRITICAL:** on REQUEST_URI/HTTP_HOST cache-path reads use `sanitize_text_field(wp_unslash())`, **NOT `esc_url_raw`** (would desync the drop-in cache key from `CacheKey::build()` and silently break cache HITs). For the 7–8 `assets/wpmgr-advanced-cache.php` hits, `wp_unslash`/`sanitize_*` don't exist pre-WP → annotate: `-- advanced-cache drop-in runs pre-WP; wp_unslash/sanitize_* unavailable; values key-filtered via regex allowlist + path-traversal stripping`. | M |
| `ValidatedSanitizedInput.InputNotSanitized` | 34 | **MIXED → mostly REAL_FIX** | Same `wp_unslash()`+`sanitize_*` wrap clears both sniffs at once. admin-bar-purge `$_GET['url']`: `rawurldecode( sanitize_text_field( wp_unslash( $_GET['url'] ) ) )` (real guard is `safeSameHostUrl()`). advanced-cache hits → phpcs:ignore (regex allowlist IS the sanitization). | M |

### C7 — Long-tail (30 findings) — **3 real edits, rest annotations**

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `Squiz.PHP.DiscouragedFunctions.Discouraged` | 23 | JUSTIFIED_IGNORE | 22× `set_time_limit(0)` at the top of long backup/restore loops + 1× `@ini_set('zlib.output_compression','0')` (prevents double-gzip). All `@`-guarded. `-- long-running backup loop must not hit max_execution_time; @-guarded, no-op when disabled`. **Removing these corrupts mid-stream dumps/restores.** | S |
| `I18n.NonSingularStringLiteralText` | 2 | REAL_FIX | `class-stats-renderer.php:250` + `class-media-modal-injector.php:170` forward a `$text` var into `__()`. Either move literals to callers or drop `__()` (`return $text;`) for these single-locale internal labels. | S |
| `PrefixAllGlobals.NonPrefixedVariableFound` | 2 | MIXED | REAL_FIX: `assets/wpmgr-advanced-cache.php:30` `$config` leaks to global scope → rename to `$wpmgr_config` everywhere in that file (re-run cache E2E). FP: `class-error-monitor.php:243` is a sniff misparse of `$GLOBALS[self::GLOBAL_PENDING]` → `-- false positive: self:: is a class-constant accessor in a $GLOBALS subscript; the global key is the prefixed const wpmgr_agent_pending_errors`. | S |
| `Heredoc.NotAllowed` | 1 | **REAL_FIX (hard ERROR, no ignore path)** | `class-nginx-helper.php:65` — convert the `<<<NGINX … NGINX;` heredoc to `implode("\n", $lines)`/sprintf, preserving literal nginx `$` vars and the `$prefix` allowlist (L57). Add a unit test asserting byte-identical output. | M |
| `PrefixAllGlobals.DynamicHooknameFound` | 1 | JUSTIFIED_IGNORE | `class-purge.php:89` `do_action($hook, …)` — callers pass literal `wpmgr_`-prefixed names. `-- $hook is always a literal wpmgr_-prefixed action passed by in-class callers`. | S |
| `PrefixAllGlobals.NonPrefixedHooknameFound` | 1 | JUSTIFIED_IGNORE | `class-autologin-command.php:389` `do_action('wp_login', …)` — **core hook, MUST stay unprefixed** so 2FA/audit/session plugins observe the login. `-- 'wp_login' is a WordPress core action; must fire unprefixed`. | S |

### C8 — Meta blockers (10 findings) — **the gate; mostly §1/§2 decisions**

| Sniff | Count | Verdict | Recipe | Effort |
|-------|------:|---------|--------|:--:|
| `trademarked_term` | 3 | **REAL_FIX (B1)** | Slug/name rename — §2(a). Cascades into `PLUGIN_SLUG`/`PLUGIN_KEY` + manifest `slug` `hash_equals` (line 371) + all `wpmgr_*` keys + Makefile folder. **Not a phpcs:ignore — directory-policy reject.** | L |
| `plugin_updater_detected` | 1 | **MIXED (B2)** | Constant-guard `class-plugin.php:522` behind `WPMGR_WPORG_BUILD`. wp.org zip: hook never binds. self-host zip: keep. | M |
| `update_modification_detected` | 1 | **MIXED (B2)** | Physically exclude `class-update-checker.php` from the wp.org zip (nullable-type the property, conditional construction, Admin null-check, `--exclude` in rsync). | M |
| `missing_direct_file_access_protection` | 1 | JUSTIFIED_IGNORE (FP) | `assets/wpmgr-advanced-cache.php` is loaded before ABSPATH exists — **DO NOT add `if(!defined('ABSPATH'))exit;`** (breaks every cache HIT). Add `if (!defined('WP_CACHE')) { return; }` as the first line + `-- advanced-cache.php drop-in loaded by wp-settings.php before ABSPATH; guarded on WP_CACHE instead`. | S |
| `outdated_tested_upto_header` | 1 | REAL_FIX | readme `Tested up to:` → current stable WP. | S |
| `stable_tag_mismatch` | 1 | REAL_FIX | `Stable tag` → header `Version` (0.31.1); automate via Makefile sed. | S |
| `readme_mismatched_header_requires_php` | 1 | REAL_FIX | readme `Requires PHP: 8.1` (match header). | S |
| `unexpected_markdown_file` | 1 | REAL_FIX | Exclude `NOTICE.md` (+ `README.md`) from the zip; relocate the `matthiasmullie/minify` MIT attribution into a `== Third-party / Credits ==` readme section. | S |

---

## 7. Real security fixes

**There are ZERO genuine, exploitable security vulnerabilities in this codebase.** Every one of the 8 triage clusters returned `real_security_fixes: []` after the engineers read the actual flagged lines. This is explicit and consolidated here so no effort is wasted "hardening" working code:

- **No SQL injection.** Every interpolated SQL identifier comes from `$wpdb->prefix + constant`, a core `$wpdb` property, or an `information_schema`-validated + backtick-stripped table name. Every value is bound via `prepare()`/typed `$wpdb` arrays. (C2, C7)
- **No XSS / unescaped attacker output.** The 93 `ExceptionNotEscaped` go to logs/SSE, not the browser; the 10 `OutputNotEscaped` are pre-escaped fixed/admin values behind nonce-gated capability checks. (C3)
- **No missing-nonce CSRF.** All browser-facing state-changing handlers gate on `current_user_can('manage_options')` + `check_admin_referer()` via `guard()`; the machine API is Ed25519-signed. The 11 nonce findings are PHPCS false positives. (C6)
- **No unsanitized-input vuln.** The 66 input findings are read-only `$_SERVER`/`$_COOKIE`/`$_GET` reads, all neutralized downstream (FILTER_VALIDATE_IP, regex allowlist, `safeSameHostUrl()` host-check). Hygiene-only. (C6)
- **No remote-code-execution surface.** The agent accepts a **closed allow-list of signed named commands**; no `eval`, no remote `include`/`require`, no remote-PHP-then-include. The self-updater is **cryptographically sound** (Ed25519 manifest + replay + rollback + SSRF guards) — it is a **policy** blocker on wp.org, not a vulnerability. (C8)

**Defense-in-depth recommendations (not blockers):** add the destructive DB-cleaner `DROP`/`TRUNCATE`/`OPTIMIZE` table-action path (`db-table-action-command`, `db-orphan-delete-command`) to the human security-review checklist, and **audit the command dispatcher one final time** before zipping to confirm no generic "run arbitrary code/SQL/PHP" command exists.

> **Sniff-noise vs. real:** of 1016 findings, **0** are security vulnerabilities. The actual code edits are limited to mechanical swaps (parse_url, unlink/mkdir, mt_rand, strip_tags, curl→wp_remote_get), one error_log helper sweep, one heredoc conversion, one global-var rename, readme metadata, and the two §2 decisions. The remaining ~700+ findings are justified one-line ignores.

---

## 8. Recommended execution order

| Stage | Work | Effort | Owner (specialist) |
|------|------|--------|--------------------|
| **0. Decisions** | Lock §2: (a) slug = **`fleet-agent-for-wpmgr`** / "Fleet Agent for WPMgr"; (b) approve the dual-build constant-guard strategy; (c) confirm live `/terms` + `/privacy` + GPLv2-or-later declaration. **Nothing else proceeds until these are made.** | 0.5 day (human) | **You** |
| **1. Meta / readme / updater (B1–B4)** | Slug+text-domain rename across tree (incl. `PLUGIN_SLUG`/`PLUGIN_KEY` + CP manifest `slug` contract). `WPMGR_WPORG_BUILD` constant-guard + `?UpdateChecker` nullable refactor + `agent-zip-wporg` Makefile target (sed-inject constant + `--exclude`). readme.txt rewrite (all header fields, Stable-tag sed automation, sections, **disclosure section**, Third-party credits). License lines. Exclude NOTICE.md/README.md. | 2–3 days | **backend-architect** (rename/build graph) + **devops-engineer** (Makefile/zip) + **docs-writer** (readme/disclosure) |
| **2. Security re-confirm** | No fixes needed — but run the final command-dispatcher audit + add DB-cleaner DROP path to the review checklist. | 0.5 day | **security-reviewer** |
| **3. Mechanical REAL_FIX sweeps** | C5 `parse_url`→`wp_parse_url`, `mt_rand`→`wp_rand`, `strip_tags`→`wp_strip_all_tags`, task-runner `fetchUrl()`→`wp_remote_get`; C1 `unlink`→`wp_delete_file` + 0755 `mkdir`→`wp_mkdir_p` (honor the 0700 exception list); C3 `esc_html()` throw-wrap; C4 error_log helper sweep (23 files); C6 `wp_unslash()`+`sanitize_*` (mind the cache-key parity rule); C7 heredoc→implode (+ byte-identical test), i18n shims, `$config`→`$wpmgr_config`. Run PHPUnit (restore/task-runner) + cache E2E after. | 3–4 days | **wp-agent-engineer** |
| **4. Ignore-annotation pass** | Apply the canonical `// phpcs:ignore <Code> -- <reason>` lines from §6 across C1 (streaming/perm/atomic), C2 (all), C3 login-protection, C4 (4 annotations), C5 curl_multi `disable/enable` block, C6 nonce FPs + drop-in, C7 (4), C8 advanced-cache `WP_CACHE` guard. Lint-forbid bare `phpcs:ignore`. | 1.5 days | **wp-agent-engineer** |
| **5. Re-run Plugin Check** | Build the `agent-zip-wporg` zip; run PCP. Confirm **Security category is clean** and only justified ignores remain. Iterate on any residual ERROR (expect heredoc/meta to be the only ERROR class — should be gone). | 0.5 day | **wp-agent-engineer** |
| **6. Assets** | Produce `icon-128/256` + `icon.svg`, `banner-772x250` + `banner-1544x500`, `screenshot-1..N` from the Fleet Hub brand mark; place in SVN `assets/` (not the zip). | 1 day | **frontend-architect** (brand mark) + **docs-writer** (screenshot captions) |
| **7. Submit** | SVN layout (`trunk/` code+readme, `tags/<ver>/`, `assets/`); submit; **verify the slug in the confirmation email before approval**; reply to correct if wrong (pre-approval only). | 0.5 day | **devops-engineer** |

**Total: ~12–14 working days**, front-loaded on the rename/build-variant work (Stage 1) which is the real engineering cost; the ~1000 PHPCS findings collapse to ~4 days of mechanical sweeps + annotations because none require security remediation.

**One-line summary for the team:** *Permitted plugin class, zero security bugs — ship blocked only by a permanent slug rename off the leading "wp" and a build-variant that strips the self-updater for the wp.org zip; everything else is mechanical swaps plus justified one-line ignores.*