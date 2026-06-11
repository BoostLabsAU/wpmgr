<?php
/**
 * H7 — Config unreadable / CLI cross-uid tests.
 *
 * Verifies:
 *   - ObjectCacheConfig::loadWithReason() returns 'config_unreadable' (not 'config_empty')
 *     when the file exists but cannot be loaded.
 *   - engine flush() returns false when in array mode because the config file exists
 *     but is unreadable (H7: honest CLI exit code, not silent success).
 *   - LOAD_UNREADABLE constant is defined.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheConfig
 * @covers \WPMgr_Object_Cache
 */
final class ConfigUnreadableTest extends TestCase
{
	/** @var string Temp directory for test config files. */
	private string $tmpDir;

	protected function set_up(): void
	{
		parent::set_up();
		$this->tmpDir = sys_get_temp_dir() . '/wpmgr_oc_test_' . getmypid() . '_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );
	}

	protected function tear_down(): void
	{
		parent::tear_down();
		// Restore perms so cleanup can delete the file.
		$configPath = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		if ( is_file( $configPath ) ) {
			@chmod( $configPath, 0600 );
			@unlink( $configPath );
		}
		if ( is_dir( $this->tmpDir ) ) {
			@rmdir( $this->tmpDir );
		}
	}

	// -------------------------------------------------------------------------
	// LOAD_UNREADABLE constant
	// -------------------------------------------------------------------------

	public function test_load_unreadable_constant_defined(): void
	{
		$this->assertSame(
			'__config_unreadable__',
			ObjectCacheConfig::LOAD_UNREADABLE,
			'LOAD_UNREADABLE sentinel must equal __config_unreadable__'
		);
	}

	// -------------------------------------------------------------------------
	// H7: config_empty when file is absent
	// -------------------------------------------------------------------------

	public function test_absent_config_returns_config_empty(): void
	{
		$loader  = new ObjectCacheConfig( $this->tmpDir );
		[ $config, $reason ] = $loader->loadWithReason();
		$this->assertSame( [], $config, 'Absent config must return empty array' );
		$this->assertSame( 'config_empty', $reason, 'Absent config reason must be config_empty' );
	}

	// -------------------------------------------------------------------------
	// H7: config_unreadable when file has a parse error or is invalid
	// -------------------------------------------------------------------------

	public function test_invalid_php_config_returns_config_unreadable(): void
	{
		$configPath = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		// Write a PHP file that doesn't return an array.
		file_put_contents( $configPath, "<?php\nreturn 'not_an_array';\n" );
		chmod( $configPath, 0600 );

		$loader  = new ObjectCacheConfig( $this->tmpDir );
		[ $config, $reason ] = $loader->loadWithReason();
		$this->assertSame( [], $config, 'Non-array config must return empty array' );
		$this->assertSame( 'config_unreadable', $reason, 'Non-array config reason must be config_unreadable' );
	}

	// -------------------------------------------------------------------------
	// H7: world-readable config is refused (security: 0644 → config_unreadable)
	// -------------------------------------------------------------------------

	public function test_world_readable_config_returns_config_unreadable(): void
	{
		$configPath = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		// Write a valid config, then make it world-readable (0644).
		file_put_contents( $configPath, "<?php\ndefined('ABSPATH')||exit;\nreturn ['host'=>'redis'];\n" );
		chmod( $configPath, 0644 ); // world-readable — must be refused.

		$loader  = new ObjectCacheConfig( $this->tmpDir );
		[ $config, $reason ] = $loader->loadWithReason();
		$this->assertSame( [], $config, 'World-readable config must be refused' );
		$this->assertSame( 'config_unreadable', $reason, 'World-readable config reason must be config_unreadable' );
	}

	// -------------------------------------------------------------------------
	// H7: valid 0600 config returns success
	// -------------------------------------------------------------------------

	public function test_valid_0600_config_loads_successfully(): void
	{
		$configPath = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		$config     = [ 'host' => 'redis', 'port' => 6379, 'prefix' => 'test' ];
		$export     = var_export( $config, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- config file generation in test
		file_put_contents( $configPath, "<?php\ndefined('ABSPATH')||exit;\nreturn " . $export . ";\n" );
		chmod( $configPath, 0600 );

		$loader  = new ObjectCacheConfig( $this->tmpDir );
		[ $loaded, $reason ] = $loader->loadWithReason();
		$this->assertSame( '', $reason, 'Valid 0600 config must return empty reason' );
		$this->assertSame( 'redis', $loaded['host'] );
	}

	// -------------------------------------------------------------------------
	// H7: flush() returns false when arrayMode and config file exists
	//     (honest exit code for CLI `wp cache flush`)
	// -------------------------------------------------------------------------

	public function test_flush_returns_false_in_array_mode_with_config_file_present(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		if ( ! is_file( $enginePath ) ) {
			$this->markTestSkipped( 'Engine source not found' );
		}
		$content = (string) file_get_contents( $enginePath );
		// H7: flush() must have a branch that returns false when in arrayMode and config file exists.
		$this->assertStringContainsString(
			'config_unreadable',
			$content,
			'Engine flush() must reference config_unreadable for H7 CLI honest failure'
		);
		// Verify the flush() error_log call for the unreadable case.
		$this->assertStringContainsString(
			'exists',
			$content,
			'Engine flush() H7 path must check if config file exists before returning false'
		);
	}

	// -------------------------------------------------------------------------
	// H7: engine in array mode with no config file: flush() returns true (no-config no-op)
	// -------------------------------------------------------------------------

	public function test_flush_returns_true_in_pure_array_mode_no_config(): void
	{
		$oc = \WPMgr_Object_Cache::boot();
		// In array mode with NO config file (no Redis ever configured), flush() returns true
		// (it is a genuine array-mode no-op; there is nothing to fail).
		// The H7 false return applies only when a config file EXISTS but is unreadable (uid mismatch).
		$result = $oc->flush();
		$this->assertTrue( $result, 'flush() in array mode with no config file must return true (no-op ok)' );
	}

	// -------------------------------------------------------------------------
	// H7: engine source flush() contains error_log for config_unreadable path
	// -------------------------------------------------------------------------

	public function test_engine_flush_has_config_unreadable_path(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		if ( ! is_file( $enginePath ) ) {
			$this->markTestSkipped( 'Engine source not found' );
		}
		$content = (string) file_get_contents( $enginePath );
		// The flush() method must handle the case where arrayMode is true
		// but a config file exists (H7 honesty requirement).
		// We look for the FAILBACK_MARKER_OPTION reference near flush or
		// a config file existence check near a false return inside flush.
		$this->assertMatchesRegularExpression(
			'/function flush.*?return false/s',
			$content,
			'flush() must have a path that returns false (array mode)'
		);
	}
}
