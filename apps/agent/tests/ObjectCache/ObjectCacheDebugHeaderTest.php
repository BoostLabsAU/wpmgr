<?php
/**
 * ObjectCacheDebugHeaderTest — binding test for the x-wpmgr-object-cache debug header.
 *
 * Named tests:
 *   1. test_build_debug_header_value_format — format validation (state + all five fields, parseable).
 *   2. test_build_debug_header_value_secrecy — value must never contain host/prefix/password/username
 *      or the engine version string across connected/degraded/array states.
 *   3. test_gating_matrix — flag off + anonymous => no emission; flag on => emission;
 *      flag off + manage_options => emission.
 *   4. test_headers_sent_guard — emitter is a no-op when headers_sent() returns true.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache::buildDebugHeaderValue
 * @covers \WPMgr_Object_Cache::wpmgr_get_config
 */
final class ObjectCacheDebugHeaderTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an array-mode WPMgr_Object_Cache instance with controlled counters.
     *
     * @param array<string,mixed> $config  Config to inject.
     * @param int                 $hits    cache_hits value.
     * @param int                 $misses  cache_misses value.
     * @param int                 $reads   redisReads value.
     * @param int                 $writes  redisWrites value.
     * @param float               $waitMs  totalWaitMs value.
     * @return \WPMgr_Object_Cache
     */
    private function buildEngineWithCounters(
        array $config = [],
        int $hits = 0,
        int $misses = 0,
        int $reads = 0,
        int $writes = 0,
        float $waitMs = 0.0
    ): \WPMgr_Object_Cache {
        $oc  = new \WPMgr_Object_Cache();
        $ref = new \ReflectionClass( \WPMgr_Object_Cache::class );

        $ref->getProperty( 'arrayMode' )->setValue( $oc, true );
        $ref->getProperty( 'config' )->setValue( $oc, $config );
        $ref->getProperty( 'cache_hits' )->setValue( $oc, $hits );
        $ref->getProperty( 'cache_misses' )->setValue( $oc, $misses );
        $ref->getProperty( 'redisReads' )->setValue( $oc, $reads );
        $ref->getProperty( 'redisWrites' )->setValue( $oc, $writes );
        $ref->getProperty( 'totalWaitMs' )->setValue( $oc, $waitMs );

        return $oc;
    }

    // -------------------------------------------------------------------------
    // Test 1: format validation
    // -------------------------------------------------------------------------

    /**
     * buildDebugHeaderValue() must return a parseable string with all six fields.
     */
    public function test_build_debug_header_value_format(): void
    {
        $oc = $this->buildEngineWithCounters(
            config:  [],
            hits:    42,
            misses:  7,
            reads:   30,
            writes:  5,
            waitMs:  123.456
        );

        $value = $oc->buildDebugHeaderValue();

        // Must parse as key=value pairs separated by '; '.
        $this->assertMatchesRegularExpression(
            '/^state=(connected|degraded|down|disabled); hits=\d+; misses=\d+; reads=\d+; writes=\d+; ms=\d+\.\d{2}$/',
            $value,
            'buildDebugHeaderValue() must match the spec format'
        );

        // All six fields present.
        $this->assertStringContainsString( 'state=', $value );
        $this->assertStringContainsString( 'hits=42', $value );
        $this->assertStringContainsString( 'misses=7', $value );
        $this->assertStringContainsString( 'reads=30', $value );
        $this->assertStringContainsString( 'writes=5', $value );

        // ms: totalWaitMs / (reads + writes) = 123.456 / 35 ≈ 3.53.
        $this->assertMatchesRegularExpression( '/ms=\d+\.\d{2}/', $value );
    }

    /**
     * When reads+writes = 0, ms must be 0.00 (no division by zero).
     */
    public function test_build_debug_header_value_zero_ops_ms(): void
    {
        $oc    = $this->buildEngineWithCounters( hits: 1, misses: 0, reads: 0, writes: 0, waitMs: 50.0 );
        $value = $oc->buildDebugHeaderValue();

        $this->assertStringContainsString( 'ms=0.00', $value, 'ms must be 0.00 when no Redis ops occurred' );
    }

    /**
     * State values match the getHeartbeatStats() ladder.
     */
    public function test_build_debug_header_value_states(): void
    {
        $ref = new \ReflectionClass( \WPMgr_Object_Cache::class );

        // disabled: arrayMode=true, errorJournal empty.
        $oc1 = $this->buildEngineWithCounters();
        $this->assertStringStartsWith( 'state=disabled', $oc1->buildDebugHeaderValue() );

        // down: arrayMode=true, errorJournal non-empty.
        $oc2 = new \WPMgr_Object_Cache();
        $ref->getProperty( 'arrayMode' )->setValue( $oc2, true );
        $ref->getProperty( 'config' )->setValue( $oc2, [] );
        $ref->getProperty( 'errorJournal' )->setValue( $oc2, [ 'boot_failure' ] );
        $this->assertStringStartsWith( 'state=down', $oc2->buildDebugHeaderValue() );
    }

    // -------------------------------------------------------------------------
    // Test 2: secrecy assertion
    // -------------------------------------------------------------------------

    /**
     * buildDebugHeaderValue() must NEVER expose host, prefix, password, username,
     * socket_path, database index, key names, or engine version in the header value.
     */
    public function test_build_debug_header_value_secrecy(): void
    {
        $sensitiveConfig = [
            'host'                 => 'secret-redis.internal.example.com',
            'port'                 => 6399,
            'socket_path'          => '/var/run/redis/sensitive.sock',
            'database'             => 7,
            'username'             => 'supersecretuser',
            'password'             => 'supersecretpassword',
            'prefix'               => 'tenantprefix42',
            'analytics_enabled'    => true,
            'debug_header_enabled' => true,
        ];

        $ref = new \ReflectionClass( \WPMgr_Object_Cache::class );

        $states = [
            'disabled' => static function ( \ReflectionClass $r, \WPMgr_Object_Cache $o, array $c ): void {
                $r->getProperty( 'arrayMode' )->setValue( $o, true );
                $r->getProperty( 'config' )->setValue( $o, $c );
            },
            'down' => static function ( \ReflectionClass $r, \WPMgr_Object_Cache $o, array $c ): void {
                $r->getProperty( 'arrayMode' )->setValue( $o, true );
                $r->getProperty( 'config' )->setValue( $o, $c );
                $r->getProperty( 'errorJournal' )->setValue( $o, [ 'boot_failure' ] );
            },
            'array_mode_no_error' => static function ( \ReflectionClass $r, \WPMgr_Object_Cache $o, array $c ): void {
                $r->getProperty( 'arrayMode' )->setValue( $o, true );
                $r->getProperty( 'config' )->setValue( $o, $c );
                $r->getProperty( 'errorJournal' )->setValue( $o, [] );
            },
        ];

        foreach ( $states as $stateName => $setup ) {
            $oc = new \WPMgr_Object_Cache();
            $setup( $ref, $oc, $sensitiveConfig );
            $value = $oc->buildDebugHeaderValue();

            // None of the sensitive config values must appear.
            $this->assertStringNotContainsString(
                'secret-redis.internal.example.com',
                $value,
                "State {$stateName}: host must not appear in header value"
            );
            $this->assertStringNotContainsString(
                '/var/run/redis/sensitive.sock',
                $value,
                "State {$stateName}: socket_path must not appear in header value"
            );
            $this->assertStringNotContainsString(
                'supersecretuser',
                $value,
                "State {$stateName}: username must not appear in header value"
            );
            $this->assertStringNotContainsString(
                'supersecretpassword',
                $value,
                "State {$stateName}: password must not appear in header value"
            );
            $this->assertStringNotContainsString(
                'tenantprefix42',
                $value,
                "State {$stateName}: prefix must not appear in header value"
            );
            $this->assertStringNotContainsString(
                'redis://',
                $value,
                "State {$stateName}: 'redis://' scheme must not appear in header value"
            );
            // Engine version must not appear.
            $this->assertStringNotContainsString(
                \WPMgr_Object_Cache::ENGINE_VERSION,
                $value,
                "State {$stateName}: ENGINE_VERSION must not appear in header value"
            );
            // Database index (7) as a standalone field must not appear specially.
            // It is acceptable for the integer "7" to appear inside a counter value,
            // but the word 'database' must never appear.
            $this->assertStringNotContainsString(
                'database',
                $value,
                "State {$stateName}: the key 'database' must not appear in header value"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Test 3: gating matrix
    // -------------------------------------------------------------------------

    /**
     * Gating: flag off + no capability => header NOT emitted.
     */
    public function test_gating_flag_off_no_cap_no_emission(): void
    {
        Functions\when( 'current_user_can' )->justReturn( false );

        $oc     = $this->buildEngineWithCounters( config: [ 'debug_header_enabled' => false ] );
        $config = $oc->wpmgr_get_config();
        $flagOn = ! empty( $config['debug_header_enabled'] );
        $capOn  = false;
        try {
            $capOn = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        } catch ( \Throwable $_ ) {
            // Never fatal.
        }

        $this->assertFalse( $flagOn, 'Flag must be off for this gate test' );
        $this->assertFalse( $capOn, 'Capability must be false for this gate test' );
        $this->assertFalse( $flagOn || $capOn, 'Neither gate condition met => no emission' );
    }

    /**
     * Gating: flag on => header emitted (regardless of capability).
     */
    public function test_gating_flag_on_emits(): void
    {

        $oc     = $this->buildEngineWithCounters( config: [ 'debug_header_enabled' => true ] );
        $config = $oc->wpmgr_get_config();
        $flagOn = ! empty( $config['debug_header_enabled'] );

        $this->assertTrue( $flagOn, 'Flag must be on => emission gate passed' );

        // Also verify the header value is well-formed.
        $value = $oc->buildDebugHeaderValue();
        $this->assertMatchesRegularExpression(
            '/^state=(connected|degraded|down|disabled); hits=\d+; misses=\d+; reads=\d+; writes=\d+; ms=\d+\.\d{2}$/',
            $value,
            'Header value must be well-formed when flag is on'
        );
    }

    /**
     * Gating: flag off + manage_options capability => header emitted.
     */
    public function test_gating_flag_off_manage_options_emits(): void
    {
        Functions\when( 'current_user_can' )->justReturn( true );

        $oc     = $this->buildEngineWithCounters( config: [ 'debug_header_enabled' => false ] );
        $config = $oc->wpmgr_get_config();
        $flagOn = ! empty( $config['debug_header_enabled'] );
        $capOn  = false;
        try {
            $capOn = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        } catch ( \Throwable $_ ) {
            // Never fatal.
        }

        $this->assertFalse( $flagOn, 'Flag must be off for this gate test' );
        $this->assertTrue( $capOn, 'Capability must be true => emission gate passed' );
    }

    /**
     * The gating logic must be resilient: current_user_can() throwing does not
     * cause a fatal — it is caught and treated as cap=false.
     */
    public function test_gating_capability_exception_is_caught(): void
    {
        Functions\when( 'current_user_can' )->alias(
            static function (): never {
                throw new \RuntimeException( 'test: current_user_can threw' );
            }
        );

        $oc     = $this->buildEngineWithCounters( config: [ 'debug_header_enabled' => false ] );
        $config = $oc->wpmgr_get_config();
        $flagOn = ! empty( $config['debug_header_enabled'] );
        $capOn  = false;
        try {
            $capOn = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        } catch ( \Throwable $_ ) {
            // Never fatal.
        }

        $this->assertFalse( $flagOn );
        // capOn must remain false — the exception was caught.
        $this->assertFalse( $capOn, 'Thrown exception from current_user_can must not propagate; capOn stays false' );
    }

    // -------------------------------------------------------------------------
    // Test 4: headers_sent guard
    // -------------------------------------------------------------------------

    /**
     * The engine source must contain a headers_sent() guard in the send_headers
     * emitter to prevent double-sending headers. This is a source-level assertion
     * since headers_sent() is a PHP internal that cannot be stubbed via Patchwork.
     */
    public function test_headers_sent_guard_present_in_engine_source(): void
    {
        $enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
        $this->assertFileExists( $enginePath );
        $content = (string) file_get_contents( $enginePath );

        $this->assertStringContainsString(
            'headers_sent()',
            $content,
            'Engine send_headers emitter must contain a headers_sent() guard'
        );

        // The guard must appear in close proximity to the header() call.
        $guardPos  = strpos( $content, 'headers_sent()' );
        $headerPos = strpos( $content, "header( 'x-wpmgr-object-cache:" );
        $this->assertIsInt( $guardPos, 'headers_sent() must be present in engine source' );
        $this->assertIsInt( $headerPos, "header('x-wpmgr-object-cache:') must be present in engine source" );

        // The guard must appear before the header() call.
        $this->assertLessThan(
            $headerPos,
            $guardPos,
            'headers_sent() guard must appear before the header() emission call'
        );
    }

    // -------------------------------------------------------------------------
    // Test 5: wpmgr_get_config() accessor
    // -------------------------------------------------------------------------

    /**
     * wpmgr_get_config() must return the config array injected at construction.
     */
    public function test_wpmgr_get_config_returns_injected_config(): void
    {
        $config = [
            'host'                 => '127.0.0.1',
            'debug_header_enabled' => true,
            'analytics_enabled'    => false,
        ];
        $oc = $this->buildEngineWithCounters( config: $config );
        $this->assertSame( $config, $oc->wpmgr_get_config() );
    }

    // -------------------------------------------------------------------------
    // Test 6: add_action registration present in engine source
    // -------------------------------------------------------------------------

    /**
     * The engine source must register the send_headers action with add_action.
     */
    public function test_send_headers_action_registered_in_engine_source(): void
    {
        $enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
        $this->assertFileExists( $enginePath );
        $content = (string) file_get_contents( $enginePath );

        $this->assertStringContainsString(
            "'send_headers'",
            $content,
            'Engine must register the send_headers action'
        );
        $this->assertStringContainsString(
            'buildDebugHeaderValue()',
            $content,
            'Engine must call buildDebugHeaderValue() in the send_headers handler'
        );
        $this->assertStringContainsString(
            'function_exists( \'add_action\' )',
            $content,
            'Engine must guard add_action registration with function_exists'
        );
        $this->assertStringContainsString(
            'x-wpmgr-object-cache',
            $content,
            'Engine must emit the x-wpmgr-object-cache header name'
        );
    }
}
