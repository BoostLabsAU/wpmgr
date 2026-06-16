# Dashboard two-factor authentication

Secure your WPMgr account with a second factor so a stolen password alone cannot
access the dashboard or, through it, every managed WordPress site.

ADR: [ADR-056](../adr/ADR-056-dashboard-2fa.md).
API contract: [2fa-api-contract.md](../2fa-api-contract.md).

---

## Why it matters

WPMgr's one-click autologin intentionally bypasses any 2FA the WordPress sites
themselves have configured (see [ADR-055](../adr/ADR-055-autologin-2fa-bypass.md)).
That makes the WPMgr dashboard the single security boundary: whoever controls a
dashboard session controls every managed site. A second factor on the dashboard
closes that gap.

---

## Supported factors

| Factor | What you need |
|--------|---------------|
| **Authenticator app (TOTP)** | Any RFC 6238 app: Authy, Google Authenticator, 1Password, Bitwarden, etc. Works on any origin. |
| **Passkey / security key (WebAuthn)** | Platform biometrics (Touch ID, Face ID, Windows Hello) or a hardware key (YubiKey, etc.). Requires accessing the dashboard on its configured relying-party domain. |

Both factors can be active at the same time. At login you may use whichever is
available.

---

## Enable an authenticator app (TOTP)

1. **Settings → Security → Authenticator app → Set up.**
2. Scan the QR code with your app, or click **Enter key manually** and paste the
   base32 secret.
3. Type a live 6-digit code from the app to confirm.
4. **Save your recovery codes** (shown once; see [Recovery codes](#recovery-codes)).
5. Two-factor is now active.

The secret is encrypted at rest (age X25519) and never returned by any API
response after enrollment.

---

## Add a passkey or security key

1. **Settings → Security → Passkeys → Add passkey.**
2. Follow the browser prompt (Touch ID, Face ID, Windows Hello, or insert a
   hardware key).
3. Give the credential a label (e.g. "MacBook Touch ID" or "YubiKey 5C").
4. The passkey appears in the list and is ready to use.

> **Relying-party domain.** WebAuthn binds a passkey to the domain where it was
> registered. On the hosted service that is `manage.wpmgr.app`. For a self-hosted
> instance, set `WPMGR_AUTH_WEBAUTHN_RPID` and `WPMGR_AUTH_WEBAUTHN_RP_ORIGINS`
> to your dashboard's origin before adding passkeys. Passkeys registered on one
> domain cannot be used on another. The authenticator-app factor has no such
> restriction.

---

## The login challenge

When 2FA is active, `POST /auth/login` returns `202 Accepted` instead of `200 OK`
and includes a `challenge` UUID and the available factor types:

```json
{
  "two_factor_required": true,
  "challenge": "550e8400-e29b-41d4-a716-446655440000",
  "factors": { "totp": true, "webauthn": false, "recovery": true }
}
```

The login page presents the matching factor UI. You enter your code (or use a
passkey), and the session is issued on success.

OIDC/SSO logins follow the same gate: the callback redirects to `/2fa-challenge`
instead of issuing a session directly.

### Remember this device

At the challenge step, check **Remember this device**. An optional label (e.g.
"Work laptop") helps identify the entry later. A `wpmgr_2fa_device` cookie is set
for 30 days; subsequent logins on that browser skip the second step.

Trusted-device cookies are:
- HttpOnly, Secure, SameSite=Lax.
- Stored as a SHA-256 hash — the raw token is never saved server-side.
- Revocable individually from **Settings → Security → Trusted devices → Revoke**,
  or all at once via **Revoke all**.
- Cleared automatically on password change, password reset, or when 2FA is disabled.

---

## Recovery codes

When you enroll any factor, 10 one-time recovery codes are generated and shown
exactly once. Save them in a password manager or printed copy.

```
AAAAA-BBBBB
CCCCC-DDDDD
...
```

At the login challenge, choose **Use a recovery code** and enter one. Each code
is consumed on use (argon2id hash comparison) and cannot be reused.

When codes run low, regenerate from **Settings → Security → Recovery codes →
Regenerate**. This requires your current password and immediately invalidates all
previous codes.

---

## Manage from Settings → Security

| Action | Where |
|--------|-------|
| View factor status and codes remaining | Security overview |
| Remove TOTP | Authenticator app → Remove (requires current password) |
| Add / remove a passkey | Passkeys → Add / Remove (remove requires current password) |
| Regenerate recovery codes | Recovery codes → Regenerate (requires current password) |
| Revoke a trusted device | Trusted devices → Revoke |
| Revoke all trusted devices | Trusted devices → Revoke all |

Removing TOTP (when it is the only active factor) disables two-factor entirely,
clears the encrypted secret, and revokes all trusted devices.

---

## Lost device recovery

If you have lost access to your authenticator app and do not have a trusted
device:

1. At the login challenge, click **Use a recovery code**.
2. Enter one of your saved codes.
3. After signing in, go to **Settings → Security** and re-enroll a new
   authenticator app or passkey.
4. Regenerate new recovery codes.

If you have also lost your recovery codes, contact your instance superadmin. A
superadmin with server access can disable 2FA for the account via the admin API
or directly in the database (set `two_factor_enabled = false`,
`totp_confirmed_at = NULL`, `totp_secret_encrypted = NULL`).

---

## Superadmin note

Two-factor is optional per user in v1. Superadmin accounts see a non-blocking
reminder banner in the dashboard until 2FA is enabled. Org-wide enforcement
("require 2FA for all members") is a planned fast-follow; the per-user column
schema already supports it.

---

## Security properties

| Property | Implementation |
|----------|---------------|
| TOTP secret | age X25519 encrypted at rest; never returned after enrollment |
| Recovery codes | argon2id hash; plaintext shown once; single-use (`used_at` timestamp) |
| Replay protection (TOTP) | Each time-step matched once; used steps burned |
| Clone detection (WebAuthn) | Sign-count strictly increases on every assertion |
| Rate limiting | 5 attempts per challenge; 10/min per user and 30/min per IP cross-challenge |
| Re-auth to disable | Current password required to remove a factor or regenerate codes |
| No bypass paths | All session-issuing paths (password, SSO, email verify, first-user bootstrap) funnel through a single gate |
| Audit log | Every enroll, verify, failure, disable, and code-regenerate event is written |
| Trusted-device binding | Cookie is bound to the authenticating user; a cookie from user A cannot satisfy user B's challenge |
