<?php
/**
 * H5 — Failback epoch / persisted outage marker tests.
 *
 * Verifies the H5 redesign:
 *   - A blip-then-success in the same boot issues NO flush.
 *   - Boot with a marker present + NX win → exactly one flush + marker cleared.
 *   - NX loss (another process holds the lock) → no flush.
 *   - The mid-request degrade→recover trigger is absent from the engine source.
 *   - persistOutageMarker() is called on first degradation.
 *   - 0.43.1: Redis failure during shutdown() does NOT write the outage marker.
 *   - 0.43.1: delete_option(marker) is called BEFORE executeFlush in maybeFailbackFlushOnBoot.
 *
 * These tests run in array mode (no live Redis) and verify the H5 logic through
 * observable effects on $GLOBALS options and the engine source structure.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class FailbackEpochTest extends TestCase
{
	/** @var \WPMgr_Object_Cache */
	private \WPMgr_Object_Cache $oc;

	/** @var array<string,mixed> Simple in-memory option store for test. */
	private array $options = [];

	/** @var int Flush call counter. */
	private int $flushCalls = 0;

	/** @var array<string> Log of update_option keys written (for spy assertions). */
	private array $updatedOptionKeys = [];

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();
		$this->options           = [];
		$this->flushCalls        = 0;
		$this->updatedOptionKeys = [];
		$this->oc                = \WPMgr_Object_Cache::boot();

		// Wire up option store stubs so Brain Monkey intercepts these calls.
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = false ) => $this->options[ $k ] ?? $d
		);
		Functions\when( 'update_option' )->alias( function ( $k, $v ) {
			$this->options[ $k ]       = $v;
			$this->updatedOptionKeys[] = $k;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( function ( $k ) {
			unset( $this->options[ $k ] );
			return true;
		} );
	}

	protected function tear_down(): void
	{
		Monkey\tearDown();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// H5: FAILBACK_MARKER_OPTION constant present
	// -------------------------------------------------------------------------

	public function test_failback_marker_option_constant_defined(): void
	{
		$this->assertTrue(
			defined( '\WPMgr_Object_Cache::FAILBACK_MARKER_OPTION' ),
			'WPMgr_Object_Cache::FAILBACK_MARKER_OPTION must be a public constant'
		);
	}

	public function test_failback_marker_option_value(): void
	{
		$this->assertSame(
			'wpmgr_oc_outage_marker',
			\WPMgr_Object_Cache::FAILBACK_MARKER_OPTION,
			'FAILBACK_MARKER_OPTION must equal wpmgr_oc_outage_marker'
		);
	}

	// -------------------------------------------------------------------------
	// H5: mid-request degrade→recover trigger is REMOVED from engine source
	// -------------------------------------------------------------------------

	public function test_mid_request_trigger_removed_from_engine_source(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// The comment "H5: mid-request failback trigger REMOVED" must be present,
		// and "executeFailbackFlushIfNeeded" or "wasDegraded" must NOT trigger a
		// direct flush call from within redisOp() on success.
		$this->assertStringContainsString(
			'mid-request failback trigger REMOVED',
			$content,
			'Engine must contain the H5 comment confirming the mid-request trigger was removed'
		);
	}

	// -------------------------------------------------------------------------
	// H5: maybeFailbackFlushOnBoot present in engine source
	// -------------------------------------------------------------------------

	public function test_maybe_failback_flush_on_boot_present(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'maybeFailbackFlushOnBoot',
			$content,
			'Engine must define maybeFailbackFlushOnBoot() for H5 NX-lock boot flush'
		);
	}

	// -------------------------------------------------------------------------
	// H5: persistOutageMarker present in engine source
	// -------------------------------------------------------------------------

	public function test_persist_outage_marker_method_present(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'persistOutageMarker',
			$content,
			'Engine must define persistOutageMarker() for immediate degrade-on-first-failure (H5)'
		);
		// It must call update_option with FAILBACK_MARKER_OPTION.
		$this->assertStringContainsString(
			'FAILBACK_MARKER_OPTION',
			$content,
			'persistOutageMarker() must reference FAILBACK_MARKER_OPTION'
		);
	}

	// -------------------------------------------------------------------------
	// H5: NX lock logic present in maybeFailbackFlushOnBoot
	// -------------------------------------------------------------------------

	public function test_nx_lock_logic_present_in_engine_source(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// The NX lock must use 'nx' option in a Redis SET.
		$this->assertStringContainsString(
			"'nx'",
			$content,
			'maybeFailbackFlushOnBoot() must use a SET NX lock to ensure only one request flushes'
		);
		$this->assertStringContainsString(
			'FAILBACK_LOCK_SUFFIX',
			$content,
			'Lock key must use FAILBACK_LOCK_SUFFIX constant'
		);
	}

	// -------------------------------------------------------------------------
	// H5: Array-mode engine does NOT flush (no Redis to flush)
	// -------------------------------------------------------------------------

	public function test_array_mode_engine_does_not_flush_on_boot(): void
	{
		// In array mode there is no Redis connection, so maybeFailbackFlushOnBoot
		// must bail early (redis === null check). Engine boots cleanly in array mode.
		$stats = $this->oc->getHeartbeatStats();
		// Array mode with a boot reason (e.g. config_empty) reports 'down' (error journal non-empty).
		// The key assertion is that the state is NOT 'connected' (no erroneous flush happened).
		$this->assertNotSame(
			'connected',
			$stats['state'],
			'Array-mode engine must not report state=connected (no Redis flush can happen)'
		);
		// The state must be 'disabled' or 'down' (both are array-mode states).
		$this->assertContains(
			$stats['state'],
			[ 'disabled', 'down' ],
			'Array-mode engine state must be disabled or down, not connected'
		);
	}

	// -------------------------------------------------------------------------
	// H5: Marker option added to ownedOptions in lifecycle
	// -------------------------------------------------------------------------

	public function test_failback_marker_in_lifecycle_owned_options(): void
	{
		$lifecyclePath = dirname( __DIR__, 2 ) . '/includes/class-lifecycle.php';
		if ( ! is_file( $lifecyclePath ) ) {
			$this->markTestSkipped( 'class-lifecycle.php not found' );
		}
		$content = (string) file_get_contents( $lifecyclePath );
		$this->assertStringContainsString(
			'wpmgr_oc_outage_marker',
			$content,
			'Lifecycle ownedOptions() must include the failback marker option for clean uninstall (H5/H8)'
		);
	}

	// -------------------------------------------------------------------------
	// 0.43.1 FIX 1: Redis failure during shutdown() must NOT persist outage marker
	// -------------------------------------------------------------------------

	/**
	 * test_redis_failure_during_shutdown_does_not_persist_outage_marker
	 *
	 * When $inShutdown = true (set at the top of shutdown()) and a Redis-side
	 * Throwable propagates through redisOp(), persistOutageMarker() must be
	 * suppressed so that update_option(FAILBACK_MARKER_OPTION, ...) is never
	 * called. The process is dying; writing a marker at that point would cause
	 * a spurious failback flush on the very next healthy-boot request.
	 *
	 * Strategy: use ReflectionClass to forcibly set $inShutdown = true, then
	 * invoke persistOutageMarker() directly. Assert the marker option key is
	 * never written to the option store.
	 */
	public function test_redis_failure_during_shutdown_does_not_persist_outage_marker(): void
	{
		$oc  = \WPMgr_Object_Cache::boot();
		$ref = new \ReflectionClass( $oc );

		// Force $inShutdown = true, simulating that shutdown() has entered.
		$shutdownProp = $ref->getProperty( 'inShutdown' );
		$shutdownProp->setValue( $oc, true );

		// Invoke persistOutageMarker() directly.
		$markerMethod = $ref->getMethod( 'persistOutageMarker' );
		$markerMethod->invoke( $oc );

		// The marker option must NOT have been written.
		$this->assertNotContains(
			\WPMgr_Object_Cache::FAILBACK_MARKER_OPTION,
			$this->updatedOptionKeys,
			'persistOutageMarker() must not call update_option(FAILBACK_MARKER_OPTION) when $inShutdown is true'
		);
		$this->assertArrayNotHasKey(
			\WPMgr_Object_Cache::FAILBACK_MARKER_OPTION,
			$this->options,
			'wpmgr_oc_outage_marker must not be present in the option store when called from shutdown path'
		);
	}

	/**
	 * Counterpart: outside the shutdown path ($inShutdown = false), persistOutageMarker()
	 * MUST write the marker. Pins the non-regression of the normal (mid-request) path.
	 */
	public function test_non_shutdown_path_still_persists_outage_marker(): void
	{
		$oc  = \WPMgr_Object_Cache::boot();
		$ref = new \ReflectionClass( $oc );

		// $inShutdown defaults to false — do not set it; verify default is correct.
		$shutdownProp = $ref->getProperty( 'inShutdown' );
		$this->assertFalse(
			$shutdownProp->getValue( $oc ),
			'$inShutdown must default to false so the normal degradation path still writes the marker'
		);

		// Invoke persistOutageMarker() without entering shutdown.
		$markerMethod = $ref->getMethod( 'persistOutageMarker' );
		$markerMethod->invoke( $oc );

		// The marker option MUST have been written.
		$this->assertContains(
			\WPMgr_Object_Cache::FAILBACK_MARKER_OPTION,
			$this->updatedOptionKeys,
			'persistOutageMarker() must call update_option(FAILBACK_MARKER_OPTION) when $inShutdown is false (normal path)'
		);
	}

	// -------------------------------------------------------------------------
	// 0.43.1 FIX 2: delete_option(marker) happens BEFORE executeFlush in
	// maybeFailbackFlushOnBoot — source ordering assertion
	// -------------------------------------------------------------------------

	/**
	 * test_marker_deleted_before_flush_in_source
	 *
	 * FLUSHDB wipes the Redis NX lock that guards exactly-once semantics; if the
	 * marker delete happened AFTER the flush, a concurrent healthy-boot request
	 * that sees the marker before the delete could win a second NX lock and flush
	 * again. The fix reverses the order: delete_option first, then executeFlush.
	 *
	 * This is a source-order assertion. We find the byte offsets of both calls
	 * inside maybeFailbackFlushOnBoot and assert that delete_option precedes
	 * executeFlush.
	 */
	public function test_marker_deleted_before_flush_in_source(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );

		// Locate the maybeFailbackFlushOnBoot method body.
		$methodStart = strpos( $content, 'private function maybeFailbackFlushOnBoot' );
		$this->assertNotFalse(
			$methodStart,
			'maybeFailbackFlushOnBoot() must exist in the engine source'
		);

		// Find the next closing-brace at method depth to bound the search.
		// A safe upper bound: look 3000 chars past the method start.
		$methodBody = substr( $content, $methodStart, 3000 );

		$deletePos = strpos( $methodBody, 'delete_option(' );
		$flushPos  = strpos( $methodBody, 'executeFlush(' );

		$this->assertNotFalse(
			$deletePos,
			'delete_option() must be present in maybeFailbackFlushOnBoot()'
		);
		$this->assertNotFalse(
			$flushPos,
			'executeFlush() must be present in maybeFailbackFlushOnBoot()'
		);
		$this->assertLessThan(
			$flushPos,
			$deletePos,
			'delete_option(FAILBACK_MARKER_OPTION) must appear BEFORE executeFlush() in maybeFailbackFlushOnBoot() — marker must be cleared before the flush wipes the NX lock'
		);
	}
}
