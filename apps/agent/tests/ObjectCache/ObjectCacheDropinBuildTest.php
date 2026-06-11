<?php
/**
 * Phase A: Determinism test for the object-cache drop-in build tool.
 *
 * Verifies that:
 *   1. Running the builder twice produces byte-identical output.
 *   2. The committed artifact matches a fresh build (same discipline as sqlc).
 *   3. The generated artifact is syntactically valid PHP.
 *   4. The SIGNATURE and Version: 2.0.1 appear within the first 200 bytes.
 *   5. The breadcrumb assignment is present and sets 'v' => '2.0.1'.
 *   6. All bail gate strings are present.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller
 */
final class ObjectCacheDropinBuildTest extends TestCase
{
	private string $buildTool;
	private string $artifactPath;

	protected function set_up(): void
	{
		parent::set_up();
		$pluginRoot         = dirname( __DIR__, 2 );
		$this->buildTool    = $pluginRoot . '/tools/build-object-cache-dropin.php';
		$this->artifactPath = $pluginRoot . '/assets/wpmgr-object-cache-dropin.php';
	}

	// -------------------------------------------------------------------------
	// Determinism
	// -------------------------------------------------------------------------

	/**
	 * The builder must produce byte-identical output on two consecutive runs.
	 */
	public function test_builder_is_deterministic(): void
	{
		if ( ! is_file( $this->buildTool ) ) {
			$this->markTestSkipped( 'Build tool not found' );
		}

		$tmpA = sys_get_temp_dir() . '/wpmgr_build_det_a_' . uniqid( '', true ) . '.php';
		$tmpB = sys_get_temp_dir() . '/wpmgr_build_det_b_' . uniqid( '', true ) . '.php';

		// Run the builder twice, capturing both outputs via the PHP script's stdout.
		// We run --check against each output in a simpler way: run the builder once,
		// write to tmpA; then regenerate and compare to tmpA.
		exec( 'php ' . escapeshellarg( $this->buildTool ) . ' 2>&1', $outA, $exitA );
		if ( $exitA !== 0 ) {
			$this->fail( 'Build tool failed on first run: ' . implode( "\n", $outA ) );
		}
		$firstOutput = is_file( $this->artifactPath ) ? (string) file_get_contents( $this->artifactPath ) : '';
		file_put_contents( $tmpA, $firstOutput );

		exec( 'php ' . escapeshellarg( $this->buildTool ) . ' 2>&1', $outB, $exitB );
		if ( $exitB !== 0 ) {
			$this->fail( 'Build tool failed on second run: ' . implode( "\n", $outB ) );
		}
		$secondOutput = is_file( $this->artifactPath ) ? (string) file_get_contents( $this->artifactPath ) : '';
		file_put_contents( $tmpB, $secondOutput );

		$this->assertSame( $firstOutput, $secondOutput, 'Builder must be deterministic: two runs must produce byte-identical output' );

		@unlink( $tmpA ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
		@unlink( $tmpB ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
	}

	/**
	 * The --check mode must confirm the committed artifact matches a fresh build.
	 *
	 * If this test fails, an engine source file was modified without regenerating
	 * the artifact. Run: php tools/build-object-cache-dropin.php
	 */
	public function test_committed_artifact_matches_fresh_build(): void
	{
		if ( ! is_file( $this->buildTool ) ) {
			$this->markTestSkipped( 'Build tool not found' );
		}
		if ( ! is_file( $this->artifactPath ) ) {
			$this->fail( 'Committed artifact missing at ' . $this->artifactPath . '. Run: php tools/build-object-cache-dropin.php' );
		}

		exec( 'php ' . escapeshellarg( $this->buildTool ) . ' --check 2>&1', $out, $exit );
		$this->assertSame(
			0,
			$exit,
			'Committed artifact is STALE. An engine source was modified without regenerating. '
			. 'Run: php tools/build-object-cache-dropin.php. Output: ' . implode( "\n", $out )
		);
	}

	// -------------------------------------------------------------------------
	// Artifact structure
	// -------------------------------------------------------------------------

	/**
	 * The generated artifact must be syntactically valid PHP.
	 */
	public function test_artifact_is_valid_php(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$out  = [];
		$exit = 0;
		exec( 'php -l ' . escapeshellarg( $this->artifactPath ) . ' 2>&1', $out, $exit );
		$this->assertSame( 0, $exit, 'Generated artifact must be valid PHP: ' . implode( "\n", $out ) );
	}

	/**
	 * SIGNATURE must be in the first 200 bytes.
	 */
	public function test_signature_in_first_200_bytes(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$first200 = substr( (string) file_get_contents( $this->artifactPath ), 0, 200 );
		$this->assertStringContainsString(
			ObjectCacheDropinInstaller::SIGNATURE,
			$first200,
			'SIGNATURE must appear within the first 200 bytes'
		);
	}

	/**
	 * Version: 2.0.1 must be in the first 200 bytes.
	 */
	public function test_version_in_first_200_bytes(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$first200 = substr( (string) file_get_contents( $this->artifactPath ), 0, 200 );
		$this->assertStringContainsString(
			'Version: 2.0.1',
			$first200,
			'Version: 2.0.1 must appear within the first 200 bytes'
		);
	}

	/**
	 * The artifact must set the breadcrumb with v => '2.0.1'.
	 */
	public function test_breadcrumb_initialization_present(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString(
			"'wpmgr_oc_stub'",
			$content,
			'Breadcrumb key wpmgr_oc_stub must be present'
		);
		$this->assertStringContainsString(
			"'v' => '2.0.1'",
			$content,
			'Breadcrumb must set v => 2.0.1'
		);
	}

	/**
	 * The generated artifact must NOT contain declare(strict_types=1).
	 *
	 * The drop-in is a WordPress compatibility surface. WP core cache.php is
	 * loose-typed; callers may pass int as $group (e.g. Action Scheduler calls
	 * wp_cache_set($key, $val, 3600)). Strict types would cause TypeError fatals
	 * on valid WordPress caller code.
	 */
	public function test_artifact_has_no_strict_types_declaration(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringNotContainsString(
			'declare(strict_types',
			$content,
			'Generated artifact must NOT contain declare(strict_types=1) — it is a WP compat surface with loose callers'
		);
	}

	/**
	 * All bail gate strings must be present in the artifact.
	 */
	public function test_bail_gate_strings_present(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString( "'php_floor'", $content, 'php_floor bail must be present' );
		$this->assertStringContainsString( "'installing'", $content, 'installing bail must be present' );
		$this->assertStringContainsString( "'killswitch'", $content, 'killswitch bail must be present' );
		$this->assertStringContainsString( "'engine_inline'", $content, 'engine_inline success path must be present' );
	}

	/**
	 * The artifact must contain class_exists guards for double-inclusion safety.
	 */
	public function test_class_exists_guards_present(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString(
			"class_exists( 'WPMgr\\\\Agent\\\\ObjectCache\\\\ObjectCacheConfig', false )",
			$content,
			'ObjectCacheConfig class_exists guard must be present'
		);
		$this->assertStringContainsString(
			"class_exists( 'WPMgr_Object_Cache', false )",
			$content,
			'WPMgr_Object_Cache class_exists guard must be present'
		);
	}

	/**
	 * The artifact must NOT contain the old locator probe code (engine path
	 * resolution stubs) — the self-contained model has no external require_once.
	 */
	public function test_no_engine_path_resolution_in_artifact(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringNotContainsString(
			'wpmgr_oc_engine',
			$content,
			'Generated artifact must not contain the old path-resolution variable'
		);
		$this->assertStringNotContainsString(
			'__WPMGR_OC_ENGINE_PATH__',
			$content,
			'Generated artifact must not contain the old placeholder token'
		);
	}

	/**
	 * The three engine classes must all be present in the artifact.
	 */
	public function test_all_engine_classes_inlined(): void
	{
		if ( ! is_file( $this->artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}
		$content = (string) file_get_contents( $this->artifactPath );
		$this->assertStringContainsString( 'class ObjectCacheConfig', $content, 'ObjectCacheConfig must be inlined' );
		$this->assertStringContainsString( 'class RedisConnection', $content, 'RedisConnection must be inlined' );
		$this->assertStringContainsString( 'class WPMgr_Object_Cache', $content, 'WPMgr_Object_Cache must be inlined' );
	}
}
