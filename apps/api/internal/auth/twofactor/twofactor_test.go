package twofactor_test

import (
	"context"
	"encoding/hex"
	"strings"
	"testing"
	"time"

	"github.com/go-webauthn/webauthn/webauthn"
	"github.com/google/uuid"
	"github.com/pquerna/otp/totp"

	"github.com/mosamlife/wpmgr/apps/api/internal/auth/twofactor"
	"github.com/mosamlife/wpmgr/apps/api/internal/cryptbox"
)

// ---------------------------------------------------------------------------
// Phase 1 (preserved): WebAuthn construction + interface checks
// ---------------------------------------------------------------------------

func TestWebAuthnConstruction(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, err := twofactor.NewWebAuthn(cfg)
	if err != nil {
		t.Fatalf("NewWebAuthn: unexpected error: %v", err)
	}
	if wa == nil {
		t.Fatal("NewWebAuthn returned nil instance")
	}
}

func TestWebAuthnConstructionInvalidOrigin(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "example.com",
		RPOrigins:     nil,
		RPDisplayName: "Test",
	}
	_, err := twofactor.NewWebAuthn(cfg)
	if err == nil {
		t.Fatal("NewWebAuthn with no RPOrigins should return an error, got nil")
	}
}

func TestParseRPOrigins(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  []string
	}{
		{
			name:  "single origin",
			input: "https://manage.wpmgr.app",
			want:  []string{"https://manage.wpmgr.app"},
		},
		{
			name:  "multiple origins with spaces",
			input: "https://manage.wpmgr.app, https://staging.wpmgr.app",
			want:  []string{"https://manage.wpmgr.app", "https://staging.wpmgr.app"},
		},
		{
			name:  "empty string returns nil",
			input: "",
			want:  nil,
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := twofactor.ParseRPOrigins(tt.input)
			if len(got) != len(tt.want) {
				t.Fatalf("ParseRPOrigins(%q) = %v, want %v", tt.input, got, tt.want)
			}
			for i := range got {
				if got[i] != tt.want[i] {
					t.Errorf("ParseRPOrigins(%q)[%d] = %q, want %q", tt.input, i, got[i], tt.want[i])
				}
			}
		})
	}
}

func TestCryptboxRoundTrip(t *testing.T) {
	box, err := cryptbox.NewAgeIdentity("")
	if err != nil {
		t.Fatalf("NewAgeIdentity: %v", err)
	}
	plaintext := []byte("JBSWY3DPEHPK3PXP")
	ciphertext, err := box.Encrypt(plaintext)
	if err != nil {
		t.Fatalf("Encrypt: %v", err)
	}
	if len(ciphertext) == 0 {
		t.Fatal("Encrypt returned empty ciphertext")
	}
	if string(ciphertext) == string(plaintext) {
		t.Fatal("ciphertext should differ from plaintext")
	}
	decrypted, err := box.Decrypt(ciphertext)
	if err != nil {
		t.Fatalf("Decrypt: %v", err)
	}
	if string(decrypted) != string(plaintext) {
		t.Fatalf("round-trip mismatch: got %q, want %q", decrypted, plaintext)
	}
}

func TestTOTPFactorKind(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	if f.Kind() != "totp" {
		t.Fatalf("TOTPFactor.Kind() = %q, want %q", f.Kind(), "totp")
	}
}

func TestWebAuthnFactorKind(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, err := twofactor.NewWebAuthn(cfg)
	if err != nil {
		t.Fatalf("NewWebAuthn: %v", err)
	}
	f := twofactor.NewWebAuthnFactor(wa)
	if f.Kind() != "webauthn" {
		t.Fatalf("WebAuthnFactor.Kind() = %q, want %q", f.Kind(), "webauthn")
	}
}

func TestSecondFactorInterface(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, err := twofactor.NewWebAuthn(cfg)
	if err != nil {
		t.Fatalf("NewWebAuthn: %v", err)
	}
	var _ twofactor.SecondFactor = twofactor.NewTOTPFactor("WPMgr")
	var _ twofactor.SecondFactor = twofactor.NewWebAuthnFactor(wa)
	t.Log("both TOTPFactor and WebAuthnFactor satisfy twofactor.SecondFactor")
}

