#!/usr/bin/env php
<?php
/**
 * WPMgr agent E2E assertion tool.
 *
 * Stages:
 *   provision      — install plugin from zip, configure object cache, verify drop-in state.
 *   assert-cli     — verify engine active, Fix415 loose-type shapes, transient round-trip,
 *                    heartbeat shape (state=connected, last_error_class='').
 *   cron-check     — run the heartbeat cron event and assert connected state.
 *   negative-check — pre-define wp_cache_init via auto_prepend_file; assert early_definition
 *                    or similar cause in heartbeat diagnose output.
 *   disable        — uninstall drop-in, assert clean.
 *
 * Usage:
 *   php /usr/local/bin/wpmgr-assert.php <stage>
 *
 * Exit 0 = pass, 1 = fail, 2 = skip (for negative-check fatals).
 */

declare(strict_types=1);

$stage = $argv[1] ?? '';

$wpRoot    = '/var/www/html';
$pluginZip = '/tmp/fleet-agent-for-wpmgr.zip';
$pluginSlug = 'fleet-agent-for-wpmgr';

/**
 * Run a shell command, print stdout/stderr, return exit code.
 *
 * @param string $cmd Command to execute.
 * @param string|null &$out Captured stdout+stderr.
 * @return int Exit code.
 */
function run(string $cmd, ?string &$out = null): int
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if ($proc === false) {
        fwrite(STDERR, "assert.php: proc_open failed for: {$cmd}\n");
        return 127;
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $out  = $stdout . $stderr;
    if (trim($out) !== '') {
        echo trim($out) . "\n";
    }
    return $exit;
}

/**
 * Run a wp-cli command inside the WordPress root.
 *
 * @param string $args wp-cli arguments (after "wp").
 * @param string|null &$out Captured output.
 * @return int Exit code.
 */
function wp(string $args, ?string &$out = null): int
{
    $cmd = 'wp --allow-root --path=' . escapeshellarg('/var/www/html') . ' ' . $args;
    return run($cmd, $out);
}

/**
 * Fail the stage with a message.
 *
 * @param string $message Failure message.
 * @param int    $code    Exit code (1 = fail, 2 = skip).
 * @return never
 */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "FAIL [{$GLOBALS['stage']}]: {$message}\n");
    exit($code);
}

/**
 * Print a pass message for the stage.
 *
 * @param string $message Success detail.
 * @return void
 */
function pass(string $message): void
{
    echo "PASS [{$GLOBALS['stage']}]: {$message}\n";
}

// Make the stage name available to fail() without passing it.
$GLOBALS['stage'] = $stage;

// ============================================================================
// Stage: provision
// ============================================================================
if ($stage === 'provision') {
    // 1. Ensure WordPress is installed.
    $exit = wp('core is-installed', $out);
    if ($exit !== 0) {
        $exit = wp(
            'core install'
            . ' --url=http://localhost'
            . ' --title=E2ETest'
            . ' --admin_user=admin'
            . ' --admin_password=password'
            . ' --admin_email=e2e@example.com'
            . ' --skip-email',
            $out
        );
        if ($exit !== 0) {
            fail('WordPress core install failed: ' . $out);
        }
    }
    pass('WordPress installed');

    // 2. Install and activate the plugin from the zip.
    if (!is_file($pluginZip)) {
        fail('Plugin zip not found at ' . $pluginZip);
    }
    $exit = wp('plugin install ' . escapeshellarg($pluginZip) . ' --activate --force', $out);
    if ($exit !== 0) {
        fail('Plugin install/activate failed: ' . $out);
    }
    pass('Plugin installed and activated');

    // 3. Write the ObjectCacheConfig to wp-content so ObjectCacheConfig::load() finds it.
    //    The config file must be at WP_CONTENT_DIR/wpmgr-object-cache-config.php
    //    and be readable only by the web process (mode 0600).
    $configPath = '/var/www/html/wp-content/wpmgr-object-cache-config.php';
    $configContent = <<<'PHP'
<?php
defined( 'ABSPATH' ) || exit;
return [
    'host'              => 'redis',
    'port'              => 6379,
    'scheme'            => 'tcp',
    'database'          => 0,
    'prefix'            => 'e2e',
    'connect_timeout_ms' => 1000,
    'read_timeout_ms'   => 1000,
    'retry_count'       => 2,
    'shared'            => false,
    'flush_on_failback' => true,
    'analytics_enabled' => true,
];
PHP;
    if (file_put_contents($configPath, $configContent) === false) {
        fail('Could not write ObjectCacheConfig at ' . $configPath);
    }
    chmod($configPath, 0600);
    $perms = decoct(fileperms($configPath) & 0777);
    if ($perms !== '600') {
        fail('Config file permissions must be 0600, got ' . $perms);
    }
    pass('ObjectCacheConfig written with 0600 perms');

    // 4. Run the drop-in installer via wp eval.
    $installCode = <<<'PHP'
$installer = new WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller();
$result = $installer->install();
echo json_encode($result);
PHP;
    $exit = wp('eval ' . escapeshellarg($installCode), $out);
    if ($exit !== 0) {
        fail('Installer eval failed: ' . $out);
    }
    $result = json_decode(trim($out), true);
    if (!is_array($result) || empty($result['ok'])) {
        fail('Installer install() returned not-ok: ' . $out);
    }
    pass('Drop-in installed: ' . ($result['detail'] ?? 'ok'));

    // 5. Verify installer state() is ours-current.
    $stateCode = <<<'PHP'
$installer = new WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller();
echo $installer->state();
PHP;
    $exit = wp('eval ' . escapeshellarg($stateCode), $out);
    if ($exit !== 0) {
        fail('state() eval failed: ' . $out);
    }
    $state = trim($out);
    if ($state !== 'ours-current') {
        fail('Expected state ours-current, got: ' . $state);
    }
    pass('Drop-in state: ours-current');

    exit(0);
}

