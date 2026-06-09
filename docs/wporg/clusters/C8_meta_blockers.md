# Cluster C8_meta_blockers  (10 findings)

## Codes in this cluster
- trademarked_term: 3  (E:0 W:3)
- plugin_updater_detected: 1  (E:1 W:0)
- update_modification_detected: 1  (E:0 W:1)
- missing_direct_file_access_protection: 1  (E:1 W:0)
- outdated_tested_upto_header: 1  (E:1 W:0)
- stable_tag_mismatch: 1  (E:1 W:0)
- readme_mismatched_header_requires_php: 1  (E:1 W:0)
- unexpected_markdown_file: 1  (E:0 W:1)

## Files in this cluster
- readme.txt: 4
- wpmgr-agent.php: 2
- includes/class-plugin.php: 1
- includes/support/class-update-checker.php: 1
- assets/wpmgr-advanced-cache.php: 1
- NOTICE.md: 1

## All findings (file | line | type | code | message)
NOTICE.md | L0 | WARNING | unexpected_markdown_file | Unexpected markdown file "NOTICE.md" detected in plugin root. Only specific markdown files are expected in production plugins.
assets/wpmgr-advanced-cache.php | L0 | ERROR | missing_direct_file_access_protection | PHP file should prevent direct access. Add a check like: if ( ! defined( 'ABSPATH' ) ) exit;
includes/class-plugin.php | L0 | ERROR | plugin_updater_detected | Plugin Updater detected. These are not permitted in WordPress.org hosted plugins. Detected: site_transient_update_plugins
includes/support/class-update-checker.php | L0 | WARNING | update_modification_detected | Plugin Updater detected. Detected code which may be altering WordPress update routines. Detected: _site_transient_update_plugins
readme.txt | L0 | ERROR | outdated_tested_upto_header | Tested up to: 6.7 < 7.0. The "Tested up to" value in your plugin is not set to the current version of WordPress. This means your plugin will not show up in sear
readme.txt | L0 | ERROR | stable_tag_mismatch | Mismatched Stable Tag: 0.19.1 != 0.31.0. Your Stable Tag is meant to be the stable version of your plugin and it needs to be exactly the same with the Version i
readme.txt | L0 | ERROR | readme_mismatched_header_requires_php | Mismatched Requires PHP: 8.0 != 8.1. "Requires PHP" needs to be exactly the same with that in your main plugin file's header.
readme.txt | L0 | WARNING | trademarked_term | The plugin name includes a restricted term. Your chosen plugin name - "WPMgr Agent" - contains the restricted term "wp" which cannot be used at all in your plug
wpmgr-agent.php | L0 | WARNING | trademarked_term | The plugin name includes a restricted term. Your chosen plugin name - "WPMgr Agent" - contains the restricted term "wp" which cannot be used at all in your plug
wpmgr-agent.php | L0 | WARNING | trademarked_term | The plugin slug includes a restricted term. Your plugin slug - "wpmgr-agent" - contains the restricted term "wp" which cannot be used at all in your plugin slug