# Cluster C7_longtail  (30 findings)

## Codes in this cluster
- Squiz.PHP.DiscouragedFunctions.Discouraged: 23  (E:0 W:23)
- WordPress.WP.I18n.NonSingularStringLiteralText: 2  (E:2 W:0)
- WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound: 2  (E:0 W:2)
- WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound: 1  (E:0 W:1)
- PluginCheck.CodeAnalysis.Heredoc.NotAllowed: 1  (E:1 W:0)
- WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound: 1  (E:0 W:1)

## Files in this cluster
- includes/backup/class-encrypt-and-upload.php: 3
- includes/backup/class-db-restorer.php: 3
- includes/class-plugin.php: 2
- assets/wpmgr-advanced-cache.php: 2
- includes/diagnostics/class-size-probe.php: 2
- includes/webhooks/class-media-modal-injector.php: 1
- includes/media/class-stats-renderer.php: 1
- includes/backup/class-core-files-archiver.php: 1
- includes/backup/class-files-archiver.php: 1
- includes/backup/class-restore-runner.php: 1
- includes/backup/class-db-dumper.php: 1
- includes/backup/class-task-runner.php: 1
- includes/cache/class-purge.php: 1
- includes/cache/class-nginx-helper.php: 1
- includes/commands/class-media-sync-command.php: 1
- includes/commands/class-db-orphan-delete-command.php: 1
- includes/commands/class-db-clean-command.php: 1
- includes/support/class-error-monitor.php: 1
- includes/backup/class-watchdog.php: 1
- includes/backup/class-restore-watchdog.php: 1
- includes/commands/class-autologin-command.php: 1
- includes/media/class-media-run-store.php: 1
- includes/class-auto-optimize-upload.php: 1

## All findings (file | line | type | code | message)
assets/wpmgr-advanced-cache.php | L30 | WARNING | NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$config&quot;.
assets/wpmgr-advanced-cache.php | L211 | WARNING | Discouraged | The use of function ini_set() is discouraged
includes/backup/class-core-files-archiver.php | L147 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-db-dumper.php | L115 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-db-restorer.php | L106 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-db-restorer.php | L256 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-db-restorer.php | L331 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-encrypt-and-upload.php | L197 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-encrypt-and-upload.php | L402 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-encrypt-and-upload.php | L610 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-files-archiver.php | L435 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-restore-runner.php | L195 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-restore-watchdog.php | L183 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-task-runner.php | L181 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/backup/class-watchdog.php | L266 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/cache/class-nginx-helper.php | L65 | ERROR | NotAllowed | Use of heredoc syntax (<<<) is not allowed; use standard strings or inline HTML instead
includes/cache/class-purge.php | L89 | WARNING | DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$hook&quot;.
includes/class-auto-optimize-upload.php | L310 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/class-plugin.php | L1137 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/class-plugin.php | L1160 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/commands/class-autologin-command.php | L389 | WARNING | NonPrefixedHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;wp_login&quot;.
includes/commands/class-db-clean-command.php | L174 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/commands/class-db-orphan-delete-command.php | L291 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/commands/class-media-sync-command.php | L80 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/diagnostics/class-size-probe.php | L129 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/diagnostics/class-size-probe.php | L450 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/media/class-media-run-store.php | L229 | WARNING | Discouraged | The use of function set_time_limit() is discouraged
includes/media/class-stats-renderer.php | L250 | ERROR | NonSingularStringLiteralText | The $text parameter must be a single text string literal. Found: $text
includes/support/class-error-monitor.php | L243 | WARNING | NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$self&quot;.
includes/webhooks/class-media-modal-injector.php | L170 | ERROR | NonSingularStringLiteralText | The $text parameter must be a single text string literal. Found: $text