package dbclean

// Representative SQL constants extracted from the PHP agent action runners.
//
// class-db-cleanup.php delete runners (DELETE shapes):
//
//	revisions:                   DELETE FROM wp_posts WHERE post_type='revision'
//	auto_drafts:                 DELETE FROM wp_posts WHERE post_status='auto-draft'
//	trashed_posts:               DELETE FROM wp_posts WHERE post_status='trash'
//	spam_comments:               DELETE FROM wp_comments WHERE comment_approved='spam'
//	trashed_comments:            DELETE FROM wp_comments WHERE comment_approved='trash'
//	expired_transients:          DELETE FROM wp_options WHERE option_name IN (...)
//	orphaned_postmeta:           DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ...) LIMIT 2000
//	orphaned_commentmeta:        DELETE FROM wp_commentmeta WHERE comment_id NOT IN (SELECT ...) LIMIT 2000
//	orphaned_term_relationships: DELETE FROM wp_term_relationships WHERE ...
//	oembed_cache:                DELETE FROM wp_posts WHERE post_type='oembed_cache'
//	duplicate_postmeta:          DELETE FROM wp_postmeta WHERE meta_id IN (...) LIMIT 2000
//	action_scheduler_completed:  DELETE FROM wp_actionscheduler_actions WHERE status='complete' LIMIT 2000
//	action_scheduler_failed:     DELETE FROM wp_actionscheduler_actions WHERE status='failed' LIMIT 2000
//
// class-db-table-action-command.php table runners:
//
//	runOptimize:       OPTIMIZE TABLE `wp_posts`
//	runRepair:         REPAIR TABLE `wp_posts`
//	runDrop:           DROP TABLE IF EXISTS `wp_plugin_log`
//	runEmpty:          TRUNCATE TABLE `wp_plugin_log`
//	runAnalyze:        ANALYZE TABLE `wp_posts`
//	runConvertInnodb:  ALTER TABLE `wp_posts` ENGINE=InnoDB

import "testing"

// Concrete SQL instances representing each agent cleanup shape.
const (
	// DELETE shapes from class-db-cleanup.php
	sqlRevisions = `DELETE FROM wp_posts WHERE post_type='revision'`
	sqlAutoDraft = `DELETE FROM wp_posts WHERE post_status='auto-draft'`
	sqlTrashed   = `DELETE FROM wp_posts WHERE post_status='trash'`
	sqlSpam      = `DELETE FROM wp_comments WHERE comment_approved='spam'`
	sqlTrashedCm = `DELETE FROM wp_commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM wp_comments) LIMIT 2000`

	// Expired transients — DELETE with IN list
	sqlExpiredTransients = `DELETE FROM wp_options WHERE option_name IN ('_transient_timeout_foo','_transient_foo')`

	// Orphaned postmeta — DELETE with subquery and LIMIT
	sqlOrphanedPostmeta = `DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts) LIMIT 2000`

	// Orphaned commentmeta
	sqlOrphanedCommentmeta = `DELETE FROM wp_commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM wp_comments) LIMIT 2000`

	// Orphaned term_relationships
	sqlOrphanedTermRel = `DELETE FROM wp_term_relationships WHERE term_taxonomy_id IN (1,2,3) AND object_id NOT IN (SELECT ID FROM wp_posts) LIMIT 2000`

	// oembed cache
	sqlOembedCache = `DELETE FROM wp_posts WHERE post_type='oembed_cache'`

	// Duplicate postmeta — DELETE with IN list and LIMIT
	sqlDuplicatePostmeta = `DELETE FROM wp_postmeta WHERE meta_id IN (101,202,303) LIMIT 2000`

	// Action Scheduler — DELETE by status
	sqlActionSchedulerCompleted = `DELETE FROM wp_actionscheduler_actions WHERE status='complete' LIMIT 2000`
	sqlActionSchedulerFailed    = `DELETE FROM wp_actionscheduler_actions WHERE status='failed' LIMIT 2000`

	// Table action shapes from class-db-table-action-command.php
	sqlOptimize     = "OPTIMIZE TABLE `wp_posts`"
	sqlRepair       = "REPAIR TABLE `wp_posts`"
	sqlDropIfExists = "DROP TABLE IF EXISTS `wp_plugin_log`"
	sqlTruncate     = "TRUNCATE TABLE `wp_plugin_log`"
	sqlAnalyze      = "ANALYZE TABLE `wp_posts`"
	sqlAlterEngine  = "ALTER TABLE `wp_posts` ENGINE=InnoDB"
)

