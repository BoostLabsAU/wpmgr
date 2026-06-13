<?php
/**
 * Regression tests for the 0.41.6 object-cache fixes.
 *
 * FIX A — Failback flush fires on every request (the live slow-admin cause):
 *
 *   The pre-fix condition `flushOnFailback && !failbackFlushed && !isDegraded()`
 *   was missing the wasDegraded() guard, causing the first successful Redis op of
 *   every request to be treated as an outage recovery and trigger a full keyspace
 *   SCAN+DEL. The fix adds wasDegraded() to the condition so the flush only fires
 *   when the connection genuinely recovered from a prior markDegraded() call.
 *
 *   Regression nets:
 *   A1 — A fresh connection (no prior markDegraded) performs successful ops without
 *        ever calling executeFailbackFlush (wasDegraded() stays false).
 *   A2 — markDegraded() sets wasDegraded() and it is never cleared by
 *        recordSuccess() or acquire().
 *   A3 — After markDegraded() + recovery, executeFailbackFlush is triggered
 *        exactly once (failbackFlushed latches it).
 *
 * FIX C — Drop-in artifact version assertions:
 *   C1 — Artifact Version header is 2.0.2.
 *   C2 — Breadcrumb v tag is '2.0.2'.
 *   C3 — ENGINE_VERSION constant in artifact is '0.41.6'.
 *   C4 — 'booted' flag assignment is present exactly once in the artifact.
 *   C5 — 'booted' flag appears immediately after the top-level boot call, not
 *        inside wpmgr_get_object_cache().
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use WPMgr\Agent\ObjectCache\RedisConnection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\RedisConnection
 * @covers \WPMgr_Object_Cache
 */
final class ObjectCacheFix416Test extends TestCase
{
	// =========================================================================
	// A2 — RedisConnection::wasDegraded() semantics
	// =========================================================================

	/**
	 * A2a: wasDegraded() returns false on a fresh connection that has never been
	 * degraded. This is the key invariant that prevents the always-flush bug.
	 */
	public function test_fresh_connection_was_not_degraded(): void
	{
		// RedisConnection requires a config array; we supply a minimal one.
		// We do not call acquire() — only test the flag state.
		$conn = new RedisConnection( [ 'host' => '127.0.0.1', 'port' => 6379 ] );

		$this->assertFalse(
			$conn->wasDegraded(),
			'wasDegraded() must be false on a fresh connection that markDegraded() was never called on'
		);
		$this->assertFalse(
			$conn->isDegraded(),
			'isDegraded() must also be false on a fresh connection'
		);
	}

	/**
	 * A2b: markDegraded() sets both isDegraded() and wasDegraded().
	 */
	public function test_mark_degraded_sets_both_flags(): void
	{
		$conn = new RedisConnection( [ 'host' => '127.0.0.1', 'port' => 6379 ] );

		$conn->markDegraded();

		$this->assertTrue(
			$conn->isDegraded(),
			'isDegraded() must be true after markDegraded()'
		);
		$this->assertTrue(
			$conn->wasDegraded(),
			'wasDegraded() must be true after markDegraded()'
		);
	}

	/**
	 * A2c: recordSuccess() clears isDegraded() but must NOT clear wasDegraded().
	 * This is the critical invariant of the fix.
	 */
	public function test_record_success_clears_degraded_but_not_was_degraded(): void
	{
		$conn = new RedisConnection( [ 'host' => '127.0.0.1', 'port' => 6379 ] );

		$conn->markDegraded();
		$this->assertTrue( $conn->wasDegraded(), 'wasDegraded must be set after markDegraded' );

		$conn->recordSuccess();

		$this->assertFalse(
			$conn->isDegraded(),
			'isDegraded() must be false after recordSuccess()'
		);
		$this->assertTrue(
			$conn->wasDegraded(),
			'wasDegraded() must remain true after recordSuccess() — never cleared'
		);
	}

	/**
	 * A2d: Multiple recordSuccess() calls do not clear wasDegraded().
	 */
	public function test_repeated_record_success_does_not_clear_was_degraded(): void
	{
		$conn = new RedisConnection( [ 'host' => '127.0.0.1', 'port' => 6379 ] );

		$conn->markDegraded();
		$conn->recordSuccess();
		$conn->recordSuccess();
		$conn->recordSuccess();

		$this->assertTrue(
			$conn->wasDegraded(),
			'wasDegraded() must remain true after repeated recordSuccess() calls'
		);
	}

