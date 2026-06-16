// Package twofactor: recovery code generation and format helpers.
package twofactor

import (
	"crypto/rand"
	"fmt"
	"strings"
)

const (
	// RecoveryCodeCount is the number of recovery codes issued per batch.
	RecoveryCodeCount = 10

	// recoveryCodeHalfLen is the length of each half of the formatted code.
	// Each half is 5 Crockford base32 characters = 25 bits per half, 50 bits
	// total. 2^50 = 1.125e15 codes per batch. With 10 codes and no lockout the
	// brute-force space is large enough (the service layer enforces a 5-attempt
	// lockout at the challenge level anyway).
	recoveryCodeHalfLen = 5

	// crockfordAlphabet is Crockford base32: 0-9 A-Z excluding I, L, O, U.
	// These characters are unambiguous in print and safe to type manually.
	crockfordAlphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
)

// GenerateRecoveryCodes generates RecoveryCodeCount random Crockford base32
// codes in the format XXXXX-XXXXX (two 5-char groups separated by a hyphen).
// Each code has >= 50 bits of entropy. Returns the plaintext codes for
// display; the caller must hash them before storage.
func GenerateRecoveryCodes() ([]string, error) {
	codes := make([]string, RecoveryCodeCount)
	for i := range codes {
		code, err := generateOneRecoveryCode()
		if err != nil {
			return nil, fmt.Errorf("generate recovery code %d: %w", i, err)
		}
		codes[i] = code
	}
	return codes, nil
}

// generateOneRecoveryCode generates a single recovery code XXXXX-XXXXX using
// crypto/rand and the Crockford base32 alphabet.
func generateOneRecoveryCode() (string, error) {
	// 10 characters from a 32-char alphabet = 5 bits/char * 10 chars = 50 bits.
	// We need ceil(10 * log2(32) / 8) = 7 bytes of randomness; we use 10 for
	// a small safety margin and discard any bits beyond what we need.
	buf := make([]byte, 10)
	if _, err := rand.Read(buf); err != nil {
		return "", fmt.Errorf("crypto/rand: %w", err)
	}

	// Build 10 Crockford characters from the random bytes. Each character
	// requires 5 bits; we pick them from the byte stream with a simple bit
	// extractor. Using bytes directly as indices into a 32-char alphabet
	// with bitmask avoids modulo bias.
	chars := make([]byte, recoveryCodeHalfLen*2)
	for i := range chars {
		chars[i] = crockfordAlphabet[buf[i]&0x1f]
	}

	return string(chars[:recoveryCodeHalfLen]) + "-" + string(chars[recoveryCodeHalfLen:]), nil
}

// NormalizeRecoveryCode upper-cases and strips hyphens + spaces from a
// user-submitted recovery code so it can be compared against the stored
// format. This is a presentation-layer helper; the canonical form is
// XXXXX-XXXXX, but users may omit or misplace the hyphen.
func NormalizeRecoveryCode(code string) string {
	code = strings.ToUpper(strings.TrimSpace(code))
	code = strings.ReplaceAll(code, "-", "")
	code = strings.ReplaceAll(code, " ", "")
	return code
}
