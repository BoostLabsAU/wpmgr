package twofactor

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"

	"github.com/go-webauthn/webauthn/protocol"
	"github.com/go-webauthn/webauthn/webauthn"
	"github.com/google/uuid"
)

// WebAuthnFactor implements SecondFactor for WebAuthn Level 2 / FIDO2
// passkeys and hardware security keys (go-webauthn/webauthn v0.17.4).
//
// Registration ceremony:
//
//	BeginRegistration  -> wa.BeginRegistration(user) -> CredentialCreation JSON + SessionData
//	FinishRegistration -> wa.CreateCredential(user, session, parsed) -> persist webauthn_credentials row
//
// Authentication ceremony:
//
//	BeginLogin  -> wa.BeginLogin(user) -> CredentialAssertion JSON + SessionData
//	FinishLogin -> wa.ValidateLogin(user, session, parsed) -> update sign_count
//
// Sign-count clone detection: go-webauthn sets Authenticator.CloneWarning
// when the returned counter is not strictly greater than the stored value.
// This factor surfaces that via ErrClonedAuthenticator so the service layer
// can reject the assertion and audit a security event.
//
// User verification choice: for 2FA use (second factor on top of a password)
// we do NOT force UserVerificationRequired on the assertion, matching the
// WebAuthn best-practice for MFA scenarios where the device is the second
// factor and the user already proved their identity via password. We set
// UserVerificationPreferred so that UV-capable devices will still verify, but
// keys that do not support it (e.g. older FIDO U2F security keys) can still
// function. This is documented as a security decision in ADR-056.
type WebAuthnFactor struct {
	wa *webauthn.WebAuthn
}

// NewWebAuthnFactor constructs a WebAuthnFactor from a go-webauthn instance.
// The caller (main / server wiring) is responsible for building wa via
// NewWebAuthn(cfg) once at startup and sharing it here.
func NewWebAuthnFactor(wa *webauthn.WebAuthn) *WebAuthnFactor {
	return &WebAuthnFactor{wa: wa}
}

// Kind returns "webauthn".
func (f *WebAuthnFactor) Kind() string { return "webauthn" }

// BeginLogin starts a WebAuthn assertion ceremony for the given user.
//
// challengeMeta carries the user adapter (webauthn.User) as the input because
// the service layer is responsible for building the adapter from the DB
// credentials list. The return value is *webauthn.SessionData (JSON-serialised
// by the service layer into two_factor_challenges.webauthn_session).
//
// For Phase 2 the SecondFactor interface does not carry the user adapter; the
// service layer calls BeginLoginForUser instead, which returns the
// CredentialAssertion JSON and the SessionData separately.
func (f *WebAuthnFactor) BeginLogin(_ context.Context, _ uuid.UUID) (any, error) {
	// The thin SecondFactor interface is too narrow for WebAuthn because we need
	// the user's credentials list to populate allowedCredentials. The service
	// layer calls BeginLoginForUser directly.
	return nil, fmt.Errorf("WebAuthnFactor.BeginLogin: use BeginLoginForUser instead")
}

// BeginLoginForUser is the real WebAuthn assertion begin. It returns the
// JSON-encoded CredentialAssertion options for the client, and the SessionData
// for the service layer to persist.
func (f *WebAuthnFactor) BeginLoginForUser(user webauthn.User, opts ...webauthn.LoginOption) ([]byte, *webauthn.SessionData, error) {
	assertion, sessionData, err := f.wa.BeginLogin(user, opts...)
	if err != nil {
		return nil, nil, fmt.Errorf("webauthn begin login: %w", err)
	}
	b, err := json.Marshal(assertion)
	if err != nil {
		return nil, nil, fmt.Errorf("marshal assertion options: %w", err)
	}
	return b, sessionData, nil
}

// FinishLogin satisfies SecondFactor. challengeMeta must be *webauthn.SessionData
// (deserialized by the service layer from the challenge row). clientData is the
// raw JSON assertion response from the browser.
//
// On a successful assertion it returns nil. On a cloned-authenticator signal it
// returns ErrClonedAuthenticator. On other failures it returns the underlying
// protocol error.
func (f *WebAuthnFactor) FinishLogin(_ context.Context, _ uuid.UUID, challengeMeta any, clientData []byte) error {
	_, _, err := f.ValidateLoginBytes(nil, challengeMeta, clientData)
	return err
}

