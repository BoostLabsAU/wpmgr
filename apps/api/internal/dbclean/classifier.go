package dbclean

import (
	"context"
	"fmt"
	"regexp"
	"strings"
	"sync"
)

// Classifier classifies a batch of item names (wp_options keys, transient
// names, custom table names, or WP-Cron hook names) against the plugin corpus
// loaded via a CorpusReader.
//
// Pattern-level regexp compilation is cached in a sync.Map keyed by pattern
// string so each unique pattern is compiled at most once across all Classify
// calls, even when called concurrently from multiple goroutines.
type Classifier struct {
	corpus  CorpusReader
	reCache sync.Map // key: pattern string → *regexp.Regexp (nil if invalid/rejected)
}

// NewClassifier returns a Classifier backed by the given CorpusReader.
func NewClassifier(cr CorpusReader) *Classifier {
	return &Classifier{corpus: cr}
}

// Classify classifies each item in items against the corpus and returns one
// Classification per item in the same order. kind selects which pattern
// column to use:
//
//   - "option"    → OptionPatterns
//   - "transient" → TransientPatterns
//   - "table"     → TablePatterns
//   - "cron_hook" → CronHookPatterns
//
// Three-pass algorithm per item:
//
//	PASS 1+2 (EXACT+PREFIX combined scan): All slugs and all patterns are
//	         scanned in a single pass over the corpus. Each pattern is tested
//	         as either a plain literal (exact) or an anchored regexp (prefix).
//	         Every matching slug is accumulated into KnownPlugins. The primary
//	         owner is determined by picking exact over prefix; among prefix
//	         matches the longest matching pattern wins for the primary owner.
//	         This unified scan ensures that if slug A matches exact and slug B
//	         matches prefix on the same item, both appear in KnownPlugins.
//	PASS 3 (HEURISTIC): Only reached when no exact or prefix match was found.
//	         The normalised leading token of the item (before the first
//	         non-alphanumeric separator) appears as a substring of some slug's
//	         normalised name → ConfidenceHeuristic. No KnownPlugins populated.
//
// If none of the passes match, the item receives ConfidenceUnknown.
//
// The corpus is loaded once per Classify call via AllSignatures; patterns are
// compiled on first use and cached in the Classifier's sync.Map.
func (c *Classifier) Classify(ctx context.Context, items []string, kind string) ([]Classification, error) {
	if len(items) == 0 {
		return nil, nil
	}

	sigs, err := c.corpus.AllSignatures(ctx)
	if err != nil {
		return nil, fmt.Errorf("classifier: load corpus: %w", err)
	}

	// Pre-compile all patterns for this kind upfront (warm the cache).
	for i := range sigs {
		for _, pat := range patternsForKind(&sigs[i], kind) {
			c.compilePattern(pat) // result stored in sync.Map; error silently drops the pattern
		}
	}

	out := make([]Classification, len(items))
	for i, item := range items {
		out[i] = c.classifyOne(item, kind, sigs)
	}
	return out, nil
}

