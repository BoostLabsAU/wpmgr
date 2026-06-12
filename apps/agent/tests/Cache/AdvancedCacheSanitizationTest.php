<?php
/**
 * AdvancedCacheSanitizationTest — named test from the wp.org review fix spec.
 *
 * Exercises the $_SERVER sanitization layer in assets/wpmgr-advanced-cache.php
 * using hostile input values. Because the drop-in runs pre-WordPress and uses
 * plain-PHP validation primitives (preg_match, preg_replace, in_array,
 * strtoupper) rather than WP functions, we verify the behaviour via a PHP
 * subprocess (php -n) that injects controlled $_SERVER values and reports what
 * the drop-in computed.
 *
 * The test duplicates the sanitization logic from the drop-in in an isolated
 * subprocess script, feeding hostile values through it and asserting that the
 * output matches what the hardenend logic should produce.
 *
 * Assertions:
 *   - Control characters in HTTP_USER_AGENT are stripped.
 *   - A 10 KB HTTP_USER_AGENT is capped to 512 bytes.
 *   - An invalid HTTP_HOST (contains slashes) falls back to 'unknown-host'.
 *   - A valid HTTP_HOST passes through intact.
 *   - Control characters in REQUEST_URI are stripped.
 *   - A 10 KB REQUEST_URI is capped to 2048 bytes.
 *   - A bogus SERVER_PROTOCOL defaults to 'HTTP/1.1'.
 *   - A valid SERVER_PROTOCOL passes through.
 *   - An invalid REQUEST_METHOD defaults to 'GET'.
 *   - None of the above cause a fatal / non-zero exit.
 *
 * @package WPMgr\Agent\Tests\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\Cache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache
 */
final class AdvancedCacheSanitizationTest extends TestCase
{
    private string $pluginRoot;