// ---------------------------------------------------------------------------
// Phase 2: TOTP enrollment + validation
// ---------------------------------------------------------------------------

func TestTOTPBeginRegistration(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, err := f.BeginRegistration(ctx, userID, "user@example.com")
	if err != nil {
		t.Fatalf("BeginRegistration: %v", err)
	}
	setup, ok := setupAny.(*twofactor.TOTPSetup)
	if !ok {
		t.Fatalf("BeginRegistration returned %T, want *twofactor.TOTPSetup", setupAny)
	}
	if setup.OtpAuthURI == "" {
		t.Error("OtpAuthURI is empty")
	}
	if !strings.HasPrefix(setup.OtpAuthURI, "otpauth://totp/") {
		t.Errorf("OtpAuthURI should start with otpauth://totp/, got %q", setup.OtpAuthURI)
	}
	if setup.Secret == "" {
		t.Error("Secret is empty")
	}
	// The secret should be base32: uppercase + digits.
	for _, c := range setup.Secret {
		if !strings.ContainsRune("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567", c) {
			t.Errorf("Secret contains non-base32 char %q", c)
		}
	}
}

func TestTOTPFinishRegistrationValidCode(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, err := f.BeginRegistration(ctx, userID, "user@example.com")
	if err != nil {
		t.Fatalf("BeginRegistration: %v", err)
	}
	setup := setupAny.(*twofactor.TOTPSetup)

	// Generate a valid code from the secret.
	code, err := totp.GenerateCode(setup.Secret, time.Now())
	if err != nil {
		t.Fatalf("GenerateCode: %v", err)
	}

	if err := f.FinishRegistration(ctx, userID, setup, []byte(code)); err != nil {
		t.Fatalf("FinishRegistration with valid code: %v", err)
	}
}

func TestTOTPFinishRegistrationInvalidCode(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	err := f.FinishRegistration(ctx, userID, setup, []byte("000000"))
	if err == nil {
		t.Fatal("FinishRegistration with wrong code should fail, got nil")
	}
}

func TestTOTPValidateCodeCorrect(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	code, err := totp.GenerateCode(setup.Secret, time.Now())
	if err != nil {
		t.Fatalf("GenerateCode: %v", err)
	}

	valid, step, err := f.ValidateCode(code, setup.Secret)
	if err != nil {
		t.Fatalf("ValidateCode: %v", err)
	}
	if !valid {
		t.Error("ValidateCode returned false for a freshly generated code")
	}
	// Step should be floor(unix/30).
	expectedStep := time.Now().Unix() / 30
	if step < expectedStep-1 || step > expectedStep+1 {
		t.Errorf("step %d not within +-1 of expected %d", step, expectedStep)
	}
}

func TestTOTPValidateCodeWrong(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	valid, _, _ := f.ValidateCode("000000", setup.Secret)
	if valid {
		t.Error("ValidateCode returned true for code 000000 with a random secret")
	}
}

// TestTOTPReplayProtection verifies that two calls with the same time step
// would be detected as a replay by the service layer's step comparison.
// The factor itself just returns the step; the caller is responsible for
// rejecting a previously accepted step.
func TestTOTPReplayProtection(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	code, _ := totp.GenerateCode(setup.Secret, time.Now())

	// First validation succeeds and returns a step.
	valid1, step1, err1 := f.ValidateCode(code, setup.Secret)
	if err1 != nil || !valid1 {
		t.Fatalf("first validation should succeed: valid=%v err=%v", valid1, err1)
	}

	// Second validation with the same code also passes (the factor is stateless).
	valid2, step2, err2 := f.ValidateCode(code, setup.Secret)
	if err2 != nil || !valid2 {
		t.Fatalf("second validation should also be valid from the factor's POV: valid=%v err=%v", valid2, err2)
	}

	// The caller detects the replay by comparing the returned steps.
	if step1 != step2 {
		t.Error("same code in same window should return the same step")
	}
	// The service would reject step2 because step1 == step2 == lastAcceptedStep.
	t.Logf("replay detection: both calls returned step=%d; service would reject the second", step1)
}

