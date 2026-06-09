# Cluster C2_database  (415 findings)

## Codes in this cluster
- WordPress.DB.DirectDatabaseQuery.DirectQuery: 127  (E:0 W:127)
- WordPress.DB.DirectDatabaseQuery.NoCaching: 119  (E:0 W:119)
- WordPress.DB.PreparedSQL.NotPrepared: 55  (E:55 W:0)
- WordPress.DB.PreparedSQL.InterpolatedNotPrepared: 52  (E:0 W:52)
- PluginCheck.Security.DirectDB.UnescapedDBParameter: 52  (E:30 W:22)
- WordPress.DB.RestrictedClasses.mysql__mysqli: 3  (E:3 W:0)
- WordPress.DB.DirectDatabaseQuery.SchemaChange: 2  (E:0 W:2)
- WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare: 2  (E:0 W:2)
- WordPress.DB.RestrictedFunctions.mysql_mysqli_report: 1  (E:1 W:0)
- WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery: 1  (E:1 W:0)
- WordPress.DB.SlowDBQuery.slow_db_query_meta_value: 1  (E:0 W:1)

## Files in this cluster
- includes/cache/class-preload-queue.php: 66
- includes/media/class-media-reference-index.php: 41
- includes/optimizer/class-db-cleanup.php: 33
- includes/support/class-activity-log.php: 24
- includes/commands/class-db-table-action-command.php: 21
- includes/backup/class-task-runner.php: 20
- includes/commands/class-backup-command.php: 20
- includes/support/class-error-monitor.php: 20
- includes/backup/class-watchdog.php: 20
- includes/support/class-login-protection.php: 18
- includes/commands/class-restore-command.php: 18
- includes/support/class-backup-source.php: 17
- includes/backup/class-restore-runner.php: 16
- includes/class-replay-cache.php: 11
- includes/commands/class-db-orphan-delete-command.php: 11
- includes/class-connector.php: 11
- includes/media/class-db-rewriter.php: 11
- includes/backup/class-restore-watchdog.php: 10
- includes/backup/class-encrypt-and-upload.php: 4
- includes/commands/class-diagnostics-command.php: 4
- mu-plugin-loader/a-wpmgr-waf.php: 4
- includes/commands/class-media-sync-command.php: 3
- includes/backup/class-db-dumper.php: 2
- includes/commands/class-db-snapshot-command.php: 2
- includes/diagnostics/class-size-probe.php: 2
- includes/commands/class-media-clean-command.php: 2
- includes/media/class-attachment-meta.php: 2
- includes/backup/class-db-restorer.php: 1
- includes/commands/class-search-replace-command.php: 1

