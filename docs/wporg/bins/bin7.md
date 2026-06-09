# BIN 7 — 137 findings across 13 files


## includes/backup/class-db-dumper.php  (16 findings)
  L  115 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  124 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  170 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  172 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  174 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  202 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  203 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  204 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  205 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  233 ERROR   mysql_mysqli_report                 Accessing the database directly should be avoided. Please use the $wpdb object and associa
  L  241 ERROR   mysql__mysqli                       Accessing the database directly should be avoided. Please use the $wpdb object and associa
  L  253 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  365 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  370 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  399 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  443 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/backup/class-watchdog.php  (30 findings)
  L   81 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L   81 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L   81 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L   92 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L   92 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  115 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  115 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  116 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  139 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  139 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  140 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  164 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  164 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  165 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  170 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  170 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  171 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  182 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  226 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  226 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  226 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  228 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  237 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  237 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  251 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  251 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  252 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  262 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  266 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  271 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/cache/class-dropin-installer.php  (4 findings)
  L  123 WARNING error_log_var_export                var_export() found. Debug code should not normally be used in production.
  L  164 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  164 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  193 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/cache/class-tally-consumer.php  (2 findings)
  L   96 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  102 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/commands/class-perf-config-update-command.php  (2 findings)
  L  161 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  161 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/commands/class-search-replace-command.php  (3 findings)
  L  152 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  484 ERROR   mysql__mysqli                       Accessing the database directly should be avoided. Please use the $wpdb object and associa
  L  487 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/media/class-media-quarantine.php  (12 findings)
  L  172 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  221 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  224 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  343 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  346 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  346 WARNING ABSPATHDetected                     Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if 
  L  694 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  697 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  701 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  773 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  794 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  797 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/optimizer/class-optimizer.php  (1 findings)
  L  211 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/optimizer/class-rucss-client.php  (7 findings)
  L  158 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  421 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  422 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  426 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  433 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  506 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  506 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]

## includes/optimizer/class-url-helper.php  (4 findings)
  L   55 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L   76 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  105 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  109 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/phpbu/class-progress-client.php  (1 findings)
  L   80 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/support/class-activity-log.php  (30 findings)
  L  815 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  920 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  922 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optTable} at &quot
  L  927 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  927 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  928 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optTable} at &quot
  L  960 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  960 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  960 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  981 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  981 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  981 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  991 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  991 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  993 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  998 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  998 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1000 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L 1026 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1026 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1031 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at         
  L 1089 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1089 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1089 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1142 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_X_FORWARDED_FOR&#039;] not unslashed before sanitization. Use wp_unsla
  L 1142 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_FORWARDED_FOR&#039
  L 1146 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_X_REAL_IP&#039;] not unslashed before sanitization. Use wp_unslash() o
  L 1146 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_REAL_IP&#039;]
  L 1149 WARNING MissingUnslash                      $_SERVER[&#039;REMOTE_ADDR&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L 1149 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REMOTE_ADDR&#039;]

## includes/support/class-error-monitor.php  (25 findings)
  L  220 WARNING error_log_set_error_handler         set_error_handler() found. Debug code should not normally be used in production.
  L  243 WARNING NonPrefixedVariableFound            Global variables defined by a theme/plugin should start with the theme/plugin prefix. Foun
  L  368 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  368 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
  L  373 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  373 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  375 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  384 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  450 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  450 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  450 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  458 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  458 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  460 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  543 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  543 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  545 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  545 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$placeholders} at &
  L  545 WARNING UnfinishedPrepare                   Replacement variables found, but no valid placeholders found in the query.
  L  568 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  568 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  600 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  600 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  605 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at         
  L  698 WARNING error_log_debug_backtrace           debug_backtrace() found. Debug code should not normally be used in production.