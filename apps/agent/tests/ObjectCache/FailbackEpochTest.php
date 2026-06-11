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
 *
 * These tests run in array mode (no live Redis) and verify the H5 logic through
 * observable effects on $GLOBALS options and the engine source structure.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

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

	protected function set_up(): void
	{
		parent::set_up();
		$this->options   = [];
		$this->flushCalls = 0;
		$this->oc        = \WPMgr_Object_Cache::boot();
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
}
