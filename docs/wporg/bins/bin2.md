# BIN 2 — 138 findings across 12 files


## includes/backup/class-db-restorer.php  (10 findings)
  L   97 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  106 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  164 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  256 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  331 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  569 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  588 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  588 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  825 ERROR   mysql__mysqli                       Accessing the database directly should be avoided. Please use the $wpdb object and associa
  L  827 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/backup/destinations/class-destination-resolver.php  (1 findings)
  L   69 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/cache/class-cache-manager.php  (4 findings)
  L  378 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  574 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or sim
  L  574 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
  L  577 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/cache/class-preload-queue.php  (66 findings)
  L  224 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  224 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  224 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 221
  L  226 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;IN
  L  273 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  273 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  273 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 269
  L  275 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  285 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  285 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  285 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 269
  L  287 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  305 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  305 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  305 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_row()\n$table assigned unsafely at line 2
  L  306 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  339 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  339 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  339 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 337
  L  341 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  374 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  374 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  374 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 366
  L  376 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  388 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  388 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  388 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 366
  L  390 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  441 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  441 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  441 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 4
  L  443 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  489 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  489 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  489 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 4
  L  491 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  518 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  518 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  518 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_results()\n$table assigned unsafely at li
  L  521 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at         
  L  551 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  551 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  551 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 549
  L  553 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;UP
  L  580 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  580 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  580 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;query()\n$table assigned unsafely at line 578
  L  582 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  634 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  634 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  634 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 6
  L  636 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  663 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  663 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  663 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 6
  L  665 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  691 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  691 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  691 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_var()\n$table assigned unsafely at line 6
  L  693 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  895 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  895 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  902 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  902 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  902 WARNING UnescapedDBParameter                Unescaped parameter $table used in $wpdb-&gt;get_results()\n$table assigned unsafely at li
  L  905 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at         

## includes/commands/class-cache-enable-command.php  (2 findings)
  L  122 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  122 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/commands/class-db-orphan-delete-command.php  (15 findings)
  L  291 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  524 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  524 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  524 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  621 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  621 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  621 WARNING UnescapedDBParameter                Unescaped parameter $escaped used in $wpdb-&gt;query()\n$escaped assigned unsafely at line
  L  621 WARNING SchemaChange                        Attempting a database schema change is discouraged.
  L  621 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $escaped
  L  660 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  660 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  660 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L  954 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  965 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  978 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/commands/class-diagnostics-command.php  (9 findings)
  L  588 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  588 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  601 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  601 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  650 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  652 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  934 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L 1003 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L 1003 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]

## includes/diagnostics/class-size-probe.php  (4 findings)
  L  129 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  450 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  482 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  482 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se

## includes/media/class-media-run-store.php  (3 findings)
  L  214 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  229 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  268 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/optimizer/class-font.php  (1 findings)
  L  205 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/support/class-backup-source.php  (22 findings)
  L  119 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  127 ERROR   file_system_operations_fread        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  131 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  161 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  189 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  347 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  347 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  357 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  357 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  357 ERROR   UnescapedDBParameter                Unescaped parameter str_replace('`', '', $table) . '`' used in $wpdb->get_row()
  L  357 WARNING SchemaChange                        Attempting a database schema change is discouraged.
  L  357 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found str_replace
  L  357 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $table
  L  361 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  361 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  361 ERROR   UnescapedDBParameter                Unescaped parameter str_replace('`', '', $table) . '`' used in $wpdb->get_results()
  L  361 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found str_replace
  L  361 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $table
  L  416 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  416 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  416 ERROR   UnescapedDBParameter                Unescaped parameter $statement used in $wpdb->query()\n$statement assigned unsafely at lin
  L  416 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $statement

## mu-plugin-loader/a-wpmgr-error-trap.php  (1 findings)
  L  114 WARNING error_log_set_error_handler         set_error_handler() found. Debug code should not normally be used in production.