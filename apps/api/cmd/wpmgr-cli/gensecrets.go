package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"io"

	"filippo.io/age"

	"github.com/mosamlife/wpmgr/apps/api/internal/agent"
	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/cryptbox"
)

// genSecrets mints the four boot-critical secrets a fresh self-host install
// needs, in the EXACT formats the control plane validates at startup, and
// prints them as KEY=VALUE lines (consumed by scripts/init-env.sh):
//
//	WPMGR_SESSION_SECRET            random >=32-byte string (base64 of 48 bytes)
//	WPMGR_AGENT_SIGNING_PRIVATE_KEY base64-std of the 64-byte ed25519 priv key
//	WPMGR_AGENT_SIGNING_PUBLIC_KEY  base64-std of the 32-byte ed25519 pub key
//	WPMGR_SITE_DEST_AGE_SECRET      age X25519 secret key (AGE-SECRET-KEY-1...)
//
// Every value is round-tripped through the SAME decode/parse path the server
// uses on boot, so a printed line is guaranteed to satisfy the startup guards
// (no "I generated a key the app then rejects" foot-gun).
func genSecrets(out io.Writer) error {
	sessionSecret, err := genSessionSecret()
	if err != nil {
		return fmt.Errorf("session secret: %w", err)
	}

	privB64, pubB64, err := genAgentSigningKeypair()
	if err != nil {
		return fmt.Errorf("agent signing keypair: %w", err)
	}

	ageSecret, err := genAgeSecret()
	if err != nil {
		return fmt.Errorf("age secret: %w", err)
	}

	fmt.Fprintf(out, "WPMGR_SESSION_SECRET=%s\n", sessionSecret)
	fmt.Fprintf(out, "WPMGR_AGENT_SIGNING_PRIVATE_KEY=%s\n", privB64)
	fmt.Fprintf(out, "WPMGR_AGENT_SIGNING_PUBLIC_KEY=%s\n", pubB64)
	fmt.Fprintf(out, "WPMGR_SITE_DEST_AGE_SECRET=%s\n", ageSecret)
	return nil
}

// genSessionSecret returns a random base64 string of >=32 bytes (48 random
// bytes => 64 base64 chars), self-checked against the boot length rule.
func genSessionSecret() (string, error) {
	raw := make([]byte, 48)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	s := base64.StdEncoding.EncodeToString(raw)
	if len(s) < 32 {
		return "", fmt.Errorf("generated session secret too short (%d)", len(s))
	}
	return s, nil
}

// genAgentSigningKeypair mints a fresh ed25519 keypair and encodes it exactly
// as the server expects: base64-std of the raw 64-byte private key (seed||pub,
// the Go ed25519.PrivateKey layout) and the raw 32-byte public key. It then
// self-verifies by feeding both values back through the server's own decode
// functions and asserting the keys correspond.
func genAgentSigningKeypair() (privB64, pubB64 string, err error) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", "", err
	}
	privB64 = base64.StdEncoding.EncodeToString(priv)
	pubB64 = base64.StdEncoding.EncodeToString(pub)

	// Self-test: round-trip through the SAME decoders the boot path uses.
	decPriv, err := agentcmd.DecodePrivateKey(privB64)
	if err != nil {
		return "", "", fmt.Errorf("self-test decode private: %w", err)
	}
	decPub, err := agent.DecodePublicKey(pubB64)
	if err != nil {
		return "", "", fmt.Errorf("self-test decode public: %w", err)
	}
	if !decPriv.Public().(ed25519.PublicKey).Equal(decPub) {
		return "", "", fmt.Errorf("self-test: private key public half does not match generated public key")
	}
	return privB64, pubB64, nil
}

// genAgeSecret mints a fresh age X25519 identity (AGE-SECRET-KEY-1...) and
// self-verifies it parses through cryptbox.NewAgeIdentity — the same call the
// control plane makes at boot for the secrets-at-rest key.
func genAgeSecret() (string, error) {
	id, err := age.GenerateX25519Identity()
	if err != nil {
		return "", err
	}
	secret := id.String()
	if _, err := cryptbox.NewAgeIdentity(secret); err != nil {
		return "", fmt.Errorf("self-test parse age identity: %w", err)
	}
	return secret, nil
}
