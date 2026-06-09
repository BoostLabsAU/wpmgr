# Cluster C1_filesystem  (211 findings)

## Codes in this cluster
- WordPress.WP.AlternativeFunctions.unlink_unlink: 50  (E:50 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_mkdir: 38  (E:38 W:0)
- WordPress.WP.AlternativeFunctions.rename_rename: 22  (E:22 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_is_writable: 21  (E:21 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_chmod: 18  (E:18 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_fclose: 17  (E:17 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_fopen: 14  (E:14 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_rmdir: 12  (E:12 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_fwrite: 8  (E:8 W:0)
- PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite: 4  (E:4 W:0)
- WordPress.WP.AlternativeFunctions.file_system_operations_fread: 3  (E:3 W:0)
- PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected: 3  (E:0 W:3)
- WordPress.WP.AlternativeFunctions.file_system_operations_readfile: 1  (E:1 W:0)

## Files in this cluster
- includes/backup/class-restore-runner.php: 21
- includes/backup/class-files-restorer.php: 21
- includes/backup/class-files-archiver.php: 19
- includes/backup/class-task-runner.php: 16
- includes/media/class-media-quarantine.php: 12
- includes/backup/destinations/class-local-destination.php: 11
- includes/commands/class-db-snapshot-command.php: 10
- includes/backup/class-core-files-archiver.php: 9
- includes/backup/class-encrypt-and-upload.php: 8
- includes/support/class-update-checker.php: 8
- includes/support/class-snapshot-manager.php: 7
- includes/cache/class-wp-config-editor.php: 6
- includes/cache/class-purge.php: 6
- includes/support/class-mu-plugin-installer.php: 6
- includes/media/class-disk-writer.php: 6
- includes/support/class-backup-source.php: 5
- assets/wpmgr-advanced-cache.php: 5
- includes/commands/class-restore-command.php: 4
- includes/commands/class-backup-command.php: 4
- includes/cache/class-cache-writer.php: 4
- includes/optimizer/class-asset-cache.php: 4
- includes/class-keystore.php: 4
- includes/cache/class-dropin-installer.php: 3
- includes/backup/class-db-dumper.php: 2
- includes/cache/class-htaccess-manager.php: 2
- includes/cache/class-tally-consumer.php: 2
- includes/commands/class-diagnostics-command.php: 2
- includes/media/class-rename.php: 2
- includes/media/class-htaccess-installer.php: 2

## All findings (file | line | type | code | message)
assets/wpmgr-advanced-cache.php | L202 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
assets/wpmgr-advanced-cache.php | L204 | ERROR | PluginDirectoryWrite | Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file_put_contents(). Detected usage of constant WP_CONTENT_DIR. Use wp_upl
assets/wpmgr-advanced-cache.php | L249 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
assets/wpmgr-advanced-cache.php | L251 | ERROR | PluginDirectoryWrite | Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file_put_contents(). Detected usage of constant WP_CONTENT_DIR. Use wp_upl
assets/wpmgr-advanced-cache.php | L253 | ERROR | file_system_operations_readfile | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: readfile().
includes/backup/class-core-files-archiver.php | L150 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-core-files-archiver.php | L178 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-core-files-archiver.php | L234 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-core-files-archiver.php | L246 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-core-files-archiver.php | L277 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-core-files-archiver.php | L293 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-core-files-archiver.php | L325 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-core-files-archiver.php | L330 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-core-files-archiver.php | L361 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-db-dumper.php | L124 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-db-dumper.php | L170 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-encrypt-and-upload.php | L267 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-encrypt-and-upload.php | L278 | ERROR | file_system_operations_fread | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fread().
includes/backup/class-encrypt-and-upload.php | L353 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-encrypt-and-upload.php | L474 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-encrypt-and-upload.php | L539 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-encrypt-and-upload.php | L571 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-encrypt-and-upload.php | L808 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-encrypt-and-upload.php | L874 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-files-archiver.php | L373 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-files-archiver.php | L392 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L438 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-archiver.php | L512 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-files-archiver.php | L526 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L628 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L701 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-files-archiver.php | L759 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-files-archiver.php | L767 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-files-archiver.php | L795 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L797 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L844 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-files-archiver.php | L864 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-files-archiver.php | L866 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-files-archiver.php | L871 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L873 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-archiver.php | L887 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-files-archiver.php | L897 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-files-archiver.php | L902 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-files-restorer.php | L166 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/backup/class-files-restorer.php | L192 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-restorer.php | L195 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/class-files-restorer.php | L243 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-restorer.php | L375 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L384 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-files-restorer.php | L387 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L389 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L491 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L499 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-files-restorer.php | L503 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L505 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L546 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L550 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-files-restorer.php | L553 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L554 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-files-restorer.php | L822 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-restorer.php | L880 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-restorer.php | L908 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-files-restorer.php | L1019 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-files-restorer.php | L1024 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-restore-runner.php | L489 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L523 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-restore-runner.php | L538 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-restore-runner.php | L573 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-restore-runner.php | L609 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-restore-runner.php | L842 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-restore-runner.php | L913 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L941 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L947 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L956 | WARNING | ABSPATHDetected | Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if storing user data or generated files.
includes/backup/class-restore-runner.php | L1169 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L1173 | WARNING | ABSPATHDetected | Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if storing user data or generated files.
includes/backup/class-restore-runner.php | L1601 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-restore-runner.php | L1607 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-restore-runner.php | L1655 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-restore-runner.php | L1791 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/backup/class-restore-runner.php | L1807 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-restore-runner.php | L2025 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/class-restore-runner.php | L2028 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/class-restore-runner.php | L2295 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-restore-runner.php | L2300 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-task-runner.php | L634 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-task-runner.php | L657 | ERROR | file_system_operations_fwrite | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fwrite().
includes/backup/class-task-runner.php | L661 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-task-runner.php | L956 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/backup/class-task-runner.php | L976 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/backup/class-task-runner.php | L1226 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/backup/class-task-runner.php | L1231 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1345 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1351 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1358 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1365 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1377 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1388 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1395 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/class-task-runner.php | L1399 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/backup/class-task-runner.php | L1441 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/destinations/class-local-destination.php | L83 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/destinations/class-local-destination.php | L86 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/destinations/class-local-destination.php | L87 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/destinations/class-local-destination.php | L110 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/destinations/class-local-destination.php | L157 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/destinations/class-local-destination.php | L188 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/backup/destinations/class-local-destination.php | L218 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/backup/destinations/class-local-destination.php | L224 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/backup/destinations/class-local-destination.php | L225 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/backup/destinations/class-local-destination.php | L226 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/backup/destinations/class-local-destination.php | L253 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/cache/class-cache-writer.php | L434 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/cache/class-cache-writer.php | L440 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-cache-writer.php | L444 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/cache/class-cache-writer.php | L445 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-dropin-installer.php | L164 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-dropin-installer.php | L164 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-dropin-installer.php | L193 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-htaccess-manager.php | L99 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-htaccess-manager.php | L99 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-purge.php | L137 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-purge.php | L203 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/cache/class-purge.php | L247 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-purge.php | L276 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-purge.php | L279 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/cache/class-purge.php | L295 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/cache/class-tally-consumer.php | L96 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/cache/class-tally-consumer.php | L102 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-wp-config-editor.php | L96 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-wp-config-editor.php | L96 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/cache/class-wp-config-editor.php | L254 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/cache/class-wp-config-editor.php | L261 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/cache/class-wp-config-editor.php | L264 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/cache/class-wp-config-editor.php | L265 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/class-keystore.php | L526 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/class-keystore.php | L535 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/class-keystore.php | L553 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/class-keystore.php | L556 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-backup-command.php | L396 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/commands/class-backup-command.php | L399 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-backup-command.php | L538 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-backup-command.php | L540 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-db-snapshot-command.php | L135 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/commands/class-db-snapshot-command.php | L138 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-db-snapshot-command.php | L171 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-db-snapshot-command.php | L382 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/commands/class-db-snapshot-command.php | L387 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-db-snapshot-command.php | L391 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-db-snapshot-command.php | L411 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-db-snapshot-command.php | L426 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-db-snapshot-command.php | L634 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/commands/class-db-snapshot-command.php | L637 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/commands/class-diagnostics-command.php | L650 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-diagnostics-command.php | L652 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-restore-command.php | L371 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/commands/class-restore-command.php | L374 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/commands/class-restore-command.php | L502 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/commands/class-restore-command.php | L504 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/media/class-disk-writer.php | L56 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-disk-writer.php | L69 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/media/class-disk-writer.php | L74 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/media/class-disk-writer.php | L75 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/media/class-disk-writer.php | L80 | ERROR | file_system_operations_chmod | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: chmod().
includes/media/class-disk-writer.php | L102 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/media/class-htaccess-installer.php | L89 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/media/class-htaccess-installer.php | L89 | ERROR | file_system_operations_is_writable | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: is_writable().
includes/media/class-media-quarantine.php | L172 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L221 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L224 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/media/class-media-quarantine.php | L343 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L346 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/media/class-media-quarantine.php | L346 | WARNING | ABSPATHDetected | Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if storing user data or generated files.
includes/media/class-media-quarantine.php | L694 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L697 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L701 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/media/class-media-quarantine.php | L773 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/media/class-media-quarantine.php | L794 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/media/class-media-quarantine.php | L797 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/media/class-rename.php | L53 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/media/class-rename.php | L72 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/optimizer/class-asset-cache.php | L119 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/optimizer/class-asset-cache.php | L125 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/optimizer/class-asset-cache.php | L128 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/optimizer/class-asset-cache.php | L129 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-backup-source.php | L119 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/support/class-backup-source.php | L127 | ERROR | file_system_operations_fread | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fread().
includes/support/class-backup-source.php | L131 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/support/class-backup-source.php | L161 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-backup-source.php | L189 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-mu-plugin-installer.php | L94 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/support/class-mu-plugin-installer.php | L113 | ERROR | PluginDirectoryWrite | Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file_put_contents(). Detected usage of constant WP_CONTENT_DIR. Use wp_upl
includes/support/class-mu-plugin-installer.php | L148 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/support/class-mu-plugin-installer.php | L166 | ERROR | PluginDirectoryWrite | Plugin folders are deleted when upgraded. Do not save data to the plugin folder using file_put_contents(). Detected usage of constant WP_CONTENT_DIR. Use wp_upl
includes/support/class-mu-plugin-installer.php | L192 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-mu-plugin-installer.php | L235 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-snapshot-manager.php | L109 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/support/class-snapshot-manager.php | L116 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/support/class-snapshot-manager.php | L236 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/support/class-snapshot-manager.php | L382 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/support/class-snapshot-manager.php | L406 | ERROR | file_system_operations_mkdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
includes/support/class-snapshot-manager.php | L462 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-snapshot-manager.php | L466 | ERROR | file_system_operations_rmdir | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
includes/support/class-update-checker.php | L818 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-update-checker.php | L832 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-update-checker.php | L841 | ERROR | unlink_unlink | unlink() is discouraged. Use wp_delete_file() to delete a file.
includes/support/class-update-checker.php | L906 | ERROR | rename_rename | rename() is discouraged. Use WP_Filesystem::move() to rename a file.
includes/support/class-update-checker.php | L1093 | ERROR | file_system_operations_fopen | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fopen().
includes/support/class-update-checker.php | L1100 | ERROR | file_system_operations_fread | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fread().
includes/support/class-update-checker.php | L1102 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().
includes/support/class-update-checker.php | L1107 | ERROR | file_system_operations_fclose | File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: fclose().