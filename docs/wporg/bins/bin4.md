# BIN 4 — 137 findings across 11 files


## assets/wpmgr-advanced-cache.php  (25 findings)
  L   30 WARNING NonPrefixedVariableFound            Global variables defined by a theme/plugin should start with the theme/plugin prefix. Foun
  L   55 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_METHOD&#039;] not unslashed before sanitization. Use wp_unslash() o
  L   55 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_METHOD&#039;]
  L  111 WARNING MissingUnslash                      $_COOKIE[&#039;wpmgr_logged_in_roles&#039;] not unslashed before sanitization. Use wp_unsl
  L  111 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_COOKIE[&#039;wpmgr_logged_in_roles&#03
  L  124 WARNING MissingUnslash                      $_COOKIE[$wpmgr_inc] not unslashed before sanitization. Use wp_unslash() or similar
  L  124 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_COOKIE[$wpmgr_inc]
  L  137 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_USER_AGENT&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  137 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_USER_AGENT&#039;]
  L  149 WARNING Recommended                         Processing form data without nonce verification.
  L  151 WARNING Recommended                         Processing form data without nonce verification.
  L  168 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or sim
  L  168 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
  L  175 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  175 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
  L  202 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  204 ERROR   PluginDirectoryWrite                Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file
  L  211 WARNING Discouraged                         The use of function ini_set() is discouraged
  L  227 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_IF_MODIFIED_SINCE&#039;] not unslashed before sanitization. Use wp_uns
  L  227 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_IF_MODIFIED_SINCE&#0
  L  230 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_PROTOCOL&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  230 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_PROTOCOL&#039;]
  L  249 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  251 ERROR   PluginDirectoryWrite                Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file
  L  253 ERROR   file_system_operations_readfile     File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/backup/class-core-files-archiver.php  (17 findings)
  L  113 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  147 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  150 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  151 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  178 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  180 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  200 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  234 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  246 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  277 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  279 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  293 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  325 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  330 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  345 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  361 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  366 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/backup/class-files-restorer.php  (54 findings)
  L  163 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  166 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  167 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  174 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  179 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  179 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  183 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  192 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  193 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  195 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  214 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  243 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  345 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  348 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  375 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  376 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  376 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  384 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  385 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  387 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  389 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  390 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  390 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  450 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  453 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  485 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  491 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  492 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  492 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  499 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  500 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  500 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  503 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  505 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  506 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  506 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  530 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  546 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  547 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  550 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  551 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  553 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  554 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  555 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  573 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  822 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  823 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  834 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  880 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  881 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  892 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  908 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1019 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1024 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/cache/class-cache-writer.php  (14 findings)
  L  286 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  286 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
  L  287 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or sim
  L  287 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
  L  288 WARNING MissingUnslash                      $_SERVER[&#039;REQUEST_METHOD&#039;] not unslashed before sanitization. Use wp_unslash() o
  L  288 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_METHOD&#039;]
  L  289 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_USER_AGENT&#039;] not unslashed before sanitization. Use wp_unslash() 
  L  289 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_USER_AGENT&#039;]
  L  323 WARNING Recommended                         Processing form data without nonce verification.
  L  434 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  438 ERROR   rand_mt_rand                        mt_rand() is discouraged. Use the far less predictable wp_rand() instead.
  L  440 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  444 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L  445 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/cache/class-nginx-helper.php  (3 findings)
  L   40 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() 
  L   40 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
  L   65 ERROR   NotAllowed                          Use of heredoc syntax (<<<) is not allowed; use standard strings or inline HTML instead

## includes/class-settings.php  (1 findings)
  L  196 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/integrations/class-varnish.php  (10 findings)
  L   49 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_X_APPLICATION&#039;] not unslashed before sanitization. Use wp_unslash
  L   49 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_APPLICATION&#039;]
  L   53 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_VIA&#039;] not unslashed before sanitization. Use wp_unslash() or simi
  L   53 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_VIA&#039;]
  L   80 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  142 WARNING MissingUnslash                      $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or sim
  L  142 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
  L  145 WARNING MissingUnslash                      $_SERVER[&#039;SERVER_NAME&#039;] not unslashed before sanitization. Use wp_unslash() or s
  L  145 WARNING InputNotSanitized                   Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_NAME&#039;]
  L  148 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/media/class-attachment-meta.php  (2 findings)
  L  502 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  502 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se

## includes/media/class-disk-writer.php  (6 findings)
  L   56 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   69 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L   74 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L   75 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L   80 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  102 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## includes/optimizer/class-self-host.php  (2 findings)
  L  106 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  129 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/support/class-login-brand.php  (3 findings)
  L  216 ERROR   OutputNotEscaped                    All output should be run through an escaping function (see the Security sections in the Wo
  L  395 ERROR   strip_tags_strip_tags               strip_tags() is discouraged. Use the more comprehensive wp_strip_all_tags() instead.
  L  418 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use