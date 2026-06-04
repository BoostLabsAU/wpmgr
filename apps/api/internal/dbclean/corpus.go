package dbclean

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// Signature holds the corpus data for a single wordpress.org plugin slug. It
// is the in-memory representation of a plugin_signatures row used by the
// classifier.
type Signature struct {
	// Slug is the wordpress.org plugin slug (primary key).
	Slug string

	// CorpusVersion is the integer version of the corpus build that produced
	// this row. Echoed in OrphansReport.CorpusVersion so operators can see
	// when the corpus was last refreshed.
	CorpusVersion int32

	// OptionPatterns is the list of RE2-compatible pattern strings for wp_options
	// option_name values owned by this plugin. Plain literals classify as
	// ConfidenceExact; anchored patterns (^prefix_) classify as ConfidencePrefix.
	OptionPatterns []string

	// TransientPatterns is the list of patterns for _transient_* option names.
	TransientPatterns []string

	// TablePatterns is the list of patterns matched against the FULL table name
	// including the default wp_ prefix (e.g. ^wp_woocommerce, ^wp_wc_). Tables
	// on a site with a non-default table prefix will not match and fall through
	// to heuristic/unknown (the safe direction — never deletable-eligible).
	TablePatterns []string

	// CronHookPatterns is the list of patterns for WP-Cron hook names.
	CronHookPatterns []string
}

// CorpusReader is the interface through which the classifier reads the
// plugin_signatures corpus. The production implementation is
// CorpusPostgresReader; a future go:embed implementation would be a drop-in.
type CorpusReader interface {
	// GetPluginSignatures returns the Signature for a single slug. Returns an
	// error wrapping ErrNotFound if the slug is absent from the corpus.
	GetPluginSignatures(ctx context.Context, slug string) (Signature, error)

	// AllSignatures returns all corpus signatures, ordered by slug. Used by the
	// classifier for batch classification (loads once per batch, then iterates
	// in-process). The slice is safe to hold across the lifetime of a single
	// Classify call; callers should not modify it.
	AllSignatures(ctx context.Context) ([]Signature, error)
}

// ErrNotFound is returned by GetPluginSignatures when the slug is not in the
// corpus. Use errors.Is to detect it.
var ErrNotFound = fmt.Errorf("plugin signature not found in corpus")

// CorpusPostgresReader implements CorpusReader backed by the plugin_signatures
// Postgres table via the sqlc-generated Queries object. The corpus table uses
// ENABLE RLS with a permissive SELECT policy and no tenant GUC is required —
// any authenticated session (including the wpmgr_app role) can read all rows.
type CorpusPostgresReader struct {
	q *sqlc.Queries
}

// NewCorpusPostgresReader returns a CorpusPostgresReader backed by the given
// sqlc.Queries. The typical call site is:
//
//	reader := dbclean.NewCorpusPostgresReader(sqlc.New(pool))
func NewCorpusPostgresReader(q *sqlc.Queries) *CorpusPostgresReader {
	return &CorpusPostgresReader{q: q}
}

// GetPluginSignatures fetches a single corpus Signature by slug.
func (r *CorpusPostgresReader) GetPluginSignatures(ctx context.Context, slug string) (Signature, error) {
	row, err := r.q.GetPluginSignatureBySlug(ctx, slug)
	if err != nil {
		return Signature{}, fmt.Errorf("corpus: GetPluginSignatures %q: %w", slug, err)
	}
	return toSignature(row)
}

// AllSignatures returns all corpus Signature rows, ordered by slug.
func (r *CorpusPostgresReader) AllSignatures(ctx context.Context) ([]Signature, error) {
	rows, err := r.q.AllPluginSignatures(ctx)
	if err != nil {
		return nil, fmt.Errorf("corpus: AllSignatures: %w", err)
	}
	sigs := make([]Signature, 0, len(rows))
	for _, row := range rows {
		sig, err := toSignature(row)
		if err != nil {
			return nil, err
		}
		sigs = append(sigs, sig)
	}
	return sigs, nil
}

// toSignature converts a sqlc PluginSignature row to a Signature. The four
// pattern columns are stored as JSONB []byte; we unmarshal them to []string.
func toSignature(row sqlc.PluginSignature) (Signature, error) {
	sig := Signature{
		Slug:          row.Slug,
		CorpusVersion: row.CorpusVersion,
	}

	if err := unmarshalPatterns(row.OptionPatterns, &sig.OptionPatterns); err != nil {
		return sig, fmt.Errorf("corpus: decode option_patterns for %q: %w", row.Slug, err)
	}
	if err := unmarshalPatterns(row.TransientPatterns, &sig.TransientPatterns); err != nil {
		return sig, fmt.Errorf("corpus: decode transient_patterns for %q: %w", row.Slug, err)
	}
	if err := unmarshalPatterns(row.TablePatterns, &sig.TablePatterns); err != nil {
		return sig, fmt.Errorf("corpus: decode table_patterns for %q: %w", row.Slug, err)
	}
	if err := unmarshalPatterns(row.CronHookPatterns, &sig.CronHookPatterns); err != nil {
		return sig, fmt.Errorf("corpus: decode cron_hook_patterns for %q: %w", row.Slug, err)
	}
	return sig, nil
}

func unmarshalPatterns(b []byte, dst *[]string) error {
	if len(b) == 0 || string(b) == "null" || string(b) == "[]" {
		*dst = nil
		return nil
	}
	return json.Unmarshal(b, dst)
}
