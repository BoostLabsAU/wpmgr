# ADR-056: Dashboard Two-Factor Authentication

**Status:** Accepted
**Date:** 2026-06-16
**Authors:** Backend Architect

---

## Context

WPMgr's agent-to-site auto-login (ADR-055) intentionally bypasses any 2FA the WordPress site has configured, because the agent authenticates via a privileged application password, not via the human login form. This is correct and intentional. However it makes the WPMgr dashboard itself the single security boundary: whoever controls a dashboard session controls every managed site. The dashboard login today requires only an email and password. A stolen password is therefore a complete compromise.

The dashboard front door must be hardened with a second factor so that a leaked password alone is not sufficient.

---

## Decision

We will implement dashboard two-factor authentication as a first-class feature in phases. Phase 1 (this ADR) establishes the data model, library dependencies, and the `SecondFactor` interface. Subsequent phases add the service logic, HTTP endpoints, session-layer enforcement, and frontend.

### Factor architecture: SecondFactor interface

Rather than hard-coding TOTP into the login flow, we define a thin `SecondFactor` interface in `internal/auth/twofactor/`. Both TOTP and WebAuthn/passkeys are first-class implementors behind the same login flow. This costs almost nothing now and eliminates a refactor when passkeys are added.

```go
// SecondFactor abstracts a second-factor type (TOTP, WebAuthn, future SMS).
type SecondFactor interface {
    // Kind returns the factor discriminator ("totp", "webauthn").
    Kind() string
    // BeginLogin issues a challenge for the given user. The challenge metadata
    // (opaque to the interface) is returned to be stashed in two_factor_challenges
    // or the session, depending on the factor type.
    BeginLogin(ctx context.Context, userID uuid.UUID) (challengeMeta any, err error)
    // FinishLogin verifies the user's response to a challenge. clientData is the
    // factor-specific payload (TOTP: the 6-digit code string; WebAuthn: the
    // signed assertion bytes).
    FinishLogin(ctx context.Context, userID uuid.UUID, challengeMeta any, clientData []byte) error
    // BeginRegistration starts enrollment for the given user. Returns setup data
    // (TOTP: otpauth URI + base32 secret; WebAuthn: PublicKeyCredentialCreationOptions JSON).
    BeginRegistration(ctx context.Context, userID uuid.UUID, userEmail string) (setupData any, err error)
    // FinishRegistration verifies the enrollment response and commits the credential.
    FinishRegistration(ctx context.Context, userID uuid.UUID, setupData any, clientData []byte) error
}
```

### Library choices

| Library | Version | Purpose |
|---------|---------|---------|
| `github.com/pquerna/otp` | v1.5.0 | RFC 6238 TOTP + otpauth URI generation |
| `github.com/go-webauthn/webauthn` | v0.17.4 | WebAuthn Level 2 registration + assertion |

`pquerna/otp` is the Go ecosystem standard for TOTP, adopted by HashiCorp Vault and many others. The RFC surface is frozen, which limits future churn. v1.5.0 was released 2024-12-31 and is actively maintained.

`go-webauthn/webauthn` is a v0.x release and its API may change across minor versions. We pin v0.17.4 in go.mod to prevent silent drift. QR code rendering is not done server-side: the backend returns an `otpauth://` URI and the frontend renders the QR client-side via a JS library, eliminating the `boombuler/barcode` transitive dependency from the HTTP path.

### Data model

Six tables are added in migration m73. All use `app.agent='on'` RLS (same as `password_reset_tokens` and `email_verification_tokens`), because these records are accessed during the pre-authentication flow where no tenant GUC is set.

**`users` column additions**

| Column | Type | Purpose |
|--------|------|---------|
| `two_factor_enabled` | `bool NOT NULL DEFAULT false` | Whether any second factor is active for this user |
| `totp_secret_encrypted` | `bytea` | age-X25519 ciphertext of the base32 TOTP shared secret |
| `totp_confirmed_at` | `timestamptz` | When TOTP enrollment was confirmed (NULL = never enrolled or unenrolled) |

**`user_recovery_codes`** -- account-scoped single-use backup codes (10 per batch)

**`webauthn_credentials`** -- registered passkeys / hardware keys per user

**`two_factor_challenges`** -- transient login challenges (factor-agnostic); consumed on verification

**`webauthn_registration_sessions`** -- stash go-webauthn SessionData during credential registration

**`trusted_devices`** -- revocable "remember this device" entries per user (30-day window by default)

### Secret storage

TOTP shared secrets are encrypted with the existing `cryptbox.AgeIdentity` (age X25519, keyed by `WPMGR_SITE_DEST_AGE_SECRET`). This matches the SMTP credential pattern and the same threat model: protection against a database dump, not a fully compromised control-plane process. The secret is never stored in plaintext.

Recovery codes are NOT encrypted with cryptbox; they are HASHED with `HashPassword` (argon2id). Recovery codes are expendable single-use tokens, not secrets that must be recovered. Hashing is the correct primitive (matches password-reset-token design intent, unphishable, constant-time comparison via `VerifyPassword`).