// TestTOTPFinishLoginUsesSecret verifies that FinishLogin correctly uses the
// secret passed as challengeMeta (the service layer's decrypted secret).
func TestTOTPFinishLoginUsesSecret(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()

	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	code, _ := totp.GenerateCode(setup.Secret, time.Now())

	// Correct secret in challengeMeta => success.
	if err := f.FinishLogin(ctx, userID, setup.Secret, []byte(code)); err != nil {
		t.Fatalf("FinishLogin with correct secret: %v", err)
	}

	// Wrong secret => failure.
	if err := f.FinishLogin(ctx, userID, "WRONGWRONGWRONGW", []byte(code)); err == nil {
		t.Fatal("FinishLogin with wrong secret should fail")
	}
}

// ---------------------------------------------------------------------------
// Phase 2: Recovery code generation
// ---------------------------------------------------------------------------

func TestGenerateRecoveryCodesCount(t *testing.T) {
	codes, err := twofactor.GenerateRecoveryCodes()
	if err != nil {
		t.Fatalf("GenerateRecoveryCodes: %v", err)
	}
	if len(codes) != twofactor.RecoveryCodeCount {
		t.Errorf("got %d codes, want %d", len(codes), twofactor.RecoveryCodeCount)
	}
}

func TestGenerateRecoveryCodesFormat(t *testing.T) {
	codes, err := twofactor.GenerateRecoveryCodes()
	if err != nil {
		t.Fatalf("GenerateRecoveryCodes: %v", err)
	}
	for i, c := range codes {
		if len(c) != 11 {
			t.Errorf("code[%d] %q: want length 11 (XXXXX-XXXXX), got %d", i, c, len(c))
		}
		if c[5] != '-' {
			t.Errorf("code[%d] %q: missing hyphen at position 5", i, c)
		}
		parts := strings.Split(c, "-")
		if len(parts) != 2 || len(parts[0]) != 5 || len(parts[1]) != 5 {
			t.Errorf("code[%d] %q: unexpected format", i, c)
		}
	}
}

func TestGenerateRecoveryCodesUnique(t *testing.T) {
	codes, _ := twofactor.GenerateRecoveryCodes()
	seen := make(map[string]bool, len(codes))
	for _, c := range codes {
		if seen[c] {
			t.Fatalf("duplicate recovery code %q", c)
		}
		seen[c] = true
	}
}

func TestGenerateRecoveryCodesEntropy(t *testing.T) {
	// Generate 100 batches and verify no code appears twice across batches,
	// which would indicate dangerously low entropy.
	seen := make(map[string]bool)
	for range 100 {
		codes, err := twofactor.GenerateRecoveryCodes()
		if err != nil {
			t.Fatalf("GenerateRecoveryCodes: %v", err)
		}
		for _, c := range codes {
			if seen[c] {
				t.Fatalf("recovery code %q appeared in two different batches", c)
			}
			seen[c] = true
		}
	}
}

func TestNormalizeRecoveryCode(t *testing.T) {
	tests := []struct {
		input string
		want  string
	}{
		{"ABCDE-FGHJK", "ABCDEFGHJK"},
		{"abcde-fghjk", "ABCDEFGHJK"},
		{"ABCDE FGHJK", "ABCDEFGHJK"},
		{"abcdefghjk", "ABCDEFGHJK"},
		{"  ABCDE-FGHJK  ", "ABCDEFGHJK"},
	}
	for _, tt := range tests {
		got := twofactor.NormalizeRecoveryCode(tt.input)
		if got != tt.want {
			t.Errorf("NormalizeRecoveryCode(%q) = %q, want %q", tt.input, got, tt.want)
		}
	}
}

// ---------------------------------------------------------------------------
// Phase 2: WebAuthn user adapter
// ---------------------------------------------------------------------------