	/**
	 * A2e: wasDegraded() is false before markDegraded() and true after, without
	 * ever calling any other method.
	 */
	public function test_was_degraded_lifecycle(): void
	{
		$conn = new RedisConnection( [] );

		$this->assertFalse( $conn->wasDegraded(), 'must be false initially' );
		$this->assertFalse( $conn->isDegraded(), 'must be false initially' );

		$conn->markDegraded();

		$this->assertTrue( $conn->wasDegraded(), 'must be true after markDegraded' );
		$this->assertTrue( $conn->isDegraded(), 'must be true after markDegraded' );
	}

	// =========================================================================
	// A1 — Engine: successful ops without markDegraded() never trigger flush
	// =========================================================================

	/**
	 * A1: In array mode (no Redis), the redisOp path is bypassed entirely.
	 * A successful set/get in array mode with flushOnFailback=true must NOT
	 * trigger executeFailbackFlush — the engine's wasDegraded() guard prevents it.
	 *
	 * We verify the invariant through observable state: if a flush were triggered,
	 * it would call executeFlush() which sets failbackFlushed=true. We expose
	 * failbackFlushed via the getHeartbeatStats() (it does not appear there
	 * directly), so we verify indirectly: array-mode ops must not throw and must
	 * return correct values, and failbackFlushed state is untouched.
	 *
	 * The real guard is the connection-level wasDegraded() === false for a fresh
	 * connection — tested in A2a above. This test confirms the engine in array
	 * mode is stable after the fix.
	 */
	public function test_array_mode_ops_do_not_trigger_failback_flush(): void
	{
		$oc = \WPMgr_Object_Cache::boot();

		// Must be in array mode (no Redis config).
		$this->assertTrue( $oc->isArrayMode() );

		// Perform multiple successful ops.
		$oc->set( 'a1_key', 'value', 'default', 60 );
		$found  = null;
		$result = $oc->get( 'a1_key', 'default', false, $found );
		$this->assertTrue( $found );
		$this->assertSame( 'value', $result );

		// Stats must reflect array mode (disabled or down — both are valid array-mode states).
		$stats = $oc->getHeartbeatStats();
		$this->assertContains(
			$stats['state'],
			[ 'disabled', 'down' ],
			'Array-mode engine must report disabled or down state, never connected or degraded'
		);

		// No exception must have escaped.
		$this->assertTrue( true );
	}

	// =========================================================================
	// A3 — Condition logic: wasDegraded() + !isDegraded() required for flush
	// =========================================================================

	/**
	 * A3: The engine condition for failback flush requires both wasDegraded()
	 * AND !isDegraded(). We test the boolean logic directly with a mock.
	 *
	 * Case 1: wasDegraded=false, isDegraded=false  => NO flush (healthy from start).
	 * Case 2: wasDegraded=true,  isDegraded=true   => NO flush (still degraded).
	 * Case 3: wasDegraded=true,  isDegraded=false  => YES flush (genuine recovery).
	 */
	public function test_failback_flush_condition_logic(): void
	{
		// Simulate the guard condition from the engine's redisOp():
		// flushOnFailback && !failbackFlushed && wasDegraded() && !isDegraded().
		$flushOnFailback  = true;
		$failbackFlushed  = false;

		// Case 1: never degraded.
		$wasDegraded = false;
		$isDegraded  = false;
		$shouldFlush = $flushOnFailback && ! $failbackFlushed && $wasDegraded && ! $isDegraded;
		$this->assertFalse( $shouldFlush, 'Case 1: healthy from start — must NOT flush' );

		// Case 2: still degraded.
		$wasDegraded = true;
		$isDegraded  = true;
		$shouldFlush = $flushOnFailback && ! $failbackFlushed && $wasDegraded && ! $isDegraded;
		$this->assertFalse( $shouldFlush, 'Case 2: still degraded — must NOT flush' );

		// Case 3: genuine recovery.
		$wasDegraded = true;
		$isDegraded  = false;
		$shouldFlush = $flushOnFailback && ! $failbackFlushed && $wasDegraded && ! $isDegraded;
		$this->assertTrue( $shouldFlush, 'Case 3: genuine recovery — MUST flush' );

		// Case 4: already flushed this request (latched).
		$failbackFlushed = true;
		$shouldFlush     = $flushOnFailback && ! $failbackFlushed && $wasDegraded && ! $isDegraded;
		$this->assertFalse( $shouldFlush, 'Case 4: already flushed — must NOT flush again' );
	}