// classifyOne runs the three-pass algorithm for a single item against the
// already-loaded corpus slice.
//
// Security invariant: PASS 1 and PASS 2 are merged into a single full-corpus
// scan so that all matching slugs — whether they match via exact literal or
// via anchored regexp — are collected into KnownPlugins. An early-return after
// the first exact hit would hide subsequent prefix matches (or duplicate exact
// matches) from KnownPlugins, defeating P3.8's ambiguity-based deletion guard.
func (c *Classifier) classifyOne(item, kind string, sigs []Signature) Classification {
	// -------------------------------------------------------------------
	// PASS 1+2: Combined exact and prefix scan over the full corpus.
	//
	// We track:
	//   exactSlugs   — slugs that produced an exact (literal) match.
	//   prefixSlugs  — slugs that produced a prefix (anchored regexp) match.
	//   bestPrefixPat / bestPrefixSlug — the prefix match with the longest
	//     matching pattern string (most-specific), used to choose the primary
	//     owner when no exact match exists.
	//
	// All slugs from both sets contribute to KnownPlugins.
	// -------------------------------------------------------------------
	type prefixMatch struct {
		slug    string
		pattern string
		patLen  int
	}

	var exactSlugs []string
	var exactPat string // pattern string of the first exact hit (for PatternHit)

	var prefixMatches []prefixMatch
	bestPrefixLen := -1
	bestPrefixSlug := ""
	bestPrefixPat := ""

	for i := range sigs {
		var sigHasExact bool
		var sigExactPat string
		var sigBestPrefixLen int = -1
		var sigBestPrefixPat string

		for _, pat := range patternsForKind(&sigs[i], kind) {
			if isAnchoredPattern(pat) {
				// PREFIX path: compile and match with true-anchor enforcement.
				re := c.compilePattern(pat)
				if re == nil {
					continue
				}
				if matchesTruePrefix(re, item) && len(pat) > sigBestPrefixLen {
					sigBestPrefixLen = len(pat)
					sigBestPrefixPat = pat
				}
			} else {
				// EXACT path: plain literal equality.
				if item == pat {
					sigHasExact = true
					if sigExactPat == "" {
						sigExactPat = pat
					}
				}
			}
		}

		if sigHasExact {
			exactSlugs = append(exactSlugs, sigs[i].Slug)
			if exactPat == "" {
				exactPat = sigExactPat
			}
		}
		if sigBestPrefixLen >= 0 {
			prefixMatches = append(prefixMatches, prefixMatch{
				slug:    sigs[i].Slug,
				pattern: sigBestPrefixPat,
				patLen:  sigBestPrefixLen,
			})
			if sigBestPrefixLen > bestPrefixLen {
				bestPrefixLen = sigBestPrefixLen
				bestPrefixSlug = sigs[i].Slug
				bestPrefixPat = sigBestPrefixPat
			}
		}
	}

	// Build the union of all matching slugs and determine primary owner.
	hasExact := len(exactSlugs) > 0
	hasPrefix := len(prefixMatches) > 0

	if hasExact || hasPrefix {
		// Collect all slugs into KnownPlugins (exact first, then prefix, deduped).
		seen := make(map[string]struct{}, len(exactSlugs)+len(prefixMatches))
		var known []string
		for _, s := range exactSlugs {
			if _, dup := seen[s]; !dup {
				seen[s] = struct{}{}
				known = append(known, s)
			}
		}
		for _, pm := range prefixMatches {
			if _, dup := seen[pm.slug]; !dup {
				seen[pm.slug] = struct{}{}
				known = append(known, pm.slug)
			}
		}

		var ownerSlug string
		var conf ConfidenceLevel
		var patHit string

		if hasExact {
			// Exact beats prefix for primary attribution.
			ownerSlug = exactSlugs[0]
			conf = ConfidenceExact
			patHit = exactPat
		} else {
			// No exact hit; use longest-matching prefix as primary.
			ownerSlug = bestPrefixSlug
			conf = ConfidencePrefix
			patHit = bestPrefixPat
		}

		return Classification{
			ItemName:     item,
			ItemKind:     kind,
			OwnerSlug:    ownerSlug,
			KnownPlugins: known,
			Confidence:   conf,
			PatternHit:   patHit,
		}
	}

	// -------------------------------------------------------------------
	// PASS 3: HEURISTIC — the normalised slug (hyphens/underscores removed,
	// lower-cased) appears as a substring of the lower-cased item name.
	// This catches cases like slug "contact-form-7" → "contactform7" appearing
	// inside option name "contactform7_recaptcha_key".
	// -------------------------------------------------------------------
	itemLower := strings.ToLower(item)
	if itemLower != "" {
		for i := range sigs {
			slugNorm := normalizeSlug(sigs[i].Slug)
			if slugNorm != "" && strings.Contains(itemLower, slugNorm) {
				return Classification{
					ItemName:     item,
					ItemKind:     kind,
					OwnerSlug:    sigs[i].Slug,
					KnownPlugins: []string{sigs[i].Slug},
					Confidence:   ConfidenceHeuristic,
					PatternHit:   "",
				}
			}
		}
	}

	// -------------------------------------------------------------------
	// PASS 4: UNKNOWN — no match at all.
	// -------------------------------------------------------------------
	return Classification{
		ItemName:     item,
		ItemKind:     kind,
		OwnerSlug:    "",
		KnownPlugins: nil,
		Confidence:   ConfidenceUnknown,
		PatternHit:   "",
	}
}