func TestWebAuthnUserAdapter(t *testing.T) {
	id := uuid.MustParse("11111111-1111-1111-1111-111111111111")
	u := &twofactor.WebAuthnUser{
		ID:          id,
		Email:       "user@example.com",
		DisplayName: "Test User",
		Credentials: nil,
	}

	// WebAuthnID must be the UUID bytes.
	wid := u.WebAuthnID()
	if len(wid) != 16 {
		t.Fatalf("WebAuthnID length: got %d, want 16", len(wid))
	}
	if uid, err := uuid.FromBytes(wid); err != nil || uid != id {
		t.Errorf("WebAuthnID bytes did not round-trip to original UUID: %v / %v", err, uid)
	}

	if u.WebAuthnName() != "user@example.com" {
		t.Errorf("WebAuthnName: got %q, want %q", u.WebAuthnName(), "user@example.com")
	}
	if u.WebAuthnDisplayName() != "Test User" {
		t.Errorf("WebAuthnDisplayName: got %q, want %q", u.WebAuthnDisplayName(), "Test User")
	}
	if u.WebAuthnCredentials() != nil {
		t.Errorf("WebAuthnCredentials: got %v, want nil", u.WebAuthnCredentials())
	}
}

func TestWebAuthnUserAdapterDisplayNameFallback(t *testing.T) {
	u := &twofactor.WebAuthnUser{
		ID:          uuid.New(),
		Email:       "user@example.com",
		DisplayName: "", // empty => should fall back to email
	}
	if u.WebAuthnDisplayName() != "user@example.com" {
		t.Errorf("DisplayName fallback: got %q, want %q", u.WebAuthnDisplayName(), "user@example.com")
	}
}

func TestWebAuthnUserAdapterImplementsInterface(t *testing.T) {
	// Compile-time check that WebAuthnUser satisfies webauthn.User.
	var _ webauthn.User = (*twofactor.WebAuthnUser)(nil)
	t.Log("WebAuthnUser satisfies webauthn.User interface")
}

// ---------------------------------------------------------------------------
// Phase 2: BuildCredentialFromRow
// ---------------------------------------------------------------------------

func TestBuildCredentialFromRow(t *testing.T) {
	credID := []byte{0x01, 0x02, 0x03}
	publicKey := []byte{0x04, 0x05}
	aaguid := make([]byte, 16)
	transports := []string{"internal", "usb"}

	cred := twofactor.BuildCredentialFromRow(
		credID, publicKey, "none", aaguid,
		42, transports, true, false,
	)

	if string(cred.ID) != string(credID) {
		t.Errorf("CredentialID mismatch")
	}
	if string(cred.PublicKey) != string(publicKey) {
		t.Errorf("PublicKey mismatch")
	}
	if cred.Authenticator.SignCount != 42 {
		t.Errorf("SignCount: got %d, want 42", cred.Authenticator.SignCount)
	}
	if len(cred.Transport) != 2 {
		t.Errorf("Transports: got %d, want 2", len(cred.Transport))
	}
	if !cred.Flags.BackupEligible {
		t.Error("BackupEligible should be true")
	}
	if cred.Flags.BackupState {
		t.Error("BackupState should be false")
	}
}

// ---------------------------------------------------------------------------
// Phase 2: MarshalSessionData / UnmarshalSessionData
// ---------------------------------------------------------------------------

func TestMarshalUnmarshalSessionData(t *testing.T) {
	original := &webauthn.SessionData{
		Challenge:      "test-challenge-string",
		RelyingPartyID: "localhost",
		UserID:         []byte{1, 2, 3, 4},
	}

	b, err := twofactor.MarshalSessionData(original)
	if err != nil {
		t.Fatalf("MarshalSessionData: %v", err)
	}
	if len(b) == 0 {
		t.Fatal("marshalled bytes are empty")
	}

	roundTripped, err := twofactor.UnmarshalSessionData(b)
	if err != nil {
		t.Fatalf("UnmarshalSessionData: %v", err)
	}
	if roundTripped.Challenge != original.Challenge {
		t.Errorf("Challenge: got %q, want %q", roundTripped.Challenge, original.Challenge)
	}
	if roundTripped.RelyingPartyID != original.RelyingPartyID {
		t.Errorf("RelyingPartyID: got %q, want %q", roundTripped.RelyingPartyID, original.RelyingPartyID)
	}
}

