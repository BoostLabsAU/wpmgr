# BIN 3 — 137 findings across 11 files


## includes/backup/class-task-runner.php  (54 findings)
  L  181 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L  257 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  288 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  288 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  460 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  577 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  634 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  636 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  654 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L  657 ERROR   file_system_operations_fwrite       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  661 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  686 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  690 ERROR   curl_curl_init                      Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  694 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  695 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  696 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  697 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  698 ERROR   curl_curl_exec                      Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  699 ERROR   curl_curl_getinfo                   Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  700 ERROR   curl_curl_close                     Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  956 ERROR   file_system_operations_fopen        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  976 ERROR   file_system_operations_fclose       File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1055 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1057 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1057 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1057 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->get_row()\n$prepared assigned unsafely at lin
  L 1057 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1119 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $sql
  L 1130 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1130 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1130 ERROR   UnescapedDBParameter                Unescaped parameter $prepared used in $wpdb->query()\n$prepared assigned unsafely at line 
  L 1130 ERROR   NotPrepared                         Use placeholders and $wpdb->prepare(); found $prepared
  L 1209 WARNING error_log_error_log                 error_log() found. Debug code should not normally be used in production.
  L 1226 ERROR   rename_rename                       rename() is discouraged. Use WP_Filesystem::move() to rename a file.
  L 1231 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1239 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1239 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1258 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L 1286 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1286 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1345 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1351 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1358 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1365 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1377 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1388 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1395 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L 1399 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1408 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1408 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1424 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L 1424 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L 1441 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L 1442 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo

## includes/backup/destinations/class-local-destination.php  (12 findings)
  L   83 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   84 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L   86 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L   87 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  110 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  157 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  188 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  218 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  224 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  225 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  226 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  253 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/cache/class-cacheability.php  (2 findings)
  L  239 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  249 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/cache/class-purge.php  (10 findings)
  L   89 WARNING DynamicHooknameFound                Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &qu
  L  104 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  116 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  137 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  159 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  203 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  247 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  276 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  279 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  295 ERROR   file_system_operations_rmdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F

## includes/class-plugin.php  (2 findings)
  L 1137 WARNING Discouraged                         The use of function set_time_limit() is discouraged
  L 1160 WARNING Discouraged                         The use of function set_time_limit() is discouraged

## includes/commands/class-backup-command.php  (25 findings)
  L  350 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  350 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  350 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;SE
  L  356 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  356 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  360 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  372 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  372 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  393 ERROR   ExceptionNotEscaped                 All output should be run through an escaping function (see the Security sections in the Wo
  L  396 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  399 ERROR   file_system_operations_chmod        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  419 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  419 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  420 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;IN
  L  504 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  504 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  538 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  540 ERROR   file_system_operations_is_writable  File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  585 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  585 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  615 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  615 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  690 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  690 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  691 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$table} at &quot;IN

## includes/commands/class-media-clean-command.php  (2 findings)
  L  299 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  299 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se

## includes/media/class-media-uploader.php  (1 findings)
  L  338 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/support/class-backup-transport.php  (19 findings)
  L  192 ERROR   curl_curl_multi_init                Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  217 ERROR   curl_curl_init                      Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  222 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  223 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  224 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  225 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  226 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  227 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  230 ERROR   curl_curl_setopt                    Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  235 ERROR   curl_curl_multi_add_handle          Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  255 ERROR   curl_curl_multi_exec                Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  261 ERROR   curl_curl_multi_select              Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  265 ERROR   curl_curl_multi_info_read           Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  270 ERROR   curl_curl_getinfo                   Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  277 ERROR   curl_curl_multi_remove_handle       Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  278 ERROR   curl_curl_close                     Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  288 ERROR   curl_curl_multi_close               Using cURL functions is highly discouraged. Use wp_remote_get() instead.
  L  412 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use
  L  462 ERROR   parse_url_parse_url                 parse_url() is discouraged because of inconsistency in the output across PHP versions; use

## includes/support/class-mu-plugin-installer.php  (6 findings)
  L   94 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  113 ERROR   PluginDirectoryWrite                Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file
  L  148 ERROR   file_system_operations_mkdir        File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. F
  L  166 ERROR   PluginDirectoryWrite                Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file
  L  192 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.
  L  235 ERROR   unlink_unlink                       unlink() is discouraged. Use wp_delete_file() to delete a file.

## mu-plugin-loader/a-wpmgr-waf.php  (4 findings)
  L  246 WARNING DirectQuery                         Use of a direct database call is discouraged.
  L  246 WARNING NoCaching                           Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_se
  L  246 WARNING UnescapedDBParameter                Unescaped parameter $optionTable used in $wpdb-&gt;get_var()\n$optionTable assigned unsafe
  L  248 WARNING InterpolatedNotPrepared             Use placeholders and $wpdb-&gt;prepare(); found interpolated variable {$optionTable} at &q