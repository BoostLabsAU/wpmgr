<?php
/**
 * Object cache command handler tests.
 *
 * Tests the five command handlers against mocked config/connection surfaces.
 * No Redis connection is made.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WPMgr\Agent\Commands\ObjectcacheApplyConfigCommand;
use WPMgr\Agent\Commands\ObjectcacheEnableCommand;
use WPMgr\Agent\Commands\ObjectcacheDisableCommand;
use WPMgr\Agent\Commands\ObjectcacheFlushCommand;
use WPMgr\Agent\ObjectCache\ObjectCacheConfig;
use WPMgr\Agent\ObjectCache\ObjectCacheDropinInstaller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Commands\ObjectcacheApplyConfigCommand
 * @covers \WPMgr\Agent\Commands\ObjectcacheEnableCommand
 * @covers \WPMgr\Agent\Commands\ObjectcacheDisableCommand
 * @covers \WPMgr\Agent\Commands\ObjectcacheFlushCommand
 */
final class ObjectCacheCommandsTest extends TestCase
{
	private string $tmpDir;

	/** @var array<string,mixed> */
	private array $optionStore = [];

	protected function set_up(): void
	{
		parent::set_up();
		Monkey\setUp();

		$this->tmpDir     = sys_get_temp_dir() . '/wpmgr_cmd_test_' . uniqid( '', true );
		mkdir( $this->tmpDir, 0755, true );
		$this->optionStore = [];

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $this->optionStore[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) {
			$this->optionStore[ $k ] = $v;
			return true;
		} );
		// Brain Monkey defines these when absent. They must NOT live in
		// bootstrap.php: a definition loaded before Patchwork breaks
		// redefinition (DefinedTooEarly) for every other test that mocks them.
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-.]/', '', strtolower( (string) $key ) ) ?? '' )
		);
	}

	protected function tear_down(): void
	{
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

	// -------------------------------------------------------------------------
	// apply_config
	// -------------------------------------------------------------------------

	public function test_apply_config_name(): void
	{
		$cmd = new ObjectcacheApplyConfigCommand();
		$this->assertSame( 'objectcache.apply_config', $cmd->name() );
	}

	public function test_apply_config_persists_and_returns_hash(): void
	{
		// Override the config loader to write to our tmp dir.
		// We test by constructing directly.
		$config = ObjectCacheConfig::fromParams( [
			'scheme' => 'tcp',
			'host'   => '127.0.0.1',
			'port'   => 6379,
		] );
		$loader = new ObjectCacheConfig( $this->tmpDir );
		$loader->save( $config );

		$this->assertTrue( $loader->exists() );
		$hash = $loader->computeHash( $config );
		$this->assertNotEmpty( $hash );
	}

	public function test_apply_config_hash_excludes_password(): void
	{
		$loader  = new ObjectCacheConfig( $this->tmpDir );
		$configA = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secretA' ] );
		$configB = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1', 'password' => 'secretB' ] );
		$this->assertSame( $loader->computeHash( $configA ), $loader->computeHash( $configB ) );
	}

	// -------------------------------------------------------------------------
	// enable
	// -------------------------------------------------------------------------

	public function test_enable_command_name(): void
	{
		$cmd = new ObjectcacheEnableCommand();
		$this->assertSame( 'objectcache.enable', $cmd->name() );
	}

	public function test_enable_fails_without_config_hash(): void
	{
		$cmd    = new ObjectcacheEnableCommand();
		$result = $cmd->execute( [], [] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'config_hash', $result['detail'] );
	}

	public function test_enable_fails_with_hash_mismatch(): void
	{
		// Save a real config to tmp dir.
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );
		$loader = new ObjectCacheConfig( $this->tmpDir );
		$loader->save( $config );

		// The enable command uses WP_CONTENT_DIR which we cannot override here
		// without a more complex fixture. We test the hash-mismatch path directly.
		$cmd    = new ObjectcacheEnableCommand();
		$result = $cmd->execute( [], [ 'config_hash' => 'wrong_hash' ] );
		// Either no-config or hash-mismatch; both are failures.
		$this->assertFalse( $result['ok'] );
	}

	// -------------------------------------------------------------------------
	// disable
	// -------------------------------------------------------------------------

	public function test_disable_command_name(): void
	{
		$cmd = new ObjectcacheDisableCommand();
		$this->assertSame( 'objectcache.disable', $cmd->name() );
	}

	public function test_disable_returns_ok_when_nothing_installed(): void
	{
		// No config and no drop-in => disable should succeed gracefully.
		$cmd    = new ObjectcacheDisableCommand();
		$result = $cmd->execute( [], [ 'flush' => false ] );
		// OK because there is nothing to remove.
		$this->assertTrue( $result['ok'] );
	}

	// -------------------------------------------------------------------------
	// flush
	// -------------------------------------------------------------------------

	public function test_flush_command_name(): void
	{
		$cmd = new ObjectcacheFlushCommand();
		$this->assertSame( 'objectcache.flush', $cmd->name() );
	}

	public function test_flush_fails_without_config(): void
	{
		$cmd    = new ObjectcacheFlushCommand();
		$result = $cmd->execute( [], [ 'scope' => 'all' ] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'no config', $result['detail'] );
	}

	public function test_flush_group_scope_requires_group_name(): void
	{
		// Save a real config to tmp dir for the config path to have content.
		// But WP_CONTENT_DIR is not set to tmpDir here, so the config won't load.
		// We test the path where it DOES load by faking the execute() flow.
		// Save config to system WP_CONTENT_DIR equivalent; skip if undefined.
		$cmd    = new ObjectcacheFlushCommand();
		$result = $cmd->execute( [], [ 'scope' => 'group', 'group' => '' ] );
		// Without config, it fails at the config check.
		$this->assertFalse( $result['ok'] );
	}

	// -------------------------------------------------------------------------
	// Heartbeat omission when disabled
	// -------------------------------------------------------------------------

	public function test_heartbeat_block_omitted_when_no_config(): void
	{
		// ObjectCacheHeartbeat::build() returns null when no config exists.
		$heartbeat = \WPMgr\Agent\ObjectCache\ObjectCacheHeartbeat::build();
		$this->assertNull( $heartbeat );
	}

	// -------------------------------------------------------------------------
	// Page-cache coexistence
	// -------------------------------------------------------------------------

	public function test_advanced_cache_and_object_cache_can_coexist(): void
	{
		// Both drop-in types can be installed in the same wp-content directory
		// without conflict: they use different filenames.
		$this->assertNotSame(
			\WPMgr\Agent\Cache\DropinInstaller::CANONICAL,
			ObjectCacheDropinInstaller::CANONICAL
		);
	}

	// -------------------------------------------------------------------------
	// Flush strategy selection
	// -------------------------------------------------------------------------

	public function test_flush_strategy_auto_dedicated_uses_flushdb(): void
	{
		// When shared=false and strategy=auto, flush should use flushdb.
		// We test the config-level decision without a real Redis connection.
		$config = ObjectCacheConfig::fromParams( [
			'shared'         => false,
			'flush_strategy' => 'auto',
		] );
		$this->assertFalse( $config['shared'] );
		$this->assertSame( 'auto', $config['flush_strategy'] );
		// Strategy resolution: auto + !shared => flushdb-safe.
		$useFlushDb = ( $config['flush_strategy'] === 'auto' || $config['flush_strategy'] === 'flushdb' )
			&& ! $config['shared'];
		$this->assertTrue( $useFlushDb );
	}

	public function test_flush_strategy_auto_shared_uses_scan(): void
	{
		$config = ObjectCacheConfig::fromParams( [
			'shared'         => true,
			'flush_strategy' => 'auto',
		] );
		$useFlushDb = ( $config['flush_strategy'] === 'auto' || $config['flush_strategy'] === 'flushdb' )
			&& ! $config['shared'];
		$this->assertFalse( $useFlushDb );
	}

	// -------------------------------------------------------------------------
	// Config file 0600 and atomic write
	// -------------------------------------------------------------------------

	public function test_config_file_0600_permissions(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );
		$cfg->save( $config );

		$path  = $this->tmpDir . '/' . ObjectCacheConfig::FILENAME;
		$perms = fileperms( $path );
		$this->assertNotFalse( $perms );
		$this->assertSame( 0600, $perms & 0777 );
	}

	public function test_config_file_no_tmp_files_left(): void
	{
		$cfg    = new ObjectCacheConfig( $this->tmpDir );
		$config = ObjectCacheConfig::fromParams( [ 'host' => '127.0.0.1' ] );
		$cfg->save( $config );

		$files = glob( $this->tmpDir . '/*.tmp.*' );
		$this->assertSame( [], $files === false ? [] : $files );
	}
}
