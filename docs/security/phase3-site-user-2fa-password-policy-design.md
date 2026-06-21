# Security Suite Phase 3 — Site-user 2FA + Password Policy (build design)

> CP-first build design for the next Security Suite phase after file integrity (Phase 2).
> Goal: bring agency-managed sites' **WordPress users** under 2FA + password policy.
> Today only WPMgr **operators** (dashboard users) have 2FA (v0.50.0, ADR-056); managed-site
> WP users have none. Enforcement point moves from the CP login handler to the **agent** (the
> WP login flow), with the CP owning policy config, HIBP caching, and the operator escape hatch.
>
> Clean-room: techniques are described neutrally; no third-party code is copied or named in shipped artifacts.

---

## 0. Where this fits the existing machine

This phase **extends two existing, proven patterns** rather than inventing new ones:

1. **The operator-2FA crypto + provider pattern** (control plane) — mirror its TOTP/recovery-code/
   trusted-device logic, but relocate the *verification gate* to the agent.
   - `apps/api/internal/auth/twofactor/twofactor.go:29-72` — the thin stateless `SecondFactor` interface (`Kind/BeginLogin/FinishLogin/BeginRegistration/FinishRegistration`).
   - `apps/api/internal/auth/twofactor/totp.go:109-124` — TOTP gen via `github.com/pquerna/otp/totp` (SHA1, 6 digits, period 30); `totp.go:71-101` validate w/ exact-step return for replay-burn.
   - `apps/api/internal/auth/twofactor/recovery.go:12-74` — 10 Crockford-base32 codes, argon2id-hashed, constant-time no-break match.
   - `apps/api/internal/auth/twofa.go:392-411` (TOTP replay-burn), `:437-512` (recovery burn), `:262-276` (two-step **user-bound** trusted-device check — the B1 fix), `:359/:445/:561` (cross-challenge per-user/per-IP rate limit), `:1098-1111` (32-byte token, SHA-256 hash, `wpmgr_2fa_device` cookie, 30-day TTL).
   - `apps/api/migrations/20260706000000_m73_two_factor.sql` — the table + RLS shape.

2. **The `sync_security_hardening` signed-config-push pattern** (Phase 1, ADR-057) — mirror it exactly for a new `sync_security_policy` command.
   - **CP source of truth:** `apps/api/migrations/20260709000000_m76_security_hardening.sql` — `site_security_hardening_config` (typed columns, all default OFF, CHECK enums, tenant + agent RLS).
   - **CP contract:** `apps/api/internal/agentcmd/hardening_contract.go:1-95` — authoritative CP→agent JSON shapes; full snapshot replaced atomically on every push (no diffing).
   - **CP client:** `apps/api/internal/agentcmd/client.go:176` `SyncSecurityHardening(...)` mints an EdDSA JWT (`cmd`, `aud=siteId`, `exp≤now+60s`, single-use `jti`) over the SSRF-hardened transport.
   - **CP service/repo/handler:** `apps/api/internal/security/{service,repo,handler,model}.go` — `Service.SetHardeningClient` (`service.go:109`), best-effort push after save (`service.go:336,567`).
   - **Agent receive→validate→apply→persist:** `apps/agent/includes/commands/class-sync-security-hardening-command.php:87-131` → `HardeningConfig::fromArray` (`class-hardening-config.php:148-186`, every field default-OFF, control-char rejection) → `HardeningModule::applyConfig` (`class-hardening-module.php:49-82`, JSON in a wp-option, never-fatal try/catch).
   - **Agent command plumbing for free:** `interface-command-interface.php` contract; Router dispatch `class-router.php:54-94`; **Ed25519 verify** (`Connector::verifyCommand`, `class-connector.php:193-221` — verifies signature FIRST, then `aud`/`cmd`/`exp`/single-use `jti`).