// matchesTruePrefix reports whether the compiled regexp truly matches item
// starting at position 0 (a proper left-anchor). regexp.MatchString is an
// unanchored search — a pattern like "^pre_" compiles to anchor at the start
// of the input, but a malformed corpus pattern like "^pre_|evil" would anchor
// only the first branch and still match "some_evil_string" anywhere in the
// item name. To defend against untrusted corpus patterns:
//
//  1. We use FindStringIndex to locate the first match.
//  2. We require the match to start at index 0 (truly left-anchored).
//  3. We reject any pattern whose string representation contains a top-level
//     '|' character — alternations undermine the anchoring guarantee and
//     should never appear in well-formed corpus patterns.
//
// Precondition: re was produced by compilePattern (only called for patterns
// that passed isAnchoredPattern), so re is non-nil.
func matchesTruePrefix(re *regexp.Regexp, item string) bool {
	loc := re.FindStringIndex(item)
	return loc != nil && loc[0] == 0
}

// patternsForKind returns the pattern slice for the given kind from a
// Signature. Returns nil for an unrecognised kind (callers skip nil).
func patternsForKind(sig *Signature, kind string) []string {
	switch kind {
	case "option":
		return sig.OptionPatterns
	case "transient":
		return sig.TransientPatterns
	case "table":
		return sig.TablePatterns
	case "cron_hook":
		return sig.CronHookPatterns
	default:
		return nil
	}
}

// isAnchoredPattern reports whether pat is an anchored regexp pattern (starts
// with '^'). Plain literals do not start with '^'.
func isAnchoredPattern(pat string) bool {
	return strings.HasPrefix(pat, "^")
}

// compilePattern compiles pat to *regexp.Regexp and caches the result in the
// Classifier's sync.Map. Returns nil if:
//   - pat does not start with '^' (not an anchored regexp),
//   - pat contains a top-level '|' (alternation breaks anchoring guarantees;
//     such patterns are rejected to prevent corpus-injection attacks), or
//   - pat fails to compile.
//
// Safe for concurrent use via sync.Map.
func (c *Classifier) compilePattern(pat string) *regexp.Regexp {
	if !isAnchoredPattern(pat) {
		return nil
	}
	// Reject alternation at the pattern level: a '|' in the pattern string
	// means at least one branch of the alternation may not be anchored at ^,
	// allowing the regexp to match mid-string on a non-owned item.
	// P3.1 corpus invariant already prohibits '|' in stored patterns, but the
	// classifier enforces this defensively at match time.
	if strings.ContainsRune(pat, '|') {
		c.reCache.Store(pat, (*regexp.Regexp)(nil))
		return nil
	}

	if v, ok := c.reCache.Load(pat); ok {
		if re, _ := v.(*regexp.Regexp); re != nil {
			return re
		}
		return nil
	}
	re, err := regexp.Compile(pat)
	if err != nil {
		c.reCache.Store(pat, (*regexp.Regexp)(nil))
		return nil
	}
	c.reCache.Store(pat, re)
	return re
}

// normalizeSlug normalises a plugin slug for heuristic matching by removing
// hyphens and underscores and lower-casing the result. For example,
// "contact-form-7" becomes "contactform7". The classifier checks whether the
// normalised slug appears as a substring of the lower-cased item name.
func normalizeSlug(slug string) string {
	return strings.ToLower(strings.NewReplacer("-", "", "_", "").Replace(slug))
}
