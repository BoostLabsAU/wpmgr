// export_test.go exposes package-private functions for white-box testing from
// the vuln_test package. This file is compiled only during `go test`.
package vuln

import "encoding/json"

// IsSafeURL is isSafeURL exposed for testing (F2).
func IsSafeURL(u string) bool { return isSafeURL(u) }

// FilterReferences is filterReferences exposed for testing (F2).
func FilterReferences(raw json.RawMessage) json.RawMessage { return filterReferences(raw) }

// NormSlug returns the normalised (lower-cased) form of a software slug (F3).
// This mirrors the normalisation applied on both the ingest path
// (UpsertFeedRecord) and the lookup path (LookupSoftware).
func NormSlug(slug string) string { return normSlug(slug) }

// ParseFeedRecord exposes parseFeedRecord for parser unit tests.
func ParseFeedRecord(vulnID string, raw json.RawMessage) (FeedRecord, string, string, string, error) {
	return parseFeedRecord(vulnID, raw)
}

// ErrNoUsableSoftware exposes errNoUsableSoftware so tests can assert the skip sentinel.
var ErrNoUsableSoftware = errNoUsableSoftware
