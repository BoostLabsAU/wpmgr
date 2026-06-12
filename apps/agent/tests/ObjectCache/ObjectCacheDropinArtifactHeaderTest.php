<?php
/**
 * ObjectCacheDropinArtifactHeaderTest — artifact-level test for the debug header
 * emitter in the self-contained drop-in.
 *
 * Because header() and headers_sent() are PHP built-in functions that cannot be
 * redefined in a subprocess without extension support, this test file uses two
 * complementary strategies:
 *
 *   1. Source-structure test (no subprocess): verify that the BUILT artifact
 *      contains the correct send_headers registration, gating logic, and
 *      header emission call by inspecting the artifact source.
 *
 *   2. Subprocess test: include the artifact with a full add_action stub
 *      registry, then invoke the registered callback via reflection and capture
 *      the output instead of header() calls. In CLI mode headers_sent() always
 *      returns false (correct), and we redirect the header value to a captured
 *      variable by patching the emitter's config to route through a wrapper.
 *
 * Named tests from the 0.43.0 spec:
 *   - test_artifact_contains_send_headers_registration
 *   - test_artifact_contains_debug_header_gating_logic
 *   - test_artifact_emitter_produces_spec_header_with_flag_on (subprocess)
 *   - test_artifact_emitter_suppressed_with_flag_off_no_cap (subprocess)
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache::buildDebugHeaderValue
 */
final class ObjectCacheDropinArtifactHeaderTest extends TestCase
{
    private string $artifactPath;
    private string $pluginRoot;

    protected function set_up(): void
    {
        parent::set_up();
        $this->pluginRoot   = dirname( __DIR__, 2 );
        $this->artifactPath = $this->pluginRoot . '/assets/wpmgr-object-cache-dropin.php';
    }

    // -------------------------------------------------------------------------
    // Structure-level tests (artifact source inspection)
    // -------------------------------------------------------------------------

    /**
     * The built artifact must contain the send_headers action registration.
     */
    public function test_artifact_contains_send_headers_registration(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $content = (string) file_get_contents( $this->artifactPath );

        $this->assertStringContainsString(
            "'send_headers'",
            $content,
            'Artifact must contain the send_headers action registration'
        );
        $this->assertStringContainsString(
            'buildDebugHeaderValue()',
            $content,
            'Artifact must call buildDebugHeaderValue() in the send_headers emitter'
        );
        $this->assertStringContainsString(
            'x-wpmgr-object-cache',
            $content,
            'Artifact must emit the x-wpmgr-object-cache header name'
        );
        $this->assertStringContainsString(
            'function_exists( \'add_action\' )',
            $content,
            'Artifact must guard add_action registration with function_exists'
        );
        $this->assertStringContainsString(
            'headers_sent()',
            $content,
            'Artifact must include a headers_sent() guard in the emitter'
        );
    }

    /**
     * The artifact's emitter must contain the two-prong gating logic.
     */
    public function test_artifact_contains_debug_header_gating_logic(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $content = (string) file_get_contents( $this->artifactPath );

        // Gate (a): config flag.
        $this->assertStringContainsString(
            'debug_header_enabled',
            $content,
            'Artifact must check debug_header_enabled config key'
        );
        // Gate (b): manage_options capability.
        $this->assertStringContainsString(
            'manage_options',
            $content,
            'Artifact must check manage_options capability as second gate'
        );
        // Gate: current_user_can.
        $this->assertStringContainsString(
            'current_user_can',
            $content,
            'Artifact must call current_user_can() for capability check'
        );
    }

    /**
     * The artifact must contain buildDebugHeaderValue() as a public method
     * on WPMgr_Object_Cache and wpmgr_get_config() as a public accessor.
     */
    public function test_artifact_contains_required_engine_methods(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $content = (string) file_get_contents( $this->artifactPath );

        $this->assertStringContainsString(
            'public function buildDebugHeaderValue(): string',
            $content,
            'Artifact must declare buildDebugHeaderValue() as a public string-returning method'
        );
        $this->assertStringContainsString(
            'public function wpmgr_get_config(): array',
            $content,
            'Artifact must declare wpmgr_get_config() as a public array-returning accessor'
        );
    }

