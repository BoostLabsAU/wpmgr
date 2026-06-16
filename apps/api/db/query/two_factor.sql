-- two_factor.sql — sqlc queries for ADR-056 dashboard 2FA (m73).
-- All writes run under Pool.InAgentTx (app.agent='on').
-- Named parameters use @param convention (sqlc named params).

-- -----------------------------------------------------------------------
-- users: TOTP column setters
-- -----------------------------------------------------------------------

-- name: SetUserTOTPSecret :exec
-- Store the age-encrypted TOTP shared secret during enrollment confirmation.
-- Sets totp_confirmed_at so the enrollment timestamp is visible in the UI.
UPDATE users
SET totp_secret_encrypted = @totp_secret_encrypted,
    totp_confirmed_at     = now(),
    two_factor_enabled    = true,
    updated_at            = now()
WHERE id = @user_id;

-- name: SetUserTwoFactorEnabled :exec
-- Toggle two_factor_enabled without touching the secret (disable path).
-- The secret and confirmed_at are intentionally retained for audit purposes.
UPDATE users
SET two_factor_enabled = @two_factor_enabled,
    updated_at         = now()
WHERE id = @user_id;

-- name: GetUserTOTPLastStep :one
-- Read the last accepted TOTP time-step for replay-protection comparison.
-- Returns NULL when no code has ever been accepted (new enrollment).
SELECT totp_last_step
FROM users
WHERE id = @user_id;

-- name: SetUserTOTPLastStep :exec
-- Persist the accepted TOTP time-step immediately after a successful verify.
-- Called inside the same InAgentTx as the challenge consumption so the step
-- is stamped atomically with the challenge being marked used.
UPDATE users
SET totp_last_step = @totp_last_step,
    updated_at     = now()
WHERE id = @user_id;

-- name: ClearUserTOTPSecret :exec
-- Disable TOTP by nulling the secret and confirmed_at.
-- The secret bytes are cleared so a DB dump after disablement reveals nothing.
-- two_factor_enabled is recomputed by the service after this call (it may
-- remain true if WebAuthn credentials still exist).
UPDATE users
SET totp_secret_encrypted = NULL,
    totp_confirmed_at     = NULL,
    totp_last_step        = NULL,
    updated_at            = now()
WHERE id = @user_id;

-- name: SetUserTOTPProvisional :exec
-- Store the provisional (unconfirmed) TOTP secret between BeginRegistration
-- and FinishRegistration. Overwriting is safe: the user may restart enrollment.
UPDATE users
SET totp_provisional_secret_encrypted = @totp_provisional_secret_encrypted,
    totp_provisional_expires_at       = @totp_provisional_expires_at,
    updated_at                        = now()
WHERE id = @user_id;

-- name: GetUserTOTPProvisional :one
-- Retrieve the provisional TOTP secret for confirmation. Returns the row only
-- when the provisional TTL has not yet expired.
SELECT totp_provisional_secret_encrypted,
       totp_provisional_expires_at
FROM users
WHERE id                       = @user_id
  AND totp_provisional_expires_at > now()
  AND totp_provisional_secret_encrypted IS NOT NULL;

-- name: ClearUserTOTPProvisional :exec
-- Clear the provisional secret after enrollment is confirmed (or on explicit
-- cancel). Idempotent: safe to call even if the columns are already NULL.
UPDATE users
SET totp_provisional_secret_encrypted = NULL,
    totp_provisional_expires_at       = NULL,
    updated_at                        = now()
WHERE id = @user_id;

-- name: CountWebAuthnCredentials :one
-- Count registered WebAuthn credentials for a user. Used to recompute
-- two_factor_enabled after credential deletion.
SELECT COUNT(*)::bigint
FROM webauthn_credentials
WHERE user_id = @user_id;

-- name: GetUserTwoFactorState :one
-- Lightweight read for the 2FA branch in the login handler (Phase 2).
SELECT id,
       two_factor_enabled,
       totp_secret_encrypted,
       totp_confirmed_at
FROM users
WHERE id = @user_id;

-- -----------------------------------------------------------------------
-- user_recovery_codes
-- -----------------------------------------------------------------------

