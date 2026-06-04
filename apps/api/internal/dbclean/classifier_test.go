package dbclean

import (
	"context"
	"sort"
	"testing"
)

// fakeCorpusReader is an in-memory CorpusReader for testing — no database.
type fakeCorpusReader struct {
	sigs []Signature
}

func (f *fakeCorpusReader) GetPluginSignatures(_ context.Context, slug string) (Signature, error) {
	for _, s := range f.sigs {
		if s.Slug == slug {
			return s, nil
		}
	}
	return Signature{}, ErrNotFound
}

func (f *fakeCorpusReader) AllSignatures(_ context.Context) ([]Signature, error) {
	return f.sigs, nil
}

// sortedStrings sorts a string slice in place and returns it for inline use.
func sortedStrings(ss []string) []string {
	sort.Strings(ss)
	return ss
}

// stringsEqual reports whether two string slices contain the same elements
// regardless of order.
func stringsEqual(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	sa := sortedStrings(append([]string(nil), a...))
	sb := sortedStrings(append([]string(nil), b...))
	for i := range sa {
		if sa[i] != sb[i] {
			return false
		}
	}
	return true
}

func TestClassifier(t *testing.T) {
	// Corpus used for all sub-tests:
	//   "contact-form-7"  option  patterns: exact literal "wpcf7_version"
	//                              anchored: "^wpcf7_"
	//   "yoast-seo"       option  patterns: exact literal "wpseo_titles"
	//                              anchored: "^wpseo_" (shorter than ^wpcf7_sitemap_)
	//   "long-prefix-plugin" option patterns: anchored "^wpcf7_sitemap_" (longer prefix
	//                              that matches items starting with wpcf7_sitemap_)
	//   "wp-rocket"       option  patterns: anchored "^rocket_"
	//   "heuristic-plugin" (no patterns — exercises heuristic pass)
	corpus := &fakeCorpusReader{
		sigs: []Signature{
			{
				Slug:           "contact-form-7",
				OptionPatterns: []string{"wpcf7_version", "^wpcf7_"},
			},
			{
				Slug:           "yoast-seo",
				OptionPatterns: []string{"wpseo_titles", "^wpseo_"},
			},
			{
				Slug: "long-prefix-plugin",
				// Anchored pattern longer than "^wpcf7_" so it wins for items
				// that start with "wpcf7_sitemap_".
				OptionPatterns: []string{"^wpcf7_sitemap_"},
			},
			{
				Slug:           "wp-rocket",
				OptionPatterns: []string{"^rocket_"},
			},
			{
				// "heuristic-plugin" has no option patterns but its slug normalises
				// to "heuristicplugin" which appears inside the item name
				// "heuristicplugin_setting".
				Slug:           "heuristic-plugin",
				OptionPatterns: nil,
			},
		},
	}

	cl := NewClassifier(corpus)
	ctx := context.Background()

	tests := []struct {
		name         string
		item         string
		kind         string
		wantConf     ConfidenceLevel
		wantOwner    string
		wantKnown    []string // nil means don't check (for unknown)
		wantPat      string   // empty means don't check
	}{
		{
			name:      "exact literal hit",
			item:      "wpcf7_version",
			kind:      "option",
			wantConf:  ConfidenceExact,
			wantOwner: "contact-form-7",
			wantKnown: []string{"contact-form-7"},
			wantPat:   "wpcf7_version",
		},
		{
			name:      "exact literal hit — yoast",
			item:      "wpseo_titles",
			kind:      "option",
			wantConf:  ConfidenceExact,
			wantOwner: "yoast-seo",
			wantKnown: []string{"yoast-seo"},
			wantPat:   "wpseo_titles",
		},
		{
			name:      "prefix hit — short prefix",
			item:      "wpcf7_some_option",
			kind:      "option",
			wantConf:  ConfidencePrefix,
			wantOwner: "contact-form-7",
			wantKnown: []string{"contact-form-7"},
		},
		{
			name:      "prefix hit — rocket_",
			item:      "rocket_critical_css",
			kind:      "option",
			wantConf:  ConfidencePrefix,
			wantOwner: "wp-rocket",
			wantKnown: []string{"wp-rocket"},
		},
		{
			name: "longest-slug wins — wpcf7_sitemap_ longer pattern beats ^wpcf7_",
			// Both "contact-form-7" (^wpcf7_) and "long-prefix-plugin" (^wpcf7_sitemap_)
			// match. The longer pattern (^wpcf7_sitemap_) wins, so long-prefix-plugin
			// is the primary owner.
			item:      "wpcf7_sitemap_entries",
			kind:      "option",
			wantConf:  ConfidencePrefix,
			wantOwner: "long-prefix-plugin",
			wantKnown: []string{"contact-form-7", "long-prefix-plugin"},
		},
		{
			name: "multi-candidate — KnownPlugins has both slugs",
			// Item matches both ^wpcf7_ (contact-form-7) and ^wpcf7_sitemap_ (long-prefix-plugin).
			item:      "wpcf7_sitemap_cron",
			kind:      "option",
			wantConf:  ConfidencePrefix,
			wantOwner: "long-prefix-plugin",
			wantKnown: []string{"contact-form-7", "long-prefix-plugin"},
		},
		{
			name:      "heuristic-only — no pattern match but slug in item name",
			item:      "heuristicplugin_setting",
			kind:      "option",
			wantConf:  ConfidenceHeuristic,
			wantOwner: "heuristic-plugin",
			wantKnown: []string{"heuristic-plugin"},
		},
		{
			name:      "unknown — no match at all",
			item:      "completely_unrelated_option_xyz_zzz",
			kind:      "option",
			wantConf:  ConfidenceUnknown,
			wantOwner: "",
			wantKnown: nil,
		},
		{
			name:      "kind transient — no patterns defined for any sig",
			item:      "some_transient",
			kind:      "transient",
			wantConf:  ConfidenceUnknown,
			wantOwner: "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			results, err := cl.Classify(ctx, []string{tt.item}, tt.kind)
			if err != nil {
				t.Fatalf("Classify error: %v", err)
			}
			if len(results) != 1 {
				t.Fatalf("expected 1 result, got %d", len(results))
			}
			got := results[0]

			if got.ItemName != tt.item {
				t.Errorf("ItemName: got %q, want %q", got.ItemName, tt.item)
			}
			if got.ItemKind != tt.kind {
				t.Errorf("ItemKind: got %q, want %q", got.ItemKind, tt.kind)
			}
			if got.Confidence != tt.wantConf {
				t.Errorf("Confidence: got %q, want %q", got.Confidence, tt.wantConf)
			}
			if got.OwnerSlug != tt.wantOwner {
				t.Errorf("OwnerSlug: got %q, want %q", got.OwnerSlug, tt.wantOwner)
			}
			if tt.wantKnown != nil {
				if !stringsEqual(got.KnownPlugins, tt.wantKnown) {
					t.Errorf("KnownPlugins: got %v, want %v", got.KnownPlugins, tt.wantKnown)
				}
			}
			if tt.wantPat != "" && got.PatternHit != tt.wantPat {
				t.Errorf("PatternHit: got %q, want %q", got.PatternHit, tt.wantPat)
			}
		})
	}
}