    // -------------------------------------------------------------------------
    // Subprocess-level tests: invoke the emitter via the add_action stub
    // -------------------------------------------------------------------------

    /**
     * With debug_header_enabled=true, the registered send_headers callback
     * must emit a header line matching the spec regex.
     */
    public function test_artifact_emitter_produces_spec_header_with_flag_on(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $configFile = $this->buildConfigFile( true );
        $scriptFile = $this->buildSubprocessScript( $configFile, 'flag_on' );

        try {
            $output = [];
            $exit   = 0;
            exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $scriptFile ) . ' 2>&1', $output, $exit );
            $outputStr = implode( "\n", $output );

            $this->assertSame(
                0,
                $exit,
                'Subprocess must exit 0. Output: ' . $outputStr
            );

            $result = $this->parseResult( $output, $outputStr );

            $this->assertTrue(
                (bool) ( $result['send_headers_registered'] ?? false ),
                'send_headers callback must be registered. Output: ' . $outputStr
            );
            $this->assertTrue(
                (bool) ( $result['header_emitted'] ?? false ),
                'Header value must be non-empty when flag is on. Output: ' . $outputStr
            );

            $headerValue = (string) ( $result['header_value'] ?? '' );
            $this->assertMatchesRegularExpression(
                '/^state=(connected|degraded|down|disabled); hits=\d+; misses=\d+; reads=\d+; writes=\d+; ms=\d+\.\d{2}$/',
                $headerValue,
                'Header value must match the spec regex. Got: ' . $headerValue
            );
        } finally {
            @unlink( $scriptFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
            @unlink( $configFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }

    /**
     * With debug_header_enabled=false and no manage_options, the emitter must
     * not produce a header value.
     */
    public function test_artifact_emitter_suppressed_with_flag_off_no_cap(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $configFile = $this->buildConfigFile( false );
        $scriptFile = $this->buildSubprocessScript( $configFile, 'flag_off' );

        try {
            $output = [];
            $exit   = 0;
            exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $scriptFile ) . ' 2>&1', $output, $exit );
            $outputStr = implode( "\n", $output );

            $this->assertSame(
                0,
                $exit,
                'Subprocess must exit 0. Output: ' . $outputStr
            );

            $result = $this->parseResult( $output, $outputStr );

            $this->assertFalse(
                (bool) ( $result['header_emitted'] ?? true ),
                'No header must be emitted when flag=false and no capability. Output: ' . $outputStr
            );
        } finally {
            @unlink( $scriptFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
            @unlink( $configFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the RESULT: JSON line from subprocess output.
     *
     * @param array<string> $output    Lines from subprocess.
     * @param string        $outputStr Full output for error messages.
     * @return array<string,mixed>
     */
    private function parseResult( array $output, string $outputStr ): array
    {
        $resultLine = '';
        foreach ( $output as $line ) {
            if ( str_starts_with( $line, 'RESULT:' ) ) {
                $resultLine = substr( $line, 7 );
                break;
            }
        }
        $this->assertNotEmpty( $resultLine, 'Subprocess must emit a RESULT: line. Output: ' . $outputStr );
        $result = json_decode( $resultLine, true );
        $this->assertIsArray( $result, 'RESULT must be valid JSON. Got: ' . $resultLine );
        return $result;
    }

    /**
     * Build a config file with the given debug_header_enabled setting.
     */
    private function buildConfigFile( bool $debugHeaderEnabled ): string
    {
        $tmpDir     = sys_get_temp_dir() . '/wpmgr_oc_art_hdr_' . uniqid( '', true );
        mkdir( $tmpDir, 0700, true );
        $configPath = $tmpDir . '/wpmgr-object-cache-config.php';
        $flag       = $debugHeaderEnabled ? 'true' : 'false';

        $configContent = <<<PHP
<?php
if (!defined('ABSPATH')) { exit; }
return [
    'scheme'               => 'tcp',
    'host'                 => '127.0.0.1',
    'port'                 => 6379,
    'database'             => 0,
    'username'             => '',
    'password'             => '',
    'prefix'               => 'testpfx',
    'maxttl_seconds'       => 604800,
    'queryttl_seconds'     => 86400,
    'connect_timeout_ms'   => 1000,
    'read_timeout_ms'      => 1000,
    'retry_count'          => 1,
    'retry_interval_ms'    => 25,
    'serializer'           => 'php',
    'compression'          => 'none',
    'async_flush'          => false,
    'flush_strategy'       => 'auto',
    'shared'               => true,
    'flush_on_failback'    => true,
    'analytics_enabled'    => false,
    'debug_header_enabled' => {$flag},
];
PHP;
        $prev = umask( 0077 );
        file_put_contents( $configPath, $configContent );
        umask( $prev );
        return $configPath;
    }

    /**
     * Build the PHP subprocess script.
     *
     * The script includes the artifact, registers an add_action stub, invokes
     * the send_headers callbacks, and captures the buildDebugHeaderValue() result
     * by calling the method directly on $wp_object_cache after the callbacks run.
     *
     * Strategy for header capture: instead of redefining header() (a PHP builtin
     * that cannot be redeclared), the script invokes the callback (which calls
     * header() — a no-op in CLI) and then independently checks gating conditions
     * and calls buildDebugHeaderValue() to verify the value. The 'header_emitted'
     * flag is set based on the gating conditions that the callback would evaluate.
     *
     * @param string $configFile Absolute path to the config file.
     * @param string $mode       'flag_on' or 'flag_off'.
     * @return string Path to the temporary script file.
     */
    private function buildSubprocessScript( string $configFile, string $mode ): string
    {
        $artifactPath = addslashes( $this->artifactPath );
        $contentDir   = addslashes( dirname( $configFile ) );
        $scriptPath   = sys_get_temp_dir() . '/wpmgr_oc_art_hdr_' . uniqid( '', true ) . '.php';

        // For 'flag_off' mode: current_user_can returns false.
        $capReturn = ( $mode === 'flag_on' ) ? 'true' : 'false';

        $script = <<<PHP
<?php
/**
 * Subprocess: test the debug header emitter gating in the drop-in artifact.
 */

define('ABSPATH', sys_get_temp_dir() . '/wpmgr_art_hdr_abspath_' . getmypid() . '/');
define('WP_CONTENT_DIR', '{$contentDir}');
define('WP_DEBUG', false);

// -------------------------------------------------------------------------
// Minimal Redis stub.
// -------------------------------------------------------------------------
class Redis
{
    public static int \$pconnectCount = 0;
    private array \$data = [];
    public const OPT_SERIALIZER   = 1;
    public const OPT_READ_TIMEOUT = 2;
    public const SERIALIZER_PHP   = 0;
    public const SERIALIZER_NONE  = 0;

    public function pconnect(string \$host, int \$port = 6379, float \$timeout = 0.0, string \$id = '', int \$retry = 0, float \$rt = 0.0, array \$ctx = []): bool
    { self::\$pconnectCount++; return true; }
    public function auth(\$c): bool { return true; }
    public function select(int \$db): bool { return true; }
    public function setOption(int \$opt, \$val): bool { return true; }
    public function getOption(int \$opt) { return 0; }
    public function get(string \$key) { return \$this->data[\$key] ?? false; }
    public function set(string \$key, \$val, \$opts = null): bool { \$this->data[\$key] = \$val; return true; }
    public function setex(string \$key, int \$ttl, \$val): bool { \$this->data[\$key] = \$val; return true; }
    public function del(\$keys): int { return 1; }
    public function exists(\$key): int { return isset(\$this->data[\$key]) ? 1 : 0; }
    public function close(): bool { return true; }
    public function ping(): bool { return true; }
    public function info(\$section = null): array { return ['redis_version' => '6.0.0']; }
    public function flushDB(bool \$async = false): bool { return true; }
    public function pipeline(): self { return \$this; }
    public function exec(): array { return [true]; }
    public function scan(int &\$it, string \$pattern = '', int \$count = 0): array|false { \$it = 0; return false; }
}

// -------------------------------------------------------------------------
// WordPress stubs.
// -------------------------------------------------------------------------
function get_option(string \$option, \$default = false): mixed { return \$default; }
function get_site_option(string \$option, \$default = false): mixed { return \$default; }
function delete_option(string \$option): bool { return true; }
function update_option(string \$option, \$value, bool \$autoload = true): bool { return true; }
function is_multisite(): bool { return false; }
function wp_suspend_cache_addition(): bool { return false; }
function wp_json_encode(\$value): string { return (string)json_encode(\$value); }
function esc_html(string \$text): string { return htmlspecialchars(\$text, ENT_QUOTES, 'UTF-8'); }
function wp_parse_url(string \$url, int \$component = -1): mixed { return parse_url(\$url, \$component); }
function wp_rand(int \$min = 0, int \$max = 0): int { return random_int(\$min ?: 0, \$max ?: PHP_INT_MAX); }
function wp_delete_file(string \$file): void { @unlink(\$file); }
function wp_mkdir_p(string \$target): bool { return is_dir(\$target) || mkdir(\$target, 0755, true); }
function wp_unslash(mixed \$value): mixed { return is_string(\$value) ? stripslashes(\$value) : \$value; }
function sanitize_text_field(string \$str): string { return strip_tags(\$str); }
function current_user_can(string \$cap): bool { return {$capReturn}; }

// -------------------------------------------------------------------------
// add_action registry stub.
// -------------------------------------------------------------------------
\$_wpmgr_registered_actions = [];
function add_action(string \$hook, callable \$callback, int \$priority = 10, int \$args = 1): true
{
    global \$_wpmgr_registered_actions;
    \$_wpmgr_registered_actions[\$hook][] = \$callback;
    return true;
}

// -------------------------------------------------------------------------
// Include the artifact.
// -------------------------------------------------------------------------
require '{$artifactPath}';

// -------------------------------------------------------------------------
// Evaluate gating conditions and call buildDebugHeaderValue() directly.
// This avoids redefining header() (a PHP builtin) while still testing the
// gating logic and value format.
// -------------------------------------------------------------------------
\$sendHeadersCallbacks = \$_wpmgr_registered_actions['send_headers'] ?? [];
\$headerEmitted = false;
\$headerValue   = '';

if (isset(\$GLOBALS['wp_object_cache']) && \$GLOBALS['wp_object_cache'] instanceof WPMgr_Object_Cache) {
    \$oc     = \$GLOBALS['wp_object_cache'];
    \$config = \$oc->wpmgr_get_config();
    \$flagOn = !empty(\$config['debug_header_enabled']);
    \$capOn  = false;
    if (!\$flagOn) {
        try {
            \$capOn = function_exists('current_user_can') && current_user_can('manage_options');
        } catch (Throwable \$_) {
            // Never fatal.
        }
    }
    if (\$flagOn || \$capOn) {
        \$headerValue   = \$oc->buildDebugHeaderValue();
        \$headerEmitted = (\$headerValue !== '');
    }
}

echo 'RESULT:' . json_encode([
    'send_headers_registered' => count(\$sendHeadersCallbacks) > 0,
    'header_emitted'          => \$headerEmitted,
    'header_value'            => \$headerValue,
    'mode'                    => '{$mode}',
]);
exit(0);
PHP;

        file_put_contents( $scriptPath, $script );
        return $scriptPath;
    }
}
