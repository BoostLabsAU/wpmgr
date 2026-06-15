# ADR-055: Autologin 2FA Bypass + 502 Hardening

**Status:** Accepted
**Date:** 2026-06-10
**Deciders:** Engineering

---

## Context

The one-click autologin route (`GET /wpmgr/v1/autologin`) was producing **502 Bad Gateway** errors on re-click, with two distinct root causes:

1. **Blocking consume timeout**: `CONSUME_TIMEOUT` was 10 s. A degraded control plane causes `wp_remote_post` to block for the full 10 s. On many shared hosts `request_terminate_timeout` is 30 s, leaving only 20 s of headroom. Under concurrent pressure this could still exceed the FPM kill threshold.

2. **Unguarded post-verify body**: Only the JWT verification step was wrapped in `try/catch`. The consume call, `issueAuthCookie()`, the `do_action('wp_login')` invocation, and the success hook all ran unguarded. A Throwable from any hooked third-party plugin became an FPM worker death (502) instead of a structured HTTP error.

3. **wp_login re-fire on re-click**: When the operator clicks the one-click login a second time (fresh valid JWT from the web UI on each click), the user is already logged in. Re-calling `issueAuthCookie()` and `do_action('wp_login')` over a live session triggers 2FA/security plugins that hook `wp_login` and tear down the session or show an interstitial, causing the 502.

4. **2FA challenge interstitials**: `wp_login` is the sole producer of the Solid Security interstitial (`ITSEC_Lib_Login_Interstitial`, registered at priority -1000, calls `show_interstitial()` + `die()`) and the official Two Factor plugin's session-teardown enforcement (hooked at `PHP_INT_MAX`, destroys sessions not marked as two-factor-verified and calls `show_two_factor_login()` + `exit`). Both plugins were triggered on every first-login autologin attempt where `wp_login` was fired.

---

## Decisions

### D1 — Reduce consume timeout to 5 s

`CONSUME_TIMEOUT` lowered from 10 to 5. A degraded CP now makes `wp_remote_post` return a `WP_Error` within 5 s, which `consume()` converts to a clean 410 `consume_rejected` response. The FPM `request_terminate_timeout` floor on most hosts (≥ 30 s) gives substantial headroom above this.

### D2 — Wrap the post-verify body in try/catch(\Throwable)

Steps 4–10 (consume, resolveUser, rolesAllowed, replay mark, issueAuthCookie, success hook, redirect) are enclosed in a single `try { ... } catch (\Throwable $e)` block. A catchable exception from any hooked plugin in this body returns `fail('autologin_error', 500, $jti)` — a structured JSON error — instead of an FPM 502. Hard `exit()`/`die()` calls inside a hook still cannot be caught; that is an accepted residual given the same-user fast-path and the wp_login suppression (D6) remove the most common triggers.

### D3 — Same-user fast-path skips cookie re-issue

After the replay mark succeeds (Step 7), if `is_user_logged_in()` is true AND `get_current_user_id() === $user->ID`, the call to `issueAuthCookie()` is skipped entirely. The success hook and `wp_safe_redirect` still execute normally. This handles the re-click case without touching cookie or session state.

Account-switch (token targets a different user than the current session) still proceeds through the full `issueAuthCookie()` path, as intended.

### D4 — 2FA bypass via request-scoped session markers and filters

The **authorization gate** for the autologin path is the Ed25519-signed single-use JWT verified against the CP-registered public key, plus the CP role allow-list. This is a stronger proof of operator intent than an interactive TOTP/push/email 2FA challenge. Requiring a second 2FA step would:
- Defeat the purpose of the feature (the operator is the one who minted the token).
- Block access to sites where the operator themselves has 2FA active.

The bypass is unconditional once the JWT+role gate passes. It is implemented using **request-scoped** mechanisms only: filters are added and removed within a single method call; nothing is persisted to options, user meta, transients, or global state.

#### Per-plugin technique

