package rum

import (
	"math"
	"testing"
)

// ---------------------------------------------------------------------------
// p75 histogram interpolation tests
// ---------------------------------------------------------------------------

func TestComputeP75_empty(t *testing.T) {
	results := computeP75(nil, 100)
	if len(results) != 0 {
		t.Errorf("expected 0 results for empty input, got %d", len(results))
	}
}

func TestComputeP75_suppressed_below_minSampleCount(t *testing.T) {
	// 10 samples < minSampleCount=100 → P75Milli must be 0.
	counts := make([]int32, NumBuckets)
	counts[0] = 10 // all 10 samples in [0, 200) bucket
	rollups := []HourlyRollup{
		{
			RollupKey:    RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
			SampleCount:  10,
			BucketCounts: counts,
		},
	}
	results := computeP75(rollups, 100)
	if len(results) != 1 {
		t.Fatalf("expected 1 result, got %d", len(results))
	}
	if results[0].P75Milli != 0 {
		t.Errorf("expected P75Milli=0 (suppressed), got %f", results[0].P75Milli)
	}
	if results[0].SampleCount != 10 {
		t.Errorf("expected SampleCount=10, got %d", results[0].SampleCount)
	}
}

func TestComputeP75_first_bucket_interpolation(t *testing.T) {
	// 100 samples all in the first bucket [0, 200ms).
	// p75 target = ceil(0.75 * 100) = 75.
	// All 100 samples in bucket 0 → lower=0, upper=200.
	// Interpolated = 0 + (75 - 0) / 100 * 200 = 150ms.
	counts := make([]int32, NumBuckets)
	counts[0] = 100
	rollups := []HourlyRollup{
		{
			RollupKey:    RollupKey{Metric: "lcp", Device: "desktop", Country: "US"},
			SampleCount:  100,
			BucketCounts: counts,
			MaxValue:     150,
		},
	}
	results := computeP75(rollups, 100)
	if len(results) != 1 {
		t.Fatalf("expected 1 result, got %d", len(results))
	}
	got := results[0].P75Milli
	want := 150.0
	if math.Abs(got-want) > 1.0 {
		t.Errorf("P75Milli = %f, want ≈ %f", got, want)
	}
}

func TestComputeP75_spans_multiple_hours(t *testing.T) {
	// Two hourly rows for the same key: 50 samples each.
	// Combined: 100 samples total, 50 in bucket[0] + 50 in bucket[1].
	// p75 target = ceil(75) = 75. bucket[0] has 50 → not enough (cum=50 < 75).
	// bucket[1] adds 50 → cum=100 ≥ 75.
	// bucket[1] = [200, 300). lower=200, upper=300.
	// interpolated = 200 + (75 - 50) / 50 * 100 = 200 + 50 = 250.
	make50 := func(bucketIdx int) []int32 {
		c := make([]int32, NumBuckets)
		c[bucketIdx] = 50
		return c
	}
	rollups := []HourlyRollup{
		{
			RollupKey:    RollupKey{Metric: "lcp", Device: "mobile", Country: "GB"},
			SampleCount:  50,
			BucketCounts: make50(0),
		},
		{
			RollupKey:    RollupKey{Metric: "lcp", Device: "mobile", Country: "GB"},
			SampleCount:  50,
			BucketCounts: make50(1),
		},
	}
	results := computeP75(rollups, 100)
	if len(results) != 1 {
		t.Fatalf("expected 1 result (groups collapsed), got %d", len(results))
	}
	got := results[0].P75Milli
	want := 250.0
	if math.Abs(got-want) > 1.0 {
		t.Errorf("P75Milli = %f, want ≈ %f", got, want)
	}
}

func TestComputeP75_multiple_metrics(t *testing.T) {
	// Two different metrics: lcp + fcp, each 200 samples.
	// lcp: all in bucket[0], p75 → ~150ms.
	// fcp: all in bucket[2] ([300,400)), p75 → ≈ 375ms.
	make200 := func(bucketIdx int) []int32 {
		c := make([]int32, NumBuckets)
		c[bucketIdx] = 200
		return c
	}
	rollups := []HourlyRollup{
		{RollupKey: RollupKey{Metric: "lcp", Device: "desktop", Country: "US"}, SampleCount: 200, BucketCounts: make200(0), MaxValue: 200},
		{RollupKey: RollupKey{Metric: "fcp", Device: "desktop", Country: "US"}, SampleCount: 200, BucketCounts: make200(2), MaxValue: 400},
	}
	results := computeP75(rollups, 100)
	if len(results) != 2 {
		t.Fatalf("expected 2 results, got %d", len(results))
	}
	// Results are sorted: fcp < lcp alphabetically.
	metrics := map[string]float64{}
	for _, r := range results {
		metrics[r.Metric] = r.P75Milli
	}
	if math.Abs(metrics["lcp"]-150.0) > 1.0 {
		t.Errorf("lcp P75Milli = %f, want ≈ 150", metrics["lcp"])
	}
	// fcp in [300, 400): p75 = 300 + (150 - 0)/200 * 100 = 375.
	if math.Abs(metrics["fcp"]-375.0) > 1.0 {
		t.Errorf("fcp P75Milli = %f, want ≈ 375", metrics["fcp"])
	}
}

