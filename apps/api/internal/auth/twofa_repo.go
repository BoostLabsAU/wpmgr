package auth

import (
	"context"
	"encoding/json"
	"errors"
	"net/netip"
	"time"

	"github.com/go-webauthn/webauthn/webauthn"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// TwoFactorState carries the 2FA fields from the users row needed by the
// challenge orchestrator.
type TwoFactorState struct {
	TwoFactorEnabled    bool
	TOTPSecretEncrypted []byte
	TOTPConfirmedAt     *time.Time
	TOTPLastStep        *int64
}

// TwoFactorChallenge is the domain model for a two_factor_challenges row.
type TwoFactorChallenge struct {
	ID             uuid.UUID
	UserID         uuid.UUID
	ChallengeNonce string
	Kind           string
	WebAuthnSession []byte
	ExpiresAt      time.Time
	UsedAt         *time.Time
	Attempts       int32
	RequestedIP    *netip.Addr
	CreatedAt      time.Time
}

// TrustedDevice is the domain model for a trusted_devices row.
type TrustedDevice struct {
	ID         uuid.UUID
	UserID     uuid.UUID
	TokenHash  string
	Label      string
	UserAgent  string
	IP         *netip.Addr
	CreatedAt  time.Time
	ExpiresAt  time.Time
	LastUsedAt *time.Time
	RevokedAt  *time.Time
}

// WebAuthnCredentialRow is the domain model for a webauthn_credentials row.
type WebAuthnCredentialRow struct {
	ID              uuid.UUID
	UserID          uuid.UUID
	CredentialID    []byte
	PublicKey       []byte
	AttestationType string
	AAGUID          []byte
	SignCount       int64
	Transports      []string
	Name            string
	BackupEligible  bool
	BackupState     bool
	CreatedAt       time.Time
	LastUsedAt      *time.Time
}

// twoFARepo extends Repo with 2FA-specific operations. All writes run under
// pool.InAgentTx because these tables use app.agent='on' RLS (pre-auth flow).
type twoFARepo struct {
	r *Repo
}

func (r *Repo) twoFA() *twoFARepo { return &twoFARepo{r: r} }

// --- users 2FA state ---

func (tr *twoFARepo) GetTwoFactorState(ctx context.Context, userID uuid.UUID) (TwoFactorState, error) {
	var out TwoFactorState
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetUserTwoFactorState(ctx, userID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("user_not_found", "user not found")
			}
			return domain.Internal("2fa_state_failed", "failed to load 2FA state").WithCause(err)
		}
		out.TwoFactorEnabled = row.TwoFactorEnabled
		out.TOTPSecretEncrypted = row.TotpSecretEncrypted
		if row.TotpConfirmedAt.Valid {
			t := row.TotpConfirmedAt.Time
			out.TOTPConfirmedAt = &t
		}
		// TotpLastStep is not in GetUserTwoFactorState; load separately when needed.
		return nil
	})
	return out, err
}

func (tr *twoFARepo) SetTOTPSecret(ctx context.Context, userID uuid.UUID, encrypted []byte) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).SetUserTOTPSecret(ctx, sqlc.SetUserTOTPSecretParams{
			UserID:              userID,
			TotpSecretEncrypted: encrypted,
		})
	})
}

func (tr *twoFARepo) ClearTOTPSecret(ctx context.Context, userID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).ClearUserTOTPSecret(ctx, userID)
	})
}

func (tr *twoFARepo) SetTwoFactorEnabled(ctx context.Context, userID uuid.UUID, enabled bool) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).SetUserTwoFactorEnabled(ctx, sqlc.SetUserTwoFactorEnabledParams{
			UserID:           userID,
			TwoFactorEnabled: enabled,
		})
	})
}

