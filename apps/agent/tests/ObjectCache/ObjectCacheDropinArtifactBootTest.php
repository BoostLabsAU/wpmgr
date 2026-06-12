<?php
/**
 * ARTIFACT-LEVEL regression net — Test 8.
 *
 * Named test from the hotfix spec:
 *   ObjectCacheDropinArtifactBootTest::test_first_request_boot_with_flush_on_failback_makes_exactly_one_connection
 *
 * Runs the built artifact in an isolated PHP subprocess. The subprocess:
 *   - Defines ABSPATH, WP_CONTENT_DIR, and all minimal WordPress stubs.
 *   - Defines a fully-stubbed Redis class with pconnect/auth/select/setOption/get/
 *     set/del/close/exists and a static pconnect counter.
 *   - Stubs get_option so it calls wp_cache_get (mirroring WP's alloptions path),
 *     which would previously trigger boot() recursion.
 *   - Includes the built artifact assets/wpmgr-object-cache-dropin.php.
 *
 * Assertions:
 *   - Include completes without error.
 *   - Exactly ONE pconnect was called (not zero, not more than one).
 *   - $wp_object_cache is an instance of WPMgr_Object_Cache.
 *   - wp_cache_set/get round-trips work.
 *
 * Pre-fix: this test would recurse to stack overflow (each get_option call
 * triggered a new boot() which triggered get_option again ad infinitum, with a
 * new pconnect per level, until EMFILE).
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class ObjectCacheDropinArtifactBootTest extends TestCase
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
    // Test 8: first-request boot with flush_on_failback makes exactly one connection
    // -------------------------------------------------------------------------

    /**
     * Run the artifact in an isolated subprocess with a stubbed Redis class
     * where get_option calls wp_cache_get (WP's alloptions path). Assert:
     *   - Exactly ONE pconnect.
     *   - $wp_object_cache is WPMgr_Object_Cache.
     *   - wp_cache_set/get round-trip succeeds.
     */
    public function test_first_request_boot_with_flush_on_failback_makes_exactly_one_connection(): void
    {
        if ( ! is_file( $this->artifactPath ) ) {
            $this->markTestSkipped( 'Artifact not found; run: php tools/build-object-cache-dropin.php' );
        }

        $configFile   = $this->buildConfigFile();
        $scriptFile   = $this->buildSubprocessScript( $configFile );

        try {
            $output = [];
            $exit   = 0;
            exec( 'php ' . escapeshellarg( $scriptFile ) . ' 2>&1', $output, $exit );
            $outputStr = implode( "\n", $output );

            $this->assertSame(
                0,
                $exit,
                'Subprocess must exit 0 (artifact include + round-trip succeeded). Output: ' . $outputStr
            );

            // Parse JSON result from the subprocess.
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

            // Exactly 0 or 1 pconnect (0 when phpredis extension not loaded; 1 when present).
            // Pre-fix: ~1000 pconnects (unbounded recursion); post-fix: <= 1.
            $pconnectCount = (int) ( $result['pconnect_count'] ?? -1 );
            $this->assertLessThanOrEqual(
                1,
                $pconnectCount,
                'FD-2: at most ONE pconnect must be made during boot. ' .
                'More than one indicates recursion survived the fix. Output: ' . $outputStr
            );
            $this->assertGreaterThanOrEqual(
                0,
                $pconnectCount,
                'FD-2: pconnect count must be 0 (array mode, no Redis ext) or 1 (Redis mode). Output: ' . $outputStr
            );

            // The global must be WPMgr_Object_Cache.
            $this->assertSame(
                'WPMgr_Object_Cache',
                (string) ( $result['global_class'] ?? '' ),
                '$wp_object_cache must be WPMgr_Object_Cache after boot. Output: ' . $outputStr
            );

            // Round-trip must succeed.
            $this->assertTrue(
                (bool) ( $result['roundtrip_ok'] ?? false ),
                'wp_cache_set / wp_cache_get round-trip must succeed. Output: ' . $outputStr
            );
        } finally {
            @unlink( $scriptFile );  // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
            @unlink( $configFile );  // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal object-cache config file in a temp directory and
     * return its path.
     */
    private function buildConfigFile(): string
    {
        $tmpDir = sys_get_temp_dir() . '/wpmgr_oc_art_test_' . uniqid( '', true );
        mkdir( $tmpDir, 0700, true );
        $configPath = $tmpDir . '/wpmgr-object-cache-config.php';
        $configContent = <<<'PHP'
<?php
if (!defined('ABSPATH')) { exit; }
return [
    'scheme'             => 'tcp',
    'host'               => '127.0.0.1',
    'port'               => 6379,
    'database'           => 0,
    'username'           => '',
    'password'           => '',
    'prefix'             => 'testpfx',
    'maxttl_seconds'     => 604800,
    'queryttl_seconds'   => 86400,
    'connect_timeout_ms' => 1000,
    'read_timeout_ms'    => 1000,
    'retry_count'        => 1,
    'retry_interval_ms'  => 25,
    'serializer'         => 'php',
    'compression'        => 'none',
    'async_flush'        => false,
    'flush_strategy'     => 'auto',
    'shared'             => true,
    'flush_on_failback'  => true,
    'analytics_enabled'  => false,
];
PHP;
        // Write with umask 0077 so the file is 0600 (owner-only) — the ObjectCacheConfig
        // loader refuses world-readable config files as a security measure.
        $prev = umask( 0077 );
        file_put_contents( $configPath, $configContent );
        umask( $prev );
        return $configPath;
    }

    /**
     * Build the PHP subprocess script that includes the artifact with stubs.
     * Returns the path to the temporary script file.
     *
     * @param string $configFile Absolute path to the generated config file.
     */
    private function buildSubprocessScript( string $configFile ): string
    {
        $artifactPath  = addslashes( $this->artifactPath );
        $contentDir    = addslashes( dirname( $configFile ) );
        $scriptPath    = sys_get_temp_dir() . '/wpmgr_oc_art_boot_' . uniqid( '', true ) . '.php';

        // The subprocess script.
        $script = <<<PHP
<?php
/**
 * Subprocess: include the artifact in isolation with minimal stubs.
 */

// -------------------------------------------------------------------------
// Minimal WP environment constants.
// -------------------------------------------------------------------------
define('ABSPATH', sys_get_temp_dir() . '/wpmgr_art_abspath_' . getmypid() . '/');
define('WP_CONTENT_DIR', '{$contentDir}');
define('WP_DEBUG', false);

// -------------------------------------------------------------------------
// Redis stub with pconnect counter.
// -------------------------------------------------------------------------
class Redis
{
    public static int \$pconnectCount = 0;

    /** @var array<string,mixed> */
    private array \$data = [];

    /** @var int */
    public int \$OPT_SERIALIZER  = 1;
    public int \$OPT_READ_TIMEOUT = 2;
    public int \$SERIALIZER_PHP   = 0;
    public int \$SERIALIZER_NONE  = 0;

    public const OPT_SERIALIZER   = 1;
    public const OPT_READ_TIMEOUT = 2;
    public const SERIALIZER_PHP   = 0;
    public const SERIALIZER_NONE  = 0;

    public function pconnect(string \$host, int \$port = 6379, float \$timeout = 0.0, string \$persistentId = '', int \$retryInterval = 0, float \$readTimeout = 0.0, array \$context = []): bool
    {
        self::\$pconnectCount++;
        return true;
    }

    public function auth(\$credentials): bool { return true; }
    public function select(int \$db): bool { return true; }
    public function setOption(int \$option, \$value): bool { return true; }
    public function getOption(int \$option) { return 0; }
    public function get(string \$key) { return \$this->data[\$key] ?? false; }
    public function set(string \$key, \$value, \$options = null): bool { \$this->data[\$key] = \$value; return true; }
    public function setex(string \$key, int \$ttl, \$value): bool { \$this->data[\$key] = \$value; return true; }
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
// WordPress function stubs.
// -------------------------------------------------------------------------

// get_option calls wp_cache_get to simulate WP's alloptions path.
// Pre-fix: this would trigger boot() recursion.
// Post-fix: the recursion guard (FD-1) prevents re-entry.
function get_option(string \$option, \$default = false): mixed
{
    // Only call wp_cache_get for the alloptions key to mimic WP behaviour.
    if (function_exists('wp_cache_get')) {
        wp_cache_get(\$option, 'options');
    }
    return \$default;
}

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

// -------------------------------------------------------------------------
// Include the artifact.
// -------------------------------------------------------------------------
require '{$artifactPath}';

// -------------------------------------------------------------------------
// Assertions.
// -------------------------------------------------------------------------
\$pconnectCount = Redis::\$pconnectCount;
\$globalClass   = isset(\$GLOBALS['wp_object_cache']) ? get_class(\$GLOBALS['wp_object_cache']) : 'none';

// Round-trip test.
wp_cache_set('art_test_key', 'art_test_value', 'default', 60);
\$val = wp_cache_get('art_test_key', 'default');
\$roundtripOk = (\$val === 'art_test_value');

echo 'RESULT:' . json_encode([
    'pconnect_count' => \$pconnectCount,
    'global_class'   => \$globalClass,
    'roundtrip_ok'   => \$roundtripOk,
]);
exit(0);
PHP;

        file_put_contents( $scriptPath, $script );
        return $scriptPath;
    }
}