3. **The autologin lockout-bypass pattern** — the single most important safety primitive to reuse.
   - `apps/agent/includes/commands/class-autologin-command.php:498-557` `issueAuthCookie()`: `wp_clear_auth_cookie()` → `wp_set_current_user()` → `wp_set_auth_cookie()` and **deliberately never fires `do_action('wp_login')`**, the sole trigger for other plugins' 2FA interstitials (docblock `:467-494`). Phase 3's own interstitial MUST be written so this same path bypasses it (see §3.7, §6).

> **Decision recorded:** there is **no User-Groups concept in the CP today** — RBAC is role-based only (`internal/authz`). Phase 3 introduces site-user **policy groups** as a new, agent-resolved concept (membership resolved by WP role on the site), with the CP storing only the *group→policy* mapping. We do **not** try to enumerate every WP user in the CP.

---

## 1. Policy model

One **policy row per site** (extends the m76 config model), plus an optional set of **per-group overrides** keyed by WP role. The CP is the source of truth; the agent mirrors it via a new signed `sync_security_policy` command (full-snapshot replace, mirroring `sync_security_hardening`).

### 1.1 Site-level policy knobs

| Knob | Type | Default | Meaning |
|---|---|---|---|
| `two_factor_enabled` | bool | false | Master switch for the site-user 2FA subsystem. |
| `two_factor_methods` | text[] | `{totp,email,backup}` | Allowed providers (subset of `totp`/`email`/`backup`). |
| `two_factor_required_roles` | text[] | `{}` | WP roles that **must** use 2FA (e.g. `administrator`,`editor`). Empty = optional for all. |
| `two_factor_grace_logins` | int | 3 | Allowed logins before a *required* but unenrolled user is forced into onboarding (0 = force immediately). |
| `two_factor_remember_device_days` | int | 30 | Trusted-device TTL; 0 disables remember-device. |
| `block_xmlrpc_for_2fa_users` | bool | true | Reject password-based XML-RPC for any user with 2FA configured. |
| `password_min_zxcvbn_score` | int (0–4) | 0 (off) | Minimum zxcvbn score on set/change/reset. 0 = disabled. |
| `password_min_zxcvbn_roles` | text[] | `{}` | Roles the strength rule applies to (empty = all). |
| `password_block_compromised` | bool | false | Reject HIBP-breached passwords on set/change. |
| `password_reuse_block_count` | int | 0 | Block reusing the last N passwords (0 = off; 1 = current only). |
| `password_max_age_days` | int | 0 | Force change after N days since last change (0 = off). |
| `password_expiry_roles` | text[] | `{}` | Roles the expiry rule applies to (empty = all). |
| `hide_backend_enabled` | bool | false | Master switch for the secret login slug. |
| `hide_backend_slug` | text | `''` | Secret login slug (e.g. `my-secret-login`); validated `^[a-z0-9-]{4,64}$`, must not collide with reserved paths. |
| `hide_backend_redirect` | text | `''` | Where to send logged-out hits on the canonical `wp-login`/`wp-admin` (empty = 404/403). |

### 1.2 Per-group overrides (`site_security_policy_groups`)

A group is **(site, role) → policy override**. Membership is resolved **on the site** by the agent from the user's WP role(s) — the CP never enumerates WP users. Each group row may override a subset: `require_2fa bool`, `allowed_methods text[]`, `min_zxcvbn_score int`, `block_compromised bool`, `max_age_days int`. Resolution: a user inherits the **strictest** matching group, falling back to the site-level knobs (union semantics — if any matching group requires 2FA, 2FA is required).

> Why role-keyed, not user-keyed: it matches how the reference's group matcher resolves (role / min-role / canonical-role union), keeps the CP free of per-site user inventory, and survives WP user churn. A future phase can add explicit user-id membership pushed from a CP user-directory if agencies ask.

---

## 2. Data model (new migration **m78** — next after m77 file-integrity)

`apps/api/migrations/20260711000000_m78_site_security_policy.sql` (mirror the m76 idempotent DO-block + RLS style exactly).