// TestClassifierEmptyCorpus verifies that Classify returns ConfidenceUnknown
// for all items when the corpus is empty.
func TestClassifierEmptyCorpus(t *testing.T) {
	cl := NewClassifier(&fakeCorpusReader{sigs: nil})
	ctx := context.Background()

	items := []string{"wpcf7_version", "wpseo_titles", "rocket_critical_css"}
	results, err := cl.Classify(ctx, items, "option")
	if err != nil {
		t.Fatalf("Classify error: %v", err)
	}
	if len(results) != len(items) {
		t.Fatalf("expected %d results, got %d", len(items), len(results))
	}
	for i, got := range results {
		if got.Confidence != ConfidenceUnknown {
			t.Errorf("item %d (%q): expected ConfidenceUnknown, got %q", i, items[i], got.Confidence)
		}
		if got.OwnerSlug != "" {
			t.Errorf("item %d (%q): expected empty OwnerSlug, got %q", i, items[i], got.OwnerSlug)
		}
		if len(got.KnownPlugins) != 0 {
			t.Errorf("item %d (%q): expected nil KnownPlugins, got %v", i, items[i], got.KnownPlugins)
		}
	}
}

// TestClassifierSharedExactLiteralMultiCandidate is the regression test for
// security fix 1 (PASS 1 EXACT multi-candidate completeness). When two slugs
// share the same exact literal pattern, both must appear in KnownPlugins —
// the old early-return code only recorded the first slug.
func TestClassifierSharedExactLiteralMultiCandidate(t *testing.T) {
	corpus := &fakeCorpusReader{
		sigs: []Signature{
			{Slug: "plugin-a", OptionPatterns: []string{"shared_opt"}},
			{Slug: "plugin-b", OptionPatterns: []string{"shared_opt"}},
		},
	}
	cl := NewClassifier(corpus)
	ctx := context.Background()

	results, err := cl.Classify(ctx, []string{"shared_opt"}, "option")
	if err != nil {
		t.Fatalf("Classify error: %v", err)
	}
	got := results[0]

	if got.Confidence != ConfidenceExact {
		t.Errorf("Confidence: got %q, want %q", got.Confidence, ConfidenceExact)
	}
	if len(got.KnownPlugins) != 2 {
		t.Errorf("KnownPlugins: got %v (len %d), want len 2", got.KnownPlugins, len(got.KnownPlugins))
	}
	if !stringsEqual(got.KnownPlugins, []string{"plugin-a", "plugin-b"}) {
		t.Errorf("KnownPlugins: got %v, want [plugin-a plugin-b]", got.KnownPlugins)
	}
}

