# BIN 6 — 137 findings across 13 files


## includes/backup/class-files-archiver.php  (28 findings)
  L  263 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  373 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  392 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  435 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  438 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  439 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  512 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  514 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  526 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  628 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  666 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  701 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  709 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  759 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  761 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  767 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  795 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  797 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  799 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  799 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  844 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  864 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  866 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  871 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  873 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  887 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  897 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  902 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/backup/class-restore-watchdog.php  (17 findings)
  L   69 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L   69 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L   70 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L   96 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L   96 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  103 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  113 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  113 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  120 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  133 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  164 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  164 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  165 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  170 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  180 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  183 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  188 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/cache/class-admin-bar-purge.php  (3 findings)
  L  142 WARNING Recommended                         Processing form data without nonce verification.
  L  142 WARNING Recommended                         Processing form data without nonce verification.
  L  142 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_GET[&#039;url&#039;]

## includes/cache/class-perf-reporter.php  (2 findings)
  L  207 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  207 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/class-keystore.php  (4 findings)
  L  526 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  535 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  553 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  556 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/class-replay-cache.php  (12 findings)
  L   72 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L   77 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L   77 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L   77 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->get_var()\n$sql assigned unsafely at line 72.
  L   77 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  108 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  160 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  181 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  186 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  186 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  186 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->query()\n$sql assigned unsafely at line 181.
  L  186 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql

## includes/class-router.php  (1 findings)
  L  120 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/commands/class-media-sync-command.php  (5 findings)
  L   80 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  130 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  211 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  211 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  211 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql

## includes/commands/class-restore-command.php  (23 findings)
  L  295 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  295 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  296 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  305 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  305 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  315 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  336 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  336 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  365 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  371 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  374 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  395 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  395 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  396 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;IN
  L  477 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  477 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  502 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  504 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  543 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  543 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  572 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  572 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  573 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;IN

## includes/integrations/class-integration.php  (1 findings)
  L  119 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/media/class-stats-renderer.php  (1 findings)
  L  250 ERROR   NonSingularStringLiteralText        The $text parameter must be a single text string literal. Found: $text

## includes/optimizer/class-db-cleanup.php  (33 findings)
  L  427 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  514 ERROR   UnescapedDBParameter                Unescaped parameter $ttSql used in $wpdb->get_col()\n$ttSql assigned unsafely at line 513.
  L  514 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $ttSql
  L  644 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  649 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_results()\n$prepared assigned unsafely at
  L  649 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1186 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->get_results()\n$sql assigned unsafely at line 1176
  L 1186 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1713 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->get_row()\n$sql assigned unsafely at line 1707.
  L 1713 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1740 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1744 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_var()\n$prepared assigned unsafely at lin
  L 1744 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1980 ERROR   UnescapedDBParameter                Unescaped parameter str_replace('`', '', $table) . '`') used in $wpdb->query()
  L 1980 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found str_replace
  L 1980 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $table
  L 2247 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L 2253 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 
  L 2253 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 2350 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 2357 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 2388 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 2405 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->query()\n$sql used without escaping.
  L 2405 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 2439 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 2443 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_col()\n$prepared assigned unsafely at lin
  L 2443 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 2462 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 2466 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_results()\n$prepared assigned unsafely at
  L 2466 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 2482 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 2486 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 
  L 2486 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared

## includes/support/class-snapshot-manager.php  (7 findings)
  L  109 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  116 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  236 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  382 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  406 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  462 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  466 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F