// GetTOTPLastStep returns the last accepted TOTP step for the user, or nil
// if no code has ever been accepted (new enrollment or TOTP just enrolled).
func (tr *twoFARepo) GetTOTPLastStep(ctx context.Context, userID uuid.UUID) (*int64, error) {
	var out *int64
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		step, err := sqlc.New(tx).GetUserTOTPLastStep(ctx, userID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil // user exists but step is NULL
			}
			return domain.Internal("totp_step_failed", "failed to read TOTP step").WithCause(err)
		}
		out = step
		return nil
	})
	return out, err
}

// SetTOTPLastStep records the last accepted step. Called inside the same
// InAgentTx as challenge consumption; extracted here for testability.
func (tr *twoFARepo) SetTOTPLastStep(ctx context.Context, tx pgx.Tx, userID uuid.UUID, step int64) error {
	return sqlc.New(tx).SetUserTOTPLastStep(ctx, sqlc.SetUserTOTPLastStepParams{
		UserID:       userID,
		TotpLastStep: &step,
	})
}

// --- provisional TOTP secret ---

func (tr *twoFARepo) SetTOTPProvisional(ctx context.Context, userID uuid.UUID, encrypted []byte, expiresAt time.Time) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).SetUserTOTPProvisional(ctx, sqlc.SetUserTOTPProvisionalParams{
			UserID:                         userID,
			TotpProvisionalSecretEncrypted: encrypted,
			TotpProvisionalExpiresAt:       pgtype.Timestamptz{Time: expiresAt, Valid: true},
		})
	})
}

// GetTOTPProvisional returns the encrypted provisional secret if it has not
// expired. Returns domain.Gone when the TTL has passed or no provisional secret
// is set.
func (tr *twoFARepo) GetTOTPProvisional(ctx context.Context, userID uuid.UUID) ([]byte, error) {
	var enc []byte
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetUserTOTPProvisional(ctx, userID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.Gone("totp_provisional_expired", "TOTP enrollment session has expired; please restart enrollment")
			}
			return domain.Internal("totp_provisional_failed", "failed to load provisional TOTP secret").WithCause(err)
		}
		enc = row.TotpProvisionalSecretEncrypted
		return nil
	})
	return enc, err
}

func (tr *twoFARepo) ClearTOTPProvisional(ctx context.Context, userID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).ClearUserTOTPProvisional(ctx, userID)
	})
}

// --- recovery codes ---

// InsertRecoveryCodes inserts all 10 hashed recovery codes atomically.
func (tr *twoFARepo) InsertRecoveryCodes(ctx context.Context, userID uuid.UUID, hashes []string) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		for _, h := range hashes {
			if err := q.InsertRecoveryCode(ctx, sqlc.InsertRecoveryCodeParams{
				UserID:   userID,
				CodeHash: h,
			}); err != nil {
				return domain.Internal("recovery_code_insert_failed", "failed to store recovery code").WithCause(err)
			}
		}
		return nil
	})
}

// ReplaceRecoveryCodes deletes old codes and inserts the new batch atomically.
func (tr *twoFARepo) ReplaceRecoveryCodes(ctx context.Context, userID uuid.UUID, hashes []string) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		q := sqlc.New(tx)
		if err := q.DeleteAllRecoveryCodes(ctx, userID); err != nil {
			return domain.Internal("recovery_code_delete_failed", "failed to delete old recovery codes").WithCause(err)
		}
		for _, h := range hashes {
			if err := q.InsertRecoveryCode(ctx, sqlc.InsertRecoveryCodeParams{
				UserID:   userID,
				CodeHash: h,
			}); err != nil {
				return domain.Internal("recovery_code_insert_failed", "failed to store recovery code").WithCause(err)
			}
		}
		return nil
	})
}

// ListActiveRecoveryCodes returns all unused recovery code rows.
func (tr *twoFARepo) ListActiveRecoveryCodes(ctx context.Context, userID uuid.UUID) ([]sqlc.UserRecoveryCode, error) {
	var out []sqlc.UserRecoveryCode
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListActiveRecoveryCodes(ctx, userID)
		if err != nil {
			return domain.Internal("recovery_code_list_failed", "failed to list recovery codes").WithCause(err)
		}
		out = rows
		return nil
	})
	return out, err
}