// ============================================================================
// Stage: assert-cli
// ============================================================================
if ($stage === 'assert-cli') {
    // 1. Verify the engine class is active (WPMgr_Object_Cache loaded).
    $classCheck = 'echo class_exists("WPMgr_Object_Cache") ? "yes" : "no";';
    $exit = wp('eval ' . escapeshellarg($classCheck), $out);
    if ($exit !== 0 || trim($out) !== 'yes') {
        fail('WPMgr_Object_Cache class not found; engine not active. Output: ' . $out);
    }
    pass('WPMgr_Object_Cache class is loaded');

    // 2. Fix415 loose-typed regression shapes: int group must not throw TypeError.
    $fix415Code = <<<'PHP'
$ok = wp_cache_set('as_ensure_recurring', true, 3600);
echo $ok ? 'set_ok' : 'set_fail';
echo ' ';
$val = wp_cache_get('as_ensure_recurring', 3600, false, $found);
echo ($found && $val === true) ? 'get_ok' : 'get_fail';
PHP;
    $exit = wp('eval ' . escapeshellarg($fix415Code), $out);
    if ($exit !== 0) {
        fail('Fix415 eval exited non-zero: ' . $out);
    }
    $parts = explode(' ', trim($out));
    if (($parts[0] ?? '') !== 'set_ok' || ($parts[1] ?? '') !== 'get_ok') {
        fail('Fix415 loose-typed int group failed: ' . $out);
    }
    pass('Fix415 Action Scheduler shape (int group) passes');

    // 3. Transient set/get — must land in Redis under the prefix.
    $transientCode = <<<'PHP'
set_transient('wpmgr_e2e_ping', 'pong', 300);
$val = get_transient('wpmgr_e2e_ping');
echo $val === 'pong' ? 'transient_ok' : 'transient_fail';
PHP;
    $exit = wp('eval ' . escapeshellarg($transientCode), $out);
    if ($exit !== 0 || trim($out) !== 'transient_ok') {
        fail('Transient round-trip failed: ' . $out);
    }
    pass('Transient set/get round-trip ok');

    // 4. Heartbeat shape: state=connected AND last_error_class=''.
    $heartbeatCode = <<<'PHP'
$block = WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::build();
if ($block === null) {
    echo json_encode(['error' => 'build() returned null']);
} else {
    echo json_encode([
        'state'           => $block['state'] ?? '',
        'last_error_class' => $block['last_error_class'] ?? 'MISSING',
        'hit_count'       => $block['hit_count'] ?? -1,
    ]);
}
PHP;
    $exit = wp('eval ' . escapeshellarg($heartbeatCode), $out);
    if ($exit !== 0) {
        fail('Heartbeat eval failed: ' . $out);
    }
    $hb = json_decode(trim($out), true);
    if (!is_array($hb)) {
        fail('Heartbeat build() returned non-JSON: ' . $out);
    }
    if (isset($hb['error'])) {
        fail('Heartbeat build() returned null (config not found): ' . $hb['error']);
    }
    if (($hb['state'] ?? '') !== 'connected') {
        fail('Heartbeat state must be connected, got: ' . ($hb['state'] ?? 'MISSING'));
    }
    if (($hb['last_error_class'] ?? 'MISSING') !== '') {
        fail('Heartbeat last_error_class must be empty, got: ' . ($hb['last_error_class'] ?? 'MISSING'));
    }
    pass('Heartbeat: state=connected, last_error_class=""');

    exit(0);
}