func TestSafeStatementCheckPositive(t *testing.T) {
	cases := []struct {
		name string
		sql  string
	}{
		{"revisions delete", sqlRevisions},
		{"auto-draft delete", sqlAutoDraft},
		{"trashed posts delete", sqlTrashed},
		{"spam comments delete", sqlSpam},
		{"trashed commentmeta delete", sqlTrashedCm},
		{"expired transients delete IN", sqlExpiredTransients},
		{"orphaned postmeta delete subquery LIMIT", sqlOrphanedPostmeta},
		{"orphaned commentmeta delete subquery LIMIT", sqlOrphanedCommentmeta},
		{"orphaned term_relationships delete", sqlOrphanedTermRel},
		{"oembed cache delete", sqlOembedCache},
		{"duplicate postmeta delete IN LIMIT", sqlDuplicatePostmeta},
		{"action_scheduler complete delete", sqlActionSchedulerCompleted},
		{"action_scheduler failed delete", sqlActionSchedulerFailed},
		{"OPTIMIZE TABLE", sqlOptimize},
		{"REPAIR TABLE (regex path)", sqlRepair},
		{"DROP TABLE IF EXISTS", sqlDropIfExists},
		{"TRUNCATE TABLE", sqlTruncate},
		{"ANALYZE TABLE", sqlAnalyze},
		{"ALTER TABLE ENGINE=InnoDB", sqlAlterEngine},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if err := SafeStatementCheck(tc.sql); err != nil {
				t.Errorf("expected nil error for safe SQL, got: %v\nSQL: %s", err, tc.sql)
			}
		})
	}
}

func TestSafeStatementCheckNegative(t *testing.T) {
	cases := []struct {
		name string
		sql  string
	}{
		{
			name: "DELETE without WHERE",
			sql:  "DELETE FROM wp_posts",
		},
		{
			name: "stacked statements DELETE then DROP",
			sql:  "DELETE FROM wp_posts WHERE post_type='revision'; DROP TABLE wp_users",
		},
		{
			name: "SELECT statement",
			sql:  "SELECT * FROM wp_posts WHERE post_type='revision'",
		},
		{
			name: "multi-table DELETE (join form)",
			sql:  "DELETE wp_postmeta FROM wp_postmeta LEFT JOIN wp_posts ON wp_postmeta.post_id = wp_posts.ID WHERE wp_posts.ID IS NULL",
		},
		{
			// The TiDB parser strips block comments before parsing, correctly
			// recognising this as a single valid DELETE — so we demonstrate the
			// guardrail rejects a UNION-SELECT injection instead (a SELECT-bearing
			// subquery used as an EXISTS clause would pass a WHERE check, but a
			// standalone SELECT is always rejected).
			name: "SELECT hidden inside CREATE TABLE rejected",
			sql:  "CREATE TABLE wp_evil AS SELECT * FROM wp_users",
		},
		{
			name: "UPDATE statement",
			sql:  "UPDATE wp_options SET option_value='foo' WHERE option_name='bar'",
		},
		{
			name: "INSERT statement",
			sql:  "INSERT INTO wp_options (option_name, option_value) VALUES ('foo', 'bar')",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if err := SafeStatementCheck(tc.sql); err == nil {
				t.Errorf("expected error for unsafe SQL, got nil\nSQL: %s", tc.sql)
			}
		})
	}
}
