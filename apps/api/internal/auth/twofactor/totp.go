package twofactor

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
	"github.com/pquerna/otp"
	"github.com/pquerna/otp/totp"
)

// TOTPFactor implements SecondFactor for RFC 6238 time-based one-time passwords.
//
// TOTP is stateless on the server side during login: the 6-digit code is
// derived from the shared secret + current time, so no per-challenge state is
// required from the server for the TOTP verify step. The only server-side
// state is the encrypted shared secret stored in users.totp_secret_encrypted.
//
// For enrollment, TOTPFactor generates a new secret via pquerna/otp and
// returns it as a TOTPSetup. The secret is not persisted until FinishRegistration
// proves the client has successfully loaded it into their authenticator app.
//
// Clock-skew tolerance: ValidateOpts.Skew = 1 accepts the current period and
// one period in each direction (effective +/- 30-second window). RFC 6238
// recommends at most one step of skew.
//
// The TOTPFactor implementor is stateless and safe to share across goroutines.
// It is the caller's responsibility (the service layer, not this type) to:
//   - Store/retrieve the encrypted secret from the database.
//   - Enforce replay protection via the totp_last_step column.
//   - Record audit events.
type TOTPFactor struct {
	// issuer is the account issuer string shown in authenticator apps.
	issuer string
}

// NewTOTPFactor constructs a TOTPFactor with the given issuer label
// (e.g. "WPMgr"). The issuer appears in the otpauth URI and in authenticator
// app account listings.
func NewTOTPFactor(issuer string) *TOTPFactor {
	return &TOTPFactor{issuer: issuer}
}

// Kind returns "totp".
func (f *TOTPFactor) Kind() string { return "totp" }

// BeginLogin is a no-op for TOTP: the server does not need to issue a
// server-side challenge value because the time-step is the implicit challenge.
// The service layer creates the two_factor_challenges row with a nonce of its
// own. This method satisfies the SecondFactor interface; it always returns
// (nil, nil).
func (f *TOTPFactor) BeginLogin(_ context.Context, _ uuid.UUID) (any, error) {
	return nil, nil
}

// FinishLogin verifies a 6-digit TOTP code against the decrypted shared
// secret.
//
// clientData must be the 6-digit code as ASCII bytes (e.g. []byte("123456")).
// challengeMeta is ignored for TOTP (always nil).
// secret is the PLAINTEXT base32 secret already decrypted by the service
// layer; TOTPFactor never touches the database.
//
// Returns nil on success. Returns a typed error on failure:
//   - ErrInvalidTOTPCode  for an incorrect code.
//
// Replay protection (checking totp_last_step) is the service layer's
// responsibility because it requires a database transaction; this method only
// validates the code's mathematical correctness.
func (f *TOTPFactor) ValidateCode(code, secret string) (bool, int64, error) {
	now := time.Now()
	period := int64(30)
	skew := int64(1)

	// S3: probe each candidate step in the skew window and return the EXACT step
	// that matched. Returning the current step regardless of which step actually
	// matched was a replay-protection gap: if the user presented a code for
	// step N-1, we stored step N as last_step, allowing them to use that same
	// N-1 code again in the next call (because N-1 != N so it would not be
	// rejected). By returning the actual matched step we burn the exact step the
	// code was derived from, closing the replay window within the skew tolerance.
	currentStep := now.Unix() / period
	for delta := -skew; delta <= skew; delta++ {
		candidate := currentStep + delta
		t := time.Unix(candidate*period, 0)
		ok, err := totp.ValidateCustom(code, secret, t, totp.ValidateOpts{
			Period:    uint(period),
			Skew:      0, // we handle skew ourselves to know which step matched
			Digits:    otp.DigitsSix,
			Algorithm: otp.AlgorithmSHA1,
		})
		if err != nil {
			return false, 0, fmt.Errorf("totp validate: %w", err)
		}
		if ok {
			return true, candidate, nil
		}
	}
	return false, 0, nil
}

// BeginRegistration generates a new TOTP secret for the given user + email and
// returns a *TOTPSetup. The secret is NOT persisted here; the service layer
// stores it as a provisional secret. FinishRegistration (via the service layer)
// commits it after the user verifies a live code.
//
// userEmail is used as the account name in the otpauth URI.
func (f *TOTPFactor) BeginRegistration(_ context.Context, _ uuid.UUID, userEmail string) (any, error) {
	key, err := totp.Generate(totp.GenerateOpts{
		Issuer:      f.issuer,
		AccountName: userEmail,
		Period:      30,
		Digits:      otp.DigitsSix,
		Algorithm:   otp.AlgorithmSHA1,
	})
	if err != nil {
		return nil, fmt.Errorf("generate TOTP key: %w", err)
	}
	return &TOTPSetup{
		OtpAuthURI: key.URL(),
		Secret:     key.Secret(),
	}, nil
}

// FinishRegistration is a pass-through that validates the 6-digit code against
// the provisional plaintext secret. It does NOT touch the database; the service
// layer performs the DB writes (clear provisional, store confirmed secret,
// insert recovery codes, set two_factor_enabled).
//
// setupData must be a *TOTPSetup returned by BeginRegistration.
// clientData must be the 6-digit code as ASCII bytes.
//
// Returns nil on success. Returns an error if the code is invalid.
func (f *TOTPFactor) FinishRegistration(_ context.Context, _ uuid.UUID, setupData any, clientData []byte) error {
	setup, ok := setupData.(*TOTPSetup)
	if !ok {
		return fmt.Errorf("invalid TOTP setup data type %T", setupData)
	}
	valid, _, err := f.ValidateCode(string(clientData), setup.Secret)
	if err != nil {
		return fmt.Errorf("validate TOTP enrollment code: %w", err)
	}
	if !valid {
		return ErrInvalidTOTPCode
	}
	return nil
}

// FinishLogin satisfies SecondFactor. The secret must be supplied via
// challengeMeta as the plaintext base32 string (the service layer decrypts
// and passes it).
func (f *TOTPFactor) FinishLogin(_ context.Context, _ uuid.UUID, challengeMeta any, clientData []byte) error {
	secret, ok := challengeMeta.(string)
	if !ok {
		return fmt.Errorf("TOTP FinishLogin: challengeMeta must be the plaintext secret string, got %T", challengeMeta)
	}
	valid, _, err := f.ValidateCode(string(clientData), secret)
	if err != nil {
		return fmt.Errorf("validate TOTP login code: %w", err)
	}
	if !valid {
		return ErrInvalidTOTPCode
	}
	return nil
}

// ErrInvalidTOTPCode is returned when the submitted 6-digit code is
// mathematically incorrect for the stored secret + current time.
// The service layer maps this to domain.Unauthorized("invalid_totp_code").
var ErrInvalidTOTPCode = fmt.Errorf("invalid TOTP code")