-- name: InsertRecoveryCode :exec
-- Insert one hashed recovery code. Called 10 times during enrollment.
-- Run under InAgentTx.
INSERT INTO user_recovery_codes (user_id, code_hash)
VALUES (@user_id, @code_hash);

-- name: ListActiveRecoveryCodes :many
-- All unused recovery codes for a user (used_at IS NULL).
-- Ordered by created_at ASC, id ASC for stable pagination.
SELECT id, user_id, code_hash, used_at, created_at
FROM user_recovery_codes
WHERE user_id = @user_id
  AND used_at IS NULL
ORDER BY created_at ASC, id ASC;

-- name: ConsumeRecoveryCode :one
-- Atomically consume a single recovery code by ID.
-- Returns the row only when the update succeeds (used_at was NULL).
UPDATE user_recovery_codes
SET used_at = now()
WHERE id      = @id
  AND user_id = @user_id
  AND used_at IS NULL
RETURNING id, user_id, code_hash, used_at, created_at;

-- name: DeleteAllRecoveryCodes :exec
-- Purge all recovery codes for a user (before inserting a regenerated batch).
DELETE FROM user_recovery_codes WHERE user_id = @user_id;

-- name: CountActiveRecoveryCodes :one
-- Count remaining (unused) recovery codes. Shown in the Security settings card.
SELECT COUNT(*)::bigint
FROM user_recovery_codes
WHERE user_id = @user_id
  AND used_at IS NULL;

-- -----------------------------------------------------------------------
-- webauthn_credentials
-- -----------------------------------------------------------------------

-- name: InsertWebAuthnCredential :one
-- Persist a newly registered WebAuthn credential after FinishRegistration.
INSERT INTO webauthn_credentials (
    user_id, credential_id, public_key, attestation_type,
    aaguid, sign_count, transports, name, backup_eligible, backup_state
) VALUES (
    @user_id, @credential_id, @public_key, @attestation_type,
    @aaguid, @sign_count, @transports, @name, @backup_eligible, @backup_state
)
RETURNING id, user_id, credential_id, public_key, attestation_type, aaguid,
          sign_count, transports, name, backup_eligible, backup_state,
          created_at, last_used_at;

-- name: GetWebAuthnCredentialByCredentialID :one
-- Load a credential by its binary credential_id (used during assertion).
SELECT id, user_id, credential_id, public_key, attestation_type, aaguid,
       sign_count, transports, name, backup_eligible, backup_state,
       created_at, last_used_at
FROM webauthn_credentials
WHERE credential_id = @credential_id;

-- name: ListWebAuthnCredentialsForUser :many
-- All registered credentials for a user (for the Security settings list).
-- Ordered by created_at DESC, id DESC.
SELECT id, user_id, credential_id, public_key, attestation_type, aaguid,
       sign_count, transports, name, backup_eligible, backup_state,
       created_at, last_used_at
FROM webauthn_credentials
WHERE user_id = @user_id
ORDER BY created_at DESC, id DESC;

-- name: UpdateWebAuthnCredentialSignCount :exec
-- Bump the sign_count and last_used_at after a successful assertion.
UPDATE webauthn_credentials
SET sign_count   = @sign_count,
    backup_state = @backup_state,
    last_used_at = now()
WHERE id = @id;

-- name: DeleteWebAuthnCredential :execrows
-- Remove a single credential by its primary key and user_id guard.
DELETE FROM webauthn_credentials
WHERE id      = @id
  AND user_id = @user_id;

-- -----------------------------------------------------------------------
-- two_factor_challenges
-- -----------------------------------------------------------------------

-- name: InsertTwoFactorChallenge :one
-- Create a new factor-agnostic login challenge. Returns the created row.
INSERT INTO two_factor_challenges (
    user_id, challenge_nonce, kind, webauthn_session, expires_at, requested_ip
) VALUES (
    @user_id, @challenge_nonce, @kind, @webauthn_session, @expires_at, @requested_ip
)
RETURNING id, user_id, challenge_nonce, kind, webauthn_session,
          expires_at, used_at, attempts, requested_ip, created_at;

-- name: GetActiveTwoFactorChallenge :one
-- Load an active (unused, non-expired) challenge by ID.
SELECT id, user_id, challenge_nonce, kind, webauthn_session,
       expires_at, used_at, attempts, requested_ip, created_at
