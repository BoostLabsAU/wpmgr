# ADR-059 — Site-User Authentication Policy (2FA + Password Policy)

**Status:** Accepted — 2026-06-20
**Phase:** Security Suite Phase 3 (P3.0 CP foundation)

---

## Context

WPMgr operators acquired 2FA on the WPMgr dashboard in v0.50.0 (ADR-056). Managed
WordPress sites remain unprotected: a WP administrator account on a managed site
carries no second factor and no password-strength requirement enforced by WPMgr.
Agencies who manage high-value WordPress sites need to bring those sites' user
accounts under the same kind of policy controls they already expect for their own
dashboard access.

This ADR describes the design for the CP foundation layer: the data model, the CP
policy service, the signed push contract (`sync_security_policy`), the HIBP
breach-password proxy, and the operator escape hatches. Agent enforcement and the
dashboard surface are separate phases.

---

## Enforcement moves to the agent

The key architectural decision in this phase is that **the verification gate for
site-user 2FA lives in the agent (the WordPress login flow), not in the CP.** The
CP is the source of truth for policy; the agent mirrors it via a signed
`sync_security_policy` command (full-snapshot replace on every save, mirroring
`sync_security_hardening` from ADR-057) and enforces it locally.

Consequences:

- The CP stores **policy** only (knobs + per-role group overrides). It does not
  store per-WP-user 2FA secrets, recovery codes, trusted-device tokens, or
  password-history hashes — those live in encrypted WP user-meta on the site.
- The agent receives one authoritative snapshot per push and applies it atomically
  (never-fatal, default-OFF on parse failure — same discipline as `HardeningConfig`).
- The dashboard "2FA coverage" surface comes from an aggregate enrollment summary
  (counts per role: enrolled / required-not-enrolled / total) that the agent
  piggybacks onto its diagnostics push. No secrets or individual user identity
  leaves the site.

---

## Data model

### `site_security_policy` (m78, one row per site, PK = `site_id`)

Tenant-scoped (same RLS pair as m76). Every column defaults to the OFF/safe
value so a fresh upsert does not start enforcing anything.

Site-level policy knobs:

| Column | Type | Default | Meaning |
|---|---|---|---|
| `two_factor_enabled` | bool | false | Master switch for the site-user 2FA subsystem. |
| `two_factor_methods` | text[] | `{totp,email,backup}` | Allowed providers. |
| `two_factor_required_roles` | text[] | `{}` | WP roles that must use 2FA. Empty = optional. |
| `two_factor_grace_logins` | int | 3 | Logins before a required-but-unenrolled user is forced into onboarding. |
| `two_factor_remember_device_days` | int | 30 | Trusted-device TTL. 0 = disabled. |
| `block_xmlrpc_for_2fa_users` | bool | true | Reject password-only XML-RPC for any user with 2FA. |
| `password_min_zxcvbn_score` | int (0–4) | 0 | Minimum zxcvbn score on set/change. 0 = disabled. |
| `password_min_zxcvbn_roles` | text[] | `{}` | Roles the strength rule applies to. Empty = all. |
| `password_block_compromised` | bool | false | Reject passwords in HIBP breach corpus. |
| `password_reuse_block_count` | int | 0 | Block reusing last N passwords. 0 = off. |
| `password_max_age_days` | int | 0 | Force change after N days. 0 = off. |
| `password_expiry_roles` | text[] | `{}` | Roles the expiry rule applies to. Empty = all. |
| `hide_backend_enabled` | bool | false | Master switch for the secret login slug. |
| `hide_backend_slug` | text | `''` | Secret login slug (validated `^[a-z0-9-]{4,64}$`). |
| `hide_backend_redirect` | text | `''` | Where to send hits on the canonical wp-login/wp-admin. Empty = 404. |

### `site_security_policy_groups` (m78, per-role overrides)

One row per `(site_id, role)`. Each row may override a subset of the site-level
knobs for users who hold that WP role. Resolution: the strictest matching group
wins; if any matching group requires 2FA, 2FA is required.

| Column | Type | Meaning |
|---|---|---|
| `role` | text | WP role slug (e.g. `administrator`, `editor`). |
| `require_2fa` | bool nullable | Override the site's 2FA required-ness for this role. |
| `allowed_methods` | text[] nullable | Override the allowed provider set. |
| `min_zxcvbn_score` | int nullable | Override the minimum strength score. |
| `block_compromised` | bool nullable | Override the HIBP check. |
| `max_age_days` | int nullable | Override the password-expiry window. |

### `hibp_breach_cache` (m78, global — no tenant RLS)

One row per 5-character SHA-1 prefix, holding the raw `SUFFIX:COUNT` body from
the HIBP range API. This is public breach data with no tenant association, so it
carries no RLS. A 30-day TTL keeps the cache fresh; a cache miss triggers a live
HIBP fetch from the CP.

---

## HIBP proxy: CP-side with fail-open

The agent never makes direct outbound calls to HIBP. Instead:

1. At password-set time the agent hashes the candidate password with SHA-1 and
   sends the **5-char prefix only** to the CP proxy endpoint
   `GET /agent/v1/security/hibp/range/{prefix}`.
