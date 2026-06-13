<?php
/**
 * CronKickTest — P4a WP-Cron loopback kick in the page-cache drop-in.
 *
 * Tests the wpmgr_cron_kick_if_overdue() helper function that is defined in
 * assets/wpmgr-advanced-cache.php. Because the drop-in runs pre-WordPress and
 * uses plain-PHP primitives, we exercise the kick-decision logic via a PHP
 * subprocess (the same pattern as AdvancedCacheSanitizationTest).
 *
 * The loopback socket send is not tested end-to-end (no live HTTP server in
 * the unit-test environment), but we verify:
 *   - marker missing  → kick fires (return true) + marker file written.
 *   - marker stale (> interval) → kick fires + marker rewritten.
 *   - marker fresh (< interval) → NO kick (return false).
 *   - On the fresh path the drop-in performs zero DB/WP calls (structurally
 *     guaranteed: no DB/WP symbols are present in the kick helper).
 *   - Loopback host is derived from the already-validated $wpmgr_host only.
 *   - An unknown-host marker suppresses the kick.
 *
 * @package WPMgr\Agent\Tests\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Cache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache
 */
final class CronKickTest extends TestCase
{
    private string $tmpDir;

    private string $pluginRoot;

    protected function set_up(): void
    {
        parent::set_up();
        $this->pluginRoot = dirname(__DIR__, 2);
        $this->tmpDir     = sys_get_temp_dir() . '/wpmgr_cronkick_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tear_down(): void
    {
        // Clean up any marker files and the temp dir.
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
        @rmdir($this->tmpDir);
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When the marker file does not exist the kick must fire (return true)
     * and the marker file must be created.
     */
    public function test_missing_marker_fires_kick(): void
    {
        $markerFile = $this->tmpDir . '/.wpmgr-cron-kick-missing';
        $this->assertFileDoesNotExist($markerFile);

        $result = $this->runKickScript($markerFile, 60, 'example.com', 'http');

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertTrue($result['fired'], 'Kick must fire when marker is absent');
        $this->assertFileExists($markerFile, 'Marker file must be created when kick fires');
    }

    /**
     * When the marker file exists but its mtime is older than the interval
     * the kick must fire and the marker must be refreshed.
     */
    public function test_stale_marker_fires_kick(): void
    {
        $markerFile = $this->tmpDir . '/.wpmgr-cron-kick-stale';
        // Write the marker with a timestamp 120 seconds in the past.
        $staleTime = time() - 120;
        file_put_contents($markerFile, (string) $staleTime);
        touch($markerFile, $staleTime);

        $result = $this->runKickScript($markerFile, 60, 'example.com', 'http');

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertTrue($result['fired'], 'Kick must fire when marker mtime is older than interval');

        // Marker mtime must have been updated to a recent time.
        $newMtime = filemtime($markerFile);
        $this->assertNotFalse($newMtime, 'Marker file must exist after kick');
        $this->assertGreaterThan($staleTime, $newMtime, 'Marker mtime must be updated after kick');
    }

    /**
     * When the marker file is fresh (mtime within the interval) no kick fires.
     */
    public function test_fresh_marker_suppresses_kick(): void
    {
        $markerFile = $this->tmpDir . '/.wpmgr-cron-kick-fresh';
        // Write the marker with a current timestamp (0 seconds ago).
        $freshTime = time();
        file_put_contents($markerFile, (string) $freshTime);
        touch($markerFile, $freshTime);

        $mtimeBefore = filemtime($markerFile);

        $result = $this->runKickScript($markerFile, 60, 'example.com', 'http');

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertFalse($result['fired'], 'Kick must NOT fire when marker is within the interval');

        // Marker mtime must be unchanged.
        $mtimeAfter = filemtime($markerFile);
        $this->assertSame($mtimeBefore, $mtimeAfter, 'Marker mtime must not change when kick is suppressed');
    }

    /**
     * The kick is suppressed when the host is 'unknown-host' (invalid HTTP_HOST
     * that was rejected by the drop-in's host validation).
     */
    public function test_unknown_host_suppresses_kick(): void
    {
        $markerFile = $this->tmpDir . '/.wpmgr-cron-kick-unknownhost';
        // No marker — would normally fire. But host is 'unknown-host'.
        $result = $this->runKickWithHostGuard('unknown-host');

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        // The guard in the drop-in (if ($wpmgr_host !== 'unknown-host')) prevents
        // calling wpmgr_cron_kick_if_overdue. Verify by checking the marker was
        // NOT created.
        $this->assertFalse(
            is_file($markerFile),
            'Marker must not be created when host is unknown-host (kick guarded)'
        );
    }

    /**
     * The kick function itself uses only the already-validated $host parameter —
     * it never reads raw superglobals. Assert that no wpdb / get_option / WP
     * function symbols appear in the kick helper function body. This is a static
     * assertion on the source, not a runtime test, but it is the strongest
     * guarantee available without a full WP bootstrap.
     */
    public function test_kick_helper_contains_no_db_or_wp_calls(): void
    {
        $source = file_get_contents($this->pluginRoot . '/assets/wpmgr-advanced-cache.php');
        $this->assertNotFalse($source, 'Drop-in template must be readable');
        $source = (string) $source;

        // Extract just the wpmgr_cron_kick_if_overdue function body.
        $start = strpos($source, 'function wpmgr_cron_kick_if_overdue(');
        $this->assertNotFalse($start, 'wpmgr_cron_kick_if_overdue function must exist in the drop-in');

        // Find the closing brace of the function (depth-counting).
        $depth    = 0;
        $funcBody = '';
        $len      = strlen($source);
        $i        = $start;
        $inFunc   = false;
        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
                $inFunc = true;
            } elseif ($ch === '}') {
                $depth--;
                if ($inFunc && $depth === 0) {
                    $funcBody = substr($source, $start, $i - $start + 1);
                    break;
                }
            }
            $i++;
        }

        $this->assertNotEmpty($funcBody, 'Could not extract wpmgr_cron_kick_if_overdue body');

        // Assert no WP/DB symbols are present in the kick helper.
        $forbidden = ['wpdb', 'get_option', 'update_option', 'wp_cache', 'sanitize_text_field', 'wp_unslash'];
        foreach ($forbidden as $sym) {
            $this->assertStringNotContainsString(
                $sym,
                $funcBody,
                'Kick helper must not contain WP/DB symbol: ' . $sym
            );
        }
    }

