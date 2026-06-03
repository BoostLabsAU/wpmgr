// Package cryptbox holds the control plane's shared secret-at-rest primitive:
// an age (X25519) identity used to encrypt customer/operator secrets before they
// touch Postgres. It was extracted from internal/sitedestination so multiple
// domains (site destinations, SMTP settings, future alert-channel secrets) can
// share one encryption boundary without importing each other.
//
// The identity's private key never leaves the control plane and is never shipped
// to an agent. In production the operator MUST supply a stable key
// (WPMGR_SITE_DEST_AGE_SECRET) — an empty key yields a fresh ephemeral identity
// so dev boots, but every restart would then orphan previously stored secrets.
package cryptbox

import (
	"bytes"
	"fmt"
	"io"
	"strings"

	"filippo.io/age"
)

// AgeIdentity wraps an age X25519 identity (for decryption) plus its recipient
// (for encryption). Encrypt at write time, Decrypt at read time.
type AgeIdentity struct {
	identity  *age.X25519Identity
	recipient *age.X25519Recipient
}

// NewAgeIdentity parses an age secret key (the AGE-SECRET-KEY-1... format). An
// empty key produces a fresh ephemeral identity so dev startup succeeds; callers
// that must persist ciphertext across restarts should refuse to boot in
// production on an empty key (see the startup guard in cmd/wpmgr).
func NewAgeIdentity(secretKey string) (*AgeIdentity, error) {
	if strings.TrimSpace(secretKey) == "" {
		id, err := age.GenerateX25519Identity()
		if err != nil {
			return nil, fmt.Errorf("generate ephemeral age identity: %w", err)
		}
		return &AgeIdentity{identity: id, recipient: id.Recipient()}, nil
	}
	id, err := age.ParseX25519Identity(strings.TrimSpace(secretKey))
	if err != nil {
		return nil, fmt.Errorf("parse age identity: %w", err)
	}
	return &AgeIdentity{identity: id, recipient: id.Recipient()}, nil
}

// Encrypt age-encrypts plaintext for the wrapped recipient.
func (a *AgeIdentity) Encrypt(plaintext []byte) ([]byte, error) {
	var buf bytes.Buffer
	w, err := age.Encrypt(&buf, a.recipient)
	if err != nil {
		return nil, fmt.Errorf("age encrypt: %w", err)
	}
	if _, err := w.Write(plaintext); err != nil {
		return nil, fmt.Errorf("age write: %w", err)
	}
	if err := w.Close(); err != nil {
		return nil, fmt.Errorf("age close: %w", err)
	}
	return buf.Bytes(), nil
}

// RecipientString returns the public age recipient ("age1...") for surfacing in
// a UI so operators can verify the encrypted-at-rest claim out of band, or "" if
// the identity is nil.
func (a *AgeIdentity) RecipientString() string {
	if a == nil || a.recipient == nil {
		return ""
	}
	return a.recipient.String()
}

// Decrypt age-decrypts ciphertext produced by Encrypt with the same identity.
func (a *AgeIdentity) Decrypt(ciphertext []byte) ([]byte, error) {
	r, err := age.Decrypt(bytes.NewReader(ciphertext), a.identity)
	if err != nil {
		return nil, fmt.Errorf("age decrypt: %w", err)
	}
	out, err := io.ReadAll(r)
	if err != nil {
		return nil, fmt.Errorf("age read: %w", err)
	}
	return out, nil
}
