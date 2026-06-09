package rum

import (
	"regexp"
	"strings"
)

// NormalizeURL strips the query string and fragments, and templates dynamic path
// segments so that distinct page variants (e.g. /products/123, /products/456)
// collapse to a single pattern (/products/{id}) for rollup.
//
// Normalization rules (applied in order):
//  1. Strip scheme+host (keep only the path).
//  2. Strip query string and fragment.
//  3. Replace pure-numeric segments with {id}.
//  4. Replace UUID-shaped segments with {uuid}.
//  5. Replace segments that look like slugs with many digits with {id}
//     (e.g. /p/12345-blue-widget → /p/{id}).
//  6. Collapse repeated slashes.
//  7. Return "/" when the resulting path is empty.
//
// The caller is responsible for ensuring the input is a URL (or at minimum a
// path string). NormalizeURL does not validate the URL — it normalizes
// defensively.
func NormalizeURL(rawURL string) string {
	// Keep only path (strip scheme, host, query, fragment).
	path := rawURL

	// Strip query string.
	if idx := strings.IndexByte(path, '?'); idx >= 0 {
		path = path[:idx]
	}
	// Strip fragment.
	if idx := strings.IndexByte(path, '#'); idx >= 0 {
		path = path[:idx]
	}
	// Strip scheme+host: find the first "/" after "://" (or just the first "/").
	if idx := strings.Index(path, "://"); idx >= 0 {
		rest := path[idx+3:]
		if slashIdx := strings.IndexByte(rest, '/'); slashIdx >= 0 {
			path = rest[slashIdx:]
		} else {
			path = "/"
		}
	}

	// Template dynamic segments.
	path = reDynamicSegment.ReplaceAllStringFunc(path, func(seg string) string {
		seg = strings.TrimPrefix(seg, "/")
		if reUUID.MatchString(seg) {
			return "/{uuid}"
		}
		if reNumeric.MatchString(seg) {
			return "/{id}"
		}
		if reSlugWithDigits.MatchString(seg) {
			return "/{id}"
		}
		return "/" + seg
	})

	// Collapse repeated slashes.
	for strings.Contains(path, "//") {
		path = strings.ReplaceAll(path, "//", "/")
	}

	if path == "" {
		return "/"
	}
	return path
}

var (
	// reDynamicSegment matches each path segment (including the leading "/").
	reDynamicSegment = regexp.MustCompile(`/[^/]+`)

	// reNumeric matches segments that are entirely digits.
	reNumeric = regexp.MustCompile(`^\d+$`)

	// reUUID matches UUID v4 / v1 shaped strings.
	reUUID = regexp.MustCompile(`(?i)^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

	// reSlugWithDigits matches slugs that start with 4+ digits (WooCommerce product
	// IDs embedded in slugs, e.g. "12345-blue-widget").
	reSlugWithDigits = regexp.MustCompile(`^\d{4,}[-]`)
)

// NormalizeCountry applies the country top-N cap: if the given ISO-3166-1
// alpha-2 country code is not in the allow set, it returns "__other__". The
// allow set is the per-site max_distinct_countries most-frequent countries
// previously seen (maintained by the rollup worker). For Phase 1 the caller
// passes a nil allow set to disable the cap (all countries pass through).
func NormalizeCountry(country string, allowSet map[string]bool) string {
	if len(country) != 2 {
		return "__other__"
	}
	c := strings.ToUpper(country)
	if allowSet == nil {
		return c
	}
	if allowSet[c] {
		return c
	}
	return "__other__"
}

// AllowedMetrics is the set of Web Vitals metric names accepted by the ingest
// handler. Any metric not in this set is rejected.
var AllowedMetrics = map[string]bool{
	"lcp":  true,
	"inp":  true,
	"cls":  true,
	"ttfb": true,
	"fcp":  true,
}

// AllowedDevices is the set of device values accepted by the ingest handler.
var AllowedDevices = map[string]bool{
	"desktop": true,
	"mobile":  true,
	"tablet":  true,
}

// AllowedConn is the set of network connection type values accepted.
var AllowedConn = map[string]bool{
	"4g":      true,
	"3g":      true,
	"2g":      true,
	"slow-2g": true,
	"offline": true,
	"unknown": true,
}
