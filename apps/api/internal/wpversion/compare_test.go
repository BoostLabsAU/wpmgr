package wpversion_test

import (
	"testing"

	"github.com/mosamlife/wpmgr/apps/api/internal/wpversion"
)

// ---------------------------------------------------------------------------
// version_compare parity tests
// ---------------------------------------------------------------------------

func TestCompare(t *testing.T) {
	type tc struct {
		a, b string
		want int // -1, 0, or 1
	}

	cases := []tc{
		// Basic numeric ordering.
		{"1.0", "1.0", 0},
		{"1.0.0", "1.0", 0},   // trailing zero equivalent
		{"1.0.1", "1.0", 1},
		{"1.0", "1.0.1", -1},
		{"2.0", "1.9.9", 1},
		{"1.10", "1.9", 1},    // "10" > "9" numerically
		{"1.9", "1.10", -1},

		// Four-segment versions (WordPress-style like 6.5.0.1).
		{"6.5.0.1", "6.5.0", 1},
		{"6.5.0.0", "6.5.0", 0},

		// Pre-release ordering: dev < alpha/a < beta/b < RC/rc < release < pl/p.
		{"1.0-dev", "1.0-alpha", -1},
		{"1.0-alpha", "1.0-beta", -1},
		{"1.0-beta", "1.0-RC1", -1},
		{"1.0-RC1", "1.0", -1},
		{"1.0", "1.0-pl1", -1},
		{"1.0-pl1", "1.0-p1", 0},  // pl and p are equivalent rank

		// 'a' is an alias for 'alpha', 'b' for 'beta'.
		{"1.0a1", "1.0alpha1", 0},
		{"1.0b1", "1.0beta1", 0},
		{"1.0a2", "1.0b1", -1},
		{"1.0b2", "1.0RC1", -1},

		// RC ordering (case-insensitive).
		{"1.0-RC1", "1.0-RC2", -1},
		{"1.0-rc2", "1.0-RC1", 1}, // case-insensitive

		// Digit/non-digit boundary split inside a token.
		{"1.2beta1", "1.2beta2", -1},
		{"1.2beta2", "1.2", -1},    // beta < release

		// Underscore-separated (alternate separator).
		{"1.2_3", "1.2.3", 0},
		{"1.2_beta", "1.2-beta", 0},

		// Empty / wildcard.
		{"", "*", 0},     // both collapse to empty
		{"1.0", "*", 1},  // 1.0 > unbounded-low
		{"*", "1.0", -1},

		// WordPress core versions.
		{"6.5", "6.4.3", 1},
		{"6.4.3", "6.5", -1},
		{"5.9", "6.0", -1},
		{"6.0", "6.0.0", 0},

		// Leading-zero numeric components.
		{"1.01", "1.1", 0},
		{"1.001", "1.001", 0},

		// Mixed separators.
		{"1.2+3", "1.2.3", 0},
	}

	for _, tc := range cases {
		got := wpversion.Compare(tc.a, tc.b)
		if got != tc.want {
			t.Errorf("Compare(%q, %q) = %d; want %d", tc.a, tc.b, got, tc.want)
		}
	}
}

// ---------------------------------------------------------------------------
// IsVulnerable range tests
// ---------------------------------------------------------------------------