// ConsumeRecoveryCode marks one code used atomically.
func (tr *twoFARepo) ConsumeRecoveryCode(ctx context.Context, tx pgx.Tx, codeID, userID uuid.UUID) error {
	_, err := sqlc.New(tx).ConsumeRecoveryCode(ctx, sqlc.ConsumeRecoveryCodeParams{
		ID:     codeID,
		UserID: userID,
	})
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return domain.Gone("recovery_code_already_used", "recovery code was already used")
		}
		return domain.Internal("recovery_code_consume_failed", "failed to consume recovery code").WithCause(err)
	}
	return nil
}

// CountActiveRecoveryCodes returns the number of unused codes remaining.
func (tr *twoFARepo) CountActiveRecoveryCodes(ctx context.Context, userID uuid.UUID) (int64, error) {
	var n int64
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		count, err := sqlc.New(tx).CountActiveRecoveryCodes(ctx, userID)
		if err != nil {
			return domain.Internal("recovery_code_count_failed", "failed to count recovery codes").WithCause(err)
		}
		n = count
		return nil
	})
	return n, err
}

// --- two_factor_challenges ---

// CreateChallenge inserts a new challenge row and returns its ID + nonce.
func (tr *twoFARepo) CreateChallenge(ctx context.Context, userID uuid.UUID, nonce, kind string, webauthnSession []byte, ip *netip.Addr, ttl time.Duration) (uuid.UUID, error) {
	var id uuid.UUID
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).InsertTwoFactorChallenge(ctx, sqlc.InsertTwoFactorChallengeParams{
			UserID:          userID,
			ChallengeNonce:  nonce,
			Kind:            kind,
			WebauthnSession: webauthnSession,
			ExpiresAt:       time.Now().Add(ttl),
			RequestedIp:     ip,
		})
		if err != nil {
			return domain.Internal("challenge_create_failed", "failed to create 2FA challenge").WithCause(err)
		}
		id = row.ID
		return nil
	})
	return id, err
}

// GetActiveChallenge loads a non-expired, non-used challenge by ID.
func (tr *twoFARepo) GetActiveChallenge(ctx context.Context, challengeID uuid.UUID) (TwoFactorChallenge, error) {
	var out TwoFactorChallenge
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetActiveTwoFactorChallenge(ctx, challengeID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.Gone("challenge_expired", "this 2FA challenge has expired or was already used")
			}
			return domain.Internal("challenge_get_failed", "failed to load 2FA challenge").WithCause(err)
		}
		out = challengeFromSQLC(row)
		return nil
	})
	return out, err
}

// IncrementChallengeAttempts bumps the attempt counter and returns the updated
// row. The caller checks whether the limit is exceeded.
func (tr *twoFARepo) IncrementChallengeAttempts(ctx context.Context, tx pgx.Tx, challengeID uuid.UUID) (int32, error) {
	row, err := sqlc.New(tx).IncrementChallengeAttempts(ctx, challengeID)
	if err != nil {
		return 0, domain.Internal("challenge_attempt_failed", "failed to increment challenge attempts").WithCause(err)
	}
	return row.Attempts, nil
}

// ConsumeChallenge marks the challenge used. Called on success.
func (tr *twoFARepo) ConsumeChallenge(ctx context.Context, tx pgx.Tx, challengeID uuid.UUID) error {
	_, err := sqlc.New(tx).ConsumeTwoFactorChallenge(ctx, challengeID)
	if err != nil {
		return domain.Internal("challenge_consume_failed", "failed to consume 2FA challenge").WithCause(err)
	}
	return nil
}

// ExpireChallenge locks the challenge by setting used_at (limit reached).
func (tr *twoFARepo) ExpireChallenge(ctx context.Context, tx pgx.Tx, challengeID uuid.UUID) error {
	return sqlc.New(tx).ExpireTwoFactorChallenge(ctx, challengeID)
}

