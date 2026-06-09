# Plugin Check residual cleanup punch-list (54 findings, 17 files)

These findings remain because a phpcs:ignore was placed on the WRONG line of a multi-line statement (a TRAILING `// phpcs:ignore` only suppresses ITS OWN line) OR no annotation was added. 
FIX RULE: for each line below, ensure the violation line is suppressed. Place a STANDALONE `// phpcs:ignore <Code> -- <reason>` on the line DIRECTLY ABOVE the reported line, OR a trailing `// phpcs:ignore <Code> -- <reason>` on the EXACT reported line. If a stale/misplaced ignore already exists for that statement one line too high, MOVE it to the correct line (do not leave a duplicate). For InterpolatedNotPrepared/NotPrepared inside a $wpdb->prepare(...) the {$table} interpolation still trips the sniff even though values are bound -> add the ignore on the SQL-string line. Do NOT change any behavior; these are all justified ignores.

## assets/wpmgr-advanced-cache.php
- L118  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L118  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L132  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L132  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L146  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L146  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L241  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L241  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed

## includes/integrations/class-varnish.php
- L50  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L50  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L145  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L145  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L149  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed
- L149  WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    reason: value is filtered/validated downstream (regex allowlist / IP validate / string-compare); not stored or echoed

## includes/support/class-error-monitor.php
- L376  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L464  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L550  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L550  WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    reason: placeholders built in a dynamic IN() list via array_fill/argument spread
- L612  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders

## includes/support/class-activity-log.php
- L924  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L931  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L999  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L1007  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L1039  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders

## includes/media/class-db-rewriter.php
- L358  WordPress.DB.DirectDatabaseQuery.DirectQuery
    reason: direct query on plugin-owned table; no core $wpdb helper exists
- L358  WordPress.DB.DirectDatabaseQuery.NoCaching
    reason: correctness requires a live read on the plugin-owned table
- L439  WordPress.DB.DirectDatabaseQuery.DirectQuery
    reason: direct query on plugin-owned table; no core $wpdb helper exists
- L439  WordPress.DB.DirectDatabaseQuery.NoCaching
    reason: correctness requires a live read on the plugin-owned table
- L455  WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    reason: bounded migration/rewrite batch, not a request-path query

## includes/media/class-media-reference-index.php
- L321  WordPress.DB.DirectDatabaseQuery.DirectQuery
    reason: direct query on plugin-owned table; no core $wpdb helper exists
- L321  WordPress.DB.DirectDatabaseQuery.NoCaching
    reason: correctness requires a live read on the plugin-owned table
- L387  WordPress.DB.DirectDatabaseQuery.DirectQuery
    reason: direct query on plugin-owned table; no core $wpdb helper exists
- L387  WordPress.DB.DirectDatabaseQuery.NoCaching
    reason: correctness requires a live read on the plugin-owned table
- L959  WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
    reason: static literal LIKE pattern, no bound value

## includes/commands/class-backup-command.php
- L351  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L422  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L695  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders

## includes/backup/class-db-dumper.php
- L172  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output
- L174  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output
- L205  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output

## includes/backup/class-encrypt-and-upload.php
- L260  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output
- L263  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output
- L634  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output

## includes/commands/class-restore-command.php
- L401  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders
- L581  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders

## includes/commands/class-db-snapshot-command.php
- L542  WordPress.DB.DirectDatabaseQuery.DirectQuery
    reason: direct query on plugin-owned table; no core $wpdb helper exists
- L542  WordPress.DB.DirectDatabaseQuery.NoCaching
    reason: correctness requires a live read on the plugin-owned table

## mu-plugin-loader/a-wpmgr-waf.php
- L248  WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    reason: interpolated identifier is $wpdb->prefix + class constant (trusted); values are bound via placeholders

## includes/commands/class-media-sync-command.php
- L213  WordPress.DB.PreparedSQL.NotPrepared
    reason: identifier validated against information_schema / prefix+constant; values bound via placeholders

## includes/backup/class-restore-runner.php
- L1875  WordPress.DB.PreparedSQL.NotPrepared
    reason: identifier validated against information_schema / prefix+constant; values bound via placeholders

## includes/backup/class-files-archiver.php
- L799  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output

## includes/backup/class-files-restorer.php
- L214  WordPress.Security.EscapeOutput.ExceptionNotEscaped
    reason: thrown exception; message goes to server log/SSE, not browser output

## includes/backup/class-task-runner.php
- L1116  WordPress.DB.PreparedSQL.NotPrepared
    reason: identifier validated against information_schema / prefix+constant; values bound via placeholders
