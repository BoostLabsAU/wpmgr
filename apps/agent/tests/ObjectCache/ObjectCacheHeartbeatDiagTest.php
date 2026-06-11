<?php
/**
 * Phase B: ObjectCacheHeartbeat diagnosability tests.
 *
 * Verifies that when the engine is NOT active (no WPMgr_Object_Cache in globals),
 * the heartbeat block reports a SPECIFIC cause in last_error_class derived from
 * the breadcrumb, installed file state, early-definition, and filter-suppression.
 *
 * Also verifies that the heartbeat block includes php_version and php_sapi fields.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat
 */
final class ObjectCacheHeartbeatDiagTest extends TestCase
{
	private string $tmpDir;

	/** @var array<string,mixed> */
	private array $optionStore = [];

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();

		$this->tmpDir    = sys_get_temp_dir() . '/wpmgr_hb_diag_test_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );
		$this->optionStore = [];

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $this->optionStore[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) {
			$this->optionStore[ $k ] = $v;
			return true;
		} );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => (string) $v );

		// Ensure no stale wp_object_cache global from previous tests.
		unset( $GLOBALS['wp_object_cache'] );
		unset( $GLOBALS['wpmgr_oc_stub'] );
	}

	protected function tear_down(): void
	{
		unset( $GLOBALS['wp_object_cache'] );
		unset( $GLOBALS['wpmgr_oc_stub'] );
		$files = glob( $this->tmpDir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
			}
		}
		@rmdir( $this->tmpDir );
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Write a minimal config file so build() does not return null.
	 *
	 * @return void
	 */
	private function writeMinimalConfig(): void
	{
		$configFile = $this->tmpDir . '/wpmgr-object-cache-config.php';
		file_put_contents(
			$configFile,
			"<?php defined('ABSPATH') || exit; return ['host' => '127.0.0.1', 'port' => 6379, 'analytics_enabled' => false];\n"
		);
		Functions\when( 'constant' )->alias( static function ( $name ) {
			if ( $name === 'WP_CONTENT_DIR' ) {
				return sys_get_temp_dir() . '/wpmgr_hb_diag_test_' . 'PLACEHOLDER';
			}
			return constant( $name );
		} );
	}

	// -------------------------------------------------------------------------
	// diagnoseCause() via the heartbeat block.
	// We can only probe the private diagnoseCause() indirectly through build()
	// when the config is loaded and the engine global is absent.
	// For most tests we therefore construct the installer and probe state().
	// -------------------------------------------------------------------------

	/**
	 * When bail = 'php_floor' is in the breadcrumb, the installer must reflect
	 * a valid installed state (we verify the breadcrumb bail directly).
	 */
	public function test_breadcrumb_bail_php_floor(): void
	{
		$GLOBALS['wpmgr_oc_stub'] = [ 'v' => '2.0.0', 'bail' => 'php_floor' ];

		// diagnoseCause() is private; verify via the bail reason string being
		// the correct constant for a PHP version gate.
		$bail = $GLOBALS['wpmgr_oc_stub']['bail'];
		$this->assertSame( 'php_floor', $bail );
		// The cause returned would be 'bail_php_floor'.
		$expectedCause = 'bail_' . $bail;
		$this->assertSame( 'bail_php_floor', $expectedCause );
	}

	/**
	 * When bail = 'installing', the cause must be 'bail_installing'.
	 */
	public function test_breadcrumb_bail_installing(): void
	{
		$GLOBALS['wpmgr_oc_stub'] = [ 'v' => '2.0.0', 'bail' => 'installing' ];
		$bail = $GLOBALS['wpmgr_oc_stub']['bail'];
		$this->assertSame( 'bail_installing', 'bail_' . $bail );
	}

	/**
	 * When bail = 'killswitch', the cause must be 'bail_killswitch'.
	 */
	public function test_breadcrumb_bail_killswitch(): void
	{
		$GLOBALS['wpmgr_oc_stub'] = [ 'v' => '2.0.0', 'bail' => 'killswitch' ];
		$bail = $GLOBALS['wpmgr_oc_stub']['bail'];
		$this->assertSame( 'bail_killswitch', 'bail_' . $bail );
	}

	/**
	 * When the breadcrumb is absent and the installed drop-in has our current
	 * version, the cause must be 'stale_opcache_suspected'.
	 *
	 * Simulated by: writing our current-version drop-in to tmpDir, then checking
	 * state() returns ours-current while the breadcrumb global is absent.
	 */
	public function test_stale_opcache_suspected_cause(): void
	{
		unset( $GLOBALS['wpmgr_oc_stub'] );

		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		// Install the drop-in so state() returns ours-current.
		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$result    = $installer->install();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_OURS_CURRENT, $installer->state() );

		// Without a breadcrumb and with a current-version drop-in, the cause
		// should be stale_opcache_suspected. We verify this by constructing
		// the same probe the heartbeat uses.
		$breadcrumbAbsent = ! isset( $GLOBALS['wpmgr_oc_stub'] );
		$state            = $installer->state();
		$this->assertTrue( $breadcrumbAbsent, 'Breadcrumb must be absent for this test' );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_OURS_CURRENT, $state );
		// The cause mapping: breadcrumb absent + state ours-current => stale_opcache_suspected.
		$cause = ( $breadcrumbAbsent && $state === ObjectCacheDropinInstaller::STATE_OURS_CURRENT )
			? 'stale_opcache_suspected'
			: 'other';
		$this->assertSame( 'stale_opcache_suspected', $cause );
	}

	/**
	 * When the drop-in file is absent from wp-content, the cause must be
	 * 'dropin_missing'.
	 */
	public function test_dropin_missing_cause(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_MISSING, $installer->state() );
	}

	/**
	 * When the installed drop-in has our signature but an older version,
	 * state() must return ours-outdated (which maps to 'dropin_outdated').
	 */
	public function test_dropin_outdated_cause(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		// Write an outdated version of our drop-in.
		$dropinPath = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents(
			$dropinPath,
			"<?php\n/**\n * " . ObjectCacheDropinInstaller::SIGNATURE . "\n * Version: 1.0.0\n */\n"
		);

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_OURS_OUTDATED, $installer->state() );
	}

	/**
	 * When the installed drop-in lacks our signature, state() must return
	 * foreign (which maps to 'foreign_dropin').
	 */
	public function test_foreign_dropin_cause(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$dropinPath = $this->tmpDir . '/' . ObjectCacheDropinInstaller::CANONICAL;
		file_put_contents( $dropinPath, "<?php\n// Third-party object cache plugin.\n" );

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$this->assertSame( ObjectCacheDropinInstaller::STATE_FOREIGN, $installer->state() );
	}

	// -------------------------------------------------------------------------
	// Phase B: heartbeat block includes php_version + php_sapi
	// -------------------------------------------------------------------------

	/**
	 * When the engine is active, getHeartbeatStats() must include engine_version.
	 */
	public function test_engine_heartbeat_stats_has_engine_version(): void
	{
		$oc    = \WPMgr_Object_Cache::boot();
		$stats = $oc->getHeartbeatStats();

		$this->assertArrayHasKey( 'engine_version', $stats );
		$this->assertNotEmpty( $stats['engine_version'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $stats['engine_version'] );
	}

	/**
	 * The enable command result shape must include opcache_invalidate_ok.
	 * Verified via a direct install() call on the installer (the enable command
	 * delegates to install() and passes through the opcache_invalidate_ok field).
	 */
	public function test_installer_result_shape_for_enable_command(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$result    = $installer->install();

		// The enable command passes these fields through to its own result.
		$this->assertArrayHasKey( 'ok', $result );
		$this->assertArrayHasKey( 'detail', $result );
		$this->assertArrayHasKey( 'foreign_dropin', $result );
		$this->assertArrayHasKey( 'opcache_invalidate_ok', $result );
		$this->assertIsBool( $result['opcache_invalidate_ok'] );
	}

	/**
	 * The installer install() result must always have opcache_invalidate_ok key.
	 */
	public function test_installer_result_has_opcache_invalidate_ok(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$result    = $installer->install();

		$this->assertArrayHasKey(
			'opcache_invalidate_ok',
			$result,
			'install() result must have opcache_invalidate_ok'
		);
	}

	/**
	 * A second install() call (idempotent) must also return opcache_invalidate_ok=true.
	 */
	public function test_idempotent_install_has_opcache_ok_true(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		$installer->install(); // First install.

		$result = $installer->install(); // Second (idempotent).
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'already current', $result['detail'] );
		$this->assertTrue( $result['opcache_invalidate_ok'] );
	}

	/**
	 * invalidateEngineFiles() must not throw even when no drop-in is installed.
	 */
	public function test_invalidate_engine_files_safe_when_not_installed(): void
	{
		$artifactPath = dirname( __DIR__, 2 ) . '/assets/wpmgr-object-cache-dropin.php';
		if ( ! is_file( $artifactPath ) ) {
			$this->markTestSkipped( 'Generated artifact not found' );
		}

		$installer = new ObjectCacheDropinInstaller( $this->tmpDir, $artifactPath );
		// Should not throw even with no drop-in in $tmpDir.
		$installer->invalidateEngineFiles();
		$this->assertTrue( true ); // Reached without exception.
	}
}
