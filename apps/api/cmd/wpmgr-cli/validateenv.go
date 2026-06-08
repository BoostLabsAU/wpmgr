package main

import (
	"fmt"
	"io"
	"os"

	"github.com/mosamlife/wpmgr/apps/api/internal/config"
)

// validateEnv loads the configuration and runs the same aggregated checks that
// the server's degraded-boot path runs. It prints a checklist to out (one line
// per check: OK/FAIL + env-var name + reason) and returns a non-nil error if
// any check failed so the caller can exit non-zero.
//
// It NEVER opens a database connection, a Redis connection, or an HTTP server —
// this command is safe to run in any environment without network access.
//
// SECRET-LEAK INVARIANT: names and reasons only are printed; secret values,
// DSN strings, and raw crypto errors are never surfaced.
func validateEnv(out io.Writer) error {
	cfg, err := config.Load(os.Getenv("WPMGR_CONFIG_FILE"))
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	issues := config.Validate(cfg)

	// Build a fast lookup for which names have issues.
	failed := make(map[string]string, len(issues))
	for _, iss := range issues {
		failed[iss.Name] = iss.Reason
	}

	// Ordered list of checks we surface (mirrors config.Validate order).
	checks := []string{
		"WPMGR_SESSION_SECRET",
		"WPMGR_AGENT_SIGNING_PRIVATE_KEY",
		"WPMGR_SITE_DEST_AGE_SECRET",
	}

	anyFail := false
	for _, name := range checks {
		if reason, bad := failed[name]; bad {
			fmt.Fprintf(out, "FAIL  %s — %s\n", name, reason)
			anyFail = true
		} else {
			fmt.Fprintf(out, "OK    %s\n", name)
		}
	}

	if anyFail {
		return fmt.Errorf("one or more config checks failed")
	}
	return nil
}