// ---------------------------------------------------------------------------
// Beacon-key generation tests
// ---------------------------------------------------------------------------

func TestGenerateBeaconKey_format(t *testing.T) {
	pt, hash, err := GenerateBeaconKey()
	if err != nil {
		t.Fatalf("GenerateBeaconKey: %v", err)
	}
	if pt == "" {
		t.Error("plaintext must not be empty")
	}
	if len(hash) != 32 {
		t.Errorf("hash must be 32 bytes (sha256), got %d", len(hash))
	}
}

func TestGenerateBeaconKey_unique(t *testing.T) {
	pt1, _, _ := GenerateBeaconKey()
	pt2, _, _ := GenerateBeaconKey()
	if pt1 == pt2 {
		t.Error("two GenerateBeaconKey calls returned the same plaintext")
	}
}

func TestHashBeaconKeyFromPlaintext_roundtrip(t *testing.T) {
	pt, expectedHash, err := GenerateBeaconKey()
	if err != nil {
		t.Fatalf("GenerateBeaconKey: %v", err)
	}
	got, err := HashBeaconKeyFromPlaintext(pt)
	if err != nil {
		t.Fatalf("HashBeaconKeyFromPlaintext: %v", err)
	}
	if len(got) != 32 {
		t.Errorf("hash length = %d, want 32", len(got))
	}
	// The hash derived from the plaintext must match the one returned by GenerateBeaconKey.
	for i, b := range expectedHash {
		if got[i] != b {
			t.Errorf("hash mismatch at byte %d: got %02x, want %02x", i, got[i], b)
		}
	}
}

func TestHashBeaconKeyFromPlaintext_invalid(t *testing.T) {
	_, err := HashBeaconKeyFromPlaintext("not-valid-base64!!!")
	if err == nil {
		t.Error("expected error for invalid base64 input")
	}
}

// ---------------------------------------------------------------------------
// URL normalization tests
// ---------------------------------------------------------------------------

func TestNormalizeURL_strips_query(t *testing.T) {
	cases := []struct {
		input string
		want  string
	}{
		{"https://example.com/blog/hello-world?utm_source=email", "/blog/hello-world"},
		{"https://example.com/shop/product/123", "/shop/product/{id}"},
		{"https://example.com/user/f47ac10b-58cc-4372-a567-0e02b2c3d479/profile", "/user/{uuid}/profile"},
		{"https://example.com/", "/"},
		{"https://example.com", "/"},
		{"/contact-us", "/contact-us"},
		{"/p/12345-blue-widget", "/p/{id}"},
	}
	for _, c := range cases {
		got := NormalizeURL(c.input)
		if got != c.want {
			t.Errorf("NormalizeURL(%q) = %q, want %q", c.input, got, c.want)
		}
	}
}

func TestNormalizeCountry_valid(t *testing.T) {
	if got := NormalizeCountry("US", nil); got != "US" {
		t.Errorf("NormalizeCountry(US) = %q, want US", got)
	}
	if got := NormalizeCountry("gb", nil); got != "GB" {
		t.Errorf("NormalizeCountry(gb) = %q, want GB", got)
	}
	if got := NormalizeCountry("XYZ", nil); got != "__other__" {
		t.Errorf("NormalizeCountry(XYZ) = %q, want __other__", got)
	}
	if got := NormalizeCountry("", nil); got != "__other__" {
		t.Errorf("NormalizeCountry empty = %q, want __other__", got)
	}
}

func TestNormalizeCountry_allowSet(t *testing.T) {
	allowSet := map[string]bool{"US": true, "GB": true}
	if got := NormalizeCountry("DE", allowSet); got != "__other__" {
		t.Errorf("NormalizeCountry DE with allowSet = %q, want __other__", got)
	}
	if got := NormalizeCountry("US", allowSet); got != "US" {
		t.Errorf("NormalizeCountry US with allowSet = %q, want US", got)
	}
}

// ---------------------------------------------------------------------------
// CrUXBuckets consistency check
// ---------------------------------------------------------------------------

func TestCrUXBuckets_count(t *testing.T) {
	if NumBuckets != len(CrUXBuckets)+1 {
		t.Errorf("NumBuckets=%d but len(CrUXBuckets)+1=%d", NumBuckets, len(CrUXBuckets)+1)
	}
	if NumBuckets != 24 {
		t.Errorf("expected NumBuckets=24, got %d", NumBuckets)
	}
}

func TestCrUXBuckets_ascending(t *testing.T) {
	for i := 1; i < len(CrUXBuckets); i++ {
		if CrUXBuckets[i] <= CrUXBuckets[i-1] {
			t.Errorf("CrUXBuckets not strictly ascending at index %d: %d <= %d",
				i, CrUXBuckets[i], CrUXBuckets[i-1])
		}
	}
}
