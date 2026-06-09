# BIN 1 — 138 findings across 12 files


## includes/backup/class-restore-runner.php  (67 findings)
  L  195 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  330 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  410 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  411 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  481 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  484 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  489 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  490 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  523 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  538 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  541 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  573 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  609 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  665 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  668 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  809 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  842 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  845 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  857 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  903 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  913 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  914 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  924 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  934 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  941 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  947 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  948 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  953 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  956 WARNING ABSPATHDetected                     Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if 
  L 1157 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1169 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1170 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1173 WARNING ABSPATHDetected                     Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if 
  L 1174 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1601 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1607 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1655 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1731 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1776 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L 1791 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1807 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1833 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1835 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1835 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1835 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_row()\n$prepared assigned unsafely at lin
  L 1835 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1875 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1887 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1887 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1887 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 
  L 1887 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1913 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1919 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1919 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1952 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1952 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 2004 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 2004 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 2025 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 2026 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L 2028 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 2223 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 2233 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 2239 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 2256 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 2295 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 2300 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/backup/class-sql-inspector.php  (1 findings)
  L  142 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/cache/class-preload.php  (6 findings)
  L  282 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  325 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  330 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  349 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  362 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  363 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/cache/class-wp-config-editor.php  (6 findings)
  L   96 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   96 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  254 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  261 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  264 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  265 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/class-auto-optimize-upload.php  (1 findings)
  L  310 WARNING Discouraged                         The use of function set_time_limit() is discouraged

## includes/commands/class-autologin-command.php  (3 findings)
  L  389 WARNING NonPrefixedHooknameFound            Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &qu
  L  456 WARNING MissingUnslash                      $_SERVER[&#039;REMOTE_ADDR&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  456 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REMOTE_ADDR&#039;]

## includes/commands/class-db-snapshot-command.php  (15 findings)
  L  135 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  138 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  171 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  380 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  382 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  383 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  387 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  388 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  391 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  411 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  426 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  542 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  542 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  634 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  637 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/commands/class-db-table-action-command.php  (21 findings)
  L  277 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L  333 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 33
  L  333 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  349 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 33
  L  349 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  367 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 36
  L  367 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  382 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 36
  L  382 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  404 WARNING UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line
  L  404 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  441 WARNING UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line
  L  441 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  456 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 44
  L  456 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  479 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 47
  L  479 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  515 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 51
  L  515 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  528 ERROR   UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb->query()\n$escaped assigned unsafely at line 51
  L  528 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped

## includes/commands/class-metadata-command.php  (2 findings)
  L  262 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  262 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/media/class-db-rewriter.php  (11 findings)
  L  358 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  358 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  358 WARNING UnescapedDBParameter                Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 3
  L  371 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  371 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  438 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  438 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  438 WARNING UnescapedDBParameter                Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 4
  L  451 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  451 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  453 WARNING slow_db_query_meta_value            Detected usage of meta_value, possible slow query.

## includes/media/class-htaccess-installer.php  (4 findings)
  L   89 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   89 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  190 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  190 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/optimizer/class-cdn-rewrite.php  (1 findings)
  L  102 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use