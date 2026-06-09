# Cluster C6_input_nonce  (77 findings)

## Codes in this cluster
- WordPress.Security.ValidatedSanitizedInput.InputNotSanitized: 34  (E:0 W:34)
- WordPress.Security.ValidatedSanitizedInput.MissingUnslash: 32  (E:0 W:32)
- WordPress.Security.NonceVerification.Missing: 6  (E:0 W:6)
- WordPress.Security.NonceVerification.Recommended: 5  (E:0 W:5)

## Files in this cluster
- assets/wpmgr-advanced-cache.php: 18
- includes/cache/class-cache-writer.php: 9
- includes/integrations/class-varnish.php: 8
- includes/class-admin.php: 7
- includes/support/class-activity-log.php: 6
- includes/cache/class-htaccess-manager.php: 4
- includes/cache/class-admin-bar-purge.php: 3
- includes/cache/class-cache-manager.php: 2
- includes/cache/class-nginx-helper.php: 2
- includes/optimizer/class-rucss-client.php: 2
- includes/commands/class-diagnostics-command.php: 2
- includes/media/class-htaccess-installer.php: 2
- includes/cache/class-perf-reporter.php: 2
- includes/support/class-error-monitor.php: 2
- includes/commands/class-metadata-command.php: 2
- includes/commands/class-autologin-command.php: 2
- includes/commands/class-cache-enable-command.php: 2
- includes/commands/class-perf-config-update-command.php: 2

## All findings (file | line | type | code | message)
assets/wpmgr-advanced-cache.php | L55 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_METHOD&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L55 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_METHOD&#039;]
assets/wpmgr-advanced-cache.php | L111 | WARNING | MissingUnslash | $_COOKIE[&#039;wpmgr_logged_in_roles&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L111 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_COOKIE[&#039;wpmgr_logged_in_roles&#039;]
assets/wpmgr-advanced-cache.php | L124 | WARNING | MissingUnslash | $_COOKIE[$wpmgr_inc] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L124 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_COOKIE[$wpmgr_inc]
assets/wpmgr-advanced-cache.php | L137 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_USER_AGENT&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L137 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_USER_AGENT&#039;]
assets/wpmgr-advanced-cache.php | L149 | WARNING | Recommended | Processing form data without nonce verification.
assets/wpmgr-advanced-cache.php | L151 | WARNING | Recommended | Processing form data without nonce verification.
assets/wpmgr-advanced-cache.php | L168 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L168 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
assets/wpmgr-advanced-cache.php | L175 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L175 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
assets/wpmgr-advanced-cache.php | L227 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_IF_MODIFIED_SINCE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L227 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_IF_MODIFIED_SINCE&#039;]
assets/wpmgr-advanced-cache.php | L230 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_PROTOCOL&#039;] not unslashed before sanitization. Use wp_unslash() or similar
assets/wpmgr-advanced-cache.php | L230 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_PROTOCOL&#039;]
includes/cache/class-admin-bar-purge.php | L142 | WARNING | Recommended | Processing form data without nonce verification.
includes/cache/class-admin-bar-purge.php | L142 | WARNING | Recommended | Processing form data without nonce verification.
includes/cache/class-admin-bar-purge.php | L142 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_GET[&#039;url&#039;]
includes/cache/class-cache-manager.php | L574 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-cache-manager.php | L574 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
includes/cache/class-cache-writer.php | L286 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-cache-writer.php | L286 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
includes/cache/class-cache-writer.php | L287 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-cache-writer.php | L287 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
includes/cache/class-cache-writer.php | L288 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_METHOD&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-cache-writer.php | L288 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_METHOD&#039;]
includes/cache/class-cache-writer.php | L289 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_USER_AGENT&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-cache-writer.php | L289 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_USER_AGENT&#039;]
includes/cache/class-cache-writer.php | L323 | WARNING | Recommended | Processing form data without nonce verification.
includes/cache/class-htaccess-manager.php | L158 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-htaccess-manager.php | L158 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/cache/class-htaccess-manager.php | L171 | WARNING | MissingUnslash | $_SERVER[&#039;LSWS_EDITION&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-htaccess-manager.php | L171 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;LSWS_EDITION&#039;]
includes/cache/class-nginx-helper.php | L40 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-nginx-helper.php | L40 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/cache/class-perf-reporter.php | L207 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/cache/class-perf-reporter.php | L207 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/class-admin.php | L419 | WARNING | Missing | Processing form data without nonce verification.
includes/class-admin.php | L419 | WARNING | Missing | Processing form data without nonce verification.
includes/class-admin.php | L419 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_POST[&#039;wpmgr_cp_url&#039;]
includes/class-admin.php | L443 | WARNING | Missing | Processing form data without nonce verification.
includes/class-admin.php | L444 | WARNING | Missing | Processing form data without nonce verification.
includes/class-admin.php | L609 | WARNING | Missing | Processing form data without nonce verification.
includes/class-admin.php | L610 | WARNING | Missing | Processing form data without nonce verification.
includes/commands/class-autologin-command.php | L456 | WARNING | MissingUnslash | $_SERVER[&#039;REMOTE_ADDR&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/commands/class-autologin-command.php | L456 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REMOTE_ADDR&#039;]
includes/commands/class-cache-enable-command.php | L122 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/commands/class-cache-enable-command.php | L122 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/commands/class-diagnostics-command.php | L1003 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/commands/class-diagnostics-command.php | L1003 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/commands/class-metadata-command.php | L262 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/commands/class-metadata-command.php | L262 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/commands/class-perf-config-update-command.php | L161 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/commands/class-perf-config-update-command.php | L161 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/integrations/class-varnish.php | L49 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_X_APPLICATION&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/integrations/class-varnish.php | L49 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_APPLICATION&#039;]
includes/integrations/class-varnish.php | L53 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_VIA&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/integrations/class-varnish.php | L53 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_VIA&#039;]
includes/integrations/class-varnish.php | L142 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_HOST&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/integrations/class-varnish.php | L142 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_HOST&#039;]
includes/integrations/class-varnish.php | L145 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_NAME&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/integrations/class-varnish.php | L145 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_NAME&#039;]
includes/media/class-htaccess-installer.php | L190 | WARNING | MissingUnslash | $_SERVER[&#039;SERVER_SOFTWARE&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/media/class-htaccess-installer.php | L190 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;SERVER_SOFTWARE&#039;]
includes/optimizer/class-rucss-client.php | L506 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/optimizer/class-rucss-client.php | L506 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]
includes/support/class-activity-log.php | L1142 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_X_FORWARDED_FOR&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/support/class-activity-log.php | L1142 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_FORWARDED_FOR&#039;]
includes/support/class-activity-log.php | L1146 | WARNING | MissingUnslash | $_SERVER[&#039;HTTP_X_REAL_IP&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/support/class-activity-log.php | L1146 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;HTTP_X_REAL_IP&#039;]
includes/support/class-activity-log.php | L1149 | WARNING | MissingUnslash | $_SERVER[&#039;REMOTE_ADDR&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/support/class-activity-log.php | L1149 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REMOTE_ADDR&#039;]
includes/support/class-error-monitor.php | L368 | WARNING | MissingUnslash | $_SERVER[&#039;REQUEST_URI&#039;] not unslashed before sanitization. Use wp_unslash() or similar
includes/support/class-error-monitor.php | L368 | WARNING | InputNotSanitized | Detected usage of a non-sanitized input variable: $_SERVER[&#039;REQUEST_URI&#039;]