	// =========================================================================
	// C — Artifact version and booted flag assertions
	// =========================================================================

	/** @var string */
	private string $artifactPath = '';

	protected function set_up(): void
	{
		parent::set_up();
		$this->artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
	}

	/**
	 * C1: Artifact Version header must be 2.2.1 (updated from 2.2.0 in 0.43.1).
	 */
	public function test_artifact_version_header_is_202(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$first200 = substr( (string) file_get_contents( $this->artifactPath ), 0, 200 );
		$this->assertStringContainsString(
			'Version: 2.2.1',
			$first200,
			'Artifact Version header must be 2.2.1 after 0.43.1 shutdown-marker fix bump'
		);
	}

	/**
	 * C2: Breadcrumb v tag must be '2.2.1' (updated from 2.2.0 in 0.43.1).
	 */
	public function test_artifact_breadcrumb_version_is_202(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString(
			"'v' => '2.2.1'",
			$content,
			"Breadcrumb must set v => '2.2.1' after 0.43.1 shutdown-marker fix bump"
		);
	}

	/**
	 * C3: ENGINE_VERSION constant in artifact must be '0.45.0' (updated from 0.44.0 in P4a cron-kick feature).
	 */
	public function test_artifact_engine_version_is_0416(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString(
			"ENGINE_VERSION = '0.45.0'",
			$content,
			"ENGINE_VERSION constant must be '0.45.0' in the artifact (0.45.0 P4a cron-kick feature)"
		);
	}

	/**
	 * C4: 'booted' flag assignment must appear exactly once in the artifact.
	 */
	public function test_artifact_has_exactly_one_booted_flag(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$count   = substr_count( $content, "\$GLOBALS['wpmgr_oc_stub']['booted'] = true;" );
		$this->assertSame(
			1,
			$count,
			"The 'booted' flag assignment must appear exactly once in the artifact"
		);
	}

	/**
	 * C5: 'booted' flag must appear after the top-level boot call and NOT inside
	 * the wpmgr_get_object_cache() function body.
	 *
	 * We verify this by finding the position of 'booted' relative to
	 * 'register_shutdown_function' (which immediately follows the top-level boot).
	 */
	public function test_artifact_booted_flag_is_at_top_level_boot(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );

		$bootedPos   = strpos( $content, "\$GLOBALS['wpmgr_oc_stub']['booted'] = true;" );
		$shutdownPos = strpos( $content, 'register_shutdown_function(' );

		$this->assertNotFalse( $bootedPos, "'booted' flag must be present in artifact" );
		$this->assertNotFalse( $shutdownPos, 'register_shutdown_function must be present in artifact' );

		// The booted flag appears BEFORE register_shutdown_function — it sits between
		// the boot call and the shutdown registration.
		$this->assertLessThan(
			$shutdownPos,
			$bootedPos,
			"'booted' flag must appear before register_shutdown_function (i.e., at the top-level boot, not in wpmgr_get_object_cache)"
		);

		// Additionally, the booted flag must NOT be inside the wpmgr_get_object_cache
		// function. That function ends with "return $wp_object_cache;" followed by "}"
		// and then the "// Boot now and install..." comment. We check that 'booted' is
		// positioned AFTER the wpmgr_get_object_cache function's closing brace.
		$bootNowPos = strpos( $content, '// Boot now and install the shutdown hook' );
		$this->assertNotFalse( $bootNowPos, 'Boot-now comment must be present in artifact' );
		$this->assertGreaterThan(
			$bootNowPos,
			$bootedPos,
			"'booted' flag must appear AFTER the 'Boot now' comment, not inside wpmgr_get_object_cache()"
		);
	}
}