2. The CP checks the 30-day cache; on miss it fetches
   `https://api.pwnedpasswords.com/range/{prefix}` with the `Add-Padding: true`
   header (k-anonymity privacy; decoy padding lines are kept in the body to avoid
   leaking query patterns).
3. The CP returns the raw `SUFFIX:COUNT` body. The agent matches the suffix
   locally and never sends the full hash or plaintext anywhere.
4. **Fail-open:** if HIBP is unreachable or returns an error the proxy returns an
   empty body with a `200 ok`. This ensures a site login / password-set is never
   blocked solely because HIBP is down.

---

## Signed push contract: `sync_security_policy`

Command name: `sync_security_policy`.
Transport: same as `sync_security_hardening` — an EdDSA-signed JWT
(`cmd`, `aud=siteId`, `exp≤now+60s`, single-use `jti`) over the SSRF-hardened
transport.

The CP sends a **full snapshot** on every policy save (no diffing):

```json
{
  "policy": {
    "two_factor_enabled": false,
    "two_factor_methods": ["totp","email","backup"],
    "two_factor_required_roles": [],
    "two_factor_grace_logins": 3,
    "two_factor_remember_device_days": 30,
    "block_xmlrpc_for_2fa_users": true,
    "password_min_zxcvbn_score": 0,
    "password_min_zxcvbn_roles": [],
    "password_block_compromised": false,
    "password_reuse_block_count": 0,
    "password_max_age_days": 0,
    "password_expiry_roles": [],
    "hide_backend_enabled": false,
    "hide_backend_slug": "",
    "hide_backend_redirect": ""
  },
  "groups": [
    {
      "role": "administrator",
      "require_2fa": true,
      "allowed_methods": ["totp","backup"],
      "min_zxcvbn_score": 3,
      "block_compromised": true,
      "max_age_days": 90
    }
  ],
  "force_password_change": [
    { "user_login": "johndoe", "reason": "admin_reset" }
  ]
}
```

Response:

```json
{
  "ok": true,
  "detail": "applied",
  "enrollment_summary": {
    "per_role": {
      "administrator": { "enrolled": 2, "required": 2, "total": 2 }
    }
  }
}
```

---

## Lockout-proofing invariants

The following invariants are hard requirements on any shipped implementation:

1. **Default-OFF.** A fresh `site_security_policy` row must not start enforcing
   2FA or password expiry. `two_factor_enabled=false` and `password_max_age_days=0`
   are the defaults.
2. **Autologin always bypasses.** The agent's autologin path
   (`POST /wp-json/wpmgr/v1/autologin`) never fires `wp_login` and therefore
   never triggers the 2FA interstitial, the forced-change interstitial, or the
   hide-backend redirect. This is inherited from the existing autologin design
   (ADR-055) and must not be broken.
3. **CP escape hatch (disable policy).** An operator can PUT a policy with
   `two_factor_enabled=false` from the CP at any time. The push is best-effort;
   if the agent is unreachable, the stored policy is updated and will be applied
   on the next connection.
4. **Required-but-unenrolled users get grace logins + email fallback,** never an
   immediate hard wall. `two_factor_grace_logins` (default 3) gives unenrolled
   required users a window; the email provider is injected as the fallback
   provider for required users who have no method configured.
5. **Agent apply path is try/catch-guarded.** A malformed push can never crash
   the agent or brick the WP login flow. All module applies are wrapped in
   `try/catch`; on any exception the module must fall back to the inert
   (no-enforcement) state.
6. **wp-config constant last-resort.** `define('WPMGR_DISABLE_SITE_2FA', true)`
   in `wp-config.php` disables the entire interstitial unconditionally. Analogous
   constant for hide-backend: `WPMGR_DISABLE_HIDE_BACKEND`.

---

## Security review mandate

The agent implementation of this feature (P3.2–P3.4) is **HIGH security-review
priority** before any release: it gates the WP login flow, stores second-factor
secrets on the site, and changes how authentication cookies are issued. The
security-reviewer must review every agent PR in this phase before merge, with
focus on:

- The half-authenticated cookie window (capture + destroy at `wp_login@-1000`).
- Trusted-device user-binding (the B1 class of bug from ADR-056).
- Interstitial session signing (HMAC, 1h TTL, per-challenge attempt cap).
- Replay-burn on TOTP (exact-step) and single-use burn on email/backup codes.
- The hide-backend bypass surface (REST/cron/CLI bail, autologin reachability).

---

## Alternatives considered

**Store per-user 2FA enrollment state on the CP.** Rejected: this would require
the CP to enumerate every WP user on every managed site, maintain a replica of
WP's user table, and handle user churn. The agent already holds the ground truth;
an enrollment summary (counts only) is sufficient for the dashboard surface.

**Run the HIBP range query from the agent.** Rejected: agents on locked-down
hosts cannot make arbitrary outbound calls; a fleet-wide CP cache is cheaper and
keeps the agent's egress surface minimal.

**Diffing policy pushes.** Rejected: full-snapshot replace is simpler, eliminates
partial-state edge cases, and is the established pattern from `sync_security_hardening`.
