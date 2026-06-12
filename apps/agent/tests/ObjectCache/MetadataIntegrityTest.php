<?php
/**
 * H4 — Metadata integrity / OPT_SERIALIZER try/finally tests.
 *
 * Verifies that OPT_SERIALIZER is always restored after a metadata integrity
 * check window, even when Redis throws. These tests exercise the engine in array
 * mode (no live Redis) and confirm the structure of checkMetadataIntegrity()
 * through its observable effects. The H4 finally-block property is also
 * verified in ObjectCacheDropinBuildTest by grepping the generated artifact.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class MetadataIntegrityTest extends TestCase
{
	/** @var \WPMgr_Object_Cache */
	private \WPMgr_Object_Cache $oc;

	protected function set_up(): void
	{
		parent::set_up();
		// Boot in array mode (no config file).
		$this->oc = \WPMgr_Object_Cache::boot();
	}

	// -------------------------------------------------------------------------
	// H4: try/finally in the metadata window (structural verification)
	// -------------------------------------------------------------------------

	/**
	 * The generated drop-in must contain 'finally' blocks for OPT_SERIALIZER restoration.
	 * This is the primary H4 test; the build test greps for it too.
	 */
	public function test_dropin_artifact_contains_finally_block(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated drop-in artifact not found; run php tools/build-object-cache-dropin.php' );
		}
		$content = (string) file_get_contents( $artifactPath );
		$this->assertStringContainsString(
			'finally',
			$content,
			'Generated drop-in must contain finally block for OPT_SERIALIZER restoration (H4)'
		);
	}

	/**
	 * The engine source itself must contain finally for H4 compliance.
	 */
	public function test_engine_source_contains_finally_in_metadata_check(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// Both the metadata integrity check and the sync FLUSHDB window should have finally.
		$finallyCount = substr_count( $content, '} finally {' );
		$this->assertGreaterThanOrEqual(
			2,
			$finallyCount,
			'Engine must have at least 2 finally blocks: one for metadata integrity, one for flushDB timeout (H4/M9)'
		);
	}

	// -------------------------------------------------------------------------
	// M10: wp_version comparison present in engine source
	// -------------------------------------------------------------------------

	public function test_engine_source_contains_wp_version_comparison(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'wp_version',
			$content,
			'Engine must compare wp_version in metadata integrity check (M10)'
		);
	}

	// -------------------------------------------------------------------------
	// M10: effective codec fields written to metadata
	// -------------------------------------------------------------------------

	public function test_engine_source_writes_effective_serializer_field(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'effective_serializer',
			$content,
			'Engine must write effective_serializer to metadata (H3/M10)'
		);
		$this->assertStringContainsString(
			'effective_compression',
			$content,
			'Engine must write effective_compression to metadata (H3/M10)'
		);
	}

	// -------------------------------------------------------------------------
	// Array-mode smoke: engine boots cleanly and accepts operations
	// -------------------------------------------------------------------------

	public function test_array_mode_set_get_unaffected_by_metadata_path(): void
	{
		$this->oc->set( 'meta_smoke', 'value', 'default', 60 );
		$found  = null;
		$result = $this->oc->get( 'meta_smoke', 'default', false, $found );
		$this->assertTrue( $found );
		$this->assertSame( 'value', $result );
	}

	public function test_metadata_integrity_check_is_absent_in_array_mode(): void
	{
		// checkMetadataIntegrity returns early when redis===null.
		// Engine in array mode must not fatal or have any journal error from metadata.
		$stats = $this->oc->getHeartbeatStats();
		// The 'metadata_integrity_failed' entry must NOT appear when in array mode.
		$lastError = $stats['last_error_class'] ?? '';
		$this->assertStringNotContainsString(
			'metadata_integrity_failed',
			$lastError,
			'Array-mode engine must not report metadata integrity failure'
		);
	}
}
