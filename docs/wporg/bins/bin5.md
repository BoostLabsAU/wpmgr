# BIN 5 — 137 findings across 13 files


## includes/backup/class-encrypt-and-upload.php  (27 findings)
  L  197 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  206 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  260 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  263 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  267 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  269 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  278 ERROR   file_system_operations_fread        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  353 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  402 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  463 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  467 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  470 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  474 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  519 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  523 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  533 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  539 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  571 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  610 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  632 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  634 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  808 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  874 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  912 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  912 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  935 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  935 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se

## includes/cache/class-cache-refresh-cron.php  (1 findings)
  L  129 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/cache/class-htaccess-manager.php  (6 findings)
  L   99 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   99 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  158 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  158 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
  L  171 WARNING MissingUnslash                      $_SERVER[&#039;LSWS_EDITION&#039;] not unslashed before sanitization. Use wp_unslash() or 
  L  171 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;LSWS_EDITION&#039;]

## includes/class-admin.php  (15 findings)
  L  198 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  213 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  226 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  237 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  255 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  275 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  344 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  357 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  419 WARNING Missing                             Processing form data without nonce verification.
  L  419 WARNING Missing                             Processing form data without nonce verification.
  L  419 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_POST[&#039;wpmgr_cp_url&#039;]
  L  443 WARNING Missing                             Processing form data without nonce verification.
  L  444 WARNING Missing                             Processing form data without nonce verification.
  L  609 WARNING Missing                             Processing form data without nonce verification.
  L  610 WARNING Missing                             Processing form data without nonce verification.

## includes/class-connector.php  (12 findings)
  L  243 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  248 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  248 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  248 ERROR   UnescapedDBParameter                Unescaped parameter $sql used in $wpdb->get_var()\n$sql assigned unsafely at line 243.
  L  248 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  270 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  272 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  272 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  272 ERROR   UnescapedDBParameter                Unescaped parameter $pruneSql used in $wpdb->query()\n$pruneSql assigned unsafely at line 
  L  272 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $pruneSql
  L  275 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  293 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/commands/class-db-clean-command.php  (4 findings)
  L  174 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  355 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  460 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  473 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/commands/class-rucss-compute-command.php  (3 findings)
  L  260 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  265 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  283 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/media/class-media-reference-index.php  (41 findings)
  L  321 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  321 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  387 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  387 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  442 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  442 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  468 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  468 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  520 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  520 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  547 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  547 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  573 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  573 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  628 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  628 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  669 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  669 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  711 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  711 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  778 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  778 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  785 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$placeholders} at  
  L  786 WARNING UnfinishedPrepare                   Replacement variables found, but no valid placeholders found in the query.
  L  827 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  827 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  870 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  870 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  945 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  945 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  949 ERROR   LikeWildcardsInQuery                SQL wildcards for a LIKE query should be passed in through a replacement parameter. Found:
  L  974 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  974 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1005 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1005 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1041 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1041 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1075 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1075 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1105 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1105 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se

## includes/media/class-rename.php  (2 findings)
  L   53 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L   72 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.

## includes/optimizer/class-asset-cache.php  (5 findings)
  L  119 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  123 ERROR   rand_mt_rand                        mt_rand() is discouraged. Use the far less predictable wp_rand() instead.
  L  125 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  128 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  129 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/support/class-login-protection.php  (19 findings)
  L  327 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  327 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  329 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE
  L  499 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  499 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  502 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at         
  L  579 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  586 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  592 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  592 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  592 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L  748 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  800 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  838 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  838 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  838 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  847 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  847 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  849 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;DE

## includes/support/class-update-runner.php  (1 findings)
  L  329 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.

## includes/webhooks/class-media-modal-injector.php  (1 findings)
  L  170 ERROR   NonSingularStringLiteralText        The $text parameter must be a single text string literal. Found: $text