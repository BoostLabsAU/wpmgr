package config

import (
	"os"
	"strings"
)

// Issue describes a single configuration problem detected by Validate. Name is
// the environment-variable name (safe to log and surface to operators); Reason
// is a short, human-readable explanation that NEVER contains a secret value.
type Issue struct {
	Name   string
	Reason string
}

// Validate aggregates ALL boot-critical configuration problems and returns them
// as a slice of Issues. An empty slice means the configuration is valid.
//
// Checks performed (in order):
//  1. WPMGR_SESSION_SECRET — empty, placeholder, or too short.
//  2. WPMGR_AGENT_SIGNING_PRIVATE_KEY — production-only: known committed dev key.
//  3. WPMGR_SITE_DEST_AGE_SECRET — production-only: must be present.
//
// The production guard mirrors the exact condition used during full boot: checks
// 2 and 3 are skipped in non-production environments so the function is safe to
// call in development without any secrets configured.
//
// SECRET-LEAK INVARIANT: every Reason string contains only the env-var name and
// a short human description. Raw errors from crypto parsing, DSN construction,
// or any other credential-wrapping path are NEVER included.
func Validate(cfg Config) []Issue {
	var issues []Issue

	// 1. Session secret.
	s := cfg.Auth.SessionSecret
	switch {
	case s == "":
		issues = append(issues, Issue{
			Name:   "WPMGR_SESSION_SECRET",
			Reason: "empty — set a random secret of at least 32 bytes",
		})
	case strings.HasPrefix(s, "change-me"):
		issues = append(issues, Issue{
			Name:   "WPMGR_SESSION_SECRET",
			Reason: "still holds the placeholder value — set a real random secret of at least 32 bytes",
		})
	case len(s) < 32:
		issues = append(issues, Issue{
			Name:   "WPMGR_SESSION_SECRET",
			Reason: "too short — use at least 32 bytes",
		})
	}

	// 2. Agent signing private key (production-only: known committed dev key).
	if cfg.IsProduction() {
		k := cfg.Agent.SigningPrivateKey
		if k != "" {
			for _, dev := range devAgentSigningPrivateKeys {
				if k == dev {
					issues = append(issues, Issue{
						Name:   "WPMGR_AGENT_SIGNING_PRIVATE_KEY",
						Reason: "holds a known committed dev key — generate a fresh control-plane Ed25519 keypair for production",
					})
					break
				}
			}
		}
	}

	// 3. Site-destination age secret (production-only: must be present).
	if cfg.IsProduction() {
		if strings.TrimSpace(os.Getenv("WPMGR_SITE_DEST_AGE_SECRET")) == "" {
			issues = append(issues, Issue{
				Name:   "WPMGR_SITE_DEST_AGE_SECRET",
				Reason: "required in production — an empty value uses an ephemeral key that orphans stored secrets on restart",
			})
		}
	}

	return issues
}
