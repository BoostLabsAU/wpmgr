// Package dbclean implements the Database Cleaner corpus-based classification
// pipeline. Phase 3.1 (Corpus Foundation) ships the type definitions used by
// the classifier (P3.2) and the orphan-scan pipeline (P3.3+). Nothing in this
// file executes any destructive database operation.
package dbclean

// ConfidenceLevel represents how confidently the classifier attributed an
// orphaned item (wp_options row, WP-Cron event, or custom table) to a known
// plugin slug from the corpus.
//
// Wire format: lowercase string — "exact" | "prefix" | "heuristic" | "unknown".
// Frontend float mapping: exact→0.95, prefix→0.80, heuristic→0.45, unknown→0.0.
//
// Deletion-gate semantics (enforced independently by the CP service layer and
// the frontend UI):
//   - ConfidenceExact, ConfidencePrefix: eligible for the signed delete
//     allowlist when OwnerSlug is non-empty AND the owning plugin is absent from
//     the site's installed-plugins snapshot at scan time.
//   - ConfidenceHeuristic: displayed read-only; never offered for deletion.
//   - ConfidenceUnknown: displayed read-only; no delete affordance.
type ConfidenceLevel string

const (
	// ConfidenceExact indicates the item name matched a plain-literal pattern in
	// the corpus (no regexp metacharacters). The most conservative and precise
	// level; the item name IS the corpus literal.
	ConfidenceExact ConfidenceLevel = "exact"

	// ConfidencePrefix indicates the item name matched an anchored regexp pattern
	// (e.g. ^wpcf7_) from a single corpus slug. High precision when the prefix is
	// unique to that slug in the corpus.
	ConfidencePrefix ConfidenceLevel = "prefix"

	// ConfidenceHeuristic indicates the item name was attributed via substring
	// slug-normalization (replace hyphens/underscores, lower-case slug appears as
	// a substring of the item name). Lower precision; never offered for deletion.
	ConfidenceHeuristic ConfidenceLevel = "heuristic"

	// ConfidenceUnknown indicates no corpus match was found. The item is displayed
	// informationally with no owner attribution and no delete affordance.
	ConfidenceUnknown ConfidenceLevel = "unknown"
)

// Classification is the result of classifying one item (option name, cron hook
// name, or table name) against the corpus.
type Classification struct {
	// ItemName is the raw name as returned by the agent (e.g. "wpcf7_last_version").
	ItemName string

	// ItemKind is the kind of item: "option", "transient", "cron_hook", or "table".
	ItemKind string

	// OwnerSlug is the wordpress.org plugin slug the item was attributed to.
	// Empty when Confidence is ConfidenceUnknown.
	OwnerSlug string

	// KnownPlugins is the list of ALL slugs whose signature matched this item
	// (across all passes that produced a hit). When len > 1, the item is
	// plausibly shared between multiple plugins. P3.8 uses this to refuse
	// deletion when ownership is ambiguous.
	KnownPlugins []string

	// Confidence is the classification level.
	Confidence ConfidenceLevel

	// PatternHit is the specific corpus pattern string that matched the item.
	// Empty for ConfidenceHeuristic and ConfidenceUnknown (heuristic uses slug
	// normalization, not a stored pattern).
	PatternHit string
}

// OrphanedOption represents a single wp_options row that the agent identified
// as potentially orphaned (not owned by an active installed plugin).
// Populated by the agent's scanOrphanedOptions() PHP method (P3.3).
type OrphanedOption struct {
	// OptionName is the raw option_name column value.
	OptionName string

	// Autoload indicates whether the row has autoload='yes'.
	Autoload bool

	// SizeBytes is LENGTH(option_value) at scan time.
	SizeBytes int64

	// GuessedPrefix is the static prefix the agent extracted from the option
	// name (e.g. "wpcf7_" from "wpcf7_last_version"). May be empty.
	GuessedPrefix string

	// Classification is set by the CP classifier (P3.5+) after the agent scan.
	// Zero value (ConfidenceUnknown, empty OwnerSlug) is safe: it just means the
	// item is displayed read-only with no delete affordance.
	Classification Classification
}

// OrphanedCronEvent represents a single WP-Cron event that the agent identified
// as potentially orphaned (the registered action hook has no active handler).
// Populated by the agent's scanOrphanedCron() PHP method (P3.3).
type OrphanedCronEvent struct {
	// HookName is the WP-Cron hook name (e.g. "wpcf7_autosave").
	HookName string

	// NextRunAt is the Unix timestamp of the next scheduled execution.
	NextRunAt int64

	// Recurrence is the schedule name (e.g. "daily") or empty for one-time events.
	Recurrence string

	// ArgsHash is a stable hash of the event arguments (for deduplication).
	ArgsHash string

	// ArgsCount is the number of arguments passed to the hook. Used to choose
	// between wp_unschedule_event (args_count > 0) and wp_clear_scheduled_hook.
	ArgsCount int

	// Classification is set by the CP classifier (P3.5+) after the agent scan.
	Classification Classification
}