| Plugin | Mechanism | Scope |
|---|---|---|
| **Official Two Factor** (`wordpress/two-factor`) | `add_filter('attach_session_information', ...)` injects `two-factor-login` (timestamp) and `two-factor-provider` (user's configured provider from `_two_factor_provider` meta) into the auth cookie's session data before `wp_set_auth_cookie()`. This is a **convenience marker**: it allows the operator to edit the Two Factor settings screen without re-validating, and provides future-proofing. The primary interstitial bypass is D6 (not firing `wp_login`). If the user has no provider meta, no marker is injected. Filter removed immediately after `wp_set_auth_cookie()`. | Request-scoped filter |
| **WP 2FA (Melapress)** | `add_filter('wp_2fa_should_redirect_unconfigured', '__return_false')` — the plugin's documented public lever, suppresses its `admin_init` enforcement redirect. Orthogonal to D6; removed immediately after `wp_set_auth_cookie()`. | Request-scoped filter |
| **Wordfence Login Security** | Enforces via the `authenticate` filter chain. `wp_set_auth_cookie()` never passes through `authenticate`. Already bypassed; no action. | N/A |
| **miniOrange (common mode)** | Same as Wordfence — `authenticate` filter only. Already bypassed; no action. | N/A |

**Latent bug corrected:** The previous approach (inject markers + fire `wp_login`) did NOT reliably prevent the Two Factor plugin's teardown on a genuine first login because the teardown predicate is `is_current_user_session_two_factor()`, which checks the session store — but on a first login the cookie session is new and the marker injection races against the session being flushed. D6 (not firing `wp_login` at all) removes this bug class entirely. The same-user fast-path (D3) had masked the failure on re-clicks.

### D5 — SecuPress detection fast-path (superseded by D7 — see below)

*(Combined into D7.)*

### D6 — Do not fire `wp_login` on the autologin path

`wp_login` is not fired anywhere on the autologin path. This is the primary mechanism that defeats both the Solid Security interstitial and the official Two Factor interstitial.

**Mechanism (verified against WP.org SVN trunk):**
- `issueAuthCookie()` in WP core fires `do_action('wp_login', $userLogin, $user)` after setting the auth cookie.
- Solid Security's `ITSEC_Lib_Login_Interstitial` is registered on `wp_login` at priority -1000. It calls `ITSEC_Login_Interstitial_Session::create()` followed by `show_interstitial()` which ends in `die()`.
- The official Two Factor plugin hooks `wp_login` at `PHP_INT_MAX`. It calls `Two_Factor_Core::show_two_factor_login()` and `exit` for sessions it does not consider two-factor-verified.
- Neither plugin re-checks on `init` or `admin_init`. Not firing `wp_login` fully bypasses both with no residual admin gate.

`wp_login` is a post-authentication notification hook, not an authorization control. The authorization gate (Ed25519 JWT + CP role allow-list) is the authority.

**Audit/logging note:** Third-party plugins hooked on `wp_login` for login logging will not record autologin events. Operators should hook `wpmgr_autologin_success` for autologin-specific audit, or rely on CP-side audit (the consume callback returns an `audit_id`).

### D7 — SecuPress hard-bail (409, token not consumed)

SecuPress (free and pro) distrusts out-of-band cookies in its passwordless/magic-link flow and would loop the browser rather than accepting a cookie issued outside its own authentication path. Unlike the plugins handled by D4/D6, there is no filter lever or session marker that resolves the conflict.

Detection: if `{WP_PLUGIN_DIR}/secupress/secupress.php` or `.../secupress-pro/secupress-pro.php` exists, `securityPluginHardBail()` returns the slug `'secupress'`.

The bail fires **after** JWT verify and local replay check, but **before** the CP consume call and replay `mark()`. This means:
- A guaranteed-failing attempt does NOT burn the single-use token.
- The operator can disable SecuPress and retry with the same link.
- The response is 409 `wpmgr_autologin_unsupported_security_plugin`.

`securityPluginHardBail()` is `protected` (not `private`) so a test subclass can override it as a seam without real filesystem calls.

---

## Residual (accepted)

**Shield Security** (login-intent interstitial) may still present a challenge on the first page load after autologin. Shield reads its own internal session state (no public verified-session marker); we decline to forge that state. The autologin still succeeds and the auth cookie is valid — Shield's challenge is that plugin's own security policy, which we respect.

**Solid Security** and the **official Two Factor plugin** are now **resolved** by D6 (not firing `wp_login`).

---

## Consequences

**Positive:**
- Re-click no longer causes a 502 on sites with 2FA active.
- Re-click on a same-user session is a no-op (no cookie churn, no `wp_login` re-fire).
- Any catchable hook-plugin Throwable in the post-verify path returns a structured 500.
- Solid Security and Two Factor interstitials are fully bypassed (D6).
- WP 2FA users can reach wp-admin without a second challenge (D4).
- SecuPress conflicts surface as a clean 409 without burning the token (D7).
- All bypass mechanisms are strictly request-scoped — no persistent state changes.

**Negative / Accepted:**
- Hard `exit()`/`die()` in a hook still produces a 502 (uncatchable).
- Shield Security may still challenge on first page load after autologin.
- `CONSUME_TIMEOUT` lowered to 5 s means a very slow CP (> 5 s response) will return 410 instead of eventually succeeding. Operators with high-latency CP connections should ensure the CP is reachable within 5 s.
- Audit/login-logging plugins hooked on `wp_login` will not record the autologin. Operators should hook `wpmgr_autologin_success` and/or rely on CP-side audit (the consume callback returns an `audit_id`).

---

## Security review notes

- The bypass is conditional on the Ed25519 JWT gate passing. It cannot be triggered without a valid signed token from the CP.
- Session markers injected via `attach_session_information` are written into the WP session store (a `wp_usermeta` row for the auth cookie token), not into the cookie body directly. They are not forgeable from the client side.
- The `wp_2fa_should_redirect_unconfigured` filter only affects the current PHP request. It is removed before the method returns and cannot persist across requests.
- No user meta is modified (we only read `_two_factor_provider`, never write it).
- The same-user fast-path still requires a valid JWT, a successful CP consume, a resolved local user, and a passing role allow-list before the fast-path branch is taken. There is no short-circuit of the authorization gate.
- The SecuPress bail fires before the token is consumed, preserving the single-use invariant even for bailed attempts.
