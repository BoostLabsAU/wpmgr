# Cluster C4_error_log  (102 findings)

## Codes in this cluster
- WordPress.PHP.DevelopmentFunctions.error_log_error_log: 98  (E:0 W:98)
- WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler: 2  (E:0 W:2)
- WordPress.PHP.DevelopmentFunctions.error_log_var_export: 1  (E:0 W:1)
- WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace: 1  (E:0 W:1)

## Files in this cluster
- includes/support/class-update-checker.php: 36
- includes/backup/class-restore-runner.php: 19
- includes/backup/class-watchdog.php: 9
- includes/backup/class-task-runner.php: 6
- includes/backup/class-restore-watchdog.php: 6
- includes/backup/class-files-restorer.php: 4
- includes/backup/class-db-restorer.php: 2
- includes/commands/class-db-orphan-delete-command.php: 2
- includes/commands/class-db-clean-command.php: 2
- includes/support/class-error-monitor.php: 2
- includes/media/class-media-run-store.php: 2
- includes/cache/class-preload.php: 1
- includes/commands/class-search-replace-command.php: 1
- includes/cache/class-dropin-installer.php: 1
- includes/cache/class-cache-manager.php: 1
- includes/class-replay-cache.php: 1
- includes/optimizer/class-rucss-client.php: 1
- includes/commands/class-media-sync-command.php: 1
- includes/class-connector.php: 1
- includes/support/class-update-runner.php: 1
- includes/class-router.php: 1
- includes/optimizer/class-optimizer.php: 1
- mu-plugin-loader/a-wpmgr-error-trap.php: 1

## All findings (file | line | type | code | message)
includes/backup/class-db-restorer.php | L164 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-db-restorer.php | L569 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-files-restorer.php | L823 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-files-restorer.php | L834 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-files-restorer.php | L881 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-files-restorer.php | L892 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L330 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L809 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L845 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L857 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L903 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L914 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L924 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L934 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L948 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L953 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L1157 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L1170 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L1174 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L1731 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L1913 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L2223 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L2233 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L2239 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-runner.php | L2256 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L103 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L120 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L133 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L170 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L180 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-restore-watchdog.php | L188 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L257 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L460 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L577 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L636 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L654 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-task-runner.php | L1209 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L116 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L140 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L165 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L171 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L182 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L228 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L252 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L262 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/backup/class-watchdog.php | L271 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/cache/class-cache-manager.php | L378 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/cache/class-dropin-installer.php | L123 | WARNING | error_log_var_export | var_export() found. Debug code should not normally be used in production.
includes/cache/class-preload.php | L363 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/class-connector.php | L293 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/class-replay-cache.php | L160 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/class-router.php | L120 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-db-clean-command.php | L355 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-db-clean-command.php | L473 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-db-orphan-delete-command.php | L965 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-db-orphan-delete-command.php | L978 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-media-sync-command.php | L130 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/commands/class-search-replace-command.php | L152 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/media/class-media-run-store.php | L214 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/media/class-media-run-store.php | L268 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/optimizer/class-optimizer.php | L211 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/optimizer/class-rucss-client.php | L158 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-error-monitor.php | L220 | WARNING | error_log_set_error_handler | set_error_handler() found. Debug code should not normally be used in production.
includes/support/class-error-monitor.php | L698 | WARNING | error_log_debug_backtrace | debug_backtrace() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L218 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L241 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L255 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L268 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L274 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L310 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L318 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L323 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L329 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L343 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L349 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L358 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L368 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L372 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L378 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L382 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L391 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L402 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L408 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L413 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L420 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L429 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L435 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L452 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L469 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L479 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L489 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L493 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L507 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L513 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L522 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L532 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L538 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L547 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L901 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-checker.php | L910 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
includes/support/class-update-runner.php | L329 | WARNING | error_log_error_log | error_log() found. Debug code should not normally be used in production.
mu-plugin-loader/a-wpmgr-error-trap.php | L114 | WARNING | error_log_set_error_handler | set_error_handler() found. Debug code should not normally be used in production.