### 2.1 `site_security_policy` (one row per site, PK = `site_id`)
Typed columns for every site-level knob in §1.1. `text[]` for role/method lists; `int` for counts/days with CHECK ranges (`password_min_zxcvbn_score` CHECK 0–4); `tenant_id uuid NOT NULL` FK; `updated_at`, `actor_type`, `actor_id`. **All knobs default to the OFF/safe value.**
RLS (copy m76 verbatim): ENABLE + FORCE; `_tenant_isolation` (`app.tenant_id` GUC) + `_agent` (`app.agent='on'`). Tenant index on `tenant_id`.

### 2.2 `site_security_policy_groups` (per-group overrides)
`id uuid PK`, `tenant_id`, `site_id`, `role text NOT NULL`, nullable override columns (`require_2fa`, `allowed_methods text[]`, `min_zxcvbn_score`, `block_compromised`, `max_age_days`), `created_at`. UNIQUE `(site_id, role)`. Same RLS pair + tenant/site index.

### 2.3 `hibp_breach_cache` (CP-side HIBP range cache — **NOT tenant-scoped**)
`prefix char(5) PRIMARY KEY`, `body text NOT NULL` (the raw `SUFFIX:COUNT` lines), `fetched_at timestamptz`. This is shared infra (global breach corpus, not tenant data) — **no RLS / `app.agent`-only read**, written by a CP background fetch. ~16^5 = 1,048,576 possible prefixes; we only cache prefixes actually queried, TTL ~30 days. (See §5 for the decision to cache CP-side.)

### 2.4 What is **NOT** in the CP
Per-user 2FA enrollment state (secrets, recovery codes, trusted devices, "must change password" flags, last-changed timestamps, password-history hashes) all live **on the WP site in encrypted user-meta** (§4). The CP stores only **policy** + an **aggregate enrollment report** (counts per role: enrolled / required-not-enrolled), pushed up by the agent in diagnostics for the dashboard "which users have 2FA" surface — never the secrets.

---

## 3. Agent enforcement design (apps/agent)

A new module `apps/agent/includes/security/class-site-2fa-module.php` + `class-password-policy-module.php` + `class-hide-backend-module.php`, plus a provider package mirroring the operator `SecondFactor` shape. Config arrives via a new `SyncSecurityPolicyCommand` and is stored as JSON in a wp-option `wpmgr_security_policy` (mirror `HardeningModule::OPTION_CONFIG`). **Every module defaults fully inert and every apply path is try/catch-guarded** (mirror `HardeningConfig::defaults`) so a malformed push can never brick login.