// TestClassifierExactAndPrefixBothInKnownPlugins is a regression test for
// security fix 1: when slug A matches an exact literal and slug B matches a
// prefix pattern on the same item, both slugs must appear in KnownPlugins.
func TestClassifierExactAndPrefixBothInKnownPlugins(t *testing.T) {
	corpus := &fakeCorpusReader{
		sigs: []Signature{
			{Slug: "plugin-a", OptionPatterns: []string{"acme_key"}},
			{Slug: "plugin-b", OptionPatterns: []string{"^acme_"}},
		},
	}
	cl := NewClassifier(corpus)
	ctx := context.Background()

	results, err := cl.Classify(ctx, []string{"acme_key"}, "option")
	if err != nil {
		t.Fatalf("Classify error: %v", err)
	}
	got := results[0]

	// Primary owner is exact (plugin-a beats plugin-b's prefix).
	if got.Confidence != ConfidenceExact {
		t.Errorf("Confidence: got %q, want %q", got.Confidence, ConfidenceExact)
	}
	if got.OwnerSlug != "plugin-a" {
		t.Errorf("OwnerSlug: got %q, want %q", got.OwnerSlug, "plugin-a")
	}
	// Both slugs must be present in KnownPlugins.
	if len(got.KnownPlugins) != 2 {
		t.Errorf("KnownPlugins: got %v (len %d), want len 2", got.KnownPlugins, len(got.KnownPlugins))
	}
	if !stringsEqual(got.KnownPlugins, []string{"plugin-a", "plugin-b"}) {
		t.Errorf("KnownPlugins: got %v, want [plugin-a plugin-b]", got.KnownPlugins)
	}
}

// TestClassifierAlternationPatternRejected is the regression test for
// security fix 2 (anchoring enforcement). A corpus pattern containing a
// top-level '|' ("^pre_|evil") must NOT match a non-pre_ item such as
// "some_evil_string_here". Without the fix the regexp engine would match
// "evil" mid-string and return ConfidencePrefix on an unrelated item.
func TestClassifierAlternationPatternRejected(t *testing.T) {
	corpus := &fakeCorpusReader{
		sigs: []Signature{
			// Pattern with alternation: the second branch "evil" is not anchored.
			{Slug: "plugin-p", OptionPatterns: []string{"^pre_|evil"}},
		},
	}
	cl := NewClassifier(corpus)
	ctx := context.Background()

	results, err := cl.Classify(ctx, []string{"some_evil_string_here"}, "option")
	if err != nil {
		t.Fatalf("Classify error: %v", err)
	}
	got := results[0]

	// The alternation pattern must be rejected; the item is unrelated and
	// should fall through to ConfidenceUnknown.
	if got.Confidence != ConfidenceUnknown {
		t.Errorf("Confidence: got %q, want %q (alternation pattern must not match non-pre_ item)",
			got.Confidence, ConfidenceUnknown)
	}
	if got.OwnerSlug != "" {
		t.Errorf("OwnerSlug: got %q, want empty", got.OwnerSlug)
	}
}

// TestClassifierRegexpCacheReuse verifies that calling Classify multiple times
// does not recompile patterns (cache should be populated after first call).
// This is a behavioural smoke-test; correctness is verified by the other cases.
func TestClassifierRegexpCacheReuse(t *testing.T) {
	corpus := &fakeCorpusReader{
		sigs: []Signature{
			{Slug: "wp-rocket", OptionPatterns: []string{"^rocket_"}},
		},
	}
	cl := NewClassifier(corpus)
	ctx := context.Background()

	for i := 0; i < 5; i++ {
		results, err := cl.Classify(ctx, []string{"rocket_critical_css"}, "option")
		if err != nil {
			t.Fatalf("round %d: Classify error: %v", i, err)
		}
		if results[0].Confidence != ConfidencePrefix {
			t.Errorf("round %d: expected ConfidencePrefix, got %q", i, results[0].Confidence)
		}
	}
}