// ---------------------------------------------------------------------------
// Phase 2: WebAuthn factor BeginLogin/BeginRegistration stubs
// ---------------------------------------------------------------------------

func TestWebAuthnFactorBeginLoginReturnsError(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, _ := twofactor.NewWebAuthn(cfg)
	f := twofactor.NewWebAuthnFactor(wa)

	_, err := f.BeginLogin(context.Background(), uuid.New())
	if err == nil {
		t.Fatal("BeginLogin should return error directing caller to use BeginLoginForUser")
	}
}

func TestWebAuthnFactorBeginRegistrationReturnsError(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, _ := twofactor.NewWebAuthn(cfg)
	f := twofactor.NewWebAuthnFactor(wa)

	_, err := f.BeginRegistration(context.Background(), uuid.New(), "user@example.com")
	if err == nil {
		t.Fatal("BeginRegistration should return error directing caller to use BeginRegistrationForUser")
	}
}

// TestWebAuthnFactorBeginLoginForUser verifies that BeginLoginForUser returns
// valid JSON assertion options for a user with credentials.
func TestWebAuthnFactorBeginLoginForUser(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, err := twofactor.NewWebAuthn(cfg)
	if err != nil {
		t.Fatalf("NewWebAuthn: %v", err)
	}
	f := twofactor.NewWebAuthnFactor(wa)

	// Build a user with a fake credential so BeginLogin does not reject "no credentials".
	credID, _ := hex.DecodeString("0102030405060708")
	fakePublicKey := make([]byte, 32)
	cred := twofactor.BuildCredentialFromRow(
		credID, fakePublicKey, "none", make([]byte, 16),
		0, nil, false, false,
	)
	user := &twofactor.WebAuthnUser{
		ID:          uuid.New(),
		Email:       "user@example.com",
		DisplayName: "Test User",
		Credentials: []webauthn.Credential{cred},
	}

	assertionJSON, sessionData, err := f.BeginLoginForUser(user)
	if err != nil {
		t.Fatalf("BeginLoginForUser: %v", err)
	}
	if len(assertionJSON) == 0 {
		t.Error("assertionJSON is empty")
	}
	if sessionData == nil {
		t.Error("sessionData is nil")
	}
	if sessionData.Challenge == "" {
		t.Error("sessionData.Challenge is empty")
	}
	if sessionData.RelyingPartyID != "localhost" {
		t.Errorf("sessionData.RelyingPartyID: got %q, want %q", sessionData.RelyingPartyID, "localhost")
	}
}

// TestWebAuthnFactorBeginRegistrationForUser verifies that BeginRegistrationForUser
// returns valid JSON creation options for a new user.
func TestWebAuthnFactorBeginRegistrationForUser(t *testing.T) {
	cfg := twofactor.Config{
		RPID:          "localhost",
		RPOrigins:     []string{"http://localhost:3000"},
		RPDisplayName: "WPMgr Test",
	}
	wa, err := twofactor.NewWebAuthn(cfg)
	if err != nil {
		t.Fatalf("NewWebAuthn: %v", err)
	}
	f := twofactor.NewWebAuthnFactor(wa)

	user := &twofactor.WebAuthnUser{
		ID:          uuid.New(),
		Email:       "newuser@example.com",
		DisplayName: "New User",
		Credentials: nil,
	}

	creationJSON, sessionData, err := f.BeginRegistrationForUser(user)
	if err != nil {
		t.Fatalf("BeginRegistrationForUser: %v", err)
	}
	if len(creationJSON) == 0 {
		t.Error("creationJSON is empty")
	}
	if sessionData == nil {
		t.Error("sessionData is nil")
	}
	if sessionData.Challenge == "" {
		t.Error("sessionData.Challenge is empty")
	}
}

// ---------------------------------------------------------------------------
// Phase 2: Clone detection logic (unit test via ValidateLoginBytes)
// ---------------------------------------------------------------------------

