# Cluster C3_escape_output  (103 findings)

## Codes in this cluster
- WordPress.Security.EscapeOutput.ExceptionNotEscaped: 93  (E:93 W:0)
- WordPress.Security.EscapeOutput.OutputNotEscaped: 10  (E:10 W:0)

## Files in this cluster
- includes/backup/class-files-restorer.php: 29
- includes/backup/class-encrypt-and-upload.php: 12
- includes/backup/class-db-dumper.php: 11
- includes/backup/class-restore-runner.php: 10
- includes/backup/class-files-archiver.php: 8
- includes/class-admin.php: 8
- includes/backup/class-core-files-archiver.php: 7
- includes/backup/class-db-restorer.php: 4
- includes/commands/class-db-snapshot-command.php: 3
- includes/backup/class-task-runner.php: 2
- includes/cache/class-preload.php: 1
- includes/support/class-login-brand.php: 1
- includes/support/class-login-protection.php: 1
- includes/backup/class-sql-inspector.php: 1
- includes/backup/destinations/class-local-destination.php: 1
- includes/backup/destinations/class-destination-resolver.php: 1
- includes/commands/class-restore-command.php: 1
- includes/commands/class-search-replace-command.php: 1
- includes/commands/class-backup-command.php: 1

## All findings (file | line | type | code | message)
includes/backup/class-core-files-archiver.php | L113 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$absPath'.
includes/backup/class-core-files-archiver.php | L151 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$outDir'.
includes/backup/class-core-files-archiver.php | L180 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$cachePath'.
includes/backup/class-core-files-archiver.php | L200 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$rel'.
includes/backup/class-core-files-archiver.php | L279 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$cachePath'.
includes/backup/class-core-files-archiver.php | L345 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$path'.
includes/backup/class-core-files-archiver.php | L366 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$partPath'.
includes/backup/class-db-dumper.php | L172 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$this'.
includes/backup/class-db-dumper.php | L174 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$e'.
includes/backup/class-db-dumper.php | L202 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$outPath'.
includes/backup/class-db-dumper.php | L203 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$exists'.
includes/backup/class-db-dumper.php | L204 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$readable'.
includes/backup/class-db-dumper.php | L205 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '(string)'.
includes/backup/class-db-dumper.php | L253 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$mysqli'.
includes/backup/class-db-dumper.php | L365 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '"SHOW CREATE TABLE {$table} faile
includes/backup/class-db-dumper.php | L370 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '"SHOW CREATE TABLE {$table} retur
includes/backup/class-db-dumper.php | L399 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '"SELECT from {$table} failed"'.
includes/backup/class-db-dumper.php | L443 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '"SHOW COLUMNS for {$table} failed
includes/backup/class-db-restorer.php | L97 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$sqlGzPath'.
includes/backup/class-db-restorer.php | L588 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$tmpTable'.
includes/backup/class-db-restorer.php | L588 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$mysqli'.
includes/backup/class-db-restorer.php | L827 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$mysqli'.
includes/backup/class-encrypt-and-upload.php | L206 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$scratchDir'.
includes/backup/class-encrypt-and-upload.php | L260 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$i'.
includes/backup/class-encrypt-and-upload.php | L263 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$absPath'.
includes/backup/class-encrypt-and-upload.php | L269 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$absPath'.
includes/backup/class-encrypt-and-upload.php | L463 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$hash'.
includes/backup/class-encrypt-and-upload.php | L467 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$chunkPath'.
includes/backup/class-encrypt-and-upload.php | L470 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$hash'.
includes/backup/class-encrypt-and-upload.php | L519 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$hash'.
includes/backup/class-encrypt-and-upload.php | L523 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$chunkPath'.
includes/backup/class-encrypt-and-upload.php | L533 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$hash'.
includes/backup/class-encrypt-and-upload.php | L632 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$e'.
includes/backup/class-encrypt-and-upload.php | L634 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$e'.
includes/backup/class-files-archiver.php | L263 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$sourceDir'.
includes/backup/class-files-archiver.php | L439 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$outDir'.
includes/backup/class-files-archiver.php | L514 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$cachePath'.
includes/backup/class-files-archiver.php | L666 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$partPath'.
includes/backup/class-files-archiver.php | L709 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$partPath'.
includes/backup/class-files-archiver.php | L761 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$cachePath'.
includes/backup/class-files-archiver.php | L799 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$e'.
includes/backup/class-files-archiver.php | L799 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$e'.
includes/backup/class-files-restorer.php | L163 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L167 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$parent'.
includes/backup/class-files-restorer.php | L174 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$z'.
includes/backup/class-files-restorer.php | L179 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$z'.
includes/backup/class-files-restorer.php | L179 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$rc'.
includes/backup/class-files-restorer.php | L183 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$z'.
includes/backup/class-files-restorer.php | L193 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingDir'.
includes/backup/class-files-restorer.php | L214 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$zipPath'.
includes/backup/class-files-restorer.php | L345 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingDir'.
includes/backup/class-files-restorer.php | L348 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L376 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L376 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$oldFiles'.
includes/backup/class-files-restorer.php | L385 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L390 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingDir'.
includes/backup/class-files-restorer.php | L390 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L450 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingDir'.
includes/backup/class-files-restorer.php | L453 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$targetDir'.
includes/backup/class-files-restorer.php | L485 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingSub'.
includes/backup/class-files-restorer.php | L492 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$subdir'.
includes/backup/class-files-restorer.php | L492 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$liveSub'.
includes/backup/class-files-restorer.php | L500 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$subdir'.
includes/backup/class-files-restorer.php | L500 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$liveSub'.
includes/backup/class-files-restorer.php | L506 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$subdir'.
includes/backup/class-files-restorer.php | L506 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingSub'.
includes/backup/class-files-restorer.php | L530 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingDir'.
includes/backup/class-files-restorer.php | L547 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$liveItem'.
includes/backup/class-files-restorer.php | L551 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$liveItem'.
includes/backup/class-files-restorer.php | L555 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$stagingItem'.
includes/backup/class-files-restorer.php | L573 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$comp'.
includes/backup/class-restore-runner.php | L410 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'self'.
includes/backup/class-restore-runner.php | L411 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'self'.
includes/backup/class-restore-runner.php | L481 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$i'.
includes/backup/class-restore-runner.php | L484 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$logical'.
includes/backup/class-restore-runner.php | L490 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$outDir'.
includes/backup/class-restore-runner.php | L541 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$outPath'.
includes/backup/class-restore-runner.php | L665 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '(string)'.
includes/backup/class-restore-runner.php | L668 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '(string)'.
includes/backup/class-restore-runner.php | L1776 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'substr'.
includes/backup/class-restore-runner.php | L2026 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$dir'.
includes/backup/class-sql-inspector.php | L142 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$dumpPath'.
includes/backup/class-task-runner.php | L1258 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$phase'.
includes/backup/class-task-runner.php | L1442 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$dir'.
includes/backup/destinations/class-destination-resolver.php | L69 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$kind'.
includes/backup/destinations/class-local-destination.php | L84 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$chunksDir'.
includes/cache/class-preload.php | L282 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$code'.
includes/class-admin.php | L198 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L213 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L226 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L237 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L255 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L275 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L344 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/class-admin.php | L357 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$actionUrl'.
includes/commands/class-backup-command.php | L393 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$base'.
includes/commands/class-db-snapshot-command.php | L380 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$base'.
includes/commands/class-db-snapshot-command.php | L383 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$base'.
includes/commands/class-db-snapshot-command.php | L388 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$base'.
includes/commands/class-restore-command.php | L365 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$base'.
includes/commands/class-search-replace-command.php | L487 | ERROR | ExceptionNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$mysqli'.
includes/support/class-login-brand.php | L216 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$safeUrl'.
includes/support/class-login-protection.php | L748 | ERROR | OutputNotEscaped | All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$html'.