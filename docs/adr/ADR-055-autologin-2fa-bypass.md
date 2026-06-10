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

4. **2FA challenge loop**: The official Two Factor plugin hooks `wp_login` at `PHP_INT_MAX` and tears down sessions that are not marked as already two-factor–verified. Any autologin attempt on a site with Two Factor active would be immediately invalidated.

---

## Decisions

### D1 — Reduce consume timeout to 5 s

`CONSUME_TIMEOUT` lowered from 10 to 5. A degraded CP now makes `wp_remote_post` return a `WP_Error` within 5 s, which `consume()` converts to a clean 410 `consume_rejected` response. The FPM `request_terminate_timeout` floor on most hosts (≥ 30 s) gives substantial headroom above this.

### D2 — Wrap the post-verify body in try/catch(\Throwable)

Steps 4–10 (consume, resolveUser, rolesAllowed, replay mark, issueAuthCookie, success hook, redirect) are enclosed in a single `try { ... } catch (\Throwable $e)` block. A catchable exception from any hooked plugin in this body returns `fail('autologin_error', 500, $jti)` — a structured JSON error — instead of an FPM 502. Hard `exit()`/`die()` calls inside a hook still cannot be caught; that is an accepted residual given the same-user fast-path removes the most common trigger.

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
| **Official Two Factor** (`wordpress/two-factor`) | `add_filter('attach_session_information', ...)` injects `two-factor-login` (timestamp) and `two-factor-provider` (user's configured provider from `_two_factor_provider` meta) into the auth cookie's session data before `wp_set_auth_cookie()`. `Two_Factor_Core::is_current_user_session_two_factor()` reads these exact fields. If the user has no provider meta, no marker is injected. Filter removed immediately after `wp_set_auth_cookie()`. | Request-scoped filter |
| **WP 2FA (Melapress)** | `add_filter('wp_2fa_should_redirect_unconfigured', '__return_false')` — the plugin's documented public lever, suppresses its `admin_init` enforcement redirect. Removed immediately after `wp_set_auth_cookie()`. | Request-scoped filter |
| **Wordfence Login Security** | Enforces via the `authenticate` filter chain. `wp_set_auth_cookie()` never passes through `authenticate`. Already bypassed; no action. | N/A |
| **miniOrange (common mode)** | Same as Wordfence — `authenticate` filter only. Already bypassed; no action. | N/A |

#### `wp_login` ordering

`do_action('wp_login', ...)` is fired **after** `wp_set_auth_cookie()` (and after the session markers are written into the cookie session). The Two Factor plugin hooks `wp_login` at `PHP_INT_MAX` priority and checks `is_current_user_session_two_factor()` against the cookie's session data. Firing after the markers are written means the plugin finds a verified session and does not tear it down.

#### Residual (accepted)

**Solid Security** (`itsec_login_interstitial`) and **Shield Security** (login-intent) use post-login interstitials enforced on the subsequent page load, reading their own internal session state. There is no public, documented verified-session marker for these plugins. We do not forge their internal state. Sites using these plugins may still see a 2FA challenge on the first page after autologin. This is documented here as an **accepted residual**: the autologin still succeeds; the 2FA step the user encounters is that plugin's own security policy, which we respect.

---

## Consequences

**Positive:**
- Re-click no longer causes a 502 on sites with 2FA active.
- Re-click on a same-user session is a no-op (no cookie churn, no `wp_login` re-fire).
- Any catchable hook-plugin Throwable in the post-verify path returns a structured 500.
- Two Factor and WP 2FA users can reach wp-admin via autologin without a second challenge.
- All bypass mechanisms are strictly request-scoped — no persistent state changes.

**Negative / Accepted:**
- Hard `exit()`/`die()` in a hook still produces a 502 (uncatchable).
- Solid Security and Shield may still challenge on first page load after autologin.
- `CONSUME_TIMEOUT` lowered to 5 s means a very slow CP (> 5 s response) will return 410 instead of eventually succeeding. Operators with high-latency CP connections should ensure the CP is reachable within 5 s.

---

## Security review notes

- The bypass is conditional on the Ed25519 JWT gate passing. It cannot be triggered without a valid signed token from the CP.
- Session markers injected via `attach_session_information` are written into the WP session store (a `wp_usermeta` row for the auth cookie token), not into the cookie body directly. They are not forgeable from the client side.
- The `wp_2fa_should_redirect_unconfigured` filter only affects the current PHP request. It is removed before the method returns and cannot persist across requests.
- No user meta is modified (we only read `_two_factor_provider`, never write it).
- The same-user fast-path still requires a valid JWT, a successful CP consume, a resolved local user, and a passing role allow-list before the fast-path branch is taken. There is no short-circuit of the authorization gate.