func TestIsVulnerable(t *testing.T) {
	type tc struct {
		name      string
		installed string
		ranges    []wpversion.AffectedVersionRange
		want      bool
	}

	cases := []tc{
		// Unbounded range: all versions.
		{
			name:      "unbounded both sides",
			installed: "1.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "*", ToInclusive: true},
			},
			want: true,
		},
		// Exact version match (from == to, both inclusive).
		{
			name:      "exact match inclusive",
			installed: "2.3.4",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "2.3.4", FromInclusive: true, ToVersion: "2.3.4", ToInclusive: true},
			},
			want: true,
		},
		// Just above upper bound (exclusive).
		{
			name:      "above exclusive upper bound",
			installed: "2.0.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "2.0.0", ToInclusive: false},
			},
			want: false,
		},
		// At exclusive upper bound.
		{
			name:      "at exclusive upper bound",
			installed: "1.9.9",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "2.0.0", ToInclusive: false},
			},
			want: true,
		},
		// At inclusive upper bound.
		{
			name:      "at inclusive upper bound",
			installed: "2.0.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "2.0.0", ToInclusive: true},
			},
			want: true,
		},
		// Below inclusive lower bound.
		{
			name:      "below inclusive lower bound",
			installed: "0.9",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "1.0", FromInclusive: true, ToVersion: "2.0", ToInclusive: false},
			},
			want: false,
		},
		// At inclusive lower bound.
		{
			name:      "at inclusive lower bound",
			installed: "1.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "1.0", FromInclusive: true, ToVersion: "2.0", ToInclusive: false},
			},
			want: true,
		},
		// At exclusive lower bound — should NOT match.
		{
			name:      "at exclusive lower bound",
			installed: "1.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "1.0", FromInclusive: false, ToVersion: "2.0", ToInclusive: false},
			},
			want: false,
		},
		// Empty ranges slice.
		{
			name:      "empty ranges",
			installed: "1.5",
			ranges:    nil,
			want:      false,
		},
		// Multiple ranges — vulnerable if in ANY range.
		{
			name:      "in second range",
			installed: "3.0",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "1.0", FromInclusive: true, ToVersion: "2.0", ToInclusive: false},
				{FromVersion: "2.5", FromInclusive: true, ToVersion: "3.5", ToInclusive: false},
			},
			want: true,
		},
		// Not in any range.
		{
			name:      "between ranges",
			installed: "2.2",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "1.0", FromInclusive: true, ToVersion: "2.0", ToInclusive: false},
				{FromVersion: "2.5", FromInclusive: true, ToVersion: "3.5", ToInclusive: false},
			},
			want: false,
		},
		// Unknown version → not vulnerable (avoid false positives).
		{
			name:      "unknown version",
			installed: "unknown",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "*", ToInclusive: true},
			},
			want: false,
		},
		// Empty version → not vulnerable.
		{
			name:      "empty version",
			installed: "",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "*", ToInclusive: true},
			},
			want: false,
		},
		// WordPress core version scenario.
		{
			name:      "core 6.3 in vulnerable range <6.4.1",
			installed: "6.3",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "6.4.1", ToInclusive: false},
			},
			want: true,
		},
		{
			name:      "core 6.4.1 patched, not vulnerable",
			installed: "6.4.1",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "6.4.1", ToInclusive: false},
			},
			want: false,
		},
		// Pre-release version in range.
		{
			name:      "beta version in range",
			installed: "1.2-beta1",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "1.2.0", ToInclusive: false},
			},
			want: true, // 1.2-beta1 < 1.2.0
		},
		{
			name:      "RC below fix",
			installed: "2.0-RC1",
			ranges: []wpversion.AffectedVersionRange{
				{FromVersion: "*", FromInclusive: true, ToVersion: "2.0", ToInclusive: false},
			},
			want: true, // RC1 < release
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := wpversion.IsVulnerable(tc.installed, tc.ranges)
			if got != tc.want {
				t.Errorf("IsVulnerable(%q) = %v; want %v", tc.installed, got, tc.want)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// BestFixedVersion tests
// ---------------------------------------------------------------------------

func TestBestFixedVersion(t *testing.T) {
	type tc struct {
		name      string
		installed string
		patched   []string
		want      string
	}

	cases := []tc{
		{
			name:      "first fix above installed",
			installed: "1.5",
			patched:   []string{"1.6", "2.0"},
			want:      "1.6",
		},
		{
			name:      "all patched versions older than installed",
			installed: "2.5",
			patched:   []string{"1.0", "2.0"},
			want:      "2.0", // fallback: highest known fix
		},
		{
			name:      "exactly at patched version — still reports that fix",
			installed: "1.6",
			patched:   []string{"1.6"},
			want:      "", // 1.6 is not > 1.6 so no "strictly newer" fix exists; fall through: return "1.6"
		},
		{
			name:      "empty patched list",
			installed: "1.0",
			patched:   nil,
			want:      "",
		},
		{
			name:      "multiple candidates, picks minimum newer",
			installed: "1.2",
			patched:   []string{"2.0", "1.3", "1.4"},
			want:      "1.3",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := wpversion.BestFixedVersion(tc.installed, tc.patched)
			if got != tc.want {
				t.Errorf("BestFixedVersion(%q, %v) = %q; want %q", tc.installed, tc.patched, got, tc.want)
			}
		})
	}
}