// ValidateLoginBytes validates the raw assertion bytes against the session and
// the user. It returns the matched credential (so the service layer can update
// sign_count and check CloneWarning), the updated credential, and any error.
//
// user must implement webauthn.User with the correct credentials loaded from
// the DB. If user is nil the function uses a discoverable-login flow (not
// supported in v1; pass a non-nil user).
func (f *WebAuthnFactor) ValidateLoginBytes(user webauthn.User, challengeMeta any, assertionJSON []byte) (*webauthn.Credential, *webauthn.SessionData, error) {
	session, ok := challengeMeta.(*webauthn.SessionData)
	if !ok {
		return nil, nil, fmt.Errorf("WebAuthn FinishLogin: challengeMeta must be *webauthn.SessionData, got %T", challengeMeta)
	}

	parsedResponse, err := protocol.ParseCredentialRequestResponseBytes(assertionJSON)
	if err != nil {
		return nil, nil, fmt.Errorf("parse assertion response: %w", err)
	}

	cred, err := f.wa.ValidateLogin(user, *session, parsedResponse)
	if err != nil {
		return nil, nil, fmt.Errorf("validate login: %w", err)
	}

	// go-webauthn sets CloneWarning when the returned sign count is not strictly
	// greater than the stored value (and both are non-zero). Treat this as a
	// fatal rejection so the service layer can audit it.
	if cred.Authenticator.CloneWarning {
		return cred, session, ErrClonedAuthenticator
	}

	return cred, session, nil
}

// BeginRegistration starts a WebAuthn registration ceremony for the given user.
// It calls BeginRegistrationForUser with the same user adapter pattern as login.
func (f *WebAuthnFactor) BeginRegistration(_ context.Context, _ uuid.UUID, _ string) (any, error) {
	// The thin SecondFactor interface is too narrow for WebAuthn registration.
	// The service layer calls BeginRegistrationForUser instead.
	return nil, fmt.Errorf("WebAuthnFactor.BeginRegistration: use BeginRegistrationForUser instead")
}

// BeginRegistrationForUser is the real WebAuthn registration begin. It returns
// the JSON-encoded CredentialCreation options and the SessionData for storage.
//
// opts is optional; callers may pass webauthn.WithAuthenticatorSelection to
// control UserVerification/ResidentKey preferences. The default behaviour
// (no opts) uses the RP's configured AuthenticatorSelection, which is set to
// UserVerificationPreferred in NewWebAuthn. This is intentional: for a 2FA
// scenario the device is the second factor; we prefer UV but do not require it
// so that FIDO U2F security keys without a PIN still work.
func (f *WebAuthnFactor) BeginRegistrationForUser(user webauthn.User, opts ...webauthn.RegistrationOption) ([]byte, *webauthn.SessionData, error) {
	creation, sessionData, err := f.wa.BeginRegistration(user, opts...)
	if err != nil {
		return nil, nil, fmt.Errorf("webauthn begin registration: %w", err)
	}
	b, err := json.Marshal(creation)
	if err != nil {
		return nil, nil, fmt.Errorf("marshal credential creation options: %w", err)
	}
	return b, sessionData, nil
}

// FinishRegistration satisfies SecondFactor. setupData must be *webauthn.SessionData
// (stored by the service layer during BeginRegistrationForUser). clientData is
// the raw JSON attestation response from the browser. This method only validates;
// it does NOT persist the credential -- the service layer does that.
func (f *WebAuthnFactor) FinishRegistration(_ context.Context, _ uuid.UUID, setupData any, clientData []byte) error {
	_, err := f.CreateCredentialFromBytes(nil, setupData, clientData)
	return err
}

// CreateCredentialFromBytes validates the registration response bytes and
// returns the verified Credential. The service layer persists it.
//
// user must implement webauthn.User. sessionData must be *webauthn.SessionData.
func (f *WebAuthnFactor) CreateCredentialFromBytes(user webauthn.User, sessionData any, attestationJSON []byte) (*webauthn.Credential, error) {
	session, ok := sessionData.(*webauthn.SessionData)
	if !ok {
		return nil, fmt.Errorf("WebAuthn FinishRegistration: sessionData must be *webauthn.SessionData, got %T", sessionData)
	}

	parsedResponse, err := protocol.ParseCredentialCreationResponseBytes(attestationJSON)
	if err != nil {
		return nil, fmt.Errorf("parse attestation response: %w", err)
	}

	cred, err := f.wa.CreateCredential(user, *session, parsedResponse)
	if err != nil {
		return nil, fmt.Errorf("create credential: %w", err)
	}

	return cred, nil
}

