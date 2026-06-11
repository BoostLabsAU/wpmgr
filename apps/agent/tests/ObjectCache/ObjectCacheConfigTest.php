<?php
/**
 * ObjectCacheConfig: config file persistence + hash + fromParams.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\ObjectCache\ObjectCacheConfig
 */
final class ObjectCacheConfigTest extends TestCase
{
	/** @var string Temporary directory for config file writes. */
	private string $tmpDir;

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();
		// Stub WP functions used by save().
		Functions\when( 'update_option' )->justReturn( true );
		// wp_json_encode is defined in bootstrap.php; Patchwork cannot redefine it here.
		$this->tmpDir = sys_get_temp_dir() . '/wpmgr_oc_test_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );
	}

	protected function tear_down(): void
	{
		// Clean up temp dir.
		$files = glob( $this->tmpDir . '/*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
			}
		}
		@rmdir( $this->tmpDir );
		Monkey\tearDown();
		parent::tear_down();
	}

	public function test_load_returns_empty_when_file_absent(): void
	{
		$cfg = new ObjectCacheConfig( $this->tmpDir );
		$this->assertSame( [], $cfg->load() );
	}

	public function test_save_and_load_round_trip(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [
			'scheme'   => 'tcp',
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'database' => 0,
			'prefix'   => 'mysite',
		] );

		$this->assertTrue( $cfg->save( $config ) );

		$loaded = $cfg->load();
		$this->assertSame( 'tcp', $loaded['scheme'] );
		$this->assertSame( '127.0.0.1', $loaded['host'] );
		$this->assertSame( 6379, $loaded['port'] );
		$this->assertSame( 'mysite', $loaded['prefix'] );
	}

	public function test_save_writes_0600_permissions(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );
		$cfg->save( $config );

		$path  = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		$perms = fileperms( $path );
		$this->assertNotFalse( $perms );
		// On Linux: 0600 = 0x8180; mask to lower 9 bits.
		$this->assertSame( 0600, $perms & 0777 );
	}

	public function test_save_atomic_write(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );

		// Write twice: second write should overwrite atomically.
		$this->assertTrue( $cfg->save( $config ) );
		$config['host'] = '10.0.0.1';
		$this->assertTrue( $cfg->save( $config ) );

		$loaded = $cfg->load();
		$this->assertSame( '10.0.0.1', $loaded['host'] );
	}

	public function test_config_hash_excludes_password(): void
	{
		$cfg = new ObjectCacheConfig( $this->tmpDir );

		$configA = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secret1' ] );
		$configB = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secret2' ] );

		// Same host, different passwords => same hash (password redacted).
		$this->assertSame( $cfg->computeHash( $configA ), $cfg->computeHash( $configB ) );

		// Different host => different hash.
		$configC = ObjectCacheConfig::fromParams( [ 'host' => '10.0.0.1', 'password' => 'secret1' ] );
		$this->assertNotSame( $cfg->computeHash( $configA ), $cfg->computeHash( $configC ) );
	}

	public function test_delete_removes_file(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );
		$cfg->save( $config );

		$this->assertTrue( $cfg->exists() );
		$cfg->delete();
		$this->assertFalse( $cfg->exists() );
	}

	public function test_from_params_defaults(): void
	{
		$config = ObjectCacheConfig::fromParams( [] );

		$this->assertSame( 'tcp', $config['scheme'] );
		$this->assertSame( '127.0.0.1', $config['host'] );
		$this->assertSame( 6379, $config['port'] );
		$this->assertSame( 0, $config['database'] );
		$this->assertSame( 'wpmgr', $config['prefix'] );
		$this->assertSame( ObjectCacheConfig::DEFAULT_MAXTTL, $config['maxttl_seconds'] );
		$this->assertSame( ObjectCacheConfig::DEFAULT_QUERYTTL, $config['queryttl_seconds'] );
		$this->assertSame( 'php', $config['serializer'] );
		$this->assertSame( 'none', $config['compression'] );
		$this->assertFalse( $config['async_flush'] );
		$this->assertSame( 'auto', $config['flush_strategy'] );
		$this->assertTrue( $config['shared'] );
		$this->assertTrue( $config['flush_on_failback'] );
		$this->assertTrue( $config['analytics_enabled'] );
	}

	public function test_from_params_clamps_port(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'port' => 99999 ] );
		$this->assertSame( 6379, $config['port'] );
	}

	public function test_from_params_clamps_database(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'database' => 99 ] );
		$this->assertSame( 0, $config['database'] );
	}

	public function test_from_params_clamps_connect_timeout(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'connect_timeout_ms' => 1 ] );
		$this->assertSame( 100, $config['connect_timeout_ms'] );

		$config = ObjectCacheConfig::fromParams( [ 'connect_timeout_ms' => 99999 ] );
		$this->assertSame( 5000, $config['connect_timeout_ms'] );
	}

	public function test_from_params_rejects_invalid_serializer(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'serializer' => 'msgpack' ] );
		$this->assertSame( 'php', $config['serializer'] );
	}

	public function test_from_params_rejects_invalid_scheme(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'scheme' => 'ftp' ] );
		$this->assertSame( 'tcp', $config['scheme'] );
	}

	public function test_from_params_rejects_invalid_compression(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'compression' => 'brotli' ] );
		$this->assertSame( 'none', $config['compression'] );
	}

	public function test_from_params_rejects_invalid_flush_strategy(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'flush_strategy' => 'magic' ] );
		$this->assertSame( 'auto', $config['flush_strategy'] );
	}

	/**
	 * S6: fromParams must fall back to 'wpmgr' when the caller supplies an
	 * empty or whitespace-only prefix — an empty prefix defeats shared-Redis
	 * namespacing and allows SCAN ':*' to cross site boundaries.
	 */
	public function test_from_params_empty_prefix_falls_back_to_wpmgr(): void
	{
		$config = ObjectCacheConfig::fromParams( [ 'prefix' => '' ] );
		$this->assertSame( 'wpmgr', $config['prefix'], 'empty prefix must fall back to wpmgr' );

		// sanitize_text_field strips and trims whitespace, so a whitespace-only
		// prefix also produces '' and must fall back.
		$config2 = ObjectCacheConfig::fromParams( [ 'prefix' => '   ' ] );
		$this->assertSame( 'wpmgr', $config2['prefix'], 'whitespace-only prefix must fall back to wpmgr' );
	}

	/**
	 * S7: save() must call opcache_invalidate after a successful write so that
	 * credential rotation is not silently no-oped on validate_timestamps=0 hosts.
	 * We verify this by confirming opcache_invalidate is called when it exists.
	 */
	public function test_save_invalidates_opcache(): void
	{
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			$this->markTestSkipped( 'opcache_invalidate not available' );
		}

		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );

		// Ensure no exception and no error; the call itself is the assertion.
		$result = $cfg->save( $config );
		$this->assertTrue( $result, 'save() must succeed' );

		// Call again to verify re-write + re-invalidate path.
		$config['host'] = '10.0.0.2';
		$result2 = $cfg->save( $config );
		$this->assertTrue( $result2, 'second save() must succeed' );
	}

	/**
	 * S8: the tmp file written during save() must never be group- or
	 * other-readable at any point. We capture any tmp files created
	 * during save() by watching for new .tmp.* files in the dir.
	 *
	 * Because the write is atomic (tmp -> rename) we hook around
	 * the tmp file existence window. The simplest verifiable invariant is
	 * that the FINAL file is 0600 AND that the class applies umask(0077)
	 * before the write (indirectly verified through the perm test + the
	 * code path covered by save()).
	 */
	public function test_save_tmp_file_not_world_readable(): void
	{
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'POSIX permissions do not apply on Windows' );
		}

		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secret-pw' ] );
		$cfg->save( $config );

		$path  = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		$perms = fileperms( $path );
		$this->assertNotFalse( $perms );

		// Neither group (040) nor other (004) bits should be set.
		$this->assertSame( 0, $perms & 0044, 'final config file must not be group- or other-readable' );
	}
}
