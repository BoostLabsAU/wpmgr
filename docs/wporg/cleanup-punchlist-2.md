# Plugin Check residual annotations (real `wp plugin check`) — exact lines

Add a `// phpcs:ignore <Code> -- <reason>` covering each line below. Same PHPCS placement rule as before: a STANDALONE `// phpcs:ignore` covers the NEXT line; a TRAILING one covers ITS OWN line. The sniff reports on the line shown; put the ignore so it covers THAT line (combine codes already on a line). These are all justified per the recipe — do NOT change behavior.

REASONS to use:
- PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite: writes to wp-content/{mu-plugins,cache} (a persistent install target outside the plugin folder), not the plugin directory
- PluginCheck.Security.DirectDB.UnescapedDBParameter: value is the output of $wpdb->prepare() / an information_schema-validated identifier; not attacker-controlled
- Squiz.PHP.DiscouragedFunctions.Discouraged: long-running backup/restore loop must not hit max_execution_time (set_time_limit) / required runtime ini tweak; @-guarded
- PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected: restore/quarantine engine intentionally writes under ABSPATH (the live WP tree); relocating would defeat the restore

## includes/cache/class-preload-queue.php
- L226  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L275  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L287  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L307  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L341  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L376  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L390  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L443  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L491  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L520  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L553  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L582  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L636  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L665  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L693  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L904  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/optimizer/class-db-cleanup.php
- L514  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L649  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1186  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1713  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1744  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1980  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L2253  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L2405  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L2443  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L2466  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L2486  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/commands/class-db-table-action-command.php
- L333  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L349  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L367  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L382  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L404  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L441  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L456  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L479  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L515  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L528  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/backup/class-restore-runner.php
- L195  Squiz.PHP.DiscouragedFunctions.Discouraged
- L956  PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
- L1173  PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
- L1835  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1887  PluginCheck.Security.DirectDB.UnescapedDBParameter

## assets/wpmgr-advanced-cache.php
- L217  PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
- L224  Squiz.PHP.DiscouragedFunctions.Discouraged
- L266  PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite

## includes/backup/class-task-runner.php
- L181  Squiz.PHP.DiscouragedFunctions.Discouraged
- L1053  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L1127  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/support/class-backup-source.php
- L357  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L361  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L416  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/class-plugin.php
- L1144  Squiz.PHP.DiscouragedFunctions.Discouraged
- L1167  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/support/class-mu-plugin-installer.php
- L113  PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
- L166  PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite

## includes/class-connector.php
- L248  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L272  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/class-replay-cache.php
- L79  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L190  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/media/class-db-rewriter.php
- L357  PluginCheck.Security.DirectDB.UnescapedDBParameter
- L437  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/class-auto-optimize-upload.php
- L310  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/commands/class-media-sync-command.php
- L80  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/backup/class-files-archiver.php
- L435  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/backup/class-restore-watchdog.php
- L187  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/backup/class-core-files-archiver.php
- L147  Squiz.PHP.DiscouragedFunctions.Discouraged

## includes/media/class-media-quarantine.php
- L346  PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected

## mu-plugin-loader/a-wpmgr-waf.php
- L245  PluginCheck.Security.DirectDB.UnescapedDBParameter

## includes/commands/class-db-orphan-delete-command.php
- L621  PluginCheck.Security.DirectDB.UnescapedDBParameter