### 3.1 Pluggable 2FA provider architecture (PHP)
A thin `Two_Factor_Provider` interface (clean-room, mirroring our operator `SecondFactor` thinness, not the reference's class):
```
interface SiteTwoFactorProvider {
    public function key(): string;                  // 'totp' | 'email' | 'backup'
    public function label(): string;
    public function is_configured_for(WP_User $u): bool;
    public function render_form(WP_User $u): string; // the interstitial code-entry HTML
    public function validate(WP_User $u, array $input): bool; // reads its own POST field
    public function pre_render(WP_User $u): void;    // side-effect (email provider sends here)
}
```
- **TOTP provider:** RFC-6238, SHA1 / 6-digit / period-30, **±1 step skew** to match operator-2FA defaults and authenticator-app compatibility. Pure-PHP HOTP truncation (no new dependency); secret = 160-bit random, base32-encoded, **encrypted in user-meta** via the agent's age crypto (`class-age-crypto.php`, §4). Constant-time compare (`hash_equals`). Treat a decrypt failure as "configured-but-unusable" (never silent bypass). Local QR (we already render headless in media-encoder; agent emits the `otpauth://` URI + a data-URI QR).
- **Email provider:** generate an 8-digit numeric code, store only `wp_hash(code)` + issued-time in user-meta (multiple outstanding allowed), 15-minute TTL expired-on-read, **burn all on success**. Send via the **per-site SMTP** already shipped (the per-site email/SMTP feature) — see §8 deliverability risk.
- **Backup-codes provider:** 10 single-use codes, each `wp_hash_password`-hashed in user-meta, shown once, count-down + delete-on-use, "regenerate" replaces the set.

### 3.2 The login interstitial (the core technique)
**Do NOT wedge the prompt into the `authenticate` filter.** Build a small **post-`wp_login` interstitial** (clean-room of the framework technique):
1. Hook `wp_login` at a **very early priority (e.g. -1000)**. At this point primary password auth has succeeded and the `WP_User` exists.
2. **Capture the just-issued auth session token** via the `auth_cookie` filter, then **destroy that session token + clear the auth cookie** — so there is *no* window where a half-authenticated cookie is valid.
3. Create a **server-side signed interstitial session** stored as a single user-meta row: `{uuid (server-only secret), user_id, current_step, created_at, redirect_to, remember_me}`. The browser carries only three non-secret hidden form fields between requests: `user_id`, `session_meta_id`, and a `token = hash_hmac('sha256', "{uid}|{mid}|{created}|{uuid}", AGENT_SECRET)`. Expiry **1 hour**; `hash_equals` on verify; user-id must match. (Mirror our operator challenge TTL/attempt discipline.)
4. Render the chosen provider's `render_form()` and `die()` before WP finishes the real login.
5. On submit: re-verify the signed session, call provider `validate()`. On `WP_Error` re-render the form (failed code stays on the form, counts toward attempt cap). On success: `wp_set_auth_cookie()` to mint the real session, optionally set the remember-device cookie, then redirect. **Re-fire `wp_login`** only after success to let normal login side-effects run — guarded so our own interstitial does not recurse (set a request-scoped "already verified" flag).
6. **`login_init` re-show guard:** if a logged-out user with a pending interstitial session loads any login page, re-show the interstitial so they cannot navigate away around it.

### 3.3 Per-group enforcement
At interstitial time, resolve the user's required-ness: union of site-level `two_factor_required_roles` and any matching `site_security_policy_groups` row for the user's WP role(s). For a **required-but-unenrolled** user, inject the **email provider** as a forced fallback (so a required user is never un-promptable), and after `two_factor_grace_logins` route them into the **forced-enrollment (onboarding) interstitial** step instead of a code form.

### 3.4 Password requirements engine (PHP)
Clean-room of the **evaluate/validate split + user-meta cache** (the most valuable idea):
- **Hooks that gate password set:** `user_profile_update_errors` (priority 0), `validate_password_reset`, `registration_errors`, and `wp_authenticate_user` (priority 999) where plaintext is briefly available at login.
- **Two-phase:** `evaluate(plaintext, user)` needs the transient plaintext → cache the result (zxcvbn score, HIBP count) in user-meta keyed per requirement, stamped with eval-time. `validate(cached, user, policy)` runs later off the cache without plaintext, so group-scoped rules re-check at every login cheaply.
- **Requirements:** `strength` (zxcvbn, vendored zxcvbn-php, score threshold from policy, default 4 when enabled; penalty dictionary built from user_login/email/display_name/site fields), `compromised` (HIBP, §5), `reuse` (store a rolling array of the last N `wp_hash_password` digests in user-meta, loop `wp_check_password`), `expiration` (compare `now - last_changed > max_age_days`).
- **Last-changed tracking:** on `profile_update`/`password_reset`, if `user_pass` changed, set `wpmgr_pw_last_changed` (GMT now), clear the forced-change flag, push the old hash onto the reuse-history array (trim to N), and clear cached evaluations.

### 3.5 Forced-change interstitial
Reuse the §3.2 interstitial framework with a step `update-password`:
- Flag set in user-meta `wpmgr_pw_change_required = <reason code>` at `wp_login` (priority **-2000**, before the 2FA interstitial at -1000), when any enabled requirement fails (expiry, or a freshly-failing strength/HIBP re-check), or by an operator force-flag pushed from the CP.
- The interstitial holds the session (capture+destroy token, as §3.2), renders a native WP password field, and on submit re-runs full validation → `wp_update_user` → clears the flag.

### 3.6 HIBP enforcement point
Agent calls the **CP proxy** `GET /api/v1/security/hibp/range/{prefix}` (signed agent-auth) at password-set time, which returns the cached `SUFFIX:COUNT` body; the agent matches the suffix **locally** and never sends the password or full hash anywhere. (CP-cache decision in §5.)

### 3.7 Hide-backend secret slug
Clean-room of the routing technique:
- Intercept at **`setup_theme`** (before WP routes to wp-login.php / wp-admin), bail for REST/cron/CLI.
- Compare the request path against the secret `hide_backend_slug`:
  - path == slug → internally route to the real `wp-login.php` **with an access token** set as a short-lived (1h) HttpOnly+Secure cookie so the multi-request login dance keeps working.
  - canonical `wp-login`/`wp-admin` for a **logged-out, untokened** visitor → **404 or redirect** (`hide_backend_redirect`); never reveal. Allow `postpass`/registration/REST as needed.
- **Rewrite generated URLs:** filter `site_url`/`network_site_url`/`admin_url`/`wp_redirect`/notification URLs to inject the token query-arg so legitimate links + emails never break; remove WP's `/admin`,`/login` convenience redirects.
- **Lockout-avoidance:** the slug itself doubles as the access token (accepted via query-arg OR the short-lived cookie); **the WAF private-IP bypass and the autologin path must remain reachable** (autologin posts to `wpmgr/v1/autologin`, a REST route — already exempt by the REST bail).

### 3.8 Login-flow hooks already present (do not collide)
- `class-login-protection.php:225` `authenticate@30` (brute-force block-only) — our interstitial runs *after* on `wp_login`, no conflict.
- `class-hardening-module.php:274` `restrict_login_identifier` removes core `authenticate` filters — orthogonal.
- `class-activity-log.php:141` `wp_login@10` — our interstitial's deferred `wp_login` re-fire (§3.2 step 5) keeps audit logging correct; the forced-change/2FA verification itself should also emit a dedicated activity-log event.

---

## 4. Secret storage on the site

| Artifact | Storage | Protection |
|---|---|---|
| TOTP secret | user-meta `wpmgr_2fa_totp_secret` | base32 plaintext **age-encrypted** via `class-age-crypto.php` (the enrollment Keystore identity), stored as ciphertext. Decrypt-fail = "configured-but-unusable". |
| Email codes | user-meta `wpmgr_2fa_email_token` rows | only `wp_hash(code)` + issued-time; 15-min TTL; burn-all on success. |
| Backup codes | user-meta `wpmgr_2fa_backup_codes` | array of `wp_hash_password` digests; show-once; delete-on-use. |
| Trusted device | cookie `wpmgr_2fa_device` + user-meta | cookie = 32-byte random token (hex); store only `sha256(token)` + `expires` + (optional) device fingerprint, **one row per device, bound to user_id**; rotate on use; **nuke all on password change**. TTL from `two_factor_remember_device_days` (default 30). |
| Pending interstitial | user-meta `wpmgr_2fa_session` | server-only `uuid` secret; HMAC signature carried client-side; 1h TTL. |
| Password reuse-history | user-meta `wpmgr_pw_history` | rolling array of last N `wp_hash_password` digests. |
| Last-changed / must-change | user-meta `wpmgr_pw_last_changed` / `wpmgr_pw_change_required` | timestamps / reason code. |

> **Reuse the operator B1 fix:** the trusted-device cookie MUST be validated user-bound — look up by `sha256(token)`, then assert the row's `user_id == authenticating user` before honoring it (mirror `twofa.go:262-276`). A fingerprint match (IP/UA weighted) is a Phase 4 hardening, not required for v1.

---

## 5. HIBP integration (decision: **CP proxy + cache**)

HIBP Pwned Passwords range API is **free and keyless** (confirmed): `GET https://api.pwnedpasswords.com/range/{first5SHA1}` returns newline-delimited `SUFFIX:COUNT` (35-char suffix). The password/full hash never leaves the caller (k-anonymity). We add the **`Add-Padding: true`** header (the reference omits it) and discard zero-count decoy lines for stronger hygiene.

**Decision: run the range query from the CP, cache it, serve a signed proxy to agents.** Rationale:
- One fleet-wide cache (`hibp_breach_cache`, §2.3) instead of N per-site outbound calls; agents on locked-down hosts that can't reach the public internet still get the check.
- The agent never makes an arbitrary outbound HTTP call (keeps the agent's egress surface minimal).
- The agent still does the **local suffix match**, so the CP cache holds only public breach data, never anything site-specific.

**CP endpoint:** `GET /api/v1/security/hibp/range/{prefix}` (agent-authenticated) → check cache (TTL 30d) → on miss, fetch from HIBP with `Add-Padding`, store, return body. (A self-hosted full corpus ~25–40GB is an optional later optimization; the on-demand cache is enough for v1.)

---

## 6. CP↔agent contract (`agentcmd/security_policy_contract.go`)

New authoritative contract file mirroring `hardening_contract.go`. Command `sync_security_policy`:
```
POST {site_url}/wp-json/wpmgr/v1/command/sync_security_policy
Bearer <EdDSA JWT: cmd="sync_security_policy", aud=siteId, exp≤now+60s, single-use jti>
Body: SecurityPolicyRequest {
  policy: { ...all §1.1 site-level knobs... },
  groups: [ { role, require_2fa?, allowed_methods?, min_zxcvbn_score?, block_compromised?, max_age_days? } ],
  force_password_change: [ { user_login, reason } ]   // operator escape: flag specific users
}
Response 200: { ok: bool, detail, enrollment_summary?: { per_role: {enrolled, required, total} } }
```
- Full-snapshot replace on every push (no diffing), exactly like `sync_security_hardening`.
- CP client method `SyncSecurityPolicy(ctx, siteID, siteURL, req)` on `agentcmd.Client` (mirror `client.go:176`); wired into the security service via `SetPolicyClient` (mirror `service.go:109`), best-effort push after save.
- Agent: new `SyncSecurityPolicyCommand` registered in `class-plugin.php` (~`:1238`), validated through a `SecurityPolicy::fromArray` value object (default-OFF, enum-coerce, reject control chars / bad slug), applied + persisted to `wpmgr_security_policy`, never-fatal.
- **Enrollment report up:** agent piggybacks `enrollment_summary` (counts only, no secrets/usernames beyond what diagnostics already carry) onto its diagnostics push so the dashboard can show "which roles have 2FA coverage."

### 6.1 Operator escape hatch (the lockout antidote)
A dedicated signed command `force_user_password_unlock` / `disable_user_2fa` (or via the same `sync_security_policy` `force_password_change` + a `clear_2fa_for[]` field), **plus** the existing **autologin** path which already bypasses all interstitials (`class-autologin-command.php:498-557`, no `wp_login`). The operator can always:
1. One-click **autologin** into any managed site (bypasses 2FA + hide-backend, since autologin is a REST route + sets the cookie directly), then
2. From the CP, **disable the policy** or **clear a specific user's 2FA / force-reset their password** via a signed command.

This is the structural guarantee that a misconfigured policy can **never** permanently lock out the only admin.

---

## 7. Dashboard surface (apps/web)

- **Per-site Security → Authentication policy** page: site-level knobs (§1.1) + a per-role overrides table (§1.2), saved via the CP security handler (mirror the existing hardening panel). Live zxcvbn-style copy, slug validation, "test slug reachable" hint.
- **2FA coverage card:** from the agent `enrollment_summary` — per role: enrolled / required-but-not / total, with a "send reminder" action (triggers the onboarding nudge).
- **Compromised-password / weak-password flags:** surface the cached strength/HIBP evaluation counts per role (no plaintext, no per-user secret).
- **Operator panic buttons:** "Disable 2FA policy for this site" and "Clear 2FA for user X" (calls the §6.1 escape commands) — gated to operator/admin RBAC.
- Place under **Insights/Security** sidebar group (consistent with the v0.51.x nav reorg).

---

## 8. Risks + mitigations

| # | Risk | Mitigation |
|---|---|---|
| 1 | **Admin lock-out (the #1 risk).** A misconfigured 2FA/expiry/hide-backend policy locks the only admin out. | (a) **Autologin always bypasses** (REST route, sets cookie directly, no `wp_login`, no hide-backend REST exemption needed). (b) Operator CP escape commands disable policy / clear a user's 2FA / force-reset (§6.1). (c) Required-but-unenrolled users get a forced-email fallback provider + grace logins, never a hard wall. (d) Backup codes (show-once). (e) Every agent apply path is default-OFF + try/catch (malformed push can't brick login). (f) A wp-config constant (e.g. `define('WPMGR_DISABLE_SITE_2FA', true)`) as the classic last-resort recovery. |
| 2 | **2FA bypass.** Half-authenticated cookie window; trusted-device cookie not user-bound (the operator B1 bug). | Capture + **destroy the auth session token** at `wp_login` before rendering the interstitial (no valid cookie exists until verify). Trusted-device validated **user-bound** (look up by `sha256(token)`, assert `user_id` match — mirror `twofa.go:262-276`). Replay-burn email/backup codes; per-challenge attempt cap + per-user/per-IP rate limit (mirror `twofa.go:359/445/561`). |
| 3 | **Interstitial timing — `do_action('wp_login')`.** Our interstitial fires *on* `wp_login`; firing `wp_login` ourselves (or another plugin doing so) can trip foreign 2FA interstitials, and our autologin must not trip ours. | Hook `wp_login@-1000` for the interstitial; **defer the real `wp_login` re-fire until after our verify** (request-scoped "already verified" guard prevents recursion). **Autologin keeps never firing `wp_login`** (`class-autologin-command.php:467-494`), so it bypasses our interstitial by construction — the same mechanism that already bypasses other plugins' interstitials. |
| 4 | **Email-2FA deliverability.** If email codes don't arrive, a required user is locked out. | Send via the **per-site SMTP** feature (already shipped) for reliable delivery + the cross-site email log for debugging; backup codes as the offline fallback; operator escape (§6.1) as the floor. Never make email the *only* allowed method for a required role without backup codes also enabled (validate this in the CP). |
| 5 | **Brute-forcing the interstitial.** Attacker who has the password guesses the 2FA code. | Per-challenge attempt cap (e.g. 5) → expire session; per-user + per-IP sliding rate limit on the verify endpoint (mirror operator `twofa.go` S2 limits); TOTP replay-burn (exact-step); email/backup single-use burn. Tie into the existing brute-force login-events table for cross-fleet IP reputation. |
| 6 | **Hide-backend self-lockout / breaking integrations.** Hiding wp-login breaks bookmarks, webhooks, REST, the agent itself. | Token-as-slug + short-lived cookie keeps the login dance working; rewrite all generated URLs; **REST/cron/CLI bail at `setup_theme`** so the agent's `wpmgr/v1` routes + autologin are never blocked; WAF private-IP bypass unaffected. |
| 7 | **HIBP availability / privacy.** HIBP down, or worry about leaking password data. | CP cache absorbs outages (30d TTL); k-anonymity means only a 5-char SHA1 prefix ever leaves, plus `Add-Padding`. Compromised-check failure must **fail-open** (don't block a legitimate password set because HIBP is unreachable) — log it. |
| 8 | **RLS scope mismatch.** Operator-2FA tables use `app.agent='on'` (pre-auth); these are tenant-scoped. | Use the **m76 tenant + agent RLS pair** for `site_security_policy*` (operator path = `app.tenant_id`, agent push path = `app.agent='on'`). The HIBP cache is global infra (no tenant RLS). |

---

## 9. Phasing (CP-first, ordered, with specialist owners)

> **This phase is HIGH security-review priority** — it touches the WP auth flow, gates login, and stores second-factor secrets. `security-reviewer` reviews every PR before merge (mandatory per the standing routing rule). Build CP-first, deploy all layers, every feature ships its named test + docs DoD.

**P3.0 — Contract + data model (backend-architect)**
- m78 migration (`site_security_policy`, `site_security_policy_groups`, `hibp_breach_cache`) — mirror m76 RLS exactly; `sqlc generate` (no hand-edit).
- `agentcmd/security_policy_contract.go` + `client.SyncSecurityPolicy` + `Service.SetPolicyClient`.
- CP `security` service/repo/handler extensions + REST routes (policy CRUD, escape commands, HIBP proxy endpoint).
- *Gate:* contract frozen + reviewed before agent work starts.

**P3.1 — HIBP proxy + cache (backend-architect)**
- `GET /api/v1/security/hibp/range/{prefix}` with cache + `Add-Padding` + fail-open. Named test: cache hit/miss + suffix-match correctness.

**P3.2 — Agent: provider arch + password engine (wp-agent-engineer)**
- `SyncSecurityPolicyCommand` + `SecurityPolicy` value object (default-OFF, validation).
- 2FA providers (TOTP/email/backup) + age-encrypted secret storage.
- Password requirements engine (evaluate/validate split, strength/HIBP/reuse/expiry) + last-changed tracking.
- Vendored zxcvbn-php (tech-stack-researcher confirms lib + license before vendoring).

**P3.3 — Agent: interstitial + enforcement (wp-agent-engineer)**
- Post-`wp_login` interstitial framework (capture+destroy token, signed session, multi-step, `login_init` re-show).
- 2FA verify + forced-change step + per-group required-ness + onboarding step.
- `block_xmlrpc_for_2fa_users`; trusted-device (user-bound, rotate, nuke-on-pw-change).
- **Autologin + WAF bypass verified intact** (named regression test: autologin must reach wp-admin with policy fully ON).

**P3.4 — Agent: hide-backend (wp-agent-engineer)**
- `setup_theme` routing, token-as-slug + cookie, URL rewriting, REST/cron/CLI bail, autologin reachability test.

**P3.5 — Dashboard (frontend-architect)**
- Authentication-policy page (site + per-role), 2FA coverage card, operator panic buttons, slug validation. Impeccable gate.

**P3.6 — Security review + hardening (security-reviewer, blocking)**
- Full review of the auth-flow hooks, secret storage, interstitial session signing, lockout-proofing, hide-backend bypass surface, HIBP fail-open, trusted-device user-binding (B1), brute-force on the interstitial. **Must-fix list before any release.**

**P3.7 — Ship (devops-engineer + docs-writer)**
- media-encoder/api/web images + agent release (deploy-all-layers checklist); CHANGELOG + landing/README (docs DoD); m78 auto-on-boot.

---

## 10. Open decisions to confirm before P3.0

1. **zxcvbn-php library** — confirm a maintained, GPL-compatible pure-PHP port to vendor (route to `tech-stack-researcher`).
2. **Email-2FA as sole method** — block in CP validation that a required role has *only* `email` allowed without `backup` (deliverability floor)?
3. **Reuse-history depth default** — N (suggest 5) when `password_reuse_block_count` enabled but unset.
4. **wp-config recovery constant name** — `WPMGR_DISABLE_SITE_2FA` (and a sibling for hide-backend?).
5. **ADR number** — this phase warrants a new ADR (suggest ADR-059, after the file-integrity ADR) capturing the enforcement-moves-to-agent decision + the lockout-proofing invariants.