// --- webauthn_credentials ---

// InsertWebAuthnCredential persists a freshly verified WebAuthn credential.
func (tr *twoFARepo) InsertWebAuthnCredential(ctx context.Context, userID uuid.UUID, cred *webauthn.Credential, name string) (WebAuthnCredentialRow, error) {
	var out WebAuthnCredentialRow
	transports := make([]string, len(cred.Transport))
	for i, t := range cred.Transport {
		transports[i] = string(t)
	}
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).InsertWebAuthnCredential(ctx, sqlc.InsertWebAuthnCredentialParams{
			UserID:          userID,
			CredentialID:    cred.ID,
			PublicKey:       cred.PublicKey,
			AttestationType: cred.AttestationType,
			Aaguid:          cred.Authenticator.AAGUID,
			SignCount:       int64(cred.Authenticator.SignCount),
			Transports:      transports,
			Name:            name,
			BackupEligible:  cred.Flags.BackupEligible,
			BackupState:     cred.Flags.BackupState,
		})
		if err != nil {
			return domain.Internal("webauthn_cred_insert_failed", "failed to store WebAuthn credential").WithCause(err)
		}
		out = credRowFromSQLC(row)
		return nil
	})
	return out, err
}

// ListWebAuthnCredentials returns all registered credentials for a user.
func (tr *twoFARepo) ListWebAuthnCredentials(ctx context.Context, userID uuid.UUID) ([]WebAuthnCredentialRow, error) {
	var out []WebAuthnCredentialRow
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListWebAuthnCredentialsForUser(ctx, userID)
		if err != nil {
			return domain.Internal("webauthn_cred_list_failed", "failed to list WebAuthn credentials").WithCause(err)
		}
		out = make([]WebAuthnCredentialRow, len(rows))
		for i, row := range rows {
			out[i] = credRowFromSQLC(row)
		}
		return nil
	})
	return out, err
}

// GetWebAuthnCredentialByCredentialID loads a credential by its binary ID.
func (tr *twoFARepo) GetWebAuthnCredentialByCredentialID(ctx context.Context, credentialID []byte) (WebAuthnCredentialRow, error) {
	var out WebAuthnCredentialRow
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetWebAuthnCredentialByCredentialID(ctx, credentialID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("webauthn_cred_not_found", "WebAuthn credential not found")
			}
			return domain.Internal("webauthn_cred_get_failed", "failed to load WebAuthn credential").WithCause(err)
		}
		out = credRowFromSQLC(row)
		return nil
	})
	return out, err
}

// UpdateWebAuthnCredentialSignCount bumps sign_count + last_used_at after assertion.
func (tr *twoFARepo) UpdateWebAuthnCredentialSignCount(ctx context.Context, tx pgx.Tx, id uuid.UUID, signCount int64, backupState bool) error {
	return sqlc.New(tx).UpdateWebAuthnCredentialSignCount(ctx, sqlc.UpdateWebAuthnCredentialSignCountParams{
		ID:          id,
		SignCount:   signCount,
		BackupState: backupState,
	})
}

// DeleteWebAuthnCredential removes one credential. Returns NotFound if it did
// not exist or does not belong to the user.
func (tr *twoFARepo) DeleteWebAuthnCredential(ctx context.Context, id, userID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		n, err := sqlc.New(tx).DeleteWebAuthnCredential(ctx, sqlc.DeleteWebAuthnCredentialParams{
			ID:     id,
			UserID: userID,
		})
		if err != nil {
			return domain.Internal("webauthn_cred_delete_failed", "failed to delete WebAuthn credential").WithCause(err)
		}
		if n == 0 {
			return domain.NotFound("webauthn_cred_not_found", "WebAuthn credential not found")
		}
		return nil
	})
}