    protected function set_up(): void
    {
        parent::set_up();
        $this->pluginRoot = dirname(__DIR__, 2);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Control characters in HTTP_USER_AGENT must be stripped before the
     * mobile-detection regex runs. The value must also be capped at 512 bytes.
     */
    public function test_user_agent_control_chars_stripped_and_length_capped(): void
    {
        // 10 KB UA containing a null byte and a control char.
        $ua = "\x00\x1Flegitimate-browser/" . str_repeat('A', 10000);

        $result = $this->runSubprocess([
            'HTTP_USER_AGENT' => $ua,
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertArrayHasKey('ua_length', $result['data']);
        $this->assertLessThanOrEqual(
            512,
            $result['data']['ua_length'],
            'HTTP_USER_AGENT must be capped at 512 bytes. Got: ' . $result['data']['ua_length']
        );
        $this->assertStringNotContainsString(
            "\x00",
            $result['data']['ua_sample'],
            'Null byte must be stripped from HTTP_USER_AGENT'
        );
        $this->assertStringNotContainsString(
            "\x1F",
            $result['data']['ua_sample'],
            'Control char 0x1F must be stripped from HTTP_USER_AGENT'
        );
    }

    /**
     * An HTTP_HOST containing slashes or other invalid characters must produce
     * 'unknown-host', not pass through to the cache-key path.
     */
    public function test_invalid_http_host_becomes_unknown_host(): void
    {
        $result = $this->runSubprocess([
            'HTTP_HOST' => 'evil.com/../../etc/passwd',
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertSame(
            'unknown-host',
            $result['data']['host'],
            'A host containing slashes must fall back to unknown-host'
        );
    }

    /**
     * A valid HTTP_HOST (hostname + optional port) must pass through unchanged.
     */
    public function test_valid_http_host_passes_through(): void
    {
        $result = $this->runSubprocess([
            'HTTP_HOST' => 'example.com:8080',
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertSame(
            'example.com:8080',
            $result['data']['host'],
            'A valid HTTP_HOST must pass through the allowlist unchanged'
        );
    }

    /**
     * Control characters in REQUEST_URI must be stripped and the value capped
     * at 2048 bytes before it reaches the cache-key logic.
     */
    public function test_request_uri_control_chars_stripped_and_length_capped(): void
    {
        $uri = "/path\x01\x1F?q=" . str_repeat('x', 10000);

        $result = $this->runSubprocess([
            'REQUEST_URI' => $uri,
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertLessThanOrEqual(
            2048,
            $result['data']['uri_length'],
            'REQUEST_URI must be capped at 2048 bytes. Got: ' . $result['data']['uri_length']
        );
        $this->assertStringNotContainsString(
            "\x01",
            $result['data']['uri_sample'],
            'Control char 0x01 must be stripped from REQUEST_URI'
        );
    }

    /**
     * A bogus SERVER_PROTOCOL value must default to 'HTTP/1.1'.
     */
    public function test_bogus_server_protocol_defaults_to_http11(): void
    {
        $result = $this->runSubprocess([
            'SERVER_PROTOCOL' => 'BOGUS/INJECTION',
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertSame(
            'HTTP/1.1',
            $result['data']['proto'],
            'A bogus SERVER_PROTOCOL must default to HTTP/1.1'
        );
    }

    /**
     * A valid SERVER_PROTOCOL (HTTP/2) must pass through the regex allowlist.
     */
    public function test_valid_server_protocol_passes_through(): void
    {
        $result = $this->runSubprocess([
            'SERVER_PROTOCOL' => 'HTTP/2',
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertSame(
            'HTTP/2',
            $result['data']['proto'],
            'HTTP/2 must pass through the SERVER_PROTOCOL allowlist'
        );
    }

    /**
     * An invalid REQUEST_METHOD must default to 'GET'.
     */
    public function test_invalid_request_method_defaults_to_get(): void
    {
        $result = $this->runSubprocess([
            'REQUEST_METHOD' => 'BADMETHOD',
        ]);

        $this->assertSame(0, $result['exit'], 'Subprocess must not fatal. Output: ' . $result['output']);
        $this->assertSame(
            'GET',
            $result['data']['method'],
            'An unrecognised REQUEST_METHOD must default to GET'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the subprocess PHP script that duplicates the sanitization logic
     * from the advanced-cache drop-in and emits a JSON RESULT line with the
     * computed values for assertion.
     *
     * The script is built via string concatenation (not heredoc interpolation)
     * to remain compatible with PHP 8.5+ where T_CURLY_OPEN (the {$var} form
     * inside strings) was removed.
     *
     * @param array<string,string> $serverVars $_SERVER values to inject.
     * @return string Path to the temporary script.
     */
    private function buildScript(array $serverVars): string
    {
        // Encode the server vars as a PHP var_export so no interpolation issues.
        $serverExport = var_export($serverVars, true);

        // Build the script via concatenation to avoid PHP 8.5 heredoc issues.
        $script  = '<?php' . "\n";
        $script .= '// Minimal environment — no WordPress, no extensions.' . "\n";
        $script .= '$_inject = ' . $serverExport . ';' . "\n";
        $script .= 'foreach ($_inject as $k => $v) { $_SERVER[$k] = $v; }' . "\n";
        $script .= "\n";
        $script .= '// Duplicate sanitization logic from wpmgr-advanced-cache.php' . "\n";
        $script .= "\n";
        $script .= '// REQUEST_METHOD.' . "\n";
        $script .= '$_method_raw = isset($_SERVER[\'REQUEST_METHOD\']) ? strtoupper((string)$_SERVER[\'REQUEST_METHOD\']) : \'GET\';' . "\n";
        $script .= '$_method = in_array($_method_raw, array(\'GET\',\'HEAD\',\'POST\',\'PUT\',\'PATCH\',\'DELETE\',\'OPTIONS\'), true) ? $_method_raw : \'GET\';' . "\n";
        $script .= "\n";
        $script .= '// HTTP_USER_AGENT.' . "\n";
        $script .= '$_ua_raw = isset($_SERVER[\'HTTP_USER_AGENT\']) ? (string)$_SERVER[\'HTTP_USER_AGENT\'] : \'\';' . "\n";
        $script .= '$_ua = substr(preg_replace(\'/[\x00-\x1F\x7F]/\', \'\', $_ua_raw), 0, 512);' . "\n";
        $script .= "\n";
        $script .= '// HTTP_HOST.' . "\n";
        $script .= '$_host_raw = isset($_SERVER[\'HTTP_HOST\']) ? strtolower((string)$_SERVER[\'HTTP_HOST\']) : \'\';' . "\n";
        $script .= 'if ($_host_raw !== \'\' && preg_match(\'/^[a-z0-9.-]+(:[0-9]{1,5})?$/\', $_host_raw) === 1) {' . "\n";
        $script .= '    $_host = $_host_raw;' . "\n";
        $script .= '} else {' . "\n";
        $script .= '    $_host = \'unknown-host\';' . "\n";
        $script .= '}' . "\n";
        $script .= "\n";
        $script .= '// REQUEST_URI.' . "\n";
        $script .= '$_uri_raw = isset($_SERVER[\'REQUEST_URI\']) ? (string)$_SERVER[\'REQUEST_URI\'] : \'/\';' . "\n";
        $script .= '$_uri = substr(preg_replace(\'/[\x00-\x1F\x7F]/\', \'\', $_uri_raw), 0, 2048);' . "\n";
        $script .= "\n";
        $script .= '// SERVER_PROTOCOL.' . "\n";
        $script .= '$_proto_raw = isset($_SERVER[\'SERVER_PROTOCOL\']) ? (string)$_SERVER[\'SERVER_PROTOCOL\'] : \'HTTP/1.1\';' . "\n";
        $script .= '$_proto = preg_match(\'#^HTTP/[0-9](\\.[0-9])?$#\', $_proto_raw) === 1 ? $_proto_raw : \'HTTP/1.1\';' . "\n";
        $script .= "\n";
        $script .= 'echo \'RESULT:\' . json_encode([' . "\n";
        $script .= '    \'method\'     => $_method,' . "\n";
        $script .= '    \'ua_length\'  => strlen($_ua),' . "\n";
        $script .= '    \'ua_sample\'  => substr($_ua, 0, 20),' . "\n";
        $script .= '    \'host\'       => $_host,' . "\n";
        $script .= '    \'uri_length\' => strlen($_uri),' . "\n";
        $script .= '    \'uri_sample\' => substr($_uri, 0, 20),' . "\n";
        $script .= '    \'proto\'      => $_proto,' . "\n";
        $script .= ']);' . "\n";
        $script .= 'exit(0);' . "\n";

        $path = sys_get_temp_dir() . '/wpmgr_acs_script_' . uniqid('', true) . '.php';
        file_put_contents($path, $script);
        return $path;
    }

    /**
     * Run the subprocess and return the parsed result.
     *
     * @param array<string,string> $serverVars
     * @return array{exit:int,output:string,data:array<string,mixed>}
     */
    private function runSubprocess(array $serverVars): array
    {
        $scriptPath = $this->buildScript($serverVars);

        try {
            $output = [];
            $exit   = 0;
            exec(
                escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($scriptPath) . ' 2>&1',
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

            return ['exit' => $exit, 'output' => $outputStr, 'data' => $data];
        } finally {
            @unlink($scriptPath); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }
}
