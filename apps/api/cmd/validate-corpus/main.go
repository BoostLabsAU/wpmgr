// validate-corpus checks the v2 seed migration for three invariants:
//  1. Every JSON pattern array in the SQL parses correctly.
//  2. Every pattern string compiles as a Go RE2 regexp.
//  3. No bare anchored-prefix pattern (^BODY_$  or  ^BODY_ as a complete pattern
//     — i.e. the pattern is exactly "^BODY_" with nothing after the underscore)
//     has a BODY shorter than minPrefixBodyLen characters.
//
// "Bare prefix" means the pattern is exactly ^WORD_ (ends with underscore) with
// nothing more specific after it.  Patterns like ^wp_woocommerce, ^wp_wc_ that
// have additional specificity after the second segment are NOT bare prefixes and
// are subject only to the regexp-compile check.
//
// Usage: go run ./cmd/validate-corpus ./migrations/20260606020000_m43_plugin_signatures_v2_seed.sql
package main

import (
	"encoding/json"
	"fmt"
	"os"
	"regexp"
)

// minPrefixBodyLen matches the corpus-gen tool invariant.
const minPrefixBodyLen = 4

// barePrefix matches patterns that are ONLY ^BODY_ (the body is the text
// between ^ and the single trailing underscore, nothing after).
// Examples that match: "^et_"  "^ep_"  "^sq_"
// Examples that do NOT match: "^wp_woocommerce"  "^wpcf7_"  "^wc_stripe_"
var barePrefix = regexp.MustCompile(`^\^([A-Za-z0-9]+)_$`)

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: validate-corpus <seed.sql>")
		os.Exit(1)
	}
	raw, err := os.ReadFile(os.Args[1])
	if err != nil {
		fmt.Fprintf(os.Stderr, "read error: %v\n", err)
		os.Exit(1)
	}
	content := string(raw)

	failures := 0
	jsonArrayRE := regexp.MustCompile(`'(\[(?:[^\]'\\]|\\.)*\])'`)
	matches := jsonArrayRE.FindAllStringSubmatch(content, -1)

	slugRE := regexp.MustCompile(`\('([a-z0-9-]+)',\s*2,`)
	slugMatches := slugRE.FindAllStringSubmatch(content, -1)
	slugCount := len(slugMatches)

	for _, m := range matches {
		jsonStr := m[1]
		var patterns []string
		if err := json.Unmarshal([]byte(jsonStr), &patterns); err != nil {
			fmt.Printf("FAIL json parse: %q — %v\n", jsonStr, err)
			failures++
			continue
		}
		for _, pat := range patterns {
			if _, err := regexp.Compile(pat); err != nil {
				fmt.Printf("FAIL regexp compile: %q — %v\n", pat, err)
				failures++
				continue
			}
			// Only flag patterns that are exactly "^BODY_" (bare prefix, nothing
			// after the underscore). These are the patterns that can mass-mismatch
			// unrelated options if BODY is too short.
			if sub := barePrefix.FindStringSubmatch(pat); sub != nil {
				body := sub[1]
				if len(body) < minPrefixBodyLen {
					fmt.Printf("FAIL bare prefix body too short (%d < %d): %q\n", len(body), minPrefixBodyLen, pat)
					failures++
				}
			}
		}
	}

	fmt.Printf("Slugs in v2 seed:   %d\n", slugCount)
	fmt.Printf("JSON arrays found:  %d\n", len(matches))
	if failures > 0 {
		fmt.Printf("FAILED: %d invariant violation(s)\n", failures)
		os.Exit(1)
	}
	fmt.Println("OK: all invariants pass")
}
