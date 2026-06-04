package perf

import (
	"context"
	"encoding/json"
	"strings"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/agentcmd"
	"github.com/mosamlife/wpmgr/apps/api/internal/dbclean"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

// CorpusSource is the interface the orphans classifier uses to obtain corpus
// data. dbclean.CorpusPostgresReader satisfies it; tests inject a lightweight
// in-memory fake without needing a database.
type CorpusSource interface {
	dbclean.CorpusReader
}

// GetOrphansReport classifies the orphaned artefacts stored in the latest
// db_scan result for a site and returns a structured report.
//
// It performs on-demand classification: the stored candidate lists
// (orphaned_options_json, orphaned_cron_json, tables_json) and the installed-
// plugin snapshot (installed_plugins_json) are read from the latest scan row,
// then classified fresh against the current corpus.  No new column is written.
//
// Three artefact kinds are classified:
//   - OPTIONS: orphaned wp_options rows (kind="option").
//   - CRON: orphaned WP-Cron hook events (kind="cron_hook").
//   - TABLES: tables whose owner_type is "orphan" in tables_json (kind="table").
//
// After classification, an installed-set cross-check is performed: the corpus
// normalises each installed plugin slug and checks it against the owning slug
// and all KnownPlugins for each item.  Items whose owner is present in the
// installed set get Installed=true and are never DeletableEligible.
//
// DeletableEligible is true only when:
//   - Confidence is "exact" or "prefix" (heuristic and unknown are never eligible).
//   - len(KnownPlugins) == 1 (no multi-candidate ambiguity).
//   - Installed == false (the owning plugin is absent from the snapshot).
//
// Returns domain.ErrNotFound (wrapped) when no scan has been run for the site.
func (s *Service) GetOrphansReport(ctx context.Context, corpusSource CorpusSource, tenantID, siteID uuid.UUID) (OrphansReport, error) {
	// Load the latest scan result (RLS-scoped via InTenantTx in the repo).
	raw, err := s.repo.GetDBScanResult(ctx, tenantID, siteID)
	if err == ErrNotFound {
		return OrphansReport{}, domain.NotFound("no_scan", "no database scan result found; run a scan first")
	}
	if err != nil {
		return OrphansReport{}, err
	}

	// Unmarshal the orphan slices from JSONB.
	orphanedOptions, err := unmarshalOrphanedOptions(raw.OrphanedOptionsJSON)
	if err != nil {
		return OrphansReport{}, domain.Internal("orphans_decode_options", "failed to decode orphaned_options_json")
	}
	orphanedCron, err := unmarshalOrphanedCron(raw.OrphanedCronJSON)
	if err != nil {
		return OrphansReport{}, domain.Internal("orphans_decode_cron", "failed to decode orphaned_cron_json")
	}
	// Orphan tables are derived from the full table inventory: only rows where
	// owner_type == "orphan" are candidates for the corpus classification.
	orphanTables, err := extractOrphanTables(raw.TablesJSON)
	if err != nil {
		return OrphansReport{}, domain.Internal("orphans_decode_tables", "failed to decode tables_json")
	}

	// Build the installed-set from the installed_plugins_json snapshot.
	installedSlugs, err := buildInstalledSet(raw.InstalledPluginsJSON)
	if err != nil {
		return OrphansReport{}, domain.Internal("orphans_decode_installed", "failed to decode installed_plugins_json")
	}
	// snapshotAvailable gates DeletableEligible: with no installed snapshot the
	// cross-check is indeterminate, so nothing may be marked deletable. A real
	// snapshot always yields a non-empty set (every WP site has >=1 plugin/dropin).
	snapshotAvailable := len(installedSlugs) > 0

	// Instantiate the classifier backed by the supplied corpus source.
	clf := dbclean.NewClassifier(corpusSource)

	// Determine the corpus version from the first loaded signature (informational).
	corpusVersion := 0
	sigs, _ := corpusSource.AllSignatures(ctx)
	if len(sigs) > 0 {
		corpusVersion = int(sigs[0].CorpusVersion)
	}

	// Classify each artefact kind.
	optItems, err := classifyOptions(ctx, clf, orphanedOptions, installedSlugs, snapshotAvailable)
	if err != nil {
		return OrphansReport{}, err
	}
	cronItems, err := classifyCron(ctx, clf, orphanedCron, installedSlugs, snapshotAvailable)
	if err != nil {
		return OrphansReport{}, err
	}
	tableItems, err := classifyTables(ctx, clf, orphanTables, installedSlugs, snapshotAvailable)
	if err != nil {
		return OrphansReport{}, err
	}

	// De-noise: exclude items whose attributed owner is currently installed.
	// An installed plugin still owns its artefacts; those items are not real
	// orphans and must not appear in the report or count. hiddenInstalled
	// tracks the total suppressed count across all kinds so the UI can surface
	// an informational note (e.g. "N items attributed to installed plugins were
	// hidden"). DeletableEligible already requires installed==false, so no
	// eligible item can ever be excluded by this filter.
	optItems, hiddenOpts := filterOutInstalled(optItems)
	cronItems, hiddenCron := filterOutInstalled(cronItems)
	tableItems, hiddenTables := filterOutInstalled(tableItems)
	hiddenInstalled := hiddenOpts + hiddenCron + hiddenTables

	// Build count summary over the de-noised slices.
	deletable := 0
	for _, item := range optItems {
		if item.DeletableEligible {
			deletable++
		}
	}
	for _, item := range cronItems {
		if item.DeletableEligible {
			deletable++
		}
	}
	for _, item := range tableItems {
		if item.DeletableEligible {
			deletable++
		}
	}

	return OrphansReport{
		Options:           optItems,
		Cron:              cronItems,
		Tables:            tableItems,
		CorpusVersion:     corpusVersion,
		SnapshotAvailable: snapshotAvailable,
		HiddenInstalled:   hiddenInstalled,
		Counts: OrphansCountSummary{
			Options:   len(optItems),
			Cron:      len(cronItems),
			Tables:    len(tableItems),
			Deletable: deletable,
		},
	}, nil
}

// filterOutInstalled partitions items into those whose owner is NOT installed
// (kept) and those whose owner IS installed (suppressed). It returns the kept
// slice and the suppressed count. The function never mutates the input slice.
func filterOutInstalled(items []OrphanItem) (kept []OrphanItem, suppressed int) {
	kept = make([]OrphanItem, 0, len(items))
	for _, item := range items {
		if item.Installed {
			suppressed++
		} else {
			kept = append(kept, item)
		}
	}
	return kept, suppressed
}

// ---------------------------------------------------------------------------
// classification helpers
// ---------------------------------------------------------------------------

// classifyOptions classifies orphaned wp_options candidates against the corpus
// using kind="option" and applies the installed-set cross-check.
func classifyOptions(ctx context.Context, clf *dbclean.Classifier, items []agentcmd.OrphanedOptionItem, installed map[string]struct{}, snapshotAvailable bool) ([]OrphanItem, error) {
	if len(items) == 0 {
		return []OrphanItem{}, nil
	}
	names := make([]string, len(items))
	for i, it := range items {
		names[i] = it.Name
	}
	classifications, err := clf.Classify(ctx, names, "option")
	if err != nil {
		return nil, domain.Internal("orphans_classify_options", "classifier error: "+err.Error())
	}

	out := make([]OrphanItem, len(items))
	for i, it := range items {
		cl := classifications[i]
		autoload := it.Autoload
		installedFlag := isInstalled(cl.OwnerSlug, cl.KnownPlugins, installed)
		out[i] = OrphanItem{
			Name:              it.Name,
			OwnerSlug:         cl.OwnerSlug,
			Confidence:        string(cl.Confidence),
			KnownPlugins:      nonNilStrings(cl.KnownPlugins),
			Installed:         installedFlag,
			DeletableEligible: isDeletableEligible(cl, installedFlag, snapshotAvailable),
			SizeBytes:         it.SizeBytes,
			Autoload:          &autoload,
		}
	}
	return out, nil
}

// classifyCron classifies orphaned WP-Cron event candidates against the corpus
// using kind="cron_hook" and applies the installed-set cross-check.
func classifyCron(ctx context.Context, clf *dbclean.Classifier, items []agentcmd.OrphanedCronItem, installed map[string]struct{}, snapshotAvailable bool) ([]OrphanItem, error) {
	if len(items) == 0 {
		return []OrphanItem{}, nil
	}
	hooks := make([]string, len(items))
	for i, it := range items {
		hooks[i] = it.Hook
	}
	classifications, err := clf.Classify(ctx, hooks, "cron_hook")
	if err != nil {
		return nil, domain.Internal("orphans_classify_cron", "classifier error: "+err.Error())
	}

	out := make([]OrphanItem, len(items))
	for i, it := range items {
		cl := classifications[i]
		nextRunAt := it.NextRunAt
		installedFlag := isInstalled(cl.OwnerSlug, cl.KnownPlugins, installed)
		out[i] = OrphanItem{
			Name:              it.Hook,
			OwnerSlug:         cl.OwnerSlug,
			Confidence:        string(cl.Confidence),
			KnownPlugins:      nonNilStrings(cl.KnownPlugins),
			Installed:         installedFlag,
			DeletableEligible: isDeletableEligible(cl, installedFlag, snapshotAvailable),
			NextRunAt:         &nextRunAt,
			Recurrence:        it.Recurrence,
		}
	}
	return out, nil
}

// classifyTables classifies orphan-table candidates against the corpus using
// kind="table" and applies the installed-set cross-check.
func classifyTables(ctx context.Context, clf *dbclean.Classifier, items []agentcmd.DBScanTableInventoryRow, installed map[string]struct{}, snapshotAvailable bool) ([]OrphanItem, error) {
	if len(items) == 0 {
		return []OrphanItem{}, nil
	}
	names := make([]string, len(items))
	for i, it := range items {
		names[i] = it.Name
	}
	classifications, err := clf.Classify(ctx, names, "table")
	if err != nil {
		return nil, domain.Internal("orphans_classify_tables", "classifier error: "+err.Error())
	}

	out := make([]OrphanItem, len(items))
	for i, it := range items {
		cl := classifications[i]
		rows := it.Rows
		installedFlag := isInstalled(cl.OwnerSlug, cl.KnownPlugins, installed)
		out[i] = OrphanItem{
			Name:              it.Name,
			OwnerSlug:         cl.OwnerSlug,
			Confidence:        string(cl.Confidence),
			KnownPlugins:      nonNilStrings(cl.KnownPlugins),
			Installed:         installedFlag,
			DeletableEligible: isDeletableEligible(cl, installedFlag, snapshotAvailable),
			SizeBytes:         it.SizeBytes,
			Rows:              &rows,
		}
	}
	return out, nil
}

// ---------------------------------------------------------------------------
// installed-set cross-check
// ---------------------------------------------------------------------------

// buildInstalledSet parses installed_plugins_json into a normalised slug set.
// The set contains both the raw slug and the normalised form (hyphens and
// underscores removed, lower-cased) so that corpus slugs that differ only in
// separator style (e.g. "contact-form-7" vs "contact_form_7") are matched.
//
// The function is intentionally tolerant of empty or null JSON (both produce
// an empty set, which is the safe default for agents < 0.16.0 that omit the
// field).
func buildInstalledSet(raw []byte) (map[string]struct{}, error) {
	set := make(map[string]struct{})
	if len(raw) == 0 || string(raw) == "null" || string(raw) == "[]" {
		return set, nil
	}
	var items []agentcmd.InstalledPluginItem
	if err := json.Unmarshal(raw, &items); err != nil {
		return nil, err
	}
	for _, item := range items {
		slug := strings.ToLower(item.Slug)
		set[slug] = struct{}{}
		// Also add the normalised (no separator) form so corpus slugs with
		// hyphens match a directory slug that uses underscores (or vice versa).
		set[normalizeSlugLocal(slug)] = struct{}{}
	}
	return set, nil
}

// isInstalled returns true when ownerSlug or any of knownPlugins appears in the
// installed set. Both the raw value and its normalised form are tested so that
// slug format differences (hyphens vs underscores) are bridged.
func isInstalled(ownerSlug string, knownPlugins []string, installed map[string]struct{}) bool {
	if len(installed) == 0 {
		return false
	}
	candidates := make([]string, 0, 1+len(knownPlugins))
	if ownerSlug != "" {
		candidates = append(candidates, ownerSlug)
	}
	candidates = append(candidates, knownPlugins...)

	for _, slug := range candidates {
		slug = strings.ToLower(slug)
		if _, ok := installed[slug]; ok {
			return true
		}
		if _, ok := installed[normalizeSlugLocal(slug)]; ok {
			return true
		}
	}
	return false
}

// normalizeSlugLocal removes hyphens and underscores and lower-cases a slug.
// Mirrors dbclean.normalizeSlug (which is package-private and cannot be called
// from perf) so the installed-set lookup uses the same normalisation logic.
func normalizeSlugLocal(slug string) string {
	return strings.ToLower(strings.NewReplacer("-", "", "_", "").Replace(slug))
}

// isDeletableEligible applies the conservative deletion-eligibility gate:
//   - Confidence must be exact or prefix (never heuristic or unknown).
//   - Exactly one KnownPlugin (no multi-candidate ambiguity).
//   - The owning plugin must not be in the installed set (Installed==false).
func isDeletableEligible(cl dbclean.Classification, installed, snapshotAvailable bool) bool {
	// Fail-safe: without an installed-plugins snapshot the cross-check is
	// indeterminate (the owning plugin could be installed but simply unreported,
	// e.g. a scan from an agent < 0.16.0). Never mark an item deletable when the
	// safety oracle is absent.
	if !snapshotAvailable {
		return false
	}
	if installed {
		return false
	}
	if cl.Confidence != dbclean.ConfidenceExact && cl.Confidence != dbclean.ConfidencePrefix {
		return false
	}
	return len(cl.KnownPlugins) == 1
}

// ---------------------------------------------------------------------------
// JSON unmarshal helpers
// ---------------------------------------------------------------------------

// unmarshalOrphanedOptions decodes orphaned_options_json. Returns an empty
// slice (not an error) on null/empty input for backward-compat with agents
// that predate Phase 3.3.
func unmarshalOrphanedOptions(raw []byte) ([]agentcmd.OrphanedOptionItem, error) {
	if len(raw) == 0 || string(raw) == "null" || string(raw) == "[]" {
		return nil, nil
	}
	var items []agentcmd.OrphanedOptionItem
	if err := json.Unmarshal(raw, &items); err != nil {
		return nil, err
	}
	return items, nil
}

// unmarshalOrphanedCron decodes orphaned_cron_json.
func unmarshalOrphanedCron(raw []byte) ([]agentcmd.OrphanedCronItem, error) {
	if len(raw) == 0 || string(raw) == "null" || string(raw) == "[]" {
		return nil, nil
	}
	var items []agentcmd.OrphanedCronItem
	if err := json.Unmarshal(raw, &items); err != nil {
		return nil, err
	}
	return items, nil
}

// extractOrphanTables decodes tables_json and returns only those rows whose
// OwnerType is "orphan". The classification pipeline then runs the corpus
// classifier over these to try to attribute them to an uninstalled plugin.
func extractOrphanTables(raw []byte) ([]agentcmd.DBScanTableInventoryRow, error) {
	if len(raw) == 0 || string(raw) == "null" || string(raw) == "[]" {
		return nil, nil
	}
	var all []agentcmd.DBScanTableInventoryRow
	if err := json.Unmarshal(raw, &all); err != nil {
		return nil, err
	}
	out := make([]agentcmd.DBScanTableInventoryRow, 0, len(all))
	for _, row := range all {
		if row.OwnerType == "orphan" {
			out = append(out, row)
		}
	}
	return out, nil
}

// nonNilStrings returns an empty non-nil slice when ss is nil, otherwise ss.
// Prevents null in JSON output for KnownPlugins.
func nonNilStrings(ss []string) []string {
	if ss == nil {
		return []string{}
	}
	return ss
}