// TestWebAuthnCloneDetectionSentinel verifies that ErrClonedAuthenticator is a
// non-nil sentinel error that can be compared with errors.Is.
func TestWebAuthnCloneDetectionSentinel(t *testing.T) {
	if twofactor.ErrClonedAuthenticator == nil {
		t.Fatal("ErrClonedAuthenticator should not be nil")
	}
	// Verify it is a distinct value.
	if twofactor.ErrClonedAuthenticator == twofactor.ErrInvalidTOTPCode {
		t.Error("ErrClonedAuthenticator should not equal ErrInvalidTOTPCode")
	}
}

// ---------------------------------------------------------------------------
// Phase 2: TOTPSetup type assertions
// ---------------------------------------------------------------------------

func TestTOTPSetupOtpAuthURIContainsIssuer(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr-Test-Issuer")
	setupAny, err := f.BeginRegistration(context.Background(), uuid.New(), "test@example.com")
	if err != nil {
		t.Fatalf("BeginRegistration: %v", err)
	}
	setup := setupAny.(*twofactor.TOTPSetup)
	if !strings.Contains(setup.OtpAuthURI, "WPMgr-Test-Issuer") {
		t.Errorf("OtpAuthURI should contain issuer: %q", setup.OtpAuthURI)
	}
	if !strings.Contains(setup.OtpAuthURI, "test%40example.com") && !strings.Contains(setup.OtpAuthURI, "test@example.com") {
		t.Errorf("OtpAuthURI should contain the email: %q", setup.OtpAuthURI)
	}
}

// ---------------------------------------------------------------------------
// Phase 2: FinishRegistration wrong setupData type
// ---------------------------------------------------------------------------

func TestTOTPFinishRegistrationWrongSetupType(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	err := f.FinishRegistration(context.Background(), uuid.New(), "not a TOTPSetup", []byte("123456"))
	if err == nil {
		t.Fatal("FinishRegistration with wrong setupData type should fail")
	}
}

// ---------------------------------------------------------------------------
// Phase 2: ErrInvalidTOTPCode is surfaced
// ---------------------------------------------------------------------------

func TestTOTPErrInvalidTOTPCode(t *testing.T) {
	f := twofactor.NewTOTPFactor("WPMgr")
	ctx := context.Background()
	userID := uuid.New()
	setupAny, _ := f.BeginRegistration(ctx, userID, "user@example.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	err := f.FinishRegistration(ctx, userID, setup, []byte("000000"))
	if err != twofactor.ErrInvalidTOTPCode {
		t.Errorf("expected ErrInvalidTOTPCode, got %v", err)
	}
}

// ---------------------------------------------------------------------------
// Phase 2: cryptbox + TOTP round-trip (simulates service layer)
// ---------------------------------------------------------------------------

func TestCryptboxTOTPSecretRoundTrip(t *testing.T) {
	box, err := cryptbox.NewAgeIdentity("")
	if err != nil {
		t.Fatalf("NewAgeIdentity: %v", err)
	}

	f := twofactor.NewTOTPFactor("WPMgr")
	setupAny, _ := f.BeginRegistration(context.Background(), uuid.New(), "u@e.com")
	setup := setupAny.(*twofactor.TOTPSetup)

	// Simulate the service layer: encrypt the secret for storage.
	encrypted, err := box.Encrypt([]byte(setup.Secret))
	if err != nil {
		t.Fatalf("Encrypt: %v", err)
	}

	// Simulate the service layer: decrypt at verify time.
	decrypted, err := box.Decrypt(encrypted)
	if err != nil {
		t.Fatalf("Decrypt: %v", err)
	}
	if string(decrypted) != setup.Secret {
		t.Fatalf("round-trip mismatch: got %q, want %q", decrypted, setup.Secret)
	}

	// Verify a live code validates against the decrypted secret.
	code, _ := totp.GenerateCode(string(decrypted), time.Now())
	valid, _, verr := f.ValidateCode(code, string(decrypted))
	if verr != nil || !valid {
		t.Fatalf("code from decrypted secret failed validation: valid=%v err=%v", valid, verr)
	}
}