// ErrClonedAuthenticator is returned when the WebAuthn sign count indicates a
// possible cloned authenticator (returned count was not strictly greater than
// the stored count, and at least one of them is non-zero). The service layer
// MUST reject the authentication and audit ActionClonedAuthenticatorDetected.
var ErrClonedAuthenticator = fmt.Errorf("cloned_authenticator: sign count regression detected")

// WebAuthnUser is the adapter that presents an auth.User + their credentials
// to the go-webauthn library as a webauthn.User. The library requires this
// interface during both registration and assertion ceremonies.
//
// Security: WebAuthnID returns the user's UUID bytes. The spec says the user
// handle must be an opaque byte sequence; a UUID is perfectly opaque and fits
// within the 64-byte maximum. The library does NOT display this to the user.
type WebAuthnUser struct {
	ID          uuid.UUID
	Email       string
	DisplayName string
	Credentials []webauthn.Credential
}

// WebAuthnID returns the user's UUID as a raw byte array. This is stored in the
// authenticator and returned in assertion responses as the user handle.
func (u *WebAuthnUser) WebAuthnID() []byte {
	b := u.ID
	return b[:]
}

// WebAuthnName returns the user's email address (the username/account name
// shown in authenticator account lists).
func (u *WebAuthnUser) WebAuthnName() string { return u.Email }

// WebAuthnDisplayName returns the user's display name (may be the same as
// the email when no display name is set).
func (u *WebAuthnUser) WebAuthnDisplayName() string {
	if u.DisplayName != "" {
		return u.DisplayName
	}
	return u.Email
}

// WebAuthnCredentials returns the slice of registered WebAuthn credentials for
// this user. The library uses this to populate allowedCredentials during login
// and to detect duplicate registrations during registration.
func (u *WebAuthnUser) WebAuthnCredentials() []webauthn.Credential {
	return u.Credentials
}

// BuildCredentialFromRow converts a stored WebAuthnCredentialRow into the
// go-webauthn Credential type needed by the WebAuthnUser adapter. This is
// called by the service layer when building the user adapter for a ceremony.
func BuildCredentialFromRow(
	credentialID []byte,
	publicKey []byte,
	attestationType string,
	aaguid []byte,
	signCount int64,
	transports []string,
	backupEligible bool,
	backupState bool,
) webauthn.Credential {
	waTransports := make([]protocol.AuthenticatorTransport, len(transports))
	for i, t := range transports {
		waTransports[i] = protocol.AuthenticatorTransport(t)
	}
	return webauthn.Credential{
		ID:              credentialID,
		PublicKey:       publicKey,
		AttestationType: attestationType,
		Transport:       waTransports,
		Flags: webauthn.CredentialFlags{
			BackupEligible: backupEligible,
			BackupState:    backupState,
		},
		Authenticator: webauthn.Authenticator{
			AAGUID:    aaguid,
			SignCount: uint32(signCount),
		},
	}
}

// MarshalSessionData JSON-encodes a go-webauthn SessionData for storage in
// two_factor_challenges.webauthn_session (JSONB column).
func MarshalSessionData(sd *webauthn.SessionData) ([]byte, error) {
	b, err := json.Marshal(sd)
	if err != nil {
		return nil, fmt.Errorf("marshal WebAuthn session data: %w", err)
	}
	return b, nil
}

// UnmarshalSessionData JSON-decodes go-webauthn SessionData from the stored
// JSONB column value.
func UnmarshalSessionData(b []byte) (*webauthn.SessionData, error) {
	var sd webauthn.SessionData
	if err := json.NewDecoder(io.NopCloser(bytes.NewReader(b))).Decode(&sd); err != nil {
		return nil, fmt.Errorf("unmarshal WebAuthn session data: %w", err)
	}
	return &sd, nil
}
