package auth

// twofa_service_ext.go -- thin Service-level façade methods that the Phase 3
// HTTP handlers call. These delegate to repo or twofa methods that already
// exist; they are extracted here so handler.go and twofa_handler.go do not
// need to reach through the repo directly.

import (
	"context"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// GetTwoFactorState returns the 2FA columns from the users row needed for the
// /auth/2fa/status endpoint. It wraps the twoFARepo call so handlers do not
// touch the repo directly.
func (s *Service) GetTwoFactorState(ctx context.Context, userID uuid.UUID) (TwoFactorState, error) {
	if s.twofa == nil {
		return TwoFactorState{}, domain.ServiceUnavailable("2fa_not_configured", "two-factor authentication is not configured")
	}
	return s.twofa.repo.twoFA().GetTwoFactorState(ctx, userID)
}

// GetWebAuthnCount returns the number of registered WebAuthn credentials for a
// user. Used by the /auth/2fa/status endpoint to populate webauthn_count.
func (s *Service) GetWebAuthnCount(ctx context.Context, userID uuid.UUID) (int64, error) {
	if s.twofa == nil {
		return 0, domain.ServiceUnavailable("2fa_not_configured", "two-factor authentication is not configured")
	}
	return s.twofa.repo.twoFA().CountWebAuthnCredentials(ctx, userID)
}

// GetMemberships returns the memberships for a user. Used by management
// handlers that need to pass memberships to audit-recording service methods
// (DisableTOTP, RegenerateRecoveryCodes, etc.) without re-entering the Me
// codepath.
func (s *Service) GetMemberships(ctx context.Context, userID uuid.UUID) ([]Membership, error) {
	memberships, err := s.repo.ListMembershipsForUser(ctx, userID)
	if err != nil {
		return nil, domain.Internal("memberships_failed", "failed to list user memberships").WithCause(err)
	}
	return memberships, nil
}

// VerifyCurrentPassword checks that the supplied plaintext password matches the
// stored argon2id hash for the user. Used by management handlers that require
// re-authentication before a sensitive operation (disable TOTP, delete WebAuthn
// credential, regenerate recovery codes). This is the same verification logic
// as ChangePassword without the actual password update.
//
// Returns domain.Unauthorized("invalid_current_password") on mismatch.
func (s *Service) VerifyCurrentPassword(ctx context.Context, userID uuid.UUID, currentPwd string) error {
	u, err := s.repo.GetUserByID(ctx, userID)
	if err != nil {
		return err
	}
	if u.PasswordHash == "" {
		return domain.Validation("sso_account_no_password", "password verification is not available for SSO sign-in")
	}
	match, verr := VerifyPassword(currentPwd, u.PasswordHash)
	if verr != nil {
		return domain.Internal("password_verify_failed", "failed to verify password").WithCause(verr)
	}
	if !match {
		return domain.Unauthorized("invalid_current_password", "current password is incorrect")
	}
	return nil
}