FROM two_factor_challenges
WHERE id       = @id
  AND used_at  IS NULL
  AND expires_at > now();

-- name: ConsumeTwoFactorChallenge :one
-- Mark a challenge used on successful verification.
UPDATE two_factor_challenges
SET used_at = now()
WHERE id      = @id
  AND used_at IS NULL
RETURNING id, user_id, challenge_nonce, kind, webauthn_session,
          expires_at, used_at, attempts, requested_ip, created_at;

-- name: IncrementChallengeAttempts :one
-- Increment the failed-attempt counter. Returns updated row so caller can
-- check whether the attempt limit (5) has been reached.
UPDATE two_factor_challenges
SET attempts = attempts + 1
WHERE id = @id
RETURNING id, user_id, challenge_nonce, kind, webauthn_session,
          expires_at, used_at, attempts, requested_ip, created_at;

-- name: ExpireTwoFactorChallenge :exec
-- Lock a challenge by setting used_at (reached attempt limit or timeout).
UPDATE two_factor_challenges
SET used_at = now()
WHERE id = @id;

-- -----------------------------------------------------------------------
-- webauthn_registration_sessions
-- -----------------------------------------------------------------------

-- name: InsertWebAuthnRegistrationSession :one
-- Stash go-webauthn SessionData between BeginRegistration and FinishRegistration.
INSERT INTO webauthn_registration_sessions (user_id, session, expires_at)
VALUES (@user_id, @session, @expires_at)
RETURNING id, user_id, session, expires_at, created_at;

-- name: GetWebAuthnRegistrationSession :one
-- Load and return the registration session for a user (most recent, non-expired).
SELECT id, user_id, session, expires_at, created_at
FROM webauthn_registration_sessions
WHERE user_id    = @user_id
  AND expires_at > now()
ORDER BY created_at DESC, id DESC
LIMIT 1;

-- name: DeleteWebAuthnRegistrationSession :exec
-- Remove the registration session after FinishRegistration (or on failure).
DELETE FROM webauthn_registration_sessions WHERE id = @id;

-- name: DeleteExpiredWebAuthnRegistrationSessions :exec
-- Periodic GC: purge all expired registration sessions.
DELETE FROM webauthn_registration_sessions WHERE expires_at <= now();

-- -----------------------------------------------------------------------
-- trusted_devices
-- -----------------------------------------------------------------------

-- name: InsertTrustedDevice :one
-- Create a new trusted-device record after the user checks "remember this device".
INSERT INTO trusted_devices (user_id, token_hash, label, user_agent, ip, expires_at)
VALUES (@user_id, @token_hash, @label, @user_agent, @ip, @expires_at)
RETURNING id, user_id, token_hash, label, user_agent, ip,
          created_at, expires_at, last_used_at, revoked_at;

-- name: GetTrustedDeviceByTokenHash :one
-- Verify a device trust cookie token (hashed before lookup comparison).
SELECT id, user_id, token_hash, label, user_agent, ip,
       created_at, expires_at, last_used_at, revoked_at
FROM trusted_devices
WHERE token_hash = @token_hash
  AND revoked_at IS NULL
  AND expires_at > now();

-- name: ListTrustedDevicesForUser :many
-- All active trusted devices for the Security settings UI.
-- Ordered by created_at DESC, id DESC.
SELECT id, user_id, token_hash, label, user_agent, ip,
       created_at, expires_at, last_used_at, revoked_at
FROM trusted_devices
WHERE user_id    = @user_id
  AND revoked_at IS NULL
  AND expires_at > now()
ORDER BY created_at DESC, id DESC;

-- name: RevokeTrustedDevice :exec
-- Revoke a single trusted device by ID + user_id guard.
UPDATE trusted_devices
SET revoked_at = now()
WHERE id      = @id
  AND user_id = @user_id;

-- name: RevokeAllTrustedDevicesForUser :exec
-- Revoke all active trusted devices for a user (e.g. on password change or 2FA disable).
UPDATE trusted_devices
SET revoked_at = now()
WHERE user_id    = @user_id
  AND revoked_at IS NULL;

-- name: TouchTrustedDevice :exec
-- Update last_used_at on a trusted device (called when it is reused at login).
UPDATE trusted_devices
SET last_used_at = now()
WHERE id = @id;