### WebAuthn relying party configuration

The WebAuthn RP ID and origin must be configurable for self-hosted instances. Two config fields are added to `AuthConfig`:

```
WPMGR_AUTH_WEBAUTHN_RPID      (default: "manage.wpmgr.app")
WPMGR_AUTH_WEBAUTHN_RPORIGINS (comma-separated; default: "https://manage.wpmgr.app")
```

Self-hosted operators set these to match their `WPMGR_PUBLIC_BASE_URL`.

### Login flow change (Phase 2, not built here)

```
POST /auth/login
  -> Service.Login (email + password)
    -> if user.two_factor_enabled == false:
         SessionManager.Login()
         <- 200 OK + Me
    -> if user.two_factor_enabled == true:
         Service.CreateChallenge(userID, kind="login")
         <- 202 Accepted + {challenge_id}
POST /auth/2fa/challenge
  -> Service.VerifyChallenge(challenge_id, factor_response)
     -> SecondFactor.FinishLogin(...)
     -> SessionManager.Login()
  <- 200 OK + Me
```

### Enforcement

Two-factor authentication is optional per-user in v1. Superadmin accounts show a non-blocking nudge banner in the dashboard. Org-wide enforcement ("require 2FA for all members") is a future fast-follow, designed to be additive: the `two_factor_enabled` column is already per-user, so an enforcement check at login is a one-line gate.

### Security invariants

1. **Encrypted secret at rest.** TOTP shared secret encrypted with age X25519 before write; decrypted only at challenge verification time. Never returned to any API response after enrollment.
2. **Hashed single-use recovery codes.** argon2id hash stored; plaintext shown exactly once at enrollment and on explicit regenerate. Consumed via `used_at` timestamp.
3. **WebAuthn sign-count replay protection.** `webauthn_credentials.sign_count` is updated on every successful assertion. The go-webauthn library enforces that the asserted sign count is strictly greater than the stored value (authenticator clone detection).
4. **Rate limiting.** Max 5 failed factor attempts per challenge; the challenge is locked (used_at stamped) after exhaustion. Recovery-code attempts are rate-limited per IP via the existing `RateLimiter` interface.
5. **Re-auth required to disable.** Disabling 2FA requires the current password. A stolen session token alone cannot disable 2FA.
6. **Audit events.** `ActionTOTPEnrolled`, `ActionTOTPVerified`, `ActionTOTPDisabled`, `ActionTOTPCodesRegenerated`, `ActionTOTPFailed` are added to the audit action constants in Phase 2.
7. **Trusted-device cookie.** Issued as a separate `wpmgr_device` HttpOnly Secure cookie distinct from the session cookie. The token is stored hashed in `trusted_devices`. Revocation immediately invalidates all matching rows; the cookie is rendered invalid on the next login check.
8. **Clock-skew tolerance.** TOTP validation uses `Skew: 1` (one period = 30 seconds in each direction). Not `Skew: 2` or higher.
9. **No TOTP secret display after enrollment.** The secret is shown exactly during the enrollment wizard, then never returned by any endpoint.

### Standards

NIST SP 800-63B Section 5.1.5 (out-of-band authenticators) and Section 5.1.4 (single-factor OTP devices): TOTP satisfies AAL2 when combined with a memorized secret (password). Recovery codes satisfy NIST's backup authenticator requirement for lost-device scenarios. OWASP Authentication Cheat Sheet: 10 recovery codes, plus sign count enforcement for WebAuthn.

---

## Phased plan

| Phase | What | Owner |
|-------|------|-------|
| 1 (this ADR) | Data model, deps, sqlc, `SecondFactor` interface skeleton, config wiring | Backend Architect |
| 2 | Service methods: challenge CRUD, TOTP enroll/verify/disable, recovery-code generation/consumption | Backend Architect |
| 3 | HTTP handlers: `/auth/2fa/*`, modified `/auth/login` (202 branch), recovery-code fallback | Backend Architect |
| 4 | Session/middleware enforcement: `two_factor_enabled` gate in Authenticator | Backend Architect |
| 5 | OpenAPI contract + regenerate both consumers | Backend Architect |
| 6 | Frontend: enrollment wizard, login challenge, Security settings card, trusted-device list | Frontend Architect |
| 7 | Security review | Security Reviewer |
| 8 | Bugfix + QA | Joint |
| 9 | Documentation + changelog | Docs Writer |

---

## Consequences

- The login flow gains a conditional 202 branch when 2FA is enabled. Non-2FA users are unaffected.
- The `users` table grows three columns (two nullable bytea/timestamptz). No existing query changes shape.
- Five new tables are added; all are pre-tenant (auth-flow scope), using `app.agent='on'` RLS.
- The `go-webauthn/webauthn` library is v0.x and pinned to v0.17.4. API changes require a coordinated update.
- `WPMGR_AUTH_WEBAUTHN_RPID` and `WPMGR_AUTH_WEBAUTHN_RPORIGINS` must be set by self-hosted operators when they deploy Phase 6. Defaults cover the hosted instance.