// CountWebAuthnCredentials returns the number of registered credentials.
func (tr *twoFARepo) CountWebAuthnCredentials(ctx context.Context, userID uuid.UUID) (int64, error) {
	var n int64
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		count, err := sqlc.New(tx).CountWebAuthnCredentials(ctx, userID)
		if err != nil {
			return domain.Internal("webauthn_cred_count_failed", "failed to count WebAuthn credentials").WithCause(err)
		}
		n = count
		return nil
	})
	return n, err
}

// --- webauthn_registration_sessions ---

// StoreWebAuthnRegistrationSession persists the go-webauthn SessionData between
// BeginRegistration and FinishRegistration.
func (tr *twoFARepo) StoreWebAuthnRegistrationSession(ctx context.Context, userID uuid.UUID, sessionData *webauthn.SessionData, ttl time.Duration) (uuid.UUID, error) {
	b, err := json.Marshal(sessionData)
	if err != nil {
		return uuid.Nil, domain.Internal("webauthn_session_marshal_failed", "failed to encode WebAuthn session").WithCause(err)
	}
	var id uuid.UUID
	err = tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).InsertWebAuthnRegistrationSession(ctx, sqlc.InsertWebAuthnRegistrationSessionParams{
			UserID:    userID,
			Session:   b,
			ExpiresAt: time.Now().Add(ttl),
		})
		if err != nil {
			return domain.Internal("webauthn_session_insert_failed", "failed to store WebAuthn registration session").WithCause(err)
		}
		id = row.ID
		return nil
	})
	return id, err
}

// LoadWebAuthnRegistrationSession retrieves the most recent non-expired
// registration session and parses the SessionData.
func (tr *twoFARepo) LoadWebAuthnRegistrationSession(ctx context.Context, userID uuid.UUID) (uuid.UUID, *webauthn.SessionData, error) {
	var sessionID uuid.UUID
	var sd webauthn.SessionData
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetWebAuthnRegistrationSession(ctx, userID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.Gone("webauthn_registration_expired", "WebAuthn registration session has expired; please restart enrollment")
			}
			return domain.Internal("webauthn_session_get_failed", "failed to load WebAuthn registration session").WithCause(err)
		}
		if err := json.Unmarshal(row.Session, &sd); err != nil {
			return domain.Internal("webauthn_session_unmarshal_failed", "failed to decode WebAuthn session").WithCause(err)
		}
		sessionID = row.ID
		return nil
	})
	return sessionID, &sd, err
}

// DeleteWebAuthnRegistrationSession removes a registration session after finish.
func (tr *twoFARepo) DeleteWebAuthnRegistrationSession(ctx context.Context, sessionID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).DeleteWebAuthnRegistrationSession(ctx, sessionID)
	})
}

// --- trusted_devices ---

// InsertTrustedDevice creates a new trusted-device record.
func (tr *twoFARepo) InsertTrustedDevice(ctx context.Context, userID uuid.UUID, tokenHash, label, userAgent string, ip *netip.Addr, expiresAt time.Time) (TrustedDevice, error) {
	var out TrustedDevice
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).InsertTrustedDevice(ctx, sqlc.InsertTrustedDeviceParams{
			UserID:    userID,
			TokenHash: tokenHash,
			Label:     label,
			UserAgent: userAgent,
			Ip:        ip,
			ExpiresAt: expiresAt,
		})
		if err != nil {
			return domain.Internal("trusted_device_insert_failed", "failed to create trusted device").WithCause(err)
		}
		out = trustedDeviceFromSQLC(row)
		return nil
	})
	return out, err
}

// GetTrustedDeviceByTokenHash loads an active, non-expired device by its token hash.
func (tr *twoFARepo) GetTrustedDeviceByTokenHash(ctx context.Context, tokenHash string) (TrustedDevice, error) {
	var out TrustedDevice
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		row, err := sqlc.New(tx).GetTrustedDeviceByTokenHash(ctx, tokenHash)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return domain.NotFound("trusted_device_not_found", "trusted device not found or expired")
			}
			return domain.Internal("trusted_device_get_failed", "failed to load trusted device").WithCause(err)
		}
		out = trustedDeviceFromSQLC(row)
		return nil
	})
	return out, err
}

