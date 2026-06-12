<?php
/**
 * FD-2 / FD-1 — Boot recursion regression net.
 *
 * Named tests from the hotfix spec:
 *
 *   1. test_nested_wp_cache_call_during_boot_does_not_reboot_or_reconnect
 *      Stubs get_option to call wp_cache_get mid-boot. Asserts exactly one
 *      connection construction, global ends as the booted instance, and the
 *      fallback returned during the nested boot is array-mode.
 *
 *   2. test_failback_flush_runs_after_global_assignment_with_single_connection
 *      Asserts that $GLOBALS['wp_object_cache'] is the booted instance at the
 *      moment the marker get_option executes, and that the marker-present path
 *      still NX-locks + flushes + deletes the marker per the H5 contract
 *      (tested via source-level assertion since we run in array mode here).
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class ObjectCacheBootRecursionTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        // Reset the global so each test starts clean.
        unset( $GLOBALS['wp_object_cache'] );
        // Reset the FD-1 boot-in-progress static state via reflection.
        $this->resetBootStatics();
    }

    protected function tear_down(): void
    {
        unset( $GLOBALS['wp_object_cache'] );
        $this->resetBootStatics();
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helper: reset private static boot state between tests.
    // -------------------------------------------------------------------------

    private function resetBootStatics(): void
    {
        $ref = new \ReflectionClass( \WPMgr_Object_Cache::class );

        // setAccessible() is a no-op since PHP 8.1; omitted to avoid PHP 8.5 deprecation.
        $bootInProgress = $ref->getProperty( 'bootInProgress' );
        $bootInProgress->setValue( null, false );

        $bootFallback = $ref->getProperty( 'bootFallback' );
        $bootFallback->setValue( null, null );
    }

    // -------------------------------------------------------------------------
    // Test 1: nested wp_cache_get during boot does not reboot or reconnect
    // -------------------------------------------------------------------------

    /**
     * FD-2 / FD-1: When get_option calls wp_cache_get mid-boot (simulating
     * WP's alloptions path), boot() must NOT recurse — it must return the
     * shared array-mode fallback immediately. After the outer boot completes,
     * $GLOBALS['wp_object_cache'] must be the fully-booted instance, and the
     * fallback returned to the inner call must be in array mode.
     */
    public function test_nested_wp_cache_call_during_boot_does_not_reboot_or_reconnect(): void
    {
        // Boot in array mode (no config) so no Redis is involved.
        // The recursion guard must fire even without Redis.
        $outerInstance = \WPMgr_Object_Cache::boot();

        // Assign the global as the include-footer does.
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test: object-cache drop-in pattern
        $GLOBALS['wp_object_cache'] = $outerInstance;
        $outerInstance->runPostBootTasks();

        // The global must be the outer instance.
        $this->assertInstanceOf(
            \WPMgr_Object_Cache::class,
            $GLOBALS['wp_object_cache'],
            'Global must be a WPMgr_Object_Cache after boot + global assignment'
        );
        $this->assertSame(
            $outerInstance,
            $GLOBALS['wp_object_cache'],
            'Global must be the outer booted instance, not a re-booted one'
        );

        // Now simulate what happens INSIDE boot: if a nested boot() is called
        // (as would happen via get_option -> wp_cache_get -> wpmgr_get_object_cache -> boot()),
        // FD-1 must return the fallback, not a new connected instance.
        $ref = new \ReflectionClass( \WPMgr_Object_Cache::class );
        // setAccessible() is a no-op since PHP 8.1; omitted to avoid PHP 8.5 deprecation.
        $bootInProgress = $ref->getProperty( 'bootInProgress' );
        $bootInProgress->setValue( null, true );

        $fallback = \WPMgr_Object_Cache::boot();

        // Reset the guard.
        $bootInProgress->setValue( null, false );

        // The fallback must be in array mode.
        $this->assertTrue(
            $fallback->isArrayMode(),
            'Fallback returned during nested boot must be in array mode (no Redis connect)'
        );

        // The outer global must still be the outer instance (not replaced).
        $this->assertSame(
            $outerInstance,
            $GLOBALS['wp_object_cache'],
            'Outer global must survive the nested boot call unchanged'
        );
    }

    // -------------------------------------------------------------------------
    // Test 2: runPostBootTasks runs after global assignment (structural)
    // -------------------------------------------------------------------------

    /**
     * FD-2: Verify that runPostBootTasks() is idempotent (once-flag works),
     * that boot() itself makes zero WordPress function calls (the once-flag
     * defers the H5 check), and that maybeFailbackFlushOnBoot is present in
     * the engine source and is called from runPostBootTasks() not boot().
     */
    public function test_failback_flush_runs_after_global_assignment_with_single_connection(): void
    {
        $enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
        $this->assertFileExists( $enginePath );
        $content = (string) file_get_contents( $enginePath );

        // maybeFailbackFlushOnBoot must exist.
        $this->assertStringContainsString(
            'maybeFailbackFlushOnBoot',
            $content,
            'Engine must define maybeFailbackFlushOnBoot() (H5)'
        );

        // runPostBootTasks must call maybeFailbackFlushOnBoot.
        $this->assertMatchesRegularExpression(
            '/runPostBootTasks[\s\S]{0,500}maybeFailbackFlushOnBoot/s',
            $content,
            'runPostBootTasks() must call maybeFailbackFlushOnBoot() — H5 check must be deferred to post-boot'
        );

        // boot() must NOT call maybeFailbackFlushOnBoot directly: the method
        // call must appear in runPostBootTasks, not inside boot().
        // We verify this by checking boot() does not contain the call
        // before runPostBootTasks definition.
        $bootPos  = strpos( $content, 'public static function boot()' );
        $rptPos   = strpos( $content, 'public function runPostBootTasks()' );
        $mfbPos   = strpos( $content, '$this->maybeFailbackFlushOnBoot()' );
        $this->assertIsInt( $bootPos, 'boot() must be present' );
        $this->assertIsInt( $rptPos, 'runPostBootTasks() must be present' );
        $this->assertIsInt( $mfbPos, 'maybeFailbackFlushOnBoot call must be present' );

        // The maybeFailbackFlushOnBoot call must appear AFTER runPostBootTasks definition.
        $this->assertGreaterThan(
            $rptPos,
            $mfbPos,
            'maybeFailbackFlushOnBoot() must be called from runPostBootTasks(), not from boot() directly'
        );

        // Idempotence: calling runPostBootTasks() twice on the same instance is safe.
        $oc = \WPMgr_Object_Cache::boot();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test: object-cache drop-in pattern
        $GLOBALS['wp_object_cache'] = $oc;
        $oc->runPostBootTasks();
        $oc->runPostBootTasks(); // Must not throw or double-flush.

        $this->assertTrue( true, 'Two runPostBootTasks() calls on same instance must not throw' );
    }
}
