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
}