// TouchTrustedDevice updates last_used_at.
func (tr *twoFARepo) TouchTrustedDevice(ctx context.Context, id uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).TouchTrustedDevice(ctx, id)
	})
}

// ListTrustedDevices returns all active (non-revoked, non-expired) devices.
func (tr *twoFARepo) ListTrustedDevices(ctx context.Context, userID uuid.UUID) ([]TrustedDevice, error) {
	var out []TrustedDevice
	err := tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		rows, err := sqlc.New(tx).ListTrustedDevicesForUser(ctx, userID)
		if err != nil {
			return domain.Internal("trusted_device_list_failed", "failed to list trusted devices").WithCause(err)
		}
		out = make([]TrustedDevice, len(rows))
		for i, row := range rows {
			out[i] = trustedDeviceFromSQLC(row)
		}
		return nil
	})
	return out, err
}

// RevokeTrustedDevice soft-deletes one device by ID + user guard.
func (tr *twoFARepo) RevokeTrustedDevice(ctx context.Context, id, userID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).RevokeTrustedDevice(ctx, sqlc.RevokeTrustedDeviceParams{
			ID:     id,
			UserID: userID,
		})
	})
}

// RevokeAllTrustedDevices revokes all active devices for a user.
func (tr *twoFARepo) RevokeAllTrustedDevices(ctx context.Context, userID uuid.UUID) error {
	return tr.r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		return sqlc.New(tx).RevokeAllTrustedDevicesForUser(ctx, userID)
	})
}

// CountTrustedDevices returns the count of active devices for a user (for audit metadata).
func (tr *twoFARepo) CountTrustedDevices(ctx context.Context, userID uuid.UUID) (int, error) {
	devs, err := tr.ListTrustedDevices(ctx, userID)
	return len(devs), err
}

// --- mapping helpers ---

func challengeFromSQLC(row sqlc.TwoFactorChallenge) TwoFactorChallenge {
	c := TwoFactorChallenge{
		ID:              row.ID,
		UserID:          row.UserID,
		ChallengeNonce:  row.ChallengeNonce,
		Kind:            row.Kind,
		WebAuthnSession: row.WebauthnSession,
		ExpiresAt:       row.ExpiresAt,
		Attempts:        row.Attempts,
		RequestedIP:     row.RequestedIp,
		CreatedAt:       row.CreatedAt,
	}
	if row.UsedAt.Valid {
		t := row.UsedAt.Time
		c.UsedAt = &t
	}
	return c
}

func credRowFromSQLC(row sqlc.WebauthnCredential) WebAuthnCredentialRow {
	r := WebAuthnCredentialRow{
		ID:              row.ID,
		UserID:          row.UserID,
		CredentialID:    row.CredentialID,
		PublicKey:       row.PublicKey,
		AttestationType: row.AttestationType,
		AAGUID:          row.Aaguid,
		SignCount:       row.SignCount,
		Transports:      row.Transports,
		Name:            row.Name,
		BackupEligible:  row.BackupEligible,
		BackupState:     row.BackupState,
		CreatedAt:       row.CreatedAt,
	}
	if row.LastUsedAt.Valid {
		t := row.LastUsedAt.Time
		r.LastUsedAt = &t
	}
	return r
}

func trustedDeviceFromSQLC(row sqlc.TrustedDevice) TrustedDevice {
	d := TrustedDevice{
		ID:        row.ID,
		UserID:    row.UserID,
		TokenHash: row.TokenHash,
		Label:     row.Label,
		UserAgent: row.UserAgent,
		IP:        row.Ip,
		CreatedAt: row.CreatedAt,
		ExpiresAt: row.ExpiresAt,
	}
	if row.LastUsedAt.Valid {
		t := row.LastUsedAt.Time
		d.LastUsedAt = &t
	}
	if row.RevokedAt.Valid {
		t := row.RevokedAt.Time
		d.RevokedAt = &t
	}
	return d
}
