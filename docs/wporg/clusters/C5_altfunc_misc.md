# Cluster C5_altfunc_misc  (68 findings)

## Codes in this cluster
- WordPress.WP.AlternativeFunctions.parse_url_parse_url: 40  (E:40 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_setopt: 11  (E:11 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_init: 2  (E:2 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_getinfo: 2  (E:2 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_close: 2  (E:2 W:0)
- WordPress.WP.AlternativeFunctions.rand_mt_rand: 2  (E:2 W:0)
- WordPress.WP.AlternativeFunctions.strip_tags_strip_tags: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_exec: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_init: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_exec: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_select: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle: 1  (E:1 W:0)
- WordPress.WP.AlternativeFunctions.curl_curl_multi_close: 1  (E:1 W:0)

## Files in this cluster
- includes/support/class-backup-transport.php: 19
- includes/backup/class-task-runner.php: 9
- includes/cache/class-preload.php: 4
- includes/optimizer/class-rucss-client.php: 4
- includes/optimizer/class-url-helper.php: 4
- includes/cache/class-purge.php: 3
- includes/commands/class-rucss-compute-command.php: 3
- includes/support/class-login-brand.php: 2
- includes/cache/class-cacheability.php: 2
- includes/integrations/class-varnish.php: 2
- includes/optimizer/class-self-host.php: 2
- includes/cache/class-cache-manager.php: 1
- includes/cache/class-cache-writer.php: 1
- includes/cache/class-cache-refresh-cron.php: 1
- includes/support/class-update-checker.php: 1
- includes/integrations/class-integration.php: 1
- includes/phpbu/class-progress-client.php: 1
- includes/optimizer/class-cdn-rewrite.php: 1
- includes/optimizer/class-font.php: 1
- includes/optimizer/class-asset-cache.php: 1
- includes/commands/class-diagnostics-command.php: 1
- includes/commands/class-db-orphan-delete-command.php: 1
- includes/commands/class-db-clean-command.php: 1
- includes/media/class-media-uploader.php: 1
- includes/class-settings.php: 1

## All findings (file | line | type | code | message)
includes/backup/class-task-runner.php | L686 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/backup/class-task-runner.php | L690 | ERROR | curl_curl_init | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L694 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L695 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L696 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L697 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L698 | ERROR | curl_curl_exec | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L699 | ERROR | curl_curl_getinfo | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/backup/class-task-runner.php | L700 | ERROR | curl_curl_close | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/cache/class-cache-manager.php | L577 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-cache-refresh-cron.php | L129 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-cache-writer.php | L438 | ERROR | rand_mt_rand | mt_rand() is discouraged. Use the far less predictable wp_rand() instead.
includes/cache/class-cacheability.php | L239 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-cacheability.php | L249 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-preload.php | L325 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-preload.php | L330 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-preload.php | L349 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-preload.php | L362 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-purge.php | L104 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-purge.php | L116 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/cache/class-purge.php | L159 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/class-settings.php | L196 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-db-clean-command.php | L460 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-db-orphan-delete-command.php | L954 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-diagnostics-command.php | L934 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-rucss-compute-command.php | L260 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-rucss-compute-command.php | L265 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/commands/class-rucss-compute-command.php | L283 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/integrations/class-integration.php | L119 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/integrations/class-varnish.php | L80 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/integrations/class-varnish.php | L148 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/media/class-media-uploader.php | L338 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-asset-cache.php | L123 | ERROR | rand_mt_rand | mt_rand() is discouraged. Use the far less predictable wp_rand() instead.
includes/optimizer/class-cdn-rewrite.php | L102 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-font.php | L205 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-rucss-client.php | L421 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-rucss-client.php | L422 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-rucss-client.php | L426 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-rucss-client.php | L433 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-self-host.php | L106 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-self-host.php | L129 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-url-helper.php | L55 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-url-helper.php | L76 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-url-helper.php | L105 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/optimizer/class-url-helper.php | L109 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/phpbu/class-progress-client.php | L80 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/support/class-backup-transport.php | L192 | ERROR | curl_curl_multi_init | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L217 | ERROR | curl_curl_init | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L222 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L223 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L224 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L225 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L226 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L227 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L230 | ERROR | curl_curl_setopt | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L235 | ERROR | curl_curl_multi_add_handle | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L255 | ERROR | curl_curl_multi_exec | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L261 | ERROR | curl_curl_multi_select | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L265 | ERROR | curl_curl_multi_info_read | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L270 | ERROR | curl_curl_getinfo | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L277 | ERROR | curl_curl_multi_remove_handle | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L278 | ERROR | curl_curl_close | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L288 | ERROR | curl_curl_multi_close | Using cURL functions is highly discouraged. Use wp_remote_get() instead.
includes/support/class-backup-transport.php | L412 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/support/class-backup-transport.php | L462 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/support/class-login-brand.php | L395 | ERROR | strip_tags_strip_tags | strip_tags() is discouraged. Use the more comprehensive wp_strip_all_tags() instead.
includes/support/class-login-brand.php | L418 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.
includes/support/class-update-checker.php | L511 | ERROR | parse_url_parse_url | parse_url() is discouraged because of inconsistency in the output across PHP versions; use wp_parse_url() instead.