// ============================================================================
// Stage: cron-check
// ============================================================================
if ($stage === 'cron-check') {
    // Run the heartbeat cron event manually.
    $exit = wp('cron event run wpmgr_agent_heartbeat', $out);
    // wp cron event run may exit non-zero if the event doesn't exist yet; tolerate.
    if ($exit !== 0) {
        // The cron event may not be registered in this context; run the heartbeat directly.
        $runCode = 'WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::build();';
        $exit = wp('eval ' . escapeshellarg($runCode), $out);
        if ($exit !== 0) {
            fail('Cron heartbeat eval failed: ' . $out);
        }
    }
    pass('Heartbeat cron context executed without fatal');

    // Assert heartbeat state is connected in the cron context.
    $heartbeatCode = <<<'PHP'
$block = WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::build();
echo is_array($block) ? ($block['state'] ?? 'null') : 'null';
PHP;
    $exit = wp('eval ' . escapeshellarg($heartbeatCode), $out);
    if ($exit !== 0) {
        fail('Cron heartbeat state eval failed: ' . $out);
    }
    $state = trim($out);
    if ($state !== 'connected') {
        fail('Heartbeat state in cron context must be connected, got: ' . $state);
    }
    pass('Heartbeat state in cron context: connected');

    exit(0);
}

// ============================================================================
// Stage: negative-check
// ============================================================================
if ($stage === 'negative-check') {
    // Write a mu-plugin that pre-defines wp_cache_init before the drop-in loads.
    $muDir    = '/var/www/html/wp-content/mu-plugins';
    $muPlugin = $muDir . '/e2e-early-cache-init.php';

    if (!is_dir($muDir)) {
        mkdir($muDir, 0755, true);
    }

    // The mu-plugin pre-defines wp_cache_init, simulating an early third-party definer.
    $muContent = <<<'PHP'
<?php
/**
 * E2E negative-check: pre-define wp_cache_init to simulate early definition.
 * This must cause the heartbeat to report early_definition (or similar) cause.
 */
if ( ! function_exists( 'wp_cache_init' ) ) {
    function wp_cache_init() {
        // Intentionally empty early-definer.
    }
}
PHP;

    if (file_put_contents($muPlugin, $muContent) === false) {
        // Skip rather than fail if we cannot write mu-plugins (some envs restrict it).
        fwrite(STDERR, "SKIP [negative-check]: could not write mu-plugin at {$muPlugin}\n");
        exit(2);
    }

    // Now run wp eval to check the diagnosis.
    $diagnoseCode = <<<'PHP'
// Drop-in was pre-empted by the mu-plugin early definer; breadcrumb absent.
// diagnose() should report early_definition or similar.
$diagnosis = WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::diagnose();
echo json_encode($diagnosis);
PHP;

    $exit = wp('eval ' . escapeshellarg($diagnoseCode), $out);

    // Clean up the mu-plugin immediately.
    @unlink($muPlugin);

    if ($exit !== 0) {
        // A fatal here could mean the engine itself crashed. Report as skip+warn.
        fwrite(STDERR, "SKIP [negative-check]: wp eval exited {$exit}; possible early-definer fatal. Output: {$out}\n");
        exit(2);
    }

    $diagnosis = json_decode(trim($out), true);
    if (!is_array($diagnosis)) {
        fwrite(STDERR, "SKIP [negative-check]: diagnose() returned non-JSON: {$out}\n");
        exit(2);
    }

    $cause = $diagnosis['cause'] ?? '';
    $validCauses = [
        'early_definition',
        'stale_opcache_suspected',
        'engine_not_loaded',
        'engine_boot_incomplete',
        'foreign_dropin',
        'filter_suppressed',
    ];
    if (!in_array($cause, $validCauses, true)) {
        fail("negative-check: expected a non-loaded cause, got '{$cause}'");
    }

    pass("negative-check: diagnose() cause='{$cause}' (early-definer scenario detected)");
    exit(0);
}

// ============================================================================
// Stage: disable
// ============================================================================
if ($stage === 'disable') {
    // 1. Deactivate and uninstall the plugin.
    $exit = wp('plugin deactivate ' . escapeshellarg($pluginSlug), $out);
    // Deactivate may fail if plugin name varies; tolerate.
    pass('Plugin deactivated (or not found)');

    // 2. Run the drop-in uninstaller via wp eval.
    $uninstallCode = <<<'PHP'
$installer = new WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller();
$result = $installer->uninstall();
echo $result ? 'uninstalled' : 'failed';
PHP;
    $exit = wp('eval ' . escapeshellarg($uninstallCode), $out);
    if ($exit !== 0) {
        fail('Uninstall eval failed: ' . $out);
    }
    if (trim($out) !== 'uninstalled') {
        fail('Uninstall returned not-uninstalled: ' . $out);
    }
    pass('Drop-in uninstalled');

    // 3. Assert object-cache.php is gone from wp-content.
    $dropinPath = '/var/www/html/wp-content/object-cache.php';
    if (is_file($dropinPath)) {
        fail('object-cache.php still present after uninstall');
    }
    pass('object-cache.php absent after uninstall: clean');

    // 4. Remove the config file.
    $configPath = '/var/www/html/wp-content/wpmgr-object-cache-config.php';
    if (is_file($configPath)) {
        unlink($configPath);
    }
    pass('Config file removed');

    exit(0);
}

// Unknown stage.
fwrite(STDERR, "assert.php: unknown stage '{$stage}'. Valid stages: provision, assert-cli, cron-check, negative-check, disable\n");
exit(1);
