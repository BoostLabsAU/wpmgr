package rum

import (
	"math"
	"sort"
)

// computeP75 groups hourly rollup rows by (metric, device, country), element-wise
// sums their bucket_counts, and interpolates the 75th percentile within the
// CrUXBuckets boundaries. Groups below minSampleCount have P75Milli == 0.
//
// Algorithm (linear interpolation within the containing bucket):
//
//  1. Total = sum(bucket_counts)
//  2. Target = ceil(0.75 * Total) — the sample rank at the 75th percentile.
//  3. Walk buckets in ascending order, accumulating counts.
//  4. When the cumulative count first reaches Target, the value is in bucket i:
//     lower bound = CrUXBuckets[i-1] (or 0 for i==0)
//     upper bound = CrUXBuckets[i]   (or MaxValue for the open-ended last bin)
//  5. Linearly interpolate: lower + (target - prev_cum) / count_i * (upper - lower)
func computeP75(rollups []HourlyRollup, minSampleCount int) []P75Result {
	type groupKey struct {
		metric  string
		device  string
		country string
	}
	type acc struct {
		counts      []int64 // NumBuckets element-wise sum
		sampleCount int64
		minVal      int32
		maxVal      int32
	}

	groups := make(map[groupKey]*acc)
	for _, r := range rollups {
		k := groupKey{metric: r.Metric, device: r.Device, country: r.Country}
		g, ok := groups[k]
		if !ok {
			g = &acc{
				counts: make([]int64, NumBuckets),
				minVal: r.MinValue,
				maxVal: r.MaxValue,
			}
			groups[k] = g
		}
		g.sampleCount += r.SampleCount
		if r.MinValue < g.minVal {
			g.minVal = r.MinValue
		}
		if r.MaxValue > g.maxVal {
			g.maxVal = r.MaxValue
		}
		for i, c := range r.BucketCounts {
			if i < NumBuckets {
				g.counts[i] += int64(c)
			}
		}
	}

	// Sort keys for deterministic output.
	keys := make([]groupKey, 0, len(groups))
	for k := range groups {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		a, b := keys[i], keys[j]
		if a.metric != b.metric {
			return a.metric < b.metric
		}
		if a.device != b.device {
			return a.device < b.device
		}
		return a.country < b.country
	})

	results := make([]P75Result, 0, len(keys))
	for _, k := range keys {
		g := groups[k]
		res := P75Result{
			Metric:      k.metric,
			Device:      k.device,
			Country:     k.country,
			SampleCount: g.sampleCount,
		}
		if g.sampleCount < int64(minSampleCount) {
			// Suppress low-N results to avoid misleading estimates.
			results = append(results, res)
			continue
		}

		total := g.sampleCount
		target := int64(math.Ceil(0.75 * float64(total)))
		var cum int64
		res.P75Milli = interpolateP75(g.counts, target, &cum, g.maxVal)
		results = append(results, res)
	}
	return results
}

// interpolateP75 locates the bucket containing the target-th sample (1-indexed)
// and linearly interpolates within it.
func interpolateP75(counts []int64, target int64, _ *int64, maxVal int32) float64 {
	var cum int64
	for i, c := range counts {
		cum += c
		if cum >= target {
			// This bucket contains the target sample.
			var lower, upper float64
			if i == 0 {
				lower = 0
			} else {
				lower = float64(CrUXBuckets[i-1])
			}
			if i < len(CrUXBuckets) {
				upper = float64(CrUXBuckets[i])
			} else {
				// Open-ended last bin: interpolate to MaxValue.
				upper = float64(maxVal)
				if upper <= lower {
					upper = lower + 1000 // fallback: 1 s above lower bound
				}
			}
			if c == 0 {
				return lower
			}
			// prev cumulative count (before this bucket).
			prevCum := cum - c
			offset := float64(target-prevCum) / float64(c)
			return lower + offset*(upper-lower)
		}
	}
	// All samples accounted for but target not reached — return upper boundary
	// of the last non-empty bucket.
	if len(CrUXBuckets) > 0 {
		return float64(CrUXBuckets[len(CrUXBuckets)-1])
	}
	return 0
}