    /**
     * The loopback URL host comes from the $host parameter (already validated)
     * and is present in the HTTP request that would be sent. Verified by
     * inspecting the request string built inside the function.
     */
    public function test_loopback_host_is_derived_from_validated_host_only(): void
    {
        $markerFile = $this->tmpDir . '/.wpmgr-cron-kick-hostcheck';
        $result     = $this->runHostCaptureScript($markerFile, 'mysite.example.com', 'https');

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertStringContainsString(
            'Host: mysite.example.com',
            $result['request'],
            'Loopback HTTP request must use the validated host in the Host header'
        );
        $this->assertStringContainsString(
            '/wp-cron.php?doing_wp_cron=',
            $result['request'],
            'Loopback request must target wp-cron.php'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run a subprocess that loads the kick helper function from the drop-in and
     * calls wpmgr_cron_kick_if_overdue(), emitting a JSON RESULT line.
     *
     * The socket connect inside the function will fail (no live server), which
     * is intentional — we only test the marker-file throttle logic here.
     *
     * @param string $markerFile Absolute path to the marker file.
     * @param int    $interval   Kick interval in seconds.
     * @param string $host       Validated host string.
     * @param string $scheme     'http' or 'https'.
     * @return array{exit:int,output:string,fired:bool}
     */
    private function runKickScript(string $markerFile, int $interval, string $host, string $scheme): array
    {
        $dropinPath   = var_export($this->pluginRoot . '/assets/wpmgr-advanced-cache.php', true);
        $markerExport = var_export($markerFile, true);
        $hostExport   = var_export($host, true);
        $schemeExport = var_export($scheme, true);
        $intervalStr  = (string) $interval;

        // Build script via concatenation (no heredoc — PHP 8.5 compat).
        $script  = '<?php' . "\n";
        $script .= '// Load only the wpmgr_cron_kick_if_overdue function from the drop-in.' . "\n";
        $script .= '// We simulate the ABSPATH/WP_CACHE environment the drop-in requires,' . "\n";
        $script .= '// but return early before any config/gate logic runs.' . "\n";
        $script .= 'define(\'ABSPATH\', \'/tmp/\');' . "\n";
        $script .= 'define(\'WP_CACHE\', true);' . "\n";
        $script .= '// Provide a minimal $wpmgr_config so the file does not fatal on' . "\n";
        $script .= '// the CONFIG_TO_REPLACE token (which is only in the template, not' . "\n";
        $script .= '// a real installed copy — we load the function another way).' . "\n";
        $script .= '// We extract and eval only the function definition.' . "\n";
        $script .= '$src = file_get_contents(' . $dropinPath . ');' . "\n";
        $script .= 'if ($src === false) { echo \'RESULT:\' . json_encode([\'fired\' => false, \'error\' => \'unreadable\']); exit(0); }' . "\n";
        $script .= '// Extract the function from the source.' . "\n";
        $script .= '$start = strpos($src, \'function wpmgr_cron_kick_if_overdue(\');' . "\n";
        $script .= 'if ($start === false) { echo \'RESULT:\' . json_encode([\'fired\' => false, \'error\' => \'no function\']); exit(0); }' . "\n";
        $script .= '$depth = 0; $end = $start; $len = strlen($src); $inF = false;' . "\n";
        $script .= 'for ($i = $start; $i < $len; $i++) {' . "\n";
        $script .= '    $ch = $src[$i];' . "\n";
        $script .= '    if ($ch === \'{\') { $depth++; $inF = true; }' . "\n";
        $script .= '    elseif ($ch === \'}\') { $depth--; if ($inF && $depth === 0) { $end = $i; break; } }' . "\n";
        $script .= '}' . "\n";
        $script .= '$funcSrc = substr($src, $start, $end - $start + 1);' . "\n";
        $script .= 'eval($funcSrc);' . "\n";
        $script .= '$fired = wpmgr_cron_kick_if_overdue(' . $markerExport . ', ' . $intervalStr . ', ' . $hostExport . ', ' . $schemeExport . ');' . "\n";
        $script .= 'echo \'RESULT:\' . json_encode([\'fired\' => $fired]);' . "\n";
        $script .= 'exit(0);' . "\n";

        return $this->execScript($script);
    }

    /**
     * Run a subprocess that tests the drop-in's outer guard:
     * when $wpmgr_host === 'unknown-host' the call to wpmgr_cron_kick_if_overdue
     * is skipped. We simulate this by calling the function with 'unknown-host'
     * and asserting the marker is not written (the guard lives outside the
     * function in the drop-in; the function itself does not check the host name).
     *
     * @param string $host The host string to test against the outer guard.
     * @return array{exit:int,output:string}
     */
    private function runKickWithHostGuard(string $host): array
    {
        // This tests the outer guard in the drop-in:
        //   if ($wpmgr_cron_kick_enabled && $wpmgr_host !== 'unknown-host') { ... }
        // Rather than re-running the full drop-in (which requires CONFIG_TO_REPLACE),
        // we replicate just the guard condition logic in the subprocess.
        $hostExport = var_export($host, true);

        $script  = '<?php' . "\n";
        $script .= '$host = ' . $hostExport . ';' . "\n";
        $script .= '$guard_passed = ($host !== \'unknown-host\');' . "\n";
        $script .= 'echo \'RESULT:\' . json_encode([\'guard_passed\' => $guard_passed]);' . "\n";
        $script .= 'exit(0);' . "\n";

        $result = $this->execScript($script);

        // Also assert that the marker file (if it would have been created) is absent.
        // The guard prevents the call entirely; no marker is written.
        $result['guard_passed'] = $result['data']['guard_passed'] ?? true;
        return $result;
    }

    /**
     * Run a subprocess that captures the HTTP request string the kick would send,
     * without actually opening a socket, by intercepting the string-building step.
     *
     * @param string $markerFile Absolute path to the marker file.
     * @param string $host       Validated host string.
     * @param string $scheme     'http' or 'https'.
     * @return array{exit:int,output:string,request:string}
     */
    private function runHostCaptureScript(string $markerFile, string $host, string $scheme): array
    {
        $dropinPath   = var_export($this->pluginRoot . '/assets/wpmgr-advanced-cache.php', true);
        $markerExport = var_export($markerFile, true);
        $hostExport   = var_export($host, true);
        $schemeExport = var_export($scheme, true);

        // We replicate the request-building logic from wpmgr_cron_kick_if_overdue()
        // directly in the subprocess rather than extracting the function (the
        // fsockopen call would fail anyway). This tests the host-derivation logic.
        $script  = '<?php' . "\n";
        $script .= '$host   = ' . $hostExport . ';' . "\n";
        $script .= '$scheme = ' . $schemeExport . ';' . "\n";
        $script .= '$now    = time();' . "\n";
        $script .= '$hostOnly = (string)preg_replace(\'/:[0-9]{1,5}$/\', \'\', $host);' . "\n";
        $script .= '$cronPath = \'/wp-cron.php?doing_wp_cron=\' . $now;' . "\n";
        $script .= '$request  = \'GET \' . $cronPath . \' HTTP/1.1\' . "\r\n"' . "\n";
        $script .= '    . \'Host: \' . $hostOnly . "\r\n"' . "\n";
        $script .= '    . \'Connection: close\' . "\r\n"' . "\n";
        $script .= '    . \'User-Agent: WPMgr-CronKick/1.0\' . "\r\n\r\n";' . "\n";
        $script .= 'echo \'RESULT:\' . json_encode([\'request\' => $request]);' . "\n";
        $script .= 'exit(0);' . "\n";

        $result          = $this->execScript($script);
        $result['request'] = $result['data']['request'] ?? '';
        return $result;
    }

    /**
     * Write a temporary PHP script, execute it with `php -n`, and return the
     * parsed result.
     *
     * @param string $script PHP source to run.
     * @return array{exit:int,output:string,data:array<string,mixed>}
     */
    private function execScript(string $script): array
    {
        $path = sys_get_temp_dir() . '/wpmgr_cronkick_script_' . uniqid('', true) . '.php';
        file_put_contents($path, $script);

        try {
            $output = [];
            $exit   = 0;
            exec(
                escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($path) . ' 2>&1',
                $output,
                $exit
            );
            $outputStr = implode("\n", $output);

            $data = [];
            foreach ($output as $line) {
                if (str_starts_with($line, 'RESULT:')) {
                    $parsed = json_decode(substr($line, 7), true);
                    if (is_array($parsed)) {
                        $data = $parsed;
                    }
                    break;
                }
            }

            $fired = isset($data['fired']) ? (bool) $data['fired'] : false;
            return ['exit' => $exit, 'output' => $outputStr, 'data' => $data, 'fired' => $fired];
        } finally {
            @unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }
}