## All findings (file | line | type | code | message)
includes/backup/class-db-dumper.php | L233 | ERROR | mysql_mysqli_report | Accessing the database directly should be avoided. Please use the $wpdb object and associated functions instead. Found: mysqli_report.
includes/backup/class-db-dumper.php | L241 | ERROR | mysql__mysqli | Accessing the database directly should be avoided. Please use the $wpdb object and associated functions instead. Found: \mysqli.
includes/backup/class-db-restorer.php | L825 | ERROR | mysql__mysqli | Accessing the database directly should be avoided. Please use the $wpdb object and associated functions instead. Found: \mysqli.
includes/backup/class-encrypt-and-upload.php | L912 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-encrypt-and-upload.php | L912 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-encrypt-and-upload.php | L935 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-encrypt-and-upload.php | L935 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-runner.php | L1833 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/backup/class-restore-runner.php | L1835 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-runner.php | L1835 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-runner.php | L1835 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_row()\n$prepared assigned unsafely at line 1833.
includes/backup/class-restore-runner.php | L1835 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/backup/class-restore-runner.php | L1875 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/backup/class-restore-runner.php | L1887 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-runner.php | L1887 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-runner.php | L1887 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 1874.
includes/backup/class-restore-runner.php | L1887 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/backup/class-restore-runner.php | L1919 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-runner.php | L1919 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-runner.php | L1952 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-runner.php | L1952 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-runner.php | L2004 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-runner.php | L2004 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-watchdog.php | L69 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-watchdog.php | L69 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-watchdog.php | L70 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT * FROM {$table} WHERE snapshot_id = %s AND restore_id = %s&quot;
includes/backup/class-restore-watchdog.php | L96 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-watchdog.php | L96 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-watchdog.php | L113 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-watchdog.php | L113 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-watchdog.php | L164 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-restore-watchdog.php | L164 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-restore-watchdog.php | L165 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT * FROM {$table} WHERE snapshot_id = %s AND restore_id = %s&quot;
includes/backup/class-task-runner.php | L288 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L288 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1055 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/backup/class-task-runner.php | L1057 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1057 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1057 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_row()\n$prepared assigned unsafely at line 1055.
includes/backup/class-task-runner.php | L1057 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/backup/class-task-runner.php | L1119 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/backup/class-task-runner.php | L1130 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1130 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1130 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 1118.
includes/backup/class-task-runner.php | L1130 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/backup/class-task-runner.php | L1239 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1239 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1286 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1286 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1408 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1408 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-task-runner.php | L1424 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-task-runner.php | L1424 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L81 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L81 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L81 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT * FROM {$table} WHERE snapshot_id = %s&quot;
includes/backup/class-watchdog.php | L92 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L92 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L115 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L115 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L139 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L139 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L164 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L164 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L170 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L170 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L226 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L226 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L226 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT * FROM {$table} WHERE snapshot_id = %s&quot;
includes/backup/class-watchdog.php | L237 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L237 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/backup/class-watchdog.php | L251 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/backup/class-watchdog.php | L251 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L224 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L224 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L224 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 221.
includes/cache/class-preload-queue.php | L226 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;INSERT INTO {$table}\n
includes/cache/class-preload-queue.php | L273 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L273 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L273 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 269.
includes/cache/class-preload-queue.php | L275 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table}\n
includes/cache/class-preload-queue.php | L285 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L285 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L285 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 269.
includes/cache/class-preload-queue.php | L287 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table}\n
includes/cache/class-preload-queue.php | L305 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L305 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L305 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_row()\n$table assigned unsafely at line 269.
includes/cache/class-preload-queue.php | L306 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT * FROM {$table} WHERE lock_token=%s LIMIT 1&quot;
includes/cache/class-preload-queue.php | L339 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L339 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L339 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 337.
includes/cache/class-preload-queue.php | L341 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE id=%d AND lock_token=%s&quot;
includes/cache/class-preload-queue.php | L374 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L374 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L374 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 366.
includes/cache/class-preload-queue.php | L376 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table}\n
includes/cache/class-preload-queue.php | L388 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L388 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L388 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 366.
includes/cache/class-preload-queue.php | L390 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table}\n
includes/cache/class-preload-queue.php | L441 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L441 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L441 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 439.
includes/cache/class-preload-queue.php | L443 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}\n
includes/cache/class-preload-queue.php | L489 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L489 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L489 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 487.
includes/cache/class-preload-queue.php | L491 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}\n
includes/cache/class-preload-queue.php | L518 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L518 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L518 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_results()\n$table assigned unsafely at line 516.
includes/cache/class-preload-queue.php | L521 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at                        FROM {$table}\n
includes/cache/class-preload-queue.php | L551 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L551 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L551 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 549.
includes/cache/class-preload-queue.php | L553 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table}\n
includes/cache/class-preload-queue.php | L580 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L580 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L580 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 578.
includes/cache/class-preload-queue.php | L582 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE group_name=%s AND callback=%s&quot;
includes/cache/class-preload-queue.php | L634 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L634 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L634 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 632.
includes/cache/class-preload-queue.php | L636 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}\n
includes/cache/class-preload-queue.php | L663 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L663 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L663 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 661.
includes/cache/class-preload-queue.php | L665 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}\n
includes/cache/class-preload-queue.php | L691 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L691 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L691 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 689.
includes/cache/class-preload-queue.php | L693 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}\n
includes/cache/class-preload-queue.php | L895 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L895 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L902 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/cache/class-preload-queue.php | L902 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/cache/class-preload-queue.php | L902 | WARNING | UnescapedDBParameter | Unescaped parameter $table used in $wpdb-&gt;get_results()\n$table assigned unsafely at line 891.
includes/cache/class-preload-queue.php | L905 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at                        FROM {$table}\n
includes/class-connector.php | L243 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT 1 FROM {$table} WHERE jti_hash = %s AND expires_at &gt;= %d LIMIT
includes/class-connector.php | L248 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-connector.php | L248 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/class-connector.php | L248 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->get_var()\n$sql assigned unsafely at line 243.
includes/class-connector.php | L248 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/class-connector.php | L270 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE expires_at &lt; %d&quot;
includes/class-connector.php | L272 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-connector.php | L272 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/class-connector.php | L272 | ERROR | UnescapedDBParameter | Unescaped parameter $pruneSql used in $wpdb->query()\n$pruneSql assigned unsafely at line 270.
includes/class-connector.php | L272 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $pruneSql
includes/class-connector.php | L275 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-replay-cache.php | L72 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT 1 FROM {$table} WHERE jti_hash = %s AND expires_at &gt;= %d LIMIT
includes/class-replay-cache.php | L77 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-replay-cache.php | L77 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/class-replay-cache.php | L77 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->get_var()\n$sql assigned unsafely at line 72.
includes/class-replay-cache.php | L77 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/class-replay-cache.php | L108 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-replay-cache.php | L181 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE expires_at &lt; %d&quot;
includes/class-replay-cache.php | L186 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/class-replay-cache.php | L186 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/class-replay-cache.php | L186 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->query()\n$sql assigned unsafely at line 181.
includes/class-replay-cache.php | L186 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/commands/class-backup-command.php | L350 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L350 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L350 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT pid, started_at FROM {$table} WHERE snapshot_id = %s&quot;
includes/commands/class-backup-command.php | L356 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L356 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L360 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L372 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L372 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L419 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L419 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L420 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;INSERT IGNORE INTO {$table} (snapshot_id, kind, phase, sub_state, starte
includes/commands/class-backup-command.php | L504 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L504 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L585 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L585 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L615 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L615 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L690 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-backup-command.php | L690 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-backup-command.php | L691 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;INSERT IGNORE INTO {$table}\n
includes/commands/class-db-orphan-delete-command.php | L524 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-db-orphan-delete-command.php | L524 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-db-orphan-delete-command.php | L524 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/commands/class-db-orphan-delete-command.php | L621 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-db-orphan-delete-command.php | L621 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-db-orphan-delete-command.php | L621 | WARNING | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line 620.
includes/commands/class-db-orphan-delete-command.php | L621 | WARNING | SchemaChange | Attempting a database schema change is discouraged.
includes/commands/class-db-orphan-delete-command.php | L621 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-orphan-delete-command.php | L660 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-db-orphan-delete-command.php | L660 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-db-orphan-delete-command.php | L660 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/commands/class-db-snapshot-command.php | L542 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-db-snapshot-command.php | L542 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-db-table-action-command.php | L277 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/commands/class-db-table-action-command.php | L333 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 332.
includes/commands/class-db-table-action-command.php | L333 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L349 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 332.
includes/commands/class-db-table-action-command.php | L349 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L367 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 366.
includes/commands/class-db-table-action-command.php | L367 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L382 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 366.
includes/commands/class-db-table-action-command.php | L382 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L404 | WARNING | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line 403.
includes/commands/class-db-table-action-command.php | L404 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L441 | WARNING | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line 440.
includes/commands/class-db-table-action-command.php | L441 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L456 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 440.
includes/commands/class-db-table-action-command.php | L456 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L479 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 478.
includes/commands/class-db-table-action-command.php | L479 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L515 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 514.
includes/commands/class-db-table-action-command.php | L515 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-db-table-action-command.php | L528 | ERROR | UnescapedDBParameter | Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 514.
includes/commands/class-db-table-action-command.php | L528 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $escaped
includes/commands/class-diagnostics-command.php | L588 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-diagnostics-command.php | L588 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-diagnostics-command.php | L601 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-diagnostics-command.php | L601 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-media-clean-command.php | L299 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-media-clean-command.php | L299 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-media-sync-command.php | L211 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-media-sync-command.php | L211 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-media-sync-command.php | L211 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/commands/class-restore-command.php | L295 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L295 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L296 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT pid, started_at FROM {$table} WHERE snapshot_id = %s AND restore_
includes/commands/class-restore-command.php | L305 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L305 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L315 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L336 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L336 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L395 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L395 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L396 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;INSERT IGNORE INTO {$table}\n
includes/commands/class-restore-command.php | L477 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L477 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L543 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L543 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L572 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/commands/class-restore-command.php | L572 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/commands/class-restore-command.php | L573 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;INSERT IGNORE INTO {$table}\n
includes/commands/class-search-replace-command.php | L484 | ERROR | mysql__mysqli | Accessing the database directly should be avoided. Please use the $wpdb object and associated functions instead. Found: \mysqli.
includes/diagnostics/class-size-probe.php | L482 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/diagnostics/class-size-probe.php | L482 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-attachment-meta.php | L502 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-attachment-meta.php | L502 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-db-rewriter.php | L358 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-db-rewriter.php | L358 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-db-rewriter.php | L358 | WARNING | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 348.
includes/media/class-db-rewriter.php | L371 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-db-rewriter.php | L371 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-db-rewriter.php | L438 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-db-rewriter.php | L438 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-db-rewriter.php | L438 | WARNING | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 426.
includes/media/class-db-rewriter.php | L451 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-db-rewriter.php | L451 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-db-rewriter.php | L453 | WARNING | slow_db_query_meta_value | Detected usage of meta_value, possible slow query.
includes/media/class-media-reference-index.php | L321 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L321 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L387 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L387 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L442 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L442 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L468 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L468 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L520 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L520 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L547 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L547 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L573 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L573 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L628 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L628 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L669 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L669 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L711 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L711 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L778 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L778 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L785 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$placeholders} at              WHERE pm.meta_key IN ({$placeholders})\n
includes/media/class-media-reference-index.php | L786 | WARNING | UnfinishedPrepare | Replacement variables found, but no valid placeholders found in the query.
includes/media/class-media-reference-index.php | L827 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L827 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L870 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L870 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L945 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L945 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L949 | ERROR | LikeWildcardsInQuery | SQL wildcards for a LIKE query should be passed in through a replacement parameter. Found:  LIKE 'wpmgr\\_%'.
includes/media/class-media-reference-index.php | L974 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L974 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L1005 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L1005 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L1041 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L1041 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L1075 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L1075 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/media/class-media-reference-index.php | L1105 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/media/class-media-reference-index.php | L1105 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/optimizer/class-db-cleanup.php | L427 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L514 | ERROR | UnescapedDBParameter | Unescaped parameter $ttSql used in $wpdb->get_col()\n$ttSql assigned unsafely at line 513.
includes/optimizer/class-db-cleanup.php | L514 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $ttSql
includes/optimizer/class-db-cleanup.php | L644 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L649 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_results()\n$prepared assigned unsafely at line 644.
includes/optimizer/class-db-cleanup.php | L649 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L1186 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->get_results()\n$sql assigned unsafely at line 1176.
includes/optimizer/class-db-cleanup.php | L1186 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L1713 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->get_row()\n$sql assigned unsafely at line 1707.
includes/optimizer/class-db-cleanup.php | L1713 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L1740 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L1744 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_var()\n$prepared assigned unsafely at line 1740.
includes/optimizer/class-db-cleanup.php | L1744 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L1980 | ERROR | UnescapedDBParameter | Unescaped parameter str_replace('`', '', $table) . '`') used in $wpdb->query()
includes/optimizer/class-db-cleanup.php | L1980 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found str_replace
includes/optimizer/class-db-cleanup.php | L1980 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $table
includes/optimizer/class-db-cleanup.php | L2247 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE status = %s&quot;
includes/optimizer/class-db-cleanup.php | L2253 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 2246.
includes/optimizer/class-db-cleanup.php | L2253 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L2350 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L2357 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L2388 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L2405 | ERROR | UnescapedDBParameter | Unescaped parameter $sql used in $wpdb->query()\n$sql used without escaping.
includes/optimizer/class-db-cleanup.php | L2405 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L2439 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L2443 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_col()\n$prepared assigned unsafely at line 2439.
includes/optimizer/class-db-cleanup.php | L2443 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L2462 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L2466 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->get_results()\n$prepared assigned unsafely at line 2462.
includes/optimizer/class-db-cleanup.php | L2466 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/optimizer/class-db-cleanup.php | L2482 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/optimizer/class-db-cleanup.php | L2486 | ERROR | UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 2482.
includes/optimizer/class-db-cleanup.php | L2486 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $prepared
includes/support/class-activity-log.php | L815 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L920 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L922 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optTable} at &quot;UPDATE {$optTable} SET option_value = option_value + 1 WHERE option_n
includes/support/class-activity-log.php | L927 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L927 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L928 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optTable} at &quot;SELECT option_value FROM {$optTable} WHERE option_name = %s&quot;
includes/support/class-activity-log.php | L960 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L960 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L960 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT this_hash FROM {$table} ORDER BY seq DESC LIMIT 1&quot;
includes/support/class-activity-log.php | L981 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L981 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L981 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}&quot;
includes/support/class-activity-log.php | L991 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L991 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L993 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE shipped = 1 ORDER BY id ASC LIMIT %d&quot;
includes/support/class-activity-log.php | L998 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L998 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L1000 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} ORDER BY id ASC LIMIT %d&quot;
includes/support/class-activity-log.php | L1026 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L1026 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L1031 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at                  FROM {$table}\n
includes/support/class-activity-log.php | L1089 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-activity-log.php | L1089 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-activity-log.php | L1089 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/support/class-backup-source.php | L347 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-backup-source.php | L347 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-backup-source.php | L357 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-backup-source.php | L357 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-backup-source.php | L357 | ERROR | UnescapedDBParameter | Unescaped parameter str_replace('`', '', $table) . '`' used in $wpdb->get_row()
includes/support/class-backup-source.php | L357 | WARNING | SchemaChange | Attempting a database schema change is discouraged.
includes/support/class-backup-source.php | L357 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found str_replace
includes/support/class-backup-source.php | L357 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $table
includes/support/class-backup-source.php | L361 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-backup-source.php | L361 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-backup-source.php | L361 | ERROR | UnescapedDBParameter | Unescaped parameter str_replace('`', '', $table) . '`' used in $wpdb->get_results()
includes/support/class-backup-source.php | L361 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found str_replace
includes/support/class-backup-source.php | L361 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $table
includes/support/class-backup-source.php | L416 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-backup-source.php | L416 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-backup-source.php | L416 | ERROR | UnescapedDBParameter | Unescaped parameter $statement used in $wpdb->query()\n$statement assigned unsafely at line 412.
includes/support/class-backup-source.php | L416 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $statement
includes/support/class-error-monitor.php | L373 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L373 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L375 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UPDATE {$table} SET occurrence_count = occurrence_count + 1, last_seen =
includes/support/class-error-monitor.php | L384 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L450 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L450 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L450 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}&quot;
includes/support/class-error-monitor.php | L458 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L458 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L460 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} ORDER BY last_seen ASC LIMIT %d&quot;
includes/support/class-error-monitor.php | L543 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L543 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L545 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE md5 IN ({$placeholders})&quot;
includes/support/class-error-monitor.php | L545 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$placeholders} at &quot;DELETE FROM {$table} WHERE md5 IN ({$placeholders})&quot;
includes/support/class-error-monitor.php | L545 | WARNING | UnfinishedPrepare | Replacement variables found, but no valid placeholders found in the query.
includes/support/class-error-monitor.php | L568 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L568 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L600 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-error-monitor.php | L600 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-error-monitor.php | L605 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at                  FROM {$table}\n
includes/support/class-login-protection.php | L327 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L327 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-login-protection.php | L329 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} WHERE ip = %s AND status = %d&quot;
includes/support/class-login-protection.php | L499 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L499 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-login-protection.php | L502 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at                  FROM {$table}\n
includes/support/class-login-protection.php | L579 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table} WHERE status = %d AND occurred_at &gt; %d 
includes/support/class-login-protection.php | L586 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table} WHERE status = %d AND occurred_at &gt; %d&
includes/support/class-login-protection.php | L592 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L592 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-login-protection.php | L592 | ERROR | NotPrepared | Use placeholders and $wpdb->prepare(); found $sql
includes/support/class-login-protection.php | L800 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L838 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L838 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-login-protection.php | L838 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SELECT COUNT(*) FROM {$table}&quot;
includes/support/class-login-protection.php | L847 | WARNING | DirectQuery | Use of a direct database call is discouraged.
includes/support/class-login-protection.php | L847 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
includes/support/class-login-protection.php | L849 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DELETE FROM {$table} ORDER BY occurred_at ASC LIMIT %d&quot;
mu-plugin-loader/a-wpmgr-waf.php | L246 | WARNING | DirectQuery | Use of a direct database call is discouraged.
mu-plugin-loader/a-wpmgr-waf.php | L246 | WARNING | NoCaching | Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
mu-plugin-loader/a-wpmgr-waf.php | L246 | WARNING | UnescapedDBParameter | Unescaped parameter $optionTable used in $wpdb-&gt;get_var()\n$optionTable assigned unsafely at line 244.
mu-plugin-loader/a-wpmgr-waf.php | L248 | WARNING | InterpolatedNotPrepared | Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optionTable} at &quot;SELECT option_value FROM {$optionTable} WHERE option_name = %s LIM